<?php
/**
 * Filesystem Service
 *
 * Provides shared filesystem initialization for plugin, theme, and core operations.
 *
 * @package    Tailwatch
 * @subpackage Services/Common
 */

namespace Tailwatch\Admin\App\Api\Services\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FilesystemService
 *
 * Shared service class for WordPress filesystem operations.
 * Used by ThemeManagerService, PluginManagerService, and CoreController
 * to initialize the WordPress filesystem for file operations.
 *
 */
class FilesystemService {

	/**
	 * Initialize the WordPress filesystem.
	 *
	 * Sets up WP_Filesystem for file operations during installations and updates.
	 * Uses direct method to avoid FTP credential prompts in API context.
	 *
	 * @return bool True if filesystem initialized, false on failure.
	 */
	public static function setup_filesystem(): bool {
		global $wp_filesystem;

		if ( ! empty( $wp_filesystem ) ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Use direct filesystem method for API context.
		add_filter( 'filesystem_method', array( __CLASS__, 'force_direct_filesystem' ), 999 );

		$initialized = WP_Filesystem( false, ABSPATH, true );

		remove_filter( 'filesystem_method', array( __CLASS__, 'force_direct_filesystem' ), 999 );

		return $initialized && ! empty( $wp_filesystem );
	}

	/**
	 * Force direct filesystem method for programmatic operations.
	 *
	 * This callback is used with the 'filesystem_method' filter to ensure
	 * the direct filesystem method is used, avoiding FTP/SSH credential prompts
	 * that cannot be handled in an API context.
	 *
	 * @return string 'direct' filesystem method.
	 */
	public static function force_direct_filesystem(): string {
		return 'direct';
	}

	/**
	 * Get the global WordPress filesystem object.
	 *
	 * Initializes the filesystem if not already initialized.
	 *
	 * @return \WP_Filesystem_Base|false The filesystem object or false on failure.
	 */
	public static function get_filesystem() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			if ( ! self::setup_filesystem() ) {
				return false;
			}
		}

		return $wp_filesystem;
	}
}
