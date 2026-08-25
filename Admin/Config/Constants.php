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
	}

	/**
	 * Define a constant if not already defined.
	 *
	 * @param string $tailwatch_constant_name  Constant name.
	 * @param mixed  $tailwatch_constant_value Constant value.
	 * @return void
	 */
	private static function define( $tailwatch_constant_name, $tailwatch_constant_value ) {
		if ( ! defined( $tailwatch_constant_name ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Dynamic constant definition.
			define( $tailwatch_constant_name, $tailwatch_constant_value );
		}
	}

	/**
	 * Define core plugin constants.
	 *
	 * @return void
	 */
	private static function define_core_constants() {
		// Main plugin file path.
		self::define( 'TAILWATCH_FILE', TAILWATCH_PLUGIN_FILE );

		// Plugin version number.
		self::define( 'TAILWATCH_VERSION', '1.0.1' );

		// WordPress plugins directory path.
		self::define( 'TAILWATCH_PLUGIN_DIR', WP_PLUGIN_DIR );

		// Plugin display name.
		self::define( 'TAILWATCH_NAME', 'Tailwatch' );

		// Plugin URL path.
		self::define( 'TAILWATCH_URI', plugin_dir_url( TAILWATCH_PLUGIN_FILE ) );

		// Plugin directory path.
		self::define( 'TAILWATCH_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) );
	}

	/**
	 * Define admin directory constants.
	 *
	 * @return void
	 */
	private static function define_directory_constants() {
		// Admin directory path.
		self::define( 'TAILWATCH_ADMIN_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) . 'Admin/' );

		// Admin assets URL path.
		self::define( 'TAILWATCH_ADMIN_ASSETS_URI', plugin_dir_url( TAILWATCH_PLUGIN_FILE ) . 'Admin/Assets/' );

		// Admin assets directory path.
		self::define( 'TAILWATCH_ADMIN_ASSETS_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) . 'Admin/Assets/' );

		// Admin includes directory path.
		self::define( 'TAILWATCH_ADMIN_INCLUDES_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) . 'Admin/Includes/' );

		// Admin interface directory path.
		self::define( 'TAILWATCH_ADMIN_INTERFACE_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) . 'Admin/Interface/' );

		// Admin app directory path.
		self::define( 'TAILWATCH_ADMIN_APP_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) . 'Admin/App/' );

		// Admin API directory path.
		self::define( 'TAILWATCH_ADMIN_API_DIR', plugin_dir_path( TAILWATCH_PLUGIN_FILE ) . 'Admin/App/Api/' );
	}

	/**
	 * Define API-related constants.
	 *
	 * @return void
	 */
	private static function define_api_constants() {
		// API base URL for external services.
		self::define( 'TAILWATCH_API_BASE_URL', 'https://api.wptailwatch.com' );

		// Central API relay endpoint. Push notifications and license verification
		self::define( 'TAILWATCH_RELAY', TAILWATCH_API_BASE_URL . '/tailwatch/relay' );

		// Plugin feedback endpoint.
		self::define( 'TAILWATCH_PLUGIN_FEEDBACK', TAILWATCH_API_BASE_URL . '/plugin-feedback/submit' );

		// Public Tailwatch service URLs surfaced in the React dashboard. Handed to the app
		// via wp_localize_script (see InterfaceController::get_service_urls) so PHP and the
		// bundled app link to the same properties. Campaign (utm_*) parameters are appended
		// by each link position in the app, not stored here.
		self::define( 'TAILWATCH_WEBSITE_URL', 'https://wptailwatch.com' );
		self::define( 'TAILWATCH_DASHBOARD_URL', 'https://dashboard.wptailwatch.com' );
		self::define( 'TAILWATCH_DOCS_URL', 'https://doc.wptailwatch.com' );
		self::define( 'TAILWATCH_PRICING_URL', TAILWATCH_WEBSITE_URL . '/pricing/' );
		self::define( 'TAILWATCH_ROADMAP_URL', TAILWATCH_WEBSITE_URL . '/roadmap/' );
		self::define( 'TAILWATCH_CONTACT_URL', TAILWATCH_WEBSITE_URL . '/contact/' );
		self::define( 'TAILWATCH_PRIVACY_POLICY_URL', TAILWATCH_WEBSITE_URL . '/privacy-policy' );
		self::define( 'TAILWATCH_SUPPORT_EMAIL', 'support@wptailwatch.com' );
	}

	/**
	 * Define database-related constants.
	 *
	 * @return void
	 */
	private static function define_database_constants() {
		// Settings database table name.
		self::define( 'TAILWATCH_DB_TABLE_NAME', 'tw_settings' );

		// Logs database table name.
		self::define( 'TAILWATCH_DB_LOGS_TABLE_NAME', 'tw_logs' );

		// File-integrity baseline (one row per file) + scan-history tables.
		self::define( 'TAILWATCH_DB_FILEMON_BASELINE_TABLE', 'tw_filemon_baseline' );
		self::define( 'TAILWATCH_DB_FILEMON_SCANS_TABLE', 'tw_filemon_scans' );

		// Database schema version. Bump only when the table structure changes;
		// activation runs dbDelta only when the stored version differs from this.
		self::define( 'TAILWATCH_DB_VERSION', '1.0.0' );
	}

	/**
	 * Define site and environment constants.
	 *
	 * @return void
	 */
	private static function define_environment_constants() {
		// Current site URL.
		self::define( 'TAILWATCH_GET_SITE_URL', get_site_url() );

		// Backup and migration storage: a slug-named folder in wp-content, kept
		// independent of the per-site uploads directory.
		self::define( 'TAILWATCH_CONTENT_DIR_BASE', WP_CONTENT_DIR . '/tailwatch' );
		self::define( 'TAILWATCH_CONTENT_URL_BASE', content_url( '/tailwatch' ) );

		// Backup storage filesystem path and public URL.
		self::define( 'TAILWATCH_BACKUP_DIR', TAILWATCH_CONTENT_DIR_BASE . '/tailwatch-backup' );
		self::define( 'TAILWATCH_BACKUP_URL', TAILWATCH_CONTENT_URL_BASE . '/tailwatch-backup' );

		// Logs and generated data live in a slug-named folder inside the uploads
		// directory, resolved through wp_get_upload_dir() so custom upload
		// locations are honored.
		$uploads      = wp_get_upload_dir();
		$uploads_base = wp_normalize_path( $uploads['basedir'] );
		self::define( 'TAILWATCH_LOGS_DIRECTORY', trailingslashit( $uploads_base ) . 'tailwatch/tailwatch-logs' );
	}

	/**
	 * Define version requirement constants.
	 *
	 * @return void
	 */
	private static function define_version_constants() {
		// Minimum PHP version required.
		self::define( 'TAILWATCH_PHP_VERSION', '7.4.0' );

		// Minimum WordPress version required.
		self::define( 'TAILWATCH_WP_VERSION', '6.3.0' );
	}

	/**
	 * Define miscellaneous constants.
	 *
	 * @return void
	 */
	private static function define_misc_constants() {
		// Visit data option key.
		self::define( 'TAILWATCH_VISIT_DATA', 'tailwatch_visit_data' );
	}
}
