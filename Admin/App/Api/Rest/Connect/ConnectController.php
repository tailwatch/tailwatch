<?php
/**
 * Connect Controller
 *
 * Registers the Connect REST API (mobile app and cloud dashboard) under the
 * tailwatch/v1 namespace and delegates each route to existing services:
 *
 *   POST tailwatch/v1/authenticate   -> AuthController::login (issue JWT)
 *   POST tailwatch/v1/token/refresh  -> AuthController::refresh_token (rotate JWT)
 *   POST tailwatch/v1/dispatch       -> ActionDispatcher::dispatch (run an action)
 *
 * Authentication for every route lives in the permission_callback
 * (ConnectAuthenticator), so it is enforced before any callback runs and is
 * visible to the REST lifecycle and static analysis. These routes replace the
 * plugin's former hand-rolled router; there is no rewrite rule and no custom
 * request parsing.
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

use Tailwatch\Admin\App\Api\Controllers\Auth\AuthController;
use Tailwatch\Admin\App\Api\Controllers\Routes\ActionDispatcher;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ConnectController
 */
class ConnectController {

	/**
	 * REST namespace for the Connect API.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'tailwatch/v1';

	/**
	 * Constructor - register routes on rest_api_init.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the Connect routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/authenticate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'authenticate' ),
				'permission_callback' => array( ConnectAuthenticator::class, 'authenticate_shared_secret' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/token/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_token' ),
				'permission_callback' => array( ConnectAuthenticator::class, 'authenticate_shared_secret' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/dispatch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dispatch' ),
				'permission_callback' => array( ConnectAuthenticator::class, 'authenticate' ),
				'args'                => array(
					'action_type' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Issue a JWT for valid client credentials.
	 *
	 * Credentials arrive as HTTP Basic auth and are validated inside
	 * AuthController::login(); the shared auth-header key was already checked in
	 * the permission_callback.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function authenticate( WP_REST_Request $request ) {
		unset( $request );
		return $this->to_response( ( new AuthController() )->login() );
	}

	/**
	 * Rotate a JWT using a refresh token supplied in the
	 * X-Tailwatch-Refresh-Token header.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function refresh_token( WP_REST_Request $request ) {
		unset( $request );
		return $this->to_response( ( new AuthController() )->refresh_token() );
	}

	/**
	 * Execute a plugin action through the shared dispatcher.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return WP_REST_Response
	 */
	public function dispatch( WP_REST_Request $request ) {
		$action_type = sanitize_text_field( (string) $request->get_param( 'action_type' ) );

		$dispatcher = new ActionDispatcher();

		// The action payload mirrors the admin AJAX contract: a JSON string in the
		// `data` field. When the client sends a JSON body, the REST layer decodes
		// `data` into an array — re-encode it so the shared sanitizer receives the
		// same JSON-string shape it expects on both front-doors.
		$raw = $request->get_param( 'data' );
		if ( is_array( $raw ) ) {
			$raw = wp_json_encode( $raw );
		}
		$post_data = $dispatcher->sanitize_request_payload( is_string( $raw ) ? $raw : null );

		// Act as the administrator bound to this pairing — resolved and re-verified in
		// the permission_callback — so WordPress capability checks apply to the action.
		// Scoped to this dispatch only and restored in the finally block: the acting
		// user is never persisted and no authentication cookie is set.
		$acting_user_id   = ConnectAuthenticator::acting_user_id();
		$previous_user_id = get_current_user_id();
		if ( $acting_user_id > 0 ) {
			wp_set_current_user( $acting_user_id );
		}

		try {
			$result  = $dispatcher->dispatch( $action_type, $post_data );
			$payload = $result['payload'];

			// The cloud API depends on a site_health indicator in every dispatch response —
			// it confirms the plugin received and executed the request. Set here at the REST
			// boundary only (the pre-REST mobile router added it the same way), so the
			// wp-admin AJAX plane is unaffected; any value a controller already set is kept.
			if ( is_array( $payload ) && ! isset( $payload['site_health'] ) ) {
				$payload['site_health'] = true;
			}

			$status = ( is_array( $payload ) && isset( $payload['code'] ) && is_int( $payload['code'] ) )
				? $payload['code']
				: ( $result['success'] ? 200 : 400 );

			return new WP_REST_Response( $payload, $status );
		} finally {
			wp_set_current_user( $previous_user_id );
		}
	}

	/**
	 * Wrap an AuthController result array ({ data, message, code }) in a REST
	 * response, using its code as the HTTP status.
	 *
	 * @param array $result Result array from AuthController.
	 * @return WP_REST_Response
	 */
	private function to_response( $result ) {
		$status = ( is_array( $result ) && isset( $result['code'] ) && is_int( $result['code'] ) ) ? $result['code'] : 200;
		return new WP_REST_Response( $result, $status );
	}
}
