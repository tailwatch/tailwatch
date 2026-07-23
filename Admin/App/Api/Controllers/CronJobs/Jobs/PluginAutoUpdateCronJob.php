<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Plugin Auto-Update Cron Job
 *
 * Handles scheduled automatic plugin updates.
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
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\Plugin\PluginController;
use Tailwatch\Admin\App\Api\Services\Common\CompatibilityService;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class PluginAutoUpdateCronJob
 *
 * Cron job for Plugin Auto-Update feature:
 * - Checks for available plugin updates
 * - Automatically updates plugins based on schedule
 *
 * Controllers are lazy-loaded only when needed.
 *
 */
class PluginAutoUpdateCronJob extends AbstractCronJob {


	/**
	 * PluginTheme Controller instance (lazy loaded).
	 *
	 * @var PluginThemeController|null
	 */
	private $plugin_theme_controller = null;

	/**
	 * Plugin Controller instance (lazy loaded).
	 *
	 * @var PluginController|null
	 */
	private $plugin_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_plugin_auto_update_schedule';
		$this->schedule_name    = 'wptw_plugin_auto_update';
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
	 * Get Plugin controller instance (lazy loading).
	 *
	 * @return PluginController
	 */
	private function get_plugin_controller() {
		if ( null === $this->plugin_controller ) {
			$this->plugin_controller = new PluginController();
		}
		return $this->plugin_controller;
	}

	/**
	 * Cached enabled status for plugin updates.
	 *
	 * @var bool|null
	 */
	private $plugin_cached_is_enabled = null;

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
		if ( $force_refresh || null === $this->plugin_cached_is_enabled ) {
			$controller = $this->get_plugin_theme_controller();
			$status     = $controller->wptw_plugin_update_feature_enable();

			$this->plugin_cached_is_enabled = isset( $status['feature_enable'] )
				&& true === $status['feature_enable']
				&& isset( $status['scheduler_enable'] )
				&& true === $status['scheduler_enable'];
		}
		return $this->plugin_cached_is_enabled;
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
		$interval   = $controller->wptw_get_plugin_scheduler_interval();

		return ! empty( $interval ) ? $interval : $this->default_interval;
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'Plugin Auto-Update', 'tailwatch' );
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
		$this->plugin_cached_is_enabled = null;
	}

	/**
	 * Execute the plugin auto-update cron job.
	 *
	 * Processes multiple plugin updates in sequence with proper handling
	 * to ensure all plugins get updated, not just the first one.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			/**
			 * Fires before plugin auto-update cron executes.
			 *
			 */
			do_action( 'wptw_before_plugin_auto_update_cron_execute' );

			$plugin_controller = $this->get_plugin_controller();

			// Force refresh plugin update transient to get accurate data.
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();

			// Get all installed plugins with updates only.
			$post_data = wp_json_encode(
				array(
					'limit'        => 50, // Increased limit to catch all updates.
					'page'         => 1,
					'updates_only' => true,
				)
			);

			$plugins = $plugin_controller->wptw_get_all_installed_plugins( $post_data );

			if ( $plugins['code'] === 200 && ! empty( $plugins['plugins'] ) ) {
				// Collect all plugin data that need updates FIRST.
				// This prevents issues with transient invalidation during the loop.
				$plugins_to_update = array();

				foreach ( $plugins['plugins'] as $plugin ) {
					if ( isset( $plugin['update_available'] ) && true === $plugin['update_available'] && isset( $plugin['plugin'] ) ) {
						$plugin_file = sanitize_text_field( $plugin['plugin'] );
						$plugin_slug = sanitize_text_field( $plugin['slug'] );

						if ( ! empty( $plugin_file ) ) {
							$plugins_to_update[] = array(
								'plugin_file'     => $plugin_file,
								'slug'            => $plugin_slug,
								'current_version' => isset( $plugin['version'] ) ? $plugin['version'] : '0.0.0',
								'target_version'  => isset( $plugin['update_version'] ) ? $plugin['update_version'] : null,
								'name'            => isset( $plugin['name'] ) ? $plugin['name'] : $plugin_slug,
							);
						}
					}
				}

				$total_plugins = count( $plugins_to_update );
				$updated_count = 0;
				$failed_count  = 0;

				// Process each plugin update sequentially.
				foreach ( $plugins_to_update as $index => $plugin_info ) {
					$plugin_file = $plugin_info['plugin_file'];
					$plugin_slug = $plugin_info['slug'];

					// 1. Check Compatibility BEFORE updating.
					if ( ! empty( $plugin_info['target_version'] ) ) {
						$compatibility = CompatibilityService::check_compatibility(
							'plugin',
							$plugin_slug,
							$plugin_info['target_version'],
							$plugin_info['current_version']
						);

						if ( ! $compatibility['is_compatible'] ) {
							Log::warning(
								"Auto-update skipped: Incompatible plugin detected - {$plugin_info['name']}",
								array(
									'feature'         => 'plugin_auto_update',
									'action'          => 'update_skipped_compatibility',
									'plugin'          => $plugin_slug,
									'current_version' => $plugin_info['current_version'],
									'target_version'  => $plugin_info['target_version'],
									'reason'          => isset( $compatibility['blocking_issues'][0]['message'] ) ? $compatibility['blocking_issues'][0]['message'] : 'Unknown compatibility issue',
								)
							);
							continue; // Skip this update.
						}
					}

					// Force refresh the update transient before each update.
					// This ensures WordPress has fresh data for each plugin.
					if ( $index > 0 ) {
						delete_site_transient( 'update_plugins' );
						wp_update_plugins();

						// Add a small delay between updates to allow WordPress to stabilize.
						// This prevents race conditions and ensures clean state.
						sleep( 1 );
					}

					$update_data = wp_json_encode(
						array(
							'plugin'       => $plugin_file,
							'triggered_by' => 'cron',
						)
					);
					$result      = $plugin_controller->wptw_update_plugin( $update_data );

					if ( isset( $result['code'] ) && 200 === $result['code'] ) {
						++$updated_count;
					} else {
						++$failed_count;
					}
				}
			}

			/**
			 * Fires after plugin auto-update cron executes.
			 *
			 */
			do_action( 'wptw_after_plugin_auto_update_cron_execute' );

		} catch ( \Throwable $e ) {
			Log::error(
				'Plugin Auto-Update Cron Job Failed',
				array(
					'feature' => 'plugin_auto_update',
					'action'  => 'cron_execution_failed',
					'error'   => $e->getMessage(),
					'trace'   => $e->getTraceAsString(),
				)
			);
		}
	}
}
