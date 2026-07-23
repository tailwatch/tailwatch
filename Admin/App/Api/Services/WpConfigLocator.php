<?php
/**
 * wp-config.php location helper.
 *
 * WordPress core's wp-load.php probes both ABSPATH/wp-config.php AND
 * dirname(ABSPATH)/wp-config.php. Plugins that only look in ABSPATH silently
 * break on Bedrock / Roots-style installs where wp-config.php sits one
 * directory above ABSPATH for security hardening.
 *
 * @package    Tailwatch
 * @subpackage Services
 */

namespace Tailwatch\Admin\App\Api\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WpConfigLocator {

	/**
	 * Absolute path to wp-config.php, or null if neither candidate exists.
	 *
	 * Mirrors WordPress core's own resolution order:
	 *   1. ABSPATH/wp-config.php               (standard layout)
	 *   2. dirname(ABSPATH)/wp-config.php      (Bedrock, Roots, hardened installs)
	 *
	 * @return string|null
	 */
	public static function locate() {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * True when wp-config.php is present in either candidate location.
	 *
	 * @return bool
	 */
	public static function exists() {
		return null !== self::locate();
	}
}