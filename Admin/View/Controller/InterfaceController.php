<?php

/**
 * Tailwatch - Interface Controller
 *
 * Handles the main dashboard interface and asset loading.
 *
 * @package    Tailwatch
 * @subpackage Admin\View\Controller
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\View\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;

class InterfaceController {

	/**
	 * Constructor.
	 *
	 * Initialize hooks for admin scripts and menu.
	 *
	 */
	public function __construct() {
		$hook_controllers = new HookControllers();
		$hook_controllers->add_action_hook( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_script' ), 20 );
		$hook_controllers->add_action_hook( 'admin_menu', array( $this, 'add_tailwatch_page' ) );

		// Hide the WordPress admin toolbar on the Tailwatch React app screen.
		// This filter is registered at plugins_loaded time, BEFORE init priority 0
		// where _wp_admin_bar_init() decides whether the toolbar will render.
		// (An earlier implementation tried to do this from admin_init, but
		// admin_init runs AFTER init — too late to suppress the toolbar.)
		$hook_controllers->add_filter_hook( 'show_admin_bar', array( $this, 'hide_admin_bar_on_tailwatch' ) );
	}

	/**
	 * Suppress the WordPress admin toolbar when the user is on the Tailwatch
	 * full-screen React app (page=tailwatch). Returns the incoming value
	 * unchanged on every other admin page, so the toolbar still appears
	 * normally elsewhere and the user's profile preference is respected.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar_on_tailwatch( $show ) {
		if ( ! is_admin() ) {
			return $show;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page identifier for display scoping; not an action that mutates state.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'tailwatch' === $page ) {
			return false;
		}
		return $show;
	}

	/**
	 * Add plugin admin menu pages.
	 *
	 * @return void
	 */
	public function add_tailwatch_page() {
		add_menu_page(
			'Tailwatch Security',
			'Tailwatch Security',
			'manage_options',
			'tailwatch',
			array( $this, 'tailwatch_option_page' ),
			// File URL (not base64) so svg-painter leaves the gradient intact.
			TAILWATCH_URI . 'Admin/View/Static/images/tailwatch-shield-icon.svg',
			// Between Tools (75) and Settings (80); clear of the 4/59/99 core menu separators.
			78
		);

		add_submenu_page(
			'tailwatch',
			esc_html__( 'Dashboard', 'tailwatch' ),
			esc_html__( 'Dashboard', 'tailwatch' ),
			'manage_options',
			'tailwatch',
			array( $this, 'tailwatch_option_page' )
		);
	}

	/**
	 * Get all files from a directory with a specific extension.
	 *
	 * @param string $dir       Directory path.
	 * @param string $extension File extension.
	 * @return array List of file URLs.
	 */
	public function get_all_files( $dir, $extension ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Enumerating plugin-owned static asset dir; wildcard pattern that WP_Filesystem->dirlist() can't express in one call.
		$files     = glob( $dir . '*.' . $extension );
		if ( false === $files ) {
			$files = array();
		}
		$file_urls = array_map(
			function ( $file ) use ( $dir ) {
				return TAILWATCH_URI . str_replace( TAILWATCH_DIR, '', $file );
			},
			$files
		);
		return $file_urls;
	}

	/**
	 * External Tailwatch service URLs handed to the React dashboard.
	 *
	 * Values come from the plugin constants so PHP and the bundled app link to the
	 * same properties. Campaign (utm_*) parameters are appended by each link position
	 * in the app, not stored here.
	 *
	 * @return array Map of URL keys to absolute URLs.
	 */
	public static function get_service_urls() {
		return array(
			'website'   => TAILWATCH_WEBSITE_URL,
			'dashboard' => TAILWATCH_DASHBOARD_URL,
			'docs'      => TAILWATCH_DOCS_URL,
			'pricing'   => TAILWATCH_PRICING_URL,
			'roadmap'   => TAILWATCH_ROADMAP_URL,
			'contact'   => TAILWATCH_CONTACT_URL,
			'privacy'   => TAILWATCH_PRIVACY_POLICY_URL,
			'support_email' => TAILWATCH_SUPPORT_EMAIL,
		);
	}

	/**
	 * Enqueue dashboard scripts and styles.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_dashboard_script( $hook_suffix ) {
		// Define directories
		$js_directory  = TAILWATCH_DIR . 'Admin/View/Static/js/';
		$css_directory = TAILWATCH_DIR . 'Admin/View/Static/css/';

		$js_files  = $this->get_all_files( $js_directory, 'js' );
		$css_files = $this->get_all_files( $css_directory, 'css' );

		if ( $hook_suffix === 'toplevel_page_tailwatch' ) {
			// Hide all WordPress admin notices and errors for clean React interface
			$this->hide_admin_notices();

			// Enqueue WordPress media uploader
			wp_enqueue_media();

			// Enqueue all JavaScript files
			foreach ( $js_files as $index => $js_file ) {
				wp_enqueue_script(
					'tailwatch-dashboard-js-' . $index,
					$js_file,
					array(),
					TAILWATCH_VERSION,
					true
				);
			}

			// Localize script
			if ( ! empty( $js_files ) ) {
				wp_localize_script(
					'tailwatch-dashboard-js-0',
					'tailwatch_ajax',
					array(
						'ajax_url'  => admin_url( 'admin-ajax.php' ),
						'nonce'     => wp_create_nonce( 'tailwatch_ajax_nonce' ),
						'version'   => TAILWATCH_VERSION,
						'admin_url' => admin_url(),
						'base_url'  => TAILWATCH_GET_SITE_URL,
						'asset_url' => TAILWATCH_URI . 'Admin/View/Static/images/',
						'urls'      => self::get_service_urls(),
					)
				);
			}

			// Enqueue all CSS files
			foreach ( $css_files as $index => $css_file ) {
				wp_enqueue_style( 'tailwatch-dashboard-css-' . $index, $css_file, array(), TAILWATCH_VERSION );
			}
		}
	}

	/**
	 * Check and render option page.
	 *
	 * @return void
	 */
	public function tailwatch_option_page() {
		// Additional safety measure to hide notices on this specific page
		$this->hide_admin_notices();
		?>
		<noscript><?php esc_html_e( 'You need to enable JavaScript to run this app.', 'tailwatch' ); ?></noscript>
		<div id="root"></div>
		<?php
	}

	/**
	 * Hide WordPress admin notices on the Tailwatch admin page only.
	 *
	 * ## Scope
	 *
	 * This method is intentionally restricted to the Tailwatch top-level admin
	 * page (`page=tailwatch`). The plugin renders a single-page React app there
	 * and admin notices from other plugins routinely overlap the React UI in
	 * ways that break layout. Outside of `page=tailwatch`, this method is a
	 * no-op — notices from WordPress core, other plugins, and themes display
	 * normally in their usual locations.
	 *
	 * The upstream callers (`admin_enqueue_scripts` callback and the page
	 * renderer) already gate this method to the Tailwatch screen. The inline
	 * guard below is defense in depth so a future caller that invokes this
	 * method without first checking the screen cannot accidentally suppress
	 * notices on unrelated admin pages.
	 *
	 * @return void
	 */
	private function hide_admin_notices() {
		// Defense in depth: only operate when we are actually on the Tailwatch
		// admin page. The check accepts either the GET parameter (works during
		// page load before get_current_screen() is fully populated) or the
		// current screen ID.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page-identifier check for display scoping; not an action that mutates state.
		$page          = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_our_screen = ( 'tailwatch' === $page )
			|| ( $screen && isset( $screen->id ) && 'toplevel_page_tailwatch' === $screen->id );
		if ( ! $on_our_screen ) {
			return;
		}


		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_style(
					'tailwatch-admin-notice-hide',
					TAILWATCH_URI . 'Admin/Assets/css/tailwatch-admin-notice-hide.css',
					array(),
					TAILWATCH_VERSION
				);
			}
		);
	}
}
