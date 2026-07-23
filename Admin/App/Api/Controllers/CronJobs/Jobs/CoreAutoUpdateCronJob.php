<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Core Auto-Update Cron Job
 *
 * Handles scheduled automatic WordPress core updates.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Core\CoreController;
use Tailwatch\Admin\App\Api\Services\Common\CompatibilityService;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class CoreAutoUpdateCronJob
 *
 * Cron job for WordPress Core Auto-Update feature:
 * - Checks for available core updates
 * - Automatically updates WordPress core based on schedule
 *
 * Controller is lazy-loaded only when needed.
 *
 */
class CoreAutoUpdateCronJob extends AbstractCronJob {

	/**
	 * Core Controller instance (lazy loaded).
	 *
	 * @var CoreController|null
	 */
	private $core_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_core_auto_update_schedule';
		$this->schedule_name    = 'wptw_core_auto_update';
		$this->default_interval = '1_day';

		parent::__construct();
	}

	/**
	 * Get Core controller instance (lazy loading).
	 *
	 * @return CoreController
	 */
	private function get_core_controller() {
		if ( null === $this->core_controller ) {
			$this->core_controller = new CoreController();
		}
		return $this->core_controller;
	}

	/**
	 * Cached enabled status for core updates.
	 *
	 * @var bool|null
	 */
	private $core_cached_is_enabled = null;

	/**
	 * Get feature settings.
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		// Return empty array as we use controller methods directly
		// This is needed for AbstractCronJob compatibility
		return array();
	}

	/**
	 * Check if the cron job is enabled.
	 *
	 * Override to check both feature_enable AND scheduler_enable.
	 *
	 * @param bool $force_refresh Force refresh from database.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_enabled( $force_refresh = false ) {
		if ( $force_refresh || null === $this->core_cached_is_enabled ) {
			$controller = $this->get_core_controller();
			$status     = $controller->wptw_core_update_feature_enable();

			$this->core_cached_is_enabled = isset( $status['feature_enable'] )
				&& true === $status['feature_enable']
				&& isset( $status['scheduler_enable'] )
				&& true === $status['scheduler_enable'];
		}
		return $this->core_cached_is_enabled;
	}

	/**
	 * Get configured interval from settings.
	 *
	 * Override to use controller method that returns interval string.
	 *
	 * @return string Interval string (e.g., '1_day', '3_days').
	 */
	protected function get_configured_interval() {
		$controller = $this->get_core_controller();
		$interval   = $controller->wptw_get_core_scheduler_interval();

		return ! empty( $interval ) ? $interval : $this->default_interval;
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'WordPress Core Auto-Update', 'tailwatch' );
	}

	/**
	 * Clear cached settings.
	 *
	 * Override to clear custom cache property.
	 *
	 * @return void
	 */
	public function clear_cache() {
		parent::clear_cache();
		$this->core_cached_is_enabled = null;
	}

	/**
	 * Execute the core auto-update cron job.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			/**
			 * Fires before core auto-update cron executes.
			 *
			 */
			do_action( 'wptw_before_core_auto_update_cron_execute' );

			$core_controller = $this->get_core_controller();

			// Check if core update is available
			$core_updates = $core_controller->wptw_get_core_updates();

			if ( $core_updates['code'] === 200 ) {
				// Check if update is actually available
				$update_available = false;
				if ( isset( $core_updates['core'] ) && is_array( $core_updates['core'] ) ) {
					foreach ( $core_updates['core'] as $update ) {
						if ( isset( $update['update_available'] ) && true === $update['update_available'] ) {

							// 1. Check Compatibility
							$current_version = isset( $update['current_version'] ) ? $update['current_version'] : get_bloginfo( 'version' );
							$target_version  = isset( $update['update_version'] ) ? $update['update_version'] : null;

							if ( $target_version ) {
								$compatibility = CompatibilityService::check_compatibility(
									'core',
									null,
									$target_version,
									$current_version
								);

								if ( ! $compatibility['is_compatible'] ) {
									Log::warning(
										"Core Auto-update skipped: Incompatible version detected - {$target_version}",
										array(
											'feature' => 'core_auto_update',
											'action'  => 'update_skipped_compatibility',
											'current_version' => $current_version,
											'target_version' => $target_version,
											'reason'  => isset( $compatibility['blocking_issues'][0]['message'] ) ? $compatibility['blocking_issues'][0]['message'] : 'Unknown compatibility issue',
										)
									);
									continue; // Skip this incompatible update
								}
							}

							$update_available = true;
							break;
						}
					}
				}

				if ( $update_available ) {
					$core_controller->wptw_update_core();
				}
			}

			/**
			 * Fires after core auto-update cron executes.
			 *
			 */
			do_action( 'wptw_after_core_auto_update_cron_execute' );

		} catch ( \Throwable $e ) {
			Log::error(
				'Core Auto-Update Cron Job Failed',
				array(
					'feature' => 'core_auto_update',
					'action'  => 'cron_execution_failed',
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);
		}
	}
}
