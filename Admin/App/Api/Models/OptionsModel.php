<?php
/**
 * Tailwatch - Options Model
 *
 * Handles Plugin Data Initialization and Management.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Models
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Users\UserRolesController;

class OptionsModel {

	private $wp_admin_slug;
	private $wp_param_slug;
	private $wp_author_slug;

	/**
	 * Constructor.
	 *
	 * Initializes hooks for database row insertion.
	 *
	 */
	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'tailwatch_inserting_rows_into_database', array( $this, 'insert_site_data' ) );
	}

	/**
	 * Handle plugin activation tasks.
	 *
	 * Creates necessary database tables and sets default options.
	 *
	 * @return void
	 */
	public function tailwatch_upon_activation() {
		global $wpdb;
		$settings_table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;
		$logs_table_name     = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$baseline_table  	 = $wpdb->prefix . TAILWATCH_DB_FILEMON_BASELINE_TABLE;
		$scans_table         = $wpdb->prefix . TAILWATCH_DB_FILEMON_SCANS_TABLE;
		$charset_collate     = $wpdb->get_charset_collate();

		// dbDelta() requires the table name to be interpolated directly into the
		// SQL string — it parses the CREATE TABLE statement itself and does not
		// accept the %i placeholder. Both $settings_table_name and $logs_table_name
		// are built from $wpdb->prefix concatenated with code-literal constants,
		// so there is no user input on the SQL path.
		$create_tables = array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- dbDelta requires literal table-name interpolation; both names derived from $wpdb->prefix + code-literal constants.
			"CREATE TABLE $settings_table_name (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT,
                child_of INT,
                `key` VARCHAR(255),
                `option` VARCHAR(255),
                `value` LONGTEXT,
                type VARCHAR(255),
                type_state VARCHAR(255),
                date_created VARCHAR(255),
                date_modified VARCHAR(255),
                is_active TINYINT(1) DEFAULT 0,
                PRIMARY KEY  (id),
                KEY idx_process_monitor (`key`(50), `option`(100), date_modified)
            ) $charset_collate;",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- dbDelta requires literal table-name interpolation; both names derived from $wpdb->prefix + code-literal constants.
			"CREATE TABLE $logs_table_name (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT,
                child_of INT,
                `key` VARCHAR(255),
                `option` VARCHAR(255),
                `value` LONGTEXT,
                type VARCHAR(255),
                type_state VARCHAR(255),
                ip_address VARCHAR(45),
                username VARCHAR(100),
                action VARCHAR(100),
                facet_1 VARCHAR(191),
                facet_2 VARCHAR(191),
                date_created DATETIME,
                date_modified DATETIME,
                is_active TINYINT(1) DEFAULT 0,
                PRIMARY KEY  (id),
                KEY idx_process_recovery (`key`(50), `option`(100), date_created),
                KEY idx_logs_username (`option`(50), username, id),
                KEY idx_logs_ip (`option`(50), ip_address, id),
                KEY idx_logs_action (`option`(50), action, id),
                KEY idx_logs_facet1 (`option`(50), facet_1(100), id)
            ) $charset_collate;",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- dbDelta requires literal table-name interpolation; name = $wpdb->prefix + code-literal constant.
			"CREATE TABLE $baseline_table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				file_path_hash CHAR(32) NOT NULL,
				file_path TEXT NOT NULL,
				file_name VARCHAR(255) NOT NULL DEFAULT '',
				file_hash CHAR(64) NOT NULL DEFAULT '',
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				file_modified DATETIME NULL DEFAULT NULL,
				file_permission VARCHAR(8) NOT NULL DEFAULT '',
				monitored_path VARCHAR(191) NOT NULL DEFAULT '',
				last_seen_scan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				comparison_status TINYINT(1) NOT NULL DEFAULT 0,
				date_created DATETIME NULL DEFAULT NULL,
				date_modified DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_path_hash (file_path_hash),
				KEY idx_last_seen (last_seen_scan_id, monitored_path),
				KEY idx_monitored (monitored_path)
			) $charset_collate;",
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- dbDelta requires literal table-name interpolation; name = $wpdb->prefix + code-literal constant.
			"CREATE TABLE $scans_table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_id BIGINT UNSIGNED NOT NULL,
				monitored_path VARCHAR(191) NOT NULL DEFAULT '',
				folder_name VARCHAR(255) NOT NULL DEFAULT '',
				scan_time DATETIME NULL DEFAULT NULL,
				is_completed TINYINT(1) NOT NULL DEFAULT 0,
				malware_scan VARCHAR(8) NOT NULL DEFAULT 'No',
				malware_status VARCHAR(64) NOT NULL DEFAULT '',
				suspicious_handling VARCHAR(8) NOT NULL DEFAULT 'No',
				old_total_scan_files BIGINT UNSIGNED NOT NULL DEFAULT 0,
				new_scan_file BIGINT UNSIGNED NOT NULL DEFAULT 0,
				total_captured_file BIGINT UNSIGNED NOT NULL DEFAULT 0,
				added_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
				modified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
				deleted_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
				folder_files_ref VARCHAR(255) NULL DEFAULT NULL,
				date_created DATETIME NULL DEFAULT NULL,
				date_modified DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uq_scan_id (scan_id),
				KEY idx_scan_time (scan_time),
				KEY idx_completed_time (is_completed, scan_time)
			) $charset_collate;",
		);

		// Only run the schema build when the stored schema version differs from the
		// current one. dbDelta re-parses every CREATE TABLE definition to diff it
		// against the live tables on each call, so gating it keeps routine
		// re-activations from repeating that work once the schema is already current.
		if ( get_option( 'tailwatch_db_version' ) !== TAILWATCH_DB_VERSION ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			foreach ( $create_tables as $sql ) {
				dbDelta( $sql );
			}

			update_option( 'tailwatch_db_version', TAILWATCH_DB_VERSION, false );
		}

		add_option( 'tailwatch_plugin_activation_redirect', true, '', false );

		// Seed operational options as NON-autoloaded. They are read only on specific
		// admin screens (Cron Manager), so they must not load on every page request.
		// Creating them here means later update_option() calls preserve the non-autoload
		// state (autoload is only re-evaluated on creation).
		add_option( 'tailwatch_paused_cron_jobs', array(), '', false );
		add_option( 'tailwatch_custom_schedules', array(), '', false );
	}

	/**
	 * Get WP Admin Slug.
	 *
	 * @return string Random generated slug.
	 */
	public function get_wp_admin_slug() {
		if ( empty( $this->wp_admin_slug ) ) {
			$this->wp_admin_slug = $this->tailwatch_generate_random_string();
		}
		return $this->wp_admin_slug;
	}

	/**
	 * Get WP Param Slug.
	 *
	 * @return string Random generated slug.
	 */
	public function get_wp_param_slug() {
		if ( empty( $this->wp_param_slug ) ) {
			$this->wp_param_slug = $this->tailwatch_generate_random_string();
		}
		return $this->wp_param_slug;
	}

	/**
	 * Get WP Author Slug.
	 *
	 * @return string Random generated slug.
	 */
	public function get_wp_author_slug() {
		if ( empty( $this->wp_author_slug ) ) {
			$this->wp_author_slug = $this->tailwatch_generate_random_string();
		}
		return $this->wp_author_slug;
	}

	/**
	 * Generate a random string.
	 *
	 * @param int $length Length of the string. Default 6.
	 * @return string Generated random string.
	 */
	public function tailwatch_generate_random_string( $length = 6 ) {
		$characters        = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$characters_length = strlen( $characters );
		$random_string     = '';

		$random_length = wp_rand( 5, $length );
		for ( $i = 0; $i < $random_length; $i++ ) {
			$random_string .= $characters[ wp_rand( 0, $characters_length - 1 ) ];
		}
		return $random_string;
	}

	/**
	 * Compile complete site data for initialization.
	 *
	 * @return array Site settings and configuration data.
	 */
	public function tailwatch_complete_site_data() {
		$roles_controller = new UserRolesController();
		$role_options     = $roles_controller->tailwatch_generate_role_options();
		$user_id          = get_current_user_id();

		return array(
			'site_settings'                  => array(
				'user_id'       => $user_id,
				'child_of'      => '1',
				'key'           => 'plugin_activation',
				'option'        => 'site_settings',
				'value'         => wp_json_encode(
					array(
                        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variables sanitized below
						'user_ip'                         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
                        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variables sanitized below
						'user_browser'                    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
						'wordpress_version'               => get_bloginfo( 'version' ),
						'site_url'                        => site_url(),
						'home_url'                        => home_url(),
						'wp_admin_url'                    => admin_url(),
						'admin_email'                     => get_option( 'admin_email' ),
						'blog_name'                       => get_option( 'blogname' ),
						'blog_description'                => get_option( 'blogdescription' ),
						'date_format'                     => get_option( 'date_format' ),
						'time_format'                     => get_option( 'time_format' ),
						'permalink_structure'             => get_option( 'permalink_structure' ),
						'template'                        => get_option( 'template' ),
						'stylesheet'                      => get_option( 'stylesheet' ),
						'activate_plugins'                => get_option( 'active_plugins' ),
						'uninstall_plugins'               => get_option( 'uninstall_plugins' ),
						'moderation_keys'                 => '',
						'default_role'                    => get_option( 'default_role' ),
						'site_icon'                       => '',
						'upload_path'                     => wp_upload_dir()['basedir'],
						'auto_plugin_theme_update_emails' => '',
						'auto_update_core_dev'            => '',
						'auto_update_core_minor'          => '',
						'auto_update_core_major'          => '',
						'wp_force_deactivated_plugins'    => '',
						'db_version'                      => '1.0',
						'initial_db_version'              => '1.0',
						'mobile_notification'             => true,
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Files Permission Controller
			'default_files_and_permission'   => array(
				'user_id'       => $user_id,
				'child_of'      => 1,
				'key'           => 'default_feature_settings',
				'option'        => 'default_files_and_permission',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Files & Permissions',
						'category'            => array( 'security' ),
						'description'         => 'This feature allows you to secure WordPress default directories such as wp-content, uploads, wp-admin, wp-includes, themes, and plugins by preventing unauthorized file execution. It also includes removing WordPress meta information, minifying frontend HTML, masking site identity, and more to strengthen overall site security.',
						'verify_status'       => 'check_files_permission',
						'recommended_feature' => true,
						'display_as_tabs'     => true,
						'tab_config'          => array(
							array(
								'field_ids' => array( 'field_1', 'field_2' ),
								'tab_title' => 'Editors',
								'tab_icon'  => 'code',
							),
							array(
								'field_ids' => array( 'field_7', 'field_16' ),
								'tab_title' => 'Files & Directory Access',
								'tab_icon'  => 'folder',
							),
							array(
								'field_ids' => array( 'field_10', 'field_12', 'field_13', 'field_20' ),
								'tab_title' => 'Info Disclosure',
								'tab_icon'  => 'eye-slash',
							),
							array(
								'field_ids' => array( 'field_21', 'field_22', 'field_23' ),
								'tab_title' => 'Scripts & Feeds',
								'tab_icon'  => 'file-code',
							),
						),
						'options'             => array(
							// Editors
							'field_1'         => array(
								'key'         => 'disable_file_editor_in_plugins',
								'id'          => 'disable_file_editor_in_plugins',
								'type'        => 'checkbox',
								'label'       => 'Disable File Editor in Plugins',
								'description' => 'Prevents users with the edit_plugins capability from accessing the plugin editor.',
								'placeholder' => 'Disable File Editor in Plugins',
								'required'    => true,
								'register'    => 'disable_file_editor_in_plugins',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_2'         => array(
								'key'         => 'disable_file_editor_in_themes',
								'id'          => 'disable_file_editor_in_themes',
								'type'        => 'checkbox',
								'label'       => 'Disable File Editor in Themes',
								'description' => 'Prevents users with the edit_themes capability from accessing the theme editor.',
								'placeholder' => 'Disable File Editor in Themes',
								'required'    => true,
								'register'    => 'disable_file_editor_in_themes',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							// Files & Directory Access
							'field_7'         => array(
								'key'         => 'disable_access_to_xmlrpc',
								'id'          => 'disable_access_to_xmlrpc',
								'type'        => 'checkbox',
								'label'       => 'Disable access to xmlrpc.php',
								'description' => 'Prevents access to xmlrpc.php, which is often targeted by attackers.',
								'placeholder' => 'Disable access to xmlrpc.php',
								'required'    => true,
								'register'    => 'disable_access_to_xmlrpc',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_16'        => array(
								'key'         => 'restrict_author_archive_access',
								'id'          => 'restrict_author_archive_access',
								'type'        => 'checkbox',
								'label'       => 'Restrict Author Archive Access',
								'description' => 'Redirects users away from author archive pages to the homepage to prevent user enumeration.',
								'placeholder' => 'Restrict Author Archive Access',
								'required'    => true,
								'register'    => 'restrict_author_archive_access',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),

							// Info Disclosure
							'field_10'        => array(
								'key'         => 'remove_headers',
								'id'          => 'remove_headers',
								'type'        => 'checkbox',
								'label'       => 'Hide Server Information Headers',
								'description' => 'Removes X-Powered-By, X-Pingback, X-WP-Total and X-WP-TotalPages response headers to reduce information disclosure. The plugin also attempts to remove the Server header via PHP — this works on some setups but can be overridden by the web server core. If the Server header still exposes version info after enabling this option, apply server-level configuration: Apache → add "ServerTokens Prod" and "ServerSignature Off" to httpd.conf; nginx → add "server_tokens off;" to nginx.conf; LiteSpeed → disable "Use Server Header" in admin panel. These server-level settings require main-config access (cannot be done via .htaccess).',
								'placeholder' => 'Hide Server Information Headers',
								'required'    => true,
								'register'    => 'remove_headers',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_12'        => array(
								'key'         => 'remove_meta_tags',
								'id'          => 'remove_meta_tags',
								'type'        => 'checkbox',
								'label'       => 'Remove Meta Tags',
								'description' => 'Removes wlwmanifest, RSD, and shortlink meta tags.',
								'placeholder' => 'Remove Meta Tags',
								'required'    => true,
								'register'    => 'remove_meta_tags',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_13'        => array(
								'key'         => 'remove_html_comments',
								'id'          => 'remove_html_comments',
								'type'        => 'checkbox',
								'label'       => 'Remove HTML Comments & Minify',
								'description' => 'Removes HTML comments and minifies HTML to hide version info and other sensitive data.',
								'placeholder' => 'Remove HTML Comments & Minify',
								'required'    => true,
								'register'    => 'remove_html_comments',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							// Scripts & Feeds
							'field_20'        => array(
								'key'         => 'remove_wp_version_information',
								'id'          => 'remove_wp_version_information',
								'type'        => 'checkbox',
								'label'       => 'Remove WordPress Version Information',
								'description' => 'Hides the WordPress version number from scripts, styles, and headers.',
								'placeholder' => 'Remove WordPress Version Information',
								'required'    => true,
								'register'    => 'remove_wp_version_information',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_21'        => array(
								'key'         => 'disable_emoji',
								'id'          => 'disable_emoji',
								'type'        => 'checkbox',
								'label'       => 'Disable Emoji',
								'description' => 'Removes WordPress Emoji scripts and styles.',
								'placeholder' => 'Disable Emoji',
								'required'    => true,
								'register'    => 'disable_emoji',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_22'        => array(
								'key'         => 'disable_oembed',
								'id'          => 'disable_oembed',
								'type'        => 'checkbox',
								'label'       => 'Disable oEmbed',
								'description' => 'Disables oEmbed discovery links and scripts.',
								'placeholder' => 'Disable oEmbed',
								'required'    => true,
								'register'    => 'disable_oembed',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_23'        => array(
								'key'         => 'disable_feeds',
								'id'          => 'disable_feeds',
								'type'        => 'checkbox',
								'label'       => 'Disable RSS Feeds',
								'description' => 'Disables RSS, Atom, and RDF feeds.',
								'placeholder' => 'Disable RSS Feeds',
								'required'    => true,
								'register'    => 'disable_feeds',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Log Activity Controller
			'default_log_activity'           => array(
				'user_id'       => $user_id,
				'child_of'      => 1,
				'key'           => 'default_feature_settings',
				'option'        => 'default_log_activity',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Activity Logs',
						'category'            => array( 'monitoring' ),
						'description'         => 'Track all user activity, including logins, logouts, signups, and password reset attempts, with full visibility and optional smartphone notifications for security and auditing.',
						'verify_status'       => 'check_log_activity',
						'mobile_notification' => true,
						'recommended_feature' => true,
						'display_as_tabs'     => true,
						'tab_config'          => array(
							array(
								'field_ids' => array( 'field_1', 'field_2', 'field_3', 'field_4', 'field_5', 'field_6', 'field_7', 'field_8' ),
								'tab_title' => 'Accessing Logs',
								'tab_icon'  => 'shield',
							),
						),
						'options'             => array(
							'field_1' => array(
								'key'               => 'Login',
								'id'                => 'Login',
								'push_notification' => true,
								'push_notification_title'       => 'User Logged In',
								'push_notification_description' => 'You will receive a notification on your mobile each time a user successfully logs into your site.',
								'type'              => 'checkbox',
								'label'             => 'Login',
								'description'       => 'Logs when a user successfully logs into the site.',
								'placeholder'       => 'Login',
								'required'          => true,
								'register'          => 'Login',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_2' => array(
								'key'               => 'Logout',
								'id'                => 'Logout',
								'push_notification' => true,
								'push_notification_title'       => 'User Logged Out',
								'push_notification_description' => 'You will receive a notification on your mobile each time a user logs out of your site.',
								'type'              => 'checkbox',
								'label'             => 'Logout',
								'description'       => 'Logs when a user logs out.',
								'placeholder'       => 'Logout',
								'required'          => false,
								'register'          => 'Logout',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_3' => array(
								'key'               => 'Login-failed',
								'id'                => 'Login-failed',
								'push_notification' => true,
								'push_notification_title'       => 'Failed Login Attempt',
								'push_notification_description' => 'You will receive a notification on your mobile when a failed login attempt is detected on your site.',
								'type'              => 'checkbox',
								'label'             => 'Failed Login',
								'description'       => 'Logs unsuccessful login attempts by valid users.',
								'placeholder'       => 'Login-failed',
								'required'          => true,
								'register'          => 'Login-failed',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_4' => array(
								'key'               => 'Non-existing-user',
								'id'                => 'Non-existing-user',
								'push_notification' => true,
								'push_notification_title'       => 'Non-Existent User Login Attempt',
								'push_notification_description' => 'You will receive a notification on your mobile when someone tries to log in with a username that does not exist on your site.',
								'type'              => 'checkbox',
								'label'             => 'Non-Existent Login Attempts',
								'description'       => 'Logs attempts to log in with usernames that do not exist.',
								'placeholder'       => 'Non-existing-user',
								'required'          => false,
								'register'          => 'Non-existing-user',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_5' => array(
								'key'               => 'User-Register',
								'id'                => 'User-Register',
								'push_notification' => true,
								'push_notification_title'       => 'New User Registered',
								'push_notification_description' => 'You will receive a notification on your mobile when a new user account is registered on your site.',
								'type'              => 'checkbox',
								'label'             => 'User Signups',
								'description'       => 'Logs when a new user account is registered.',
								'placeholder'       => 'User-Register',
								'required'          => false,
								'register'          => 'User-Register',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_6' => array(
								'key'               => 'Password-Reset',
								'id'                => 'Password-Reset',
								'push_notification' => true,
								'push_notification_title'       => 'Password Reset Requested',
								'push_notification_description' => 'You will receive a notification on your mobile when a user requests a password reset on your site.',
								'type'              => 'checkbox',
								'label'             => 'Password Reset Requests',
								'description'       => 'Logs when a user requests a password reset.',
								'placeholder'       => 'Password-Reset',
								'required'          => false,
								'register'          => 'Password-Reset',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_7' => array(
								'key'               => 'Password-Reset-Non-Existing-user',
								'id'                => 'Password-Reset-Non-Existing-user',
								'push_notification' => true,
								'push_notification_title'       => 'Reset Requested for Unknown User',
								'push_notification_description' => 'You will receive a notification on your mobile when someone requests a password reset for a username that does not exist.',
								'type'              => 'checkbox',
								'label'             => 'Non-Existent User Password Reset Requests',
								'description'       => 'Logs attempts to reset passwords for usernames that do not exist.',
								'placeholder'       => 'Password-Reset-Non-Existing-user',
								'required'          => false,
								'register'          => 'Password-Reset-Non-Existing-user',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_8' => array(
								'key'               => 'Password-Reset-Successful',
								'id'                => 'Password-Reset-Successful',
								'push_notification' => true,
								'push_notification_title'       => 'Password Reset Successful',
								'push_notification_description' => 'You will receive a notification on your mobile when a user successfully resets their password on your site.',
								'type'              => 'checkbox',
								'label'             => 'Password Reset Successful',
								'description'       => 'Logs when a user successfully resets their password.',
								'placeholder'       => 'Password-Reset-Successful',
								'required'          => false,
								'register'          => 'Password-Reset-Successful',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Error Log Controller
			'default_monitoring_logs'        => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_monitoring_logs',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Error Logs',
						'category'            => array( 'monitoring' ),
						'description'         => 'Catch site errors as they occur, including 500, 403, 404, and more, with real-time logging and instant mobile app notifications. Stay informed, respond faster, and minimize downtime.',
						'verify_status'       => 'check_monitering_log',
						'mobile_notification' => true,
						'recommended_feature' => true,
						'display_as_tabs'     => true,
						'tab_config'          => array(
							array(
								'field_ids' => array( 'field_1', 'field_2', 'field_5', 'field_6' ), // 5xx errors
								'tab_title' => '5xx Errors',
								'tab_icon'  => 'alert',
							),
							array(
								'field_ids' => array( 'field_4', 'field_7', 'field_8', 'field_9', 'field_10' ), // 4xx errors
								'tab_title' => '4xx Errors',
								'tab_icon'  => 'warning',
							),
						),
						'options'             => array(
							'field_1'  => array(
								'key'               => 'log_503_service_unavailable',
								'id'                => 'log_503_service_unavailable',
								'push_notification' => false,
								'push_notification_title'       => '503 – Service Unavailable',
								'push_notification_description' => 'You will receive a notification on your mobile when your site reports a 503 Service Unavailable error caused by overload or maintenance.',
								'type'              => 'checkbox',
								'label'             => '503 – Service Unavailable',
								'description'       => 'Logs errors caused by temporary overload or maintenance.',
								'placeholder'       => 'Log 503 Service Unavailable',
								'required'          => true,
								'register'          => 'log_503_service_unavailable',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_2'  => array(
								'key'               => 'log_500_internal_server_error',
								'id'                => 'log_500_internal_server_error',
								'push_notification' => false,
								'push_notification_title'       => '500 – Internal Server Error',
								'push_notification_description' => 'You will receive a notification on your mobile when your site encounters a 500 Internal Server Error indicating misconfiguration or runtime issues.',
								'type'              => 'checkbox',
								'label'             => '500 – Internal Server Error',
								'description'       => 'Logs unexpected server errors that may indicate misconfiguration or runtime issues.',
								'placeholder'       => 'Log 500 Internal Server Error',
								'required'          => true,
								'register'          => 'log_500_internal_server_error',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_4'  => array(
								'key'               => 'log_404_not_found',
								'id'                => 'log_404_not_found',
								'push_notification' => false,
								'push_notification_title'       => '404 – Not Found',
								'push_notification_description' => 'You will receive a notification on your mobile when a 404 Not Found error is logged for a missing resource on your site.',
								'type'              => 'checkbox',
								'label'             => '404 – Not Found',
								'description'       => 'Logs requests for resources that do not exist.',
								'placeholder'       => 'Log 404 Not Found',
								'required'          => true,
								'register'          => 'log_404_not_found',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_5'  => array(
								'key'               => 'log_502_bad_gateway',
								'id'                => 'log_502_bad_gateway',
								'push_notification' => false,
								'push_notification_title'       => '502 – Bad Gateway',
								'push_notification_description' => 'You will receive a notification on your mobile when a 502 Bad Gateway error is reported by an upstream server on your site.',
								'type'              => 'checkbox',
								'label'             => '502 – Bad Gateway',
								'description'       => 'Logs gateway or upstream server errors.',
								'placeholder'       => 'Log 502 Bad Gateway',
								'required'          => true,
								'register'          => 'log_502_bad_gateway',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_6'  => array(
								'key'               => 'log_504_gateway_timeout',
								'id'                => 'log_504_gateway_timeout',
								'push_notification' => false,
								'push_notification_title'       => '504 – Gateway Timeout',
								'push_notification_description' => 'You will receive a notification on your mobile when a 504 Gateway Timeout occurs because the server fails to respond in time.',
								'type'              => 'checkbox',
								'label'             => '504 – Gateway Timeout',
								'description'       => 'Logs timeout errors when the server fails to receive a timely response.',
								'placeholder'       => 'Log 504 Gateway Timeout',
								'required'          => true,
								'register'          => 'log_504_gateway_timeout',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_7'  => array(
								'key'               => 'log_403_forbidden',
								'id'                => 'log_403_forbidden',
								'push_notification' => false,
								'push_notification_title'       => '403 – Forbidden',
								'push_notification_description' => 'You will receive a notification on your mobile when a 403 Forbidden access attempt is denied on your site.',
								'type'              => 'checkbox',
								'label'             => '403 – Forbidden',
								'description'       => 'Logs denied access attempts.',
								'placeholder'       => 'Log 403 Forbidden',
								'required'          => true,
								'register'          => 'log_403_forbidden',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_8'  => array(
								'key'               => 'log_401_unauthorized',
								'id'                => 'log_401_unauthorized',
								'push_notification' => false,
								'push_notification_title'       => '401 – Unauthorized',
								'push_notification_description' => 'You will receive a notification on your mobile when a 401 Unauthorized request is made to a protected resource on your site.',
								'type'              => 'checkbox',
								'label'             => '401 – Unauthorized',
								'description'       => 'Logs requests requiring authentication.',
								'placeholder'       => 'Log 401 Unauthorized',
								'required'          => true,
								'register'          => 'log_401_unauthorized',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_9'  => array(
								'key'               => 'log_429_too_many_requests',
								'id'                => 'log_429_too_many_requests',
								'push_notification' => false,
								'push_notification_title'       => '429 – Too Many Requests',
								'push_notification_description' => 'You will receive a notification on your mobile when requests on your site are blocked due to rate limiting.',
								'type'              => 'checkbox',
								'label'             => '429 – Too Many Requests',
								'description'       => 'Logs requests blocked due to rate limiting.',
								'placeholder'       => 'Log 429 Too Many Requests',
								'required'          => true,
								'register'          => 'log_429_too_many_requests',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_10' => array(
								'key'               => 'log_413_payload_too_large',
								'id'                => 'log_413_payload_too_large',
								'push_notification' => false,
								'push_notification_title'       => '413 – Payload Too Large',
								'push_notification_description' => 'You will receive a notification on your mobile when a request to your site exceeds the allowed size limit.',
								'type'              => 'checkbox',
								'label'             => '413 – Payload Too Large',
								'description'       => 'Logs requests exceeding allowed size limits.',
								'placeholder'       => 'Log 413 Payload Too Large',
								'required'          => true,
								'register'          => 'log_413_payload_too_large',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Email Configuration Controller
			'default_email_configure'        => array(
				'user_id'       => $user_id,
				'child_of'      => 1,
				'key'           => 'default_feature_settings',
				'option'        => 'default_email_configure',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'category'            => array( 'optimization' ),
						'title'               => 'Email/SMTP Logs',
						'description'         => 'Track every email your site sends, whether successful or failed, with detailed logs to monitor delivery performance and troubleshoot issues.',
						'verify_status'       => 'check_email_logs',
						'display_as_tabs'     => true,
						'recommended_feature' => true,
						'tab_config'          => array(
							array(
								'field_ids' => array( 'field_1', 'field_2' ),
								'tab_title' => 'Email Logs',
								'tab_icon'  => 'shield',
							),
							array(
								'field_ids' => array( 'field_3' ),
								'tab_title' => 'Smtp Configure',
								'tab_icon'  => 'shield',
							),
						),
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'log_email_activity',
								'id'                => 'log_email_activity',
								'push_notification' => true,
								'push_notification_title'       => 'Email Activity Logged',
								'push_notification_description' => 'You will receive a notification on your mobile each time an email is sent from your site, including recipient, subject, and delivery details.',
								'type'              => 'checkbox',
								'label'             => 'Log Email Activity',
								'description'       => 'Logs email sending activities including recipient, subject, message, headers, and attachments.',
								'placeholder'       => 'Log Email Activity',
								'required'          => true,
								'register'          => 'log_email_activity',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_2' => array(
								'key'               => 'log_email_error',
								'id'                => 'log_email_error',
								'push_notification' => true,
								'push_notification_title'       => 'Email Sending Failed',
								'push_notification_description' => 'You will receive a notification on your mobile each time an email fails to send from your site, with full error details.',
								'type'              => 'checkbox',
								'label'             => 'Log Email Error',
								'description'       => 'Logs email sending errors including recipient, subject, message, headers, attachments, and error details.',
								'placeholder'       => 'Log Email Error',
								'required'          => true,
								'register'          => 'log_email_error',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_3' => array(
								'key'               => 'smtp_provider',
								'id'                => 'smtp_provider',
								'push_notification' => true,
								'push_notification_title'       => 'SMTP Provider Updated',
								'push_notification_description' => 'You will receive a notification on your mobile when the SMTP provider used for sending emails is changed on your site.',
								'type'              => 'radio',
								'label'             => 'SMTP Provider',
								'display'           => 'tab_view',
								'icons'             => 'show',
								'description'       => 'Select the email service provider for sending emails.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option'  => array(
										'value'       => 'default',
										'icon'        => 'phpmailer',
										'selected'    => true,
										'sub_options' => array(
											'field_4' => array(
												'key'      => 'default_from_email',
												'id'       => 'default_from_email',
												'push_notification' => true,
												'push_notification_title'       => 'Default From Email Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the default From Email address used for outgoing emails is changed.',
												'type'     => 'email',
												'label'    => 'From Email',
												'description' => 'The email address emails will be sent from.',
												'placeholder' => 'wordpress@example.com',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => get_option( 'admin_email' ),
														'selected' => false,
													),
												),
											),
											'field_5' => array(
												'key'      => 'default_from_name',
												'id'       => 'default_from_name',
												'push_notification' => true,
												'push_notification_title'       => 'Default From Name Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the default From Name shown to email recipients is changed.',
												'type'     => 'input',
												'label'    => 'From Name',
												'description' => 'The name displayed as the sender.',
												'placeholder' => 'WordPress',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => get_bloginfo(),
														'selected' => false,
													),
												),
											),
										),
									),
									'option2' => array(
										'value'       => 'custom',
										'icon'        => 'custom',
										'selected'    => false,
										'sub_options' => array(
											'field_6'  => array(
												'key'      => 'custom_host',
												'id'       => 'custom_host',
												'push_notification' => true,
												'push_notification_title'       => 'SMTP Host Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the custom SMTP host used for sending emails is changed.',
												'type'     => 'input',
												'label'    => 'SMTP Host',
												'description' => 'The SMTP server hostname.',
												'placeholder' => 'smtp.example.com',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '',
														'selected' => false,
													),
												),
											),
											'field_7'  => array(
												'key'      => 'custom_port',
												'id'       => 'custom_port',
												'push_notification' => true,
												'push_notification_title'       => 'SMTP Port Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the custom SMTP port used to connect to the mail server is changed.',
												'type'     => 'input',
												'label'    => 'SMTP Port',
												'description' => 'The port to connect to the SMTP server.',
												'placeholder' => '587',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '',
														'selected' => false,
													),
												),
											),
											'field_8'  => array(
												'key'      => 'custom_username',
												'id'       => 'custom_username',
												'push_notification' => true,
												'push_notification_title'       => 'SMTP Username Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the SMTP authentication username is updated on your site.',
												'type'     => 'input',
												'label'    => 'Username',
												'description' => 'The username for SMTP authentication.',
												'placeholder' => 'user@example.com',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '',
														'selected' => false,
													),
												),
											),
											'field_9'  => array(
												'key'      => 'custom_password',
												'id'       => 'custom_password',
												'push_notification' => true,
												'push_notification_title'       => 'SMTP Password Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the SMTP authentication password is updated on your site.',
												'type'     => 'password',
												'label'    => 'Password',
												'description' => 'The password for SMTP authentication.',
												'placeholder' => '',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '',
														'selected' => false,
													),
												),
											),
											'field_10' => array(
												'key'      => 'custom_encryption',
												'id'       => 'custom_encryption',
												'push_notification' => true,
												'push_notification_title'       => 'SMTP Encryption Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the SMTP encryption method (TLS/SSL) is changed on your site.',
												'type'     => 'select',
												'label'    => 'Encryption',
												'description' => 'The encryption method for the SMTP connection.',
												'placeholder' => '',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => 'none',
														'selected' => false,
													),
													'option2' => array(
														'value' => 'tls',
														'selected' => true,
													),
													'option3' => array(
														'value' => 'ssl',
														'selected' => false,
													),
												),
											),
											'field_11' => array(
												'key'      => 'custom_allow_self_signed',
												'id'       => 'custom_allow_self_signed',
												'push_notification' => true,
												'push_notification_title'       => 'Self-Signed Certificate Setting Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the self-signed SSL certificate setting for SMTP is changed on your site.',
												'type'     => 'checkbox',
												'label'    => 'Allow Self-Signed Certificates',
												'description' => 'Allow self-signed SSL certificates.',
												'placeholder' => '',
												'required' => false,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '1',
														'selected' => true,
													),
												),
											),
											'field_12' => array(
												'key'      => 'custom_from_email',
												'id'       => 'custom_from_email',
												'push_notification' => true,
												'push_notification_title'       => 'Custom From Email Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the custom SMTP From Email address is changed on your site.',
												'type'     => 'email',
												'label'    => 'From Email',
												'description' => 'The email address emails will be sent from.',
												'placeholder' => 'user@example.com',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '',
														'selected' => false,
													),
												),
											),
											'field_13' => array(
												'key'      => 'custom_from_name',
												'id'       => 'custom_from_name',
												'push_notification' => true,
												'push_notification_title'       => 'Custom From Name Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the custom SMTP From Name shown to recipients is changed.',
												'type'     => 'input',
												'label'    => 'From Name',
												'description' => 'The name displayed as the sender.',
												'placeholder' => 'Custom User',
												'required' => true,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '',
														'selected' => false,
													),
												),
											),
											'field_14' => array(
												'key'      => 'custom_keep_alive',
												'id'       => 'custom_keep_alive',
												'push_notification' => true,
												'push_notification_title'       => 'SMTP Keep-Alive Updated',
												'push_notification_description' => 'You will receive a notification on your mobile when the SMTP keep-alive setting between sends is changed on your site.',
												'type'     => 'checkbox',
												'label'    => 'Keep SMTP Connection Alive',
												'description' => 'Keep the SMTP connection open between sends.',
												'placeholder' => '',
												'required' => false,
												'register' => '',
												'values'   => array(
													'option' => array(
														'value' => '1',
														'selected' => true,
													),
												),
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock more email providers with Tailwatch Pro',
								'description' => 'Tailwatch Pro adds Gmail (OAuth2 / XOAUTH2) and additional managed SMTP providers so you can connect a Google account in one click instead of configuring SMTP credentials by hand.',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_smtp',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Smart SSL Feature
			'default_verify_ssl'             => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_verify_ssl',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Smart SSL',
						'category'            => array( 'security' ),
						'description'         => 'Continuously monitor your domain’s SSL certificate and receive immediate alerts on your smartphone if it is missing, expired, or invalid. Keep your site secure at all times.',
						'verify_status'       => 'check_ssl',
						'recommended_feature' => true,
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'verify_ssl',
								'id'                => 'verify_ssl',
								'push_notification' => true,
								'push_notification_title'       => 'SSL Certificate Status Alert',
								'push_notification_description' => 'You will receive a notification on your mobile whenever your SSL certificate is missing, expired, invalid, or successfully verified.',
								'type'              => 'checkbox',
								'label'             => 'Activate Smart SSL Monitoring',
								'description'       => 'Activate automatic SSL checks to ensure your website certificate is valid and working.',
								'placeholder'       => 'SSL Tweaks',
								'required'          => true,
								'register'          => 'verify_ssl',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_2' => array(
										'key'         => 'verify_ssl_duration',
										'id'          => 'verify_ssl_duration',
										'type'        => 'radio',
										'label'       => 'SSL Check Frequency',
										'description' => 'Choose how often the SSL certificate status should be verified.',
										'display'     => 'tab_view',
										'placeholder' => 'Verify SSL',
										'required'    => true,
										'register'    => 'verify_ssl_duration',
										'values'      => array(
											'option'  => array(
												'value'    => '1 Day',
												'selected' => true,
											),
											'option2' => array(
												'value'    => 'Weekly',
												'selected' => false,
											),
											'option3' => array(
												'value'    => '15 Days',
												'selected' => false,
											),
											'option4' => array(
												'value'    => 'Monthly',
												'selected' => false,
											),
										),
									),
									'field_3' => array(
										'key'         => 'ssl_expiry_alert_thresholds',
										'id'          => 'ssl_expiry_alert_thresholds',
										'type'        => 'radio',
										'label'       => 'Expiry Alert Days',
										'description' => 'Choose when to receive expiry alerts before your SSL certificate expires.',
										'display'     => 'tab_view',
										'placeholder' => 'Expiry Alert Days',
										'required'    => false,
										'register'    => 'ssl_expiry_alert_thresholds',
										'values'      => array(
											'option'  => array(
												'value'    => '30,14,7,1',
												'selected' => true,
											),
											'option2' => array(
												'value'    => '60,30,14,7,1',
												'selected' => false,
											),
											'option3' => array(
												'value'    => '30,14,7',
												'selected' => false,
											),
											'option4' => array(
												'value'    => '14,7,1',
												'selected' => false,
											),
										),
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Backup Vault
			'default_backup_enable'          => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_backup_enable',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Backup Vault',
						'category'            => array( 'recovery' ),
						'col'                 => '1', // 2
						'description'         => 'Easily back up your full website files, database, or both, with scheduled backups to cloud or local storage. Features include automatic rotation, retention management, optimization, and one-click restoration. Monitor progress in real time with detailed logs and receive instant notifications on your smartphone.',
						'verify_status'       => 'check_backup_details',
						'recommended_feature' => true,
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'enable_backup',
								'id'                => 'enable_backup',
								'push_notification' => true,
								'push_notification_title'       => 'Auto Backup Activity',
								'push_notification_description' => 'You will receive a notification on your mobile each time an automatic backup runs, completes, or fails on your site.',
								'type'              => 'checkbox',
								'label'             => 'Activate Auto Backups',
								'description'       => 'Turn the auto backups on or off for this website.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_2' => array(
										'key'         => 'select_backup_type',
										'id'          => 'select_backup_type',
										'type'        => 'radio',
										'display'     => 'tab_view',
										'label'       => 'Backup Scope',
										'description' => 'Select which parts of your website to back up. Full Website includes both files and database, ensuring complete restoration if needed.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option3' => array(
												'label'    => 'Full Website',
												'value'    => 'Complete Backup',
												'selected' => true,
											),
										),
									),
									'field_3' => array(
										'key'         => 'select_backup_option',
										'id'          => 'select_backup_option',
										'type'        => 'radio',
										'display'     => 'tab_view',
										'label'       => 'Backup Destination',
										'description' => 'Choose where to store your backups.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => 'Local Server',
												'selected' => true,
											),
										),
									),
									'field_6' => array(
										'key'         => 'backup_interval',
										'id'          => 'backup_interval',
										'type'        => 'select',
										'label'       => 'Interval',
										'description' => 'Set how often backups should run automatically to ensure your website is always protected.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option3' => array(
												'value'    => 'Every 3 Days',
												'selected' => true,
											),
											'option4' => array(
												'value'    => 'Every 1 Week',
												'selected' => false,
											),
										),
									),
									'field_7' => array(
										'key'         => 'backup_maintain',
										'id'          => 'backup_maintain',
										'type'        => 'select',
										'label'       => 'Retention Policy',
										'description' => 'Define how long to keep your backups. Older backups will be automatically deleted based on this policy.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'label'    => 'Keep last 1 backup',
												'value'    => '1',
												'selected' => true,
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock more with Tailwatch Pro',
								'description' => 'Tailwatch Pro extends Backup Vault with: files-only and database-only backup modes; faster intervals (12-hour, 24-hour, and daily); custom retention (keep 2-9 or any custom number of backups); cloud storage integrations (Google Drive, Dropbox, AWS S3 Bucket, DigitalOcean Spaces); optimize-database-before-backup toggle; and one-click site restoration with pre-restore safety backup.',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_backup',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Malware Guard — promotional entry, no settings, renders as info card.
			'default_malware_scan'           => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_malware_scan',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Malware Guard',
						'category'            => array( 'security' ),
						'col'                 => '1',
						'description'         => 'AI-powered malware scanning with one-click remediation, automatic quarantine of detected threats, and pre-scan backup for safe recovery. Continuous monitoring scans only modified files after the initial baseline for minimal performance impact.',
						'is_upgrade_feature'  => true,
						'options'             => array(),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Get Database Optimization Controller
			'default_database_optimizer'     => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_database_optimizer',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Database Optimizer',
						'category'            => array( 'optimization' ),
						'description'         => 'Easily change your WordPress database table prefix in seconds to prevent automated SQL injection attacks. Secure your database and optimize it by removing unnecessary data such as post revisions, orphaned metadata, trashed items, expired transients, and log entries. Schedule automated cleanups to keep your site fast and efficient without using phpMyAdmin or writing any SQL queries manually.',
						'verify_status'       => 'check_db_optimize',
						'mobile_notification' => true,
						'recommended_feature' => true,
						'display_as_tabs'     => true,
						'tab_config'          => array(
							array(
								'field_ids' => array(
									'field_1',
									'field_3',
									'field_4',
									'field_5',
									'field_6',
									'field_7',
									'field_8',
									'field_9',
									'field_11',
									'field_12',
									'field_13',
									'field_14',
									'field_15',
									'field_16',
									'upgrade_notice',
								),
								'tab_title' => 'Optimize Settings',
								'tab_icon'  => 'database',
							),
						),
						'options'             => array(
							'field_1'  => array(
								'key'               => 'enable_database_optimizer',
								'id'                => 'enable_database_optimizer',
								'push_notification' => true,
								'push_notification_title'       => 'Database Optimization Run',
								'push_notification_description' => 'You will receive a notification on your mobile each time a database optimization runs or completes on your site.',
								'type'              => 'checkbox',
								'label'             => 'Activate Database Optimizer',
								'description'       => 'Turn the database optimization on or off for this website.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_3'  => array(
										'key'         => 'orphaned_post_meta',
										'id'          => 'orphaned_post_meta',
										'type'        => 'checkbox',
										'label'       => 'Orphaned Post Meta',
										'description' => 'Removes post meta entries that are no longer linked to any post.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_4'  => array(
										'key'         => 'auto_drafts',
										'id'          => 'auto_drafts',
										'type'        => 'checkbox',
										'label'       => 'Auto Drafts',
										'description' => 'Deletes unused auto-saved drafts.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_5'  => array(
										'key'         => 'trashed_posts',
										'id'          => 'trashed_posts',
										'type'        => 'checkbox',
										'label'       => 'Trashed Post',
										'description' => 'Permanently removes posts that are in the trash.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_6'  => array(
										'key'         => 'spam_comments',
										'id'          => 'spam_comments',
										'type'        => 'checkbox',
										'label'       => 'Spam Comments',
										'description' => 'Deletes comments marked as spam.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_7'  => array(
										'key'         => 'trashed_comments',
										'id'          => 'trashed_comments',
										'type'        => 'checkbox',
										'label'       => 'Trashed Comments',
										'description' => 'Removes comments permanently from the trash.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_8'  => array(
										'key'         => 'trackbacks_pingbacks',
										'id'          => 'trashed_backs',
										'type'        => 'checkbox',
										'label'       => 'Trackback/Pingbacks',
										'description' => 'Deletes trackbacks and pingbacks to reduce clutter.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_9'  => array(
										'key'         => 'expired_transients',
										'id'          => 'expired_transients',
										'type'        => 'checkbox',
										'label'       => 'Expired Transients',
										'description' => 'Removes expired temporary cache entries.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_11' => array(
										'key'         => 'logs_activity',
										'id'          => 'logs_activity',
										'type'        => 'checkbox',
										'label'       => 'Activity Logs',
										'description' => 'Cleans up old activity records stored in the database.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_12' => array(
										'key'         => 'email_logs',
										'id'          => 'email_logs',
										'type'        => 'checkbox',
										'label'       => 'Email Logs',
										'description' => 'Removes stored email logs to free up space.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_13' => array(
										'key'         => 'ajax_logs',
										'id'          => 'ajax_logs',
										'type'        => 'checkbox',
										'label'       => 'Network Logs',
										'description' => 'Deletes historical AJAX request logs.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_14' => array(
										'key'         => 'monitoring_logs',
										'id'          => 'monitoring_logs',
										'type'        => 'checkbox',
										'label'       => 'Error Logs',
										'description' => 'Cleans stored error log entries.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => '',
												'selected' => true,
											),
										),
									),
									'field_15' => array(
										'key'         => 'database_interval',
										'id'          => 'database_interval',
										'type'        => 'select',
										'label'       => 'Interval',
										'description' => 'Choose how often the database optimization should run automatically.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option3' => array(
												'value'    => 'Every 48 Hours',
												'selected' => true,
											),
											'option4' => array(
												'value'    => 'Every Week',
												'selected' => false,
											),
										),
									),
									'field_16' => array(
										'key'         => 'keep_revision',
										'id'          => 'keep_revision',
										'type'        => 'select',
										'label'       => 'Keep Revisions for days',
										'description' => 'Post revisions and logs older than the selected number of days will be removed automatically to optimized the database performance.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option'  => array(
												'value'    => '1 Day',
												'selected' => true,
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock more with Tailwatch Pro',
								'description' => 'Tailwatch Pro extends Database Optimizer with additional cleanup types (Post Revisions, All Transients), faster intervals (12-hour, 24-hour), and longer revision-retention options (2 Days, 7 Days, or any custom number of days).',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_db_optimizer',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			// Integrity Watch Controller
			'default_file_integrity_check'   => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_file_integrity_check',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'File Integrity Watch',
						'category'            => array( 'security' ),
						'col'                 => '1', // 2
						'description'         => 'Track every file change on your website in real time. Automatically scan modified files for malware at set intervals, compare before-and-after versions, and store change history for up to 24 hours or more. Detect unauthorized changes instantly and receive notifications directly on your smartphone.',
						'verify_status'       => 'check_file_integrity',
						'recommended_feature' => true,
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'enable_integrity_check',
								'id'                => 'enable_integrity_check',
								'push_notification' => true,
								'push_notification_title'       => 'File Integrity Change Detected',
								'push_notification_description' => 'You will receive a notification on your mobile when file integrity monitoring detects an added, modified, or deleted file on your site.',
								'type'              => 'checkbox',
								'label'             => 'Activate File Integrity Monitoring',
								'description'       => 'Turn the monitoring on or off for this website.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_2' => array(
										'key'         => 'monitoring_scope',
										'id'          => 'monitoring_scope',
										'type'        => 'radio',
										'label'       => 'Monitoring Scope',
										'description' => 'Select the scope of the file integrity monitoring.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option' => array(
												'value'    => 'Entire Website Files',
												'selected' => true,
											),
										),
									),
									'field_3' => array(
										'key'         => 'check_interval',
										'id'          => 'check_interval',
										'type'        => 'select',
										'label'       => 'Interval',
										'description' => 'Select how often the integrity check should run.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option4' => array(
												'value'    => 'Every 12 Hours',
												'selected' => true,
											),
											'option5' => array(
												'value'    => 'Every 24 Hours',
												'selected' => false,
											),
										),
									),
									'field_4' => array(
										'key'         => 'comparison_json_retention',
										'id'          => 'comparison_json_retention',
										'type'        => 'select',
										'label'       => 'History Retention',
										'description' => 'Control how long activity history is stored before it is automatically removed.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option'  => array(
												'value'    => '6 Hours',
												'selected' => true,
											),
											'option2' => array(
												'value'    => '12 Hours',
												'selected' => false,
											),
										),
									),
									'field_6' => array(
										'key'         => 'large_file_scanning',
										'id'          => 'large_file_scanning',
										'type'        => 'radio',
										'label'       => 'Large File Scanning',
										'description' => 'Files over 100 MB (such as backups, videos, or database exports) can slow down scans. Choose whether to fully scan them or skip them.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option'  => array(
												'value'    => 'Full scan (all files)',
												'selected' => true,
											),
											'option2' => array(
												'value'    => 'Skip files over 100 MB',
												'selected' => false,
											),
										),
									),
									'field_7' => array(
										'key'         => 'integrity_scan_mode',
										'id'          => 'integrity_scan_mode',
										'type'        => 'radio',
										'label'       => 'Scan Mode',
										'description' => 'Fast skips re-reading files whose size and modified time are unchanged since the last scan (much faster on large sites; any file is still re-checked the moment it changes). Thorough re-hashes every file on every scan for maximum certainty.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option'  => array(
												'value'    => 'Fast (recommended)',
												'selected' => true,
											),
											'option2' => array(
												'value'    => 'Thorough (hash every file)',
												'selected' => false,
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock more with Tailwatch Pro',
								'description' => 'Tailwatch Pro extends File Integrity Watch with faster scan intervals (hourly, every 3 hours, every 6 hours), longer history retention (18/24/30 hours and indefinite), and automatic suspicious-file scanning with auto-quarantine of detected threats.',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_file_integrity',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Hardening Audit Controller
			'default_hardening_audit'        => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_hardening_audit',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Hardening Audit',
						'category'            => array( 'security' ),
						'col'                 => '1',
						'description'         => 'Run a recurring security hardening audit against your WordPress site. Each scan inspects PHP, database, file permissions, debug exposure, SSL, REST API, XML-RPC, admin usernames, authentication keys, and pending updates, then stores a detailed report you can review at any time.',
						'verify_status'       => 'check_hardening_audit',
						'recommended_feature' => true,
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'enable_hardening_audit',
								'id'                => 'enable_hardening_audit',
								'push_notification' => true,
								'push_notification_title'       => 'Hardening Audit Completed',
								'push_notification_description' => 'You will receive a notification on your mobile each time a hardening audit completes or flags issues on your site.',
								'type'              => 'checkbox',
								'label'             => 'Activate Hardening Audit',
								'description'       => 'Turn the recurring hardening audit on or off for this website.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_2' => array(
										'key'         => 'audit_interval',
										'id'          => 'audit_interval',
										'type'        => 'select',
										'label'       => 'Audit Interval',
										'description' => 'Select how often the hardening audit should run.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option3' => array(
												'value'    => 'Every 24 Hours',
												'selected' => true,
											),
											'option4' => array(
												'value'    => 'Every 1 Week',
												'selected' => false,
											),
										),
									),
									'field_3' => array(
										'key'         => 'audit_report_retention',
										'id'          => 'audit_report_retention',
										'type'        => 'select',
										'label'       => 'Report Retention',
										'description' => 'Control how long completed audit reports are kept before they are automatically removed.',
										'placeholder' => '',
										'required'    => true,
										'register'    => '',
										'values'      => array(
											'option'  => array(
												'value'    => '24 Hours',
												'selected' => true,
											),
											'option2' => array(
												'value'    => '30 Hours',
												'selected' => false,
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock more with Tailwatch Pro',
								'description' => 'Tailwatch Pro extends Hardening Audit with faster audit intervals (every 6 hours, every 12 hours) and longer report retention (48/60/72 hours and indefinite).',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_hardening_audit',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Get Search Replace Controller
			'default_search_replace'         => array(
				'user_id'       => $user_id,
				'child_of'      => 1,
				'key'           => 'default_feature_settings',
				'option'        => 'default_search_replace',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Search & Replace',
						'category'            => array( 'optimization' ),
						'description'         => 'Search and replace any content across your website with ease. Perform safe dry runs before applying changes, making it perfect for migrations, domain updates, or bulk content modifications.',
						'verify_status'       => 'check_search_replace',
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'search_replace_key',
								'id'                => 'search_replace_id',
								'push_notification' => true,
								'push_notification_title'       => 'Search & Replace Performed',
								'push_notification_description' => 'You will receive a notification on your mobile each time a search and replace operation runs on your database tables.',
								'type'              => 'checkbox',
								'label'             => 'Search & Replace Strings',
								'description'       => 'This feature allows users to search for specific strings in the database and replace them with new values across selected tables.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// User Management — promotional entry, no settings, renders as info card.
			'advanced_user_access_control'   => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'advanced_user_access_control',
				'value'         => wp_json_encode(
					array(
						'icon'               => 'Icon',
						'title'              => 'User Management',
						'category'           => array( 'optimization' ),
						'description'        => 'Securely manage all WordPress users with password-free logins, inactivity alerts, role assignments, login-as-another-user with audit trail, and full control over login limits and redirects.',
						'is_upgrade_feature' => true,
						'options'            => array(),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// 301 Redirection Feature
			'default_redirection_manager'    => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_redirection_manager',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => '301 Redirection',
						'category'            => array( 'optimization' ),
						'description'         => 'Easily manage 301 redirects to fix broken links and preserve SEO rankings through a simple, seamless interface. Receive instant notifications on your smartphone for any required fixes.',
						'verify_status'       => 'check_redirection_manager',
						'options'             => array(
							'field_1' => array(
								'key'         => 'redirection_manager',
								'id'          => 'redirection_manager',
								'type'        => 'checkbox',
								'label'       => 'Enable Redirection',
								'description' => 'Automatically redirects old URLs to new ones using 301 status codes to preserve SEO and prevent broken links.',
								'placeholder' => 'Enable Redirection',
								'required'    => true,
								'register'    => 'redirection_manager',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Broken Links Controller
			'broken_link_checker_settings'   => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'broken_link_checker_settings',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Broken Links',
						'category'            => array( 'optimization' ),
						'description'         => 'Automatically scan your site for broken links at regular intervals and maintain a history of results. Instantly fix issues with one-click 301 redirect rules without manual effort or SEO impact. Receive immediate alerts on your smartphone for any required fixes.',
						'verify_status'       => 'check_broken_link_checker',
						'recommended_feature' => true,
						'mobile_notification' => true,
						'options'             => array(
							'field_1' => array(
								'key'               => 'enable_broken_link_checker',
								'id'                => 'enable_broken_link_checker',
								'push_notification' => true,
								'push_notification_title'       => 'Broken Links Detected',
								'push_notification_description' => 'You will receive a notification on your mobile each time a scheduled scan finds broken links on your site that need fixing.',
								'type'              => 'checkbox',
								'label'             => 'Auto Scan Broken Links',
								'description'       => 'Turn on to scan your site for broken links periodically.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => 'tailwatch_broken_link_checker',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_2' => array(
										'key'         => 'broken_link_scan_frequency',
										'id'          => 'broken_link_scan_frequency',
										'type'        => 'radio',
										'display'     => 'tab_view',
										'label'       => 'Scan Frequency',
										'description' => 'Choose how often to scan for broken links.',
										'placeholder' => '',
										'required'    => true,
										'register'    => 'tailwatch_broken_link_scan_frequency',
										'values'      => array(
											'option4' => array(
												'value'    => '15 Days',
												'selected' => true,
											),
											'option5' => array(
												'value'    => 'Monthly',
												'selected' => false,
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock more with Tailwatch Pro',
								'description' => 'Tailwatch Pro extends Broken Links with faster scan frequencies including daily, every 3 days, and weekly options for tighter link-health monitoring.',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_broken_links',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Content Restrictions Controller
			'default_disable_keys'           => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_disable_keys',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Content Restrictions',
						'category'            => array( 'security' ),
						'description'         => 'Prevent unauthorized actions such as right-clicking, inspecting elements, copy/paste, and viewing source code. This feature blocks common browser interactions often exploited to steal content or tamper with your site.',
						'verify_status'       => 'check_disable_keys',
						'options'             => array(
							'field_1' => array(
								'key'         => 'disable_context_menu',
								'id'          => 'disable_context_menu',
								'type'        => 'checkbox',
								'label'       => 'Disable Context Menu',
								'description' => 'Disable right-click context menu.',
								'placeholder' => 'Disable Context Menu',
								'required'    => true,
								'register'    => 'disable_context_menu',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_2' => array(
								'key'         => 'disable_inspect_element',
								'id'          => 'disable_inspect_element',
								'type'        => 'checkbox',
								'label'       => 'Disable Inspect Element',
								'description' => 'Disable inspect element functionality.',
								'placeholder' => 'Disable Inspect Element',
								'required'    => true,
								'register'    => 'disable_inspect_element',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_3' => array(
								'key'         => 'disable_copy_paste_cut',
								'id'          => 'disable_copy_paste_cut',
								'type'        => 'checkbox',
								'label'       => 'Disable Copy/Paste/Cut',
								'description' => 'Disable copy, paste, and cut functionalities.',
								'placeholder' => 'Disable Copy/Paste/Cut',
								'required'    => true,
								'register'    => 'disable_copy_paste_cut',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_4' => array(
								'key'         => 'disable_view_source',
								'id'          => 'disable_view_source',
								'type'        => 'checkbox',
								'label'       => 'Disable View Source',
								'description' => 'Disable view source functionality.',
								'placeholder' => 'Disable View Source',
								'required'    => true,
								'register'    => 'disable_view_source',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_5' => array(
								'key'         => 'disable_drag_and_drop',
								'id'          => 'disable_drag_and_drop',
								'type'        => 'checkbox',
								'label'       => 'Disable Drag and Drop',
								'description' => 'Disable drag and drop functionality.',
								'placeholder' => 'Disable Drag and Drop',
								'required'    => true,
								'register'    => 'disable_drag_and_drop',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Role-based 2FA — promotional entry, no settings, renders as info card.
			'default_two_step_authenticate'  => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_two_step_authenticate',
				'value'         => wp_json_encode(
					array(
						'icon'               => 'Icon',
						'title'              => 'Role-based 2FA',
						'category'           => array( 'security' ),
						'description'        => 'Enhance login security with two-factor authentication using Google Authenticator (TOTP) or email verification. Assign different 2FA methods to specific user roles, with single-use backup codes and a simple setup interface.',
						'is_upgrade_feature' => true,
						'options'            => array(),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Get Cron Job Details Controller
			'default_cron_job_list'          => array(
				'user_id'       => $user_id,
				'child_of'      => 1,
				'key'           => 'default_feature_settings',
				'option'        => 'default_cron_job_list',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Cron Jobs',
						'category'            => array( 'optimization' ),
						'col'                 => '1', // 3
						'description'         => 'Manage all your WordPress cron jobs from one place. View, edit, pause, delete, or run tasks instantly, with critical updates delivered via email and mobile app notifications.',
						'verify_status'       => 'check_cron_job',
						'options'             => array(
							'field_1' => array(
								'key'         => 'get_cron_job_list',
								'id'          => 'get_cron_job_list',
								'type'        => 'checkbox',
								'label'       => 'Cron Job Management',
								'description' => 'Enable this feature to view and manage all scheduled WordPress cron jobs.',
								'placeholder' => '',
								'required'    => true,
								'register'    => 'get_cron_job_list',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Dashboard Calculate Active Features
            'calculate_active_features' => array(
                'user_id' => $user_id,
                'child_of' => 0,
                'key' => 'default_dashboard_data',
                'option' => 'calculate_active_features',
                'value' => '',
                'type' => 'json',
                'type_state' => 'active',
                'date_created' => current_time('mysql'),
                'date_modified' => current_time('mysql'),
                'is_active' => 1
			),
			// IP Management Feature
			'default_ips_managment'          => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_ips_managment',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Geo-blocking',
						'category'            => array( 'security' ),
						'description'         => 'Block or allow individual IPs or IP ranges, temporarily or permanently. Control access to login forms or your entire site, and display custom messages to blocked visitors.',
						'verify_status'       => 'check_ip_management',
						'mobile_notification' => false,
						'display_as_tabs'     => true,
						'tab_config'          => array(
							array(
								'field_ids' => array( 'field_1', 'field_2', 'upgrade_notice' ),
								'tab_title' => 'Access Rules',
								'tab_icon'  => 'shield',
							),
							array(
								'field_ids' => array( 'field_4' ),
								'tab_title' => 'Block Page',
								'tab_icon'  => 'shield',
							),
						),
						'options'             => array(
							'field_1' => array(
								'key'               => 'whitelist_manager',
								'id'                => 'whitelist_manager',
								'type'              => 'checkbox',
								'label'             => 'Whitelist Manager',
								'description'       => 'Allow trusted IP addresses to bypass security rules and access the website without restrictions.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => false,
									),
								),
							),
							'field_2' => array(
								'key'               => 'blacklist_manager',
								'id'                => 'blacklist_manager',
								'type'              => 'checkbox',
								'label'             => 'Blacklist Manager',
								'description'       => 'Block specific IP addresses from accessing the website.',
								'placeholder'       => '',
								'required'          => true,
								'register'          => '',
								'values'            => array(
									'option' => array(
										'value'    => '',
										'selected' => false,
									),
								),
							),
							'field_4' => array(
								'key'         => 'block_page_design',
								'id'          => 'block_page_design',
								'type'        => 'design',
								'label'       => 'Block Page',
								'description' => 'Customize the page blocked visitors see: colors, background image, logo, headings, messages, and the countdown timer.',
								'values'      => array(
									'option' => array(
										'value'    => '1',
										'selected' => true,
									),
								),
								'preview'     => array(
									'action'   => 'tailwatch_preview_block_page',
									'variants'  => array(
										array(
											'key'   => 'temporary',
											'label' => 'Temporary',
										),
										array(
											'key'   => 'permanent',
											'label' => 'Permanent',
										),
									),
									'devices'  => array( 'desktop', 'mobile' ),
									'height'   => 600,
								),
								'sub_options' => array(
									'field_1' => array(
										'key'         => 'block_page_background_color',
										'id'          => 'block_page_background_color',
										'type'        => 'color',
										'label'       => 'Background Color',
										'description' => 'Top color of the block page background gradient.',
										'placeholder' => '#667eea',
										'required'    => false,
										'register'    => 'block_page_background_color',
										'values'      => array(
											'option' => array(
												'value'    => '#667eea',
												'selected' => true,
											),
										),
									),
									'field_2' => array(
										'key'         => 'block_page_accent_color',
										'id'          => 'block_page_accent_color',
										'type'        => 'color',
										'label'       => 'Accent Color',
										'description' => 'Bottom color of the block page background gradient.',
										'placeholder' => '#764ba2',
										'required'    => false,
										'register'    => 'block_page_accent_color',
										'values'      => array(
											'option' => array(
												'value'    => '#764ba2',
												'selected' => true,
											),
										),
									),
									'field_4' => array(
										'key'         => 'block_page_show_logo',
										'id'          => 'block_page_show_logo',
										'type'        => 'checkbox',
										'label'       => 'Show Site Logo',
										'description' => 'Display the site icon at the top of the block page.',
										'placeholder' => '',
										'required'    => false,
										'register'    => 'block_page_show_logo',
										'values'      => array(
											'option' => array(
												'value'    => '1',
												'selected' => true,
											),
										),
									),
									'field_5' => array(
										'key'         => 'block_page_show_countdown',
										'id'          => 'block_page_show_countdown',
										'type'        => 'checkbox',
										'label'       => 'Show Countdown Timer',
										'description' => 'Show a live countdown until a temporary block expires.',
										'placeholder' => '',
										'required'    => false,
										'register'    => 'block_page_show_countdown',
										'values'      => array(
											'option' => array(
												'value'    => '1',
												'selected' => true,
											),
										),
									),
									'field_6' => array(
										'key'         => 'block_page_heading_temporary',
										'id'          => 'block_page_heading_temporary',
										'type'        => 'input',
										'label'       => 'Heading (Temporary Block)',
										'description' => 'Title shown when a visitor is temporarily blocked.',
										'placeholder' => 'Temporarily Blocked',
										'required'    => false,
										'register'    => 'block_page_heading_temporary',
										'values'      => array(
											'option' => array(
												'value'    => 'Temporarily Blocked',
												'selected' => true,
											),
										),
									),
									'field_7' => array(
										'key'         => 'block_page_message_temporary',
										'id'          => 'block_page_message_temporary',
										'type'        => 'textarea',
										'label'       => 'Message (Temporary Block)',
										'description' => 'Shown for temporary blocks when a rule has no custom reason.',
										'placeholder' => 'Access to this website is temporarily restricted.',
										'required'    => false,
										'register'    => 'block_page_message_temporary',
										'values'      => array(
											'option' => array(
												'value'    => 'Access to this website is temporarily restricted.',
												'selected' => true,
											),
										),
									),
									'field_8' => array(
										'key'         => 'block_page_heading_permanent',
										'id'          => 'block_page_heading_permanent',
										'type'        => 'input',
										'label'       => 'Heading (Permanent Block)',
										'description' => 'Title shown when a visitor is permanently blocked.',
										'placeholder' => 'Access Restricted',
										'required'    => false,
										'register'    => 'block_page_heading_permanent',
										'values'      => array(
											'option' => array(
												'value'    => 'Access Restricted',
												'selected' => true,
											),
										),
									),
									'field_9' => array(
										'key'         => 'block_page_message_permanent',
										'id'          => 'block_page_message_permanent',
										'type'        => 'textarea',
										'label'       => 'Message (Permanent Block)',
										'description' => 'Shown for permanent blocks when a rule has no custom reason.',
										'placeholder' => 'Access to this website is restricted.',
										'required'    => false,
										'register'    => 'block_page_message_permanent',
										'values'      => array(
											'option' => array(
												'value'    => 'Access to this website is restricted.',
												'selected' => true,
											),
										),
									),
								),
							),
							'upgrade_notice' => array(
								'key'         => 'upgrade_notice',
								'id'          => 'upgrade_notice',
								'type'        => 'info',
								'label'       => 'Unlock country blocking with Tailwatch Pro',
								'description' => 'Tailwatch Pro lets you block or allow visitors by country, applied to login forms or your entire site, on top of the free IP whitelist and blacklist.',
								'links'       => array(
									array(
										'url'  => 'https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=upgrade_notice_geoblock',
										'text' => 'Upgrade Now',
									),
								),
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
			// Login Defender (Login Defender) Feature
			'default_login_defender_management' => array(
				'user_id'       => $user_id,
				'child_of'      => 0,
				'key'           => 'default_feature_settings',
				'option'        => 'default_login_defender_management',
				'value'         => wp_json_encode(
					array(
						'icon'                => 'Icon',
						'title'               => 'Login Defender',
						'category'            => array( 'security' ),
						'description'         => 'Protect your site from brute-force attacks with customizable rules based on usernames, IP addresses, and login behavior. Configure limits, block durations, and lockout scopes while maintaining full control over every login attempt. Receive instant alerts via email and mobile notifications.',
						'verify_status'       => 'check_login_defender_management',
						'recommended_feature' => true,
						'display_as_tabs'     => true,
						'tab_config'          => array(
							array(
								'field_ids' => array( 'field_13' ),
								'tab_title' => 'IP Address Protection',
								'tab_icon'  => 'shield',
							),
							array(
								'field_ids' => array( 'field_14', 'field_15', 'field_16', 'field_17', 'field_18' ),
								'tab_title' => 'Login Form',
								'tab_icon'  => 'shield',
							),
						),
						'mobile_notification' => true,
						'options'             => array(
							'field_13' => array(
								'key'               => 'ip_based_protection',
								'id'                => 'ip_based_protection',
								'push_notification' => true,
								'push_notification_title'       => 'IP Address Blocked',
								'push_notification_description' => 'You will receive a notification on your mobile when an IP address is blocked — temporarily or permanently — for suspicious or repeated failed login attempts.',
								'type'              => 'checkbox',
								'label'             => 'Activate IP-Based Protection',
								'description'       => 'Apply login attempt limits per IP address to block abusive or suspicious sources.',
								'placeholder'       => 'Enable IP-Based Protection',
								'required'          => true,
								'register'          => 'ip_based_protection',
								'values'            => array(
									'option' => array(
										'value'    => '1',
										'selected' => true,
									),
								),
								'sub_options'       => array(
									'field_14' => array(
										'key'         => 'ip_failed_attempt_threshold',
										'id'          => 'ip_failed_attempt_threshold',
										'type'        => 'number',
										'label'       => 'Maximum Failed Login Attempts',
										'description' => 'The number of failed login attempts allowed from a single IP before a block is triggered.',
										'placeholder' => 'Enter number of attempts (e.g., 3)',
										'required'    => true,
										'register'    => 'ip_failed_attempt_threshold',
										'values'      => array(
											'option' => array(
												'value'    => '3',
												'selected' => true,
											),
										),
									),
									'field_15' => array(
										'key'         => 'ip_rate_limit_attempts',
										'id'          => 'ip_rate_limit_attempts',
										'type'        => 'number',
										'label'       => 'Maximum Rapid Login Attempts',
										'description' => 'Limits how many login attempts can occur rapidly from the same IP address.',
										'placeholder' => 'Enter number of rapid attempts (e.g., 3)',
										'required'    => true,
										'register'    => 'ip_rate_limit_attempts',
										'values'      => array(
											'option' => array(
												'value'    => '3',
												'selected' => true,
											),
										),
									),
									'field_16' => array(
										'key'         => 'ip_rate_limit_window',
										'id'          => 'ip_rate_limit_window',
										'type'        => 'number',
										'label'       => 'Rate Limit Window (Seconds)',
										'description' => 'The time window used to evaluate and count login attempts from an IP address.',
										'placeholder' => 'Enter time in seconds (e.g., 3600)',
										'required'    => true,
										'register'    => 'ip_rate_limit_window',
										'values'      => array(
											'option' => array(
												'value'    => '3600',
												'selected' => true,
											),
										),
									),
									'field_17' => array(
										'key'         => 'ip_min_submission_time',
										'id'          => 'ip_min_submission_time',
										'type'        => 'number',
										'label'       => 'Minimum Time Between Login Attempts (Seconds)',
										'description' => 'Enforces a delay between consecutive login attempts from the same IP.',
										'placeholder' => 'Enter time in seconds (e.g., 20)',
										'required'    => true,
										'register'    => 'ip_min_submission_time',
										'values'      => array(
											'option' => array(
												'value'    => '20',
												'selected' => true,
											),
										),
									),
									'field_18' => array(
										'key'         => 'ip_block_type',
										'id'          => 'ip_block_type',
										'type'        => 'radio',
										'label'       => 'Block Type for Failed Logins',
										'description' => 'Choose whether failed login attempts from an IP result in a temporary or permanent block.',
										'placeholder' => 'Select block type',
										'required'    => true,
										'display'     => 'tab_view',
										'icons'       => 'show',
										'register'    => 'ip_block_type',
										'values'      => array(
											'option'  => array(
												'value'    => 'temporary',
												'label'    => 'Temporary',
												'selected' => true,
												'sub_options' => array(
													'field_19' => array(
														'key'  => 'ip_temp_block_duration',
														'id'   => 'ip_temp_block_duration',
														'type' => 'number',
														'label' => 'Temporary Block Duration (Seconds)',
														'description' => 'The length of time an IP address is temporarily restricted after exceeding limits.',
														'placeholder' => 'Enter duration in seconds (e.g., 200)',
														'required' => true,
														'register' => 'user_temp_block_duration',
														'values' => array(
															'option' => array(
																'value' => '200',
																'selected' => true,
															),
														),
													),
													'field_20' => array(
														'key'  => 'ip_escalation_threshold',
														'id'   => 'ip_escalation_threshold',
														'type' => 'number',
														'label' => 'Temporary Blocks Before Permanent',
														'description' => 'Number of temporary blocks allowed before escalating an IP to a permanent block.',
														'placeholder' => 'Enter number of blocks (e.g., 3)',
														'required' => true,
														'register' => 'user_escalation_threshold',
														'values' => array(
															'option' => array(
																'value' => '3',
																'selected' => true,
															),
														),
													),
													'field_21' => array(
														'key'  => 'ip_escalation_window',
														'id'   => 'ip_escalation_window',
														'type' => 'number',
														'label' => 'Escalation Window (Seconds)',
														'description' => 'The time period during which repeated temporary blocks trigger escalation.',
														'placeholder' => 'Enter time in seconds (e.g., 86400)',
														'required' => true,
														'register' => 'ip_escalation_window',
														'values' => array(
															'option' => array(
																'value' => '86400',
																'selected' => true,
															),
														),
													),
													'field_22' => array(
														'key'  => 'ip_temporary_block_command',
														'id'   => 'ip_temporary_block_command',
														'type' => 'textarea',
														'label' => 'Temporary Block Message',
														'description' => 'Message displayed to users when their IP address is temporarily blocked.',
														'placeholder' => 'Enter the message here....',
														'required' => false,
														'register' => 'ip_temporary_block_command',
														'values' => array(
															'option' => array(
																'value' => '',
																'selected' => false,
															),
														),
													),
												),
											),
											'option2' => array(
												'Label'    => 'Permanent',
												'value'    => 'permanent',
												'selected' => false,
												'sub_options' => array(
													'field_23' => array(
														'key'  => 'ip_permanent_block_command',
														'id'   => 'ip_permanent_block_command',
														'type' => 'textarea',
														'label' => 'Permanent Block Message',
														'description' => 'Message displayed when an IP address is permanently restricted due to repeated violations.',
														'placeholder' => 'Enter the message here....',
														'required' => false,
														'register' => 'ip_permanent_block_command',
														'values' => array(
															'option' => array(
																'value' => '',
																'selected' => false,
															),
														),
													),
												),
											),
										),
									),
								),
							),
							'field_14' => array(
								'key'         => 'enable_honeypot_nonce',
								'id'          => 'enable_honeypot_nonce',
								'type'        => 'checkbox',
								'label'       => 'Honeypot Nonce',
								'description' => 'Activate a hidden nonce field in the login form to detect and block automated bot login attempts.',
								'placeholder' => '',
								'required'    => false,
								'register'    => 'enable_honeypot_nonce',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_15' => array(
								'key'         => 'enable_honeypot_bot',
								'id'          => 'enable_honeypot_bot',
								'type'        => 'checkbox',
								'label'       => 'Honeypot Bot Protection',
								'description' => 'Add a hidden field to the login and registration forms to trap and block bots submitting automated requests.',
								'placeholder' => '',
								'required'    => false,
								'register'    => 'enable_honeypot_bot',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_16' => array(
								'key'         => 'disable_user_registration',
								'id'          => 'disable_user_registration',
								'type'        => 'checkbox',
								'label'       => 'Disable User Signup Form',
								'description' => 'Completely disable the user registration form to prevent new user signups.',
								'placeholder' => '',
								'required'    => false,
								'register'    => 'disable_user_registration',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_17' => array(
								'key'         => 'disable_lost_password',
								'id'          => 'disable_lost_password',
								'type'        => 'checkbox',
								'label'       => 'Disable Lost Password',
								'description' => 'Prevent users from accessing the password reset form to restrict account recovery via the login page.',
								'placeholder' => '',
								'required'    => false,
								'register'    => 'disable_lost_password',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
							'field_18' => array(
								'key'         => 'hide_language_dropdown',
								'id'          => 'hide_language_dropdown',
								'type'        => 'checkbox',
								'label'       => 'Hide Language Dropdown',
								'description' => 'Remove the language selection dropdown to simplify the login and registration forms.',
								'placeholder' => '',
								'required'    => false,
								'register'    => 'hide_language_dropdown',
								'values'      => array(
									'option' => array(
										'value'    => '',
										'selected' => true,
									),
								),
							),
						),
					)
				),
				'type'          => 'json',
				'type_state'    => 'inactive',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 0,
			),
		);
	}

	public function insert_site_data() {
		$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );
		if ( is_array( $get_data ) ) {
			$get_data['db_initialize']['cron_running']       = true;
			$get_data['db_initialize']['function_started']   = true;
			$get_data['db_initialize']['function_completed'] = false;
			update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
		}

		$user_id = get_current_user_id();

		$this->insert_rows_batch_wise( $this->tailwatch_complete_site_data(), $user_id );

		$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );
		if ( is_array( $get_data ) ) {
			$get_data['db_initialize']['function_started']     = false;
			$get_data['db_initialize']['function_completed']   = true;
			$get_data['db_initialize']['completion_timestamp'] = time();
			update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
		}
	}

	public function insert_rows_batch_wise( $insert_rows, $user_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$batch_size = 10;

		$get_data = $this->tailwatch_get_initialization_data();

		$last_inserted_index = $this->get_last_insert_index();

		$current_batch = array_slice( $insert_rows, $last_inserted_index, $batch_size );

		// Insert the current batch into the database
		if ( ! empty( $current_batch ) ) {
			foreach ( $current_batch as $row ) {
				// Ensure user_id is set for each row
				$row['user_id'] = $user_id;

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch insertion during plugin initialization
				$wpdb->insert( $table_name, $row );
			}
		}

		$new_last_inserted_index = $last_inserted_index + count( $current_batch );
		$total_rows              = count( $insert_rows );
		$progress_percentage     = (int) ( ( $new_last_inserted_index / $total_rows ) * 100 );

		$progress_message = "Processing rows {$last_inserted_index} to {$new_last_inserted_index} of {$total_rows}.";

		if ( empty( $get_data ) ) {
			$store_value = array(
				'last_inserted_index'  => $new_last_inserted_index,
				'initialize_completed' => false,
				'progress_percentage'  => $progress_percentage,
				'progress_message'     => $progress_message,
			);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Using wpdb::insert() for proper data sanitization
			$wpdb->insert(
				$table_name,
				array(
					'user_id'       => $user_id,
					'child_of'      => 0,
					'key'           => 'tailwatch_insert_rows',
					'option'        => 'plugin_data_initialize',
					'value'         => wp_json_encode( $store_value ),
					'type'          => 'json',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => 1, // Convert boolean true to integer 1 for database
				)
			);
		} else {
			$get_data['last_inserted_index'] = $new_last_inserted_index;
			$get_data['progress_percentage'] = $progress_percentage;
			$get_data['progress_message']    = $progress_message;
			$this->tailwatch_update_initialization_data( $get_data );
		}

		if ( $new_last_inserted_index < count( $insert_rows ) ) {
			wp_schedule_single_event( time() + 5, 'tailwatch_inserting_rows_into_database' );
			return;
		}

		$get_data                         = $this->tailwatch_get_initialization_data();
		$get_data['initialize_completed'] = true;
		$get_data['progress_message']     = 'All rows have been processed successfully.';
		$this->tailwatch_update_initialization_data( $get_data );

		$visit_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );
		if ( is_array( $visit_data ) ) {
			$visit_data['db_initialize']['is_completed'] = true;
			update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $visit_data ) );
		}
	}

	public function get_last_insert_index() {
		$get_data = $this->tailwatch_get_initialization_data();
		return ! empty( $get_data ) ? $get_data['last_inserted_index'] : 0;
	}

	public function tailwatch_get_initialization_data() {
		$key      = 'tailwatch_insert_rows';
		$option   = 'plugin_data_initialize';
		$db_model = new DBModel();
		$result   = $db_model->get_value( $option, $key );
		return ! empty( $result ) ? $result : null;
	}

	public function tailwatch_update_initialization_data( $get_data ) {
		$key      = 'tailwatch_insert_rows';
		$option   = 'plugin_data_initialize';
		$db_model = new DBModel();
		$db_data  = array(
			'value' => wp_json_encode( $get_data ),
		);

		$where = array(
			'key'    => $key,
			'option' => $option,
		);

		$db_model->update_rows( $db_data, $where );
	}

	public function tailwatch_get_formatted_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins       = get_plugins();
		$formatted_plugins = array();
		$i                 = 0;
		foreach ( $all_plugins as $plugin_path => $plugin_data ) {
			$folder_name = dirname( $plugin_path );
			if ( $folder_name === '.' ) {
				$folder_name = basename( $plugin_path, '.php' );
			}

			$key                       = ( $i === 0 ) ? 'option' : 'option' . ( $i + 1 );
			$formatted_plugins[ $key ] = array(
				'value'    => $folder_name,
				'label'    => $plugin_data['Name'] . ' (' . $folder_name . ')',
				'selected' => false,
			);
			++$i;
		}

		if ( empty( $formatted_plugins ) ) {
			$formatted_plugins['option'] = array(
				'value'    => '',
				'label'    => 'No plugins found',
				'selected' => false,
			);
		}

		return $formatted_plugins;
	}

	public function tailwatch_get_formatted_themes() {
		$all_themes       = wp_get_themes();
		$formatted_themes = array();
		$i                = 0;
		foreach ( $all_themes as $theme_slug => $theme ) {
			$key                      = ( $i === 0 ) ? 'option' : 'option' . ( $i + 1 );
			$formatted_themes[ $key ] = array(
				'value'    => $theme_slug,
				'label'    => $theme->get( 'Name' ) . ' (' . $theme_slug . ')',
				'selected' => false,
			);
			++$i;
		}

		if ( empty( $formatted_themes ) ) {
			$formatted_themes['option'] = array(
				'value'    => '',
				'label'    => 'No themes found',
				'selected' => false,
			);
		}

		return $formatted_themes;
	}
}
