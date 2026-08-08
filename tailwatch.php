<?php
/**
 * Tailwatch - Main Plugin File
 *
 * @package     Tailwatch
 * @author      WP Tailwatch
 * @copyright   2025-2026 WP TAILWATCH LLC
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: Tailwatch
 * Plugin URI:  https://wptailwatch.com/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=plugin_uri
 * Author:      WP Tailwatch
 * Author URI:  https://wptailwatch.com/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=author_uri
 * Description: WordPress security with backups, monitoring, SSL tracking, file integrity checks, and event-based push notifications.
 * Version:     1.0.0
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tailwatch
 * Domain Path: /languages
 * Requires at least: 6.3
 * Tested up to:      7.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define the main plugin file constant.
 *
 * This must be defined before loading other constants as they depend on it.
 */
if ( ! defined( 'TAILWATCH_PLUGIN_FILE' ) ) {
	define( 'TAILWATCH_PLUGIN_FILE', __FILE__ );
}

// Load and initialize plugin constants.
require_once plugin_dir_path( __FILE__ ) . 'Admin/Config/Constants.php';
Tailwatch\Admin\Config\Constants::init();

// Load bootstrap class and start the plugin.
require_once TAILWATCH_ADMIN_INCLUDES_DIR . 'Bootstrap.php';
new Tailwatch\Admin\Includes\Bootstrap();
