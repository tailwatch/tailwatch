<?php

namespace Tailwatch\Admin\App\Api\Controllers\FilesAndPermissions;

defined( 'ABSPATH' ) || exit;


use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Logging\Log;

class FilesPermissionController {

	private $settings_cache = null;

	public function __construct() {
		$hook_controller = new HookControllers();

		// Core features
		$hook_controller->add_filter_hook( 'user_has_cap', array( $this, 'editor_control_disable_file_editor' ), 10, 3 );
		$hook_controller->add_action_hook( 'template_redirect', array( $this, 'restrict_author_archive_access' ) );

		// Header and Meta cleanup
		// Centralized header management (fixes duplication issue)
		$hook_controller->add_filter_hook( 'wp_headers', array( $this, 'handle_headers' ), 999 );

		// Strip PHP-emitted X-Powered-By header at the PHP runtime layer
		// (the wp_headers filter cannot reach headers set outside WP).
		$hook_controller->add_action_hook( 'init', array( $this, 'remove_php_exposed_headers' ), -10 );

		// Strip REST API disclosure headers (X-WP-Total, X-WP-TotalPages).
		// These are set by WP_REST_Server directly on the response object,
		// so wp_headers filter cannot reach them — must use rest_post_dispatch.
		$hook_controller->add_filter_hook( 'rest_post_dispatch', array( $this, 'filter_rest_headers' ), 999, 3 );

		// Meta tag removal (init is appropriate for removing actions)
		$hook_controller->add_action_hook( 'init', array( $this, 'remove_meta_tags' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'remove_wp_version_information' ) );

		// HTML Comments
		$hook_controller->add_action_hook( 'template_redirect', array( $this, 'remove_html_comments' ) );

		// Additional hardening hooks
		$hook_controller->add_action_hook( 'init', array( $this, 'disable_emoji' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'disable_oembed' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'disable_feeds' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'disable_xmlrpc_completely' ) );

		// Runs before admin scripts print (script_concat_settings), so it can flip the
		// $concatenate_scripts global without any wp-config.php modification.
		$hook_controller->add_action_hook( 'admin_init', array( $this, 'disable_concatenate_scripts' ) );

		// Keep the file-access hardening .htaccess block in sync with the toggles.
		// Only rewrites when the toggle signature changed; loopback-verified + rolled back.
		$hook_controller->add_action_hook( 'admin_init', array( $this, 'apply_file_access_rules' ), 20 );
	}

	public function get_features_options() {
		if ( $this->settings_cache !== null ) {
			return $this->settings_cache;
		}

		$key                  = 'default_feature_settings';
		$option               = 'default_files_and_permission';
		$is_active            = true;
		$options_controller   = new OptionsController();
		$this->settings_cache = $options_controller->get_features_options( $key, $option, $is_active );

		return $this->settings_cache;
	}

	public function editor_control_disable_file_editor( $allcaps, $caps, $args ) {
		$get_feature = $this->get_features_options();

		if ( $get_feature ) {
			if ( isset( $caps[0] ) ) {
				if ( $caps[0] === 'edit_plugins' ) {
					if ( isset( $get_feature['field_1']['options']['option']['selected'] ) && $get_feature['field_1']['options']['option']['selected'] ) {
						$allcaps[ $caps[0] ] = false;
					}
				}
				if ( $caps[0] === 'edit_themes' ) {
					if ( isset( $get_feature['field_2']['options']['option']['selected'] ) && $get_feature['field_2']['options']['option']['selected'] ) {
						$allcaps[ $caps[0] ] = false;
					}
				}
			}
		}
		return $allcaps;
	}

	public function restrict_author_archive_access() {
		$get_feature = $this->get_features_options();

		if ( $get_feature ) {
			$restrict_author = isset( $get_feature['field_16']['options']['option']['selected'] ) && $get_feature['field_16']['options']['option']['selected'];
			if ( $restrict_author && is_author() ) {
				wp_safe_redirect( home_url() );
				exit;
			}
		}
	}

	public function remove_wp_version_information() {
		$get_feature = $this->get_features_options();
		if ( $get_feature && isset( $get_feature['field_20']['options']['option']['selected'] ) && $get_feature['field_20']['options']['option']['selected'] ) {
			$hook_controller = new HookControllers();

			// Remove version from WordPress CORE files only
			$hook_controller->add_filter_hook( 'style_loader_src', array( $this, 'remove_wp_core_version' ), 9999, 2 );
			$hook_controller->add_filter_hook( 'script_loader_src', array( $this, 'remove_wp_core_version' ), 9999, 2 );

			// Remove WordPress generator meta tag
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );

			// Remove version from admin footer
			add_filter( 'update_footer', '__return_empty_string', 11 );

			// Remove version from RSS feeds
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

	public function remove_wp_core_version( $src, $handle ) {
		if ( empty( $src ) ) {
			return $src;
		}

		// Only process if source has a version parameter
		if ( strpos( $src, 'ver=' ) === false ) {
			return $src;
		}

		// Check if this is a WordPress core file (most reliable method)
		$is_wp_core = (
			strpos( $src, '/wp-includes/' ) !== false ||
			strpos( $src, '/wp-admin/' ) !== false
		);

		// Additional check: Compare version with WordPress version
		global $wp_version;
		$has_wp_version = strpos( $src, 'ver=' . $wp_version ) !== false;

		// Remove version ONLY from WordPress core files
		if ( $is_wp_core || $has_wp_version ) {
			$src = remove_query_arg( 'ver', $src );
		}

		return $src;
	}

	/**
	 * Centralized header management
	 * Handles both general security header removal and specific feature headers (like X-Pingback)
	 */
	public function handle_headers( $headers ) {
		$get_feature = $this->get_features_options();
		if ( ! $get_feature ) {
			return $headers;
		}

		$remove_security_headers = isset( $get_feature['field_10']['options']['option']['selected'] )
			&& $get_feature['field_10']['options']['option']['selected'];

		$disable_xmlrpc = isset( $get_feature['field_7']['options']['option']['selected'] )
			&& $get_feature['field_7']['options']['option']['selected'];

		// 1. Remove WP-set information disclosure headers from REST/WP responses.
		// Note: 'Server' and 'X-Powered-By' are NOT in $headers — Apache/PHP-FPM
		// add them after WP runs. Server requires server-level config; X-Powered-By
		// is removed via header_remove() in remove_php_exposed_headers().
		if ( $remove_security_headers ) {
			unset( $headers['X-WP-Total'] );
			unset( $headers['X-WP-TotalPages'] );
		}

		// 2. Remove X-Pingback if EITHER feature is enabled
		if ( $remove_security_headers || $disable_xmlrpc ) {
			unset( $headers['X-Pingback'] );
		}

		return $headers;
	}

	/**
	 * Remove headers that PHP itself emits (e.g. X-Powered-By from expose_php).
	 * The wp_headers filter cannot remove these because they are set by the
	 * PHP runtime, not WordPress. Must run before any output is sent.
	 *
	 */
	public function remove_php_exposed_headers() {
		$get_feature = $this->get_features_options();
		if ( ! $get_feature ) {
			return;
		}

		$enabled = isset( $get_feature['field_10']['options']['option']['selected'] )
			&& $get_feature['field_10']['options']['option']['selected'];

		if ( ! $enabled || headers_sent() ) {
			return;
		}

		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );

			// Best-effort: ask PHP to drop the Server header from the outgoing
			// response. This works when the web server's core HTTP filter
			// respects PHP's header table (common on configs with
			// "ServerTokens Prod" or PHP-FPM behind nginx with
			// "fastcgi_pass_header" disabled). On configs like default XAMPP
			// (ServerTokens Full), Apache's core filter re-adds Server after
			// PHP runs — in that case server-level config is the only fix.
			header_remove( 'Server' );
		}
	}

	/**
	 * Remove disclosure headers from REST API responses.
	 *
	 * The wp_headers filter does not reach REST responses because
	 * WP_REST_Server sets X-WP-Total / X-WP-TotalPages directly on
	 * the WP_HTTP_Response object. rest_post_dispatch is the earliest
	 * point where we can mutate them before WP streams the response.
	 *
	 * @param \WP_HTTP_Response $response REST response object.
	 * @param \WP_REST_Server   $server   REST server instance.
	 * @param \WP_REST_Request  $request  REST request object.
	 * @return \WP_HTTP_Response
	 */
	public function filter_rest_headers( $response, $server, $request ) {
		if ( ! ( $response instanceof \WP_HTTP_Response ) ) {
			return $response;
		}

		$get_feature = $this->get_features_options();
		if ( ! $get_feature ) {
			return $response;
		}

		$enabled = isset( $get_feature['field_10']['options']['option']['selected'] )
			&& $get_feature['field_10']['options']['option']['selected'];

		if ( ! $enabled ) {
			return $response;
		}

		// WP_HTTP_Response has no remove_header() method, and header('X',null)
		// leaves the key present with an empty value (so WP still sends
		// "X-WP-Total: "). Work around by rebuilding the full headers array
		// with the disclosure headers unset.
		$headers = $response->get_headers();
		unset( $headers['X-WP-Total'], $headers['X-WP-TotalPages'] );
		$response->set_headers( $headers );

		return $response;
	}

	/**
	 * Remove meta tags from wp_head
	 * Renamed from remove_headers_and_meta to reflect actual purpose
	 */
	public function remove_meta_tags() {
		$get_feature = $this->get_features_options();
		if ( ! $get_feature ) {
			return;
		}

		if ( ! isset( $get_feature['field_12']['options']['option']['selected'] ) ||
			! $get_feature['field_12']['options']['option']['selected'] ) {
			return;
		}

		// Remove the three info-disclosure meta tags this option advertises.
		// Each remove_action() call mirrors the corresponding WP core
		// add_action() in wp-includes/default-filters.php so the priority and
		// callback signature match exactly.
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );

		// Shortlinks are also exposed via the HTTP Link: response header.
		// WP core registers this at priority 11 — match it here so the header
		// is suppressed too, otherwise the URL leaks even when the <link> tag
		// is gone (visible only via curl -I, but security scanners pick it up).
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );

		// Adjacent / parent / start / index post links — same family of
		// info-disclosure tags that reveal site structure. Leaving these in
		// would let crawlers walk the post graph from any single page.
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'parent_post_rel_link', 10 );
		remove_action( 'wp_head', 'start_post_rel_link', 10 );
		remove_action( 'wp_head', 'index_rel_link' );
	}

	public function remove_html_comments() {
		$get_feature = $this->get_features_options();
		if ( $get_feature && isset( $get_feature['field_13']['options']['option']['selected'] ) && $get_feature['field_13']['options']['option']['selected'] ) {
			ob_start(
				function ( $buffer ) {
					if ( empty( $buffer ) || ! is_string( $buffer ) ) {
						return $buffer;
					}

					// Only process full HTML documents.
					if ( stripos( $buffer, '<!DOCTYPE html' ) === false && stripos( $buffer, '<html' ) === false ) {
						return $buffer;
					}

					return $this->tailwatch_minify_html_buffer( $buffer );
				}
			);

			// Ensure buffer is flushed at shutdown
			add_action(
				'shutdown',
				function () {
					if ( ob_get_level() > 0 ) {
						ob_end_flush();
					}
				},
				999
			);
		}
	}

	/**
	 * Remove HTML comments and collapse inter-tag whitespace without corrupting content whose
	 * whitespace is significant.
	 *
	 * <script>, <style>, <pre> and <textarea> blocks are set aside before any whitespace is
	 * touched and restored verbatim afterwards (the established Minify_HTML approach): collapsing
	 * whitespace inside them would break preformatted text, textarea values, inline JavaScript
	 * (a line // comment could swallow the next statement) and JSON-LD. Any PCRE failure returns
	 * the buffer unchanged so the page is never blanked or broken.
	 *
	 * @param string $buffer Full page HTML.
	 * @return string
	 */
	private function tailwatch_minify_html_buffer( $buffer ) {
		$original = $buffer;
		$reserved = array();
		$reserve  = static function ( $matches ) use ( &$reserved ) {
			$placeholder              = '%%%TW_HTMLMIN_RESERVE_' . count( $reserved ) . '%%%';
			$reserved[ $placeholder ] = $matches[0];
			return $placeholder;
		};

		// Set aside whitespace-significant blocks so the minify below cannot touch them.
		foreach ( array(
			'#<script\b[^>]*>.*?</script>#is',
			'#<style\b[^>]*>.*?</style>#is',
			'#<pre\b[^>]*>.*?</pre>#is',
			'#<textarea\b[^>]*>.*?</textarea>#is',
		) as $pattern ) {
			$result = preg_replace_callback( $pattern, $reserve, $buffer );
			if ( null === $result ) {
				return $original;
			}
			$buffer = $result;
		}

		// Remove HTML comments (preserve IE conditional comments), then trim only
		// insignificant whitespace: indentation, trailing line whitespace and blank
		// lines. Whitespace between elements and inside text/attributes is left
		// intact, so inline spacing (e.g. "<b>a</b> <i>b</i>") is never joined.
		$passes = array(
			'/<!--(?!\s*(?:\[if\s|\[endif\]|<!))(?:(?!-->).)*-->/s' => '',
			'/[ \t]*\r?\n[ \t]*/' => "\n",
			'/\n{2,}/'            => "\n",
		);
		foreach ( $passes as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $buffer );
			if ( null === $result ) {
				return $original;
			}
			$buffer = $result;
		}

		// Restore reserved blocks verbatim. Loop to resolve any nested placeholders.
		$guard = 0;
		while ( ! empty( $reserved ) && $guard++ < 5 && strpos( $buffer, '%%%TW_HTMLMIN_RESERVE_' ) !== false ) {
			$buffer = strtr( $buffer, $reserved );
		}

		return $buffer;
	}

	public function disable_emoji() {
		$get_feature = $this->get_features_options();

		$disable_emoji = $get_feature
			&& ! empty( $get_feature['field_21']['options']['option']['selected'] );

		if ( $disable_emoji ) {
			// Front-end
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );

			// Admin area
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );

			// Feeds
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

			// TinyMCE
			add_filter(
				'tiny_mce_plugins',
				function ( $plugins ) {
					if ( is_array( $plugins ) ) {
						return array_diff( $plugins, array( 'wpemoji' ) );
					}
					return array();
				}
			);

			// Remove the DNS-prefetch resource hint for emoji assets. The hint is matched
			// by its path segment rather than a hard-coded remote URL, so no external
			// asset reference is shipped in the plugin.
			add_filter(
				'wp_resource_hints',
				function ( $urls, $relation_type ) {
					if ( 'dns-prefetch' !== $relation_type ) {
						return $urls;
					}
					return array_values(
						array_filter(
							$urls,
							function ( $url ) {
								$href = is_array( $url ) && isset( $url['href'] ) ? $url['href'] : ( is_string( $url ) ? $url : '' );
								return false === strpos( $href, 'emoji' );
							}
						)
					);
				},
				10,
				2
			);
		}
	}

	public function disable_oembed() {
		$get_feature = $this->get_features_options();

		$disable_oembed = $get_feature
			&& ! empty( $get_feature['field_22']['options']['option']['selected'] );

		if ( $disable_oembed ) {
			// Remove discovery links
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

			// Remove oEmbed-specific JavaScript
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );

			// Remove REST API oEmbed endpoint
			remove_action( 'rest_api_init', 'wp_oembed_register_route' );

			// Disable oEmbed auto-discovery
			add_filter( 'embed_oembed_discover', '__return_false' );

			// Remove oEmbed rewrite rules
			add_filter(
				'rewrite_rules_array',
				function ( $rules ) {
					foreach ( $rules as $rule => $rewrite ) {
						if ( strpos( $rewrite, 'embed=true' ) !== false ) {
							unset( $rules[ $rule ] );
						}
					}
					return $rules;
				}
			);

			// Remove query vars
			add_filter(
				'query_vars',
				function ( $vars ) {
					$vars = array_diff( $vars, array( 'embed' ) );
					return $vars;
				}
			);
		}
	}

	public function disable_feeds() {
		$get_feature = $this->get_features_options();

		$disable_feeds = $get_feature
			&& ! empty( $get_feature['field_23']['options']['option']['selected'] );

		if ( $disable_feeds ) {
			// Remove feed discovery links from <head>.
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );

			// Intercept any feed URL (/feed/, /comments/feed/, /?feed=atom, etc.)
			// at template_redirect — runs before any output and covers all
			// feed types in one hook. More reliable than add_action('do_feed_X')
			// which fires after WP has already started preparing the response.
			add_action( 'template_redirect', array( $this, 'redirect_feeds' ), 1 );
		}
	}

	public function redirect_feeds() {
		if ( ! is_feed() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	public function disable_xmlrpc_completely() {
		$get_feature = $this->get_features_options();

		// Using same field as htaccess rule for consistency: field_7
		if ( $get_feature && isset( $get_feature['field_7']['options']['option']['selected'] )
			&& $get_feature['field_7']['options']['option']['selected'] ) {

			// Method 1: Completely disable XML-RPC
			add_filter( 'xmlrpc_enabled', '__return_false' );

			// Method 2: X-Pingback header removal is now handled in handle_headers()

			// Method 3: Remove RSD link
			remove_action( 'wp_head', 'rsd_link' );

			// Method 4: Block XML-RPC methods
			add_filter(
				'xmlrpc_methods',
				function ( $methods ) {
					unset( $methods['pingback.ping'] );
					unset( $methods['pingback.extensions.getPingbacks'] );
					unset( $methods['wp.getUsersBlogs'] );
					unset( $methods['system.multicall'] );
					unset( $methods['system.listMethods'] );
					return $methods;
				}
			);

			// Block XML-RPC authentication
			add_filter(
				'authenticate',
				function ( $user, $username, $password ) {
					if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
						return new \WP_Error( 'xmlrpc_disabled', __( 'XML-RPC services are disabled on this site.', 'tailwatch' ) );
					}
					return $user;
				},
				20,
				3
			);
		}
	}

	/**
	 * Disable admin script/style concatenation without modifying wp-config.php.
	 *
	 * WordPress core's script_concat_settings() only derives $concatenate_scripts from
	 * the CONCATENATE_SCRIPTS constant when the global is not already set, and the core
	 * docblock states the $concatenate_scripts global "can be set by plugins to override
	 * the default behavior." Setting it here — before any admin script is printed — turns
	 * off concatenation the WordPress-sanctioned way, with no file writes at all.
	 *
	 * @return void
	 */
	public function disable_concatenate_scripts() {
		$get_feature = $this->get_features_options();

		if ( $get_feature && isset( $get_feature['field_24']['options']['option']['selected'] )
			&& $get_feature['field_24']['options']['option']['selected'] ) {
			// WordPress core's script_concat_settings() only reads this global when it is not
			// already set and documents it as a plugin override point (wp-includes/script-loader.php),
			// so assigning it here is the sanctioned way to disable concatenation.
			$GLOBALS['concatenate_scripts'] = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Documented WordPress script-concatenation override point.
		}
	}

	/**
	 * Keep the file-access hardening block in the root .htaccess in sync with the toggles.
	 *
	 * The block is scoped through WordPress core's insert_with_markers() (the same routine
	 * core uses for permalink rules), immediately loopback-verified, and rolled back if the
	 * live site stops responding — so a bad rule set can never 500 or lock out the site. To
	 * avoid rewriting on every request the toggle state is reduced to a short signature and
	 * the write is skipped when it has not changed since the last apply.
	 *
	 * @return void
	 */
	public function apply_file_access_rules() {
		$get_feature = $this->get_features_options();

		// When the whole Files & Permissions feature is inactive, get_features_options()
		// returns an empty set. Rather than bailing out (which would orphan a block written
		// while the feature was active), treat every sub-toggle as off so the block is
		// cleared — the signature gate below still keeps this to a single write.
		$selected = function ( $field ) use ( $get_feature ) {
			return ( is_array( $get_feature ) && isset( $get_feature[ $field ]['options']['option']['selected'] )
				&& $get_feature[ $field ]['options']['option']['selected'] ) ? '1' : '0';
		};

		$signature = $selected( 'field_3' ) . $selected( 'field_4' ) . $selected( 'field_5' )
			. $selected( 'field_6' ) . ( is_multisite() ? 'm' : 's' );

		if ( get_option( 'tailwatch_file_access_signature' ) === $signature ) {
			return;
		}

		// Advance the stored signature only when BOTH the root .htaccess block and the uploads
		// directory's .htaccess are left in the desired state, so a write blocked by a momentarily
		// locked file is retried on a later load rather than skipped.
		$root_ok    = $this->write_file_access_htaccess( $this->build_file_access_rules( $selected ) );
		$uploads_ok = $this->write_uploads_php_htaccess( '1' === $selected( 'field_5' ) );
		$core_ok    = $this->write_core_includes_php_htaccess( '1' === $selected( 'field_6' ) );
		if ( $root_ok && $uploads_ok && $core_ok ) {
			update_option( 'tailwatch_file_access_signature', $signature, false );
		}
	}

	/**
	 * Build the .htaccess directive lines for the currently enabled file-access toggles.
	 *
	 * All directives are drawn from the WordPress "Hardening WordPress" documentation and the
	 * conservative, widely-shipped patterns: protect specific sensitive files, disable
	 * directory browsing, and (opt-in) the official wp-includes restriction with the multisite
	 * ms-files.php exception applied. PHP execution in the uploads directory is handled
	 * separately in write_uploads_php_htaccess().
	 *
	 * @param callable $selected Resolver returning '1'/'0' for a given field key.
	 * @return array Directive lines (empty when nothing is enabled).
	 */
	private function build_file_access_rules( $selected ) {
		$lines = array();

		// A: Protect sensitive files (dual-syntax so it holds on both Apache 2.2 and 2.4).
		if ( '1' === $selected( 'field_3' ) ) {
			$protected_files = array(
				'wp-config.php',
				'.htaccess',
				'readme.html',
				'readme.txt',
				'xmlrpc.php',
				'install.php',
				'wp-config-sample.php',
				'debug.log',
			);
			foreach ( $protected_files as $protected_file ) {
				$lines[] = '<Files "' . $protected_file . '">';
				$lines[] = "\t<IfModule mod_authz_core.c>";
				$lines[] = "\t\tRequire all denied";
				$lines[] = "\t</IfModule>";
				$lines[] = "\t<IfModule !mod_authz_core.c>";
				$lines[] = "\t\tOrder allow,deny";
				$lines[] = "\t\tDeny from all";
				$lines[] = "\t</IfModule>";
				$lines[] = '</Files>';
			}
		}

		// B: Disable directory browsing.
		if ( '1' === $selected( 'field_4' ) ) {
			$lines[] = 'Options -Indexes';
		}

		// D: Restrict wp-includes (opt-in). Multisite keeps ms-files.php reachable via [S=4].
		if ( '1' === $selected( 'field_6' ) ) {
			$lines[] = '<IfModule mod_rewrite.c>';
			$lines[] = 'RewriteEngine On';
			$lines[] = 'RewriteBase /';
			$lines[] = 'RewriteRule ^wp-admin/includes/ - [F,L]';
			if ( is_multisite() ) {
				$lines[] = 'RewriteRule ^wp-includes/ms-files.php$ - [S=4]';
			}
			$lines[] = 'RewriteRule !^wp-includes/ - [S=3]';
			$lines[] = 'RewriteRule ^wp-includes/[^/]+\.php$ - [F,L]';
			$lines[] = 'RewriteRule ^wp-includes/js/tinymce/langs/.+\.php - [F,L]';
			$lines[] = 'RewriteRule ^wp-includes/theme-compat/ - [F,L]';
			$lines[] = '</IfModule>';
		}

		return $lines;
	}

	/**
	 * Write (or remove) the "Tailwatch File Access" block in the root .htaccess.
	 *
	 * Rules are written through WordPress core's insert_with_markers(). An empty rule set removes
	 * the marker block entirely — core's insert_with_markers() cannot delete a block (it always
	 * re-inserts its instruction comments), so removal reads the file and strips the block. A
	 * block is only ever written or removed when the on-disk state actually differs, so a fresh or
	 * never-configured install with nothing enabled never touches .htaccess. After a write the
	 * home URL is loopback-checked and the block is stripped again if the site stops responding.
	 *
	 * @param array $lines Directive lines to write (empty removes any existing block).
	 * @return bool True when .htaccess is left in the desired state (including "nothing to do");
	 *              false when a needed write could not happen yet and should be retried.
	 */
	private function write_file_access_htaccess( $lines ) {
		$htaccess_path = ABSPATH . '.htaccess';

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! is_object( $wp_filesystem ) ) {
			return true; // No usable filesystem API (e.g. FTP without stored credentials).
		}

		// No .htaccess at all (typically a non-Apache host): nothing for us to manage.
		if ( ! $wp_filesystem->exists( $htaccess_path ) ) {
			return true;
		}

		$current = $wp_filesystem->get_contents( $htaccess_path );
		if ( false === $current ) {
			return true;
		}
		$has_block = ( false !== strpos( $current, '# BEGIN Tailwatch File Access' ) );

		// Nothing enabled: strip our block if present, and never write when it is already absent.
		if ( empty( $lines ) ) {
			if ( ! $has_block ) {
				return true;
			}
			if ( ! $wp_filesystem->is_writable( $htaccess_path ) ) {
				return false;
			}
			$this->remove_file_access_block( $htaccess_path, $current );
			return true;
		}

		// Rules to apply but the file cannot be written yet — retry on a later load.
		if ( ! $wp_filesystem->is_writable( $htaccess_path ) || ! function_exists( 'insert_with_markers' ) ) {
			return false;
		}

		if ( ! insert_with_markers( $htaccess_path, 'Tailwatch File Access', $lines ) ) {
			return false;
		}

		// Confirm the live site still responds; if not, strip the block immediately.
		if ( ! $this->loopback_ok( home_url( '/' ) ) ) {
			$reverted = $wp_filesystem->get_contents( $htaccess_path );
			if ( false !== $reverted ) {
				$this->remove_file_access_block( $htaccess_path, $reverted );
			}
			Log::warning( 'File-access hardening rules were rolled back after the loopback check failed.' );
		}

		return true;
	}

	/**
	 * Build the dual-syntax <FilesMatch> deny block that blocks direct execution of PHP files.
	 *
	 * Holds on both Apache 2.2 and 2.4 and covers the common PHP handler extensions. When
	 * $exclude_basenames is given, those exact file names stay reachable via a negative lookahead
	 * so legitimately-served core endpoints (e.g. wp-tinymce.php, ms-files.php) keep working while
	 * every other PHP file in the directory and its subdirectories is denied.
	 *
	 * @param string[] $exclude_basenames File names to keep reachable (exact basename match).
	 * @return string[] Directive lines.
	 */
	private function build_php_deny_lines( array $exclude_basenames = array() ) {
		$ext = '\.(?:php[0-9]?|pht|phtml?|phps)$';
		if ( empty( $exclude_basenames ) ) {
			$pattern = '(?i)' . $ext;
		} else {
			$quoted = array();
			foreach ( $exclude_basenames as $name ) {
				$quoted[] = preg_quote( $name, null );
			}
			$pattern = '(?i)^(?!(?:' . implode( '|', $quoted ) . ')$).+' . $ext;
		}

		return array(
			'<FilesMatch "' . $pattern . '">',
			"\t<IfModule mod_authz_core.c>",
			"\t\tRequire all denied",
			"\t</IfModule>",
			"\t<IfModule !mod_authz_core.c>",
			"\t\tOrder allow,deny",
			"\t\tDeny from all",
			"\t</IfModule>",
			'</FilesMatch>',
		);
	}

	/**
	 * Write (or remove) a marker-scoped PHP-deny .htaccess inside a specific directory.
	 *
	 * The directive lives in the directory itself, so it applies regardless of the order of any
	 * rules in the site root .htaccess. Managed only when the site uses Apache (a root .htaccess
	 * is present) and the directory is a writable local directory; external/offloaded storage is
	 * left untouched. Coexists with any other content in that .htaccess and removes only its own
	 * marker block, dropping the file if nothing else remains.
	 *
	 * @param string   $target_dir Absolute directory whose .htaccess to manage.
	 * @param string[] $lines      Directive lines to write (used only when enabling).
	 * @param string   $marker     insert_with_markers marker name.
	 * @param bool     $enabled    Whether the rule should be present.
	 * @return bool True when left in the desired state (including "nothing to do"); false to retry.
	 */
	private function write_scoped_php_htaccess( $target_dir, array $lines, $marker, $enabled ) {
		// Apache heuristic: only manage a .htaccess when the site root already has one.
		if ( ! file_exists( ABSPATH . '.htaccess' ) ) {
			return true;
		}
		if ( '' === (string) $target_dir ) {
			return true;
		}
		$htaccess = rtrim( $target_dir, '/\\' ) . '/.htaccess';

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! is_object( $wp_filesystem ) ) {
			return true; // No usable filesystem API (e.g. FTP without stored credentials).
		}

		// The directory must be a local directory to host a .htaccess; offloaded storage (e.g. S3)
		// is not an Apache-served local directory and is left untouched.
		if ( ! $wp_filesystem->is_dir( $target_dir ) ) {
			return true;
		}

		$has_file = $wp_filesystem->exists( $htaccess );
		$current  = '';
		if ( $has_file ) {
			$current = $wp_filesystem->get_contents( $htaccess );
			if ( false === $current ) {
				return false; // Read error; retry on a later load.
			}
		}
		$has_block = ( false !== strpos( (string) $current, '# BEGIN ' . $marker ) );

		// Disabled: strip our block, and drop the file if it was the only thing in it.
		if ( ! $enabled ) {
			if ( ! $has_block ) {
				return true;
			}
			if ( ! $wp_filesystem->is_writable( $htaccess ) ) {
				return false;
			}
			$this->remove_file_access_block( $htaccess, (string) $current, $marker );
			$after = $wp_filesystem->exists( $htaccess ) ? $wp_filesystem->get_contents( $htaccess ) : '';
			if ( is_string( $after ) && '' === trim( $after ) && $wp_filesystem->exists( $htaccess ) ) {
				$wp_filesystem->delete( $htaccess );
			}
			return true;
		}

		// Enabled: write the deny block. Need the core helper and a writable target.
		if ( ! function_exists( 'insert_with_markers' ) ) {
			return false;
		}
		if ( $has_file && ! $wp_filesystem->is_writable( $htaccess ) ) {
			return false;
		}
		if ( ! $has_file && ! $wp_filesystem->is_writable( $target_dir ) ) {
			return false;
		}

		return (bool) insert_with_markers( $htaccess, $marker, $lines );
	}

	/**
	 * Block direct PHP execution in the uploads directory via its own .htaccess.
	 *
	 * @param bool $enabled Whether the toggle is on.
	 * @return bool
	 */
	private function write_uploads_php_htaccess( $enabled ) {
		$uploads = wp_get_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return true;
		}
		return $this->write_scoped_php_htaccess( $uploads['basedir'], $this->build_php_deny_lines(), 'Tailwatch Uploads PHP', $enabled );
	}

	/**
	 * Block direct PHP execution in the WordPress core include directories via their own .htaccess.
	 *
	 * Order-independent companion to the root-level wp-includes rewrite rules: a marker-scoped
	 * <FilesMatch> deny placed inside wp-includes and wp-admin/includes so the protection holds
	 * even when another plugin's root .htaccess rules would otherwise short-circuit the rewrite.
	 * wp-includes keeps the legitimately-served wp-tinymce.php reachable (and ms-files.php on
	 * multisite); wp-admin/includes has no directly-served PHP, so everything is denied.
	 *
	 * @param bool $enabled Whether the toggle is on.
	 * @return bool
	 */
	private function write_core_includes_php_htaccess( $enabled ) {
		$exclude = array( 'wp-tinymce.php' );
		if ( is_multisite() ) {
			$exclude[] = 'ms-files.php';
		}

		$includes_ok = $this->write_scoped_php_htaccess(
			ABSPATH . WPINC,
			$this->build_php_deny_lines( $exclude ),
			'Tailwatch wp-includes PHP',
			$enabled
		);

		$admin_includes_ok = $this->write_scoped_php_htaccess(
			ABSPATH . 'wp-admin/includes',
			$this->build_php_deny_lines(),
			'Tailwatch wp-admin-includes PHP',
			$enabled
		);

		return $includes_ok && $admin_includes_ok;
	}

	/**
	 * Remove the "Tailwatch File Access" marker block from a .htaccess file's contents.
	 *
	 * WordPress core's insert_with_markers() always re-inserts its instruction comments and so
	 * cannot fully delete a block; this strips the BEGIN…END block (and blank lines it leaves
	 * behind) through the WP_Filesystem API, writing back only when the content actually changes.
	 *
	 * @param string $htaccess_path Absolute path to the .htaccess file.
	 * @param string $content       Current file contents.
	 * @return void
	 */
	private function remove_file_access_block( $htaccess_path, $content, $marker = 'Tailwatch File Access' ) {
		global $wp_filesystem;

		$quoted   = preg_quote( $marker, "/" );
		$stripped = preg_replace(
			"/\n*# BEGIN {$quoted}\n.*?# END {$quoted}\n*/s",
			"\n",
			$content
		);

		if ( null === $stripped || $stripped === $content ) {
			return;
		}

		$wp_filesystem->put_contents( $htaccess_path, ltrim( $stripped, "\n" ), false );
	}

	/**
	 * Loopback capability check: request a URL on this site and confirm it responds without a
	 * transport error or a 500. Any failure returns false so callers can fail closed.
	 *
	 * @param string $url URL to request.
	 * @return bool
	 */
	private function loopback_ok( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return ( 0 !== $code && 500 !== $code );
	}
}
