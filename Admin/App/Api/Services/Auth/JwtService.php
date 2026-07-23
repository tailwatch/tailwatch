<?php
namespace Tailwatch\Admin\App\Api\Services\Auth;

use Tailwatch\Vendor\Firebase\JWT\JWT;
use Tailwatch\Vendor\Firebase\JWT\Key;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Services\IpService;

defined( 'ABSPATH' ) || exit;

/**
 * JWT access/refresh token lifecycle — issue, validate, refresh, revoke.
 */
class JwtService {

	/** @var int Access token TTL — 2 days. */
	private $access_token_expiration = 172800;

	/** @var int Refresh token TTL — 7 days. */
	private $refresh_token_expiration = 604800;

	/**
	 * Issue a fresh access/refresh token pair bound to the device
	 * fingerprint (user-agent + ip hash).
	 *
	 * @return array{access_token:string,access_token_expiration:int,refresh_token:string,refresh_token_expiration:int,client_id:string}
	 */
	public function generate_jwt( $client_id, $ip ) {
		$jti                = bin2hex( random_bytes( 16 ) );
		$access_expiration  = time() + $this->access_token_expiration;
		$refresh_expiration = time() + $this->refresh_token_expiration;

		$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
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

		$access_token  = JWT::encode( $access_payload, WPTW_JWT_AUTH_SECRET_KEY, 'HS256' );
		$refresh_token = JWT::encode( $refresh_payload, WPTW_JWT_AUTH_SECRET_KEY, 'HS256' );

		update_option(
			'wptw_token_jti_' . $jti,
			array(
				'client_id' => $client_id,
				'expires'   => $refresh_expiration,
				'device'    => $device_fingerprint,
			)
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
	 * Verify signature, license-connected state, JTI revocation, and device
	 * fingerprint. Returns the decoded payload as array, or false on any
	 * failure (caller treats both signature errors and policy rejections
	 * the same way).
	 *
	 * @return array|false
	 */
	public function validate_jwt( $token ) {
		try {
			$decoded = JWT::decode( $token, new Key( WPTW_JWT_AUTH_SECRET_KEY, 'HS256' ) );

			// Defense in depth: reject all tokens when the license is no
			// longer connected. The JTI revocation check below catches
			// pre-existing tokens that were explicitly revoked, but this
			// guard adds protection against any window where cleanup was
			// partial or a future code change could miss a revocation.
			$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
			if ( ! is_array( $activation ) || empty( $activation['extended_connected'] ) ) {
				return false;
			}

			$jti = $decoded->jti ?? '';
			if ( ! self::is_valid_jti_format( $jti ) ) {
				return false;
			}
			if ( get_option( 'wptw_token_revoked_' . $jti ) ) {
				return false;
			}

			// Resolve IP via the same canonical helper used when the token was
			// minted (login/refresh). Reading raw REMOTE_ADDR here would mismatch
			// the fingerprint behind a proxy/CDN (REMOTE_ADDR = edge IP, mint used
			// the forwarded client IP) and reject every request.
			$ip                 = IpService::get_client_ip();
			$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
			$device_fingerprint = hash( 'sha256', $user_agent . $ip );
			if ( ! hash_equals( (string) ( $decoded->device ?? '' ), (string) $device_fingerprint ) ) {
				$this->revoke_token( $jti );
				return false;
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
	 * @return array{data:array,message:string,code:int}
	 */
	public function refresh_jwt( $refresh_token, $ip ) {
		try {
			$decoded = JWT::decode( $refresh_token, new Key( WPTW_JWT_AUTH_SECRET_KEY, 'HS256' ) );

			$jti = $decoded->jti ?? '';
			if ( ! self::is_valid_jti_format( $jti ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid refresh token', 'tailwatch' ),
					'code'    => 401,
				);
			}
			if ( get_option( 'wptw_token_revoked_' . $jti ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Refresh token revoked', 'tailwatch' ),
					'code'    => 401,
				);
			}

			$user_agent         = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
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
				WPTW_JWT_AUTH_SECRET_KEY,
				'HS256'
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
				WPTW_JWT_AUTH_SECRET_KEY,
				'HS256'
			);

			update_option(
				'wptw_token_jti_' . $new_jti,
				array(
					'client_id' => $decoded->client_id,
					'expires'   => $new_refresh_expiration,
					'device'    => $device_fingerprint,
				)
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

	public function revoke_token( $jti ) {
		if ( ! self::is_valid_jti_format( $jti ) ) {
			return;
		}
		update_option( 'wptw_token_revoked_' . $jti, true );
	}

	/**
	 * JTIs we mint are `bin2hex(random_bytes(16))`, optionally suffixed
	 * with `_refresh` on refresh tokens. Used as a gate before any
	 * dynamic `wptw_token_jti_*` / `wptw_token_revoked_*` lookup.
	 */
	public static function is_valid_jti_format( $jti ) {
		return is_string( $jti ) && '' !== $jti && (bool) preg_match( '/^[a-f0-9]{32}(_refresh)?$/', $jti );
	}
}