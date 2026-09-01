<?php
/**
 * Tailwatch - JWT Service
 *
 * JWT access/refresh token lifecycle — issue, validate, refresh, revoke — for the
 * Connect REST API (mobile app and cloud dashboard).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Auth
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Services\Auth;

use Tailwatch\Vendor\Firebase\JWT\JWT;
use Tailwatch\Vendor\Firebase\JWT\Key;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Services\IpService;
use Tailwatch\Admin\App\Api\Services\Crypto\AppSecretService;

defined( 'ABSPATH' ) || exit;

/**
 * JWT access/refresh token lifecycle — issue, validate, refresh, revoke.
 */
class JwtService {

	/** @var int Access token TTL — 2 days. */
	private $access_token_expiration = 172800;

	/** @var int Refresh token TTL — 7 days. */
	private $refresh_token_expiration = 604800;

	/** @var string Signing algorithm. */
	const ALGORITHM = 'HS256';

	/**
	 * Resolve the HS256 signing key.
	 *
	 * Order of precedence:
	 *   1. An optional TAILWATCH_JWT_SECRET_KEY constant, for operators who prefer
	 *      a dedicated secret defined in wp-config.php. The plugin only READS it.
	 *   2. Otherwise a key derived from the plugin's master secret
	 *      (AppSecretService), which is stored independently of WordPress's salts.
	 *
	 * The key is INTENTIONALLY not derived from wp_salt(): the plugin ships a
	 * security-key rotation feature, and access/refresh tokens live for days, so a
	 * salt-derived signing key would be silently invalidated every rotation.
	 * Deriving from a dedicated, rotation-independent secret keeps issued tokens
	 * valid across salt rotation. The source is passed through SHA-256 with a
	 * domain-separation prefix, so this key is cryptographically independent of the
	 * encryption key derived from the same master secret.
	 *
	 * @return string 64-character hex key, or empty string if no key material is available.
	 */
	private static function get_signing_key() {
		if ( defined( 'TAILWATCH_JWT_SECRET_KEY' ) && is_string( TAILWATCH_JWT_SECRET_KEY ) && '' !== TAILWATCH_JWT_SECRET_KEY ) {
			return hash( 'sha256', 'tailwatch-jwt-auth|' . TAILWATCH_JWT_SECRET_KEY );
		}

		$secret = AppSecretService::get_secret();
		if ( '' === $secret ) {
			return '';
		}
		return hash( 'sha256', 'tailwatch-jwt-auth|' . $secret );
	}

	/**
	 * Issue a fresh access/refresh token pair bound to the device
	 * fingerprint (user-agent + ip hash).
	 *
	 * @param string $client_id Client identifier the tokens are minted for.
	 * @param string $ip        Client IP resolved by IpService at mint time.
	 * @return array{access_token:string,access_token_expiration:int,refresh_token:string,refresh_token_expiration:int,client_id:string}|array{}
	 */
	public function generate_jwt( $client_id, $ip ) {
		$signing_key = self::get_signing_key();
		if ( '' === $signing_key ) {
			return array();
		}

		$jti                = bin2hex( random_bytes( 16 ) );
		$access_expiration  = time() + $this->access_token_expiration;
		$refresh_expiration = time() + $this->refresh_token_expiration;

		$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$device_fingerprint = hash( 'sha256', $user_agent . $ip );

		$access_payload = array(
			'iss'       => get_bloginfo( 'url' ),
			'iat'       => time(),
			'exp'       => $access_expiration,
			'jti'       => $jti,
			'client_id' => $client_id,
			'scope'     => 'api_access',
			'device'    => $device_fingerprint,
		);

		$refresh_payload = array(
			'iss'       => get_bloginfo( 'url' ),
			'iat'       => time(),
			'exp'       => $refresh_expiration,
			'jti'       => $jti . '_refresh',
			'client_id' => $client_id,
			'device'    => $device_fingerprint,
		);

		$access_token  = JWT::encode( $access_payload, $signing_key, self::ALGORITHM );
		$refresh_token = JWT::encode( $refresh_payload, $signing_key, self::ALGORITHM );

		// Non-autoloaded: read on demand by exact key, never needed on every request.
		update_option(
			'tailwatch_token_jti_' . $jti,
			array(
				'client_id' => $client_id,
				'expires'   => $refresh_expiration,
				'device'    => $device_fingerprint,
			),
			false
		);

		return array(
			'access_token'             => $access_token,
			'access_token_expiration'  => $access_expiration,
			'refresh_token'            => $refresh_token,
			'refresh_token_expiration' => $refresh_expiration,
			'client_id'                => $client_id,
		);
	}

	/**
	 * Verify signature, license-connected state, JTI revocation, and (optionally) the
	 * device fingerprint. Returns the decoded payload as array, or false on any
	 * failure (caller treats both signature errors and policy rejections the same way).
	 *
	 * @param string $token        Access token to validate.
	 * @param bool   $check_device Whether to enforce the device fingerprint. Cookie/mobile
	 *                             clients keep it true — they are a single stable device.
	 *                             A server-to-server relay route (scanner-event) passes false:
	 *                             its egress IP can change between requests, so an IP-based
	 *                             binding cannot hold and a mismatch would auto-revoke the
	 *                             token. Such a route stays gated by signature +
	 *                             license-connected + JTI-revocation plus its own transport
	 *                             auth (a shared header key and a per-request secret).
	 * @return array|false
	 */
	public function validate_jwt( $token, $check_device = true ) {
		$signing_key = self::get_signing_key();
		if ( '' === $signing_key ) {
			return false;
		}

		try {
			$decoded = JWT::decode( $token, new Key( $signing_key, self::ALGORITHM ) );

			// Defense in depth: reject all tokens when the license is no
			// longer connected. The JTI revocation check below catches
			// pre-existing tokens that were explicitly revoked, but this
			// guard adds protection against any window where cleanup was
			// partial or a future code change could miss a revocation. It
			// also enforces consent — no request is served on a bare
			// activation, only once the site is connected.
			$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
			if ( ! is_array( $activation ) || empty( $activation['extended_connected'] ) ) {
				return false;
			}

			$jti = $decoded->jti ?? '';
			if ( ! self::is_valid_jti_format( $jti ) ) {
				return false;
			}
			if ( get_option( 'tailwatch_token_revoked_' . $jti ) ) {
				return false;
			}

			if ( $check_device ) {
				// Resolve IP via the same canonical helper used when the token was
				// minted (login/refresh). Reading raw REMOTE_ADDR here would mismatch
				// the fingerprint behind a proxy/CDN (REMOTE_ADDR = edge IP, mint used
				// the forwarded client IP) and reject every request.
				$ip                 = IpService::get_client_ip();
				$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
				$device_fingerprint = hash( 'sha256', $user_agent . $ip );
				if ( ! hash_equals( (string) ( $decoded->device ?? '' ), (string) $device_fingerprint ) ) {
					$this->revoke_token( $jti );
					return false;
				}
			}

			return (array) $decoded;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Rotate the token pair using a refresh token. Old refresh token is
	 * revoked on success so it can't be replayed.
	 *
	 * @param string $refresh_token Refresh token presented by the client.
	 * @param string $ip            Client IP resolved by IpService.
	 * @return array{data:array,message:string,code:int}
	 */
	public function refresh_jwt( $refresh_token, $ip ) {
		$signing_key = self::get_signing_key();
		if ( '' === $signing_key ) {
			return array(
				'data'    => array(),
				'message' => __( 'Invalid refresh token', 'tailwatch' ),
				'code'    => 401,
			);
		}

		try {
			$decoded = JWT::decode( $refresh_token, new Key( $signing_key, self::ALGORITHM ) );

			$jti = $decoded->jti ?? '';
			if ( ! self::is_valid_jti_format( $jti ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid refresh token', 'tailwatch' ),
					'code'    => 401,
				);
			}
			if ( get_option( 'tailwatch_token_revoked_' . $jti ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Refresh token revoked', 'tailwatch' ),
					'code'    => 401,
				);
			}

			$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$device_fingerprint = hash( 'sha256', $user_agent . $ip );
			if ( ! hash_equals( (string) ( $decoded->device ?? '' ), (string) $device_fingerprint ) ) {
				$this->revoke_token( $jti );
				return array(
					'data'    => array(),
					'message' => __( 'Invalid device', 'tailwatch' ),
					'code'    => 401,
				);
			}

			if ( $decoded->exp < time() ) {
				return array(
					'data'    => array(),
					'message' => __( 'Refresh token expired', 'tailwatch' ),
					'code'    => 401,
				);
			}

			$this->revoke_token( $decoded->jti );

			$new_jti                = bin2hex( random_bytes( 16 ) );
			$new_access_expiration  = time() + $this->access_token_expiration;
			$new_refresh_expiration = time() + $this->refresh_token_expiration;

			$new_access_token = JWT::encode(
				array(
					'iss'       => get_bloginfo( 'url' ),
					'iat'       => time(),
					'exp'       => $new_access_expiration,
					'jti'       => $new_jti,
					'client_id' => $decoded->client_id,
					'scope'     => 'api_access',
					'device'    => $device_fingerprint,
				),
				$signing_key,
				self::ALGORITHM
			);

			$new_refresh_token = JWT::encode(
				array(
					'iss'       => get_bloginfo( 'url' ),
					'iat'       => time(),
					'exp'       => $new_refresh_expiration,
					'jti'       => $new_jti . '_refresh',
					'client_id' => $decoded->client_id,
					'device'    => $device_fingerprint,
				),
				$signing_key,
				self::ALGORITHM
			);

			// Non-autoloaded: read on demand by exact key, never needed on every request.
			update_option(
				'tailwatch_token_jti_' . $new_jti,
				array(
					'client_id' => $decoded->client_id,
					'expires'   => $new_refresh_expiration,
					'device'    => $device_fingerprint,
				),
				false
			);

			return array(
				'data'    => array(
					'access_token'             => $new_access_token,
					'access_token_expiration'  => $new_access_expiration,
					'refresh_token'            => $new_refresh_token,
					'refresh_token_expiration' => $new_refresh_expiration,
					'client_id'                => $decoded->client_id,
				),
				'message' => __( 'Access token refreshed successfully', 'tailwatch' ),
				'code'    => 200,
			);
		} catch ( \Exception $e ) {
			return array(
				'data'    => array(),
				'message' => __( 'Invalid refresh token', 'tailwatch' ),
				'code'    => 401,
			);
		}
	}

	/**
	 * Mark a token's JTI as revoked so it can no longer be used.
	 *
	 * @param string $jti Token identifier to revoke.
	 * @return void
	 */
	public function revoke_token( $jti ) {
		if ( ! self::is_valid_jti_format( $jti ) ) {
			return;
		}
		// Non-autoloaded: revocation flag is checked on demand by exact key.
		update_option( 'tailwatch_token_revoked_' . $jti, true, false );
	}

	/**
	 * JTIs we mint are `bin2hex(random_bytes(16))`, optionally suffixed
	 * with `_refresh` on refresh tokens. Used as a gate before any
	 * dynamic `tailwatch_token_jti_*` / `tailwatch_token_revoked_*` lookup.
	 *
	 * @param string $jti Value to validate.
	 * @return bool
	 */
	public static function is_valid_jti_format( $jti ) {
		return is_string( $jti ) && '' !== $jti && (bool) preg_match( '/^[a-f0-9]{32}(_refresh)?$/', $jti );
	}
}
