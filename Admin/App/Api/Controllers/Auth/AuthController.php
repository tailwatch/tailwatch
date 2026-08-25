<?php
/**
 * Tailwatch - Authentication Controller
 *
 * Handles client authentication via JWT tokens for the Connect REST API
 * (mobile app and cloud dashboard).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Auth
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Auth;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Auth\JwtService;
use Tailwatch\Admin\App\Api\Services\IpService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AuthController
 *
 * Manages client authentication and JWT token generation.
 */
class AuthController {

	/**
	 * Maximum login attempts per minute.
	 *
	 * @var int
	 */
	private $rate_limit = 5;

	/**
	 * Transient key prefix for rate limiting.
	 *
	 * @var string
	 */
	private $rate_limit_key = 'tailwatch_login_attempts_';

	/**
	 * Handle client login and generate tokens.
	 *
	 * This is a REST API endpoint for JWT authentication using client credentials.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $data    Token data on success, empty on failure.
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 * }
	 */
	public function login() {
		try {
			// HTTP Basic Auth (RFC 7617): Authorization: Basic base64(id:secret).
			// Headers instead of POST body so a form-based CSRF cannot reach
			// this endpoint — custom / Authorization headers can't be set by
			// an HTML form across origins.
			$auth_header = '';
			if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
			} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
			}

			if ( '' === $auth_header || 0 !== strpos( $auth_header, 'Basic ' ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Missing or invalid Authorization header. Use HTTP Basic Auth.', 'tailwatch' ),
					'code'    => 401,
				);
			}

			$decoded = base64_decode( substr( $auth_header, 6 ), true );
			if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Malformed Authorization credentials.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			list( $client_id, $client_secret ) = explode( ':', $decoded, 2 );
			$client_id = sanitize_text_field( $client_id );
			// client_secret intentionally NOT sanitized — credential must
			// reach password_verify byte-exact.

			// CTA ids we mint are `bin2hex(random_bytes(16))`; reject
			// other shapes before the dynamic `get_option()`.
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $client_id ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid client credentials.', 'tailwatch' ),
					'code'    => 401,
				);
			}

			// Rate limiting.
			$ip = IpService::get_client_ip();
			if ( $this->is_rate_limited( $ip, $client_id ) ) {
				Log::warning(
					'Login rate limited',
					array(
						'feature'   => 'auth',
						'action'    => 'auth_login_rate_limited',
						'client_id' => $client_id,
						'ip'        => $ip,
						'title'     => 'Login Rate Limited',
						'meta_data' => array(
							'feature'  => 'Authentication',
							'event'    => 'Rate limited',
							'block_ip' => $ip,
							'endpoint' => '/authenticate',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Too many login attempts. Try again later.', 'tailwatch' ),
					'code'    => 429,
				);
			}

			// Validate client credentials.
			$stored_secret = get_option( 'tailwatch_cta_secret_' . $client_id );
			if ( ! $stored_secret || ! password_verify( $client_secret, $stored_secret ) ) {
				$this->increment_rate_limit( $ip, $client_id );
				Log::warning(
					'Login failed: Invalid credentials',
					array(
						'feature'   => 'auth',
						'action'    => 'auth_login_failed',
						'client_id' => $client_id,
						'ip'        => $ip,
						'error'     => 'Invalid client credentials',
						'meta_data' => array(
							'feature'  => 'Authentication',
							'event'    => 'Failed',
							'block_ip' => $ip,
							'endpoint' => '/authenticate',
							'reason'   => 'Invalid credentials',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid client credentials', 'tailwatch' ),
					'code'    => 401,
				);
			}

			// Generate JWT tokens.
			$jwt_config = new JwtService();
			$tokens     = $jwt_config->generate_jwt( 'service_account_' . $client_id, $ip );

			if ( empty( $tokens ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Authentication is temporarily unavailable.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			Log::info(
				'Login successful',
				array(
					'feature'   => 'auth',
					'action'    => 'auth_login_completed',
					'client_id' => $client_id,
					'ip'        => $ip,
					'title'     => 'Client Login',
				)
			);

			return array(
				'data'    => $tokens,
				'message' => __( 'Authentication successful', 'tailwatch' ),
				'code'    => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Login error: Exception occurred',
				array(
					'feature'   => 'auth',
					'action'    => 'auth_login_error',
					'error'     => $e->getMessage(),
					'exception' => $e,
					'meta_data' => array(
						'feature'  => 'Authentication',
						'event'    => 'Error',
						'endpoint' => '/authenticate',
						'reason'   => 'Unexpected error',
					),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred during authentication', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Refresh access token using refresh token.
	 *
	 * This is a REST API endpoint for JWT token refresh.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $data    New token data on success, empty on failure.
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 * }
	 */
	public function refresh_token() {
		try {
			// Refresh token arrives via the X-Tailwatch-Refresh-Token header
			// (header instead of POST body — same CSRF rationale as /authenticate).
			// A custom header is used here rather than Authorization: Bearer
			// because the JWT access token also uses Authorization: Bearer
			// on /dispatch; reusing it would conflate the two tokens.
			$refresh_token = '';
			if ( isset( $_SERVER['HTTP_X_TAILWATCH_REFRESH_TOKEN'] ) ) {
				$refresh_token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TAILWATCH_REFRESH_TOKEN'] ) );
			} elseif ( isset( $_SERVER['REDIRECT_HTTP_X_TAILWATCH_REFRESH_TOKEN'] ) ) {
				$refresh_token = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_X_TAILWATCH_REFRESH_TOKEN'] ) );
			}

			if ( empty( $refresh_token ) ) {
				Log::warning(
					'Token refresh failed: Missing refresh token',
					array(
						'feature' => 'auth',
						'action'  => 'auth_refresh_failed',
						'error'   => 'Refresh token is required',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Refresh token is required', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$jwt_config = new JwtService();
			$result     = $jwt_config->refresh_jwt( $refresh_token, IpService::get_client_ip() );

			// Log refresh attempt.
			if ( 200 === $result['code'] ) {
				Log::info(
					'Token refresh successful',
					array(
						'feature'   => 'auth',
						'action'    => 'auth_refresh_completed',
						'client_id' => $result['data']['client_id'],
						'title'     => 'Token Refreshed',
					)
				);
			} else {
				Log::warning(
					'Token refresh failed',
					array(
						'feature' => 'auth',
						'action'  => 'auth_refresh_failed',
						'error'   => $result['message'],
					)
				);
			}

			return $result;

		} catch ( \Throwable $e ) {
			Log::error(
				'Token refresh error: Exception occurred',
				array(
					'feature'   => 'auth',
					'action'    => 'auth_refresh_error',
					'error'     => $e->getMessage(),
					'exception' => $e,
					'meta_data' => array(
						'feature'  => 'Authentication',
						'event'    => 'Refresh error',
						'endpoint' => '/token/refresh',
						'reason'   => 'Unexpected error',
					),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while refreshing the token', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Check if client is rate limited.
	 *
	 * @param string $ip        Client IP address.
	 * @param string $client_id Client identifier.
	 *
	 * @return bool True if rate limited, false otherwise.
	 */
	private function is_rate_limited( $ip, $client_id ) {
		$key      = $this->rate_limit_key . md5( $ip . $client_id );
		$attempts = get_transient( $key );
		return false !== $attempts && $attempts >= $this->rate_limit;
	}

	/**
	 * Increment rate limit counter.
	 *
	 * @param string $ip        Client IP address.
	 * @param string $client_id Client identifier.
	 *
	 * @return void
	 */
	private function increment_rate_limit( $ip, $client_id ) {
		$key      = $this->rate_limit_key . md5( $ip . $client_id );
		$attempts = get_transient( $key );
		set_transient( $key, ( $attempts ? $attempts + 1 : 1 ), 60 );
	}
}
