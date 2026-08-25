<?php
/**
 * AJAX Request Controller
 *
 * wp-admin AJAX front-door for the plugin's action API. Enforces the browser
 * authentication plane (nonce + capability) and then delegates execution to the
 * shared ActionDispatcher, which owns the action map, payload sanitizer, and
 * execution pipeline. The Connect REST API is the second front-door to that same
 * dispatcher, authenticating with a JWT instead of a nonce.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Routes
 */

namespace Tailwatch\Admin\App\Api\Controllers\Routes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;

/**
 * Class AjaxRequestController
 *
 * ## Single chokepoint for all plugin AJAX requests
 *
 * Every AJAX-driven feature in the Tailwatch plugin is dispatched through this one
 * class. The constructor registers a single WordPress AJAX hook
 * (`wp_ajax_tailwatch_global_ajax_handler`) — there are NO `wp_ajax_nopriv_*`
 * registrations anywhere in the plugin, so anonymous AJAX is impossible by
 * construction.
 *
 * ## Centralized nonce + capability gate
 *
 * The single entry point `tailwatch_global_ajax_handler()` enforces — BEFORE any
 * downstream controller method is invoked — the following gate:
 *
 *   1. wp_verify_nonce( $_POST['nonce'], 'tailwatch_ajax_nonce' ). Reject the request
 *      with a JSON error if the nonce is missing or invalid. The nonce is
 *      minted server-side via wp_create_nonce('tailwatch_ajax_nonce') and emitted to
 *      the admin UI via wp_localize_script() (see InterfaceController).
 *      Anonymous, cross-origin, and replayed requests fail at this gate.
 *
 *   2. current_user_can( 'manage_options' ). Reject with a JSON error if the
 *      current user is not an administrator.
 *
 *   3. Only AFTER both gates pass does the handler read $_POST['action_type'] and
 *      the payload, sanitize the payload, and hand off to ActionDispatcher. Every
 *      downstream controller therefore inherits "admin + valid nonce" as a
 *      precondition on this front-door.
 *
 * ## Why downstream controllers do not repeat the checks
 *
 * The individual controller methods do NOT contain inline
 * wp_verify_nonce() / current_user_can() calls — the protection is enforced
 * once, upstream, on each front-door (nonce+cap here, JWT on the Connect route).
 */
class AjaxRequestController {

	/**
	 * Constructor - Register AJAX handler.
	 */
	public function __construct() {
		$hook_controllers = new HookControllers();
		$hook_controllers->add_action_hook( 'wp_ajax_tailwatch_global_ajax_handler', array( $this, 'tailwatch_global_ajax_handler' ) );
		// Intentionally NOT registering wp_ajax_nopriv_* — every plugin route requires an authenticated admin session.
	}

	/**
	 * Global AJAX handler for all plugin AJAX requests.
	 *
	 * Verifies nonce, checks capabilities, then delegates to ActionDispatcher and
	 * formats its neutral result as a JSON success/error response.
	 *
	 * @return void
	 */
	public function tailwatch_global_ajax_handler() {
		try {
			// Verify nonce for security.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in next line
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'tailwatch_ajax_nonce' ) ) {
				wp_send_json_error( __( 'Invalid nonce', 'tailwatch' ) );
				return;
			}

			// Ensure user has permission to access the plugin.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions', 'tailwatch' ) );
				return;
			}

			// Check for required parameters.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
			if ( ! isset( $_POST['action_type'] ) ) {
				wp_send_json_error( __( 'Missing required parameters', 'tailwatch' ) );
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
			$action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );

			$dispatcher = new ActionDispatcher();

			// $_POST['data'] is a JSON payload. Following the WordPress "sanitizing array input
			// data" guidance, structured input is sanitized element-by-element after decoding —
			// not by blanket-sanitizing the raw JSON string, which would corrupt URLs, credentials
			// and percent-encoded values. ActionDispatcher::sanitize_request_payload() decodes the
			// payload, sanitizes every value with the function that matches its type, and re-encodes
			// it, so each controller receives already-sanitized data. Controllers then re-sanitize
			// per field as a second layer.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified earlier in this method; $_POST['data'] is unslashed and immediately passed to ActionDispatcher::sanitize_request_payload(), which decodes the JSON and sanitizes every value element-by-element with a context-appropriate function. A JSON string cannot be blanket-sanitized inline (e.g. sanitize_text_field) without corrupting URLs, credentials and percent-encoded values, so a custom sanitizer is used here; PHPCS cannot recognise it, hence this annotation.
			$post_data = $dispatcher->sanitize_request_payload( isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : null );

			$result = $dispatcher->dispatch( $action_type, $post_data );

			if ( $result['success'] ) {
				wp_send_json_success( $result['payload'] );
			} else {
				wp_send_json_error( $result['payload'] );
			}
		} catch ( \Exception $e ) {

			wp_send_json_error(
				array(
					'code'    => 500,
					'message' => __( 'Internal server error occurred while processing the request', 'tailwatch' ),
				)
			);
		}
	}
}
