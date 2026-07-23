<?php
/**
 * Plugin Controller
 *
 * Manages plugin operations such as update, install, activate, deactivate, and delete.
 *
 * @package    Tailwatch
 * @subpackage Controllers/PluginTheme/Plugin
 */

namespace Tailwatch\Admin\App\Api\Controllers\PluginTheme\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\PluginTheme\Plugin\PluginManagerService;
use Tailwatch\Admin\App\Api\Services\PluginTheme\PluginThemeService;
use Tailwatch\Admin\App\Api\Services\Common\CompatibilityService;
use Tailwatch\Admin\App\Api\Services\Common\HistoryService;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\PluginThemeController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class PluginController
 *
 * Handles plugin management actions including updates, installations, and status changes.
 *
 */
class PluginController extends BaseController {

	/**
	 * Feature check exemptions.
	 *
	 * @var array
	 */
	protected $wptw_feature_check_exemptions = array();

	/**
	 * Protected plugins list.
	 *
	 * @var array
	 */
	protected $protected_plugins = array(
		'tailwatch/tailwatch.php',
	);

	/**
	 * Get feature status.
	 *
	 * @return array Feature enablement status.
	 */
	protected function wptw_get_feature_status() {
		$plugin_theme_controller = new PluginThemeController();
		return $plugin_theme_controller->wptw_plugin_update_feature_enable();
	}

	/**
	 * Update a specific plugin.
	 *
	 * @param string $post_data JSON encoded data containing plugin info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message         Response message.
	 *     @type array  $plugin          Updated plugin data.
	 *     @type int    $code            HTTP response code.
	 *     @type bool   $site_health     Optional site health indicator on error.
	 * }
	 */
	public function wptw_update_plugin( $post_data ) {
		$plugin_slug    = null;
		$plugin_name    = null;
		$last_version   = null;
		$update_version = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			$plugin = isset( $data['plugin'] ) ? sanitize_text_field( $data['plugin'] ) : '';
			if ( empty( $plugin ) ) {
				Log::warning(
					'Plugin update failed: No plugin specified',
					array(
						'feature'   => 'plugins',
						'action'    => 'plugin_update_failed',
						'error'     => 'No plugin specified in request',
						'meta_data' => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Update failed',
							'reason' => 'No plugin specified',
						),
					)
				);
				return array(
					'message' => __( 'No plugin specified.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			$plugin_slug = $plugin;

			if ( ! PluginManagerService::validate_plugin_path( $plugin ) ) {
				Log::warning(
					'Plugin update failed: Invalid plugin slug format',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_update_failed',
						'plugin_slug' => $plugin,
						'error'       => 'Invalid plugin slug format',
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Update failed',
							'plugin_slug' => $plugin,
							'reason'      => 'Invalid slug format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid plugin slug format. Expected format: plugin-folder/plugin-file.php or plugin-file.php', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/misc.php';

			$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin;

			// Check if plugin exists.
			if ( ! file_exists( $plugin_file_path ) ) {
				Log::error(
					'Plugin update failed: Plugin not found',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_update_failed',
						'plugin_slug' => $plugin,
						'error'       => "Plugin '{$plugin}' is not installed or does not exist",
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Update failed',
							'plugin_slug' => $plugin,
							'reason'      => 'Plugin not found',
						),
					)
				);
				return array(
					'message' => "Plugin '$plugin' is not installed or does not exist.",
					'plugin'  => array(),
					'code'    => 404,
				);
			}

			// Get current plugin data with error handling.
			$current_plugin_data = get_plugin_data( $plugin_file_path, false, false, false );
			if ( empty( $current_plugin_data['Version'] ) ) {
				Log::error(
					'Plugin update failed: Unable to read plugin version',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_update_failed',
						'plugin_slug' => $plugin,
						'error'       => 'Unable to read current plugin version',
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Update failed',
							'plugin_slug' => $plugin,
							'reason'      => 'Unable to read version',
						),
					)
				);
				return array(
					'message'     => __( 'Unable to read current plugin version.', 'tailwatch' ),
					'plugin'      => array(),
					'code'        => 500,
					'site_health' => true,
				);
			}

			$last_version = $current_plugin_data['Version'];
			$plugin_name  = $current_plugin_data['Name'] ?? basename( $plugin );

			// Capture BEFORE state for history.
			$before_state = $this->capture_plugin_state( $plugin );

			// Check if update is available.
			$update_plugins = get_site_transient( 'update_plugins' );
			if ( ! isset( $update_plugins->response[ $plugin ] ) ) {
				// No update available - not an error, just informational.
				return array(
					'message' => __( 'Plugin is already at the latest version.', 'tailwatch' ),
					'plugin'  => array(
						'slug'            => $plugin,
						'current_version' => $last_version,
					),
					'code'    => 200,
				);
			}

			$update_version = isset( $update_plugins->response[ $plugin ]->new_version )
				? $update_plugins->response[ $plugin ]->new_version
				: 'N/A';

			// Set flag BEFORE upgrade to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			// Use Automatic_Upgrader_Skin for silent background/cron updates.
			// This prevents output and exit() calls that can interrupt batch operations.
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $plugin );

			if ( is_wp_error( $result ) ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_plugin_state( $plugin );

				// Record history for failed update (flag already set before upgrade).
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_update',
						'item_type'     => 'plugin',
						'item_slug'     => $plugin,
						'item_name'     => $plugin_name,
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'old_version' => $last_version,
							'error'       => $result->get_error_message(),
							'source'      => 'wptw_plugin',
						),
					),
					false
				); // Flag already set before upgrade.

				Log::error(
					"Plugin update failed: {$plugin_name}",
					array(
						'feature'        => 'plugins',
						'action'         => 'plugin_update_failed',
						'plugin_slug'    => $plugin,
						'plugin_name'    => $plugin_name,
						'last_version'   => $last_version,
						'update_version' => $update_version,
						'error'          => $result->get_error_message(),
						'meta_data'      => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Update failed',
							'plugin_slug'  => $plugin,
							'plugin_name'  => $plugin_name,
							'from_version' => $last_version,
							'to_version'   => $update_version,
							'reason'       => 'Upgrade error',
						),
					)
				);
				return array(
					'message' => $result->get_error_message(),
					'plugin'  => array(),
					'code'    => 500,
				);
			} else {
				// Get updated plugin data.
				$plugin_data = get_plugin_data( $plugin_file_path, false, false, false );
				$new_version = $plugin_data['Version'] ?? 'N/A';

				// Re-activate plugin if it was active before update.
				if ( ! empty( $before_state['is_network_active'] ) ) {
					// Network activate if it was network active.
					activate_plugin( $plugin, '', true, true );
					Log::info(
						"Plugin re-activated (network) after update: {$plugin_name}",
						array(
							'feature'     => 'plugins',
							'action'      => 'plugin_reactivated_network',
							'plugin_slug' => $plugin,
							'plugin_name' => $plugin_name,
						)
					);
				} elseif ( ! empty( $before_state['is_active'] ) ) {
					// Activate if it was active on single site.
					activate_plugin( $plugin, '', false, true );
					Log::info(
						"Plugin re-activated after update: {$plugin_name}",
						array(
							'feature'     => 'plugins',
							'action'      => 'plugin_reactivated',
							'plugin_slug' => $plugin,
							'plugin_name' => $plugin_name,
						)
					);
				}

				// Capture AFTER state for history.
				$after_state = $this->capture_plugin_state( $plugin );

				// Record history (flag already set before upgrade).
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_update',
						'item_type'     => 'plugin',
						'item_slug'     => $plugin,
						'item_name'     => $plugin_name,
						'action_status' => 'success',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'old_version' => $last_version,
							'new_version' => $new_version,
							'source'      => 'wptw_plugin',
						),
					),
					false
				); // Flag already set before upgrade.

				// Log successful plugin update.
				Log::info(
					'Your website has been successfully updated to the latest version. All changes were applied without issues.',
					array(
						'feature'        => 'plugins',
						'action'         => 'plugin_update_completed',
						'plugin_slug'    => $plugin,
						'plugin_name'    => $plugin_name,
						'last_version'   => $last_version,
						'new_version'    => $new_version,
						'update_version' => $update_version,
						'title'          => 'Update Installed',
						'meta_data'      => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Updated',
							'plugin_slug'  => $plugin,
							'plugin_name'  => $plugin_name,
							'from_version' => $last_version,
							'to_version'   => $new_version,
						),
					)
				);

				return array(
					'message' => __( 'Plugin updated successfully.', 'tailwatch' ),
					'plugin'  => array(
						'slug'            => $plugin,
						'plugin_name'     => $plugin_name,
						'current_version' => $new_version,
						'last_version'    => $last_version,
						'update_version'  => $update_version,
					),
					'code'    => 200,
				);
			}
		} catch ( \Throwable $e ) {
			// Log exception with full context.
			Log::error(
				'We couldn\'t complete the update. This may be due to file permissions, server limits, or compatibility issues. Review logs or contact support.',
				array(
					'feature'        => 'plugins',
					'action'         => 'plugin_update_failed',
					'plugin_slug'    => $plugin_slug,
					'plugin_name'    => $plugin_name ? $plugin_name : 'Unknown',
					'last_version'   => $last_version,
					'update_version' => $update_version,
					'error_detail'   => $e->getMessage(),
					'exception'      => $e,
					'title'          => 'Update Failed',
					'meta_data'      => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Update failed',
						'plugin_slug'  => $plugin_slug,
						'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
						'from_version' => $last_version,
						'to_version'   => $update_version,
						'reason'       => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during plugin update.', 'tailwatch' ),
				'plugin'  => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get all installed plugins.
	 *
	 * @param string $post_data JSON encoded data containing pagination/filter info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message    Response message.
	 *     @type int    $code       HTTP response code.
	 *     @type array  $plugins    List of plugins.
	 *     @type array  $filters    Available filters.
	 *     @type array  $pagination Pagination parameters.
	 * }
	 */
	public function wptw_get_all_installed_plugins( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$page         = isset( $data['page'] ) ? intval( $data['page'] ) : 1;
			$limit        = isset( $data['limit'] ) ? intval( $data['limit'] ) : 10;
			$updates_only = isset( $data['updates_only'] ) ? (bool) $data['updates_only'] : false;

			// Call service layer.
			$plugins = PluginManagerService::wptw_get_all_plugins(
				array(
					'page'              => $page,
					'limit'             => $limit,
					'protected_plugins' => $this->protected_plugins,
					'updates_only'      => $updates_only,
				)
			);

			return array(
				'message'    => __( 'Plugins retrieved successfully.', 'tailwatch' ),
				'code'       => 200,
				'plugins'    => $plugins['plugins'] ?? array(),
				'filters'    => $plugins['filters'] ?? array(),
				'pagination' => $plugins['pagination'] ?? array(),
			);

		} catch ( \Throwable $e ) {
			return array(
				'message'    => __( 'Exception retrieving plugins.', 'tailwatch' ),
				'code'       => 500,
				'plugins'    => array(),
				'filters'    => array(),
				'pagination' => array(),
			);
		}
	}

	/**
	 * Activate a plugin.
	 *
	 * @param string $post_data JSON encoded data containing plugin info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $data    Optional additional data or activation result.
	 *     @type array  $plugin  Optional plugin data on success.
	 * }
	 */
	public function wptw_activate_plugin( $post_data ) {
		$plugin_slug = null;
		$plugin_name = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			if ( ! isset( $data['plugin'] ) || empty( $data['plugin'] ) ) {
				Log::warning(
					'Plugin activation failed: No plugin specified',
					array(
						'feature'   => 'plugins',
						'action'    => 'plugin_activate_failed',
						'error'     => 'No plugin specified in request',
						'meta_data' => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Activation failed',
							'reason' => 'No plugin specified',
						),
					)
				);
				return array(
					'message' => __( 'No plugin specified.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$plugin       = sanitize_text_field( $data['plugin'] );
			$plugin_slug  = $plugin;
			$network_wide = isset( $data['network_wide'] ) ? (bool) $data['network_wide'] : false;

			if ( ! PluginManagerService::validate_plugin_path( $plugin ) ) {
				Log::warning(
					'Plugin activation failed: Invalid plugin slug format',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_activate_failed',
						'plugin_slug' => $plugin,
						'error'       => 'Invalid plugin slug format',
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Activation failed',
							'plugin_slug' => $plugin,
							'reason'      => 'Invalid slug format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid plugin slug format. Expected format: plugin-folder/plugin-file.php or plugin-file.php', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			// Get plugin name before activation.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin;
			if ( file_exists( $plugin_file_path ) ) {
				$plugin_data = get_plugin_data( $plugin_file_path, false, false );
				$plugin_name = $plugin_data['Name'] ?? basename( $plugin );
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_plugin_state( $plugin );

			// Set flag BEFORE activation to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$result = PluginManagerService::wptw_activate_plugins( array( $plugin ), $network_wide );

			if ( ! empty( $result['failed'] ) ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_plugin_state( $plugin );

				// Record history for failed activation.
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_activate',
						'item_type'     => 'plugin',
						'item_slug'     => $plugin,
						'item_name'     => $plugin_name ? $plugin_name : 'Unknown',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'network_wide' => $network_wide,
							'error'        => $result['failed'][0]['error'] ?? 'Activation failed',
							'source'       => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				Log::error(
					"Plugin activation failed: {$plugin_name}",
					array(
						'feature'      => 'plugins',
						'action'       => 'plugin_activate_failed',
						'plugin_slug'  => $plugin,
						'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
						'network_wide' => $network_wide,
						'error'        => $result['failed'][0]['error'] ?? 'Activation failed',
						'meta_data'    => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Activation failed',
							'plugin_slug'  => $plugin,
							'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
							'network_wide' => $network_wide,
							'reason'       => 'Activation error',
						),
					)
				);
				return array(
					'message' => $result['failed'][0]['error'],
					'code'    => 500,
					'data'    => $result,
				);
			}

			// Capture AFTER state for history.
			$after_state = $this->capture_plugin_state( $plugin );

			// Record history for successful activation.
			HistoryService::record_action(
				array(
					'action_type'   => 'plugin_activate',
					'item_type'     => 'plugin',
					'item_slug'     => $plugin,
					'item_name'     => $plugin_name ? $plugin_name : 'Unknown',
					'action_status' => 'success',
					'triggered_by'  => $triggered_by,
					'before_state'  => $before_state,
					'after_state'   => $after_state,
					'metadata'      => array(
						'network_wide' => $network_wide,
						'source'       => 'wptw_plugin',
					),
				),
				false
			); // Flag already set above.

			// Log successful activation.
			Log::info(
				"Plugin activated successfully: {$plugin_name}",
				array(
					'feature'      => 'plugins',
					'action'       => 'plugin_activate_completed',
					'plugin_slug'  => $plugin,
					'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
					'network_wide' => $network_wide,
					'title'        => 'Plugin Activated',
					'meta_data'    => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Activated',
						'plugin_slug'  => $plugin,
						'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
						'network_wide' => $network_wide,
					),
				)
			);

			return array(
				'message' => __( 'Plugin activated successfully.', 'tailwatch' ),
				'plugin'  => $result['activated'][0] ?? array(),
				'code'    => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Plugin activation failed: Exception occurred',
				array(
					'feature'     => 'plugins',
					'action'      => 'plugin_activate_failed',
					'plugin_slug' => $plugin_slug,
					'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
					'error'       => $e->getMessage(),
					'exception'   => $e,
					'title'       => 'Plugin Activation Failed',
					'meta_data'   => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Activation failed',
						'plugin_slug' => $plugin_slug,
						'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
						'reason'      => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during plugin activation.', 'tailwatch' ),
				'plugin'  => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Deactivate a plugin.
	 *
	 * @param string $post_data JSON encoded data containing plugin info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $plugin  Optional plugin data on success or error.
	 * }
	 */
	public function wptw_deactivate_plugin( $post_data ) {
		$plugin_slug = null;
		$plugin_name = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			if ( ! isset( $data['plugin'] ) || empty( $data['plugin'] ) ) {
				Log::warning(
					'Plugin deactivation failed: No plugin specified',
					array(
						'feature'   => 'plugins',
						'action'    => 'plugin_deactivate_failed',
						'error'     => 'No plugin specified in request',
						'meta_data' => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Deactivation failed',
							'reason' => 'No plugin specified',
						),
					)
				);
				return array(
					'message' => __( 'No plugin specified.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			$plugin       = sanitize_text_field( $data['plugin'] );
			$plugin_slug  = $plugin;
			$network_wide = isset( $data['network_wide'] ) ? (bool) $data['network_wide'] : false;

			if ( ! PluginManagerService::validate_plugin_path( $plugin ) ) {
				Log::warning(
					'Plugin deactivation failed: Invalid plugin slug format',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_deactivate_failed',
						'plugin_slug' => $plugin,
						'error'       => 'Invalid plugin slug format',
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Deactivation failed',
							'plugin_slug' => $plugin,
							'reason'      => 'Invalid slug format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid plugin slug format. Expected format: plugin-folder/plugin-file.php or plugin-file.php', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			// Refuse to deactivate Tailwatch from within Tailwatch. The user can
			// still deactivate it from WordPress's own Plugins screen.
			if ( in_array( $plugin, $this->protected_plugins, true ) ) {
				Log::warning(
					'Plugin deactivation refused: protected plugin',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_deactivate_refused',
						'plugin_slug' => $plugin,
					)
				);
				return array(
					'message' => __( 'This plugin is protected and cannot be deactivated through Tailwatch. Use the WordPress Plugins screen instead.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 403,
				);
			}

			// Get plugin name before deactivation.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin;
			if ( file_exists( $plugin_file_path ) ) {
				$plugin_data = get_plugin_data( $plugin_file_path, false, false );
				$plugin_name = $plugin_data['Name'] ?? basename( $plugin );
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_plugin_state( $plugin );

			// Set flag BEFORE deactivation to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$result = PluginManagerService::wptw_deactivate_plugins( array( $plugin ), $network_wide );

			if ( ! empty( $result['failed'] ) ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_plugin_state( $plugin );

				// Record history for failed deactivation.
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_deactivate',
						'item_type'     => 'plugin',
						'item_slug'     => $plugin,
						'item_name'     => $plugin_name ? $plugin_name : 'Unknown',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'network_wide' => $network_wide,
							'error'        => $result['failed'][0]['error'] ?? 'Deactivation failed',
							'source'       => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				Log::error(
					"Plugin deactivation failed: {$plugin_name}",
					array(
						'feature'      => 'plugins',
						'action'       => 'plugin_deactivate_failed',
						'plugin_slug'  => $plugin,
						'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
						'network_wide' => $network_wide,
						'error'        => $result['failed'][0]['error'] ?? 'Deactivation failed',
						'meta_data'    => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Deactivation failed',
							'plugin_slug'  => $plugin,
							'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
							'network_wide' => $network_wide,
							'reason'       => 'Deactivation error',
						),
					)
				);
				return array(
					'message' => $result['failed'][0]['error'],
					'plugin'  => $result,
					'code'    => 500,
				);
			}

			// Capture AFTER state for history.
			$after_state = $this->capture_plugin_state( $plugin );

			// Record history for successful deactivation.
			HistoryService::record_action(
				array(
					'action_type'   => 'plugin_deactivate',
					'item_type'     => 'plugin',
					'item_slug'     => $plugin,
					'item_name'     => $plugin_name ? $plugin_name : 'Unknown',
					'action_status' => 'success',
					'triggered_by'  => $triggered_by,
					'before_state'  => $before_state,
					'after_state'   => $after_state,
					'metadata'      => array(
						'network_wide' => $network_wide,
						'source'       => 'wptw_plugin',
					),
				),
				false
			); // Flag already set above.

			// Log successful deactivation.
			Log::info(
				"Plugin deactivated successfully: {$plugin_name}",
				array(
					'feature'      => 'plugins',
					'action'       => 'plugin_deactivate_completed',
					'plugin_slug'  => $plugin,
					'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
					'network_wide' => $network_wide,
					'title'        => 'Plugin Deactivated',
					'meta_data'    => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Deactivated',
						'plugin_slug'  => $plugin,
						'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
						'network_wide' => $network_wide,
					),
				)
			);

			return array(
				'message' => __( 'Plugin deactivated successfully.', 'tailwatch' ),
				'plugin'  => $result['deactivated'][0] ?? array(),
				'code'    => 200,

			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Plugin deactivation failed: Exception occurred',
				array(
					'feature'     => 'plugins',
					'action'      => 'plugin_deactivate_failed',
					'plugin_slug' => $plugin_slug,
					'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
					'error'       => $e->getMessage(),
					'exception'   => $e,
					'title'       => 'Plugin Deactivation Failed',
					'meta_data'   => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Deactivation failed',
						'plugin_slug' => $plugin_slug,
						'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
						'reason'      => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during plugin deactivation.', 'tailwatch' ),
				'plugin'  => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Delete a plugin.
	 *
	 * @param string $post_data JSON encoded data containing plugin info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $data    Optional deletion result data.
	 * }
	 */
	public function wptw_delete_plugin( $post_data ) {
		$plugin_slug = null;
		$plugin_name = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			if ( ! isset( $data['plugin'] ) || empty( $data['plugin'] ) ) {
				Log::warning(
					'Plugin deletion failed: No plugin specified',
					array(
						'feature'   => 'plugins',
						'action'    => 'plugin_delete_failed',
						'error'     => 'No plugin specified in request',
						'meta_data' => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Deletion failed',
							'reason' => 'No plugin specified',
						),
					)
				);
				return array(
					'message' => __( 'No plugin specified.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$plugin      = sanitize_text_field( $data['plugin'] );
			$plugin_slug = $plugin;

			if ( ! PluginManagerService::validate_plugin_path( $plugin ) ) {
				Log::warning(
					'Plugin deletion failed: Invalid plugin slug format',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_delete_failed',
						'plugin_slug' => $plugin,
						'error'       => 'Invalid plugin slug format',
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Deletion failed',
							'plugin_slug' => $plugin,
							'reason'      => 'Invalid slug format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid plugin slug format. Expected format: plugin-folder/plugin-file.php or plugin-file.php', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			// Refuse to delete Tailwatch from within Tailwatch.
			if ( in_array( $plugin, $this->protected_plugins, true ) ) {
				Log::warning(
					'Plugin deletion refused: protected plugin',
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_delete_refused',
						'plugin_slug' => $plugin,
					)
				);
				return array(
					'message' => __( 'This plugin is protected and cannot be deleted through Tailwatch. Use the WordPress Plugins screen instead.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 403,
				);
			}

			// Get plugin name before deletion.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin;
			if ( file_exists( $plugin_file_path ) ) {
				$plugin_data = get_plugin_data( $plugin_file_path, false, false );
				$plugin_name = $plugin_data['Name'] ?? basename( $plugin );
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_plugin_state( $plugin );

			// Set flag BEFORE deletion to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$result = PluginManagerService::wptw_delete_plugins( array( $plugin ) );

			if ( ! empty( $result['failed'] ) ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_plugin_state( $plugin );

				// Record history for failed deletion.
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_delete',
						'item_type'     => 'plugin',
						'item_slug'     => $plugin,
						'item_name'     => $plugin_name ? $plugin_name : 'Unknown',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'error'  => $result['failed'][0]['error'] ?? 'Deletion failed',
							'source' => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				Log::error(
					"Plugin deletion failed: {$plugin_name}",
					array(
						'feature'     => 'plugins',
						'action'      => 'plugin_delete_failed',
						'plugin_slug' => $plugin,
						'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
						'error'       => $result['failed'][0]['error'] ?? 'Deletion failed',
						'meta_data'   => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Deletion failed',
							'plugin_slug' => $plugin,
							'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
							'reason'      => 'Delete error',
						),
					)
				);
				return array(
					'message' => $result['failed'][0]['error'],
					'code'    => 500,
					'data'    => $result,
				);
			}

			// After state is null (plugin deleted).
			$after_state = null;

			// Record history for successful deletion.
			HistoryService::record_action(
				array(
					'action_type'   => 'plugin_delete',
					'item_type'     => 'plugin',
					'item_slug'     => $plugin,
					'item_name'     => $plugin_name ? $plugin_name : 'Unknown',
					'action_status' => 'success',
					'triggered_by'  => $triggered_by,
					'before_state'  => $before_state,
					'after_state'   => $after_state,
					'metadata'      => array(
						'source' => 'wptw_plugin',
					),
				),
				false
			); // Flag already set above.

			// Log successful deletion.
			Log::info(
				"Plugin deleted successfully: {$plugin_name}",
				array(
					'feature'     => 'plugins',
					'action'      => 'plugin_delete_completed',
					'plugin_slug' => $plugin,
					'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
					'title'       => 'Plugin Deleted',
					'meta_data'   => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Deleted',
						'plugin_slug' => $plugin,
						'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
					),
				)
			);

			return array(
				'message' => __( 'Plugin deleted successfully.', 'tailwatch' ),
				'code'    => 200,
				'data'    => $result['deleted'][0] ?? array(),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Plugin deletion failed: Exception occurred',
				array(
					'feature'     => 'plugins',
					'action'      => 'plugin_delete_failed',
					'plugin_slug' => $plugin_slug,
					'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
					'error'       => $e->getMessage(),
					'exception'   => $e,
					'title'       => 'Plugin Deletion Failed',
					'meta_data'   => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Deletion failed',
						'plugin_slug' => $plugin_slug,
						'plugin_name' => $plugin_name ? $plugin_name : 'Unknown',
						'reason'      => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during plugin deletion.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	/**
	 * Perform bulk actions on plugins.
	 *
	 * @param string $post_data JSON encoded data containing action and plugins list.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message     Response message.
	 *     @type array  $plugins     Result data including success/fail counts.
	 *     @type int    $code        HTTP response code.
	 *     @type bool   $site_health Optional site health check trigger.
	 * }
	 */
	public function wptw_bulk_plugin_action( $post_data ) {
		$action        = null;
		$plugins_count = 0;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data['action'] ) || ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) ) {
				Log::warning(
					'Bulk plugin action failed: Invalid parameters',
					array(
						'feature'   => 'plugins',
						'action'    => 'plugin_bulk_action_failed',
						'error'     => 'Invalid parameters: missing action or plugins array',
						'meta_data' => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Bulk action failed',
							'reason' => 'Missing required information',
						),
					)
				);
				return array(
					'message' => __( 'Invalid parameters.', 'tailwatch' ),
					'plugins' => array(),
					'code'    => 400,
				);
			}

			$action        = sanitize_text_field( $data['action'] );
			$network_wide  = isset( $data['network_wide'] ) ? (bool) $data['network_wide'] : false;
			$plugins_count = count( $data['plugins'] );

			$allowed_actions = array( 'activate', 'deactivate', 'delete' );
			if ( ! in_array( $action, $allowed_actions, true ) ) {
				Log::warning(
					'Bulk plugin action failed: Invalid action',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_bulk_action_failed',
						'requested_action' => $action,
						'error'            => 'Invalid action. Allowed: ' . implode( ', ', $allowed_actions ),
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Bulk action failed',
							'bulk_action' => (string) $action,
							'reason'      => 'Invalid action',
						),
					)
				);
				return array(
					'message' => __( 'Invalid action. Allowed: ', 'tailwatch' ) . implode( ', ', $allowed_actions ),
					'plugins' => array(),
					'code'    => 400,
				);
			}

			// Validate all plugin slugs format: must be "folder/file.php".
			$valid_plugins       = array();
			$validation_failures = array();

			foreach ( $data['plugins'] as $plugin ) {
				$sanitized_plugin = sanitize_text_field( $plugin );

				if ( empty( $sanitized_plugin ) ) {
					$validation_failures[] = array(
						'plugin' => $plugin,
						'error'  => 'Empty plugin slug',
					);
					continue;
				}

				if ( ! PluginManagerService::validate_plugin_path( $sanitized_plugin ) ) {
					$validation_failures[] = array(
						'plugin' => $sanitized_plugin,
						'error'  => 'Invalid format. Expected: folder/file.php',
					);
					continue;
				}

				// Refuse to deactivate or delete Tailwatch from within Tailwatch.
				// Activate is allowed (re-activating Tailwatch from a bulk batch is harmless).
				if ( in_array( $action, array( 'deactivate', 'delete' ), true ) && in_array( $sanitized_plugin, $this->protected_plugins, true ) ) {
					$validation_failures[] = array(
						'plugin' => $sanitized_plugin,
						'error'  => 'Protected plugin cannot be ' . $action . 'd through Tailwatch.',
					);
					continue;
				}

				// Check if plugin exists for delete action.
				if ( 'delete' === $action ) {
					$plugin_file_path = WP_PLUGIN_DIR . '/' . $sanitized_plugin;
					if ( ! file_exists( $plugin_file_path ) ) {
						$validation_failures[] = array(
							'plugin' => $sanitized_plugin,
							'error'  => 'Plugin file not found',
						);
						continue;
					}
				}

				$valid_plugins[] = $sanitized_plugin;
			}

			// If no valid plugins, return error with validation failures.
			if ( empty( $valid_plugins ) ) {
				return array(
					'message'     => __( 'No valid plugins provided. All plugins failed validation.', 'tailwatch' ),
					'plugins'     => array(
						'failed' => $validation_failures,
					),
					'code'        => 400,
					'site_health' => true,
				);
			}

			// Process only valid plugins.
			$result = array();
			switch ( $action ) {
				case 'activate':
					$result = PluginManagerService::wptw_activate_plugins( $valid_plugins, $network_wide );
					break;
				case 'deactivate':
					$result = PluginManagerService::wptw_deactivate_plugins( $valid_plugins, $network_wide );
					break;
				case 'delete':
					$result = PluginManagerService::wptw_delete_plugins( $valid_plugins );
					break;
			}

			// Merge validation failures with operation failures (no duplicates).
			if ( ! empty( $validation_failures ) ) {
				if ( isset( $result['failed'] ) ) {
					// Ensure no duplicate entries by checking plugin slugs.
					$existing_failed_slugs = array_column( $result['failed'], 'plugin' );
					foreach ( $validation_failures as $failure ) {
						if ( ! in_array( $failure['plugin'], $existing_failed_slugs, true ) ) {
							$result['failed'][] = $failure;
						}
					}
				} else {
					$result['failed'] = $validation_failures;
				}
			}

			// Calculate counts.
			$success_key = 'processed'; // Default key.
			switch ( $action ) {
				case 'activate':
					$success_key = 'activated';
					break;
				case 'deactivate':
					$success_key = 'deactivated';
					break;
				case 'delete':
					$success_key = 'deleted';
					break;
			}

			$success_count = count( $result[ $success_key ] ?? array() );
			$failed_count  = count( $result['failed'] ?? array() );

			$message = "Bulk action completed. Success: {$success_count}, Failed: {$failed_count}";
			if ( ! empty( $validation_failures ) ) {
				$message .= '. ' . count( $validation_failures ) . ' plugins failed validation.';
			}

			// Log bulk action result.
			if ( $failed_count > 0 ) {
				Log::warning(
					"Bulk plugin {$action} completed with failures",
					array(
						'feature'       => 'plugins',
						'action'        => "plugin_{$action}_failed",
						'bulk_action'   => $action,
						'plugins_count' => $plugins_count,
						'success_count' => $success_count,
						'failed_count'  => $failed_count,
						'network_wide'  => $network_wide,
						'title'         => "Bulk Plugin {$action}",
					)
				);
			} else {
				Log::info(
					"Bulk plugin {$action} completed successfully",
					array(
						'feature'       => 'plugins',
						'action'        => "plugin_{$action}_completed",
						'bulk_action'   => $action,
						'plugins_count' => $plugins_count,
						'success_count' => $success_count,
						'network_wide'  => $network_wide,
						'title'         => "Bulk Plugin {$action}",
					)
				);
			}

			return array(
				'message'     => $message,
				'plugins'     => $result,
				'code'        => 200,
				'site_health' => true,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Bulk plugin action failed: Exception occurred',
				array(
					'feature'       => 'plugins',
					'action'        => 'plugin_bulk_action_failed',
					'bulk_action'   => $action,
					'plugins_count' => $plugins_count,
					'error'         => $e->getMessage(),
					'exception'     => $e,
					'title'         => 'Bulk Plugin Action Failed',
					'meta_data'     => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Bulk action failed',
						'bulk_action' => (string) $action,
						'total_count' => (int) $plugins_count,
						'reason'      => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during bulk action.', 'tailwatch' ),
				'plugins' => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get available versions for a plugin.
	 *
	 * @param string $post_data JSON encoded data containing plugin slug.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message  Response message.
	 *     @type int    $code     HTTP response code.
	 *     @type string $name     Plugin name.
	 *     @type string $slug     Plugin slug.
	 *     @type string $type     Item type (plugin).
	 *     @type array  $versions List of available versions.
	 * }
	 */
	public function wptw_get_plugin_versions( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$slug = isset( $data['plugin'] ) ? sanitize_text_field( $data['plugin'] ) : '';

			if ( empty( $slug ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'Plugin slug is required.', 'tailwatch' ),
				);
			}

			$response = wp_remote_get( "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]={$slug}&plugin" );

			if ( is_wp_error( $response ) ) {
				return array(
					'message' => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'code'    => 503,
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$data          = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $response_code ) {
				// 404 with error body = plugin not found in WP.org repo.
				if ( 404 === $response_code && $data && isset( $data['error'] ) ) {
					return array(
						'message' => __( 'This plugin does not appear to be available in the WordPress.org repository.', 'tailwatch' ),
						'code'    => 404,
					);
				}
				// Any other non-200 (5xx, etc.) = unexpected server error.
				return array(
					'message' => __( 'Unable to retrieve plugin information from WordPress.org. Please try again later.', 'tailwatch' ),
					'code'    => 502,
				);
			}

			if ( ! $data || isset( $data['error'] ) || ! isset( $data['versions'] ) || ! is_array( $data['versions'] ) ) {
				return array(
					'message' => __( 'This plugin does not appear to be available in the WordPress.org repository.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			return array(
				'name'     => $data['name'],
				'slug'     => $data['slug'],
				'type'     => 'plugin',
				'versions' => $data['versions'],
				'code'     => 200,
				'message'  => __( 'Plugin Versions Retrieved Successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Exception retrieving plugin versions.', 'tailwatch' ),
				'code'    => 500,
				'plugin'  => array(),
			);
		}
	}

	/**
	 * Get plugin details.
	 *
	 * @param string $post_data JSON encoded data containing plugin slug.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $plugin  Plugin details.
	 * }
	 */
	public function wptw_plugin_details( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$slug = isset( $data['plugin'] ) ? sanitize_text_field( $data['plugin'] ) : '';
			if ( empty( $slug ) ) {
				return array(
					'message' => __( 'Plugin slug not provided.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Accept the full plugin basename (folder/plugin.php — as sent by update/
			// activate requests and carried in notification meta) as well as the bare
			// wp.org directory slug. wptw_plugin_theme_details() needs the directory slug.
			if ( false !== strpos( $slug, '/' ) ) {
				$slug = dirname( $slug );
			}

			// Use PluginThemeService to get plugin details.
			$service = new PluginThemeService();
			$result  = $service->wptw_plugin_theme_details( $slug, 'plugin' );

			if ( $result['success'] ) {
				return array(
					'message' => $result['message'],
					'plugin'  => $result,
					'code'    => 200,
				);
			} elseif ( isset( $result['error_type'] ) && 'network_error' === $result['error_type'] ) {
				return array(
					'message' => $result['message'],
					'plugin'  => array(),
					'code'    => 503,
				);
			} else {
				return array(
					'message' => $result['message'],
					'plugin'  => array(),
					'code'    => 404,
				);
			}
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Exception retrieving plugin details.', 'tailwatch' ),
				'plugin'  => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Rollback a plugin to a specific version.
	 *
	 * @param string $post_data JSON encoded data containing plugin slug and version.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $plugin  Result data including version info.
	 * }
	 */
	public function wptw_plugin_rollback( $post_data ) {
		$plugin_slug      = null;
		$plugin_name      = null;
		$current_version  = null;
		$rollback_version = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			$slug    = isset( $data['plugin'] ) ? sanitize_text_field( $data['plugin'] ) : '';
			$version = isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '';

			// Validate required parameters.
			if ( empty( $slug ) || empty( $version ) ) {
				Log::warning(
					'Plugin rollback failed: Missing required parameters',
					array(
						'feature'   => 'plugins',
						'action'    => 'plugin_rollback_failed',
						'error'     => 'Plugin slug or version not provided',
						'meta_data' => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'reason' => 'No plugin specified',
						),
					)
				);
				return array(
					'message' => __( 'Plugin slug or version not provided.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			$plugin_slug      = $slug;
			$rollback_version = $version;

			// Validate version format (supports standard versions and versions with text suffixes like beta, RC, dev).
			// Examples: 1.0.0, 3.18.0-beta1, 1.0.0-RC1, 2.0.0-dev, 1.0.0alpha.
			if ( ! preg_match( '/^\d+(\.\d+)*([-.]?[a-zA-Z0-9]+)*$/', $version ) ) {
				Log::warning(
					'Plugin rollback failed: Invalid version format',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'rollback_version' => $version,
						'error'            => 'Invalid version format',
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug' => $slug,
							'to_version'  => $version,
							'reason'      => 'Invalid version format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid version format. Expected format: X.X.X or X.X.X-suffix (e.g., 1.0.0, 3.18.0-beta1)', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			if ( ! PluginManagerService::validate_plugin_path( $slug ) ) {
				Log::warning(
					'Plugin rollback failed: Invalid plugin slug format',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'rollback_version' => $version,
						'error'            => 'Invalid plugin slug format',
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug' => $slug,
							'to_version'  => $version,
							'reason'      => 'Invalid slug format',
						),
					)
				);
				return array(
					'message' => "Plugin slug '$slug' does not match required format: 'folder/file.php' or 'file.php'",
					'plugin'  => array(),
					'code'    => 400,
				);
			}

			// Extract plugin directory from slug (e.g., "akismet/akismet.php" -> "akismet").
			$plugin_directory = explode( '/', $slug )[0];
			$plugin_dir_path  = WP_PLUGIN_DIR . '/' . $plugin_directory;

			// Check if plugin directory exists.
			if ( ! file_exists( $plugin_dir_path ) || ! is_dir( $plugin_dir_path ) ) {
				Log::error(
					'Plugin rollback failed: Plugin not installed',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'rollback_version' => $version,
						'error'            => "Plugin '{$slug}' is not installed",
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug' => $slug,
							'to_version'  => $version,
							'reason'      => 'Not installed',
						),
					)
				);
				return array(
					'message' => "Plugin '$slug' is not installed. Only installed plugins can be rolled back.",
					'plugin'  => array(),
					'code'    => 404,
				);
			}

			// Check if the main plugin file exists.
			$plugin_file_path = WP_PLUGIN_DIR . '/' . $slug;
			if ( ! file_exists( $plugin_file_path ) ) {
				Log::error(
					'Plugin rollback failed: Plugin file not found',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'rollback_version' => $version,
						'error'            => "Plugin file '{$slug}' not found",
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug' => $slug,
							'to_version'  => $version,
							'reason'      => 'Plugin not found',
						),
					)
				);
				return array(
					'message' => "Plugin file '$slug' not found. Please check the plugin slug format (e.g., 'plugin-folder/plugin-file.php').",
					'plugin'  => array(),
					'code'    => 404,
				);
			}

			// Get current plugin version.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_data     = get_plugin_data( $plugin_file_path, false );
			$current_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : null;
			$plugin_name     = $plugin_data['Name'] ?? $plugin_directory;

			if ( empty( $current_version ) ) {
				Log::error(
					'Plugin rollback failed: Unable to read current version',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'plugin_name'      => $plugin_name,
						'rollback_version' => $version,
						'error'            => 'Unable to read current plugin version',
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug' => $slug,
							'plugin_name' => $plugin_name,
							'to_version'  => $version,
							'reason'      => 'Unable to read version',
						),
					)
				);
				return array(
					'message' => __( 'Unable to read current plugin version.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 500,
				);
			}

			// Check if trying to rollback to the same version.
			if ( version_compare( $current_version, $version, '==' ) ) {
				// Not an error, just informational.
				return array(
					'message' => "Plugin is already at version {$version}.",
					'plugin'  => array(
						'slug'            => $slug,
						'current_version' => $current_version,
					),
					'code'    => 400,
				);
			}

			// Check if the version exists in WordPress.org repository.
			$check_url = "https://downloads.wordpress.org/plugin/{$plugin_directory}.{$version}.zip";
			$response  = wp_remote_head(
				$check_url,
				array(
					'timeout'     => 10,
					'redirection' => 5,
				)
			);

			if ( is_wp_error( $response ) ) {
				Log::error(
					"Plugin rollback failed: {$plugin_name} - Connection error",
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'plugin_name'      => $plugin_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'error'            => $response->get_error_message(),
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug'  => $slug,
							'plugin_name'  => $plugin_name,
							'from_version' => $current_version,
							'to_version'   => $version,
							'reason'       => 'WordPress error',
						),
					)
				);
				return array(
					'message' => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 503,
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				if ( 404 === $response_code ) {
					Log::error(
						"Plugin rollback failed: {$plugin_name} - Version not found in repository",
						array(
							'feature'          => 'plugins',
							'action'           => 'plugin_rollback_failed',
							'plugin_slug'      => $slug,
							'plugin_name'      => $plugin_name,
							'current_version'  => $current_version,
							'rollback_version' => $version,
							'error'            => "Version {$version} not found in WordPress.org repository",
							'meta_data'        => array(
								'feature' => 'Plugin Updates',
								'event'   => 'Rollback failed',
								'plugin_slug'  => $slug,
								'plugin_name'  => $plugin_name,
								'from_version' => $current_version,
								'to_version'   => $version,
								'reason'       => 'Version not found',
							),
						)
					);
					return array(
						'message' => "Version {$version} of this plugin does not appear to be available in the WordPress.org repository.",
						'plugin'  => array(),
						'code'    => 404,
					);
				}

				// Any other non-200 (5xx, etc.) = server/network error.
				Log::error(
					"Plugin rollback failed: {$plugin_name} - WordPress.org server error",
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'plugin_name'      => $plugin_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'response_code'    => $response_code,
						'error'            => "Unexpected response code {$response_code} from WordPress.org",
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug'  => $slug,
							'plugin_name'  => $plugin_name,
							'from_version' => $current_version,
							'to_version'   => $version,
							'response_code' => (int) $response_code,
							'reason'       => 'WordPress error',
						),
					)
				);
				return array(
					'message' => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'plugin'  => array(),
					'code'    => 503,
				);
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_plugin_state( $slug );

			// Set flag BEFORE rollback to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$service = new PluginThemeService();
			$result  = $service->wptw_rollback( 'plugin', $slug, $version );

			if ( $result['success'] ) {
				// Capture AFTER state for history.
				$after_state = $this->capture_plugin_state( $slug );

				// Record history for successful rollback.
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_rollback',
						'item_type'     => 'plugin',
						'item_slug'     => $slug,
						'item_name'     => $plugin_name,
						'action_status' => 'success',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'old_version' => $current_version,
							'new_version' => $version,
							'source'      => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				// Log successful rollback.
				Log::info(
					'Your website has been successfully restored to the selected previous version. Please verify that everything is working correctly.',
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_completed',
						'plugin_slug'      => $slug,
						'plugin_name'      => $plugin_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'title'            => 'Rollback Completed',
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rolled back',
							'plugin_slug'  => $slug,
							'plugin_name'  => $plugin_name,
							'from_version' => $current_version,
							'to_version'   => $version,
						),
					)
				);

				return array(
					'message' => $result['message'],
					'plugin'  => array(
						'slug'             => $slug,
						'plugin_name'      => $plugin_name,
						'previous_version' => $current_version,
						'rollback_version' => $version,
					),
					'code'    => 200,
				);
			} else {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_plugin_state( $slug );

				// Record history for failed rollback.
				HistoryService::record_action(
					array(
						'action_type'   => 'plugin_rollback',
						'item_type'     => 'plugin',
						'item_slug'     => $slug,
						'item_name'     => $plugin_name,
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'old_version' => $current_version,
							'new_version' => $version,
							'error'       => $result['message'] ?? 'Rollback failed',
							'source'      => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				Log::error(
					"Plugin rollback failed: {$plugin_name}",
					array(
						'feature'          => 'plugins',
						'action'           => 'plugin_rollback_failed',
						'plugin_slug'      => $slug,
						'plugin_name'      => $plugin_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'error'            => $result['message'] ?? 'Rollback failed',
						'meta_data'        => array(
							'feature' => 'Plugin Updates',
							'event'   => 'Rollback failed',
							'plugin_slug'  => $slug,
							'plugin_name'  => $plugin_name,
							'from_version' => $current_version,
							'to_version'   => $version,
							'reason'       => 'Rollback error',
						),
					)
				);
				return array(
					'message' => $result['message'],
					'plugin'  => array(),
					'code'    => 500,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'We couldn\'t restore your website to the selected version. This may be due to missing backup files or server restrictions. Contact support for help.',
				array(
					'feature'          => 'plugins',
					'action'           => 'plugin_rollback_failed',
					'plugin_slug'      => $plugin_slug,
					'plugin_name'      => $plugin_name ? $plugin_name : 'Unknown',
					'current_version'  => $current_version,
					'rollback_version' => $rollback_version,
					'error_detail'            => $e->getMessage(),
					'exception'        => $e,
					'title'     => 'Rollback Failed',
					'meta_data'        => array(
						'feature' => 'Plugin Updates',
						'event'   => 'Rollback failed',
						'plugin_slug'  => $plugin_slug,
						'plugin_name'  => $plugin_name ? $plugin_name : 'Unknown',
						'from_version' => $current_version,
						'to_version'   => $rollback_version,
						'reason'       => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during plugin rollback.', 'tailwatch' ),
				'plugin'  => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Check plugin compatibility.
	 *
	 * Validates if upgrade/downgrade to a specific version is safe by checking
	 * WordPress/PHP compatibility and plugin dependencies.
	 *
	 * @param string $post_data JSON encoded POST data containing 'plugin' and 'version'.
	 *
	 * @return array Response data with compatibility check results.
	 */
	public function wptw_check_plugin_compatibility( $post_data ) {
		$plugin_slug = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data || ! isset( $data['plugin'] ) || ! isset( $data['version'] ) ) {
				Log::warning(
					'Plugin compatibility check failed: Invalid parameters',
					array(
						'feature' => 'plugins',
						'action'  => 'plugin_compatibility_check_failed',
						'error'   => 'Invalid parameters: plugin and version are required',
					)
				);
				return array(
					'message' => __( 'Invalid parameters. Plugin and version are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$slug           = sanitize_text_field( $data['plugin'] );
			$target_version = sanitize_text_field( $data['version'] );
			$plugin_slug    = $slug;

			if ( empty( $slug ) || empty( $target_version ) ) {
				return array(
					'message' => __( 'Plugin slug and version are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Resolve any input shape (file path, folder slug, single-file basename,
			// or canonical WP.org slug) to an installed plugin file. This is what
			// makes single-file plugins like Hello Dolly work: input "hello.php"
			// or "hello" or "hello-dolly" all resolve to the same installed file.
			$plugin_file     = PluginManagerService::get_plugin_file_by_slug( $slug );
			$current_version = '0.0.0';

			if ( $plugin_file ) {
				$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_file;
				if ( file_exists( $plugin_file_path ) ) {
					if ( ! function_exists( 'get_plugin_data' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$plugin_data     = get_plugin_data( $plugin_file_path, false );
					$current_version = $plugin_data['Version'] ?? '0.0.0';
				}
			}

			// Derive the canonical WP.org slug from the resolved file. WordPress
			// stores this in the update_plugins transient -- it is not always
			// derivable from the file path alone (e.g. "hello.php" -> "hello-dolly").
			// If we could not resolve a file, treat the input itself as the API slug.
			$api_slug = $plugin_file
				? ( PluginManagerService::get_wporg_slug_by_file( $plugin_file ) ?? $slug )
				: $slug;

			// Perform compatibility check.
			$compatibility = CompatibilityService::check_compatibility(
				'plugin',
				$api_slug,
				$target_version,
				$current_version
			);

			Log::info(
				'Plugin compatibility check completed',
				array(
					'feature'         => 'plugins',
					'action'          => 'plugin_compatibility_check_completed',
					'plugin_slug'     => $slug,
					'current_version' => $current_version,
					'target_version'  => $target_version,
					'is_compatible'   => $compatibility['is_compatible'],
					'blocking_issues' => count( $compatibility['blocking_issues'] ),
					'warnings'        => count( $compatibility['warnings'] ),
				)
			);

			return array(
				'compatibility' => $compatibility,
				'code'          => 200,
				'message'       => __( 'Compatibility check completed successfully.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Plugin compatibility check failed: Exception occurred',
				array(
					'feature'     => 'plugins',
					'action'      => 'plugin_compatibility_check_failed',
					'plugin_slug' => $plugin_slug,
					'error'       => $e->getMessage(),
					'exception'   => $e,
					'title'     => 'Plugin Compatibility Check Failed',
				)
			);

			return array(
				'message' => __( 'Exception during compatibility check.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Capture current plugin state for history.
	 *
	 * @param string $plugin_slug Plugin file path.
	 *
	 * @return array|null Plugin state or null if not found.
	 */
	private function capture_plugin_state( $plugin_slug ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
		if ( ! file_exists( $plugin_file_path ) ) {
			return null;
		}

		$plugin_data = get_plugin_data( $plugin_file_path, false );
		$update_info = get_site_transient( 'update_plugins' );

		return array(
			'version'           => $plugin_data['Version'] ?? 'N/A',
			'name'              => $plugin_data['Name'] ?? $plugin_slug,
			'is_active'         => is_plugin_active( $plugin_slug ),
			'is_network_active' => is_plugin_active_for_network( $plugin_slug ),
			'update_available'  => isset( $update_info->response[ $plugin_slug ] ),
			'update_version'    => isset( $update_info->response[ $plugin_slug ] )
				? $update_info->response[ $plugin_slug ]->new_version
				: null,
			'author'            => $plugin_data['Author'] ?? 'N/A',
			'description'       => $plugin_data['Description'] ?? '',
		);
	}
}
