<?php
/**
 * Plugin Name: WP TailWatch — Dev (hot reload)
 * Description: LOCAL DEVELOPMENT ONLY. Loads the Create React App dev server bundle
 *              (http://localhost:3000) into the Tailwatch admin page so you get React
 *              Fast Refresh instead of rebuilding after every edit.
 *
 * HOW TO USE
 *   1. Copy this file to  wp-content/mu-plugins/tailwatch-dev.php
 *      (create the mu-plugins folder if it does not exist).
 *   2. In this interface repo run:  npm start   (serves on http://localhost:3000)
 *   3. Open wp-admin -> Tailwatch. Edits in src/ hot-reload live, no build step.
 *   4. When done: stop `npm start` and delete this file to return to the built bundle.
 *
 * NEVER ship this. A distributed WordPress plugin must be self-contained and must
 * never load scripts from localhost / an external URL. This file lives only in the
 * public interface repo's dev/ folder as a copy-me template.
 *
 * @package Tailwatch\Dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		// Only touch the Tailwatch dashboard screen.
		if ( 'toplevel_page_tailwatch' !== $hook ) {
			return;
		}

		$dev_server = 'http://localhost:3000';
		$asset_base = defined( 'TAILWATCH_URI' ) ? TAILWATCH_URI : plugins_url( 'tailwatch/' );
		$base_url   = defined( 'TAILWATCH_GET_SITE_URL' ) ? TAILWATCH_GET_SITE_URL : home_url();

		// 1) Remove the plugin's built dashboard bundle (JS + its compiled CSS). The plugin
		//    registers these under the handles tailwatch-dashboard-js-* / tailwatch-dashboard-css-*.
		//    We reload the app from the dev server and re-add custom.css ourselves below.
		global $wp_scripts, $wp_styles;
		if ( $wp_scripts ) {
			foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
				if ( 0 === strpos( $handle, 'tailwatch-dashboard-js-' ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}
		if ( $wp_styles ) {
			foreach ( array_keys( $wp_styles->registered ) as $handle ) {
				if ( 0 === strpos( $handle, 'tailwatch-dashboard-css-' ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		// 2) Load the CRA dev bundle instead (Fast Refresh + HMR live inside it).
		wp_enqueue_script( 'tailwatch-dev-bundle', $dev_server . '/static/js/bundle.js', array(), null, true );

		// 3) Re-add the hand-authored admin-chrome CSS. custom.css hides the WP admin bar,
		//    sidebar, footer, and notices (and provides the country-flag @font-face). These
		//    rules are NOT part of the React bundle, so without this the chrome reappears in
		//    dev. We enqueue it directly so it works even if the plugin didn't (or couldn't).
		if ( defined( 'TAILWATCH_DIR' ) && file_exists( TAILWATCH_DIR . 'Admin/View/Static/css/custom.css' ) ) {
			wp_enqueue_style( 'tailwatch-dev-custom', $asset_base . 'Admin/View/Static/css/custom.css', array(), null );
		}

		// 4) Re-inject the tailwatch_ajax object the dashboard reads at runtime. The plugin
		//    normally localizes this onto tailwatch-dashboard-js-0, which we just removed,
		//    so we attach an identical object to our dev bundle instead.
		wp_localize_script(
			'tailwatch-dev-bundle',
			'tailwatch_ajax',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'tailwatch_ajax_nonce' ),
				'admin_url' => admin_url(),
				'base_url'  => $base_url,
				'asset_url' => $asset_base . 'Admin/View/Static/images/',
			)
		);
	},
	999 // Run after the plugin's own enqueue, whatever its priority.
);