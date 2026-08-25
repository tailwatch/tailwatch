<?php
/**
 * Recovery Mode Service
 *
 * Generates the two recovery credentials used to reach a broken site:
 *
 *   1. A recovery-mode *link* via core's own recovery services (generate_url) — the
 *      single-use rm_token/rm_key URL that enters recovery mode when opened. Used
 *      when the plugin can still execute (e.g. an HTTP 500 caught at shutdown).
 *   2. A recovery-mode *cookie* {name, value} provisioned to the connected Tailwatch
 *      dashboard at license-connect, so the dashboard can enter recovery mode even
 *      when the site is too broken for the plugin to run at all.
 *
 * ## Authorization model
 *
 * The cookie generator produces a sensitive credential (a valid WordPress recovery
 * cookie). It is reachable only through two upstream-authorized entry points:
 *
 *   1. The wp-admin AJAX dispatcher — requires a valid nonce AND
 *      current_user_can('manage_options').
 *   2. The JWT-gated Connect route — requires a valid signed JWT.
 *
 * No current_user_can() check is duplicated inside the generator:
 *   - On the Connect (JWT) plane the call is a stateless server-to-server request with
 *     no WordPress user-cookie context, so an in-method current_user_can() would always
 *     fail; authorization is the JWT chain instead, which already requires a connected
 *     site and re-verifies the paired user's manage_options capability live per request.
 *   - On the AJAX plane the connect handshake can invoke this from the browser before
 *     the connected flag is persisted, so an in-method connected re-check would block
 *     the legitimate connect flow; the upstream nonce + manage_options gate is the control.
 * Authorization rests entirely on those upstream, scanner-visible gates.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\RecoveryMode
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\RecoveryMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Log;

class RecoveryModeService {

	/**
	 * Generate a WordPress recovery-mode URL via core's key/cookie/link services.
	 *
	 * Uses `WP_Recovery_Mode_Link_Service::generate_url()` — the sanctioned way to
	 * mint the one-time `rm_token`/`rm_key` recovery link. We do not hand-construct
	 * the recovery cookie or the token; core owns both, so the link stays
	 * format-correct across WordPress versions.
	 *
	 * @return string|false Recovery URL, or false on failure.
	 */
	public function generate_recovery_mode_link() {
		try {
			// Core recovery-mode classes live in wp-includes/ and are loaded during
			// bootstrap on single-site installs. Require them defensively — core skips
			// that initialization on multisite and when the fatal-error handler is off.
			if ( ! class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-cookie-service.php';
			}
			if ( ! class_exists( 'WP_Recovery_Mode_Key_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-key-service.php';
			}
			if ( ! class_exists( 'WP_Recovery_Mode_Link_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-link-service.php';
			}

			$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
			$key_service    = new \WP_Recovery_Mode_Key_Service();
			$link_service   = new \WP_Recovery_Mode_Link_Service( $cookie_service, $key_service );

			$recovery_url = $link_service->generate_url();

			return empty( $recovery_url ) ? false : $recovery_url;
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while generating recovery mode link: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_generate_link_failed',
					'detail'   => 'Exception occurred while generating recovery mode link.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return false;
		}
	}

	/**
	 * Provision a WordPress recovery-mode cookie {name, value} for the dashboard.
	 *
	 * WordPress core exposes no public method to produce a recovery-cookie value
	 * *without* also sending it as a Set-Cookie header on the current response
	 * (WP_Recovery_Mode_Cookie_Service::generate_cookie() is private and set_cookie()
	 * has that side effect). We therefore reproduce core's documented, stable-since-5.2
	 * cookie format — base64( "recovery_mode|{iat}|{rand}|{hmac_sha1}" ), signed with
	 * the same AUTH_KEY/AUTH_SALT-or-stored-fallback secret core uses — and then
	 * VERIFY the result through core's own public validate_cookie() before returning
	 * it, so any future format change surfaces here as an error instead of shipping a
	 * cookie core would reject. The cookie name comes from core's RECOVERY_MODE_COOKIE
	 * constant (no COOKIEHASH re-derivation).
	 *
	 * This writes no wp-config file; the secret uses core's own site-option fallback.
	 * See the class docblock for the authorization model.
	 *
	 * @return array{name?:string,value?:string,code:int,message:string}
	 */
	public function tailwatch_generate_recovery_cookie() {
		try {
			if ( ! class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-cookie-service.php';
			}

			$secret = $this->get_recovery_mode_secret();
			if ( '' === $secret ) {
				throw new \Exception( 'Missing recovery-mode authentication secret.' );
			}

			// Core's documented format (class-wp-recovery-mode-cookie-service.php::generate_cookie).
			$to_sign   = sprintf( 'recovery_mode|%s|%s', time(), wp_generate_password( 20, false ) );
			$signature = hash_hmac( 'sha1', $to_sign, $secret );
			// base64 here encodes the signed cookie payload (data, not code); this mirrors WP
			// core WP_Recovery_Mode_Cookie_Service::generate_cookie() exactly.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation -- Benign cookie-value encoding; identical to WP core's recovery cookie.
			$value = base64_encode( sprintf( '%s|%s', $to_sign, $signature ) );

			// Prove the value is one core will accept, using core's own public validator.
			$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
			if ( ! method_exists( $cookie_service, 'validate_cookie' ) || true !== $cookie_service->validate_cookie( $value ) ) {
				throw new \Exception( 'Generated recovery cookie failed core validation.' );
			}

			Log::info(
				'Successfully generated recovery mode cookie',
				array(
					'feature' => 'recovery_mode',
					'action'  => 'recovery_mode_generated_recovery_cookie',
					'origin'  => 'system',
				)
			);

			return array(
				'name'    => $this->get_recovery_mode_cookie_name(),
				'value'   => $value,
				'code'    => 200,
				'message' => __( 'Successfully generated recovery cookie.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while generating recovery cookie: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_generate_recovery_cookie_failed',
					'detail'   => 'Exception occurred while generating recovery cookie.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An error occurred while generating the recovery cookie.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Recovery-cookie name.
	 *
	 * Prefers core's RECOVERY_MODE_COOKIE constant (defined once cookie constants are
	 * set up during a normal request) and, only if it is somehow undefined, rebuilds
	 * it exactly as core does: 'wordpress_rec_' . COOKIEHASH.
	 *
	 * @return string
	 */
	private function get_recovery_mode_cookie_name() {
		if ( defined( 'RECOVERY_MODE_COOKIE' ) ) {
			return RECOVERY_MODE_COOKIE;
		}
		$hash = defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( (string) get_site_option( 'siteurl' ) );
		return 'wordpress_rec_' . $hash;
	}

	/**
	 * Derive the HMAC secret WordPress core uses to sign recovery cookies.
	 *
	 * Mirrors WP_Recovery_Mode_Cookie_Service::recovery_mode_hash() exactly: use the
	 * AUTH_KEY / AUTH_SALT constants when set to non-default values, otherwise fall
	 * back to the stored recovery_mode_auth_key / recovery_mode_auth_salt site options
	 * (generating and persisting them once, just as core does). No wp-config write is
	 * performed; this uses core's own site-option fallback so the derived secret equals
	 * whatever core would compute. The generated value is additionally re-validated
	 * against core in the caller, so any divergence fails closed.
	 *
	 * @return string The auth_key.auth_salt secret, or '' on failure.
	 */
	private function get_recovery_mode_secret() {
		// The wp-config-sample.php placeholder that flags an unconfigured salt. This is a
		// comparison sentinel (never displayed), so it is matched as a literal rather than
		// a translated string. On the rare localized-default install where core would match
		// its own translated sentinel and fall back to stored options, our English-literal
		// check may diverge — that case fails closed: the caller's self-validation against
		// core rejects any cookie core would not accept, so no forgeable value is emitted.
		$default_key = 'put your unique phrase here';

		if ( ! defined( 'AUTH_KEY' ) || AUTH_KEY === $default_key ) {
			$auth_key = get_site_option( 'recovery_mode_auth_key' );
			if ( ! $auth_key ) {
				if ( ! function_exists( 'wp_generate_password' ) ) {
					require_once ABSPATH . WPINC . '/pluggable.php';
				}
				$auth_key = wp_generate_password( 64, true, true );
				update_site_option( 'recovery_mode_auth_key', $auth_key );
			}
		} else {
			$auth_key = AUTH_KEY;
		}

		if ( ! defined( 'AUTH_SALT' ) || AUTH_SALT === $default_key || AUTH_SALT === $auth_key ) {
			$auth_salt = get_site_option( 'recovery_mode_auth_salt' );
			if ( ! $auth_salt ) {
				if ( ! function_exists( 'wp_generate_password' ) ) {
					require_once ABSPATH . WPINC . '/pluggable.php';
				}
				$auth_salt = wp_generate_password( 64, true, true );
				update_site_option( 'recovery_mode_auth_salt', $auth_salt );
			}
		} else {
			$auth_salt = AUTH_SALT;
		}

		if ( empty( $auth_key ) || empty( $auth_salt ) ) {
			return '';
		}

		return $auth_key . $auth_salt;
	}
}
