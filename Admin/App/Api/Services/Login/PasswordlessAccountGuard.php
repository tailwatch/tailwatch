<?php
/**
 * Passwordless Account Guard
 *
 * Accounts marked `without_password` are temporary logins: they sign in only through
 * their login link, never with a password. The add-on that issues those links enforces
 * this while it runs; this guard keeps it true when the add-on is switched off or
 * removed, so an expired or disabled temporary login cannot come back through "Lost
 * your password?", a stored password or an application password, and an open session
 * ends once nothing enforces the login's expiry.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Login
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Login;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_User;

class PasswordlessAccountGuard {

	/** User meta that marks a temporary login. */
	const STATUS_META = '_tailwatch_user_status';

	/**
	 * Hook the guard.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'allow_password_reset', array( $this, 'refuse_password_reset' ), 10, 2 );
		add_filter( 'authenticate', array( $this, 'refuse_password_login' ), PHP_INT_MAX, 1 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'refuse_application_passwords' ), 10, 2 );
		add_filter( 'determine_current_user', array( $this, 'end_unenforced_session' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Whether a user is a temporary login.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_passwordless( $user_id ) {
		return $user_id > 0 && 'without_password' === get_user_meta( (int) $user_id, self::STATUS_META, true );
	}

	/**
	 * A temporary login never resets a password.
	 *
	 * @param bool $allow   Whether the reset is allowed.
	 * @param int  $user_id User ID.
	 * @return bool
	 */
	public function refuse_password_reset( $allow, $user_id ) {
		return self::is_passwordless( (int) $user_id ) ? false : $allow;
	}

	/**
	 * A temporary login never signs in with a password.
	 *
	 * @param WP_User|WP_Error|null $user Result of the earlier authentication steps.
	 * @return WP_User|WP_Error|null
	 */
	public function refuse_password_login( $user ) {
		if ( $user instanceof WP_User && self::is_passwordless( $user->ID ) ) {
			return new WP_Error(
				'tailwatch_passwordless_account',
				__( 'This account signs in only through its login link.', 'tailwatch' )
			);
		}
		return $user;
	}

	/**
	 * A temporary login gets no application passwords.
	 *
	 * @param bool    $available Whether application passwords are available.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	public function refuse_application_passwords( $available, $user ) {
		return ( $user instanceof WP_User && self::is_passwordless( $user->ID ) ) ? false : $available;
	}

	/**
	 * Sign out a temporary login when nothing enforces its expiry any more.
	 *
	 * The add-on that issues the links answers `tailwatch_temporary_logins_enforced` while
	 * its expiry check runs. Without it, such a session could outlive its login.
	 *
	 * @param int|false $user_id User resolved by the authentication methods, or false.
	 * @return int|false
	 */
	public function end_unenforced_session( $user_id ) {
		if ( empty( $user_id ) || ! self::is_passwordless( (int) $user_id ) ) {
			return $user_id;
		}
		if ( apply_filters( 'tailwatch_temporary_logins_enforced', false ) ) {
			return $user_id;
		}
		return false;
	}
}
