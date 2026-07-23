<?php
/**
 * Tailwatch - Plugin Constants
 *
 * Defines all constants used throughout the plugin.
 *
 * @package    Tailwatch
 * @subpackage Admin\Config
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants Class
 *
 * Handles definition of all plugin constants.
 * Uses global define() for backward compatibility while maintaining class-based structure.
 *
 */
class Constants {

	/**
	 * Initialize all plugin constants.
	 *
	 * @return void
	 */
	public static function init() {
		self::define_core_constants();
		self::define_directory_constants();
		self::define_api_constants();
		self::define_database_constants();
		self::define_environment_constants();
		self::define_version_constants();
		self::define_misc_constants();
		self::define_security_constants();
	}

	/**
	 * Define a constant if not already defined.
	 *
	 * @param string $wptw_constant_name  Constant name.
	 * @param mixed  $wptw_constant_value Constant value.
	 * @return void
	 */
	private static function define( $wptw_constant_name, $wptw_constant_value ) {
		if ( ! defined( $wptw_constant_name ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Dynamic constant definition.
			define( $wptw_constant_name, $wptw_constant_value );
		}
	}

	/**
	 * Define core plugin constants.
	 *
	 * @return void
	 */
	private static function define_core_constants() {
		// Main plugin file path.
		self::define( 'WPTW_FILE', WPTW_PLUGIN_FILE );

		// Plugin version number.
		self::define( 'WPTW_VERSION', '1.0.0' );

		// WordPress plugins directory path.
		self::define( 'WPTW_PLUGIN_DIR', WP_PLUGIN_DIR );

		// Plugin display name.
		self::define( 'WPTW_NAME', 'Tailwatch' );

		// Plugin URL path.
		self::define( 'WPTW_URI', plugin_dir_url( WPTW_PLUGIN_FILE ) );

		// Plugin directory path.
		self::define( 'WPTW_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) );
	}

	/**
	 * Define admin directory constants.
	 *
	 * @return void
	 */
	private static function define_directory_constants() {
		// Admin directory path.
		self::define( 'WPTW_ADMIN_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) . 'Admin/' );

		// Admin assets URL path.
		self::define( 'WPTW_ADMIN_ASSETS_URI', plugin_dir_url( WPTW_PLUGIN_FILE ) . 'Admin/Assets/' );

		// Admin assets directory path.
		self::define( 'WPTW_ADMIN_ASSETS_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) . 'Admin/Assets/' );

		// Admin includes directory path.
		self::define( 'WPTW_ADMIN_INCLUDES_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) . 'Admin/Includes/' );

		// Admin interface directory path.
		self::define( 'WPTW_ADMIN_INTERFACE_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) . 'Admin/Interface/' );

		// Admin app directory path.
		self::define( 'WPTW_ADMIN_APP_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) . 'Admin/App/' );

		// Admin API directory path.
		self::define( 'WPTW_ADMIN_API_DIR', plugin_dir_path( WPTW_PLUGIN_FILE ) . 'Admin/App/Api/' );
	}

	/**
	 * Define API-related constants.
	 *
	 * @return void
	 */
	private static function define_api_constants() {
		// API endpoint slug.
		self::define( 'WPTW_API_SLUG', 'wptw-json' );

		// API base URL for external services.
		self::define( 'WPTW_API_BASE_URL', 'https://api.wptailwatch.com' );

		// Central API relay endpoint. Push notifications and license verification
		self::define( 'WPTW_RELAY', WPTW_API_BASE_URL . '/wptw/relay' );

		// Plugin feedback endpoint.
		self::define( 'WPTW_PLUGIN_FEEDBACK', WPTW_API_BASE_URL . '/plugin-feedback/submit' );
	}

	/**
	 * Define database-related constants.
	 *
	 * @return void
	 */
	private static function define_database_constants() {
		// Settings database table name.
		self::define( 'WPTW_DB_TABLE_NAME', 'tw_settings' );

		// Logs database table name.
		self::define( 'WPTW_DB_LOGS_TABLE_NAME', 'tw_logs' );

		// File-integrity baseline (one row per file) + scan-history tables.
		self::define( 'WPTW_DB_FILEMON_BASELINE_TABLE', 'tw_filemon_baseline' );
		self::define( 'WPTW_DB_FILEMON_SCANS_TABLE', 'tw_filemon_scans' );
	}

	/**
	 * Define site and environment constants.
	 *
	 * @return void
	 */
	private static function define_environment_constants() {
		// Current site URL.
		self::define( 'WPTW_GET_SITE_URL', get_site_url() );

		// Plugin's slug-namespaced parent folder under wp-content.
		// All plugin-generated data lives under this parent — a folder named after the plugin slug.
		self::define( 'WPTW_CONTENT_DIR_BASE', WP_CONTENT_DIR . '/tailwatch' );
		self::define( 'WPTW_CONTENT_URL_BASE', content_url( '/tailwatch' ) );

		// Backup storage filesystem path and public URL.
		self::define( 'WPTW_BACKUP_DIR', WPTW_CONTENT_DIR_BASE . '/wptw-backup' );
		self::define( 'WPTW_BACKUP_URL', WPTW_CONTENT_URL_BASE . '/wptw-backup' );

		// Logs directory path.
		self::define( 'WPTW_LOGS_DIRECTORY', WPTW_CONTENT_DIR_BASE . '/wptw-logs' );
	}

	/**
	 * Define version requirement constants.
	 *
	 * @return void
	 */
	private static function define_version_constants() {
		// Minimum PHP version required.
		self::define( 'WPTW_PHP_VERSION', '7.4.0' );

		// Minimum WordPress version required.
		self::define( 'WPTW_WP_VERSION', '6.3.0' );
	}

	/**
	 * Define miscellaneous constants.
	 *
	 * @return void
	 */
	private static function define_misc_constants() {
		// Visit data option key.
		self::define( 'WPTW_VISIT_DATA', 'wptw_visit_data' );
	}

	/**
	 * Define security and authentication constants.
	 *
	 * @return void
	 */
	private static function define_security_constants() {
		// JWT authentication secret key.
		if ( ! defined( 'WPTW_JWT_AUTH_SECRET_KEY' ) ) {
			if ( defined( 'WPTW_JWT_SECRET_KEY' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Dynamic constant definition.
				define( 'WPTW_JWT_AUTH_SECRET_KEY', WPTW_JWT_SECRET_KEY );
			} else {
				$wptw_jwt_secret_key = get_option( 'wptw_jwt_secret_key' );
				if ( $wptw_jwt_secret_key ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Dynamic constant definition.
					define( 'WPTW_JWT_AUTH_SECRET_KEY', $wptw_jwt_secret_key );
				}
			}
		}

		// Encryption key for sensitive data.
		if ( ! defined( 'WPTW_ENCRYPTION_KEY' ) ) {
			if ( defined( 'WPTW_SECRET_ENCRYPTION_KEY' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Dynamic constant definition.
				define( 'WPTW_ENCRYPTION_KEY', WPTW_SECRET_ENCRYPTION_KEY );
			} else {
				$wptw_encryption_key = get_option( 'wptw_secret_encryption_key' );
				if ( $wptw_encryption_key ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Dynamic constant definition.
					define( 'WPTW_ENCRYPTION_KEY', $wptw_encryption_key );
				}
			}
		}
	}
}
