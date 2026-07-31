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

					// Only process HTML content
					if ( stripos( $buffer, '<!DOCTYPE html' ) === false && stripos( $buffer, '<html' ) === false ) {
						return $buffer;
					}

					// Minify HTML (simple)
					// Remove extra whitespace
					$buffer = preg_replace( '/\s{2,}/', ' ', $buffer );
					// Remove space between tags
					$buffer = preg_replace( '/>\s+</', '><', $buffer );

					// Remove HTML comments but preserve IE conditional comments
					$buffer = preg_replace( '/<!--(?!\s*(?:\[if\s|\[endif\]|<!))(?:(?!-->).)*-->/s', '', $buffer );

					return $buffer;
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

			// Remove DNS prefetch for emoji CDN
			add_filter(
				'wp_resource_hints',
				function ( $urls, $relation_type ) {
					if ( 'dns-prefetch' === $relation_type ) {
						$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter; not ours to prefix.
						$urls          = array_diff( $urls, array( $emoji_svg_url ) );
					}
					return $urls;
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
}
