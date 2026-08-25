<?php
/**
 * Recovery Mode Controller
 *
 * Detects HTTP 500 errors at shutdown and alerts the administrator with a
 * WordPress recovery-mode link so they can reach a broken site. When the admin
 * opens that link, WordPress core enters recovery mode and sets its recovery
 * cookie; this controller then, on the recovery landing, validates that cookie via
 * core and issues a single-use auto-login token so the admin lands signed in.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\RecoveryMode
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\RecoveryMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Services\Login\AutoLogin;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;

class RecoveryModeController {

	/**
	 * @var RecoveryModeService
	 */
	private $recovery_mode_service;

	/**
	 * Constructor. Side-effect free (registers no hooks) so the dispatch map can
	 * instantiate this controller freely for the manual-generation action; the hooks
	 * are registered once at bootstrap via register().
	 */
	public function __construct() {
		$this->recovery_mode_service = new RecoveryModeService();
	}

	/**
	 * Register the 500 detector and the recovery landing handler. Called once at
	 * bootstrap.
	 *
	 * @return void
	 */
	public function register() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'shutdown', array( $this, 'tailwatch_process_http_status_code' ) );
		// The recovery landing is WordPress core's own login action, so we act on it
		// via login_form_entered_recovery_mode rather than a broad init handler.
		$hook_controller->add_action_hook( 'login_form_entered_recovery_mode', array( $this, 'tailwatch_handle_recovery_landing' ) );
	}

	/**
	 * Detect HTTP 500 errors at shutdown and alert the administrator.
	 *
	 * The cheap status check runs first so the normal (non-500) request path performs
	 * no database work.
	 *
	 * @return void
	 */
	public function tailwatch_process_http_status_code() {
		try {
			$status_code = (int) http_response_code();
			if ( 500 !== $status_code ) {
				return;
			}

			$activation = ( new VerifyStatusController() )->tailwatch_get_plugin_activation();
			if ( empty( $activation['data'] ) ) {
				return;
			}

			$key      = 'default_feature_logs';
			$option   = 'default_recovery_mode';
			$db_model = new DBModel();
			$existing = $db_model->get_error_logs( $key, $option, $status_code );
			if ( null !== $existing && isset( $existing['type'] ) && 500 === (int) $existing['type'] ) {
				return; // Already logged for this 500 cycle.
			}

			$recovery_link = $this->recovery_mode_service->generate_recovery_mode_link();
			if ( ! $recovery_link ) {
				Log::error(
					'Failed to generate recovery mode link for HTTP 500 error',
					array(
						'feature'  => 'recovery_mode',
						'action'   => 'recovery_mode_generate_recovery_link_failed',
						'detail'   => 'Failed to generate recovery mode link for HTTP 500 error.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return;
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url         = home_url( $request_uri );
			$error       = error_get_last();
			// The recovery link is a live single-use credential; it is delivered only
			// via the push alert below and is deliberately NOT persisted in the log row.
			$log_message = $this->generate_recovery_mode_message( $error );

			$log_data = array(
				'timestamp'   => time(),
				'status_code' => $status_code,
				'url'         => $url,
			);

			( new MonitoringLogController() )->log_http_status( $log_message, $status_code, $url, $option, $log_data );

			// Real-time admin alert. Direct call (not via Logger) because recovery mode
			// is safety-critical and must reach the admin even when they have muted push
			// notifications; the dedicated method bypasses the user's mobile toggle and
			// severity gating while still respecting license/credit limits.
			( new PushNotificationController() )->tailwatch_trigger_critical_push_notification(
				'Recovery Mode Activated',
				sprintf(
					/* translators: 1: HTTP status code, 2: site URL, 3: recovery link. */
					__( 'A critical site error (HTTP %1$d) was detected at %2$s. Use this single-use recovery link to access the site: %3$s', 'tailwatch' ),
					$status_code,
					$url,
					$recovery_link
				),
				array(
					'feature'      => 'recovery_mode',
					'event'        => 'recovery_mode_activated',
					'feature_name' => 'Recovery Mode',
					'state'        => 'Activated',
					'status_code'  => $status_code,
					'url'          => $url,
				)
			);

			Log::info(
				"Successfully processed HTTP {$status_code} status and generated recovery link for URL: {$url}",
				array(
					'feature' => 'recovery_mode',
					'action'  => 'recovery_mode_generate_recovery_link',
					'origin'  => 'system',
				)
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while processing HTTP status code: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_generate_recovery_link_failed',
					'detail'   => 'Exception occurred while processing HTTP status code.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	/**
	 * Build a fatal-error message string from error_get_last().
	 *
	 * @param array|null $error Last PHP error.
	 * @return string
	 */
	public function generate_recovery_mode_message( $error ) {
		if ( empty( $error ) || ! is_array( $error ) ) {
			return '';
		}
		return sprintf(
			'Fatal error: %1$s in %2$s on line %3$d. ',
			isset( $error['message'] ) ? $error['message'] : '',
			isset( $error['file'] ) ? $error['file'] : '',
			isset( $error['line'] ) ? (int) $error['line'] : 0
		);
	}

	/**
	 * Recovery landing handler (wp-login.php?action=entered_recovery_mode).
	 *
	 * Runs only after WordPress core has entered recovery mode and set its recovery
	 * cookie. We re-validate that cookie's HMAC signature via core (defense in depth —
	 * core skips this before plugins load on multisite / with the fatal-error handler
	 * disabled), and only then mint a single-use auto-login token for the primary
	 * administrator and redirect to it. A nonce is inapplicable here — the request is
	 * authenticated by the recovery key that produced the cookie, not a session nonce.
	 *
	 * @return void
	 */
	public function tailwatch_handle_recovery_landing() {
		try {
			// Auto-login on recovery is limited to single-site installs. On multisite,
			// WordPress core skips recovery-mode initialization, the recovery cookie is
			// network-scoped, and the "primary administrator" may be the network super
			// admin — so a subsite admin who opened the recovery link must not be
			// auto-logged-in as a broader identity. Multisite falls back to core's
			// standard recovery login form; the admin still received the alert.
			if ( is_multisite() ) {
				return;
			}

			// The recovery cookie (validated below) is the authenticator, not a nonce.
			if ( ! $this->is_valid_recovery_cookie() ) {
				return;
			}

			// Already authenticated (e.g. re-visiting the landing) — nothing to do.
			if ( is_user_logged_in() ) {
				return;
			}

			$auto_login = new AutoLogin();
			$admin      = $auto_login->get_primary_admin_user();
			if ( ! $admin ) {
				return;
			}

			$token = $auto_login->generate_auto_login_token( $admin->ID );
			if ( ! $token ) {
				return;
			}

			// Prevent the token URL from leaking via referrer / cache on an already
			// fragile site.
			if ( ! headers_sent() ) {
				header( 'Referrer-Policy: no-referrer' );
				header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0' );
				header( 'Pragma: no-cache' );
				header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
			}

			Log::info(
				'Recovery mode: issuing auto-login token for the primary administrator',
				array(
					'feature' => 'recovery_mode',
					'action'  => 'recovery_mode_auto_login_issued',
					'origin'  => 'system',
				)
			);

			wp_safe_redirect( $auto_login->build_login_url( $token ) );
			exit;
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while processing recovery mode landing: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_landing_failed',
					'detail'   => 'Exception occurred while processing recovery mode landing.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	/**
	 * Verify a cryptographically valid WordPress recovery cookie is present.
	 *
	 * Does not trust the cookie's mere presence — validates its HMAC via core's own
	 * WP_Recovery_Mode_Cookie_Service::validate_cookie().
	 *
	 * @return bool
	 */
	private function is_valid_recovery_cookie() {
		if ( ! class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-recovery-mode-cookie-service.php';
		}
		if ( ! class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
			return false;
		}
		$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
		if ( ! method_exists( $cookie_service, 'validate_cookie' ) ) {
			return false;
		}
		return true === $cookie_service->validate_cookie();
	}

	/**
	 * Provision a recovery-mode cookie {name, value} for the connected dashboard.
	 *
	 * Dispatch entry point for the license-connect handshake: the dashboard stores the
	 * returned cookie so it can enter recovery mode even when the site is too broken
	 * for the plugin to run. Authorization is enforced upstream by the dispatcher
	 * (AJAX nonce + manage_options, or Connect JWT); see RecoveryModeService.
	 *
	 * @return array Response with name, value, code, message — or an error.
	 */
	public function tailwatch_generate_recovery_cookie() {
		return $this->recovery_mode_service->tailwatch_generate_recovery_cookie();
	}

	/**
	 * Generate a recovery-mode link on demand (dashboard / mobile app action).
	 *
	 * @return array Response with code, recovery_url, or an error.
	 */
	public function tailwatch_instant_generate_recovery_mode_link() {
		try {
			$recovery_url = $this->recovery_mode_service->generate_recovery_mode_link();

			if ( ! $recovery_url ) {
				return array(
					'code'         => 500,
					'message'      => __( 'Failed to generate recovery mode link.', 'tailwatch' ),
					'recovery_url' => '',
				);
			}

			// The recovery link is a live single-use credential returned to the
			// authenticated caller; it is deliberately NOT persisted in the log row.
			( new MonitoringLogController() )->log_http_status(
				'Recovery mode link generated manually',
				200,
				home_url(),
				'default_recovery_mode_manual',
				array(
					'timestamp'   => time(),
					'status_code' => 200,
				)
			);

			return array(
				'code'         => 200,
				'recovery_url' => $recovery_url,
				'message'      => __( 'Successfully generated recovery mode link.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while generating manual recovery mode link: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_generate_manual_link_failed',
					'detail'   => 'Exception occurred while generating manual recovery mode link.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'         => 500,
				'message'      => __( 'Failed to generate recovery mode link.', 'tailwatch' ),
				'recovery_url' => '',
			);
		}
	}
}
