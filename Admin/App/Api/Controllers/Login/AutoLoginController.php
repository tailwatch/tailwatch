<?php
/**
 * Auto Login Controller
 *
 * Dispatch action that mints a one-click auto-login URL for the current
 * administrator. Reachable through the authenticated dispatch layer (browser
 * nonce+capability, or the Connect API running as the paired admin), so the
 * request is already an administrator by the time this runs.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Login
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Login;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Login\AutoLogin;

/**
 * Class AutoLoginController
 */
class AutoLoginController {

	/**
	 * Auto-login service.
	 *
	 * @var AutoLogin
	 */
	private $auto_login;

	/**
	 * Constructor. Side-effect free (registers no hooks) so the dispatch map can
	 * instantiate it freely; the login-endpoint handler is registered once at
	 * bootstrap via AutoLogin::register().
	 */
	public function __construct() {
		$this->auto_login = new AutoLogin();
	}

	/**
	 * Generate a one-shot, 1-hour auto-login URL for the current administrator.
	 *
	 * @return array Response with code, login_url, expires_at, or an error.
	 */
	public function tailwatch_login_into_dashboard() {
		try {
			// The dispatch layer already authenticated the request and set the acting
			// administrator, so mint only for the current user. Fail closed if the
			// current user is not an administrator — never mint a login link for a
			// different account than the authenticated caller.
			$user_id = get_current_user_id();
			if ( $user_id <= 0 || ! user_can( $user_id, 'administrator' ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'Auto-login is available to administrators only.', 'tailwatch' ),
				);
			}

			$expiry = time() + HOUR_IN_SECONDS;
			$token  = $this->auto_login->generate_auto_login_token( $user_id, $expiry );

			if ( ! $token ) {
				return array(
					'code'    => 500,
					'message' => __( 'Failed to generate auto login url', 'tailwatch' ),
				);
			}

			return array(
				'code'       => 200,
				'login_url'  => $this->auto_login->build_login_url( $token ),
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
