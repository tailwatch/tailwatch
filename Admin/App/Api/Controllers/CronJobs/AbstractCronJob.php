<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Abstract Cron Job Base Class
 *
 * Provides common functionality for all cron jobs in the plugin.
 * Each feature-specific cron job extends this class.
 *
 * Only registers cron hook for execution.
 * Schedule management is handled via admin actions, not on every page load.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs;

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Class AbstractCronJob
 *
 * Base class for all cron jobs. Provides:
 * - Common interval parsing
 * - Schedule/unschedule functionality
 * - WordPress hooks registration
 * - Standardized interval options
 *
 */
abstract class AbstractCronJob {

	/**
	 * Hook controller instance (lazy loaded).
	 *
	 * @var HookControllers|null
	 */
	protected $hook_controller = null;

	/**
	 * Cron hook name (e.g., 'wptw_verify_ssl').
	 *
	 * @var string
	 */
	protected $cron_hook_name;

	/**
	 * Schedule name for WordPress cron (e.g., 'wptw_ssl_schedule').
	 *
	 * @var string
	 */
	protected $schedule_name;

	/**
	 * Default interval if not configured.
	 *
	 * @var string
	 */
	protected $default_interval = 'Every 12 Hours';

	/**
	 * Cached feature settings to avoid repeated DB queries.
	 *
	 * @var array|null
	 */
	private $cached_settings = null;

	/**
	 * Cached enabled status.
	 *
	 * @var bool|null
	 */
	private $cached_is_enabled = null;

	/**
	 * Constructor - Registers only essential hooks (cron execution).
	 *
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Get hook controller instance (lazy loading).
	 *
	 * @return HookControllers
	 */
	protected function get_hook_controller() {
		if ( null === $this->hook_controller ) {
			$this->hook_controller = new HookControllers();
		}
		return $this->hook_controller;
	}

	/**
	 * Register WordPress hooks for this cron job.
	 *
	 * Only registers:
	 * - cron_schedules filter (lightweight, uses cached/default values)
	 * - The actual cron hook for execution
	 * - Admin-only scheduling check (not on every page load)
	 *
	 * @return void
	 */
	protected function register_hooks() {
		// Register custom schedule interval (lightweight).
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Custom interval registered by design.
		add_filter( 'cron_schedules', array( $this, 'register_schedule_interval' ) );

		// Register the cron execution hook.
		add_action( $this->cron_hook_name, array( $this, 'execute' ) );

		// Only check scheduling in admin or during cron (not on frontend page loads).
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			add_action( 'admin_init', array( $this, 'maybe_schedule' ), 20 );
		}
	}

	/**
	 * Get feature settings (abstract - must be implemented by child classes).
	 *
	 * NOTE: This is only called when actually needed (schedule changes, execution).
	 *
	 * @return array Feature settings array.
	 */
	abstract protected function get_feature_settings();

	/**
	 * Execute the cron job task.
	 *
	 * Must be implemented by child classes to perform the actual cron task.
	 *
	 * @return void
	 */
	abstract public function execute();

	/**
	 * Get cached feature settings.
	 *
	 * Uses request-level caching to avoid multiple DB queries per request.
	 *
	 * @param bool $force_refresh Force refresh from database.
	 *
	 * @return array Feature settings array.
	 */
	protected function get_cached_settings( $force_refresh = false ) {
		if ( $force_refresh || null === $this->cached_settings ) {
			$this->cached_settings = $this->get_feature_settings();
		}
		return $this->cached_settings;
	}

	/**
	 * Check if the cron job is enabled.
	 *
	 * Uses cached settings to avoid repeated DB queries.
	 *
	 * @param bool $force_refresh Force refresh from database.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_enabled( $force_refresh = false ) {
		if ( $force_refresh || null === $this->cached_is_enabled ) {
			$settings                = $this->get_cached_settings( $force_refresh );
			$this->cached_is_enabled = isset( $settings['field_1']['options']['option']['selected'] )
				&& true === $settings['field_1']['options']['option']['selected'];
		}
		return $this->cached_is_enabled;
	}

	/**
	 * Clear cached settings.
	 *
	 * Call this when feature settings are updated.
	 *
	 * @return void
	 */
	public function clear_cache() {
		$this->cached_settings   = null;
		$this->cached_is_enabled = null;
	}

	/**
	 * Get configured interval from settings.
	 *
	 * @return string Interval string (e.g., 'Every 12 Hours').
	 */
	protected function get_configured_interval() {
		$settings = $this->get_cached_settings();

		$interval = isset( $settings['field_1']['sub_options']['field_2']['options'] )
			? $settings['field_1']['sub_options']['field_2']['options']
			: $this->default_interval;

		// Handle array format from select fields.
		if ( is_array( $interval ) ) {
			foreach ( $interval as $option ) {
				// Use loose comparison to handle different truthy values (true, 1, "1", etc.).
				if ( isset( $option['selected'] ) && $option['selected'] ) {
					return sanitize_text_field( $option['value'] );
				}
			}
			return $this->default_interval;
		}

		return is_string( $interval ) ? sanitize_text_field( $interval ) : $this->default_interval;
	}

	/**
	 * Convert interval string to seconds.
	 *
	 * Supports multiple interval formats:
	 * - Standard format: 'Every 12 Hours', '1 Day', 'Weekly', etc.
	 * - Underscore format: '1_day', '3_days', '1_week', etc. (used by Theme/Plugin/Core auto-updates)
	 *
	 * @param string $interval_string Interval string (e.g., 'Every 12 Hours', '1 Day', '1_day').
	 *
	 * @return int Interval in seconds.
	 */
	protected function interval_to_seconds( $interval_string ) {
		$intervals = array(
			// Sub-hourly intervals (maintenance tasks).
			'Every 30 Minutes' => 30 * MINUTE_IN_SECONDS,

			// Hourly intervals (standard format).
			'Hourly'         => HOUR_IN_SECONDS,
			'Every 1 Hour'   => HOUR_IN_SECONDS,
			'Every 3 Hours'  => 3 * HOUR_IN_SECONDS,
			'Every 6 Hours'  => 6 * HOUR_IN_SECONDS,
			'Every 12 Hours' => 12 * HOUR_IN_SECONDS,
			'Every 24 Hours' => DAY_IN_SECONDS,

			// Backup-style intervals.
			'Every 48 Hours' => 2 * DAY_IN_SECONDS,
			'Every 3 Days'   => 3 * DAY_IN_SECONDS,
			'Every 1 Week'   => WEEK_IN_SECONDS,
			'Every Week'     => WEEK_IN_SECONDS,

			// Daily intervals (standard format).
			'1 Day'          => DAY_IN_SECONDS,
			'Daily'          => DAY_IN_SECONDS,

			// Weekly intervals (standard format).
			'Weekly'         => WEEK_IN_SECONDS,
			'7 Days'         => WEEK_IN_SECONDS,

			// Bi-weekly (standard format).
			'15 Days'        => 15 * DAY_IN_SECONDS,

			// Monthly (standard format).
			'Monthly'        => 30 * DAY_IN_SECONDS,
			'30 Days'        => 30 * DAY_IN_SECONDS,

			// Underscore format (used by Theme/Plugin/Core auto-updates).
			'1_day'          => DAY_IN_SECONDS,
			'3_days'         => 3 * DAY_IN_SECONDS,
			'1_week'         => WEEK_IN_SECONDS,
			'2_weeks'        => 2 * WEEK_IN_SECONDS,
			'1_month'        => 30 * DAY_IN_SECONDS,
			'3_months'       => 90 * DAY_IN_SECONDS,
		);

		return isset( $intervals[ $interval_string ] ) ? $intervals[ $interval_string ] : 12 * HOUR_IN_SECONDS;
	}

	/**
	 * Register custom schedule interval with WordPress.
	 *
	 * Only queries settings when in admin context.
	 * On frontend, uses default interval to avoid DB queries.
	 *
	 * @param array $schedules Existing cron schedules.
	 *
	 * @return array Modified schedules array.
	 */
	public function register_schedule_interval( $schedules ) {
		// On frontend, use default interval to avoid DB query.
		// Actual interval is set when scheduling via admin.
		if ( ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			$interval_seconds = $this->interval_to_seconds( $this->default_interval );
		} else {
			$interval_string  = $this->get_configured_interval();
			$interval_seconds = $this->interval_to_seconds( $interval_string );
		}

		$schedules[ $this->schedule_name ] = array(
			'interval' => $interval_seconds,
			'display'  => sprintf(
				/* translators: %s: Schedule name */
				esc_html__( 'Tailwatch: %s', 'tailwatch' ),
				$this->get_schedule_display_name()
			),
		);

		return $schedules;
	}

	/**
	 * Get human-readable schedule name.
	 *
	 * @return string Display name for the schedule.
	 */
	abstract protected function get_schedule_display_name();

	/**
	 * Maybe schedule the cron job based on settings.
	 *
	 * Schedules or unschedules the cron job based on whether
	 * the feature is enabled or disabled.
	 *
	 * Uses cached settings and, unless forced, only runs in admin or cron context.
	 *
	 * @param bool $force When true, run regardless of context. Used by
	 *                    CronJobManager::schedule_all() at activation, where
	 *                    scheduling must happen even without an admin request
	 *                    (e.g. WP-CLI activation on a front-end-only site).
	 * @return void
	 */
	public function maybe_schedule( $force = false ) {
		// Skip if not in appropriate context (already checked in register_hooks),
		// unless a caller forces scheduling (plugin activation).
		if ( ! $force && ! is_admin() && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$next_scheduled = wp_next_scheduled( $this->cron_hook_name );

		if ( $this->is_enabled() ) {
			// Schedule if not already scheduled.
			if ( ! $next_scheduled ) {
				$this->schedule();
			}
		} elseif ( $next_scheduled ) {
			// Unschedule if currently scheduled but feature is disabled.
			$this->unschedule();
		}
	}

	/**
	 * Sync schedule when feature settings change.
	 *
	 * Call this method when feature is enabled/disabled or interval changes.
	 * This forces a fresh check and reschedules if needed.
	 *
	 * @return bool True if schedule was updated, false otherwise.
	 */
	public function sync_schedule() {
		// Clear cache to get fresh settings.
		$this->clear_cache();

		$was_scheduled = $this->is_scheduled();
		$is_enabled    = $this->is_enabled( true ); // Force refresh.

		if ( $is_enabled && ! $was_scheduled ) {
			// Feature was enabled, schedule it.
			return $this->schedule();
		} elseif ( ! $is_enabled && $was_scheduled ) {
			// Feature was disabled, unschedule it.
			return $this->unschedule();
		} elseif ( $is_enabled && $was_scheduled ) {
			// Feature still enabled, reschedule with potentially new interval.
			$this->unschedule();
			return $this->schedule();
		}

		return false;
	}

	/**
	 * Schedule the cron job.
	 *
	 * @return bool True if scheduled successfully, false otherwise.
	 */
	public function schedule() {
		if ( wp_next_scheduled( $this->cron_hook_name ) ) {
			return false; // Already scheduled.
		}

		$schedules = wp_get_schedules();
		if ( ! isset( $schedules[ $this->schedule_name ] ) ) {
			return false; // Schedule not registered.
		}

		$interval = $schedules[ $this->schedule_name ]['interval'];
		$result   = wp_schedule_event( time() + $interval, $this->schedule_name, $this->cron_hook_name );

		/**
		 * Fires after a cron job is scheduled.
		 *
		 * @param string $cron_hook_name The cron hook name.
		 * @param bool   $result         Whether scheduling was successful.
		 */
		do_action( 'wptw_cron_scheduled', $this->cron_hook_name, (bool) $result );

		return (bool) $result;
	}

	/**
	 * Unschedule the cron job.
	 *
	 * @return bool True if unscheduled successfully, false otherwise.
	 */
	public function unschedule() {
		$timestamp = wp_next_scheduled( $this->cron_hook_name );
		if ( ! $timestamp ) {
			return false; // Not scheduled.
		}

		$result = wp_unschedule_event( $timestamp, $this->cron_hook_name );

		/**
		 * Fires after a cron job is unscheduled.
		 *
		 * @param string $cron_hook_name The cron hook name.
		 * @param bool   $result         Whether unscheduling was successful.
		 */
		do_action( 'wptw_cron_unscheduled', $this->cron_hook_name, (bool) $result );

		return (bool) $result;
	}

	/**
	 * Get next scheduled run time.
	 *
	 * @return int|false Unix timestamp of next run, or false if not scheduled.
	 */
	public function get_next_run() {
		return wp_next_scheduled( $this->cron_hook_name );
	}

	/**
	 * Check if cron job is currently scheduled.
	 *
	 * @return bool True if scheduled, false otherwise.
	 */
	public function is_scheduled() {
		return false !== wp_next_scheduled( $this->cron_hook_name );
	}

	/**
	 * Run the cron job immediately (manual trigger).
	 *
	 * @return mixed Result of execute() method.
	 */
	public function run_now() {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		return $this->execute();
	}
}
