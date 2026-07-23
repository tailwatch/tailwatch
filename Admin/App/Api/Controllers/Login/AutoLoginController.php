<?php
namespace Tailwatch\Admin\App\Api\Controllers\Login;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Login\AutoLogin;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;

/**
 * Class AutoLoginController
 *
 * Handles auto-login functionality, including token generation and hook registration.
 *
 */
class AutoLoginController {

	/**
	 * Auto Login Service instance
	 *
	 * @var AutoLogin
	 */
	private $auto_login;

	/**
	 * Constructor
	 *
	 * Initialize the controller and register hooks.
	 *
	 */
	public function __construct() {
		$this->auto_login = new AutoLogin();
		$hook_controller  = new HookControllers();
		$hook_controller->add_action_hook( 'init', array( $this->auto_login, 'wptw_handle_auto_login_request' ) );
	}

	/**
	 * Generate an auto-login URL.
	 *
	 * Creates a one-shot, 1-hour-expiry token that logs the site's primary administrator
	 * into wp-admin when the resulting URL is opened.
	 *
	 * ## Authorization model
	 *
	 * This method is reachable only via the JWT-gated `/v1` endpoint
	 * (see Routes.php — registered in the `Auth` group). Before this method ever
	 * runs, Routing.php has already verified:
	 *
	 *   1. The JWT signature is valid.
	 *   2. The license is connected (`extended_connected` flag).
	 *   3. The JTI has not been revoked.
	 *   4. The device fingerprint matches the one bound at token issuance.
	 *
	 * The JWT itself does not carry a WordPress user ID — it is a license/device
	 * token issued during the mobile-app pairing flow that an administrator
	 * initiates from wp-admin. The holder of a valid JWT is, by construction,
	 * the administrator who paired the device, so we mint a token for the
	 * primary admin account.
	 *
	 * No `current_user_can()` check is performed because this is a stateless
	 * server-to-server API call with no WordPress user-cookie context — the
	 * JWT validation upstream IS the authorization layer. A defensive
	 * license-connected re-check is performed below as defense in depth in
	 * case future code ever calls this method outside the JWT-gated route.
	 *
	 * @since 1.0.0
	 *
	 * @return array Response array containing status code, login URL, expiry, or error message.
	 */
	public function wptw_login_into_dashboard() {
		try {
			// Defense in depth: refuse to mint a token unless the license is currently
			// connected. JwtService::validate_jwt() also checks this, but re-checking
			// here protects the method if it is ever called outside the JWT-gated route.
			$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
			if ( ! is_array( $activation ) || empty( $activation['extended_connected'] ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'License is not connected. Auto-login is unavailable.', 'tailwatch' ),
				);
			}

			$limit  = 1;
			$expiry = time() + 3600;

			$auto_login_service = new AutoLogin();
			$auto_login_token   = $auto_login_service->generate_auto_login_token( $limit, $expiry );

			if ( ! $auto_login_token ) {

				return array(
					'code'    => 500,
					'message' => __( 'Failed to generate auto login url', 'tailwatch' ),
				);
			}

			$login_url = add_query_arg( 'wptw_auto_login', $auto_login_token, wp_login_url() );

			return array(
				'code'       => 200,
				'login_url'  => $login_url,
				'expires_at' => $expiry,
				'message'    => __( 'Successfully generated auto login url.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {

			return array(
				'code'    => 500,
				'message' => __( 'Failed to generate auto login url due to an unexpected error.', 'tailwatch' ),
			);
		}
	}
}
