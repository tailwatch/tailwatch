<?php
/**
 * Tailwatch - Context Authorization Trait
 *
 * Provides an admin-capability authorization gate for controllers reachable
 * from the wp-admin AJAX router.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Traits
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Traits;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait ContextAuthorizationTrait {

	/**
	 * Authorization gate — re-verifies the admin capability at the method level.
	 *
	 * The AjaxRequestController already enforces a nonce + `manage_options` before
	 * dispatch; this adds a defense-in-depth re-check for callers that reach the
	 * controller outside the router (Pro plugin direct calls, test harnesses).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the caller is authorized; false otherwise.
	 */
	private function is_authorized_request() {
		return current_user_can( 'manage_options' );
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