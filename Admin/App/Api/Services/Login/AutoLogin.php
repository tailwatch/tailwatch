<?php
/**
 * Auto Login Service
 *
 * Handles generation and validation of auto-login tokens for secure dashboard access.
 */

namespace Tailwatch\Admin\App\Api\Services\Login;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutoLogin
 *
 * Provides secure token-based auto-login functionality with configurable limits and expiration.
 */
class AutoLogin {

	/**
	 * Token length constant.
	 */
	const TOKEN_LENGTH = 32;

	/**
	 * Generate an auto-login token.
	 *
	 * Creates a secure token for automatic login with configurable usage limits and expiration.
	 *
	 * @param int    $login_limit Number of times the token can be used. Minimum 1.
	 * @param int    $expiration  Unix timestamp when token expires. Defaults to 1 hour from now.
	 * @param string $redirect_to URL to redirect after login. Defaults to admin_url().
	 *
	 * @return string|false Token string on success, false on failure.
	 */
	public function generate_auto_login_token( $login_limit = 1, $expiration = null, $redirect_to = null ) {
		$admin_user = $this->get_primary_admin_user();
		if ( ! $admin_user ) {
			return false;
		}

		$token = wp_generate_password( self::TOKEN_LENGTH, false );

		// Validate and set expiration.
		if ( empty( $expiration ) || ! is_numeric( $expiration ) || $expiration <= time() ) {
			$expiration = time() + HOUR_IN_SECONDS;
		}

		// Validate and set redirect URL.
		if ( empty( $redirect_to ) ) {
			$redirect_to = admin_url();
		} else {
			$redirect_to = esc_url_raw( $redirect_to );
		}

		$token_data = array(
			'user_id'     => absint( $admin_user->ID ),
			'expires'     => absint( $expiration ),
			'created'     => time(),
			'login_limit' => max( 1, absint( $login_limit ) ),
			'usage_count' => 0,
			'used_at'     => array(),
			'redirect_to' => $redirect_to,
			'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		update_option( 'wptw_auto_login_token_' . $token, $token_data );

		return $token;
	}

	/**
	 * Get the primary administrator user.
	 *
	 * Retrieves the first administrator user by ID (oldest admin account).
	 *
	 * @return \WP_User|false User object on success, false if no admin found.
	 */
	private function get_primary_admin_user() {
		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return ! empty( $admin_users ) ? $admin_users[0] : false;
	}

	/**
	 * Handle auto-login request from URL parameter.
	 *
	 * ## Why this endpoint does not use wp_verify_nonce()
	 *
	 * The user arrives here by clicking a passwordless auto-login link generated
	 * by an admin via the Tailwatch dashboard or mobile app (out-of-band). They
	 * are NOT logged in yet, so the standard wp_verify_nonce() pattern — which
	 * requires an existing logged-in session to mint and verify the nonce — is
	 * structurally inapplicable to this passwordless flow.
	 *
	 * ## What replaces the nonce (single-use opaque token + admin gate)
	 *
	 *   1. The URL carries an opaque random token in `?wptw_auto_login=<token>`.
	 *      The token is generated server-side, stored in wp_options under the
	 *      key `wptw_auto_login_token_<token>`, and bound to:
	 *         - a specific target user_id
	 *         - an expiry timestamp (default 1 hour)
	 *         - a max-use counter (default 1)
	 *         - a redirect_to URL
	 *
	 *   2. validate_auto_login_token() (called below) verifies the token exists,
	 *      has not expired, and has not exceeded its usage limit. update_token_usage()
	 *      increments the use counter after a successful login so single-use tokens
	 *      become invalid on the second click.
	 *
	 *   3. user_can( $user, 'administrator' ) — explicit role gate that ensures
	 *      the login target is an administrator. A token whose target user is
	 *      not (or is no longer) an admin is hard-rejected with HTTP 403, even
	 *      if the token itself validates.
	 *
	 * The opaque single-use token is cryptographically equivalent to a wp_nonce
	 * for this use case (both are unguessable server-issued secrets validated
	 * server-side) AND additionally bound to a specific target user, expiry, and
	 * use-count — controls a wp_nonce does not provide.
	 *
	 * Hooked to 'init' action.
	 *
	 * @return void
	 */
	public function wptw_handle_auto_login_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auto-login uses secure token-based authentication, token serves as verification
		if ( ! isset( $_GET['wptw_auto_login'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Secure token-based auto-login system, validated below
		$token = isset( $_GET['wptw_auto_login'] ) ? sanitize_text_field( wp_unslash( $_GET['wptw_auto_login'] ) ) : '';

		// Tokens we mint are `wp_generate_password(32, false)`; reject
		// other shapes before the dynamic `get_option()`.
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			wp_die(
				esc_html__( 'Invalid auto login link.', 'tailwatch' ),
				esc_html__( 'Auto Login Link Error', 'tailwatch' ),
				array( 'response' => 403 )
			);
		}

		$token_data        = get_option( 'wptw_auto_login_token_' . $token );
		$validation_result = $this->validate_auto_login_token( $token, $token_data );

		if ( ! $validation_result['valid'] ) {
			wp_die(
				esc_html( $validation_result['error'] ),
				esc_html__( 'Auto Login Link Error', 'tailwatch' ),
				array( 'response' => 403 )
			);
		}

		$user_id = absint( $token_data['user_id'] );
		$user    = get_user_by( 'ID', $user_id );

		if ( ! $user || ! user_can( $user, 'administrator' ) ) {
			wp_die(
				esc_html__( 'Invalid user permissions.', 'tailwatch' ),
				esc_html__( 'Auto Login Link Error', 'tailwatch' ),
				array( 'response' => 403 )
			);
		}

		// Update token usage.
		$this->update_token_usage( $token, $token_data );

		// Perform login.
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using WordPress core wp_login action hook for proper login event handling
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( $token_data['redirect_to'] );
		exit;
	}

	/**
	 * Validate auto-login token.
	 *
	 * Checks if token exists, is not expired, and has not exceeded usage limits.
	 *
	 * @param string $token      The token string to validate.
	 * @param mixed  $token_data Token data from database (array or false).
	 *
	 * @return array {
	 *     Validation result.
	 *
	 *     @type bool   $valid Whether token is valid.
	 *     @type string $error Error message if invalid.
	 * }
	 */
	public function validate_auto_login_token( $token, $token_data ) {
		$result = array(
			'valid' => false,
			'error' => '',
		);

		if ( ! $token_data || ! is_array( $token_data ) ) {
			$result['error'] = 'Invalid or expired auto login link.';
			return $result;
		}

		if ( $token_data['expires'] < time() ) {
			delete_option( 'wptw_auto_login_token_' . $token );
			$result['error'] = 'Auto login link has expired.';
			return $result;
		}

		$usage_count = isset( $token_data['usage_count'] ) ? absint( $token_data['usage_count'] ) : 0;
		$login_limit = isset( $token_data['login_limit'] ) ? absint( $token_data['login_limit'] ) : 1;

		if ( $usage_count >= $login_limit ) {
			delete_option( 'wptw_auto_login_token_' . $token );
			$result['error'] = 'Auto login link has reached its usage limit.';
			return $result;
		}

		$result['valid'] = true;
		return $result;
	}

	/**
	 * Update token usage count and tracking.
	 *
	 * Increments usage counter and deletes token if limit reached.
	 *
	 * @param string $token      The token string.
	 * @param array  $token_data Token data array.
	 *
	 * @return void
	 */
	private function update_token_usage( $token, $token_data ) {
		$token_data['usage_count'] = isset( $token_data['usage_count'] ) ? absint( $token_data['usage_count'] ) + 1 : 1;

		if ( ! isset( $token_data['used_at'] ) || ! is_array( $token_data['used_at'] ) ) {
			$token_data['used_at'] = array();
		}
		$token_data['used_at'][] = time();

		$login_limit = isset( $token_data['login_limit'] ) ? absint( $token_data['login_limit'] ) : 1;

		if ( $token_data['usage_count'] >= $login_limit ) {
			delete_option( 'wptw_auto_login_token_' . $token );
		} else {
			update_option( 'wptw_auto_login_token_' . $token, $token_data );
		}
	}

	/**
	 * Cleanup expired auto-login tokens.
	 *
	 * Removes all expired tokens from the database.
	 *
	 * @return int Number of tokens cleaned up.
	 */
	public function cleanup_expired_tokens() {
		global $wpdb;

		// esc_like() so the trailing `_` is matched as a literal, not a LIKE wildcard.
		$option_name_pattern = $wpdb->esc_like( 'wptw_auto_login_token_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; auto-login token cleanup.
		$options = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$option_name_pattern
		) );

		$cleaned_count = 0;
		foreach ( $options as $option ) {
			// allowed_classes=>false blocks PHP object injection if the option is ever overwritten externally;
			// maybe_unserialize() doesn't expose that flag.
			$raw_value  = $option->option_value;
			$token_data = ( is_string( $raw_value ) && is_serialized( $raw_value ) )
				? @unserialize( $raw_value, array( 'allowed_classes' => false ) ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				: $raw_value;

			if ( is_array( $token_data ) && isset( $token_data['expires'] ) ) {
				if ( $token_data['expires'] < time() ) {
					delete_option( $option->option_name );
					++$cleaned_count;
				}
			}
		}

		return $cleaned_count;
	}
}
