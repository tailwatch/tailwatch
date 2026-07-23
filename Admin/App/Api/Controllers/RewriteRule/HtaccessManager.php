<?php

namespace Tailwatch\Admin\App\Api\Controllers\RewriteRule;

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\Auth\JwtService;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Vendor\Firebase\JWT\JWT;
use Tailwatch\Vendor\Firebase\JWT\Key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HtaccessManager {

	private $api_slug = WPTW_API_SLUG;
	private $htaccess_marker_start;
	private $htaccess_marker_end;

	public function __construct() {
		$hook_controller = new HookControllers();

		// Set markers for htaccess rules
		$this->htaccess_marker_start = '# BEGIN ' . strtoupper( $this->api_slug ) . ' API';
		$this->htaccess_marker_end   = '# END ' . strtoupper( $this->api_slug ) . ' API';

		// Check and create htaccess on init with high priority
		$hook_controller->add_action_hook( 'init', array( $this, 'check_and_create_htaccess' ), 5 );

		// Update htaccess when permalinks change
		$hook_controller->add_action_hook( 'permalink_structure_changed', array( $this, 'check_and_create_htaccess' ), 10 );

		// Add permalink notice for admin
		$hook_controller->add_action_hook( 'admin_init', array( $this, 'add_permalink_notice' ) );

		// Add rewrite rules directly to WordPress
		$hook_controller->add_action_hook( 'init', array( $this, 'add_rewrite_rules' ), 10 );

		// Add our query var filter for all permalink structures
		$hook_controller->add_filter_hook( 'query_vars', array( $this, 'add_query_vars' ) );

		// Fallback handler for all requests
		$hook_controller->add_action_hook( 'parse_request', array( $this, 'handle_api_request' ), 5 );

		// Handle htaccess rule regeneration requests
		$hook_controller->add_action_hook( 'init', array( $this, 'handle_htaccess_regeneration_request' ), 1 );

		// Prevent redirects for our API requests
		$hook_controller->add_filter_hook( 'redirect_canonical', array( $this, 'prevent_api_redirect' ), 10, 2 );

		// Safety net: ensure WordPress permalink rules survive plugin .htaccess writes.
		// Runs after all init hooks (including prepend_htaccess_rules calls) have completed.
		$hook_controller->add_action_hook( 'wp_loaded', array( $this, 'ensure_wordpress_rewrite_rules' ), 99 );
	}

	/**
	 * Handle .htaccess rule regeneration requests (server-to-server recovery).
	 *
	 * This endpoint exists because Tailwatch's API rewrite rules live in .htaccess.
	 * If those rules are wiped (theme update, conflicting plugin, manual deletion),
	 * the entire /wptw-api/ surface goes dark and the JWT-authenticated mobile and
	 * dashboard endpoints can no longer be reached to fix the problem from inside.
	 * This handler is registered on the init hook rather than the API routing layer
	 * so it is reachable even when .htaccess is broken.
	 *
	 * ## Why this endpoint does not use wp_verify_nonce()
	 *
	 * The caller is the Tailwatch dashboard's server — a remote, unauthenticated
	 * (in WordPress terms) machine-to-machine client. There is no logged-in user
	 * session to bind a wp_nonce to, so the standard wp_verify_nonce() pattern
	 * is structurally inapplicable. wp_nonce is a per-user CSRF token; this is a
	 * server-to-server authenticated channel where the equivalent control is a
	 * signed bearer token validated against the site's secret key.
	 *
	 * ## Authorization
	 *
	 * Because this is a public-internet-facing endpoint that writes to .htaccess, it
	 * requires a cryptographically validated recovery token sent in the standard
	 * HTTP Authorization header as `Authorization: Bearer <jwt>` (RFC 6750).
	 * Header-based auth is preferred over query/POST so the JWT never lands in
	 * web-server access logs, proxy logs, or browser referrers. The token is a
	 * JWT issued at license-pairing time and signed with the site's secret key
	 * (WPTW_JWT_AUTH_SECRET_KEY). On every recovery request we verify:
	 *
	 *   1. The JWT signature (HS256) against the site's secret key.
	 *   2. The token has not expired.
	 *   3. The license is currently connected (extended_connected).
	 *   4. The token's JTI has not been revoked.
	 *
	 * We intentionally DO NOT re-check the device fingerprint here. Recovery
	 * requests originate from the dashboard server, whose IP and user-agent differ
	 * from the originally paired mobile device. The four checks above together
	 * make forgery computationally infeasible without the site's secret key.
	 *
	 * The domain check below remains as a secondary sanity gate so a recovery URL
	 * meant for site A cannot be accidentally fired at site B.
	 */
	public function handle_htaccess_regeneration_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth via signed recovery token validated below, not a WP nonce.
		if ( ! isset( $_GET['wptw-generate-rule'] ) || ! isset( $_GET['wptw-domain-name'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth via signed recovery token validated below, not a WP nonce.
		$generate_rule = sanitize_text_field( wp_unslash( $_GET['wptw-generate-rule'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Auth via signed recovery token validated below, not a WP nonce.
		$domain_name = sanitize_text_field( wp_unslash( $_GET['wptw-domain-name'] ) );

		if ( $generate_rule !== 'true' ) {
			return;
		}

		// Recovery token arrives in Authorization: Bearer <jwt> header. Missing or
		// non-Bearer header => silent early return so this endpoint is invisible to
		// opportunistic scanners (no 401, no fingerprint). REDIRECT_HTTP_AUTHORIZATION
		// fallback covers Apache + mod_rewrite, which strips the Authorization
		// header unless the .htaccess explicitly re-passes it via
		// `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`.
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( '' === $auth_header || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return;
		}
		$raw_token = substr( $auth_header, 7 );
		if ( '' === $raw_token ) {
			return;
		}

		if ( ! $this->validate_recovery_token( $raw_token ) ) {
			$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			Log::warning(
				'Rejected .htaccess recovery request: invalid or expired recovery token.',
				array(
					'feature'  => 'htaccess_recovery',
					'action'   => 'recovery_token_rejected',
					'origin'   => 'system',
					'severity' => 'medium',
					'ip'       => $client_ip,
				)
			);
			$this->send_response( false, 'Unauthorized recovery request.', 401 );
			return;
		}

		if ( ! $this->validate_domain_name( $domain_name ) ) {
			$this->send_response( false, 'Invalid domain name provided.', 400 );
			return;
		}

		if ( ! $this->htaccess_rules_exist() ) {
			$result = $this->force_regenerate_htaccess();
			if ( $result ) {
				$this->send_response( true, 'Htaccess rules have been regenerated successfully.', 200 );
			} else {
				$this->send_response( false, 'Failed to regenerate htaccess rules. Check file permissions.', 500 );
			}
		} else {
			$this->send_response( true, 'Htaccess rules already exist and are working properly.', 200 );
		}
	}

	/**
	 * Validate a recovery token: signature, expiry, license-connected, JTI revocation.
	 *
	 * Differs from JwtService::validate_jwt() in that the device fingerprint check is
	 * intentionally skipped. Recovery requests come from the dashboard server, not
	 * the paired mobile device, so the fingerprint will not match by design.
	 *
	 * @param string $token Raw JWT string.
	 * @return bool True if the token is a valid recovery credential, false otherwise.
	 */
	private function validate_recovery_token( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		if ( ! defined( 'WPTW_JWT_AUTH_SECRET_KEY' ) || '' === WPTW_JWT_AUTH_SECRET_KEY ) {
			return false;
		}
		try {
			$decoded = JWT::decode( $token, new Key( WPTW_JWT_AUTH_SECRET_KEY, 'HS256' ) );
		} catch ( \Throwable $e ) {
			return false;
		}

		// Defensive expiry re-check (Firebase JWT also checks this, but be explicit).
		if ( ! isset( $decoded->exp ) || $decoded->exp < time() ) {
			return false;
		}

		// License must still be connected.
		$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
		if ( ! is_array( $activation ) || empty( $activation['extended_connected'] ) ) {
			return false;
		}

		// JTI must not be revoked.
		$jti = isset( $decoded->jti ) ? $decoded->jti : '';
		if ( ! JwtService::is_valid_jti_format( $jti ) ) {
			return false;
		}
		if ( get_option( 'wptw_token_revoked_' . $jti ) ) {
			return false;
		}

		return true;
	}
	/**
	 * Send JSON response and terminate
	 */
	private function send_response( $success, $message, $status_code = 200 ) {
		$response = array(
			'success'   => $success,
			'message'   => $message,
			'timestamp' => current_time( 'mysql' ),
		);

		status_header( $status_code );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $response );
		exit;
	}

	/**
	 * Validate domain name against current site domain
	 */
	private function validate_domain_name( $provided_domain ) {
		if ( empty( $provided_domain ) ) {
			return false;
		}

		$home_url = home_url();
		if ( empty( $home_url ) ) {
			return false;
		}

		$current_domain = wp_parse_url( $home_url, PHP_URL_HOST );
		if ( empty( $current_domain ) ) {
			return false;
		}

		return $provided_domain === $current_domain;
	}

	/**
	 * Check if htaccess rules exist and are properly configured
	 */
	private function htaccess_rules_exist() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home_path     = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$htaccess_file = $home_path . '.htaccess';

		if ( ! file_exists( $htaccess_file ) ) {
			return false;
		}

		$fs      = FilesystemService::get_filesystem();
		$content = $fs ? $fs->get_contents( $htaccess_file ) : false;
		if ( $content === false ) {
			return false;
		}
		return $this->has_api_rewrite_rules( $content );
	}

	/**
	 * Force regenerate htaccess rules
	 *
	 * @return bool True if successful, false if failed
	 */
	private function force_regenerate_htaccess() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home_path     = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$htaccess_file = $home_path . '.htaccess';

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( file_exists( $htaccess_file ) && ! $wp_filesystem->is_writable( $htaccess_file ) ) {
			return false;
		}

		if ( ! file_exists( $htaccess_file ) && ! $wp_filesystem->is_writable( $home_path ) ) {
			return false;
		}

		try {
			$this->insert_with_markers( $htaccess_file );
			return $this->verify_htaccess_rules( $htaccess_file );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check if .htaccess exists and add our rules if needed
	 */
	public function check_and_create_htaccess() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home_path     = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$htaccess_file = $home_path . '.htaccess';

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ( ! file_exists( $htaccess_file ) && $wp_filesystem->is_writable( $home_path ) ) || ( file_exists( $htaccess_file ) && $wp_filesystem->is_writable( $htaccess_file ) ) ) {
			if ( ! $this->htaccess_rules_exist() ) {
				$this->insert_with_markers( $htaccess_file );
			}
		} else {
			$this->ensure_rewrite_rules_active();
		}
	}

	/**
	 * Insert htaccess rules using WordPress insert_with_markers if available
	 */
	private function insert_with_markers( $htaccess_file ) {
		if ( file_exists( $htaccess_file ) ) {
			$fs      = FilesystemService::get_filesystem();
			$content = $fs ? $fs->get_contents( $htaccess_file ) : false;
			if ( $content !== false && $this->has_api_rewrite_rules( $content ) ) {
				$this->remove_existing_rules( $htaccess_file );
			}
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( function_exists( 'insert_with_markers' ) ) {
			$rules  = $this->get_htaccess_rules_array();
			$result = insert_with_markers( $htaccess_file, strtoupper( $this->api_slug ) . ' API', $rules );
			if ( ! $result ) {
				return $this->manually_update_htaccess( $htaccess_file );
			}
			return true;
		} else {
			return $this->manually_update_htaccess( $htaccess_file );
		}
	}

	/**
	 * Manually update htaccess if WordPress function is not available
	 */
	private function manually_update_htaccess( $htaccess_file ) {
		$fs               = FilesystemService::get_filesystem();
		if ( ! $fs ) {
			return false;
		}

		$htaccess_content = '';

		if ( file_exists( $htaccess_file ) ) {
			$htaccess_content = $fs->get_contents( $htaccess_file );
			if ( $htaccess_content === false ) {
				$htaccess_content = '';
			}
		}

		if ( ! $this->has_api_rewrite_rules( $htaccess_content ) ) {
			$api_rules = $this->get_api_rewrite_rules();

			if ( empty( $htaccess_content ) ) {
				$htaccess_content = $api_rules;
			} else {
				$this->remove_existing_rules_from_content( $htaccess_content );

				if (
					strpos( $htaccess_content, '# BEGIN WordPress' ) !== false &&
					strpos( $htaccess_content, '# END WordPress' ) !== false
				) {
					$htaccess_content = preg_replace(
						'/# END WordPress/',
						"# END WordPress\n\n" . $api_rules,
						$htaccess_content,
						1
					);
				} else {
					$htaccess_content = rtrim( $htaccess_content ) . "\n\n" . $api_rules;
				}
			}

			if ( ! $fs->is_writable( $htaccess_file ) && ! $fs->is_writable( dirname( $htaccess_file ) ) ) {
				return false;
			}

			$result = $fs->put_contents( $htaccess_file, str_replace( "\n", PHP_EOL, $htaccess_content ), FS_CHMOD_FILE );
			if ( $result === false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Add rewrite rules directly to WordPress
	 */
	public function add_rewrite_rules() {
		// Add rewrite rule for single endpoint
		add_rewrite_rule(
			'^' . $this->api_slug . '/([^/]+)/?$',
			'index.php?' . $this->api_slug . '=$matches[1]',
			'top'
		);

		// Handle paths with subfolders for multi-level API routes
		add_rewrite_rule(
			'^' . $this->api_slug . '/([^/]+)/([^/]+)/?$',
			'index.php?' . $this->api_slug . '=$matches[1]&action=$matches[2]',
			'top'
		);

		// Handle hyphenated endpoints (e.g., connect-google)
		add_rewrite_rule(
			'^' . $this->api_slug . '/([^-]+)-([^/]+)/?$',
			'index.php?' . $this->api_slug . '=$matches[1]-$matches[2]',
			'top'
		);

		// Plain permalink fallback
		add_rewrite_rule(
			'index\.php\?' . $this->api_slug . '=([^&]+)/?$',
			'index.php?' . $this->api_slug . '=$matches[1]',
			'top'
		);

		// Multisite subdirectory support
		if ( is_multisite() ) {
			add_rewrite_rule(
				'^([a-z0-9-]+/)?' . $this->api_slug . '/([^/]+)/?$',
				'index.php?' . $this->api_slug . '=$matches[2]',
				'top'
			);
			add_rewrite_rule(
				'^([a-z0-9-]+/)?' . $this->api_slug . '/([^-]+)-([^/]+)/?$',
				'index.php?' . $this->api_slug . '=$matches[2]-$matches[3]',
				'top'
			);
		}
	}

	/**
	 * Get the site-relative base path (for RewriteBase)
	 */
	private function get_site_relative_base() {
		$home_url_parsed = wp_parse_url( home_url() );
		if ( $home_url_parsed === false ) {
			return '/';
		}
		return isset( $home_url_parsed['path'] ) && $home_url_parsed['path'] !== '/' ? trailingslashit( $home_url_parsed['path'] ) : '/';
	}

	/**
	 * Flush rewrite rules on activation
	 */
	public function flush_rewrite_rules() {
		$this->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Ensure WordPress rewrite rules include our API endpoint
	 */
	private function ensure_rewrite_rules_active() {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$rule_pattern = '^' . $this->api_slug . '/([^/]+)/?$';

		if ( ! isset( $rules[ $rule_pattern ] ) ) {
			$this->add_rewrite_rules();
			flush_rewrite_rules();
		}
	}

	/**
	 * Check if htaccess content already has our API rewrite rules
	 */
	private function has_api_rewrite_rules( $content ) {
		return strpos( $content, $this->htaccess_marker_start ) !== false;
	}

	/**
	 * Remove existing API rules from htaccess content
	 */
	private function remove_existing_rules( $htaccess_file ) {
		if ( ! file_exists( $htaccess_file ) ) {
			return false;
		}

		$fs = FilesystemService::get_filesystem();
		if ( ! $fs ) {
			return false;
		}

		$content = $fs->get_contents( $htaccess_file );
		if ( $content === false ) {
			return false;
		}

		$pattern = '/' . preg_quote( $this->htaccess_marker_start, '/' ) . '.*?' . preg_quote( $this->htaccess_marker_end, '/' ) . '/s';
		$content = preg_replace( $pattern, '', $content );

		$content = preg_replace( '/\n\s*\n\s*\n/', "\n\n", $content );

		$result = $fs->put_contents( $htaccess_file, $content, FS_CHMOD_FILE );
		if ( $result === false ) {
			return false;
		}

		return true;
	}

	/**
	 * Get .htaccess rules as string (dynamic RewriteBase support)
	 */
	private function get_api_rewrite_rules() {
		$base_path = $this->get_site_relative_base();
		$rules     = $this->htaccess_marker_start . "\n";
		$rules    .= "<IfModule mod_rewrite.c>\n";
		$rules    .= "RewriteEngine On\n";
		$rules    .= "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
		$rules    .= "RewriteBase {$base_path}\n";
		$rules    .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$rules    .= "RewriteCond %{REQUEST_FILENAME} !-d\n";
		$rules    .= "RewriteRule ^{$this->api_slug}/([^/]+)/?$ index.php?{$this->api_slug}=$1 [QSA,L]\n";
		$rules    .= "RewriteRule ^{$this->api_slug}/([^/]+)/([^/]+)/?$ index.php?{$this->api_slug}=$1&action=$2 [QSA,L]\n";
		$rules    .= "RewriteRule ^{$this->api_slug}/([^-]+)-([^/]+)/?$ index.php?{$this->api_slug}=$1-$2 [QSA,L]\n";
		$rules    .= "</IfModule>\n";
		$rules    .= $this->htaccess_marker_end . "\n";
		return $rules;
	}

	/**
	 * Get .htaccess rules as array
	 */
	private function get_htaccess_rules_array() {
		$base_path = $this->get_site_relative_base();
		return array(
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
			'RewriteBase ' . $base_path,
			'RewriteCond %{REQUEST_FILENAME} !-f',
			'RewriteCond %{REQUEST_FILENAME} !-d',
			'RewriteRule ^' . $this->api_slug . '/([^/]+)/?$ index.php?' . $this->api_slug . '=$1 [QSA,L]',
			'RewriteRule ^' . $this->api_slug . '/([^/]+)/([^/]+)/?$ index.php?' . $this->api_slug . '=$1&action=$2 [QSA,L]',
			'RewriteRule ^' . $this->api_slug . '/([^-]+)-([^/]+)/?$ index.php?' . $this->api_slug . '=$1-$2 [QSA,L]',
			'</IfModule>',
		);
	}

	/**
	 * Route incoming API requests to the appropriate query var.
	 *
	 * ## Why this endpoint does not use wp_verify_nonce()
	 *
	 * This method is a `parse_request` hook — it inspects the URL and populates
	 * `$wp->query_vars` so that WordPress routes /wptw-api/<slug>/ requests to
	 * the API layer. It performs NO state mutation of its own: it does not write
	 * to the database, filesystem, or session — it only sets the
	 * WPTW_API_REQUEST constant and copies a slug from the request URI into a
	 * query var. wp_verify_nonce() and current_user_can() are not applicable
	 * because (a) this method does not mutate state, and (b) a wp_nonce is a
	 * per-user CSRF token bound to a browser session, and this endpoint is a
	 * URL-router hook that must accept every request URL to inspect it.
	 *
	 * ## Authorization is enforced downstream
	 *
	 * Authentication for the routed request happens in Routing.php AFTER this
	 * hook runs. Every /wptw-api/ request must present a bearer JWT in the
	 * `Authorization: Bearer <jwt>` header (or `X-Tailwatch-Auth-Key` header
	 * for mobile-app callers), validated for HS256 signature against
	 * WPTW_JWT_AUTH_SECRET_KEY (constant-time hash_equals compare), plus
	 * non-revoked JTI checked against the tokens table, plus non-expired exp
	 * claim, plus matching device fingerprint bound at license-pairing time.
	 * If any check fails, Routing.php returns 401 before the routed handler is
	 * reached — so the actual auth check runs one layer down against a
	 * cryptographically-signed JWT, not a per-user WP session cookie.
	 *
	 * @param WP $wp WordPress environment instance.
	 */
	public function handle_api_request( $wp ) {
		if ( isset( $wp->query_vars[ $this->api_slug ] ) ) {
			if ( ! defined( 'WPTW_API_REQUEST' ) ) {
				define( 'WPTW_API_REQUEST', true );
			}
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- API routing parameter, read-only
		if ( isset( $_GET[ $this->api_slug ] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- API routing parameter
			$wp->query_vars[ $this->api_slug ] = sanitize_text_field( wp_unslash( $_GET[ $this->api_slug ] ) );
			if ( ! defined( 'WPTW_API_REQUEST' ) ) {
				define( 'WPTW_API_REQUEST', true );
			}
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( strpos( $request_uri, '/' . $this->api_slug . '/' ) !== false ) {
			if ( preg_match( '~/' . $this->api_slug . '/([^/]+)/?~i', $request_uri, $matches ) ) {
				$wp->query_vars[ $this->api_slug ] = sanitize_text_field( $matches[1] );
				if ( ! defined( 'WPTW_API_REQUEST' ) ) {
					define( 'WPTW_API_REQUEST', true );
				}
				return;
			}
		}

		$parts = explode( '/', trim( $request_uri, '/' ) );
		$key   = array_search( $this->api_slug, $parts );

		if ( $key !== false && isset( $parts[ $key + 1 ] ) ) {
			$wp->query_vars[ $this->api_slug ] = sanitize_text_field( $parts[ $key + 1 ] );
			if ( ! defined( 'WPTW_API_REQUEST' ) ) {
				define( 'WPTW_API_REQUEST', true );
			}
		}
	}


	/**
	 * Add our query vars to WordPress
	 */
	public function add_query_vars( $vars ) {
		$vars[] = $this->api_slug;
		$vars[] = 'action';
		return $vars;
	}

	/**
	 * Prevent WordPress from redirecting API requests
	 */
	public function prevent_api_redirect( $redirect_url, $requested_url ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
		// API routing check, read-only operation to detect API requests
		if (
			defined( 'WPTW_API_REQUEST' ) ||
			( isset( $_GET[ $this->api_slug ] ) ) ||
			strpos( $requested_url, '/' . $this->api_slug . '/' ) !== false
		) {
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Add notice to admin if permalinks are not set to pretty format
	 */
	public function add_permalink_notice() {
		$permalink_structure = get_option( 'permalink_structure' );

		if ( empty( $permalink_structure ) && current_user_can( 'manage_options' ) ) {
			$hook_controller = new HookControllers();
			$hook_controller->add_action_hook(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<strong><?php echo esc_html( strtoupper( $this->api_slug ) ); ?> API Notice:</strong>
						Your site is using plain permalinks. While the API will still function,
						we recommend <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">changing your permalink structure</a>
						to a pretty permalink option for optimal performance and cleaner URLs.
					</p>
				</div>
					<?php
				}
			);
		}
	}

	/**
	 * Ensure the WordPress permalink block (# BEGIN WordPress) is always present.
	 *
	 * Runs on wp_loaded priority 99 — after every init-priority write to .htaccess.
	 * If any plugin controller's prepend_htaccess_rules() inadvertently dropped the
	 * WordPress block, this restores it via flush_rewrite_rules(), which uses
	 * WordPress's own insert_with_markers() and preserves all other content.
	 */
	public function ensure_wordpress_rewrite_rules() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home_path     = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$htaccess_file = $home_path . '.htaccess';

		if ( ! file_exists( $htaccess_file ) ) {
			return;
		}

		$fs      = FilesystemService::get_filesystem();
		$content = $fs ? $fs->get_contents( $htaccess_file ) : false;
		if ( $content === false ) {
			return;
		}

		// Block is already present — nothing to do.
		if ( strpos( $content, '# BEGIN WordPress' ) !== false ) {
			return;
		}

		// Block is missing. Restore it cheaply: use insert_with_markers() directly
		// rather than flush_rewrite_rules() to avoid the expensive DB write
		// (update_option 'rewrite_rules'). insert_with_markers() appends the
		// WordPress block while leaving every other .htaccess section intact.
		global $wp_rewrite;
		if ( ! $wp_rewrite ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$rules = explode( "\n", $wp_rewrite->mod_rewrite_rules() );
		\insert_with_markers( $htaccess_file, 'WordPress', $rules );
	}

	/**
	 * Remove existing API rules from content string
	 */
	private function remove_existing_rules_from_content( &$content ) {
		$pattern = '/' . preg_quote( $this->htaccess_marker_start, '/' ) . '.*?' . preg_quote( $this->htaccess_marker_end, '/' ) . '/s';
		$content = preg_replace( $pattern, '', $content );
		$content = preg_replace( '/\n\s*\n\s*\n/', "\n\n", $content );
	}

	/**
	 * Verify that htaccess rules were properly written
	 */
	private function verify_htaccess_rules( $htaccess_file ) {
		if ( ! file_exists( $htaccess_file ) ) {
			return false;
		}

		$fs      = FilesystemService::get_filesystem();
		$content = $fs ? $fs->get_contents( $htaccess_file ) : false;
		if ( $content === false ) {
			return false;
		}

		return $this->has_api_rewrite_rules( $content );
	}
}
