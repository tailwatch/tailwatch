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
	 * Authorization gate — re-verifies the caller's capability at the method level.
	 *
	 * The AjaxRequestController already enforces a nonce + `manage_options` before
	 * dispatch; this adds a defense-in-depth re-check for callers that reach the
	 * controller outside the router (Pro plugin direct calls, test harnesses).
	 *
	 * PASS THE CAPABILITY THE ACTION ACTUALLY NEEDS. The default is deliberately the
	 * broad one, because that is what every existing call site already meant, but
	 * `manage_options` is the wrong question for most sensitive actions:
	 *
	 * - On MULTISITE every subsite administrator holds `manage_options`. Anything that
	 *   affects the whole network — the shared tables, the file tree, the plugin's own
	 *   settings — must ask for `manage_network_options` instead, or one subsite's
	 *   administrator can act on every other site in the network.
	 * - Even on a single site, `manage_options` is not the capability WordPress uses to
	 *   decide who may delete a user or promote one. A role granted `manage_options`
	 *   without `delete_users` should not be able to delete users through this plugin
	 *   when it cannot through wp-admin.
	 *
	 * @since 1.0.0
	 * @since 1.0.4 Accepts the capability to check.
	 *
	 * @param string $capability Capability required by the calling action.
	 * @return bool True if the caller is authorized; false otherwise.
	 */
	private function is_authorized_request( $capability = 'manage_options' ) {
		$capability = is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';

		return current_user_can( $capability );
	}

	/**
	 * The capability that governs a network-wide act on this install.
	 *
	 * On a single site this is just `manage_options`; on multisite it is the network
	 * capability, which only a super administrator holds.
	 *
	 * @since 1.0.4
	 *
	 * @return string
	 */
	private function network_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
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