<?php
namespace Tailwatch\Admin\App\Api\Controllers\HardeningAudit;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Users\Hardening\UsernameSecurityController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\WpConfigLocator;

/**
 * Pure-library counterpart to {@see HardeningAuditController}, mirroring the
 * Database Optimizer split (orchestrator + pure-task library).
 *
 * Each `check_*` method reads the runtime (PHP version, ini, options,
 * transients, filesystem), returns a normalized envelope via build_result(),
 * and performs no scheduling, DB writes, or state mutation. The orchestrator
 * is the only thing that calls these and persists their output — which keeps
 * this file unit-testable in isolation.
 */
class HardeningChecksController {

	/**
	 * Status taxonomy for individual checks. Mirrors HardeningAuditController
	 * intentionally — both classes use the same constants so the orchestrator
	 * can compute summaries without translating between vocabularies.
	 */
	const STATUS_SECURE   = 'secure';
	const STATUS_NOTICE   = 'notice';
	const STATUS_WARNING  = 'warning';
	const STATUS_CRITICAL = 'critical';
	const STATUS_ERROR    = 'error';

	// Version thresholds reflect the upstream EOL calendar. Bump these
	// roughly once per year as PHP/MariaDB branches drop out of security
	// support — leaving stale values means a site on a long-EOL version
	// would only see WARNING instead of CRITICAL.
	//   RECOMMENDED = lowest branch still receiving security updates
	//   MINIMUM     = below this is years-EOL and unconditionally critical
	const RECOMMENDED_PHP_VERSION     = '8.3';
	const MINIMUM_PHP_VERSION         = '8.1';
	const RECOMMENDED_MYSQL_VERSION   = '8.0';
	const RECOMMENDED_MARIADB_VERSION = '10.11';

	/**
	 * Acceptable wp-config.php permission octals.
	 *
	 * @var array<string>
	 */
	private const SAFE_WP_CONFIG_PERMS = array( '0400', '0440', '0444', '0600', '0640' );

	/**
	 * Map of check_key => method_name. Exposed so the orchestrator can iterate
	 * the canonical pipeline without hard-coding the list twice. The order
	 * here is the order the orchestrator will execute checks in — keep
	 * cheap/local checks first so a partial scan still surfaces useful data.
	 *
	 * @return array<string,string>
	 */
	public static function get_check_pipeline() {
		return array(
			'php_version'              => 'check_php_version',
			'database_version'         => 'check_mysql_version',
			'savequeries'              => 'check_savequeries',
			'php_ini_settings'         => 'check_php_ini_settings',
			'dangerous_files'          => 'check_dangerous_files',
			'wordpress_version'        => 'check_wordpress_version',
			'database_prefix'          => 'check_database_prefix',
			'security_keys'            => 'check_security_keys',
			'admin_username'           => 'check_admin_username',
			'ssl_https'                => 'check_ssl_configuration',
			'debug_mode'               => 'check_debug_mode',
			'xmlrpc'                   => 'check_xmlrpc',
			'rest_api'                 => 'check_rest_api',
			'user_enumeration'         => 'check_user_enumeration',
			'plugin_updates'           => 'check_plugin_updates',
			'theme_updates'            => 'check_theme_updates',
			'file_permissions'         => 'check_file_permissions',
			// Extended checks ordered after the originals so partial scans
			// complete the canonical set first: backdoor surface (PHP-in-uploads),
			// open registration, dashboard file editor, application-password REST
			// surface, search-engine discouragement, and pingback DDoS amplification.
			'uploads_php_files'        => 'check_uploads_php_files',
			'user_registration'        => 'check_user_registration',
			'file_editor'              => 'check_file_editor',
			'application_passwords'    => 'check_application_passwords',
			'search_engine_visibility' => 'check_search_engine_visibility',
			'pingbacks'                => 'check_pingbacks',
		);
	}

	/**
	 * Map of check_key => human-readable label, mirroring the literals each
	 * `check_*` method assigns to its local `$label`. Exposed so the
	 * orchestrator's live-log writer can show "Verifying: PHP Version"
	 * BEFORE the check runs (rather than the technical key
	 * "Verifying: php_version" or having to invoke the check just to
	 * extract `feature_label`).
	 *
	 * Keys must stay in sync with `get_check_pipeline()` and with the
	 * `$label` literal inside each `check_*` method — any drift between
	 * these and the per-check labels would surface as the same check
	 * showing two different names in the UI vs the live log.
	 *
	 * @return array<string,string>
	 */
	public static function get_check_labels() {
		return array(
			'php_version'              => __( 'PHP Version', 'tailwatch' ),
			'database_version'         => __( 'Database Version', 'tailwatch' ),
			'savequeries'              => __( 'Database Query Logging', 'tailwatch' ),
			'php_ini_settings'         => __( 'PHP Runtime Settings', 'tailwatch' ),
			'dangerous_files'          => __( 'Dangerous Files', 'tailwatch' ),
			'wordpress_version'        => __( 'WordPress Version', 'tailwatch' ),
			'database_prefix'          => __( 'Database Prefix', 'tailwatch' ),
			'security_keys'            => __( 'Authentication Keys & Salts', 'tailwatch' ),
			'admin_username'           => __( 'Admin Usernames', 'tailwatch' ),
			'file_permissions'         => __( 'File Permissions', 'tailwatch' ),
			'ssl_https'                => __( 'SSL / HTTPS', 'tailwatch' ),
			'debug_mode'               => __( 'Debug Mode', 'tailwatch' ),
			'xmlrpc'                   => __( 'XML-RPC', 'tailwatch' ),
			'rest_api'                 => __( 'REST API', 'tailwatch' ),
			'user_enumeration'         => __( 'User Enumeration', 'tailwatch' ),
			'plugin_updates'           => __( 'Plugin Updates', 'tailwatch' ),
			'theme_updates'            => __( 'Theme Updates', 'tailwatch' ),
			'uploads_php_files'        => __( 'PHP Files in Uploads', 'tailwatch' ),
			'user_registration'        => __( 'User Registration', 'tailwatch' ),
			'file_editor'              => __( 'File Editor', 'tailwatch' ),
			'application_passwords'    => __( 'Application Passwords', 'tailwatch' ),
			'search_engine_visibility' => __( 'Search Engine Visibility', 'tailwatch' ),
			'pingbacks'                => __( 'Pingbacks', 'tailwatch' ),
		);
	}

	/**
	 * Build the standardized result envelope every check returns.
	 *
	 * Public so the orchestrator can wrap a thrown exception in the same
	 * shape via {@see build_error_result} without reimplementing the schema.
	 *
	 * @param string $feature_key
	 * @param string $feature_label
	 * @param string $status
	 * @param string $message
	 * @param array  $details
	 * @return array
	 */
	public function build_result( $feature_key, $feature_label, $status, $message, array $details = array() ) {
		return array(
			'feature_key'   => $feature_key,
			'feature_label' => $feature_label,
			'status'        => $status,
			'message'       => $message,
			'details'       => $details,
		);
	}

	/**
	 * Normalize a thrown check into a result envelope so a single failed
	 * check cannot abort the whole audit. Logged once here so the
	 * orchestrator does not need to log too.
	 *
	 * @param string     $feature_key
	 * @param string     $feature_label
	 * @param \Throwable $exception
	 * @return array
	 */
	public function build_error_result( $feature_key, $feature_label, \Throwable $exception ) {
		Log::error(
			sprintf( 'Hardening audit check %s failed: %s', $feature_key, $exception->getMessage() ),
			array(
				'feature' => 'hardening_audit',
				'action'  => 'check_failed',
				'check'   => $feature_key,
				'error'   => $exception->getMessage(),
			)
		);

		return $this->build_result(
			$feature_key,
			$feature_label,
			self::STATUS_ERROR,
			__( 'This check could not be completed. Please try again.', 'tailwatch' )
		);
	}

	/**
	 * Coerce an `ini_get()` value to a real boolean. `ini_get` returns
	 * '1','On','true','yes' for enabled and '0','Off','false','no','' for
	 * disabled — string comparison alone is unreliable.
	 *
	 * @param string $directive
	 * @return bool
	 */
	private function ini_get_bool( $directive ) {
		$value = ini_get( $directive );
		if ( false === $value ) {
			return false;
		}
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Convert an absolute filesystem path to a path relative to ABSPATH so
	 * scan results never leak the full server path.
	 *
	 * @param string $path
	 * @return string
	 */
	private function relative_path( $path ) {
		$abspath = wp_normalize_path( ABSPATH );
		$path    = wp_normalize_path( $path );

		if ( 0 === strpos( $path, $abspath ) ) {
			return ltrim( substr( $path, strlen( $abspath ) ), '/' );
		}
		return basename( $path );
	}

	/**
	 * Extract the actual server version from a `SELECT VERSION()` result.
	 * Handles MariaDB compatibility prefix `5.5.5-...` and trailing flags
	 * like `-MariaDB-log` or `-log`.
	 *
	 * @param string $raw_version
	 * @return string
	 */
	private function extract_db_version_number( $raw_version ) {
		if ( false !== stripos( $raw_version, 'mariadb' ) && 0 === strpos( $raw_version, '5.5.5-' ) ) {
			$raw_version = substr( $raw_version, 6 );
		}

		if ( preg_match( '/^(\d+(?:\.\d+){0,3})/', $raw_version, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	// Individual checks.

	public function check_php_version() {
		$current_version = PHP_VERSION;
		$label           = __( 'PHP Version', 'tailwatch' );

		$details = array(
			'current_value'     => $current_version,
			'recommended_value' => '>= ' . self::RECOMMENDED_PHP_VERSION,
			'minimum_value'     => '>= ' . self::MINIMUM_PHP_VERSION,
		);

		if ( version_compare( $current_version, self::MINIMUM_PHP_VERSION, '<' ) ) {
			return $this->build_result(
				'php_version',
				$label,
				self::STATUS_CRITICAL,
				sprintf(
					/* translators: 1: current PHP version, 2: minimum supported PHP version */
					__( 'Your PHP version %1$s is end-of-life and no longer receives security patches. Upgrade to PHP %2$s or higher immediately.', 'tailwatch' ),
					$current_version,
					self::RECOMMENDED_PHP_VERSION
				),
				$details
			);
		}

		if ( version_compare( $current_version, self::RECOMMENDED_PHP_VERSION, '<' ) ) {
			return $this->build_result(
				'php_version',
				$label,
				self::STATUS_WARNING,
				sprintf(
					/* translators: 1: current PHP version, 2: recommended PHP version */
					__( 'Your PHP version %1$s is below the recommended %2$s. We recommend upgrading.', 'tailwatch' ),
					$current_version,
					self::RECOMMENDED_PHP_VERSION
				),
				$details
			);
		}

		return $this->build_result(
			'php_version',
			$label,
			self::STATUS_SECURE,
			sprintf(
				/* translators: %s: current PHP version */
				__( 'You are running a supported PHP version (%s).', 'tailwatch' ),
				$current_version
			),
			$details
		);
	}

	public function check_mysql_version() {
		global $wpdb;
		$label = __( 'Database Version', 'tailwatch' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading server version.
		$raw_version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( empty( $raw_version ) ) {
			return $this->build_result(
				'database_version',
				$label,
				self::STATUS_ERROR,
				__( 'Could not detect the database server version.', 'tailwatch' )
			);
		}

		$is_mariadb         = ( false !== stripos( $raw_version, 'mariadb' ) );
		$server_label       = $is_mariadb ? 'MariaDB' : 'MySQL';
		$recommended        = $is_mariadb ? self::RECOMMENDED_MARIADB_VERSION : self::RECOMMENDED_MYSQL_VERSION;
		$normalized_version = $this->extract_db_version_number( $raw_version );

		$details = array(
			'server'            => $server_label,
			'current_value'     => $normalized_version,
			'recommended_value' => '>= ' . $recommended,
			'raw_version'       => $raw_version,
		);

		if ( '' === $normalized_version ) {
			return $this->build_result(
				'database_version',
				$label,
				self::STATUS_ERROR,
				__( 'Could not parse the database server version string.', 'tailwatch' ),
				$details
			);
		}

		if ( version_compare( $normalized_version, $recommended, '<' ) ) {
			return $this->build_result(
				'database_version',
				$label,
				self::STATUS_WARNING,
				sprintf(
					/* translators: 1: server name, 2: current version, 3: recommended version */
					__( 'Your %1$s version %2$s is below the recommended %3$s.', 'tailwatch' ),
					$server_label,
					$normalized_version,
					$recommended
				),
				$details
			);
		}

		return $this->build_result(
			'database_version',
			$label,
			self::STATUS_SECURE,
			sprintf(
				/* translators: 1: server name, 2: current version */
				__( 'You are running a supported %1$s version (%2$s).', 'tailwatch' ),
				$server_label,
				$normalized_version
			),
			$details
		);
	}

	public function check_savequeries() {
		$label   = __( 'Database Query Logging', 'tailwatch' );
		$enabled = defined( 'SAVEQUERIES' ) && SAVEQUERIES;

		if ( $enabled ) {
			return $this->build_result(
				'savequeries',
				$label,
				self::STATUS_WARNING,
				__( 'SAVEQUERIES is enabled. This is a development setting that exposes query data and slows the site. Disable it in production.', 'tailwatch' ),
				array( 'current_value' => 'enabled' )
			);
		}

		return $this->build_result(
			'savequeries',
			$label,
			self::STATUS_SECURE,
			__( 'SAVEQUERIES is disabled.', 'tailwatch' ),
			array( 'current_value' => 'disabled' )
		);
	}

	public function check_php_ini_settings() {
		$label  = __( 'PHP Runtime Settings', 'tailwatch' );
		$issues = array();

		if ( $this->ini_get_bool( 'display_errors' ) ) {
			$issues[] = array(
				'directive' => 'display_errors',
				'severity'  => self::STATUS_WARNING,
				'message'   => __( 'PHP display_errors is enabled — errors should be logged, not displayed to visitors.', 'tailwatch' ),
			);
		}

		if ( $this->ini_get_bool( 'allow_url_include' ) ) {
			$issues[] = array(
				'directive' => 'allow_url_include',
				'severity'  => self::STATUS_CRITICAL,
				'message'   => __( 'PHP allow_url_include is enabled — this allows remote file inclusion attacks.', 'tailwatch' ),
			);
		}

		if ( $this->ini_get_bool( 'expose_php' ) ) {
			$issues[] = array(
				'directive' => 'expose_php',
				'severity'  => self::STATUS_NOTICE,
				'message'   => __( 'PHP expose_php is enabled — this reveals your PHP version in HTTP headers.', 'tailwatch' ),
			);
		}

		if ( empty( $issues ) ) {
			return $this->build_result(
				'php_ini_settings',
				$label,
				self::STATUS_SECURE,
				__( 'PHP runtime security settings look good.', 'tailwatch' )
			);
		}

		// Aggregate severity: critical wins over warning, warning over notice.
		$status = self::STATUS_NOTICE;
		foreach ( $issues as $issue ) {
			if ( self::STATUS_CRITICAL === $issue['severity'] ) {
				$status = self::STATUS_CRITICAL;
				break;
			}
			if ( self::STATUS_WARNING === $issue['severity'] && self::STATUS_CRITICAL !== $status ) {
				$status = self::STATUS_WARNING;
			}
		}

		return $this->build_result(
			'php_ini_settings',
			$label,
			$status,
			__( 'One or more PHP runtime settings need attention.', 'tailwatch' ),
			array( 'issues' => $issues )
		);
	}

	public function check_dangerous_files() {
		$label = __( 'Dangerous Files', 'tailwatch' );

		// Two severity tiers — credential / VCS / dump leaks are
		// unambiguously critical (anyone hitting these URLs gets serious
		// secrets), while warning-tier items either ship with WordPress
		// core (wp-config-sample.php) or are auto-created by PHP error
		// logging (debug.log / error_log). Reporting all of them at
		// CRITICAL produced a steady false-positive stream on every
		// stock WordPress install (wp-config-sample.php is part of the
		// core zip — it's there until you delete it).
		$critical_root_files = array(
			'wp-config.php.bak',
			'wp-config.php.old',
			'wp-config.php~',
			'.env',
			'.env.bak',
			'.git',
			'.svn',
			'phpinfo.php',
			'info.php',
			'test.php',
			'adminer.php',
			'backup.zip',
			'backup.sql',
			'database.sql',
			'dump.sql',
		);

		$warning_root_files = array(
			// Ships with WP core. Removing it is a hardening best practice
			// (reduces the obvious "this is a WordPress site" signal) but
			// it does not leak credentials — it only contains placeholder
			// strings like 'database_name_here'. Warning-tier, not critical.
			'wp-config-sample.php',
		);

		$warning_content_files = array(
			'debug.log',
			'error_log',
		);

		$critical_found = array();
		$warning_found  = array();

		foreach ( $critical_root_files as $name ) {
			$path = trailingslashit( ABSPATH ) . $name;
			if ( file_exists( $path ) ) {
				$critical_found[] = $name;
			}
		}

		foreach ( $warning_root_files as $name ) {
			$path = trailingslashit( ABSPATH ) . $name;
			if ( file_exists( $path ) ) {
				$warning_found[] = $name;
			}
		}

		$content_dir_label = wp_basename( WP_CONTENT_DIR );
		foreach ( $warning_content_files as $name ) {
			$path = trailingslashit( WP_CONTENT_DIR ) . $name;
			if ( file_exists( $path ) ) {
				$warning_found[] = $content_dir_label . '/' . $name;
			}
		}

		$all_found = array_merge( $critical_found, $warning_found );

		if ( ! empty( $critical_found ) ) {
			return $this->build_result(
				'dangerous_files',
				$label,
				self::STATUS_CRITICAL,
				sprintf(
					/* translators: %d: number of sensitive files found */
					_n(
						'Found %d sensitive file that should be removed.',
						'Found %d sensitive files that should be removed.',
						count( $all_found ),
						'tailwatch'
					),
					count( $all_found )
				),
				array(
					'files'    => $all_found,
					'critical' => $critical_found,
					'warning'  => $warning_found,
				)
			);
		}

		if ( ! empty( $warning_found ) ) {
			return $this->build_result(
				'dangerous_files',
				$label,
				self::STATUS_WARNING,
				sprintf(
					/* translators: %d: number of low-risk files found */
					_n(
						'Found %d file you may want to remove (log files or the WordPress sample config).',
						'Found %d files you may want to remove (log files or the WordPress sample config).',
						count( $warning_found ),
						'tailwatch'
					),
					count( $warning_found )
				),
				array(
					'files'   => $warning_found,
					'warning' => $warning_found,
				)
			);
		}

		return $this->build_result(
			'dangerous_files',
			$label,
			self::STATUS_SECURE,
			__( 'No dangerous files were found in your installation.', 'tailwatch' )
		);
	}

	public function check_wordpress_version() {
		global $wp_version;

		$label   = __( 'WordPress Version', 'tailwatch' );
		$details = array( 'current_value' => $wp_version );

		// Read the cached update_core site transient WordPress already
		// maintains via cron. Avoids a network call from this hot path.
		$update_core = get_site_transient( 'update_core' );
		if ( ! $update_core || empty( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
			return $this->build_result(
				'wordpress_version',
				$label,
				self::STATUS_SECURE,
				sprintf(
					/* translators: %s: current WordPress version */
					__( 'WordPress %s is installed.', 'tailwatch' ),
					$wp_version
				),
				$details
			);
		}

		foreach ( $update_core->updates as $update ) {
			if ( isset( $update->response ) && 'upgrade' === $update->response && ! empty( $update->version ) ) {
				$details['recommended_value'] = $update->version;

				return $this->build_result(
					'wordpress_version',
					$label,
					self::STATUS_WARNING,
					sprintf(
						/* translators: 1: latest WordPress version, 2: current WordPress version */
						__( 'WordPress %1$s is available — you are running %2$s.', 'tailwatch' ),
						$update->version,
						$wp_version
					),
					$details
				);
			}
		}

		return $this->build_result(
			'wordpress_version',
			$label,
			self::STATUS_SECURE,
			sprintf(
				/* translators: %s: current WordPress version */
				__( 'You are running the latest WordPress version (%s).', 'tailwatch' ),
				$wp_version
			),
			$details
		);
	}

	public function check_database_prefix() {
		global $wpdb;

		$label  = __( 'Database Prefix', 'tailwatch' );
		$prefix = $wpdb->prefix;

		if ( 'wp_' === $prefix ) {
			return $this->build_result(
				'database_prefix',
				$label,
				self::STATUS_WARNING,
				__( 'You are using the default database prefix "wp_". Consider using a custom prefix for additional defense in depth.', 'tailwatch' ),
				array(
					'current_value'     => $prefix,
					'recommended_value' => __( 'Custom prefix (e.g. xyz_)', 'tailwatch' ),
				)
			);
		}

		return $this->build_result(
			'database_prefix',
			$label,
			self::STATUS_SECURE,
			__( 'You are using a custom database prefix.', 'tailwatch' ),
			array( 'current_value' => $prefix )
		);
	}

	public function check_security_keys() {
		$label = __( 'Authentication Keys & Salts', 'tailwatch' );

		$required_keys = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		$placeholders = array(
			'put your unique phrase here',
			'your secret key here',
		);

		$missing = array();
		$weak    = array();

		foreach ( $required_keys as $key ) {
			if ( ! defined( $key ) ) {
				$missing[] = $key;
				continue;
			}

			$value = constant( $key );
			if ( ! is_string( $value ) || '' === $value ) {
				$missing[] = $key;
				continue;
			}

			if ( in_array( $value, $placeholders, true ) || strlen( $value ) < 30 ) {
				$weak[] = $key;
			}
		}

		if ( ! empty( $missing ) || ! empty( $weak ) ) {
			return $this->build_result(
				'security_keys',
				$label,
				self::STATUS_CRITICAL,
				__( 'Your authentication keys are not properly configured.', 'tailwatch' ),
				array(
					'missing' => $missing,
					'weak'    => $weak,
				)
			);
		}

		return $this->build_result(
			'security_keys',
			$label,
			self::STATUS_SECURE,
			__( 'All authentication keys and salts are configured.', 'tailwatch' )
		);
	}

	public function check_admin_username() {
		$label             = __( 'Admin Usernames', 'tailwatch' );
		$blocked_usernames = UsernameSecurityController::get_blocked_usernames();
		$found_users       = array();

		foreach ( $blocked_usernames as $username ) {
			$user = get_user_by( 'login', $username );
			if ( $user instanceof \WP_User ) {
				$found_users[] = array(
					'username' => $user->user_login,
					'roles'    => $user->roles,
				);
			}
		}

		if ( ! empty( $found_users ) ) {
			return $this->build_result(
				'admin_username',
				$label,
				self::STATUS_WARNING,
				sprintf(
					/* translators: %d: number of accounts using common usernames */
					_n(
						'%d user account uses a commonly-targeted username.',
						'%d user accounts use commonly-targeted usernames.',
						count( $found_users ),
						'tailwatch'
					),
					count( $found_users )
				),
				array( 'found_users' => $found_users )
			);
		}

		return $this->build_result(
			'admin_username',
			$label,
			self::STATUS_SECURE,
			__( 'No common admin usernames were detected.', 'tailwatch' )
		);
	}

	public function check_file_permissions() {
		$label = __( 'File Permissions', 'tailwatch' );

		$uploads_basedir = wp_get_upload_dir()['basedir'] ?? ( WP_CONTENT_DIR . '/uploads' );
		$wp_config_path  = WpConfigLocator::locate();
		$paths           = array(
			ABSPATH . '.htaccess',
			WP_CONTENT_DIR,
			$uploads_basedir,
			WP_PLUGIN_DIR,
			get_theme_root(),
		);
		if ( null !== $wp_config_path ) {
			array_unshift( $paths, $wp_config_path );
		}

		$issues = array();

		foreach ( $paths as $path ) {
			if ( ! file_exists( $path ) ) {
				continue;
			}

			$perms     = fileperms( $path );
			$octal     = substr( sprintf( '%o', $perms ), -4 );
			$is_config = ( 'wp-config.php' === basename( $path ) );

			if ( $is_config ) {
				if ( ! in_array( $octal, self::SAFE_WP_CONFIG_PERMS, true ) ) {
					$issues[] = array(
						'path'        => $this->relative_path( $path ),
						'current'     => $octal,
						'recommended' => '0440 or 0640',
						'severity'    => self::STATUS_CRITICAL,
					);
				}
				continue;
			}

			// World-writable bit (0002) catches 0666, 0777, 0775, etc. — not
			// just the literal 0777 a naive check would flag.
			if ( $perms & 0002 ) {
				$issues[] = array(
					'path'        => $this->relative_path( $path ),
					'current'     => $octal,
					'recommended' => is_dir( $path ) ? '0755' : '0644',
					'severity'    => self::STATUS_WARNING,
				);
			}
		}

		if ( ! empty( $issues ) ) {
			$has_critical = false;
			foreach ( $issues as $issue ) {
				if ( self::STATUS_CRITICAL === $issue['severity'] ) {
					$has_critical = true;
					break;
				}
			}

			return $this->build_result(
				'file_permissions',
				$label,
				$has_critical ? self::STATUS_CRITICAL : self::STATUS_WARNING,
				__( 'One or more files have insecure permissions.', 'tailwatch' ),
				array( 'issues' => $issues )
			);
		}

		return $this->build_result(
			'file_permissions',
			$label,
			self::STATUS_SECURE,
			__( 'File permissions look good.', 'tailwatch' )
		);
	}

	public function check_ssl_configuration() {
		$label    = __( 'SSL / HTTPS', 'tailwatch' );
		$site_url = (string) get_option( 'siteurl' );
		$home_url = (string) get_option( 'home' );

		// Inspect stored URLs (not is_ssl()) — is_ssl reflects only the
		// current request and produces false negatives when the audit runs
		// from a non-HTTPS admin context.
		$site_https      = ( 0 === stripos( $site_url, 'https://' ) );
		$home_https      = ( 0 === stripos( $home_url, 'https://' ) );
		$force_ssl_admin = defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN;

		$issues = array();

		if ( ! $site_https ) {
			$issues[] = __( 'Site Address (siteurl) is not set to HTTPS.', 'tailwatch' );
		}
		if ( ! $home_https ) {
			$issues[] = __( 'WordPress Address (home) is not set to HTTPS.', 'tailwatch' );
		}
		if ( ! $force_ssl_admin ) {
			$issues[] = __( 'FORCE_SSL_ADMIN is not enabled in wp-config.php.', 'tailwatch' );
		}

		if ( ! empty( $issues ) ) {
			return $this->build_result(
				'ssl_https',
				$label,
				$site_https && $home_https ? self::STATUS_WARNING : self::STATUS_CRITICAL,
				__( 'SSL / HTTPS is not fully configured.', 'tailwatch' ),
				array(
					'issues'          => $issues,
					'site_https'      => $site_https,
					'home_https'      => $home_https,
					'force_ssl_admin' => $force_ssl_admin,
				)
			);
		}

		return $this->build_result(
			'ssl_https',
			$label,
			self::STATUS_SECURE,
			__( 'SSL / HTTPS is properly configured.', 'tailwatch' )
		);
	}

	public function check_debug_mode() {
		$label            = __( 'Debug Mode', 'tailwatch' );
		$wp_debug         = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$wp_debug_display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$wp_debug_log     = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$script_debug     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

		$details = array(
			'wp_debug'         => $wp_debug,
			'wp_debug_display' => $wp_debug_display,
			'wp_debug_log'     => $wp_debug_log,
			'script_debug'     => $script_debug,
		);

		// Errors are only visible when BOTH WP_DEBUG and WP_DEBUG_DISPLAY
		// are on; checking WP_DEBUG_DISPLAY alone false-positives on every
		// stock WordPress install (which defines it as true by default).
		if ( $wp_debug && $wp_debug_display ) {
			return $this->build_result(
				'debug_mode',
				$label,
				self::STATUS_CRITICAL,
				__( 'WP_DEBUG_DISPLAY is enabled — error traces are visible to site visitors.', 'tailwatch' ),
				$details
			);
		}

		if ( ! $wp_debug && $wp_debug_display ) {
			return $this->build_result(
				'debug_mode',
				$label,
				self::STATUS_NOTICE,
				__( 'WP_DEBUG_DISPLAY is technically enabled, but WP_DEBUG is off so errors are not displayed. For best practice, add define(\'WP_DEBUG_DISPLAY\', false) to wp-config.php.', 'tailwatch' ),
				$details
			);
		}

		if ( $script_debug ) {
			return $this->build_result(
				'debug_mode',
				$label,
				self::STATUS_WARNING,
				__( 'SCRIPT_DEBUG is enabled — this loads unminified core scripts and is for development only.', 'tailwatch' ),
				$details
			);
		}

		return $this->build_result(
			'debug_mode',
			$label,
			self::STATUS_SECURE,
			__( 'Debug output is not exposed.', 'tailwatch' ),
			$details
		);
	}

	public function check_xmlrpc() {
		$label       = __( 'XML-RPC', 'tailwatch' );
		$file_exists = file_exists( ABSPATH . 'xmlrpc.php' );
		/** This filter is documented in wp-includes/class-wp-xmlrpc-server.php */
		$filter_enabled = (bool) apply_filters( 'xmlrpc_enabled', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-reading WP-core filter to detect current state, not introducing a new hook.

		$details = array(
			'file_exists'    => $file_exists,
			'filter_enabled' => $filter_enabled,
		);

		if ( $file_exists && $filter_enabled ) {
			return $this->build_result(
				'xmlrpc',
				$label,
				self::STATUS_WARNING,
				__( 'XML-RPC is enabled. If you do not use the WordPress mobile app or remote publishing, consider disabling it to reduce the brute-force attack surface.', 'tailwatch' ),
				$details
			);
		}

		return $this->build_result(
			'xmlrpc',
			$label,
			self::STATUS_SECURE,
			__( 'XML-RPC is disabled or blocked.', 'tailwatch' ),
			$details
		);
	}

	/**
	 * Heuristic check — reports SECURE only when *something* has hooked the
	 * `rest_authentication_errors` filter. Many plugins hook this filter for
	 * unrelated reasons (custom JWT auth, third-party permission frameworks,
	 * even a few firewalls), so a SECURE result here means "some plugin is
	 * gating the REST API," NOT necessarily "the REST API is locked down for
	 * unauthenticated users." Treat this signal as a hint, not proof.
	 *
	 * @return array
	 */
	public function check_rest_api() {
		$label       = __( 'REST API', 'tailwatch' );
		$has_blocker = (bool) has_filter( 'rest_authentication_errors' );

		if ( ! $has_blocker ) {
			return $this->build_result(
				'rest_api',
				$label,
				self::STATUS_NOTICE,
				__( 'The WordPress REST API is open to anonymous visitors. Enable REST API protection in Tailwatch settings to require authentication.', 'tailwatch' ),
				array( 'restricted' => false )
			);
		}

		return $this->build_result(
			'rest_api',
			$label,
			self::STATUS_SECURE,
			__( 'The REST API is restricted.', 'tailwatch' ),
			array( 'restricted' => true )
		);
	}

	/**
	 * Heuristic check — same caveat as `check_rest_api`. SECURE here just
	 * means *some plugin* hooked `redirect_canonical` or `rest_endpoints`
	 * (any SEO plugin, redirect manager, or canonical-URL tweaker will), NOT
	 * proof that `?author=N` enumeration is actually blocked. The check
	 * stays useful as a coarse "did anything attempt to harden this?" probe
	 * — but a SECURE here should not be taken as authoritative without a
	 * live HTTP probe (which we deliberately avoid because it deadlocks
	 * single-worker servers and trips WAFs).
	 *
	 * @return array
	 */
	public function check_user_enumeration() {
		$label = __( 'User Enumeration', 'tailwatch' );

		$has_canonical_filter = (bool) has_filter( 'redirect_canonical' );
		$has_rest_endpoints   = (bool) has_filter( 'rest_endpoints' );
		$blocked              = $has_canonical_filter || $has_rest_endpoints;

		if ( ! $blocked ) {
			return $this->build_result(
				'user_enumeration',
				$label,
				self::STATUS_WARNING,
				__( 'User enumeration via ?author=N may be possible. Enable the path-protection feature to block it.', 'tailwatch' ),
				array( 'blocked' => false )
			);
		}

		return $this->build_result(
			'user_enumeration',
			$label,
			self::STATUS_SECURE,
			__( 'User enumeration is blocked.', 'tailwatch' ),
			array( 'blocked' => true )
		);
	}

	public function check_plugin_updates() {
		$label = __( 'Plugin Updates', 'tailwatch' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Read the cached update_plugins site transient WordPress maintains.
		// Calling wp_update_plugins() here would force a network round-trip
		// to wp.org for every plugin on every audit tick.
		$update_plugins  = get_site_transient( 'update_plugins' );
		$pending_updates = array();

		if ( $update_plugins && ! empty( $update_plugins->response ) && is_array( $update_plugins->response ) ) {
			$all_plugins = get_plugins();
			foreach ( $update_plugins->response as $plugin_file => $plugin_update ) {
				if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
					continue;
				}
				$pending_updates[] = array(
					'name'              => $all_plugins[ $plugin_file ]['Name'],
					'current_version'   => $all_plugins[ $plugin_file ]['Version'],
					'available_version' => isset( $plugin_update->new_version ) ? $plugin_update->new_version : '',
				);
			}
		}

		if ( ! empty( $pending_updates ) ) {
			$count = count( $pending_updates );
			return $this->build_result(
				'plugin_updates',
				$label,
				self::STATUS_WARNING,
				sprintf(
					/* translators: %d: number of plugins with available updates */
					_n(
						'%d plugin has an available update.',
						'%d plugins have available updates.',
						$count,
						'tailwatch'
					),
					$count
				),
				array(
					'count'   => $count,
					'plugins' => $pending_updates,
				)
			);
		}

		return $this->build_result(
			'plugin_updates',
			$label,
			self::STATUS_SECURE,
			__( 'All plugins are up to date.', 'tailwatch' )
		);
	}

	public function check_theme_updates() {
		$label           = __( 'Theme Updates', 'tailwatch' );
		$update_themes   = get_site_transient( 'update_themes' );
		$pending_updates = array();

		if ( $update_themes && ! empty( $update_themes->response ) && is_array( $update_themes->response ) ) {
			$all_themes = wp_get_themes();
			foreach ( $update_themes->response as $slug => $theme_update ) {
				if ( ! isset( $all_themes[ $slug ] ) ) {
					continue;
				}
				$pending_updates[] = array(
					'name'              => $all_themes[ $slug ]->get( 'Name' ),
					'current_version'   => $all_themes[ $slug ]->get( 'Version' ),
					'available_version' => isset( $theme_update['new_version'] ) ? $theme_update['new_version'] : '',
				);
			}
		}

		if ( ! empty( $pending_updates ) ) {
			$count = count( $pending_updates );
			return $this->build_result(
				'theme_updates',
				$label,
				self::STATUS_WARNING,
				sprintf(
					/* translators: %d: number of themes with available updates */
					_n(
						'%d theme has an available update.',
						'%d themes have available updates.',
						$count,
						'tailwatch'
					),
					$count
				),
				array(
					'count'  => $count,
					'themes' => $pending_updates,
				)
			);
		}

		return $this->build_result(
			'theme_updates',
			$label,
			self::STATUS_SECURE,
			__( 'All themes are up to date.', 'tailwatch' )
		);
	}

	/**
	 * Scan the uploads directory recursively for executable PHP files.
	 *
	 * The uploads directory should never contain PHP — it's the canonical
	 * landing spot for shell uploads after a plugin/theme exploit. Even a
	 * single .php in there usually means the site is already compromised.
	 *
	 * Caps the scan at 5,000 files to keep the cron tick bounded on large
	 * media libraries, and caps reported items at 50 so a worst-case
	 * compromised site doesn't ship a megabyte of paths through the
	 * report row.
	 *
	 * @return array
	 */
	public function check_uploads_php_files() {
		$label    = __( 'PHP Files in Uploads', 'tailwatch' );
		$uploads  = wp_get_upload_dir();
		$base_dir = $uploads['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			return $this->build_result(
				'uploads_php_files',
				$label,
				self::STATUS_SECURE,
				__( 'Uploads directory not found — nothing to scan.', 'tailwatch' )
			);
		}

		$found     = array();
		$php_exts  = array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8' );
		$file_cap  = 5000;
		$found_cap = 50;
		$scanned   = 0;
		$truncated = false;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ++$scanned > $file_cap ) {
					$truncated = true;
					break;
				}
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( in_array( $ext, $php_exts, true ) ) {
					$found[] = $this->relative_path( $file->getPathname() );
					if ( count( $found ) >= $found_cap ) {
						$truncated = true;
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return $this->build_error_result( 'uploads_php_files', $label, $e );
		}

		if ( ! empty( $found ) ) {
			return $this->build_result(
				'uploads_php_files',
				$label,
				self::STATUS_CRITICAL,
				sprintf(
					/* translators: %d: number of PHP files found in uploads */
					_n(
						'Found %d PHP file in your uploads directory — uploads should never contain executable PHP.',
						'Found %d PHP files in your uploads directory — uploads should never contain executable PHP.',
						count( $found ),
						'tailwatch'
					),
					count( $found )
				),
				array(
					'files'     => $found,
					'truncated' => $truncated,
					'scanned'   => $scanned,
				)
			);
		}

		return $this->build_result(
			'uploads_php_files',
			$label,
			self::STATUS_SECURE,
			__( 'No PHP files were found in your uploads directory.', 'tailwatch' ),
			array(
				'truncated' => $truncated,
				'scanned'   => $scanned,
			)
		);
	}

	/**
	 * Check whether anonymous user registration is enabled, and if so, what
	 * default role new accounts get.
	 *
	 * Reports tiered severity:
	 *   - CRITICAL : registration open AND default role grants editor+
	 *                (any random visitor can publish content / install
	 *                plugins / change settings)
	 *   - WARNING  : registration open with subscriber-tier default role
	 *                (still a spam / credential-spray surface, but bounded)
	 *   - SECURE   : registration closed
	 *
	 * @return array
	 */
	public function check_user_registration() {
		$label        = __( 'User Registration', 'tailwatch' );
		$can_register = (bool) get_option( 'users_can_register', false );
		$default_role = (string) get_option( 'default_role', 'subscriber' );

		$details = array(
			'users_can_register' => $can_register,
			'default_role'       => $default_role,
		);

		if ( ! $can_register ) {
			return $this->build_result(
				'user_registration',
				$label,
				self::STATUS_SECURE,
				__( 'New user registration is disabled.', 'tailwatch' ),
				$details
			);
		}

		$privileged = array( 'administrator', 'editor', 'author', 'shop_manager' );
		if ( in_array( $default_role, $privileged, true ) ) {
			return $this->build_result(
				'user_registration',
				$label,
				self::STATUS_CRITICAL,
				sprintf(
					/* translators: %s: default role name */
					__( 'Anyone can register, and the default role is "%s" — strangers gain privileged access. Disable open registration or downgrade the default role.', 'tailwatch' ),
					$default_role
				),
				$details
			);
		}

		return $this->build_result(
			'user_registration',
			$label,
			self::STATUS_WARNING,
			sprintf(
				/* translators: %s: default role name */
				__( 'New user registration is open (default role: %s). Disable it if you do not need anonymous signups.', 'tailwatch' ),
				$default_role
			),
			$details
		);
	}

	/**
	 * Check whether the dashboard plugin/theme file editor is disabled.
	 *
	 * `DISALLOW_FILE_EDIT` removes the in-browser PHP editor — without it,
	 * a single compromised admin account can write arbitrary PHP through
	 * the dashboard, no exploit chain required. WP itself recommends this
	 * constant for production sites.
	 *
	 * @return array
	 */
	public function check_file_editor() {
		$label    = __( 'File Editor', 'tailwatch' );
		$disallow = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;

		$details = array( 'disallow_file_edit' => $disallow );

		if ( $disallow ) {
			return $this->build_result(
				'file_editor',
				$label,
				self::STATUS_SECURE,
				__( 'The plugin/theme file editor is disabled (DISALLOW_FILE_EDIT is set).', 'tailwatch' ),
				$details
			);
		}

		return $this->build_result(
			'file_editor',
			$label,
			self::STATUS_WARNING,
			__( 'The plugin/theme file editor is enabled. A compromised admin account could write malicious PHP through it. Add define(\'DISALLOW_FILE_EDIT\', true) to wp-config.php.', 'tailwatch' ),
			$details
		);
	}

	/**
	 * Check whether WordPress Application Passwords are enabled.
	 *
	 * Application Passwords (introduced in WP 5.6) let any user generate
	 * long-lived tokens that authenticate via Basic Auth on the REST API,
	 * bypassing 2FA and session-based protections. They're useful for sites
	 * with legitimate API integrations; they're a needless attack surface
	 * for sites that don't use them. Reports as NOTICE (informational) so
	 * users with deliberate API integrations don't see it as a "fix me."
	 *
	 * @return array
	 */
	public function check_application_passwords() {
		$label = __( 'Application Passwords', 'tailwatch' );

		// WP < 5.6 doesn't have the feature at all.
		if ( ! function_exists( 'wp_is_application_passwords_available' ) ) {
			return $this->build_result(
				'application_passwords',
				$label,
				self::STATUS_SECURE,
				__( 'Application Passwords are not available on this WordPress version.', 'tailwatch' ),
				array( 'available' => false )
			);
		}

		$available = (bool) wp_is_application_passwords_available();

		if ( ! $available ) {
			return $this->build_result(
				'application_passwords',
				$label,
				self::STATUS_SECURE,
				__( 'Application Passwords are disabled.', 'tailwatch' ),
				array( 'available' => false )
			);
		}

		return $this->build_result(
			'application_passwords',
			$label,
			self::STATUS_NOTICE,
			__( 'Application Passwords are enabled. If you do not use REST API integrations, consider disabling this feature with the wp_is_application_passwords_available filter.', 'tailwatch' ),
			array( 'available' => true )
		);
	}

	/**
	 * Check whether the "Discourage search engines from indexing this site"
	 * setting was accidentally left on after launch.
	 *
	 * Reading → Search Engine Visibility maps to the `blog_public` option:
	 * 1 = visible, 0 = discouraged. Many sites get launched with 0 from
	 * the staging environment and silently disappear from Google for weeks.
	 * Not a security risk per se, but flagged because it's almost always
	 * unintentional on a production install.
	 *
	 * @return array
	 */
	public function check_search_engine_visibility() {
		$label       = __( 'Search Engine Visibility', 'tailwatch' );
		$blog_public = (int) get_option( 'blog_public', 1 );

		if ( 0 === $blog_public ) {
			return $this->build_result(
				'search_engine_visibility',
				$label,
				self::STATUS_WARNING,
				__( 'Search engines are discouraged from indexing this site. If this is a production site, enable indexing in Settings → Reading.', 'tailwatch' ),
				array( 'blog_public' => 0 )
			);
		}

		return $this->build_result(
			'search_engine_visibility',
			$label,
			self::STATUS_SECURE,
			__( 'Search engines can index this site.', 'tailwatch' ),
			array( 'blog_public' => 1 )
		);
	}

	/**
	 * Check whether pingbacks/trackbacks are accepted on new posts by default.
	 *
	 * `default_ping_status='open'` means new posts will accept incoming
	 * pingbacks/trackbacks, which arrive as comments and are a low-effort
	 * spam vector. NOT a DDoS amplification check — that vulnerability is
	 * the XML-RPC `pingback.ping` method (covered separately by
	 * {@see check_xmlrpc}) and is independent of this option.
	 *
	 * Reported as NOTICE because turning pingbacks off is a one-click
	 * Settings → Discussion change with almost no downside for most sites.
	 *
	 * @return array
	 */
	public function check_pingbacks() {
		$label               = __( 'Pingbacks', 'tailwatch' );
		$default_ping_status = (string) get_option( 'default_ping_status', 'open' );

		$details = array( 'default_ping_status' => $default_ping_status );

		if ( 'open' === $default_ping_status ) {
			return $this->build_result(
				'pingbacks',
				$label,
				self::STATUS_NOTICE,
				__( 'Pingbacks are accepted on new posts by default. They are commonly used as a comment-spam vector — disable them in Settings → Discussion if you do not need them.', 'tailwatch' ),
				$details
			);
		}

		return $this->build_result(
			'pingbacks',
			$label,
			self::STATUS_SECURE,
			__( 'Pingbacks are disabled by default on new posts.', 'tailwatch' ),
			$details
		);
	}
}