<?php
/**
 * Verification Keys Controller
 *
 * Generates the Connect pairing credentials (client id / secret and the
 * shared auth-header key) and prunes expired JWT tracking rows.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Verification
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Auth\JwtService;

/**
 * Class VerificationKeysController
 *
 * Manages generation and cleanup of Connect pairing credentials and JWT tracking rows.
 */
class VerificationKeysController {

	/**
	 * Generate the pairing credentials the mobile app and cloud dashboard use to
	 * authenticate against the Connect REST API.
	 *
	 * Issues three values:
	 *   - a client id (`tailwatch_cta_id`),
	 *   - a client secret (stored bcrypt-hashed as `tailwatch_cta_secret_<id>`),
	 *   - a shared auth-header key (`tailwatch_auth_header_key`, stored plaintext for
	 *     per-request hash_equals comparison on every Connect route).
	 *
	 * Any previous credentials are cleared first, so a single active pairing exists
	 * at a time. Requires an administrator; the admin AJAX router already enforces a
	 * nonce and capability check, and this is a defense-in-depth re-check.
	 *
	 * @return array
	 */
	public function tailwatch_get_generated_cta_keys() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'You do not have permission to access this resource.', 'tailwatch' ),
				);
			}

			// Clear the previous pairing so only one is active at a time.
			$previous_cta_id = get_option( 'tailwatch_cta_id' );
			if ( $previous_cta_id ) {
				delete_option( 'tailwatch_cta_secret_' . $previous_cta_id );
				delete_option( 'tailwatch_cta_user_' . $previous_cta_id );
			}
			delete_option( 'tailwatch_cta_id' );
			delete_option( 'tailwatch_auth_header_key' );

			$cta_id     = bin2hex( random_bytes( 16 ) );
			$cta_secret = bin2hex( random_bytes( 32 ) );
			// Secondary credential the client must send as the X-Tailwatch-Auth-Key
			// header on every Connect route. Stored plaintext for per-request
			// hash_equals validation (compared on each hit; a bcrypt verify would
			// add cost to every request).
			$auth_header_key = bin2hex( random_bytes( 32 ) );

			update_option( 'tailwatch_cta_id', $cta_id );
			update_option( 'tailwatch_cta_secret_' . $cta_id, password_hash( $cta_secret, PASSWORD_BCRYPT ) );
			update_option( 'tailwatch_auth_header_key', $auth_header_key, false );
			// Bind this pairing to the admin who created it (this method runs behind a
			// manage_options gate). Connect requests re-verify this user's live capability
			// and act as them, so WordPress capability checks apply to dispatched actions.
			update_option( 'tailwatch_cta_user_' . $cta_id, get_current_user_id() );

			return array(
				'cta_id'          => $cta_id,
				'cta_secret'      => $cta_secret,
				'auth_header_key' => $auth_header_key,
				'code'            => 200,
				'message'         => __( 'Client ID, Secret and Auth Header Key retrieved successfully.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			return array(
				'code'    => 500,
				'message' => __( 'Failed to generate pairing credentials', 'tailwatch' ),
			);
		}
	}

	/**
	 * Delete expired JWT token tracking rows from the options table.
	 *
	 * Scans all `tailwatch_token_jti_*` options and removes any whose `expires`
	 * timestamp is in the past, then clears orphaned revocation flags. Called on a
	 * daily schedule so these tracking rows never accumulate indefinitely.
	 *
	 * @return int Number of expired token rows deleted.
	 */
	public function cleanup_expired_jti_tokens() {
		global $wpdb;

		// Pass 1 — delete JTI tracking rows whose tokens have expired.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; JWT-token cleanup.
		$jti_options = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'tailwatch_token_jti_' ) . '%'
		) );

		$deleted = 0;
		foreach ( $jti_options as $option ) {
			$data = get_option( $option->option_name );
			if ( is_array( $data ) && isset( $data['expires'] ) && $data['expires'] < time() ) {
				delete_option( $option->option_name );
				++$deleted;
			}
		}

		// Pass 2 — clean orphaned revocation flags. A tailwatch_token_revoked_<jti>
		// row whose matching tailwatch_token_jti_<jti> row no longer exists is
		// redundant: the token's natural lifetime has passed (refresh TTL is
		// 7 days, and the JTI row was cleaned in pass 1 or earlier), so the
		// JWT itself would fail signature/expiry verification regardless.
		// Removing these prevents option-table bloat over time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; revocation-flag cleanup.
		$revoked_options = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'tailwatch_token_revoked_' ) . '%'
		) );

		foreach ( $revoked_options as $option ) {
			$jti = substr( $option->option_name, strlen( 'tailwatch_token_revoked_' ) );

			// Skip malformed entries — don't auto-delete; leave for inspection.
			if ( ! JwtService::is_valid_jti_format( $jti ) ) {
				continue;
			}

			// $jti is validated by is_valid_jti_format() above — only /^[a-f0-9]{32}(_refresh)?$/
			// can reach here, so the option name is always tailwatch_token_jti_<hex32>. No arbitrary reads.
			$matching_jti = 'tailwatch_token_jti_' . $jti;
			if ( false === get_option( $matching_jti, false ) ) {
				delete_option( $option->option_name );
				++$deleted;
			}
		}

		return $deleted;
	}
}
