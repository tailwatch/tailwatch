<?php

namespace Tailwatch\Admin\App\Api\Controllers\RecoveryMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Login\AutoLogin;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Recovery mode credential generation.
 *
 * ## Authorization model
 *
 * Methods in this class generate sensitive recovery credentials (the WordPress
 * recovery cookie and the recovery-mode URL bundled with an auto-login token).
 * They are reachable through two distinct entry points, each with its own
 * upstream authorization gate:
 *
 *   1. The wp-admin AJAX router (AjaxRequestController) — requires a valid
 *      `wp_ajax_nonce` AND `current_user_can('manage_options')`.
 *   2. The JWT-gated mobile route (MobileAppController) — requires a valid
 *      JWT signed with the site's secret key, with JTI not revoked, and
 *      matching device fingerprint.
 *
 * No `current_user_can()` check is performed inside these methods because the
 * JWT path has no WordPress user-cookie context (it is a stateless
 * server-to-server call). Adding `current_user_can()` here would break the
 * mobile dashboard flow.
 *
 * ### Why there is no `extended_connected` defense-in-depth re-check
 *
 * Unlike most other JWT-gated controllers, these recovery-credential generators
 * are invoked DURING the license-connect handshake itself (the dashboard
 * provisions a recovery cookie + URL as part of pairing a site). At that point
 * the `extended_connected` flag is not yet persisted on this side, so a
 * defensive re-check would block the legitimate connect flow. We rely entirely
 * on the upstream JWT signature + JTI + device fingerprint chain (or the AJAX
 * nonce + capability chain) for authorization, and we do not duplicate the
 * license-connected guard here. If a future code change separates recovery
 * provisioning from license-connect, reintroducing the trait-based gate
 * (ContextAuthorizationTrait::is_authorized_request) would be appropriate.
 */
class RecoveryModeService {

	public function wptw_generate_recovery_cookie() {
		try {
			// Validate and sanitize inputs
			$default_keys = array(
				'put your unique phrase here',
				__( 'put your unique phrase here', 'tailwatch' ),
			);

			if ( ! defined( 'AUTH_KEY' ) || in_array( AUTH_KEY, $default_keys, true ) ) {
				$auth_key = get_site_option( 'recovery_mode_auth_key' );
				if ( ! $auth_key ) {
					if ( ! function_exists( 'wp_generate_password' ) ) {
						require_once ABSPATH . WPINC . '/pluggable.php';
					}
					$auth_key      = wp_generate_password( 64, true, true );
					$update_result = update_site_option( 'recovery_mode_auth_key', $auth_key );
					if ( ! $update_result ) {
						Log::error(
							'Failed to store generated recovery mode auth key',
							array(
								'feature'  => 'recovery_mode',
								'action'   => 'recovery_mode_generate_recovery_cookie_failed',
								'detail'   => 'Failed to store generated recovery mode auth key',
								'origin'   => 'system',
								'severity' => 'high',
							)
						);
						throw new \Exception( 'Failed to store recovery mode auth key' );
					}
				}
			} else {
				$auth_key = AUTH_KEY;
			}

			// Generate or retrieve auth salt
			if ( ! defined( 'AUTH_SALT' ) || in_array( AUTH_SALT, $default_keys, true ) || AUTH_SALT === $auth_key ) {
				$auth_salt = get_site_option( 'recovery_mode_auth_salt' );
				if ( ! $auth_salt ) {
					if ( ! function_exists( 'wp_generate_password' ) ) {
						require_once ABSPATH . WPINC . '/pluggable.php';
					}
					$auth_salt     = wp_generate_password( 64, true, true );
					$update_result = update_site_option( 'recovery_mode_auth_salt', $auth_salt );
					if ( ! $update_result ) {
						Log::error(
							'Failed to store generated recovery mode auth salt',
							array(
								'feature'  => 'recovery_mode',
								'action'   => 'recovery_mode_generate_recovery_cookie_failed',
								'detail'   => 'Failed to store generated recovery mode auth salt',
								'origin'   => 'system',
								'severity' => 'high',
							)
						);
						throw new \Exception( 'Failed to store recovery mode auth salt' );
					}
				}
			} else {
				$auth_salt = AUTH_SALT;
			}

			// Validate that we have both auth key and salt
			if ( empty( $auth_key ) || empty( $auth_salt ) ) {
				Log::error(
					'Auth key or salt is empty for recovery cookie generation',
					array(
						'feature'  => 'recovery_mode',
						'action'   => 'recovery_mode_generate_recovery_cookie_failed',
						'detail'   => 'Auth key or salt is empty for recovery cookie generation',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				throw new \Exception( 'Missing authentication data for recovery cookie' );
			}

			// Generate cookie value
			$secret       = $auth_key . $auth_salt;
			$to_sign      = sprintf( 'recovery_mode|%s|%s', time(), wp_generate_password( 20, false ) );
			$signature    = hash_hmac( 'sha1', $to_sign, $secret );
			$cookie_value = sprintf( '%s|%s', $to_sign, $signature );

			$siteurl = get_site_option( 'siteurl' );
			if ( ! $siteurl ) {
				Log::error(
					'Site URL is not available for recovery cookie generation',
					array(
						'feature'  => 'recovery_mode',
						'action'   => 'recovery_mode_generate_recovery_cookie_failed',
						'detail'   => 'Site URL is not available for recovery cookie generation',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				throw new \Exception( 'Site URL not found' );
			}

			$cookie_name   = 'wordpress_rec_' . md5( $siteurl );
			$encoded_value = base64_encode( $cookie_value );

			Log::info(
				"Successfully generated recovery mode cookie: {$cookie_name}",
				array(
					'feature' => 'recovery_mode',
					'action'  => 'recovery_mode_generated_recovery_cookie',
					'origin'  => 'system',
				)
			);
			return array(
				'name'    => $cookie_name,
				'value'   => $encoded_value,
				'code'    => 200,
				'message' => __( 'Successfully generated recovery cookie.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while generating recovery cookie: ' . $e->getMessage(),
				array(
					'feature'  => 'recovery_mode',
					'action'   => 'recovery_mode_generate_recovery_cookie_failed',
					'detail'   => 'Exception occurred while generating recovery cookie: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An error occurred.', 'tailwatch' ),
			);
		}
	}

	public function generate_recovery_mode_link() {
		try {
			// Authorization is enforced by the upstream JWT or AJAX gates. We do not
			// re-check `extended_connected` here because this method is invoked
			// during the license-connect handshake itself, before that flag is set.
			// See the class-level docblock for the full rationale.

			// WP core recovery mode classes live in wp-includes/.
			if ( ! class_exists( 'WP_Recovery_Mode_Link_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-link-service.php';
			}
			if ( ! class_exists( 'WP_Recovery_Mode_Cookie_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-cookie-service.php';
			}
			if ( ! class_exists( 'WP_Recovery_Mode_Key_Service' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-recovery-mode-key-service.php';
			}

			$cookie_service = new \WP_Recovery_Mode_Cookie_Service();
			$key_service    = new \WP_Recovery_Mode_Key_Service();
			$link_service   = new \WP_Recovery_Mode_Link_Service( $cookie_service, $key_service );

			$recovery_url = $link_service->generate_url();

			if ( empty( $recovery_url ) ) {
				return false;
			}

			$redirect_link = admin_url( 'index.php?recovery_mode_login=1' );

			$auto_login_service = new AutoLogin();
			$auto_login_token   = $auto_login_service->generate_auto_login_token( 1, null, $redirect_link );

			if ( $auto_login_token ) {
				$recovery_url = add_query_arg( 'wptw_auto_login', $auto_login_token, $recovery_url );
			} else {
				Log::error(
					'Auto-login token generation failed; returning recovery URL without auto-login',
					array(
						'feature'  => 'recovery_mode',
						'action'   => 'recovery_mode_auto_login_token_failed',
						'detail'   => 'Recovery URL is still valid but user will need to log in manually',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
			}

			return $recovery_url;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
