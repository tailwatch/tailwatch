<?php
/**
 * Tailwatch - API Routing Handler
 *
 * Handles routing of API requests to appropriate controllers.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Routes
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Routes;

use Tailwatch\Admin\App\Api\Services\Auth\JwtService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Routing' ) ) {

	/**
	 * Class Routing
	 *
	 * Routes API requests to controllers based on endpoint and authentication.
	 *
	 */
	class Routing {

		/**
		 * Public-group endpoints (path component, leading slash stripped)
		 * that opt in to X-Tailwatch-Auth-Key header validation. Auth-group
		 * endpoints always require the header — listing them here would be
		 * redundant.
		 *
		 * @var string[]
		 */
		private static $public_endpoints_requiring_header = array(
			'login',
			'refresh-token',
		);

		/**
		 * Current request HTTP method.
		 *
		 * @var string
		 */
		private static $request_method;

		/**
		 * Current request URI.
		 *
		 * @var string
		 */
		private static $request_uri;

		/**
		 * Authorization token from request header.
		 *
		 * @var string
		 */
		private static $auth_token;

		/**
		 * Singleton instance.
		 *
		 * @var Routing|null
		 */
		private static $instance;

		/**
		 * Registered routes configuration.
		 *
		 * @var array
		 */
		private static $routes;

		/**
		 * Initialize routing singleton.
	     *
		 * @param array $routes Routes configuration array.
		 *
		 * @return Routing|null Routing instance.
		 */
		public static function initialize( $routes ) {
			if ( ! isset( self::$instance ) && isset( $routes ) ) {
				self::$instance = new self();
				self::$routes   = $routes;
			}
			return self::$instance;
		}

		/**
		 * Private constructor for singleton pattern.
		 *
		 */
		private function __construct() {
			add_action( 'plugins_loaded', array( $this, 'initialize_routing' ), 9999999 );
		}

		/**
		 * Initialize routing on plugins_loaded hook.
	     *
		 * @return void
		 */
		public function initialize_routing() {
			if ( did_action( 'init' ) ) {
				$this->execute_routing();
			} else {
				add_action( 'init', array( $this, 'execute_routing' ), 999999 );
			}
		}

		/**
		 * Execute routing logic for current request.
	     *
		 * @return void
		 */
		public function execute_routing() {
			$routes           = self::$routes;
			$redirect_auth    = isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) : '';
			$http_auth        = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
			$auth_header      = ! empty( $redirect_auth ) ? $redirect_auth : $http_auth;
			self::$auth_token = str_replace( 'Bearer ', '', $auth_header );

			self::$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
			self::$request_uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url_parse            = wp_parse_url( self::$request_uri );
			$url_path             = isset( $url_parse['path'] ) ? rtrim( $url_parse['path'], '/' ) : null;

			if ( ! isset( $url_path ) ) {
				return;
			}

			$url_indexes = explode( '/', $url_path );

			// Ensure URL has enough segments to extract API slug and endpoint.
			if ( count( $url_indexes ) < 2 ) {
				return;
			}

			$requested_endpoint = basename( implode( '/', array_slice( $url_indexes, 2 ) ) );
			$api_slug           = $url_indexes[ count( $url_indexes ) - 2 ];
			$api_slug_found     = false;

			if ( WPTW_API_SLUG !== $api_slug ) {
				return;
			}

			// Check if the route is in the 'Auth' group (requires authentication).
			foreach ( $routes as $route => $route_value ) {
				foreach ( $route_value as $method => $endpoints ) {
					foreach ( $endpoints as $endpoint => $controller ) {

						if ( 'Auth' === $route && isset( $endpoints[ $endpoint ] ) && self::$request_method === $method && basename( $endpoint ) === $requested_endpoint ) {
							$api_slug_found = true;

							// Auth-group routes always require the secondary
							// header. Validate before any JWT work so a missing
							// header short-circuits the more expensive signature
							// check.
							if ( ! self::validate_auth_header_key() ) {
								self::response( array(
									'data'    => array(),
									'message' => __( 'Missing or invalid auth header key.', 'tailwatch' ),
									'code'    => 401,
								) );
								exit;
							}

							// Validate JWT token for Auth routes.
							if ( ! empty( self::$auth_token ) ) {
								$jwt_config    = new JwtService();
								$decoded_token = $jwt_config->validate_jwt( self::$auth_token );

								if ( ! $decoded_token ) {
									$data = array(
										'data'    => array(),
										'message' => __( 'Invalid or expired token', 'tailwatch' ),
										'code'    => 401,
									);
									self::response( $data );
									exit;
								}
							} else {
								$data = array(
									'data'    => array(),
									'message' => __( 'Unauthorized request.', 'tailwatch' ),
									'code'    => 401,
								);
								self::response( $data );
								exit;
							}

							// Process the Auth route after successful token validation.
							self::process_rules( $api_slug, $endpoint );
							self::parse_request( $method, $api_slug . $endpoint, $controller );
							break 3;
						}

						// Check if the route is in the 'Public' group (no authentication required).
						if ( 'Public' === $route && isset( $endpoints[ $endpoint ] ) && self::$request_method === $method && basename( $endpoint ) === $requested_endpoint ) {
							$api_slug_found = true;

							// Public endpoints opt in to header validation
							// via $public_endpoints_requiring_header. Endpoints
							// not in the list (e.g. /connect-google OAuth
							// callback) skip this check.
							if ( in_array( $requested_endpoint, self::$public_endpoints_requiring_header, true )
								&& ! self::validate_auth_header_key() ) {
								self::response( array(
									'data'    => array(),
									'message' => __( 'Missing or invalid auth header key.', 'tailwatch' ),
									'code'    => 401,
								) );
								exit;
							}

							self::process_rules( $api_slug, $endpoint );
							self::parse_request( $method, $api_slug . $endpoint, $controller );
							break 3;
						}
					}
				}
			}

			if ( ! $api_slug_found ) {
				$data = array(
					'data'    => array(),
					'message' => __( 'Sorry, this method/route is not allowed.', 'tailwatch' ),
					'code'    => 404,
				);
				self::response( $data );
				exit;
			}
		}


		/**
		 * Parse and execute request callback.
	     *
		 * @param string $method   HTTP method.
		 * @param string $uri      Request URI.
		 * @param string $callback Callback in format 'method@Controller'.
		 *
		 * @return void
		 */
		private static function parse_request( string $method, string $uri, string $callback ) {
			$explode        = explode( '@', $callback );
			$method         = $explode[0];
			$controller     = $explode[1];
			$callback_class = '\Tailwatch\Admin\App\Api\Controllers\\' . $controller;
			$callback_class = new $callback_class();
			$callback_data  = $callback_class->$method();
			if ( is_array( $callback_data ) ) {
				$expected_keys = array( 'message', 'code' );
				$array_keys    = array_keys( $callback_data );
				if ( array() === array_diff( $expected_keys, $array_keys ) ) {
					if ( is_int( $callback_data['code'] ) ) {
						self::response( $callback_data );
					} else {
						echo esc_html( 'Incorrect Response: The value of the index named [CODE] must be an integer, e.g: 200,404 etc. Please see: ' . $method . '@' . $controller );
					}
				} else {
					echo esc_html( 'Incorrect Response: One or multiple indexes are missing, should have a minimum of THREE Indexes [data, message and code]. Please see: ' . $method . '@' . $controller );
				}
			} else {
				echo esc_html( 'Incorrect Response: The response should be an ARRAY with a minimum of THREE Indexes [data,message and code]. Please see: ' . $method . '@' . $controller );
			}
			exit;
		}

		/**
		 * Send JSON response and exit.
	     *
		 * @param array $data Response data array.
		 *
		 * @return void
		 */
		private static function response( array $data ) {
			http_response_code( ( ! isset( $data['code'] ) ) ? 200 : $data['code'] );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( $data );
			exit;
		}

		/**
		 * Compare the request's X-Tailwatch-Auth-Key header against the
		 * stored value using constant-time comparison. The stored value is
		 * issued by VerificationKeysController::wptw_get_generated_cta_keys
		 * and cleared on license disconnect / plugin deactivation.
		 *
		 * @since 1.0.0
		 *
		 * @return bool True when the request header matches the stored key.
		 */
		private static function validate_auth_header_key() {
			$stored = get_option( 'wptw_auth_header_key' );
			if ( ! is_string( $stored ) || '' === $stored ) {
				return false;
			}

			// Reverse-proxy environments may surface the header under the
			// REDIRECT_ prefix; mirror the JWT bearer-token lookup which
			// checks both.
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
		 * Process rewrite rules for API endpoint.
	     *
		 * @param string $api_slug API slug.
		 * @param string $endpoint Endpoint path.
		 *
		 * @return bool Always returns true.
		 */
		private static function process_rules( $api_slug, $endpoint ) {
			$existing_rules = get_option( 'rewrite_rules' );
			$endpoint_rule  = '^' . $api_slug . '/' . $endpoint . '/?$';
			if ( ! isset( $existing_rules[ $endpoint_rule ] ) ) {
				add_rewrite_rule( '^' . $api_slug . '/' . $endpoint . '/?$', 'index.php?' . $api_slug . '=' . $endpoint . '', 'top' );
				// This flush is NOT the "flush on every page load" anti-pattern: it is
				// reached only on an already-matched /<api_slug>/<endpoint> API request
				// (normal front-end and admin page loads return early in
				// execute_routing() before ever calling this), AND only when that
				// specific endpoint's rewrite rule is not yet persisted. It is
				// self-limiting — once the rule is written to the rewrite_rules option
				// the isset() guard above prevents any further flush for that endpoint,
				// so it runs at most once per endpoint for the lifetime of the install.
				// Activation/deactivation flushes are handled separately in Bootstrap.
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- One-time-per-endpoint persistence on a matched API request; guarded + self-limiting, not a per-page-load flush.
				flush_rewrite_rules();
			}
			return true;
		}
	}
}
