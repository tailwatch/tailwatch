<?php
/**
 * Tailwatch - Autoloader
 *
 * Automatically loads plugin classes based on namespace.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader for Tailwatch API with vendor prefix support
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Tailwatch\\Admin\\App\\Api\\';

		// Check if the class uses the vendor prefix
		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			// Not our API class, let other autoloaders handle it
			return;
		}

		// Remove the vendor prefix from the class name
		$relative_class = substr( $class, $len );

		// Base directory for the API
		$baseDir = __DIR__ . '/';

		$file = $baseDir . str_replace( '\\', '/', $relative_class ) . '.php';

		// If the file exists, require it
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
