<?php
/**
 * Recovery Mode Controller.
 *
 * Detects HTTP 500 errors at shutdown and generates a WordPress recovery mode
 * link wrapped with a single-use auto-login token. Also exposes a token-protected
 * redirect endpoint that admins (or the mobile app) can use to land on the latest
 * stored recovery URL.
 *
 * @package Tailwatch
 */

namespace Tailwatch\Admin\App\Api\Controllers\RecoveryMode;

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RecoveryModeController {

	/**
	 * @var RecoveryModeService
	 */
	private $recovery_mode_service;

	public function __construct() {
		$this->recovery_mode_service = new RecoveryModeService();

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'shutdown', array( $this, 'wptw_process_http_status_code' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'wptw_redirect_to_login_link' ) );
	}

	/**
	 * Detect HTTP 500 errors on shutdown and generate a recovery link.
	 *
	 * Performs the cheap status check first so the normal request path
	 * (the 99.9% non-500 case) avoids any DB queries.
	 *
	 * @return void
	 */
	public function wptw_process_http_status_code() {
		try {
			$status_code = (int) http_response_code();
			if ( 500 !== $status_code ) {
				return;
			}

			$connected_data = new VerifyStatusController();
			$extented_data  = $connected_data->wptw_get_plugin_activation();
			if ( empty( $extented_data['data'] ) ) {
				return;
			}

			$key              = 'default_feature_logs';
			$option           = 'default_recovery_mode';
			$db_model         = new DBModel();
			$get_error_status = $db_model->get_error_logs( $key, $option, $status_code );
			$monitor_error    = ( null !== $get_error_status && isset( $get_error_status['type'] ) ) ? (int) $get_error_status['type'] : 0;

			if ( 500 === $monitor_error ) {
				return; // Already logged for this 500 cycle.
			}

			$recovery_link_data = $this->recovery_mode_service->generate_recovery_mode_link();
			if ( ! $recovery_link_data ) {
				Log::error(
					'Failed to generate recovery mode link for HTTP 500 error',
					array(
						'feature'  => 'recovery_mode',
						'action'   => 'recovery_mode_generate_recovery_link_failed',
						'detail'   => 'Failed to generate recovery mode link for HTTP 500 error',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return;
			}

			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url         = home_url( $request_uri );
			$error       = error_get_last();
			$log_message = $this->generate_recovery_mode_message( $error ) . ' Recovery link: ' . $recovery_link_data;

			$log_data = array(
				'recovery_link' => $recovery_link_data,
				'timestamp'     => time(),
				'status_code'   => $status_code,
				'url'           => $url,
			);

			$monitor_logs = new MonitoringLogController();
			$monitor_logs->log_http_status( $log_message, $status_code, $url, $option, $log_data );

			// Real-time admin alert. Direct call (not via Logger) because recovery mode is
			// safety-critical and must reach the admin even when they have disabled push
			// notifications in their preferences. The dedicated method bypasses the user's
			// mobile_notification toggle and severity gating, while still respecting
			// license/credit constraints (those are external paid-tier limits, not user prefs).
			// The DB audit row above and the Log::info below remain the system-internal
			// trail; this push is the only direct notification channel for this event.
			$push_notification = new PushNotificationController();
			$push_notification->wptw_trigger_critical_push_notification(
				'Recovery Mode Activated',
				sprintf(
					'A critical site error (HTTP %1$d) was detected at %2$s. Use this single-use recovery link to access the site: %3$s',
					$status_code,
					$url,
					$recovery_link_data
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
					'detail'   => 'Exception occurred while processing HTTP status code: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	/**
	 * Build a fatal error message string from PHP's error_get_last() output.
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
	 * Cookie-based auto-login on the recovery mode landing page.
	 *
	 * ## Why this endpoint does not use wp_verify_nonce()
	 *
	 * The user arrives here by clicking a one-time SOS recovery link issued by
	 * the Tailwatch dashboard (out-of-band, possibly via email or SMS) — they
	 * are NOT logged in yet and there is no opportunity for the plugin to mint
	 * a wp_nonce in advance. WordPress nonces are by definition tied to a
	 * logged-in user's session, so the standard wp_verify_nonce() pattern is
	 * structurally inapplicable to a passwordless-recovery flow.
	 *
	 * ## What replaces the nonce (single-use cryptographic key)
	 *
	 * Fires on the `init` hook. WP core's handle_begin_link() processes the raw
	 * recovery URL (wp-login.php?action=enter_recovery_mode&rm_token=...&rm_key=...),
	 * validates the single-use rm_key with hash_equals against the stored hash,
	 * sets the HMAC-signed recovery cookie, and redirects to
	 * wp-login.php?action=entered_recovery_mode — stripping all other query params
	 * in the process. On that redirected request:
	 *   - $GLOBALS['pagenow'] === 'wp-login.php'
	 *   - $_GET['action'] === 'entered_recovery_mode'
	 *   - A cryptographically valid recovery cookie is present
	 *
	 * When all three conditions are met, we auto-login the admin. Critically, we
	 * do NOT trust the recovery cookie's mere presence — we re-validate its HMAC
	 * signature via WP core's own WP_Recovery_Mode_Cookie_Service::validate_cookie().
	 * On a standard single-site install WP core already rejects a forged cookie
	 * (with wp_die) before plugins load, but core skips that validation on
	 * multisite and when the fatal-error handler is disabled — so we perform the
	 * signature check ourselves to guarantee a forged cookie can never reach this
	 * auto-login path in ANY configuration.
	 *
	 * @return void
	 */
	public function wptw_redirect_to_login_link() {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cookie validated below.
			if ( ! isset( $_GET['action'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cookie validated below.
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
			if ( 'entered_recovery_mode' !== $action ) {
				return;
			}

			// We only run on wp-login.php — which is where WP core redirects after validating
			// the one-time recovery key (rm_key) and setting its recovery cookie. The cookie's
			// presence is proof the user legitimately used a recovery link.
			if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] ) {
				return;
			}

			if ( ! $this->is_valid_recovery_cookie() ) {
				return;
			}

			$session = $this->get_stored_recovery_session();
			if ( null === $session ) {
				return;
			}

			$this->login_admin_and_redirect( $session );
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while processing recovery mode redirect: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_redirect_process_failed',
					'detail'   => 'Exception occurred while processing recovery mode redirect: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	/**
	 * Look up the most recent recovery session in the DB logs table.
	 *
	 * Tries the HTTP-500-triggered session first, then the manually-generated session.
	 *
	 * @return array|null Decoded recovery session data, or null if no valid session found.
	 */
	private function get_stored_recovery_session() {
		$db_model = new DBModel();

		// Fetch BOTH session types and pick the most recently generated one.
		// Otherwise we'd always prefer the auto-500 session even when the user
		// clicked a freshly-generated manual link (and the auto session's rm_key
		// is stale), producing the wrong redirect URL.
		$auto_row   = $db_model->get_error_logs( 'default_feature_logs', 'default_recovery_mode', 500 );
		$manual_row = $db_model->get_error_logs( 'default_feature_logs', 'default_recovery_mode_manual', 200 );

		$candidates = array();
		foreach ( array( $auto_row, $manual_row ) as $candidate ) {
			if ( empty( $candidate ) || ! isset( $candidate['value'] ) ) {
				continue;
			}
			$decoded = json_decode( $candidate['value'], true );
			if ( ! is_array( $decoded ) || empty( $decoded['recovery_link'] ) ) {
				continue;
			}
			$decoded['_row_id'] = (int) $candidate['id'];
			$candidates[]       = $decoded;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		// Pick the candidate with the latest timestamp — this corresponds to
		// the most recently generated link, which is the one the user most
		// likely just clicked.
		usort(
			$candidates,
			function ( $a, $b ) {
				return ( $b['timestamp'] ?? 0 ) <=> ( $a['timestamp'] ?? 0 );
			}
		);

		$chosen = $candidates[0];
		return $chosen;
	}

	/**
	 * Verify that a cryptographically valid WordPress recovery cookie is present.
	 *
	 * Does NOT trust the cookie's mere presence — cookies are attacker-controllable.
	 * Instead it re-validates the cookie's HMAC signature through WP core's own
	 * WP_Recovery_Mode_Cookie_Service::validate_cookie(), which returns true only
	 * for a correctly-signed, unexpired recovery cookie (the same check WP core
	 * performs in WP_Recovery_Mode::handle_cookie()).
	 *
	 * This closes a defense-in-depth gap: on a standard single-site install WP core
	 * already rejects a forged recovery cookie with wp_die() before plugins load,
	 * but it skips that validation on multisite and when the fatal-error handler is
	 * disabled (see the `! is_multisite() && wp_is_fatal_error_handler_enabled()`
	 * guard around wp_recovery_mode()->initialize() in wp-settings.php). By
	 * re-validating here we ensure a forged cookie can never reach the auto-login
	 * redirect in any configuration.
	 *
	 * @return bool True only when a valid, signed recovery cookie is present.
	 */
	private function is_valid_recovery_cookie() {
		if ( ! class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
			return false;
		}
		$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
		if ( ! method_exists( $cookie_service, 'validate_cookie' ) ) {
			return false;
		}
		// validate_cookie() reads the recovery cookie from $_COOKIE itself and
		// returns true on a valid HMAC signature, or a WP_Error otherwise.
		return true === $cookie_service->validate_cookie();
	}

	/**
	 * Redirect the user to the stored recovery URL to trigger auto-login.
	 *
	 * Called after the recovery cookie has been verified by wptw_redirect_to_login_link().
	 * The stored recovery_link is WP core's recovery URL with our wptw_auto_login token
	 * already appended. Because the recovery cookie is now set, WP core's initialize()
	 * goes down the handle_cookie() path (not handle_begin_link) — so it will NOT strip
	 * query params this time. The request reaches the init hook with wptw_auto_login
	 * intact, and AutoLogin::wptw_handle_auto_login_request performs the login.
	 *
	 * Calls exit on success.
	 *
	 * @param array $session Recovery session data from get_stored_recovery_session.
	 * @return void
	 */
	private function login_admin_and_redirect( $session ) {

		Log::info(
			'Recovery mode: redirecting to recovery_link for AutoLogin processing',
			array(
				'feature' => 'recovery_mode',
				'action'  => 'recovery_mode_redirect_to_recovery_link',
				'origin'  => 'system',
			)
		);

		// Defense against URL leak:
		// - Referrer-Policy: no-referrer — prevents the wptw_auto_login URL from being
		//   leaked via the Referer header when the browser follows our redirect (so the
		//   dashboard and any external resources it loads never see the token).
		// - Cache-Control: no-store, private — prevents the token URL from being cached
		//   by the browser, proxies, or the back-button history cache.
		// - X-Robots-Tag: noindex, nofollow — tells search engines not to index the URL
		//   if it ever ends up somewhere public (pastebin, screenshot, etc.).
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		}

		wp_safe_redirect( esc_url_raw( $session['recovery_link'] ) );
		exit;
	}

	/**
	 * Manually generate a recovery mode link on demand.
	 *
	 * Used by dashboard / mobile app actions. Stores the link with a single-use
	 * redirect token that the redirect endpoint requires.
	 *
	 * @return array
	 */
	public function wptw_instant_generate_recovery_mode_link() {
		try {
			$recovery_url = $this->recovery_mode_service->generate_recovery_mode_link();

			if ( ! $recovery_url ) {
				Log::error(
					'Failed to generate recovery mode link through service',
					array(
						'feature'  => 'recovery_mode',
						'action'   => 'recovery_mode_generate_instant_recovery_link_failed',
						'detail'   => 'Failed to generate recovery mode link through service',
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

			$log_data = array(
				'recovery_link' => $recovery_url,
				'timestamp'     => time(),
				'status_code'   => 200,
			);

			$monitor_logs = new MonitoringLogController();
			$monitor_logs->log_http_status(
				'Recovery mode link generated manually',
				200,
				home_url(),
				'default_recovery_mode_manual',
				$log_data
			);

			Log::info(
				'Successfully generated instant recovery mode link',
				array(
					'feature' => 'recovery_mode',
					'action'  => 'recovery_mode_generated_instant_recovery_link',
					'origin'  => 'system',
				)
			);
			return array(
				'code'         => 200,
				'message'      => __( 'Recovery mode link generated successfully.', 'tailwatch' ),
				'recovery_url' => esc_url_raw( $recovery_url ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while generating instant recovery mode link: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_generate_instant_recovery_link_failed',
					'detail'   => 'Exception occurred while generating instant recovery mode link: ' . $e->getMessage(),
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
