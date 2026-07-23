<?php
/**
 * Tailwatch - Root Autoloader
 *
 * Automatically loads plugin classes based on namespace.
 *
 * @package    Tailwatch
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader for Tailwatch plugin with vendor prefix support
 */
spl_autoload_register(
	function ( $class ) {
		// Define the vendor prefix for the plugin
		$prefix = 'Tailwatch\\';

		// Check if the class uses the vendor prefix
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			// Not our plugin's class, let other autoloaders handle it
			return;
		}

		// Remove the vendor prefix from the class name
		$relative_class = substr( $class, $len );

		// Base directory for the plugin
		$baseDir = __DIR__ . '/';

		// Build the file path
		$file = $baseDir . str_replace( '\\', '/', $relative_class ) . '.php';

		// If the file exists, require it
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
