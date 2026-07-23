<?php
/**
 * Login Defender Authentication Controller
 *
 * Orchestrates IP-based login protection during the authenticate hook.
 *
 * @package Tailwatch\Admin\App\Api\Controllers\LoginDefender
 */

namespace Tailwatch\Admin\App\Api\Controllers\LoginDefender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections\IpProtectionController;

/**
 * Authentication Controller
 */
class AuthenticationController {

	private $ip_protection;

	public function __construct() {
		$this->ip_protection = new IpProtectionController();

		$hook_controller = new HookControllers();
		// Priority 25: AFTER WP's password check (@20) so the IP isn't resolved
		// for nothing on bad requests, but BEFORE the 2FA gate (@30). 2FA renders
		// its form and exit()s on the challenge step, which would short-circuit a
		// block check sharing @30 — a blocked IP would then see the 2FA form and
		// only be rejected after submitting the code. Running first guarantees a
		// blocked IP is stopped at the password step with a clear message.
		$hook_controller->add_filter_hook( 'authenticate', array( $this, 'wptw_handle_authentication' ), 25, 3 );
		$hook_controller->add_action_hook( 'wp_login_failed', array( $this, 'wptw_handle_failed_login' ), 10, 2 );
		$hook_controller->add_action_hook( 'wp_login', array( $this, 'wptw_handle_successful_login' ), 10, 2 );
		$hook_controller->add_action_hook( 'admin_init', array( $this, 'wptw_check_access' ), 10 );
		$hook_controller->add_filter_hook( 'login_errors', array( $this, 'wptw_custom_login_error_message' ), 10 );
	}

	public function wptw_handle_authentication( $user, $username, $password ) {
		// Enforce the existing block. The IP-blocked push is NOT fired here — this
		// runs on every attempt from an already-blocked IP and would spam. The push
		// is fired once at block creation in IpProtectionController::handle_failed_login.
		return $this->ip_protection->handle_authentication( $user, $username, $password );
	}

	public function wptw_handle_failed_login( $username, $error ) {
		$this->ip_protection->handle_failed_login( $username, $error );
	}

	public function wptw_handle_successful_login( $user_login, $user ) {
		$this->ip_protection->handle_successful_login( $user_login, $user );
	}

	public function wptw_check_access() {
		$this->ip_protection->check_access();
	}

	public function wptw_custom_login_error_message( $error ) {
		return $this->ip_protection->custom_login_error_message( $error );
	}
}
