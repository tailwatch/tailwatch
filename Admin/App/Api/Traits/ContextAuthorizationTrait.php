<?php
/**
 * Tailwatch - Context Authorization Trait
 *
 * Provides a context-aware authorization gate for controllers that are
 * reachable from BOTH the wp-admin AJAX router (which has WP user-cookie
 * context) AND the JWT-gated mobile route (which is stateless and has no
 * WP user-cookie context).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Traits
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Traits;

use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait ContextAuthorizationTrait {

	/**
	 * Context-aware authorization gate.
	 *
	 * Upstream gates (AjaxRequestController nonce+cap, MobileAppController JWT)
	 * already authorize requests before reaching the controller. This helper
	 * adds a defense-in-depth re-check at the method level for cases where a
	 * caller reaches the controller outside the routers (Pro plugin direct
	 * calls, future code paths, test harnesses).
	 *
	 * The check is context-aware because the JWT path has no WordPress
	 * user-cookie context, so `current_user_can()` would always return false
	 * there and break the mobile flow. Instead:
	 *
	 *   - AJAX path (get_current_user_id() > 0): re-verify `manage_options`.
	 *   - JWT path (stateless): verify the license is still connected.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the caller is authorized; false otherwise.
	 */
	private function is_authorized_request() {
		if ( get_current_user_id() > 0 ) {
			return current_user_can( 'manage_options' );
		}
		$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
		return is_array( $activation ) && ! empty( $activation['extended_connected'] );
	}

	/**
	 * Standard 403 response shape for unauthorized requests.
	 *
	 * Matches the response shape used elsewhere in the user-management
	 * controllers so callers can rely on a consistent contract.
	 *
	 * @return array
	 */
	private function unauthorized_response() {
		return array(
			'success' => false,
			'code'    => 403,
			'message' => __( 'You do not have permission to perform this action.', 'tailwatch' ),
		);
	}
}