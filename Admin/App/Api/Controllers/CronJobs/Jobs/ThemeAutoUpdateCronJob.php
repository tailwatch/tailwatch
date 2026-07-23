<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Theme Auto-Update Cron Job
 *
 * Handles scheduled automatic theme updates.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\PluginThemeController;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\Theme\ThemeController;
use Tailwatch\Admin\App\Api\Services\Common\CompatibilityService;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class ThemeAutoUpdateCronJob
 *
 * Cron job for Theme Auto-Update feature:
 * - Checks for available theme updates
 * - Automatically updates themes based on schedule
 *
 * Controllers are lazy-loaded only when needed.
 *
 */
class ThemeAutoUpdateCronJob extends AbstractCronJob {


	/**
	 * PluginTheme Controller instance (lazy loaded).
	 *
	 * @var PluginThemeController|null
	 */
	private $plugin_theme_controller = null;

	/**
	 * Theme Controller instance (lazy loaded).
	 *
	 * @var ThemeController|null
	 */
	private $theme_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_theme_auto_update_schedule';
		$this->schedule_name    = 'wptw_theme_auto_update';
		$this->default_interval = '1_day';

		parent::__construct();
	}

	/**
	 * Get PluginTheme controller instance (lazy loading).
	 *
	 * @return PluginThemeController
	 */
	private function get_plugin_theme_controller() {
		if ( null === $this->plugin_theme_controller ) {
			$this->plugin_theme_controller = new PluginThemeController();
		}
		return $this->plugin_theme_controller;
	}

	/**
	 * Get Theme controller instance (lazy loading).
	 *
	 * @return ThemeController
	 */
	private function get_theme_controller() {
		if ( null === $this->theme_controller ) {
			$this->theme_controller = new ThemeController();
		}
		return $this->theme_controller;
	}

	/**
	 * Cached enabled status for theme updates.
	 *
	 * @var bool|null
	 */
	private $theme_cached_is_enabled = null;

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
		if ( $force_refresh || null === $this->theme_cached_is_enabled ) {
			$controller = $this->get_plugin_theme_controller();
			$status     = $controller->wptw_theme_update_feature_enable();

			$this->theme_cached_is_enabled = isset( $status['feature_enable'] )
				&& true === $status['feature_enable']
				&& isset( $status['scheduler_enable'] )
				&& true === $status['scheduler_enable'];
		}
		return $this->theme_cached_is_enabled;
	}

	/**
	 * Get configured interval from settings.
	 *
	 * Override to use controller method that returns interval string.
	 *
	 * @return string Interval string (e.g., '1_day', '3_days').
	 */
	protected function get_configured_interval() {
		$controller = $this->get_plugin_theme_controller();
		$interval   = $controller->wptw_get_theme_scheduler_interval();

		return ! empty( $interval ) ? $interval : $this->default_interval;
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'Theme Auto-Update', 'tailwatch' );
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
		$this->theme_cached_is_enabled = null;
	}

	/**
	 * Execute the theme auto-update cron job.
	 *
	 * Processes multiple theme updates in sequence with proper handling
	 * to ensure all themes get updated, not just the first one.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			/**
			 * Fires before theme auto-update cron executes.
			 *
			 */
			do_action( 'wptw_before_theme_auto_update_cron_execute' );

			$theme_controller = $this->get_theme_controller();

			// Force refresh theme update transient to get accurate data.
			delete_site_transient( 'update_themes' );
			wp_update_themes();

			// Get all installed themes with updates only.
			$post_data = wp_json_encode(
				array(
					'limit'        => 50, // Increased limit to catch all updates.
					'page'         => 1,
					'updates_only' => true,
				)
			);

			$themes = $theme_controller->wptw_get_all_installed_themes( $post_data );

			if ( $themes['code'] === 200 && ! empty( $themes['themes'] ) ) {
				// Collect all theme data that need updates FIRST.
				// This prevents issues with transient invalidation during the loop.
				$themes_to_update = array();

				foreach ( $themes['themes'] as $theme ) {
					if ( isset( $theme['update_available'] ) && true === $theme['update_available'] && isset( $theme['theme'] ) ) {
						$theme_slug = sanitize_text_field( $theme['theme'] );
						if ( ! empty( $theme_slug ) ) {
							$themes_to_update[] = array(
								'slug'            => $theme_slug,
								'current_version' => isset( $theme['version'] ) ? $theme['version'] : '0.0.0',
								'target_version'  => isset( $theme['update_version'] ) ? $theme['update_version'] : null,
								'name'            => isset( $theme['name'] ) ? $theme['name'] : $theme_slug,
							);
						}
					}
				}

				$total_themes  = count( $themes_to_update );
				$updated_count = 0;
				$failed_count  = 0;

				// Process each theme update sequentially.
				foreach ( $themes_to_update as $index => $theme_info ) {
					$theme_slug = $theme_info['slug'];

					// 1. Check Compatibility BEFORE updating.
					if ( ! empty( $theme_info['target_version'] ) ) {
						$compatibility = CompatibilityService::check_compatibility(
							'theme',
							$theme_slug,
							$theme_info['target_version'],
							$theme_info['current_version']
						);

						if ( ! $compatibility['is_compatible'] ) {
							Log::warning(
								"Auto-update skipped: Incompatible theme detected - {$theme_info['name']}",
								array(
									'feature'         => 'theme_auto_update',
									'action'          => 'update_skipped_compatibility',
									'theme'           => $theme_slug,
									'current_version' => $theme_info['current_version'],
									'target_version'  => $theme_info['target_version'],
									'reason'          => isset( $compatibility['blocking_issues'][0]['message'] ) ? $compatibility['blocking_issues'][0]['message'] : 'Unknown compatibility issue',
								)
							);
							continue; // Skip this update.
						}
					}

					// Force refresh the update transient before each update.
					// This ensures WordPress has fresh data for each theme.
					if ( $index > 0 ) {
						delete_site_transient( 'update_themes' );
						wp_update_themes();

						// Add a small delay between updates to allow WordPress to stabilize.
						// This prevents race conditions and ensures clean state.
						sleep( 1 );
					}

					$update_data = wp_json_encode(
						array(
							'theme'        => $theme_slug,
							'triggered_by' => 'cron',
						)
					);
					$result      = $theme_controller->wptw_update_theme( $update_data );

					if ( isset( $result['code'] ) && 200 === $result['code'] ) {
						++$updated_count;
					} else {
						++$failed_count;
					}
				}
			}

			/**
			 * Fires after theme auto-update cron executes.
			 *
			 */
			do_action( 'wptw_after_theme_auto_update_cron_execute' );

		} catch ( \Throwable $e ) {
			Log::error(
				'Theme Auto-Update Cron Job Failed',
				array(
					'feature' => 'theme_auto_update',
					'action'  => 'cron_execution_failed',
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);
		}
	}
}
