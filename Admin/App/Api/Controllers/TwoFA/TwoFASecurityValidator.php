<?php
/**
 * TwoFA Security Validator
 *
 * Validates 2FA bypass/skip conditions (session, IP, rate limit). Used by the
 * Login Defender login-form protections so an in-progress 2FA flow is not
 * blocked by the nonce/honeypot checks. No-op (returns skip=false) when no 2FA
 * session is pending, so it is safe in the free plugin where 2FA is inactive.
 *
 * @package Tailwatch\Admin\App\Api\Controllers\TwoFA
 */

namespace Tailwatch\Admin\App\Api\Controllers\TwoFA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * TwoFA Security Validator
 */
class TwoFASecurityValidator {

	/**
	 * Enhanced security validation method for 2FA bypass.
	 *
	 * @param \WP_User|null $user     The user object.
	 * @param string        $username The username being authenticated.
	 * @param string        $context  Context for logging (e.g., 'nonce', 'honeypot', 'recaptcha').
	 * @return array{skip: bool, process_stop?: bool, reason?: string}
	 */
	public static function validate_security_skip( $user, $username, $context = 'security' ) {
		$current_user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		if ( ! $current_user_id ) {
			return array( 'skip' => false );
		}

		$transient_key = 'wptw_2fa_pending_' . $current_user_id;
		$pending       = get_transient( $transient_key );

		if ( ! is_array( $pending ) || empty( $pending['skip_other_security'] ) || true !== $pending['skip_other_security'] ) {
			return array( 'skip' => false );
		}

		$session_timestamp = isset( $pending['timestamp'] ) ? (int) $pending['timestamp'] : 0;
		if ( time() - $session_timestamp > 300 ) {
			delete_transient( $transient_key );
			Log::error(
				"Security bypass expired in $context",
				array(
					'feature' => 'security',
					'action'  => 'security_bypass_expired',
					'title'  => '2FA Security',
					'detail'  => "Security bypass expired for user: $username",
				)
			);
			return array(
				'skip'         => false,
				'process_stop' => true,
				'reason'       => 'Session expired',
			);
		}

		$session_ip = isset( $pending['ip_address'] ) ? (string) $pending['ip_address'] : '';
		$current_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $session_ip !== $current_ip ) {
			delete_transient( $transient_key );
			Log::error(
				"IP address changed during 2FA in $context",
				array(
					'feature' => 'security',
					'action'  => 'security_ip_change_detected',
					'title'  => '2FA Security',
					'detail'  => "IP changed. Session: $session_ip, Current: $current_ip, User: $username",
				)
			);
			return array(
				'skip'         => false,
				'process_stop' => true,
				'reason'       => 'IP address changed',
			);
		}

		return array(
			'skip'   => true,
			'reason' => 'Valid 2FA session',
		);
	}

	/**
	 * Rate limiting for 2FA attempts.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $context Context for logging.
	 * @return bool True if within rate limit, false otherwise.
	 */
	public static function check_2fa_rate_limit( $user_id, $context = 'security' ) {
		$rate_limit_key = 'wptw_2fa_attempts_' . $user_id;
		$attempts       = get_transient( $rate_limit_key ) ?: 0;

		if ( $attempts >= 5 ) {
			Log::error(
				"2FA rate limit exceeded in $context",
				array(
					'feature' => 'security',
					'action'  => 'two_factor_rate_limit_exceeded',
					'title'  => '2FA Security',
					'detail'  => "User ID: $user_id",
				)
			);
			return false;
		}

		set_transient( $rate_limit_key, $attempts + 1, 900 );
		return true;
	}

	/**
	 * Convert internal security reasons to user-friendly messages.
	 *
	 * @param string $internal_reason The internal reason from security validation.
	 * @return string User-friendly message.
	 */
	public static function get_user_friendly_message( $internal_reason ) {
		switch ( $internal_reason ) {
			case 'User ID mismatch':
			case 'Session expired':
			case 'IP address changed':
				return __( 'Your login session has expired. Please try logging in again.', 'tailwatch' );
			case 'Rate limit exceeded':
				return __( 'Too many login attempts. Please try again in a few minutes.', 'tailwatch' );
			case 'No skip flag':
				return __( 'Authentication session invalid. Please try logging in again.', 'tailwatch' );
			default:
				return __( 'Authentication failed. Please try again.', 'tailwatch' );
		}
	}
}
