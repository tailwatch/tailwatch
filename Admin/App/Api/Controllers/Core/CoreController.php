<?php
/**
 * Tailwatch - Core Controller
 *
 * Handles WordPress Core updates, rollbacks, and version checks.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Core
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Core;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\PluginTheme\PluginThemeController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\Common\CompatibilityService;
use Tailwatch\Admin\App\Api\Services\Common\HistoryService;

/**
 * Class CoreController
 *
 * Manages WordPress Core update and rollback operations.
 *
 */
class CoreController extends BaseController {

	/**
	 * Exemptions for feature check.
	 *
	 * @var array
	 */
	protected $tailwatch_feature_check_exemptions = array();

	/**
	 * Get feature status.
	 *
	 * @return array Feature status configuration.
	 */
	protected function tailwatch_get_feature_status() {
		return $this->tailwatch_core_update_feature_enable();
	}

	/**
	 * Capture current core state for history.
	 *
	 * @return array Core state.
	 */
	private function capture_core_state() {
		global $wp_version;

		$update_info      = get_site_transient( 'update_core' );
		$update_available = false;
		$update_version   = null;

		if ( $update_info && ! empty( $update_info->updates ) ) {
			foreach ( $update_info->updates as $update ) {
				if ( 'upgrade' === $update->response ) {
					$update_available = true;
					$update_version   = $update->version;
					break;
				}
			}
		}

		return array(
			'version'          => $wp_version,
			'update_available' => $update_available,
			'update_version'   => $update_version,
			'php_version'      => PHP_VERSION,
			'mysql_version'    => $GLOBALS['wpdb']->db_version(),
		);
	}

	/**
	 * Check if core update feature is enabled.
	 *
	 * @return array Feature enabled status array.
	 */
	public function tailwatch_core_update_feature_enable() {
		$feature_settings = PluginThemeController::tailwatch_plugin_theme_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'    => false,
				'feature_enable'   => false,
				'scheduler_enable' => false,
			);
		}

		$core_enabled = $feature_settings['field_7']['options']['option']['selected'] ?? false;

		$scheduler_enabled = $feature_settings['field_7']['sub_options']['field_8']['options']['option']['selected'] ?? false;

		if ( true === $core_enabled ) {
			return array(
				'parent_enable'    => true,
				'feature_enable'   => true,
				'scheduler_enable' => $scheduler_enabled,
			);
		}

		return array(
			'parent_enable'    => true,
			'feature_enable'   => false,
			'scheduler_enable' => false,
		);
	}

	/**
	 * Get core scheduler interval.
	 *
	 * @return string Scheduler interval value.
	 */
	public function tailwatch_get_core_scheduler_interval() {
		$feature_settings = PluginThemeController::tailwatch_plugin_theme_options();

		if ( empty( $feature_settings ) ) {
			return '1_day'; // Default value.
		}

		$interval_options = $feature_settings['field_7']['sub_options']['field_8']['sub_options']['field_9']['options'] ?? array();

		foreach ( $interval_options as $option ) {
			if ( isset( $option['selected'] ) && true === $option['selected'] ) {
				return $option['value'];
			}
		}

		return '1_day'; // Default value.
	}

	/**
	 * Get the actual WordPress version from disk.
	 *
	 * Reads version.php directly to get the real installed version,
	 * bypassing any cached $wp_version global.
	 *
	 * @return string|null The version string or null if unable to read.
	 */
	private function get_disk_version() {
		$version_file = ABSPATH . WPINC . '/version.php';
		if ( ! file_exists( $version_file ) ) {
			return null;
		}

		// Read via WP_Filesystem so we do not rely on native file_get_contents.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return null;
		}

		$version_content = $wp_filesystem->get_contents( $version_file );
		if ( $version_content && preg_match( "/\\\$wp_version\s*=\s*['\"]([^'\"]+)['\"]/", $version_content, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Install a specific WordPress core version.
	 *
	 * This is the core installation logic used by both update and rollback.
	 * It downloads, extracts, and copies WordPress files directly without
	 * relying on WordPress transients (which can be corrupted).
	 *
	 * @param string $target_version The WordPress version to install.
	 * @param string $action_type    The action type: 'core_update' or 'core_rollback'.
	 * @return array Response with 'success', 'message', 'code', and optionally 'new_version'.
	 */
	private function install_core_version( $target_version, $action_type = 'core_update' ) {
		$current_version = $this->get_disk_version();
		if ( null === $current_version ) {
			global $wp_version;
			$current_version = $wp_version;
		}

		// Official release package (WordPress.org only). The current-version package is
		// supplied as Core_Upgrader's rollback safety net.
		$download_url = "https://downloads.wordpress.org/release/wordpress-{$target_version}.zip";
		$rollback_url = "https://downloads.wordpress.org/release/wordpress-{$current_version}.zip";

		Log::info(
			'WordPress core installation starting',
			array(
				'feature'         => 'core',
				'action'          => "{$action_type}_starting",
				'current_version' => $current_version,
				'target_version'  => $target_version,
				'download_url'    => $download_url,
			)
		);

		// Verify the target package exists before handing off to Core_Upgrader.
		$head_response = wp_remote_head( $download_url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $head_response ) ) {
			return array(
				'success' => false,
				// translators: %s is the error message.
				'message' => sprintf( __( 'Cannot access download URL: %s', 'tailwatch' ), $head_response->get_error_message() ),
				'code'    => 500,
			);
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $head_response ) ) {
			return array(
				'success' => false,
				// translators: %s is the WordPress version number.
				'message' => sprintf( __( 'WordPress version %s is not available for download.', 'tailwatch' ), $target_version ),
				'code'    => 404,
			);
		}

		// Load WordPress core's own upgrader stack.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		if ( ! class_exists( 'Core_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		}
		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}

		// Build an explicit update offer for the exact target version and hand it to
		// WordPress core's Core_Upgrader, which downloads, unpacks, and installs the
		// release through core's own update_core() (maintenance mode, correct file
		// handling) and can restore the previous version on a critical failure. This is
		// the sanctioned path — the same mechanism WP-CLI's `core update --version` uses
		// — so the plugin never copies core files into ABSPATH itself. pre_check_md5 is
		// disabled because a specific or older version will not match the current
		// install's file checksums.
		$offer = (object) array(
			'response'        => 'upgrade',
			'download'        => $download_url,
			'locale'          => get_locale(),
			'packages'        => (object) array(
				'full'        => $download_url,
				'no_content'  => false,
				'new_bundled' => false,
				'partial'     => false,
				'rollback'    => $rollback_url,
			),
			'current'         => $target_version,
			'version'         => $target_version,
			'php_version'     => '5.2.4',
			'mysql_version'   => '5.0',
			'new_bundled'     => '',
			'partial_version' => '',
		);

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade(
			$offer,
			array(
				'pre_check_md5'    => false,
				'attempt_rollback' => true,
				'do_rollback'      => false,
			)
		);

		if ( is_wp_error( $result ) || false === $result ) {
			$error_message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The update could not be installed.', 'tailwatch' );
			Log::error(
				'WordPress core installation failed',
				array(
					'feature'        => 'core',
					'action'         => "{$action_type}_failed",
					'target_version' => $target_version,
					'error'          => $error_message,
				)
			);
			return array(
				'success' => false,
				// translators: %s is the error message.
				'message' => sprintf( __( 'Failed to install WordPress version: %s', 'tailwatch' ), $error_message ),
				'code'    => 500,
			);
		}

		// Clear update caches.
		wp_clean_update_cache();

		// Verify the installation by reading version.php directly.
		$new_version = $this->get_disk_version();
		if ( null === $new_version ) {
			$new_version = $target_version; // Fallback.
		}

		// Check if version matches expected.
		if ( $new_version !== $target_version ) {
			Log::error(
				'WordPress core installation failed: Version mismatch after installation',
				array(
					'feature'         => 'core',
					'action'          => "{$action_type}_failed",
					'target_version'  => $target_version,
					'actual_version'  => $new_version,
					'current_version' => $current_version,
					'error'           => 'Version mismatch after file copy',
				)
			);
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: expected version, 2: actual version */
					__( 'Version mismatch after installation. Expected: %1$s, Actual: %2$s.', 'tailwatch' ),
					$target_version,
					$new_version
				),
				'code'    => 500,
			);
		}

		Log::info(
			'WordPress core installation completed',
			array(
				'feature'         => 'core',
				'action'          => "{$action_type}_completed",
				'current_version' => $current_version,
				'new_version'     => $new_version,
				'target_version'  => $target_version,
			)
		);

		return array(
			'success'         => true,
			// translators: %s is the WordPress version number.
			'message'         => sprintf( __( 'WordPress successfully installed version %s.', 'tailwatch' ), $target_version ),
			'code'            => 200,
			'current_version' => $current_version,
			'new_version'     => $new_version,
		);
	}

	/**
	 * Get the latest available WordPress version from WordPress.org API.
	 *
	 * @return string|null The latest version or null on failure.
	 */
	private function get_latest_wordpress_version() {
		$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/', array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['offers'][0]['version'] ) ) {
			return $data['offers'][0]['version'];
		}

		return null;
	}

	/**
	 * Update WordPress Core.
	 *
	 * Gets the latest version from WordPress.org API and installs it directly.
	 * This approach bypasses Core_Upgrader and avoids transient corruption issues.
	 *
	 * @param string|null $post_data Optional JSON encoded POST data. Supports `triggered_by`.
	 *
	 * @return array Response data with message and code.
	 */
	/**
	 * Return a 403 response array when the current user lacks $capability, else null.
	 *
	 * The front-door gates manage_options, but on multisite update_core is super-admin-only;
	 * gating the update/rollback actions lets map_meta_cap enforce that. No-op on single site.
	 *
	 * @param string $capability
	 * @return array|null
	 */
	private function tailwatch_require_capability( $capability ) {
		if ( current_user_can( $capability ) ) {
			return null;
		}

		return array(
			'success' => false,
			'code'    => 403,
			'message' => __( 'You are not allowed to perform this action.', 'tailwatch' ),
		);
	}

	public function tailwatch_update_core( $post_data = null ) {
		$denied = $this->tailwatch_require_capability( 'update_core' );
		if ( null !== $denied ) {
			return $denied;
		}

		global $wp_version;

		try {
			$json_data    = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data         = json_decode( $json_data, true );
			$triggered_by = HistoryService::normalize_triggered_by( is_array( $data ) ? ( $data['triggered_by'] ?? null ) : null );

			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/update.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/misc.php';

			// Get actual current version from disk.
			$current_version = $this->get_disk_version();
			if ( null === $current_version ) {
				$current_version = $wp_version;
			}

			if ( ! FilesystemService::setup_filesystem() ) {
				Log::error(
					'WordPress core update failed: Filesystem initialization failed',
					array(
						'feature'         => 'core',
						'action'          => 'core_update_failed',
						'current_version' => $current_version,
						'error'           => 'Could not initialize WordPress filesystem',
						'meta_data'       => array(
							'feature' => 'WordPress Core',
							'event'   => 'Update failed',
							'from_version' => $current_version,
							'reason'       => 'Permission denied',
						),
					)
				);
				return array(
					'message' => __( 'Failed to initialize filesystem for update.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			$latest_version = $this->get_latest_wordpress_version();

			if ( empty( $latest_version ) ) {
				Log::error(
					'WordPress core update failed: Cannot get latest version',
					array(
						'feature'   => 'core',
						'action'    => 'core_update_failed',
						'error'     => 'Failed to retrieve latest WordPress version from API',
						'meta_data' => array(
							'feature' => 'WordPress Core',
							'event'   => 'Update failed',
							'reason' => 'WordPress error',
						),
					)
				);
				return array(
					'message' => __( 'Failed to retrieve latest WordPress version from API.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			// Check if already at the latest version.
			if ( version_compare( $current_version, $latest_version, '>=' ) ) {
				Log::info(
					'WordPress core update check: No updates available',
					array(
						'feature'         => 'core',
						'action'          => 'core_update_completed',
						'current_version' => $current_version,
						'latest_version'  => $latest_version,
						'message'         => __( 'WordPress core is already at the latest version', 'tailwatch' ),
						'meta_data'       => array(
							'feature' => 'WordPress Core',
							'event'   => 'Updated',
							'from_version' => $current_version,
							'to_version'   => $latest_version,
						),
					)
				);
				return array(
					// translators: %s is the current WordPress version number.
					'message' => sprintf( __( 'No core updates available. Already at version %s', 'tailwatch' ), $current_version ),
					'code'    => 404,
				);
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_core_state();

			// Set flag to prevent duplicate history from hooks.
			set_transient( 'tailwatch_history_tracking_core_update', true, 60 );

			// Install the latest version using our reliable direct method.
			$result = $this->install_core_version( $latest_version, 'core_update' );

			if ( ! $result['success'] ) {
				// Capture AFTER state for failed history.
				$after_state = $this->capture_core_state();

				// Record failed history.
				HistoryService::record_action(
					array(
						'action_type'   => 'core_update',
						'item_type'     => 'core',
						'item_slug'     => 'wordpress',
						'item_name'     => 'WordPress',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'old_version'      => $current_version,
							'expected_version' => $latest_version,
							'error'            => $result['message'],
							'source'           => 'tailwatch_plugin',
						),
					),
					false
				);

				delete_transient( 'tailwatch_history_tracking_core_update' );

				Log::error(
					'WordPress core update failed',
					array(
						'feature'          => 'core',
						'action'           => 'core_update_failed',
						'current_version'  => $current_version,
						'expected_version' => $latest_version,
						'error'            => $result['message'],
						'meta_data'        => array(
							'feature' => 'WordPress Core',
							'event'   => 'Update failed',
							'from_version' => $current_version,
							'to_version'   => $latest_version,
							'reason'       => 'Upgrade error',
						),
					)
				);

				return array(
					'message' => $result['message'],
					'code'    => $result['code'],
				);
			}

			// Success - capture AFTER state and record history.
			$new_version            = $result['new_version'];
			$after_state            = $this->capture_core_state();
			$after_state['version'] = $new_version;

			HistoryService::record_action(
				array(
					'action_type'   => 'core_update',
					'item_type'     => 'core',
					'item_slug'     => 'wordpress',
					'item_name'     => 'WordPress',
					'action_status' => 'success',
					'triggered_by'  => $triggered_by,
					'before_state'  => $before_state,
					'after_state'   => $after_state,
					'metadata'      => array(
						'old_version' => $current_version,
						'new_version' => $new_version,
						'source'      => 'tailwatch_plugin',
					),
				),
				false
			);

			delete_transient( 'tailwatch_history_tracking_core_update' );

			Log::info(
				'Your website has been successfully updated to the latest version. All changes were applied without issues.',
				array(
					'feature'         => 'core',
					'action'          => 'core_update_completed',
					'current_version' => $current_version,
					'new_version'     => $new_version,
					'title'           => 'Update Installed',
					'meta_data'       => array(
						'feature' => 'WordPress Core',
						'event'   => 'Updated',
						'from_version' => $current_version,
						'to_version'   => $new_version,
					),
				)
			);

			return array(
				'message' => __( 'WordPress core updated successfully.', 'tailwatch' ),
				'code'    => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'We couldn\'t complete the update. This may be due to file permissions, server limits, or compatibility issues. Review logs or contact support.',
				array(
					'feature'   => 'core',
					'action'    => 'core_update_failed',
					'error_detail'     => $e->getMessage(),
					'exception' => $e,
					'title'     => 'Update Failed',
					'meta_data' => array(
						'feature' => 'WordPress Core',
						'event'   => 'Update failed',
						'reason' => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during core update.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Rollback WordPress Core to a specific version.
	 *
	 * Uses the shared install_core_version method for reliable installation.
	 *
	 * @param string $post_data JSON encoded POST data containing 'version'.
	 * @return array Response data with message and code.
	 */
	public function tailwatch_rollback_update_core( $post_data ) {
		$denied = $this->tailwatch_require_capability( 'update_core' );
		if ( null !== $denied ) {
			return $denied;
		}

		global $wp_version;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$triggered_by = HistoryService::normalize_triggered_by( is_array( $data ) ? ( $data['triggered_by'] ?? null ) : null );

			if ( ! $data || ! isset( $data['version'] ) ) {
				Log::warning(
					'WordPress core rollback failed: Invalid parameters',
					array(
						'feature'   => 'core',
						'action'    => 'core_rollback_failed',
						'error'     => 'Invalid parameters: version not provided',
						'meta_data' => array(
							'feature' => 'WordPress Core',
							'event'   => 'Rollback failed',
							'reason' => 'No version specified',
						),
					)
				);
				return array(
					'message' => __( 'Invalid parameters.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$version = sanitize_text_field( $data['version'] );

			if ( empty( $version ) ) {
				Log::warning(
					'WordPress core rollback failed: No version specified',
					array(
						'feature'   => 'core',
						'action'    => 'core_rollback_failed',
						'error'     => 'No version specified in request',
						'meta_data' => array(
							'feature' => 'WordPress Core',
							'event'   => 'Rollback failed',
							'reason' => 'No version specified',
						),
					)
				);
				return array(
					'message' => __( 'No version specified.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Validate version format (e.g., 6.5.2, 6.5, 6.5.2-RC1, 6.5-beta1).
			if ( ! preg_match( '/^\d+(\.\d+)*([-.]?[a-zA-Z0-9]+)*$/', $version ) ) {
				Log::warning(
					'WordPress core rollback failed: Invalid version format',
					array(
						'feature'   => 'core',
						'action'    => 'core_rollback_failed',
						'version'   => $version,
						'error'     => 'Invalid version format',
						'meta_data' => array(
							'feature' => 'WordPress Core',
							'event'   => 'Rollback failed',
							'to_version' => $version,
							'reason'     => 'Invalid version format',
						),
					)
				);
				return array(
					'message' => __( 'Invalid version format.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/misc.php';

			// Get actual current version from disk.
			$current_version = $this->get_disk_version();
			if ( null === $current_version ) {
				$current_version = $wp_version;
			}

			if ( ! FilesystemService::setup_filesystem() ) {
				Log::error(
					'WordPress core rollback failed: Filesystem initialization failed',
					array(
						'feature'          => 'core',
						'action'           => 'core_rollback_failed',
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'error'            => 'Could not initialize WordPress filesystem',
						'meta_data'        => array(
							'feature' => 'WordPress Core',
							'event'   => 'Rollback failed',
							'from_version' => $current_version,
							'to_version'   => $version,
							'reason'       => 'Permission denied',
						),
					)
				);
				return array(
					'message' => __( 'Failed to initialize filesystem for rollback.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			// Capture BEFORE state for history.
			$before_state = $this->capture_core_state();

			// Set flag to prevent duplicate history from hooks.
			set_transient( 'tailwatch_history_tracking_core_rollback', true, 60 );

			// Install the specified version using our reliable direct method.
			$result = $this->install_core_version( $version, 'core_rollback' );

			if ( ! $result['success'] ) {
				// Capture AFTER state for failed history.
				$after_state = $this->capture_core_state();

				// Record failed history.
				HistoryService::record_action(
					array(
						'action_type'   => 'core_rollback',
						'item_type'     => 'core',
						'item_slug'     => 'wordpress',
						'item_name'     => 'WordPress',
						'action_status' => 'failed',
						'triggered_by'  => $triggered_by,
						'before_state'  => $before_state,
						'after_state'   => $after_state,
						'metadata'      => array(
							'old_version'      => $current_version,
							'expected_version' => $version,
							'error'            => $result['message'],
							'source'           => 'tailwatch_plugin',
						),
					),
					false
				);

				delete_transient( 'tailwatch_history_tracking_core_rollback' );

				Log::error(
					'WordPress core rollback failed',
					array(
						'feature'          => 'core',
						'action'           => 'core_rollback_failed',
						'current_version'  => $current_version,
						'rollback_version' => $version,
						'error'            => $result['message'],
						'meta_data'        => array(
							'feature' => 'WordPress Core',
							'event'   => 'Rollback failed',
							'from_version' => $current_version,
							'to_version'   => $version,
							'reason'       => 'Rollback error',
						),
					)
				);

				return array(
					'message' => $result['message'],
					'code'    => $result['code'],
				);
			}

			// Success - capture AFTER state and record history.
			$new_version            = $result['new_version'];
			$after_state            = $this->capture_core_state();
			$after_state['version'] = $new_version;

			HistoryService::record_action(
				array(
					'action_type'   => 'core_rollback',
					'item_type'     => 'core',
					'item_slug'     => 'wordpress',
					'item_name'     => 'WordPress',
					'action_status' => 'success',
					'triggered_by'  => $triggered_by,
					'before_state'  => $before_state,
					'after_state'   => $after_state,
					'metadata'      => array(
						'old_version' => $current_version,
						'new_version' => $new_version,
						'source'      => 'tailwatch_plugin',
					),
				),
				false
			);

			delete_transient( 'tailwatch_history_tracking_core_rollback' );

			Log::info(
				'Your website has been successfully restored to the selected previous version. Please verify that everything is working correctly.',
				array(
					'feature'          => 'core',
					'action'           => 'core_rollback_completed',
					'current_version'  => $current_version,
					'rollback_version' => $version,
					'new_version'      => $new_version,
					'title'            => 'Rollback Completed',
					'meta_data'        => array(
						'feature' => 'WordPress Core',
						'event'   => 'Rolled back',
						'from_version' => $current_version,
						'to_version'   => $new_version,
					),
				)
			);

			return array(
				// translators: %s is the WordPress version number.
				'message' => sprintf( __( 'WordPress successfully rolled back to version %s.', 'tailwatch' ), $version ),
				'code'    => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'We couldn\'t restore your website to the selected version. This may be due to missing backup files or server restrictions. Contact support for help.',
				array(
					'feature'   => 'core',
					'action'    => 'core_rollback_failed',
					'error_detail'     => $e->getMessage(),
					'exception' => $e,
					'title'     => 'Rollback Failed',
					'meta_data' => array(
						'feature' => 'WordPress Core',
						'event'   => 'Rollback failed',
						'reason' => 'Unexpected error',
					),
				)
			);

			return array(
				'message' => __( 'Exception during core rollback.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get available WordPress Core versions.
	 *
	 * @return array Response data with versions list.
	 */
	public function tailwatch_get_core_versions() {
		try {
			$wp_core_response = wp_remote_get( 'https://api.wordpress.org/core/stable-check/1.0/' );

			if ( is_wp_error( $wp_core_response ) || 200 !== wp_remote_retrieve_response_code( $wp_core_response ) ) {
				$error_message = is_wp_error( $wp_core_response ) ? $wp_core_response->get_error_message() : 'Invalid response';
				return array(
					'message' => __( 'Error retrieving WordPress core versions.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			$wp_core_data = json_decode( wp_remote_retrieve_body( $wp_core_response ), true );

			if ( $wp_core_data ) {
				$filtered_versions = array_filter(
					$wp_core_data,
					function ( $status ) {
						return 'insecure' !== $status;
					}
				);
				$core_versions     = array_keys( $filtered_versions );

				return array(
					'name'     => 'WordPress Core',
					'slug'     => 'wordpress',
					'type'     => 'core',
					'versions' => array_combine( $core_versions, $filtered_versions ),
					'code'     => 200,
					'message'  => __( 'WordPress Core Versions Retrieved Successfully.', 'tailwatch' ),
				);
			} else {
				return array(
					'message' => __( 'No WordPress core versions found.', 'tailwatch' ),
					'code'    => 404,
				);
			}
		} catch ( \Throwable $e ) {
			return array(
				'code'    => 500,
				'message' => __( 'Error retrieving core versions.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get current WordPress version details.
	 *
	 * @return array Response data with version details.
	 */
	public function tailwatch_get_wordpress_details() {
		try {
			// Get actual current version from disk for accuracy.
			$current_version = $this->get_disk_version();
			if ( null === $current_version ) {
				global $wp_version;
				$current_version = $wp_version;
			}

			$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/' );

			if ( is_wp_error( $response ) ) {
				return array(
					'message' => __( 'Unable to fetch core updates.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( empty( $data['offers'] ) || ! isset( $data['offers'][0] ) ) {
				return array(
					'message' => __( 'No update information found.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			$latest_version   = $data['offers'][0]['version'] ?? $current_version;
			$update_url       = $data['offers'][0]['download'] ?? '';
			$update_available = version_compare( $current_version, $latest_version, '<' );

			return array(
				'core'    => array(
					'name'             => 'WordPress',
					'description'      => 'WordPress is a popular open-source content management system (CMS) that powers millions of websites.',
					'avatar'           => '',
					'current_version'  => $current_version,
					'update_version'   => $latest_version,
					'update_available' => $update_available,
					'update_url'       => $update_url,
				),
				'code'    => 200,
				'message' => __( 'WordPress details retrieved successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Failed to retrieve WordPress details.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get available Core updates.
	 *
	 * @return array Response data with updates list.
	 */
	public function tailwatch_get_core_updates() {
		try {
			// Get actual current version from disk for accuracy.
			$current_version = $this->get_disk_version();
			if ( null === $current_version ) {
				global $wp_version;
				$current_version = $wp_version;
			}

			$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/' );

			if ( is_wp_error( $response ) ) {
				return array(
					'message' => __( 'Unable to fetch core updates.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( empty( $data['offers'] ) || ! isset( $data['offers'][0] ) ) {
				return array(
					'message' => __( 'No update information found.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			$latest_version = $data['offers'][0]['version'] ?? $current_version;
			$update_url     = $data['offers'][0]['download'] ?? '';
			$description    = $data['offers'][0]['description'] ?? '';

			$updates = array(
				array(
					'name'             => 'WordPress Core',
					'current_version'  => $current_version,
					'update_version'   => $latest_version,
					'update_available' => version_compare( $current_version, $latest_version, '<' ),
					'slug'             => 'wordpress',
					'update_url'       => $update_url,
					'description'      => $description,
				),
			);

			return array(
				'core'    => $updates,
				'code'    => 200,
				'message' => __( 'Core updates information retrieved successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Failed to retrieve core updates information.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Check WordPress core compatibility.
	 *
	 * Validates if upgrade/downgrade to a specific version is safe by checking
	 * PHP/MySQL requirements and plugin/theme compatibility.
	 *
	 * @param string $post_data JSON encoded POST data containing 'version'.
	 *
	 * @return array Response data with compatibility check results.
	 */
	public function tailwatch_check_core_compatibility( $post_data ) {
		global $wp_version;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data || ! isset( $data['version'] ) ) {
				Log::warning(
					'Core compatibility check failed: Invalid parameters',
					array(
						'feature' => 'core',
						'action'  => 'core_compatibility_check_failed',
						'error'   => 'Invalid parameters: version not provided',
					)
				);
				return array(
					'message' => __( 'Invalid parameters. Version is required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$target_version = sanitize_text_field( $data['version'] );

			if ( empty( $target_version ) ) {
				return array(
					'message' => __( 'No version specified.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Perform compatibility check.
			$compatibility = CompatibilityService::check_compatibility(
				'core',
				null,
				$target_version,
				$wp_version
			);

			Log::info(
				'Core compatibility check completed',
				array(
					'feature'         => 'core',
					'action'          => 'core_compatibility_check_completed',
					'current_version' => $wp_version,
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
				'Core compatibility check failed: Exception occurred',
				array(
					'feature'   => 'core',
					'action'    => 'core_compatibility_check_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);

			return array(
				'message' => __( 'Exception during compatibility check.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

}
