<?php
/**
 * Connect Authenticator
 *
 * REST permission callbacks for the Connect API (mobile app and cloud
 * dashboard). This is the machine-to-machine authentication plane: it does NOT
 * use nonces (nonces are CSRF protection for cookie-authenticated browser
 * requests, not an authentication mechanism for external clients). Instead it
 * verifies, inside the REST permission_callback so the check is visible to the
 * request lifecycle and to static analysis:
 *
 *   1. a per-site shared secret sent in the X-Tailwatch-Auth-Key header
 *      (constant-time hash_equals against the stored key), and
 *   2. for the dispatch route, a signed HS256 Bearer token validated by
 *      JwtService (signature, license-connected state, revocation, and device
 *      fingerprint).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Rest\Connect
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Rest\Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Auth\JwtService;
use WP_Error;
use WP_REST_Request;

/**
 * Class ConnectAuthenticator
 */
class ConnectAuthenticator {

	/**
	 * WordPress user id the current dispatch request acts as, resolved from the
	 * pairing bound to the request's JWT. 0 until authenticate() resolves it.
	 *
	 * @var int
	 */
	private static $acting_user_id = 0;

	/**
	 * Permission callback for the dispatch route: require the shared auth-header
	 * key AND a valid Bearer JWT, then resolve the administrator bound to the
	 * pairing so the dispatched action runs with that admin's real capabilities.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error True when authenticated, WP_Error (401/403) otherwise.
	 */
	public static function authenticate( WP_REST_Request $request ) {
		self::$acting_user_id = 0;

		if ( ! self::has_valid_auth_header_key() ) {
			return self::unauthorized();
		}

		$token = self::get_bearer_token( $request );
		if ( '' === $token ) {
			return self::unauthorized();
		}

		$claims = ( new JwtService() )->validate_jwt( $token );
		if ( false === $claims ) {
			return self::unauthorized();
		}

		// Resolve the admin who established this pairing and re-verify their LIVE
		// capability. The stored id is identity only, never cached authorization:
		// a deleted or demoted admin fails here, so the paired device loses access.
		$user_id = self::resolve_pairing_admin( $claims );
		if ( 0 === $user_id ) {
			return self::forbidden();
		}

		self::$acting_user_id = $user_id;
		return true;
	}

	/**
	 * The user id the dispatch route should act as, set by authenticate().
	 *
	 * @return int
	 */
	public static function acting_user_id() {
		return self::$acting_user_id;
	}

	/**
	 * Resolve the administrator bound to the pairing that minted this token, and
	 * re-verify — on every request — that they still exist and still hold
	 * manage_options. The bound id is recorded at pairing time (behind a
	 * manage_options gate) as tailwatch_cta_user_<cta_id>.
	 *
	 * @param array $claims Validated JWT claims.
	 * @return int User id, or 0 when no valid administrator is bound.
	 */
	private static function resolve_pairing_admin( $claims ) {
		$client_id = isset( $claims['client_id'] ) ? (string) $claims['client_id'] : '';
		$prefix    = 'service_account_';
		if ( 0 !== strpos( $client_id, $prefix ) ) {
			return 0;
		}

		$cta_id = substr( $client_id, strlen( $prefix ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $cta_id ) ) {
			return 0;
		}

		$user_id = (int) get_option( 'tailwatch_cta_user_' . $cta_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		// Live re-verification every request — never trust the stored value.
		if ( ! get_userdata( $user_id ) || ! user_can( $user_id, 'manage_options' ) ) {
			return 0;
		}

		return $user_id;
	}

	/**
	 * Permission callback for the authenticate / token-refresh routes: require the
	 * shared auth-header key only. Credentials (Basic auth) and the refresh token
	 * are validated in the route body, which issues or rotates the JWT.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error True when the shared key is valid, WP_Error (401) otherwise.
	 */
	public static function authenticate_shared_secret( WP_REST_Request $request ) {
		unset( $request );
		if ( ! self::has_valid_auth_header_key() ) {
			return self::unauthorized();
		}
		return true;
	}

	/**
	 * Compare the request's X-Tailwatch-Auth-Key header against the stored value
	 * using constant-time comparison. The stored value is issued by
	 * VerificationKeysController::tailwatch_get_generated_cta_keys() when the admin
	 * pairs a device, and cleared on deactivation / disconnect.
	 *
	 * @return bool True when the request header matches the stored key.
	 */
	private static function has_valid_auth_header_key() {
		$stored = get_option( 'tailwatch_auth_header_key' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return false;
		}

		// Reverse-proxy environments may surface the header under the REDIRECT_
		// prefix; check both, mirroring the Bearer-token lookup.
		$header = '';
		if ( isset( $_SERVER['HTTP_X_TAILWATCH_AUTH_KEY'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TAILWATCH_AUTH_KEY'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_X_TAILWATCH_AUTH_KEY'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_X_TAILWATCH_AUTH_KEY'] ) );
		}

		if ( '' === $header ) {
			return false;
		}

		return hash_equals( $stored, $header );
	}

	/**
	 * Extract the Bearer token from the Authorization header.
	 *
	 * Reads the header via the REST request first, then falls back to the raw
	 * server values (including the REDIRECT_ prefix) for environments where the
	 * Authorization header is not surfaced to the REST request object.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return string The token, or an empty string when none is present.
	 */
	private static function get_bearer_token( WP_REST_Request $request ) {
		$auth = (string) $request->get_header( 'authorization' );

		if ( '' === $auth ) {
			if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
			} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$auth = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
			}
		}

		// Fallback for hosts that strip the standard Authorization header before it
		// reaches PHP (CGI/FastCGI, plain-permalink Apache, or nginx without the
		// fastcgi_param). The dashboard mirrors the identical "Bearer <jwt>" value under
		// a custom X-Tailwatch-Authorization header, which those servers do forward.
		// Consulted only when Authorization is absent; the two-factor gate is unchanged
		// (this Bearer JWT AND the separate X-Tailwatch-Auth-Key are both still required).
		if ( '' === $auth ) {
			$fallback = (string) $request->get_header( 'x-tailwatch-authorization' );
			if ( '' === $fallback ) {
				if ( isset( $_SERVER['HTTP_X_TAILWATCH_AUTHORIZATION'] ) ) {
					$fallback = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TAILWATCH_AUTHORIZATION'] ) );
				} elseif ( isset( $_SERVER['REDIRECT_HTTP_X_TAILWATCH_AUTHORIZATION'] ) ) {
					$fallback = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_X_TAILWATCH_AUTHORIZATION'] ) );
				}
			}
			$auth = $fallback;
		}

		if ( '' === $auth || 0 !== stripos( $auth, 'Bearer ' ) ) {
			return '';
		}

		return trim( substr( $auth, 7 ) );
	}

	/**
	 * Standard 401 error for the Connect plane. A single generic message avoids
	 * revealing which factor failed.
	 *
	 * @return WP_Error
	 */
	private static function unauthorized() {
		return new WP_Error(
			'tailwatch_rest_unauthorized',
			__( 'Authentication required.', 'tailwatch' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Standard 403 for an authenticated request whose bound administrator is no
	 * longer authorized (deleted, or no longer holds manage_options).
	 *
	 * @return WP_Error
	 */
	private static function forbidden() {
		return new WP_Error(
			'tailwatch_rest_forbidden',
			__( 'This device is no longer authorized.', 'tailwatch' ),
			array( 'status' => 403 )
		);
	}
}
