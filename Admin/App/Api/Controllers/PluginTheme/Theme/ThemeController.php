<?php
/**
 * Theme Controller
 *
 * Manages theme operations such as update, install, activate, and delete.
 *
 * @package    Tailwatch
 * @subpackage Controllers/PluginTheme/Theme
 */

namespace Tailwatch\Admin\App\Api\Controllers\PluginTheme\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\PluginTheme\Theme\ThemeManagerService;
use Tailwatch\Admin\App\Api\Services\PluginTheme\PluginThemeService;
use Tailwatch\Admin\App\Api\Services\Common\CompatibilityService;
use Tailwatch\Admin\App\Api\Services\Common\HistoryService;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\PluginThemeController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class ThemeController
 *
 * Handles theme management actions including updates, installations, and status changes.
 *
 */
class ThemeController extends BaseController {

	/**
	 * Feature check exemptions.
	 *
	 * @var array
	 */
	protected $wptw_feature_check_exemptions = array();

	/**
	 * Get feature status.
	 *
	 * @return array Feature enablement status.
	 */
	protected function wptw_get_feature_status() {
		$plugin_theme_controller = new PluginThemeController();
		return $plugin_theme_controller->wptw_theme_update_feature_enable();
	}

	/**
	 * Capture current theme state for history.
	 *
	 * @param string $theme_slug Theme slug.
	 *
	 * @return array|null Theme state or null if not found.
	 */
	private function capture_theme_state( $theme_slug ) {
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return null;
		}

		$current_theme = wp_get_theme();
		$update_info   = get_site_transient( 'update_themes' );

		return array(
			'version'          => $theme->get( 'Version' ),
			'name'             => $theme->get( 'Name' ),
			'is_active'        => ( $theme_slug === $current_theme->get_stylesheet() ),
			'is_parent'        => ! empty( $theme->get( 'Template' ) ),
			'parent_theme'     => $theme->get( 'Template' ),
			'update_available' => isset( $update_info->response[ $theme_slug ] ),
			'update_version'   => isset( $update_info->response[ $theme_slug ] )
				? $update_info->response[ $theme_slug ]['new_version']
				: null,
			'author'           => $theme->get( 'Author' ),
			'description'      => $theme->get( 'Description' ),
		);
	}

	/**
	 * Update a specific theme.
	 *
	 * @param string $post_data JSON encoded data containing theme info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message         Response message.
	 *     @type string $current_version Current version after update.
	 *     @type string $last_version    Previous version.
	 *     @type string $update_version  Version updated to.
	 *     @type int    $code            HTTP response code.
	 * }
	 */
	public function wptw_update_theme( $post_data ) {
		$theme_slug     = null;
		$theme_name     = null;
		$last_version   = null;
		$update_version = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			$theme = isset( $data['theme'] ) ? sanitize_text_field( $data['theme'] ) : '';
			if ( empty( $theme ) ) {
				Log::warning(
					'Theme update failed: No theme specified',
					array(
						'feature'   => 'themes',
						'action'    => 'theme_update_failed',
						'error'     => 'No theme specified in request',
						'meta_data' => array(
							'feature' => 'Theme Updates',
							'event'   => 'Update failed',
							'reason' => 'No theme specified',
						),
					)
				);
				return array(
					'message' => __( 'No theme specified.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$theme_slug = $theme;

			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/misc.php';

			$current_theme = wp_get_theme( $theme );
			if ( ! $current_theme->exists() ) {
				Log::error(
					'Theme update failed: Theme not found',
					array(
						'feature'    => 'themes',
						'action'     => 'theme_update_failed',
						'theme_slug' => $theme,
						'error'      => "Theme '{$theme}' is not installed or does not exist",
						'meta_data'  => array(
							'feature' => 'Theme Updates',
							'event'   => 'Update failed',
							'theme_slug' => $theme,
							'reason'     => 'Not installed',
						),
					)
				);
				return array(
					'message' => "Theme '$theme' is not installed or does not exist.",
					'code'    => 404,
				);
			}

			$last_version = $current_theme->get( 'Version' );
			$theme_name   = $current_theme->get( 'Name' ) ?? $theme;

			// Capture BEFORE state for history.
			$before_state = $this->capture_theme_state( $theme );

			// Check if update is available.
			$update_themes = get_site_transient( 'update_themes' );
			if ( ! isset( $update_themes->response[ $theme ] ) ) {
				// No update available - not an error, just informational.
				return array(
					'message'         => __( 'Theme is already at the latest version.', 'tailwatch' ),
					'current_version' => $last_version,
					'code'            => 200,
				);
			}

			$update_version = isset( $update_themes->response[ $theme ]['new_version'] )
				? $update_themes->response[ $theme ]['new_version']
				: 'N/A';

			// Set flag BEFORE upgrade to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			// Use Automatic_Upgrader_Skin for silent background/cron updates.
			// This prevents output and exit() calls that can interrupt batch operations.
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $theme );

			if ( is_wp_error( $result ) ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_theme_state( $theme );

				// Record history for failed update.
				HistoryService::record_action(
					array(
						'action_type'   => 'theme_update',
						'item_type'     => 'theme',
						'item_slug'     => $theme,
						'item_name'     => $theme_name,
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
					"Theme update failed: {$theme_name}",
					array(
						'feature'        => 'themes',
						'action'         => 'theme_update_failed',
						'theme_slug'     => $theme,
						'theme_name'     => $theme_name,
						'last_version'   => $last_version,
						'update_version' => $update_version,
						'error'          => $result->get_error_message(),
						'meta_data'      => array(
							'feature' => 'Theme Updates',
							'event'   => 'Update failed',
							'theme_slug'  => $theme,
							'theme_name'  => $theme_name,
							'from_version' => $last_version,
							'to_version'   => $update_version,
							'reason'       => 'Upgrade error',
						),
					)
				);
				return array(
					'message' => $result->get_error_message(),
					'code'    => 500,
				);
			} else {
				$theme_data  = wp_get_theme( $theme );
				$new_version = $theme_data->get( 'Version' );

				// Capture AFTER state for history.
				$after_state = $this->capture_theme_state( $theme );

				// Record history for successful update.
				HistoryService::record_action(
					array(
						'action_type'   => 'theme_update',
						'item_type'     => 'theme',
						'item_slug'     => $theme,
						'item_name'     => $theme_name,
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

				// Log successful theme update.
				Log::info(
					'Your website has been successfully updated to the latest version. All changes were applied without issues.',
					array(
						'feature'        => 'themes',
						'action'         => 'theme_update_completed',
						'theme_slug'     => $theme,
						'theme_name'     => $theme_name,
						'last_version'   => $last_version,
						'new_version'    => $new_version,
						'update_version' => $update_version,
						'title'          => 'Update Installed',
						'meta_data'      => array(
							'feature' => 'Theme Updates',
							'event'   => 'Updated',
							'theme_slug'  => $theme,
							'theme_name'  => $theme_name,
							'from_version' => $last_version,
							'to_version'   => $new_version,
						),
					)
				);

				return array(
					'message'         => __( 'Theme updated successfully.', 'tailwatch' ),
					'current_version' => $new_version,
					'last_version'    => $last_version,
					'update_version'  => $update_version,
					'code'            => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'We couldn\'t complete the update. This may be due to file permissions, server limits, or compatibility issues. Review logs or contact support.',
				array(
					'feature'        => 'themes',
					'action'         => 'theme_update_failed',
					'theme_slug'     => $theme_slug,
					'theme_name'     => $theme_name ? $theme_name : 'Unknown',
					'last_version'   => $last_version,
					'update_version' => $update_version,
					'error_detail'   => $e->getMessage(),
					'exception'      => $e,
					'title'     => 'Update Failed',
					'meta_data'     => array(
						'feature' => 'Theme Updates',
						'event'   => 'Update failed',
						'theme_slug'  => $theme_slug,
						'theme_name'  => $theme_name ? $theme_name : 'Unknown',
						'from_version' => $last_version,
						'to_version'   => $update_version,
						'reason'       => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during theme update.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get all installed themes.
	 *
	 * @param string $post_data JSON encoded data containing pagination/filter info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message    Response message.
	 *     @type int    $code       HTTP response code.
	 *     @type array  $themes     List of themes.
	 *     @type array  $filters    Available filters.
	 *     @type array  $pagination Pagination parameters.
	 * }
	 */
	public function wptw_get_all_installed_themes( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$page         = isset( $data['page'] ) ? intval( $data['page'] ) : 1;
			$limit        = isset( $data['limit'] ) ? intval( $data['limit'] ) : 10;
			$updates_only = isset( $data['updates_only'] ) ? (bool) $data['updates_only'] : false;

			// Call service layer.
			$themes = ThemeManagerService::wptw_get_all_themes(
				array(
					'page'         => $page,
					'limit'        => $limit,
					'updates_only' => $updates_only,
				)
			);

			return array(
				'message'    => __( 'Themes retrieved successfully.', 'tailwatch' ),
				'code'       => 200,
				'themes'     => $themes['themes'] ?? array(),
				'filters'    => $themes['filters'] ?? array(),
				'pagination' => $themes['pagination'] ?? array(),
			);

		} catch ( \Throwable $e ) {
			return array(
				'message'    => __( 'Exception retrieving themes.', 'tailwatch' ),
				'code'       => 500,
				'themes'     => array(),
				'filters'    => array(),
				'pagination' => array(),
			);
		}
	}

	/**
	 * Activate a theme.
	 *
	 * @param string $post_data JSON encoded data containing theme info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $theme   Optional theme data on success.
	 * }
	 */
	public function wptw_activate_theme( $post_data ) {
		$theme_slug = null;
		$theme_name = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			if ( ! isset( $data['theme'] ) || empty( $data['theme'] ) ) {
				Log::warning(
					'Theme activation failed: No theme specified',
					array(
						'feature'   => 'themes',
						'action'    => 'theme_activate_failed',
						'error'     => 'No theme specified in request',
						'meta_data' => array(
							'feature' => 'Theme Updates',
							'event'   => 'Activation failed',
							'reason' => 'No theme specified',
						),
					)
				);
				return array(
					'message' => __( 'No theme specified.', 'tailwatch' ),
					'code'    => 400,
					'theme'   => array(),
				);
			}

			$theme_slug = sanitize_text_field( $data['theme'] );

			$theme_obj = wp_get_theme( $theme_slug );
			if ( $theme_obj->exists() ) {
				$theme_name = $theme_obj->get( 'Name' ) ?? $theme_slug;
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_theme_state( $theme_slug );

			// Set flag BEFORE activation to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$result = ThemeManagerService::wptw_activate_theme( $theme_slug );

			if ( ! $result['success'] ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_theme_state( $theme_slug );

				// Record history for failed activation.
				HistoryService::record_action(
					array(
						'action_type'   => 'theme_activate',
						'item_type'     => 'theme',
						'item_slug'     => $theme_slug,
						'item_name'     => $theme_name ? $theme_name : 'Unknown',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'error'  => $result['error'] ?? 'Activation failed',
							'source' => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				Log::error(
					"Theme activation failed: {$theme_name}",
					array(
						'feature'    => 'themes',
						'action'     => 'theme_activate_failed',
						'theme_slug' => $theme_slug,
						'theme_name' => $theme_name ? $theme_name : 'Unknown',
						'error'      => $result['error'] ?? 'Activation failed',
						'meta_data'  => array(
							'feature' => 'Theme Updates',
							'event'   => 'Activation failed',
							'theme_slug' => $theme_slug,
							'theme_name' => $theme_name ? $theme_name : 'Unknown',
							'reason'     => 'Activation error',
						),
					)
				);
				return array(
					'message' => $result['error'],
					'code'    => 500,
					'theme'   => array(),
				);
			}

			// Capture AFTER state for history.
			$after_state = $this->capture_theme_state( $theme_slug );

			// Record history for successful activation.
			HistoryService::record_action(
				array(
					'action_type'   => 'theme_activate',
					'item_type'     => 'theme',
					'item_slug'     => $theme_slug,
					'item_name'     => $theme_name ? $theme_name : 'Unknown',
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

			// Log successful activation.
			Log::info(
				"Theme activated successfully: {$theme_name}",
				array(
					'feature'    => 'themes',
					'action'     => 'theme_activate_completed',
					'theme_slug' => $theme_slug,
					'theme_name' => $theme_name ? $theme_name : 'Unknown',
					'title'      => 'Theme Activated',
					'meta_data'  => array(
						'feature' => 'Theme Updates',
						'event'   => 'Activated',
						'theme_slug' => $theme_slug,
						'theme_name' => $theme_name ? $theme_name : 'Unknown',
					),
				)
			);

			return array(
				'message' => __( 'Theme activated successfully.', 'tailwatch' ),
				'code'    => 200,
				'theme'   => $result,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Theme activation failed: Exception occurred',
				array(
					'feature'    => 'themes',
					'action'     => 'theme_activate_failed',
					'theme_slug' => $theme_slug,
					'theme_name' => $theme_name ? $theme_name : 'Unknown',
					'error'      => $e->getMessage(),
					'exception'  => $e,
					'title'     => 'Theme Activation Failed',
					'meta_data' => array(
						'feature' => 'Theme Updates',
						'event'   => 'Activation failed',
						'theme_slug' => $theme_slug,
						'theme_name' => $theme_name ? $theme_name : 'Unknown',
						'reason'     => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during theme activation.', 'tailwatch' ),
				'code'    => 500,
				'theme'   => array(),
			);
		}
	}

	/**
	 * Delete a theme.
	 *
	 * @param string $post_data JSON encoded data containing theme info.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $theme   Optional deletion result data.
	 * }
	 */
	public function wptw_delete_theme( $post_data ) {
		$theme_slug = null;
		$theme_name = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			if ( ! isset( $data['theme'] ) || empty( $data['theme'] ) ) {
				Log::warning(
					'Theme deletion failed: No theme specified',
					array(
						'feature'   => 'themes',
						'action'    => 'theme_delete_failed',
						'error'     => 'No theme specified in request',
						'meta_data' => array(
							'feature' => 'Theme Updates',
							'event'   => 'Deletion failed',
							'reason' => 'No theme specified',
						),
					)
				);
				return array(
					'message' => __( 'No theme specified.', 'tailwatch' ),
					'code'    => 400,
					'theme'   => array(),
				);
			}

			$theme_slug = sanitize_text_field( $data['theme'] );

			$theme_obj = wp_get_theme( $theme_slug );
			if ( $theme_obj->exists() ) {
				$theme_name = $theme_obj->get( 'Name' ) ?? $theme_slug;
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_theme_state( $theme_slug );

			// Set flag BEFORE deletion to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$result = ThemeManagerService::wptw_delete_theme( $theme_slug );

			if ( ! $result['success'] ) {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_theme_state( $theme_slug );

				// Record history for failed deletion.
				HistoryService::record_action(
					array(
						'action_type'   => 'theme_delete',
						'item_type'     => 'theme',
						'item_slug'     => $theme_slug,
						'item_name'     => $theme_name ? $theme_name : 'Unknown',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'error'  => $result['error'] ?? 'Deletion failed',
							'source' => 'wptw_plugin',
						),
					),
					false
				); // Flag already set above.

				Log::error(
					"Theme deletion failed: {$theme_name}",
					array(
						'feature'    => 'themes',
						'action'     => 'theme_delete_failed',
						'theme_slug' => $theme_slug,
						'theme_name' => $theme_name ? $theme_name : 'Unknown',
						'error'      => $result['error'] ?? 'Deletion failed',
						'meta_data'  => array(
							'feature' => 'Theme Updates',
							'event'   => 'Deletion failed',
							'theme_slug' => $theme_slug,
							'theme_name' => $theme_name ? $theme_name : 'Unknown',
							'reason'     => 'Delete error',
						),
					)
				);
				return array(
					'message' => $result['error'],
					'code'    => 500,
					'theme'   => array(),
				);
			}

			// After state is null (theme deleted).
			$after_state = null;

			// Record history for successful deletion.
			HistoryService::record_action(
				array(
					'action_type'   => 'theme_delete',
					'item_type'     => 'theme',
					'item_slug'     => $theme_slug,
					'item_name'     => $theme_name ? $theme_name : 'Unknown',
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
				"Theme deleted successfully: {$theme_name}",
				array(
					'feature'    => 'themes',
					'action'     => 'theme_delete_completed',
					'theme_slug' => $theme_slug,
					'theme_name' => $theme_name ? $theme_name : 'Unknown',
					'title'      => 'Theme Deleted',
					'meta_data'  => array(
						'feature' => 'Theme Updates',
						'event'   => 'Deleted',
						'theme_slug' => $theme_slug,
						'theme_name' => $theme_name ? $theme_name : 'Unknown',
					),
				)
			);

			return array(
				'message' => __( 'Theme deleted successfully.', 'tailwatch' ),
				'code'    => 200,
				'theme'   => $result,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Theme deletion failed: Exception occurred',
				array(
					'feature'    => 'themes',
					'action'     => 'theme_delete_failed',
					'theme_slug' => $theme_slug,
					'theme_name' => $theme_name ? $theme_name : 'Unknown',
					'error'      => $e->getMessage(),
					'exception'  => $e,
					'title'     => 'Theme Deletion Failed',
					'meta_data' => array(
						'feature' => 'Theme Updates',
						'event'   => 'Deletion failed',
						'theme_slug' => $theme_slug,
						'theme_name' => $theme_name ? $theme_name : 'Unknown',
						'reason'     => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during theme deletion.', 'tailwatch' ),
				'code'    => 500,
				'theme'   => array(),
			);
		}
	}

	/**
	 * Get available versions for a theme.
	 *
	 * @param string $post_data JSON encoded data containing theme slug.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message  Response message.
	 *     @type int    $code     HTTP response code.
	 *     @type string $name     Theme name.
	 *     @type string $slug     Theme slug.
	 *     @type array  $versions List of available versions.
	 * }
	 */
	public function wptw_get_theme_versions( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$slug = sanitize_text_field( $data['theme'] );

			if ( empty( $slug ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'Theme slug is required.', 'tailwatch' ),
				);
			}

			$response = wp_remote_get( "https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]={$slug}&&theme&request[fields][versions]=1" );

			if ( is_wp_error( $response ) ) {
				return array(
					'message' => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'code'    => 503,
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$data          = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $response_code ) {
				// 404 with error body = theme not found in WP.org repo.
				if ( 404 === $response_code && $data && isset( $data['error'] ) ) {
					return array(
						'message' => __( 'This theme does not appear to be available in the WordPress.org repository.', 'tailwatch' ),
						'code'    => 404,
					);
				}
				// Any other non-200 (5xx, etc.) = unexpected server error.
				return array(
					'message' => __( 'Unable to retrieve theme information from WordPress.org. Please try again later.', 'tailwatch' ),
					'code'    => 502,
				);
			}

			if ( ! $data || isset( $data['error'] ) || ! isset( $data['versions'] ) || ! is_array( $data['versions'] ) ) {
				return array(
					'message' => __( 'This theme does not appear to be available in the WordPress.org repository.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			return array(
				'name'     => $data['name'],
				'slug'     => $data['slug'],
				'versions' => $data['versions'],
				'code'     => 200,
				'message'  => __( 'Theme Versions Retrieved Successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Exception retrieving theme versions.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get theme details.
	 *
	 * @param string $post_data JSON encoded data containing theme slug.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 *     @type array  $theme   Theme details.
	 * }
	 */
	public function wptw_theme_details( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$slug = isset( $data['theme'] ) ? sanitize_text_field( $data['theme'] ) : '';
			if ( empty( $slug ) ) {
				return array(
					'message' => __( 'Theme slug not provided.', 'tailwatch' ),
					'theme'   => array(),
					'code'    => 400,
				);
			}

			// Use PluginThemeService to get theme details.
			$service = new PluginThemeService();
			$result  = $service->wptw_plugin_theme_details( $slug, 'theme' );

			if ( $result['success'] ) {
				return array(
					'message' => $result['message'],
					'theme'   => $result,
					'code'    => 200,
				);
			} elseif ( isset( $result['error_type'] ) && 'network_error' === $result['error_type'] ) {
				return array(
					'message' => $result['message'],
					'theme'   => array(),
					'code'    => 503,
				);
			} else {
				return array(
					'message' => $result['message'],
					'theme'   => array(),
					'code'    => 404,
				);
			}
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Exception retrieving theme details.', 'tailwatch' ),
				'theme'   => array(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Rollback a theme to a specific version.
	 *
	 * @param string $post_data JSON encoded data containing theme slug and version.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 * }
	 */
	public function wptw_theme_rollback( $post_data ) {
		$theme_slug       = null;
		$theme_name       = null;
		$current_version  = null;
		$rollback_version = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( $data['triggered_by'] ?? null );

			$slug    = isset( $data['theme'] ) ? sanitize_text_field( $data['theme'] ) : '';
			$version = isset( $data['version'] ) ? sanitize_text_field( $data['version'] ) : '';

			// Validate required parameters.
			if ( empty( $slug ) || empty( $version ) ) {
				Log::warning(
					'Theme rollback failed: Missing required parameters',
					array(
						'feature'   => 'themes',
						'action'    => 'theme_rollback_failed',
						'error'     => 'Theme slug or version not provided',
						'meta_data' => array(
							'feature' => 'Theme Updates',

							'event'   => 'Rollback failed',
							'reason' => 'Missing slug or version',
						),
					)
				);
				return array(
					'message' => __( 'Theme slug or version not provided.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$theme_slug       = $slug;
			$rollback_version = $version;

			$current_theme = wp_get_theme( $slug );
			if ( ! $current_theme->exists() ) {
				Log::error(
					'Theme rollback failed: Theme not installed',
					array(
						'feature'          => 'themes',
						'action'           => 'theme_rollback_failed',
						'theme_slug'       => $slug,
						'rollback_version' => $version,
						'error'            => "Theme '{$slug}' is not installed",
						'meta_data'        => array(
							'feature' => 'Theme Updates',
							'event'   => 'Rollback failed',
							'theme_slug' => $slug,
							'to_version' => $version,
							'reason'     => 'Not installed',
						),
					)
				);
				return array(
					'message' => "Theme '$slug' is not installed. Only installed themes can be rolled back.",
					'code'    => 404,
				);
			}

			$theme_name = $current_theme->get( 'Name' ) ?? $slug;

			// Validate version format (supports standard versions and versions with text suffixes like beta, RC, dev).
			// Examples: 1.0.0, 3.18.0-beta1, 1.0.0-RC1, 2.0.0-dev, 1.0.0alpha.
			if ( ! preg_match( '/^\d+(\.\d+)*([-.]?[a-zA-Z0-9]+)*$/', $version ) ) {
				Log::warning(
					'Theme rollback failed: Invalid version format',
					array(
						'feature'          => 'themes',
						'action'           => 'theme_rollback_failed',
						'theme_slug'       => $slug,
						'theme_name'       => $theme_name,
						'rollback_version' => $version,
						'error'            => 'Invalid version format',
						'meta_data'        => array(
							'feature' => 'Theme Updates',
							'event'   => 'Rollback failed',
							'theme_slug' => $slug,
							'theme_name' => $theme_name,
							'to_version' => $version,
							'reason'     => 'Invalid version format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid version format. Expected format: X.X.X or X.X.X-suffix (e.g., 1.0.0, 3.18.0-beta1)', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$current_version = $current_theme->get( 'Version' );

			// Check if trying to rollback to the same version.
			if ( $current_version === $version ) {
				// Not an error, just informational.
				return array(
					'message' => "Theme is already at version {$version}.",
					'code'    => 400,
				);
			}

			// Check if the version exists in WordPress.org repository.
			$check_url = "https://downloads.wordpress.org/theme/{$slug}.{$version}.zip";
			$response  = wp_remote_head( $check_url, array( 'timeout' => 10 ) );

			if ( is_wp_error( $response ) ) {
				Log::error(
					'Theme rollback failed: Could not verify version availability',
					array(
						'feature'          => 'themes',
						'action'           => 'theme_rollback_failed',
						'theme_slug'       => $slug,
						'theme_name'       => $theme_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'error'            => $response->get_error_message(),
						'meta_data'        => array(
							'feature' => 'Theme Updates',
							'event'   => 'Rollback failed',
							'theme_slug'  => $slug,
							'theme_name'  => $theme_name,
							'from_version' => $current_version,
							'to_version'   => $version,
							'reason'       => 'WordPress error',
						),
					)
				);
				return array(
					'message' => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'code'    => 503,
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				if ( 404 === $response_code ) {
					Log::error(
						'Theme rollback failed: Version not found in repository',
						array(
							'feature'          => 'themes',
							'action'           => 'theme_rollback_failed',
							'theme_slug'       => $slug,
							'theme_name'       => $theme_name,
							'current_version'  => $current_version,
							'rollback_version' => $version,
							'error'            => "Version {$version} not found in WordPress.org repository",
							'meta_data'        => array(
								'feature' => 'Theme Updates',
								'event'   => 'Rollback failed',
								'theme_slug'  => $slug,
								'theme_name'  => $theme_name,
								'from_version' => $current_version,
								'to_version'   => $version,
								'reason'       => 'Version not found',
							),
						)
					);
					return array(
						'message' => "Version {$version} of this theme does not appear to be available in the WordPress.org repository.",
						'code'    => 404,
					);
				}

				// Any other non-200 (5xx, etc.) = server/network error.
				Log::error(
					'Theme rollback failed: WordPress.org server error',
					array(
						'feature'          => 'themes',
						'action'           => 'theme_rollback_failed',
						'theme_slug'       => $slug,
						'theme_name'       => $theme_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'response_code'    => $response_code,
						'error'            => "Unexpected response code {$response_code} from WordPress.org",
						'meta_data'        => array(
							'feature' => 'Theme Updates',
							'event'   => 'Rollback failed',
							'theme_slug'   => $slug,
							'theme_name'   => $theme_name,
							'from_version' => $current_version,
							'to_version'   => $version,
							'response_code' => (int) $response_code,
							'reason'       => 'WordPress error',
						),
					)
				);
				return array(
					'message' => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'code'    => 503,
				);
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_theme_state( $slug );

			// Set flag BEFORE rollback to prevent duplicate from hook.
			set_transient( 'wptw_history_tracking_in_progress', true, 30 );

			$service = new PluginThemeService();
			$result  = $service->wptw_rollback( 'theme', $slug, $version );

			if ( $result['success'] ) {
				// Capture AFTER state for history.
				$after_state = $this->capture_theme_state( $slug );

				// Record history for successful rollback.
				HistoryService::record_action(
					array(
						'action_type'   => 'theme_rollback',
						'item_type'     => 'theme',
						'item_slug'     => $slug,
						'item_name'     => $theme_name,
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

				// Log successful theme rollback.
				Log::info(
					'Your website has been successfully restored to the selected previous version. Please verify that everything is working correctly.',
					array(
						'feature'          => 'themes',
						'action'           => 'theme_rollback_completed',
						'theme_slug'       => $slug,
						'theme_name'       => $theme_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'title'            => 'Rollback Completed',
						'meta_data'        => array(
							'feature' => 'Theme Updates',
							'event'   => 'Rolled back',
							'theme_slug'  => $slug,
							'theme_name'  => $theme_name,
							'from_version' => $current_version,
							'to_version'   => $version,
						),
					)
				);

				return array(
					'message' => $result['message'],
					'theme'   => array(
						'slug'             => $slug,
						'previous_version' => $current_version,
						'rollback_version' => $version,
					),
					'code'    => 200,
				);
			} else {
				// Capture AFTER state (failed, so state unchanged).
				$after_state = $this->capture_theme_state( $slug );

				// Record history for failed rollback.
				HistoryService::record_action(
					array(
						'action_type'   => 'theme_rollback',
						'item_type'     => 'theme',
						'item_slug'     => $slug,
						'item_name'     => $theme_name,
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
					"Theme rollback failed: {$theme_name}",
					array(
						'feature'          => 'themes',
						'action'           => 'theme_rollback_failed',
						'theme_slug'       => $slug,
						'theme_name'       => $theme_name,
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'error'            => $result['message'] ?? 'Rollback process failed',
						'meta_data'        => array(
							'feature' => 'Theme Updates',
							'event'   => 'Rollback failed',
							'theme_slug'  => $slug,
							'theme_name'  => $theme_name,
							'from_version' => $current_version,
							'to_version'   => $version,
							'reason'       => 'Rollback error',
						),
					)
				);
				return array(
					'message' => $result['message'],
					'code'    => 500,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'We couldn\'t restore your website to the selected version. This may be due to missing backup files or server restrictions. Contact support for help.',
				array(
					'feature'          => 'themes',
					'action'           => 'theme_rollback_failed',
					'theme_slug'       => $theme_slug,
					'theme_name'       => $theme_name ? $theme_name : 'Unknown',
					'current_version'  => $current_version,
					'rollback_version' => $rollback_version,
					'error_detail'   => $e->getMessage(),
					'exception'        => $e,
					'title'     => 'Rollback Failed',
					'meta_data'       => array(
						'feature' => 'Theme Updates',
						'event'   => 'Rollback failed',
						'theme_slug'  => $theme_slug,
						'theme_name'  => $theme_name ? $theme_name : 'Unknown',
						'from_version' => $current_version,
						'to_version'   => $rollback_version,
						'reason'       => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during theme rollback.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Check theme compatibility.
	 *
	 * Validates if upgrade/downgrade to a specific version is safe by checking
	 * WordPress/PHP compatibility and child theme relationships.
	 *
	 * @param string $post_data JSON encoded POST data containing 'theme' and 'version'.
	 *
	 * @return array Response data with compatibility check results.
	 */
	public function wptw_check_theme_compatibility( $post_data ) {
		$theme_slug = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data || ! isset( $data['theme'] ) || ! isset( $data['version'] ) ) {
				Log::warning(
					'Theme compatibility check failed: Invalid parameters',
					array(
						'feature' => 'themes',
						'action'  => 'theme_compatibility_check_failed',
						'error'   => 'Invalid parameters: theme and version are required',
					)
				);
				return array(
					'message' => __( 'Invalid parameters. Theme and version are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$slug           = sanitize_text_field( $data['theme'] );
			$target_version = sanitize_text_field( $data['version'] );
			$theme_slug     = $slug;

			if ( empty( $slug ) || empty( $target_version ) ) {
				return array(
					'message' => __( 'Theme slug and version are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Get current theme version.
			$current_version = '0.0.0';
			$theme           = wp_get_theme( $slug );

			if ( $theme->exists() ) {
				$current_version = $theme->get( 'Version' );
			}

			// Perform compatibility check.
			$compatibility = CompatibilityService::check_compatibility(
				'theme',
				$slug,
				$target_version,
				$current_version
			);

			Log::info(
				'Theme compatibility check completed',
				array(
					'feature'         => 'themes',
					'action'          => 'theme_compatibility_check_completed',
					'theme_slug'      => $slug,
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
				'Theme compatibility check failed: Exception occurred',
				array(
					'feature'    => 'themes',
					'action'     => 'theme_compatibility_check_failed',
					'theme_slug' => $theme_slug,
					'error'      => $e->getMessage(),
					'exception'  => $e,
					'title'     => 'Theme Compatibility Check Failed',
				)
			);

			return array(
				'message' => __( 'Exception during compatibility check.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}
}
