<?php
/**
 * Login Protection Controller
 *
 * Honeypot, nonce, disable registration, and login page options (login_defender_management).
 *
 * @package Tailwatch\Admin\App\Api\Controllers\LoginDefender\LoginProtection
 */

namespace Tailwatch\Admin\App\Api\Controllers\LoginDefender\LoginProtection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\TwoFA\TwoFASecurityValidator;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;

/**
 * Login Protection Controller
 */
class LoginProtectionController {

	/**
	 * Per-request cache of feature options. Lazily populated by features().
	 *
	 * @var array|false|null
	 */
	private $features_cache = null;

	public function __construct() {
		$hook_controller = new HookControllers();

		$hook_controller->add_action_hook( 'admin_init', array( $this, 'custom_disable_user_registration' ) );
		$hook_controller->add_filter_hook( 'login_display_language_dropdown', array( $this, 'hide_language_dropdown_from_login_page' ) );
		$hook_controller->add_action_hook( 'login_enqueue_scripts', array( $this, 'hide_lost_password_link' ) );
		$hook_controller->add_action_hook( 'login_enqueue_scripts', array( $this, 'enqueue_honeypot_style' ) );
		$hook_controller->add_action_hook( 'login_init', array( $this, 'block_lost_password_page' ) );

		// These login-form gates run at priority 25 — AFTER WP's password check
		// (@20) so $user is resolved for the 2FA-skip session match, but BEFORE
		// the 2FA verifier (@30). 2FA shares the authenticate hook at @30 and, on
		// the verification step, clears the pending-2FA session and returns the
		// user. The pro plugin loads on plugins_loaded@5 (before free@10), so its
		// 2FA @30 callback would otherwise run FIRST and wipe the skip session —
		// then this nonce check, finding no session, would reject the 2FA form
		// (which has no tailwatch_login_nonce) and bounce the user back to the login form.
		// Running at @25 guarantees the skip session is still intact here.
		$hook_controller->add_action_hook( 'login_form', array( $this, 'login_form_nonce' ) );
		$hook_controller->add_filter_hook( 'authenticate', array( $this, 'authenticate_user_nonce' ), 25, 3 );

		$hook_controller->add_action_hook( 'login_form', array( $this, 'add_honeypot_field' ) );
		$hook_controller->add_filter_hook( 'authenticate', array( $this, 'check_honeypot_field' ), 25, 3 );
	}

	/**
	 * Get feature options for login defender / login protection.
	 *
	 * @return array|false
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_login_defender_management';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Per-request cached features.
	 *
	 * @return array|false
	 */
	private function features() {
		if ( null === $this->features_cache ) {
			$this->features_cache = $this->get_features_options();
		}
		return $this->features_cache;
	}

	/**
	 * Whether a feature toggle field is selected/enabled.
	 *
	 * @param string $field_key e.g. "field_14".
	 * @return bool
	 */
	private function is_feature_enabled( $field_key ) {
		$features = $this->features();
		return isset( $features[ $field_key ]['options']['option']['selected'] )
			&& $features[ $field_key ]['options']['option']['selected'];
	}

	/**
	 * Sanitize a username value coming from $_POST before logging.
	 *
	 * @param mixed $username Raw username.
	 * @return string
	 */
	private function safe_username( $username ) {
		return is_scalar( $username ) ? sanitize_text_field( (string) $username ) : '';
	}

	/**
	 * Whether the current request is an actual wp-login.php form submission.
	 *
	 * The `authenticate` filter is shared by every WordPress auth path —
	 * wp-login.php, XML-RPC, REST API application passwords, and any plugin
	 * that calls wp_authenticate() programmatically. Our nonce / honeypot
	 * fields only exist on wp-login.php form submissions because they are
	 * planted by the `login_form` action, which other auth paths never fire.
	 *
	 * Without this guard every XML-RPC bot probe and every REST request
	 * triggers a false "Nonce verification failed" log + notification, since
	 * those requests have no `tailwatch_login_nonce` in POST.
	 *
	 * `log` and `pwd` are wp-login.php's standard field names and are present
	 * on every submission of that form (even when the fields are empty),
	 * while every non-form auth path uses different field names or HTTP auth
	 * headers entirely.
	 *
	 * @return bool
	 */
	private function is_login_form_submission() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This IS the nonce gate; we just need to know if a wp-login.php form was posted.
		return isset( $_POST['log'] ) && isset( $_POST['pwd'] );
	}

	public function custom_disable_user_registration() {
		if ( ! $this->is_feature_enabled( 'field_16' ) ) {
			return;
		}
		if ( (int) get_option( 'users_can_register' ) !== 0 ) {
			update_option( 'users_can_register', 0 );
		}
	}

	public function hide_lost_password_link() {
		if ( ! $this->is_feature_enabled( 'field_17' ) ) {
			return;
		}
		wp_add_inline_style( 'login', '.wp-login-lost-password,#nav a[href*="action=lostpassword"]{display:none !important;}' );
	}

	public function enqueue_honeypot_style() {
		if ( ! $this->is_feature_enabled( 'field_15' ) ) {
			return;
		}
		wp_add_inline_style( 'login', '.tailwatch-honeypot-field{position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;}' );
	}

	public function block_lost_password_page() {
		if ( ! $this->is_feature_enabled( 'field_17' ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of request action; no state change here.
		if ( 'lostpassword' === $action || 'retrievepassword' === $action ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}
	}

	public function hide_language_dropdown_from_login_page() {
		return ! $this->is_feature_enabled( 'field_18' );
	}

	/**
	 * Run a nonce create/verify with the auth session token forced empty.
	 *
	 * The login form serves logged-out / re-authenticating users. WP's reauth (reauth=1)
	 * clears the auth cookie between the GET that renders the nonce and the POST that verifies
	 * it, flipping wp_get_session_token() from the stale cookie value to empty — which breaks a
	 * session-tied nonce, so the first attempt falsely fails and is counted as a failed login.
	 * Forcing an empty session token (uid 0 | token '') at BOTH create and verify makes the
	 * login nonce deterministic across that transition, without weakening CSRF protection.
	 *
	 * @param callable $fn Closure performing wp_create_nonce / wp_verify_nonce.
	 * @return mixed
	 */
	private function with_logged_out_session( callable $fn ) {
		$cookie = defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : '';
		// Raw save/restore only: value is never read as input, just put back verbatim, so unslash/sanitize would corrupt the cookie.
		$saved  = ( '' !== $cookie && isset( $_COOKIE[ $cookie ] ) ) ? $_COOKIE[ $cookie ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( null !== $saved ) {
			unset( $_COOKIE[ $cookie ] );
		}
		try {
			return $fn();
		} finally {
			if ( null !== $saved ) {
				$_COOKIE[ $cookie ] = $saved;
			}
		}
	}

	public function login_form_nonce() {
		if ( ! $this->is_feature_enabled( 'field_14' ) ) {
			return;
		}
		$nonce = $this->with_logged_out_session(
			static function () {
				return wp_create_nonce( 'tailwatch_login_nonce_action' );
			}
		);
		echo '<input type="hidden" name="tailwatch_login_nonce" value="' . esc_attr( $nonce ) . '" />';
	}

	public function authenticate_user_nonce( $user, $username, $password ) {
		if ( ! $this->is_feature_enabled( 'field_14' ) ) {
			return $user;
		}

		// Don't run the nonce check on non-form auth paths (XML-RPC, REST API,
		// application passwords, programmatic wp_authenticate calls). Those
		// requests never carry our nonce, and logging "Nonce verification
		// failed" on every bot probe to xmlrpc.php floods the activity log
		// and triggers spurious notifications.
		if ( ! $this->is_login_form_submission() ) {
			return $user;
		}

		$safe_username = $this->safe_username( $username );

		try {
			$skip_security = TwoFASecurityValidator::validate_security_skip( $user, $username, 'nonce' );
			if ( $skip_security['skip'] ) {
				return $user;
			}

			if ( isset( $skip_security['process_stop'] ) && $skip_security['process_stop'] ) {
				Log::error(
					'Security validation failed during nonce check',
					array(
						'feature'  => 'login_defender',
						'action'   => 'security_validation_failed',
						'title'    => 'Login Defender',
						'username' => $safe_username,
						'detail'   => '2FA security validation failed. Reason: ' . $skip_security['reason'],
					)
				);
				$user_message = TwoFASecurityValidator::get_user_friendly_message( $skip_security['reason'] );
				return new \WP_Error( 'authentication_failed', esc_html( $user_message ) );
			}

			$nonce_valid = $this->with_logged_out_session(
				static function () {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing, PluginCheck.Security.VerifyNonce.UnsafeVerifyNonceStatement -- this IS the nonce verification gate; wp_verify_nonce()'s result is returned from this closure into $nonce_valid and checked below (login is rejected when false).
					return isset( $_POST['tailwatch_login_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tailwatch_login_nonce'] ) ), 'tailwatch_login_nonce_action' );
				}
			);
			if ( ! $nonce_valid ) {
				Log::error(
					'Nonce verification failed',
					array(
						'feature'  => 'login_defender',
						'action'   => 'honeypot_nonce_failed',
						'title'    => 'Login Defender',
						'username' => $safe_username,
						'detail'   => 'Nonce verification failed during login.',
					)
				);
				return new \WP_Error( 'authentication_failed', __( 'Authentication failed: nonce verification failed.', 'tailwatch' ) );
			}

			return $user;
		} catch ( \Throwable $e ) {
			Log::error(
				'Nonce verification exception',
				array(
					'feature'   => 'login_defender',
					'action'    => 'honeypot_nonce_failed',
					'title'     => 'Login Defender',
					'username'  => $safe_username,
					'detail'    => 'Exception during honeypot nonce verification. Error: ' . $e->getMessage(),
					'exception' => $e,
				)
			);
			return new \WP_Error( 'authentication_failed', __( 'Authentication failed: internal error.', 'tailwatch' ) );
		}
	}

	public function add_honeypot_field() {
		if ( ! $this->is_feature_enabled( 'field_15' ) ) {
			return;
		}
		echo '<p class="tailwatch-honeypot-field" aria-hidden="true">';
		echo '<label for="tailwatch-verify-check">' . esc_html__( 'Leave this field empty', 'tailwatch' ) . '</label>';
		echo '<input type="text" name="tailwatch_verify_check" id="tailwatch-verify-check" value="" tabindex="-1" autocomplete="off" />';
		echo '</p>';
	}

	public function check_honeypot_field( $user, $username, $password ) {
		if ( ! $this->is_feature_enabled( 'field_15' ) ) {
			return $user;
		}

		// Same reasoning as authenticate_user_nonce: skip non-form auth paths
		// so XML-RPC / REST traffic doesn't trigger validate_security_skip and
		// any of its side-effects on every bot hit.
		if ( ! $this->is_login_form_submission() ) {
			return $user;
		}

		$safe_username = $this->safe_username( $username );

		try {
			$skip_security = TwoFASecurityValidator::validate_security_skip( $user, $username, 'honeypot' );
			if ( $skip_security['skip'] ) {
				return $user;
			}

			if ( isset( $skip_security['process_stop'] ) && $skip_security['process_stop'] ) {
				Log::error(
					'Security validation failed during honeypot check',
					array(
						'feature'  => 'login_defender',
						'action'   => 'security_validation_failed',
						'title'    => 'Login Defender',
						'username' => $safe_username,
						'detail'   => '2FA security validation failed. Reason: ' . $skip_security['reason'],
					)
				);
				$user_message = TwoFASecurityValidator::get_user_friendly_message( $skip_security['reason'] );
				return new \WP_Error( 'authentication_failed', esc_html( $user_message ) );
			}

			if ( ! empty( $_POST['tailwatch_verify_check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
				Log::error(
					'Honeypot field filled',
					array(
						'feature'  => 'login_defender',
						'action'   => 'honeypot_field_failed',
						'title'    => 'Login Defender',
						'username' => $safe_username,
						'detail'   => 'Honeypot field was filled during login.',
					)
				);
				return new \WP_Error( 'honeypot_error', __( 'Authentication failed: suspicious activity detected.', 'tailwatch' ) );
			}

			return $user;
		} catch ( \Throwable $e ) {
			Log::error(
				'Honeypot check exception',
				array(
					'feature'   => 'login_defender',
					'action'    => 'honeypot_field_failed',
					'title'     => 'Login Defender',
					'username'  => $safe_username,
					'detail'    => 'Exception during honeypot field check. Error: ' . $e->getMessage(),
					'exception' => $e,
				)
			);
			return new \WP_Error( 'honeypot_error', __( 'Authentication failed: internal error.', 'tailwatch' ) );
		}
	}
}