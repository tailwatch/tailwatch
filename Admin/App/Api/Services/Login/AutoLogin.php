<?php
/**
 * Auto Login Service
 *
 * Passwordless one-click login for the site administrator. An authenticated
 * request (via the Connect API, running as the paired admin) mints a single-use,
 * short-lived token; opening the resulting link logs that administrator in.
 *
 * The click is handled on WordPress's own login endpoint via
 * `wp-login.php?action=tailwatch_auto_login` -> the `login_form_tailwatch_auto_login`
 * hook (the same pattern core uses for password resets, `?action=rp`), rather than a
 * generic front-end handler.
 *
 * Security model (what replaces a nonce — a nonce is inapplicable because the user
 * is not logged in yet):
 *   - the URL carries a 32-char CSPRNG token; only its SHA-256 hash is stored, so a
 *     database dump yields no usable token;
 *   - the stored record is bound to one target user id and a <=1h expiry (both
 *     enforced here at generation, not just by the caller);
 *   - redemption is strictly single-use: the token record is CLAIMED atomically (a
 *     row-locked DELETE) and the login proceeds only for the request that wins the
 *     claim, so concurrent reuse cannot redeem it twice;
 *   - the target is re-verified as an administrator at click time before any login.
 *
 * Note: like every admin-initiated magic-login / temporary-login mechanism, opening
 * this link sets the auth cookie directly and therefore does not re-run a second
 * factor that gates on the password `authenticate` step. The link is minted only by
 * an already-authenticated administrator for their own account and is single-use and
 * short-lived; the residual risk is link leakage.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Login
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Services\Login;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AutoLogin {

	/** Plaintext token length (characters). */
	const TOKEN_LENGTH = 32;

	/** Login action that routes wp-login.php to our handler. */
	const ACTION = 'tailwatch_auto_login';

	/** URL query arg carrying the token. */
	const QUERY_ARG = 'tailwatch_token';

	/** Option-name prefix; the suffix is the SHA-256 of the token. */
	const OPTION_PREFIX = 'tailwatch_auto_login_token_';

	/** Maximum token lifetime, enforced at generation. */
	const MAX_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Register the login-endpoint handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'login_form_' . self::ACTION, array( $this, 'handle_auto_login' ) );
	}

	/**
	 * Option name for a token — its SHA-256 hash, so the plaintext token is never
	 * stored (a database dump cannot yield a usable login link).
	 *
	 * @param string $token Plaintext token.
	 * @return string
	 */
	private function option_key( $token ) {
		return self::OPTION_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Get the primary (oldest) administrator account.
	 *
	 * Used by the recovery-mode landing, which runs in a system context (no current
	 * user) after WordPress core has validated the recovery cookie, to mint a token
	 * for the site's administrator.
	 *
	 * @return \WP_User|false
	 */
	public function get_primary_admin_user() {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		return ! empty( $admins ) ? $admins[0] : false;
	}

	/**
	 * Generate a single-use auto-login token for a specific administrator.
	 *
	 * @param int      $user_id     Target administrator user id.
	 * @param int|null $expiration  Unix expiry. Clamped to at most 1 hour from now.
	 * @param string   $redirect_to URL to land on after login. Defaults to admin_url().
	 * @return string|false Plaintext token on success, false otherwise.
	 */
	public function generate_auto_login_token( $user_id, $expiration = null, $redirect_to = null ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user || ! user_can( $user, 'administrator' ) ) {
			return false;
		}

		$token = wp_generate_password( self::TOKEN_LENGTH, false );

		// Enforce the lifetime guarantee at the sink, not just at the caller: a login
		// token lives at most one hour regardless of the requested expiry.
		if ( empty( $expiration ) || ! is_numeric( $expiration ) || $expiration <= time() ) {
			$expiration = time() + self::MAX_LIFETIME;
		}
		$expiration = min( absint( $expiration ), time() + self::MAX_LIFETIME );

		$redirect_to = empty( $redirect_to ) ? admin_url() : esc_url_raw( $redirect_to );

		$token_data = array(
			'user_id'     => absint( $user->ID ),
			'expires'     => absint( $expiration ),
			'created'     => time(),
			'redirect_to' => $redirect_to,
		);

		update_option( $this->option_key( $token ), $token_data, false );

		return $token;
	}

	/**
	 * Build the login URL for a plaintext token.
	 *
	 * @param string $token Plaintext token.
	 * @return string
	 */
	public function build_login_url( $token ) {
		return add_query_arg(
			array(
				'action'        => self::ACTION,
				self::QUERY_ARG => $token,
			),
			wp_login_url()
		);
	}

	/**
	 * Handle the auto-login click on wp-login.php?action=tailwatch_auto_login.
	 *
	 * @return void
	 */
	public function handle_auto_login() {
		// A nonce is structurally inapplicable to a passwordless login (no session
		// exists yet). The single-use, hashed, expiring token validated below IS the
		// origin/auth control — the same model WordPress core uses for ?action=rp.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Passwordless login; the single-use token below is the authentication.
		$raw_token = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		if ( '' === $raw_token ) {
			return;
		}

		// Tokens are wp_generate_password(32, false) -> [A-Za-z0-9]{32}. Reject any
		// other shape before the dynamic option lookup.
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/', $raw_token ) ) {
			$this->deny();
		}

		$key        = $this->option_key( $raw_token );
		$token_data = get_option( $key );

		$validation = $this->validate_auto_login_token( $token_data );
		if ( ! $validation['valid'] ) {
			delete_option( $key );
			$this->deny( $validation['error'] );
		}

		$user = get_user_by( 'ID', absint( $token_data['user_id'] ) );
		if ( ! $user || ! user_can( $user, 'administrator' ) ) {
			delete_option( $key );
			$this->deny( __( 'Invalid user permissions.', 'tailwatch' ) );
		}

		// Atomically claim the token: exactly one request wins the row-locked delete
		// and is allowed to log in; concurrent duplicates lose the claim and are denied.
		if ( ! $this->claim_token( $key ) ) {
			$this->deny( __( 'Auto login link has already been used.', 'tailwatch' ) );
		}

		// Log the administrator in.
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core wp_login action, fired so session / activity-log listeners run.
		do_action( 'wp_login', $user->user_login, $user );

		$redirect_to = isset( $token_data['redirect_to'] ) ? $token_data['redirect_to'] : admin_url();
		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Terminate an invalid auto-login attempt with a 403.
	 *
	 * @param string $message Optional message.
	 * @return void
	 */
	private function deny( $message = '' ) {
		wp_die(
			esc_html( '' !== $message ? $message : __( 'Invalid or expired auto login link.', 'tailwatch' ) ),
			esc_html__( 'Auto Login Link Error', 'tailwatch' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Validate a token record: present and not expired.
	 *
	 * @param mixed $token_data Option value (array or false).
	 * @return array{valid:bool,error:string}
	 */
	public function validate_auto_login_token( $token_data ) {
		if ( ! is_array( $token_data ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Invalid or expired auto login link.', 'tailwatch' ),
			);
		}

		if ( ! isset( $token_data['expires'] ) || $token_data['expires'] < time() ) {
			return array(
				'valid' => false,
				'error' => __( 'Auto login link has expired.', 'tailwatch' ),
			);
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}

	/**
	 * Atomically claim (delete) a token so exactly one request may redeem it.
	 *
	 * Uses a single row-locked DELETE rather than get/delete_option so concurrent
	 * requests carrying the same token cannot both pass a read-then-delete window.
	 * Returns true only for the request whose DELETE removed the row.
	 *
	 * @param string $key Option name (hashed).
	 * @return bool True when this request claimed the token.
	 */
	private function claim_token( $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; atomic single-use claim of an auto-login token.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $key ) );

		// The raw DELETE bypasses delete_option()'s cache invalidation; clear the
		// cached value so a stale copy cannot be re-read within this request.
		wp_cache_delete( $key, 'options' );

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Delete expired auto-login token records. Called on a schedule.
	 *
	 * @return int Number of records deleted.
	 */
	public function cleanup_expired_tokens() {
		global $wpdb;

		$pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; auto-login token cleanup.
		$options = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$pattern
		) );

		$cleaned = 0;
		foreach ( $options as $option ) {
			$raw = $option->option_value;
			// allowed_classes => false blocks object injection if the row were ever
			// overwritten externally; maybe_unserialize() does not expose that flag.
			$data = ( is_string( $raw ) && is_serialized( $raw ) )
				? @unserialize( $raw, array( 'allowed_classes' => false ) ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Guarded, object injection disabled.
				: $raw;

			if ( is_array( $data ) && isset( $data['expires'] ) && $data['expires'] < time() ) {
				delete_option( $option->option_name );
				++$cleaned;
			}
		}

		return $cleaned;
	}
}
