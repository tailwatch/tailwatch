<?php
/**
 * Website Stats Controller
 *
 * Provides comprehensive website statistics including PHP, server, WordPress,
 * database, file system, and security information.
 *
 * @package    Tailwatch
 * @subpackage Controllers/LimitIncrease
 */

namespace Tailwatch\Admin\App\Api\Controllers\LimitIncrease;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Disk\DiskSpaceController;
use Tailwatch\Admin\App\Api\Services\WpConfigLocator;

/**
 * Class WebsiteStatsController
 *
 * Provides comprehensive website statistics.
 *
 */
class WebsiteStatsController {

	/**
	 * Feature identifier
	 *
	 * @var string
	 */
	private $feature = 'website_stats';

	/**
	 * Available statistics sections
	 *
	 * @var array
	 */
	private $available_sections = array(
		'php_configuration',
		'server_information',
		'wordpress_information',
		'database_information',
		'file_system_information',
		'security_information',
	);

	/**
	 * Get website stats - can return all data or specific sections
	 *
	 * @param string|array $sections 'all' for everything or array of specific sections.
	 *                               Available sections: 'php_configuration', 'server_information',
	 *                               'wordpress_information', 'database_information',
	 *                               'file_system_information', 'security_information'.
	 * @return array Array containing requested website statistics.
	 */
	public function get_website_stats( $sections = 'all' ) {
		try {
			// Available sections mapping.
			$available_sections = array(
				'php_configuration'       => 'get_php_configuration',
				'server_information'      => 'get_server_information',
				'wordpress_information'   => 'get_wordpress_information',
				'database_information'    => 'get_database_information',
				'file_system_information' => 'get_file_system_information',
				'security_information'    => 'get_security_information',
			);

			$stats = array();

			// Handle different input types.
			if ( 'all' === $sections || empty( $sections ) ) {
				// Get all sections.
				foreach ( $available_sections as $section => $method ) {
					$stats[ $section ] = $this->$method();
				}
			} elseif ( is_array( $sections ) ) {
				// Specific sections requested (array format only).
				foreach ( $sections as $section ) {
					if ( isset( $available_sections[ $section ] ) ) {
						$method            = $available_sections[ $section ];
						$stats[ $section ] = $this->$method();
					} else {
						return array(
							'status'  => 'error',
							'data'    => array(),
							'message' => "Invalid section '$section'. Available sections: " . implode( ', ', array_keys( $available_sections ) ),
						);
					}
				}
			} else {
				// Invalid input type.
				return array(
					'status'  => 'error',
					'data'    => array(),
					'message' => __( 'Invalid sections parameter. Use \'all\' or array of sections. Available sections: ', 'tailwatch' ) . implode( ', ', array_keys( $available_sections ) ),
				);
			}

			// Add metadata.
			$stats['timestamp'] = current_time( 'mysql' );
			$stats['timezone']  = wp_timezone_string();

			return array(
				'status'  => 'success',
				'data'    => $stats,
				'message' => __( 'Website stats retrieved successfully', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			return array(
				'status'  => 'error',
				'data'    => array(),
				'message' => __( 'Failed to retrieve website stats.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get PHP configuration limits and settings
	 *
	 * @return array PHP configuration information.
	 */
	private function get_php_configuration() {
		return array(
			'php_version'         => PHP_VERSION,
			'php_sapi'            => php_sapi_name(),
			'memory_limit'        => array(
				'value' => ini_get( 'memory_limit' ),
				'bytes' => $this->convert_to_bytes( ini_get( 'memory_limit' ) ),
			),
			'max_execution_time'  => array(
				'value'   => ini_get( 'max_execution_time' ),
				'seconds' => (int) ini_get( 'max_execution_time' ),
			),
			'max_input_time'      => array(
				'value'   => ini_get( 'max_input_time' ),
				'seconds' => (int) ini_get( 'max_input_time' ),
			),
			'upload_max_filesize' => array(
				'value' => ini_get( 'upload_max_filesize' ),
				'bytes' => $this->convert_to_bytes( ini_get( 'upload_max_filesize' ) ),
			),
			'post_max_size'       => array(
				'value' => ini_get( 'post_max_size' ),
				'bytes' => $this->convert_to_bytes( ini_get( 'post_max_size' ) ),
			),
			'max_file_uploads'    => (int) ini_get( 'max_file_uploads' ),
			'max_input_vars'      => (int) ini_get( 'max_input_vars' ),
			'allow_url_fopen'     => ini_get( 'allow_url_fopen' ) ? true : false,
			'allow_url_include'   => ini_get( 'allow_url_include' ) ? true : false,
			'display_errors'      => ini_get( 'display_errors' ) ? true : false,
			'log_errors'          => ini_get( 'log_errors' ) ? true : false,
			// Reported via ini_get so this stays a pure read of the configured level for
			// the system-info snapshot, never a call that could alter error reporting.
			'error_reporting'     => (int) ini_get( 'error_reporting' ),
			'session_save_path'   => session_save_path(),
			'temp_dir'            => sys_get_temp_dir(),
			'loaded_extensions'   => get_loaded_extensions(),
			'disabled_functions'  => array_filter( array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) ),
		);
	}

	/**
	 * Get server information
	 *
	 * @return array Server information.
	 */
	private function get_server_information() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variables sanitized below for display only.
		$server_info = array(
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown',
			'server_name'         => isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : 'Unknown',
			'server_addr'         => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'Unknown',
			'server_port'         => isset( $_SERVER['SERVER_PORT'] ) ? absint( $_SERVER['SERVER_PORT'] ) : 'Unknown',
			'document_root'       => isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : 'Unknown',
			'operating_system'    => PHP_OS,
			'server_architecture' => php_uname( 'm' ),
			'server_load_average' => $this->get_server_load(),
			'current_user'        => $this->get_current_system_user(),
			'https_enabled'       => is_ssl(),
			'user_agent'          => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'Unknown',
		);

		// Add CPU and memory info if available.
		if ( function_exists( 'sys_getloadavg' ) ) {
			$server_info['cpu_load'] = sys_getloadavg();
		}

		return $server_info;
	}

	/**
	 * Get WordPress specific information
	 *
	 * @return array WordPress configuration and status information.
	 */
	private function get_wordpress_information() {
		global $wp_version;

		$upload_dir = wp_upload_dir();

		return array(
			'wordpress_version'               => $wp_version,
			'wordpress_multisite'             => is_multisite(),
			'wordpress_debug'                 => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wordpress_debug_log'             => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'wordpress_debug_display'         => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'wordpress_memory_limit'          => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'Not set',
			'wordpress_max_memory_limit'      => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'Not set',
			'wordpress_cron_disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'wordpress_file_permissions'      => defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : '0644',
			'wordpress_directory_permissions' => defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : '0755',
			'active_theme'                    => wp_get_theme()->get( 'Name' ),
			'active_plugins'                  => count( get_option( 'active_plugins', array() ) ),
			'total_plugins'                   => count( get_plugins() ),
			'total_users'                     => count_users()['total_users'],
			'site_url'                        => site_url(),
			'home_url'                        => home_url(),
			'admin_url'                       => admin_url(),
			'content_url'                     => content_url(),
			'uploads_url'                     => $upload_dir['baseurl'],
		);
	}

	/**
	 * Get database information including size details from DiskSpaceController
	 *
	 * @return array Database configuration and statistics.
	 */
	private function get_database_information() {
		global $wpdb;

		// Get basic database configuration.
		$basic_info = array(
			'database_version'     => $wpdb->db_version(),
			'database_server_info' => $wpdb->db_server_info(),
			'database_name'        => DB_NAME,
			'database_host'        => DB_HOST,
			'database_charset'     => DB_CHARSET,
			'database_collate'     => DB_COLLATE,
			'table_prefix'         => $wpdb->prefix,
		);

		// Get detailed database information using DiskSpaceController.
		try {
			$disk_controller  = new DiskSpaceController();
			$database_details = $disk_controller->get_database_info();

			// Merge basic info with detailed info.
			return array_merge( $basic_info, $database_details );
		} catch ( \Throwable $e ) {
			// If DiskSpaceController fails, return basic info only.
			return $basic_info;
		}
	}

	/**
	 * Get file system information including directory sizes from DiskSpaceController
	 *
	 * @return array File system information and directory sizes.
	 */
	private function get_file_system_information() {
		$upload_dir = wp_upload_dir();

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Get basic file system information.
		$basic_info = array(
			'wordpress_root_writable' => $wp_filesystem->is_writable( ABSPATH ),
			'wp_content_writable'     => $wp_filesystem->is_writable( WP_CONTENT_DIR ),
			'uploads_writable'        => $wp_filesystem->is_writable( $upload_dir['basedir'] ),
			'wp_config_exists'        => WpConfigLocator::exists(),
			'htaccess_exists'         => file_exists( ABSPATH . '.htaccess' ),
			'htaccess_writable'       => $wp_filesystem->is_writable( ABSPATH . '.htaccess' ),
			'temp_directory'          => sys_get_temp_dir(),
			'temp_directory_writable' => $wp_filesystem->is_writable( sys_get_temp_dir() ),
		);

		// Get detailed directory sizes using DiskSpaceController.
		try {
			$disk_controller = new DiskSpaceController();
			$directory_sizes = $disk_controller->calculate_directory_sizes();

			// Merge basic info with directory sizes.
			return array_merge( $basic_info, $directory_sizes );
		} catch ( \Throwable $e ) {
			// If DiskSpaceController fails, return basic info only.
			return $basic_info;
		}
	}

	/**
	 * Get security related information
	 *
	 * @return array Security configuration and file permissions.
	 */
	private function get_security_information() {
		$wp_config_path = WpConfigLocator::locate();
		return array(
			'wp_config_permissions'        => null !== $wp_config_path ? $this->get_file_permissions( $wp_config_path ) : null,
			'htaccess_permissions'         => $this->get_file_permissions( ABSPATH . '.htaccess' ),
			'wp_content_permissions'       => $this->get_file_permissions( WP_CONTENT_DIR ),
			'ssl_enabled'                  => is_ssl(),
			'file_editing_disabled'        => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'plugin_installation_disabled' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'wp_debug_enabled'             => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'script_debug_enabled'         => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'force_ssl_admin'              => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
			'automatic_updates_disabled'   => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED,
		);
	}

	/**
	 * Convert PHP memory values to bytes
	 *
	 * @param string $value PHP memory value (e.g., '256M', '1G').
	 * @return int Size in bytes.
	 */
	private function convert_to_bytes( $value ) {
		$value = trim( $value );

		if ( empty( $value ) ) {
			return 0;
		}

		$value_length = strlen( $value );
		if ( $value_length < 1 ) {
			return 0;
		}

		$last  = strtolower( $value[ $value_length - 1 ] );
		$value = (int) $value;

		switch ( $last ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return $value;
	}

	/**
	 * Get server load average
	 *
	 * @return array|string Server load average or 'Not available'.
	 */
	private function get_server_load() {
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			return array(
				'1_minute'  => $load[0],
				'5_minute'  => $load[1],
				'15_minute' => $load[2],
			);
		}

		return 'Not available';
	}

	/**
	 * Get current system user
	 *
	 * @return string Current system username or 'Unknown'.
	 */
	private function get_current_system_user() {
		if ( function_exists( 'get_current_user' ) ) {
			return get_current_user();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ENV variables are system-set.
		if ( isset( $_ENV['USER'] ) ) {
			return sanitize_text_field( wp_unslash( $_ENV['USER'] ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ENV variables are system-set.
		if ( isset( $_ENV['USERNAME'] ) ) {
			return sanitize_text_field( wp_unslash( $_ENV['USERNAME'] ) );
		}

		return 'Unknown';
	}

	/**
	 * Get file permissions
	 *
	 * @param string $file_path Path to file.
	 * @return string File permissions in octal format or 'File not found'.
	 */
	private function get_file_permissions( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return 'File not found';
		}

		$perms = fileperms( $file_path );
		return substr( sprintf( '%o', $perms ), -4 );
	}

	/**
	 * Get formatted website stats for display
	 *
	 * @param string $post_data JSON encoded post data containing sections parameter.
	 * @return array Formatted stats suitable for frontend display.
	 */
	public function tailwatch_get_formatted_website_stats( $post_data ) {
		try {
			if ( ! empty( $post_data ) ) {
				$json_data = is_string( $post_data ) ? wp_unslash( $post_data ) : $post_data;
				$data      = is_array( $json_data ) ? $json_data : json_decode( $json_data, true );
			}
			$sections = isset( $data['sections'] ) ? $data['sections'] : 'all';

			// Validate sections parameter.
			$validated_sections = $this->validate_sections( $sections, $this->available_sections );

			if ( false === $validated_sections ) {
				return array(
					'code'    => 400,
					'message' => __( 'Invalid sections parameter. Must be "all" or an array of valid sections: ', 'tailwatch' ) . implode( ', ', $this->available_sections ),
				);
			}

			$raw_stats = $this->get_website_stats( $sections );

			if ( 'success' !== $raw_stats['status'] ) {
				return $raw_stats;
			}

			$stats     = $raw_stats['data'];
			$formatted = array();

			// PHP Configuration - Format ALL fields.
			if ( isset( $stats['php_configuration'] ) ) {
				$php                            = $stats['php_configuration'];
				$formatted['php_configuration'] = array(
					'php_version'              => $php['php_version'],
					'php_sapi'                 => $php['php_sapi'],
					'memory_limit'             => $php['memory_limit']['value'] . ' (' . $php['memory_limit']['bytes'] . ' bytes)',
					'max_execution_time'       => $php['max_execution_time']['value'] . ' seconds',
					'max_input_time'           => $php['max_input_time']['value'] . ' seconds',
					'upload_max_filesize'      => $php['upload_max_filesize']['value'] . ' (' . $php['upload_max_filesize']['bytes'] . ' bytes)',
					'post_max_size'            => $php['post_max_size']['value'] . ' (' . $php['post_max_size']['bytes'] . ' bytes)',
					'max_file_uploads'         => $php['max_file_uploads'],
					'max_input_vars'           => $php['max_input_vars'],
					'allow_url_fopen'          => $php['allow_url_fopen'] ? 'Yes' : 'No',
					'allow_url_include'        => $php['allow_url_include'] ? 'Yes' : 'No',
					'display_errors'           => $php['display_errors'] ? 'Yes' : 'No',
					'log_errors'               => $php['log_errors'] ? 'Yes' : 'No',
					'error_reporting'          => $php['error_reporting'],
					'session_save_path'        => $php['session_save_path'],
					'temp_directory'           => $php['temp_dir'],
					'loaded_extensions_count'  => count( $php['loaded_extensions'] ),
					'disabled_functions_count' => count( $php['disabled_functions'] ),
				);
			}

			// Server Information - Format ALL fields.
			if ( isset( $stats['server_information'] ) ) {
				$server                          = $stats['server_information'];
				$formatted['server_information'] = array(
					'server_software'     => $server['server_software'],
					'server_name'         => $server['server_name'],
					'server_address'      => $server['server_addr'],
					'server_port'         => $server['server_port'],
					'document_root'       => $server['document_root'],
					'operating_system'    => $server['operating_system'],
					'server_architecture' => $server['server_architecture'],
					'server_load_average' => is_array( $server['server_load_average'] )
						? $server['server_load_average']['1_minute'] . ', ' . $server['server_load_average']['5_minute'] . ', ' . $server['server_load_average']['15_minute']
						: $server['server_load_average'],
					'current_user'        => $server['current_user'],
					'https_enabled'       => $server['https_enabled'] ? 'Yes' : 'No',
					'user_agent'          => $server['user_agent'],
				);

				if ( isset( $server['cpu_load'] ) ) {
					$formatted['server_information']['cpu_load'] = implode( ', ', $server['cpu_load'] );
				}
			}

			// WordPress Information - Format ALL fields.
			if ( isset( $stats['wordpress_information'] ) ) {
				$wp                                 = $stats['wordpress_information'];
				$formatted['wordpress_information'] = array(
					'wordpress_version'               => $wp['wordpress_version'],
					'wordpress_multisite'             => $wp['wordpress_multisite'] ? 'Yes' : 'No',
					'wordpress_debug'                 => $wp['wordpress_debug'] ? 'Enabled' : 'Disabled',
					'wordpress_debug_log'             => $wp['wordpress_debug_log'] ? 'Enabled' : 'Disabled',
					'wordpress_debug_display'         => $wp['wordpress_debug_display'] ? 'Enabled' : 'Disabled',
					'wordpress_memory_limit'          => $wp['wordpress_memory_limit'],
					'wordpress_max_memory_limit'      => $wp['wordpress_max_memory_limit'],
					'wordpress_cron_disabled'         => $wp['wordpress_cron_disabled'] ? 'Yes' : 'No',
					'wordpress_file_permissions'      => $wp['wordpress_file_permissions'],
					'wordpress_directory_permissions' => $wp['wordpress_directory_permissions'],
					'active_theme'                    => $wp['active_theme'],
					'active_plugins'                  => $wp['active_plugins'],
					'total_plugins'                   => $wp['total_plugins'],
					'total_users'                     => $wp['total_users'],
					'site_url'                        => $wp['site_url'],
					'home_url'                        => $wp['home_url'],
					'admin_url'                       => $wp['admin_url'],
					'content_url'                     => $wp['content_url'],
					'uploads_url'                     => $wp['uploads_url'],
				);
			}

			// Database Information - Format ALL fields.
			if ( isset( $stats['database_information'] ) ) {
				$db                                = $stats['database_information'];
				$formatted['database_information'] = array(
					'database_version'     => $db['database_version'],
					'database_server_info' => $db['database_server_info'],
					'database_name'        => $db['database_name'],
					'database_host'        => $db['database_host'],
					'database_charset'     => $db['database_charset'],
					'database_collate'     => $db['database_collate'],
					'table_prefix'         => $db['table_prefix'],
					'total_size'           => isset( $db['total_size'] ) ? $db['total_size'] : 'N/A',
					'table_count'          => isset( $db['table_count'] ) ? $db['table_count'] : 'N/A',
					'db_size_bytes'        => isset( $db['db_size'] ) ? $db['db_size'] : 'N/A',
				);

				if ( isset( $db['tables'] ) && is_array( $db['tables'] ) ) {
					$formatted['database_information']['tables_details'] = count( $db['tables'] ) . ' tables with details available';
				}
			}

			// File System Information - Format ALL fields.
			if ( isset( $stats['file_system_information'] ) ) {
				$fs                                   = $stats['file_system_information'];
				$formatted['file_system_information'] = array(
					'wordpress_root_writable' => $fs['wordpress_root_writable'] ? 'Yes' : 'No',
					'wp_content_writable'     => $fs['wp_content_writable'] ? 'Yes' : 'No',
					'uploads_writable'        => $fs['uploads_writable'] ? 'Yes' : 'No',
					'wp_config_exists'        => $fs['wp_config_exists'] ? 'Yes' : 'No',
					'htaccess_exists'         => $fs['htaccess_exists'] ? 'Yes' : 'No',
					'htaccess_writable'       => $fs['htaccess_writable'] ? 'Yes' : 'No',
					'temp_directory'          => $fs['temp_directory'],
					'temp_directory_writable' => $fs['temp_directory_writable'] ? 'Yes' : 'No',
				);

				// Add directory sizes if available.
				if ( isset( $fs['total_site_size'] ) ) {
					$formatted['file_system_information']['total_site_size'] = $fs['total_site_size'];
				}
				if ( isset( $fs['root'] ) ) {
					$formatted['file_system_information']['wordpress_core_size'] = $fs['root'];
				}
				if ( isset( $fs['wp-admin'] ) ) {
					$formatted['file_system_information']['wp_admin_size'] = $fs['wp-admin'];
				}
				if ( isset( $fs['wp-includes'] ) ) {
					$formatted['file_system_information']['wp_includes_size'] = $fs['wp-includes'];
				}
				if ( isset( $fs['wp-content'] ) ) {
					$formatted['file_system_information']['wp_content_size'] = $fs['wp-content'];
				}
				if ( isset( $fs['plugins'] ) ) {
					$formatted['file_system_information']['plugins_size'] = $fs['plugins'];
				}
				if ( isset( $fs['themes'] ) ) {
					$formatted['file_system_information']['themes_size'] = $fs['themes'];
				}
				if ( isset( $fs['uploads'] ) ) {
					$formatted['file_system_information']['uploads_size'] = $fs['uploads'];
				}
				if ( isset( $fs['files_size'] ) ) {
					$formatted['file_system_information']['files_size_bytes'] = $fs['files_size'];
				}
			}

			// Security Information - Format ALL fields.
			if ( isset( $stats['security_information'] ) ) {
				$security                          = $stats['security_information'];
				$formatted['security_information'] = array(
					'wp_config_permissions'        => $security['wp_config_permissions'],
					'htaccess_permissions'         => $security['htaccess_permissions'],
					'wp_content_permissions'       => $security['wp_content_permissions'],
					'ssl_enabled'                  => $security['ssl_enabled'] ? 'Yes' : 'No',
					'file_editing_disabled'        => $security['file_editing_disabled'] ? 'Yes' : 'No',
					'plugin_installation_disabled' => $security['plugin_installation_disabled'] ? 'Yes' : 'No',
					'wp_debug_enabled'             => $security['wp_debug_enabled'] ? 'Yes' : 'No',
					'script_debug_enabled'         => $security['script_debug_enabled'] ? 'Yes' : 'No',
					'force_ssl_admin'              => $security['force_ssl_admin'] ? 'Yes' : 'No',
					'automatic_updates_disabled'   => $security['automatic_updates_disabled'] ? 'Yes' : 'No',
				);
			}

			// Add timestamp and timezone if available.
			if ( isset( $stats['timestamp'] ) ) {
				$formatted['metadata'] = array(
					'generated_at' => $stats['timestamp'],
					'timezone'     => isset( $stats['timezone'] ) ? $stats['timezone'] : 'N/A',
				);
			}

			return array(
				'code'    => 200,
				'data'    => $formatted,
				'message' => __( 'Formatted website stats retrieved successfully', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to format website stats.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Validate sections parameter
	 *
	 * @param string|array $sections         Sections to validate.
	 * @param array        $available_sections Available valid sections.
	 * @return string|array|false 'all', validated array, or false if invalid.
	 */
	private function validate_sections( $sections, $available_sections ) {
		// If sections is 'all', return 'all'.
		if ( 'all' === $sections ) {
			return 'all';
		}

		// If sections is not an array, it's invalid.
		if ( ! is_array( $sections ) ) {
			return false;
		}

		// If empty array, default to 'all'.
		if ( empty( $sections ) ) {
			return 'all';
		}

		// Check if all provided sections are valid.
		foreach ( $sections as $section ) {
			if ( ! in_array( $section, $available_sections, true ) ) {
				return false; // Invalid section found.
			}
		}

		// Remove duplicates and return validated array.
		return array_unique( $sections );
	}
}
