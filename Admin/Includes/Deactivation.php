<?php
/**
 * Tailwatch - Deactivation Handler
 *
 * Manages plugin deactivation process, data cleanup, and feedback submission.
 *
 * @package    Tailwatch
 * @subpackage Admin\Includes
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\Includes;

use Tailwatch\Admin\App\Api\Templates\DeactivationAlert;
use Tailwatch\Admin\App\Api\Controllers\Features\FeatureCacheController;
use Tailwatch\Admin\App\Api\Models\DBModel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation Handler Class
 *
 * Manages plugin deactivation process and data cleanup.
 *
 */
class Deactivation {

	/**
	 * Deactivation alert instance.
	 *
	 * @var DeactivationAlert
	 */
	private $deactivation;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->deactivation = new DeactivationAlert();
		add_action( 'admin_enqueue_scripts', array( $this->deactivation, 'wptw_enqueue_deactivate_assets' ) );
		add_action( 'admin_footer', array( $this->deactivation, 'wptw_add_deactivate_popup' ) );
		add_action( 'wp_ajax_wptw_delete_plugin_data', array( $this, 'wptw_ajax_delete_plugin_data' ) );
		add_action( 'wp_ajax_wptw_submit_deactivation_feedback', array( $this, 'wptw_ajax_submit_deactivation_feedback' ) );
	}

	/**
	 * Remove all plugin data during deactivation.
	 *
	 * @return void
	 */
	public function wptw_remove_plugin_data() {
		$set_value = get_option( 'wptw_deactivate_plugin' );

		if ( $set_value ) {
			// Clear all feature caches before removing data.
			( new FeatureCacheController() )->invalidate_all_caches();

			$db_model = new DBModel();
			$db_model->wptw_drop_tables();

			$this->wptw_remove_all_option_meta_data( $db_model );

			// Clean up plugin directories: the wp-content backup folder and the
			// slug-named folder inside the uploads directory that holds logs.
			$folders = array(
				WPTW_CONTENT_DIR_BASE,
				dirname( WPTW_LOGS_DIRECTORY ),
			);

			foreach ( $folders as $folder ) {
				if ( is_dir( $folder ) ) {
					$this->wptw_delete_directory( $folder );
				}
			}

			// Remove the deactivation flag.
			delete_option( 'wptw_deactivate_plugin' );
		}
	}

	/**
	 * Safely delete a directory and its contents.
	 *
	 * @param string $dir Directory path to delete.
	 * @return bool True on success, false on failure.
	 */
	private function wptw_delete_directory( $dir ) {
		$target_dir = rtrim( wp_normalize_path( $dir ), '/' );

		// Only allow deletion inside WordPress-managed storage roots (wp-content or
		// the uploads directory), matched exactly or as a "<root>/" prefix so a
		// sibling like wp-content-evil/ is never accepted.
		$allowed_roots = array( rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) );
		$uploads       = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			$allowed_roots[] = rtrim( wp_normalize_path( $uploads['basedir'] ), '/' );
		}

		$inside = false;
		foreach ( $allowed_roots as $root ) {
			if ( $target_dir === $root || 0 === strpos( $target_dir, $root . '/' ) ) {
				$inside = true;
				break;
			}
		}
		if ( ! $inside ) {
			return false;
		}

		if ( ! file_exists( $target_dir ) ) {
			return false;
		}

		if ( ! is_dir( $target_dir ) ) {
			return wp_delete_file( $target_dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- $target_dir is validated against the allowed storage roots above; WP_Filesystem::dirlist() returns a different structure and is heavier than needed for this enumeration.
		$items = scandir( $target_dir );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $target_dir . DIRECTORY_SEPARATOR . $item;

			if ( is_dir( $path ) ) {
				$this->wptw_delete_directory( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem->rmdir( $target_dir );
	}

	/**
	 * Remove all plugin options and metadata.
	 *
	 * @param DBModel $db_model Database model instance.
	 * @return void
	 */
	private function wptw_remove_all_option_meta_data( $db_model ) {
		// Visit data.
		delete_option( WPTW_VISIT_DATA );
		delete_option( 'wptw_plugin_activation_redirect' );
		delete_option( 'wptw_deactivate_plugin' );
		delete_option( 'wptw_db_version' );

		// Additional cleanup.
		$db_model->wptw_delete_data_on_deactivate();
	}

	/**
	 * AJAX handler for plugin data deletion.
	 *
	 * @return void
	 */
	public function wptw_ajax_delete_plugin_data() {
		// Verify nonce.
		check_ajax_referer( 'wptw_nonce', 'nonce' );

		// Require manage_options: this flag triggers permanent deletion of all plugin
		// data on deactivation (tables, options, settings, logs). The lower
		// activate_plugins capability is appropriate for toggling plugins, but
		// data destruction deserves the higher administrator-level bar.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'tailwatch' ),
				),
				403
			);
		}

		// Set deactivation flag.
		$result = update_option( 'wptw_deactivate_plugin', true, false );

		if ( $result ) {
			wp_send_json_success(
				array(
					'message' => __( 'Plugin data will be deleted on deactivation.', 'tailwatch' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to set plugin data deletion flag.', 'tailwatch' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for deactivation feedback submission.
	 *
	 * Receives feedback data when user clicks 'Submit & Deactivate'.
	 * Sends feedback to external analytics API.
	 *
	 * @return void
	 */
	public function wptw_ajax_submit_deactivation_feedback() {
		// Verify nonce.
		check_ajax_referer( 'wptw_nonce', 'nonce' );

		// Verify user capability.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'tailwatch' ),
				),
				403
			);
		}

		// Sanitize and collect feedback data.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below with isset check.
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below with isset check.
		$comments = isset( $_POST['comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comments'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below with isset check.
		$data_handling = isset( $_POST['data_handling'] ) ? sanitize_text_field( wp_unslash( $_POST['data_handling'] ) ) : 'keep';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below with isset check.
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		// Get plugin version.
		$plugin_version = defined( 'WPTW_VERSION' ) ? WPTW_VERSION : 'unknown';

		// Prepare API payload matching the external endpoint requirements.
		// Required fields: domain, reason.
		// Optional fields: plugin_version, data_handeling (note: API typo), comments.
		$api_payload = array(
			'domain'         => $domain,
			'reason'         => $reason,
			'plugin_version' => $plugin_version,
			'data_handeling' => $data_handling, // API expects 'data_handeling' (their typo).
			'comments'       => $comments,
		);

		// Send feedback to external API.
		$api_endpoint = WPTW_PLUGIN_FEEDBACK;

		$response = wp_remote_post(
			$api_endpoint,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $api_payload ),
				'sslverify'   => true,
			)
		);

		// Log response only when WP_DEBUG_LOG is enabled (WordPress recommendation
		// for gating error_log() calls — silent in production).
		if ( is_wp_error( $response ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// Silent fail - don't block deactivation if feedback fails.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated by WP_DEBUG_LOG; production-silent.
			error_log( 'Tailwatch Feedback Error: ' . $response->get_error_message() );
		}

		/**
		 * Action hook to process deactivation feedback locally.
	     *
		 * @param array $api_payload The feedback data sent to API.
		 * @param mixed $response    The API response (WP_Error or response array).
		 */
		do_action( 'wptw_deactivation_feedback_submitted', $api_payload, $response );

		wp_send_json_success(
			array(
				'message' => __( 'Feedback submitted successfully.', 'tailwatch' ),
			)
		);
	}
}
