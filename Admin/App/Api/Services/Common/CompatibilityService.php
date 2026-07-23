<?php
/**
 * Compatibility Service
 *
 * Provides compatibility checks for WordPress core, plugins, and themes.
 * Validates version compatibility, dependencies, and system requirements before updates or rollbacks.
 *
 * @package    Tailwatch
 * @subpackage Services/Common
 */

namespace Tailwatch\Admin\App\Api\Services\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CompatibilityService
 *
 * Handles compatibility validation for core, plugins, and themes.
 * Checks WordPress version, PHP version, MySQL version, and dependency compatibility.
 *
 */
class CompatibilityService {

	/**
	 * Fallback PHP requirements by version.
	 *
	 * FALLBACK ONLY - Used when WordPress.org API is unavailable.
	 * Primary data source is the API via fetch_wp_version_requirements().
	 *
	 * @var array
	 */
	private static $fallback_php_requirements = array(
		'6.7' => '7.2.24',
		'6.5' => '7.0.0',
		'6.0' => '5.6.20',
		'5.2' => '5.6.20',
	);

	/**
	 * Fallback MySQL requirements by version.
	 *
	 * FALLBACK ONLY - Used when WordPress.org API is unavailable.
	 * Primary data source is the API via fetch_wp_version_requirements().
	 *
	 * @var array
	 */
	private static $fallback_mysql_requirements = array(
		'6.7' => '5.5.5',
		'6.5' => '5.5.5',
		'6.0' => '5.0.15',
		'5.2' => '5.0.15',
	);

	/**
	 * Check version compatibility.
	 *
	 * Main entry point for compatibility checks. Routes to type-specific handlers.
	 * Used for both updates and rollbacks.
	 *
	 * @param string $type            Type of item ('core', 'plugin', 'theme').
	 * @param string $slug            Plugin or theme slug (null for core).
	 * @param string $target_version  Version to install.
	 * @param string $current_version Current installed version.
	 *
	 * @return array Compatibility check result.
	 */
	public static function check_compatibility(
		string $type,
		?string $slug,
		string $target_version,
		string $current_version
	): array {
		$type            = sanitize_text_field( $type );
		$slug            = $slug ? sanitize_text_field( $slug ) : null;
		$target_version  = sanitize_text_field( $target_version );
		$current_version = sanitize_text_field( $current_version );

		$action = self::get_version_action( $current_version, $target_version );

		$result = array(
			'type'                  => $type,
			'slug'                  => $slug,
			'current_version'       => $current_version,
			'target_version'        => $target_version,
			'action'                => $action, // 'upgrade' or 'downgrade'
			'is_compatible'         => false,
			'blocking_issues'       => array(),
			'warnings'              => array(),
			'affected_dependencies' => array(),
			'recommended_actions'   => array(),
		);

		switch ( $type ) {
			case 'core':
				$result = self::check_core_compatibility( $result );
				break;

			case 'plugin':
				$result = self::check_plugin_compatibility( $result );
				break;

			case 'theme':
				$result = self::check_theme_compatibility( $result );
				break;

			default:
				$result['blocking_issues'][] = array(
					'code'    => 'INVALID_TYPE',
					'message' => __( 'Invalid item type specified. Must be core, plugin, or theme.', 'tailwatch' ),
				);
				return $result;
		}

		return $result;
	}

	/**
	 * Check WordPress core compatibility.
	 *
	 * Validates plugin/theme compatibility, PHP version, and MySQL version.
	 *
	 * @param array $data Initial compatibility data.
	 *
	 * @return array Updated compatibility data.
	 */
	private static function check_core_compatibility( array $data ): array {
		$target_version = $data['target_version'];

		// Step 1: Validate target WordPress version exists.
		if ( ! self::is_valid_wp_version( $target_version ) ) {
			$data['blocking_issues'][] = array(
				'code'    => 'INVALID_VERSION',
				'message' => sprintf(
					'WordPress version %s does not exist or is not available.',
					$target_version
				),
			);
			return $data;
		}

		// Step 2: Check PHP compatibility.
		$php_compat = self::check_php_compatibility_for_wp( $target_version );
		if ( ! $php_compat['compatible'] ) {
			$data['blocking_issues'][] = array(
				'code'         => 'PHP_INCOMPATIBLE',
				'message'      => $php_compat['message'],
				'required_php' => $php_compat['required_php'],
				'current_php'  => PHP_VERSION,
			);
		}

		// Step 3: Check MySQL compatibility.
		$mysql_compat = self::check_mysql_compatibility_for_wp( $target_version );
		if ( ! $mysql_compat['compatible'] ) {
			$data['blocking_issues'][] = array(
				'code'           => 'MYSQL_INCOMPATIBLE',
				'message'        => $mysql_compat['message'],
				'required_mysql' => $mysql_compat['required_mysql'],
				'current_mysql'  => $mysql_compat['current_mysql'],
			);
		}

		// Step 4: Check active plugins compatibility.
		$active_plugins = self::get_active_plugins_data();
		foreach ( $active_plugins as $plugin_file => $plugin_data ) {
			$plugin_compat = self::check_plugin_wp_compatibility( $plugin_data, $target_version );

			if ( ! $plugin_compat['compatible'] ) {
				$data['affected_dependencies'][] = array(
					'type'            => 'plugin',
					'name'            => $plugin_data['Name'],
					'file'            => $plugin_file,
					'issue'           => $plugin_compat['reason'],
					'severity'        => $plugin_compat['severity'],
					'required_wp_min' => $plugin_compat['requires_wp'] ?? null,
					'tested_wp_max'   => $plugin_compat['tested_wp'] ?? null,
					'action_required' => $plugin_compat['action_required'],
				);

				if ( 'critical' === $plugin_compat['severity'] ) {
					$data['blocking_issues'][] = array(
						'code'    => 'PLUGIN_INCOMPATIBLE',
						'message' => sprintf(
							"Plugin '%s' requires WordPress min %s or higher.",
							$plugin_data['Name'],
							$plugin_compat['requires_wp']
						),
						'plugin'  => $plugin_file,
					);
				}
			}
		}

		// Step 5: Check active theme compatibility.
		$active_theme = self::get_active_theme_data();
		$theme_compat = self::check_theme_wp_compatibility( $active_theme, $target_version );

		if ( ! $theme_compat['compatible'] ) {
			$data['affected_dependencies'][] = array(
				'type'            => 'theme',
				'name'            => $active_theme['name'],
				'slug'            => $active_theme['stylesheet'],
				'issue'           => $theme_compat['reason'],
				'severity'        => $theme_compat['severity'],
				'required_wp_min' => $theme_compat['requires_wp'] ?? null,
				'tested_wp_max'   => $theme_compat['tested_wp'] ?? null,
				'action_required' => $theme_compat['action_required'],
			);

			if ( 'critical' === $theme_compat['severity'] ) {
				$data['blocking_issues'][] = array(
					'code'    => 'THEME_INCOMPATIBLE',
					'message' => sprintf(
						"Theme '%s' requires WordPress min %s or higher.",
						$active_theme['name'],
						$theme_compat['requires_wp']
					),
					'theme'   => $active_theme['stylesheet'],
				);
			}
		}

		// Step 6: Generate recommended actions.
		$data['recommended_actions'] = self::generate_core_actions( $data );

		// Step 7: Determine if action can proceed.
		$data['is_compatible'] = empty( $data['blocking_issues'] );

		// Step 8: Add warnings for soft incompatibilities.
		if ( $data['is_compatible'] && ! empty( $data['affected_dependencies'] ) ) {
			$data['warnings'][] = array(
				'code'           => 'SOFT_INCOMPATIBILITY',
				'message'        => sprintf(
					'%d plugin(s)/theme(s) may have compatibility issues.',
					count( $data['affected_dependencies'] )
				),
				'recommendation' => 'Review affected items and consider deactivating them before proceeding.',
			);
		}

		return $data;
	}

	/**
	 * Check plugin compatibility.
	 *
	 * Validates WordPress/PHP compatibility and plugin dependencies.
	 *
	 * @param array $data Initial compatibility data.
	 *
	 * @return array Updated compatibility data.
	 */
	private static function check_plugin_compatibility( array $data ): array {
		global $wp_version;

		$plugin_slug    = $data['slug'];
		$target_version = $data['target_version'];
		$is_downgrade   = 'downgrade' === $data['action'];

		// Step 1: Get plugin info from WordPress.org API.
		$plugin_info = self::get_plugin_api_info( $plugin_slug );

		if ( ! $plugin_info || isset( $plugin_info['error'] ) ) {
			$data['blocking_issues'][] = array(
				'code'    => 'PLUGIN_NOT_FOUND',
				'message' => sprintf(
					'Plugin %s not found in WordPress.org repository.',
					$plugin_slug
				),
			);
			return $data;
		}

		// Step 2: Check if target version exists.
		if ( ! self::check_plugin_version_exists( $plugin_info, $target_version ) ) {
			$data['blocking_issues'][] = array(
				'code'    => 'VERSION_NOT_FOUND',
				'message' => sprintf(
					'Plugin version %s not found or not available for download.',
					$target_version
				),
			);
			return $data;
		}

		// Step 3: Check WordPress compatibility.
		$requires_wp = $plugin_info['requires'] ?? null;
		$tested_wp   = $plugin_info['tested'] ?? null;

		if ( $requires_wp && version_compare( $wp_version, $requires_wp, '<' ) ) {
			$data['blocking_issues'][] = array(
				'code'        => 'WP_VERSION_INCOMPATIBLE',
				'message'     => sprintf(
					'This plugin version requires WordPress min %s or higher. Current version is %s.',
					$requires_wp,
					$wp_version
				),
				'requires_wp' => $requires_wp,
				'current_wp'  => $wp_version,
			);
		} elseif ( $tested_wp && version_compare( $wp_version, $tested_wp, '>' ) ) {
			$data['warnings'][] = array(
				'code'      => 'WP_VERSION_WARNING',
				'message'   => sprintf(
					'This plugin version was only tested up to WordPress %s. Current version is %s.',
					$tested_wp,
					$wp_version
				),
				'tested_wp' => $tested_wp,
				'severity'  => 'warning',
			);
		}

		// Step 4: Check PHP compatibility.
		$requires_php = $plugin_info['requires_php'] ?? null;
		if ( $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			$data['blocking_issues'][] = array(
				'code'         => 'PHP_INCOMPATIBLE',
				'message'      => sprintf(
					'This plugin version requires PHP min %s or higher. Current version is %s.',
					$requires_php,
					PHP_VERSION
				),
				'requires_php' => $requires_php,
				'current_php'  => PHP_VERSION,
			);
		}

		// Step 5: Check for dependent plugins (plugins that depend on this one).
		$dependents = self::find_dependent_plugins( $plugin_slug );
		if ( ! empty( $dependents ) ) {
			foreach ( $dependents as $dependent ) {
				$data['affected_dependencies'][] = array(
					'type'            => 'plugin',
					'name'            => $dependent['name'],
					'file'            => $dependent['file'],
					'issue'           => sprintf(
						"Plugin '%s' depends on this plugin.",
						$dependent['name']
					),
					'severity'        => 'warning',
					'action_required' => 'review',
				);
			}

			$data['warnings'][] = array(
				'code'           => 'HAS_DEPENDENTS',
				'message'        => sprintf(
					'%d plugin(s) depend on this plugin and may be affected.',
					count( $dependents )
				),
				'recommendation' => 'Test dependent plugins after version change.',
			);
		}

		// Step 6: Check for required plugins (plugins this one depends on).
		$requires_plugins = $plugin_info['requires_plugins'] ?? array();
		if ( ! empty( $requires_plugins ) ) {
			$missing_deps = self::check_required_plugins( $requires_plugins );
			if ( ! empty( $missing_deps ) ) {
				foreach ( $missing_deps as $missing ) {
					$data['blocking_issues'][] = array(
						'code'            => 'MISSING_DEPENDENCY',
						'message'         => sprintf(
							"Required plugin '%s' is %s.",
							$missing['slug'],
							$missing['status']
						),
						'required_plugin' => $missing['slug'],
					);
				}
			}
		}

		// Step 7: Database compatibility warning for downgrades.
		if ( $is_downgrade ) {
			$data['warnings'][] = array(
				'code'           => 'DATABASE_DOWNGRADE_RISK',
				'message'        => __( 'Moving to an older version may cause database issues if the plugin has run migrations.', 'tailwatch' ),
				'severity'       => 'warning',
				'recommendation' => 'Backup database before proceeding.',
			);
		}

		// Step 8: Generate recommended actions.
		$data['recommended_actions'] = self::generate_plugin_actions( $data );

		// Step 9: Determine if action can proceed.
		$data['is_compatible'] = empty( $data['blocking_issues'] );

		return $data;
	}

	/**
	 * Check theme compatibility.
	 *
	 * Validates WordPress/PHP compatibility and child theme relationships.
	 *
	 * @param array $data Initial compatibility data.
	 *
	 * @return array Updated compatibility data.
	 */
	private static function check_theme_compatibility( array $data ): array {
		global $wp_version;

		$theme_slug     = $data['slug'];
		$target_version = $data['target_version'];
		$is_downgrade   = 'downgrade' === $data['action'];

		// Step 1: Get theme info from WordPress.org API.
		$theme_info = self::get_theme_api_info( $theme_slug );

		if ( ! $theme_info || isset( $theme_info['error'] ) ) {
			$data['blocking_issues'][] = array(
				'code'    => 'THEME_NOT_FOUND',
				'message' => sprintf(
					'Theme %s not found in WordPress.org repository.',
					$theme_slug
				),
			);
			return $data;
		}

		// Step 2: Check if target version exists.
		if ( ! self::check_theme_version_exists( $theme_info, $target_version ) ) {
			$data['blocking_issues'][] = array(
				'code'    => 'VERSION_NOT_FOUND',
				'message' => sprintf(
					'Theme version %s not found or not available for download.',
					$target_version
				),
			);
			return $data;
		}

		// Step 3: Check WordPress compatibility.
		$requires_wp = $theme_info['requires'] ?? null;

		if ( $requires_wp && version_compare( $wp_version, $requires_wp, '<' ) ) {
			$data['blocking_issues'][] = array(
				'code'        => 'WP_VERSION_INCOMPATIBLE',
				'message'     => sprintf(
					'This theme version requires WordPress min %s or higher. Current version is %s.',
					$requires_wp,
					$wp_version
				),
				'requires_wp' => $requires_wp,
				'current_wp'  => $wp_version,
			);
		} elseif ( isset( $theme_info['tested'] ) && version_compare( $wp_version, $theme_info['tested'], '>' ) ) {
			$data['warnings'][] = array(
				'code'      => 'WP_VERSION_WARNING',
				'message'   => sprintf(
					'This theme version was only tested up to WordPress %s. Current version is %s.',
					$theme_info['tested'],
					$wp_version
				),
				'tested_wp' => $theme_info['tested'],
				'severity'  => 'warning',
			);
		}

		// Step 4: Check PHP compatibility.
		$requires_php = $theme_info['requires_php'] ?? null;
		if ( $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			$data['blocking_issues'][] = array(
				'code'         => 'PHP_INCOMPATIBLE',
				'message'      => sprintf(
					'This theme version requires PHP min %s or higher. Current version is %s.',
					$requires_php,
					PHP_VERSION
				),
				'requires_php' => $requires_php,
				'current_php'  => PHP_VERSION,
			);
		}

		// Step 5: Check for child themes.
		$child_themes = self::find_child_themes( $theme_slug );
		if ( ! empty( $child_themes ) ) {
			foreach ( $child_themes as $child ) {
				$data['affected_dependencies'][] = array(
					'type'            => 'child_theme',
					'name'            => $child['name'],
					'slug'            => $child['slug'],
					'issue'           => sprintf(
						"Child theme '%s' uses this as parent theme.",
						$child['name']
					),
					'severity'        => 'warning',
					'action_required' => 'review',
				);
			}

			$data['warnings'][] = array(
				'code'           => 'HAS_CHILD_THEMES',
				'message'        => sprintf(
					'%d child theme(s) use this as parent and may be affected.',
					count( $child_themes )
				),
				'recommendation' => 'Test child themes after version change.',
			);
		}

		// Step 6: Customizer settings warning for downgrades.
		if ( $is_downgrade ) {
			$data['warnings'][] = array(
				'code'           => 'CUSTOMIZER_SETTINGS_RISK',
				'message'        => __( 'Moving to an older version may cause customizer settings to be incompatible.', 'tailwatch' ),
				'severity'       => 'warning',
				'recommendation' => 'Export customizer settings before proceeding.',
			);
		}

		// Step 7: Generate recommended actions.
		$data['recommended_actions'] = self::generate_theme_actions( $data );

		// Step 8: Determine if action can proceed.
		$data['is_compatible'] = empty( $data['blocking_issues'] );

		return $data;
	}

	/**
	 * Determine if action is upgrade or downgrade.
	 *
	 * @param string $current Current version.
	 * @param string $target  Target version.
	 *
	 * @return string 'upgrade' or 'downgrade'.
	 */
	private static function get_version_action( string $current, string $target ): string {
		return version_compare( $target, $current, '>' ) ? 'upgrade' : 'downgrade';
	}

	/**
	 * Validate WordPress version exists.
	 *
	 * @param string $version WordPress version to check.
	 *
	 * @return bool True if version exists, false otherwise.
	 */
	private static function is_valid_wp_version( string $version ): bool {
		$response = wp_remote_get(
			'https://api.wordpress.org/core/stable-check/1.0/',
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$versions = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $versions ) ) {
			return false;
		}

		return array_key_exists( $version, $versions );
	}

	/**
	 * Check PHP compatibility for WordPress version.
	 *
	 * @param string $wp_version Target WordPress version.
	 *
	 * @return array Compatibility result.
	 */
	private static function check_php_compatibility_for_wp( string $wp_version ): array {
		$result = array(
			'compatible' => true,
			'message'    => '',
		);

		$required_php = self::get_php_requirement_for_wp( $wp_version );

		if ( $required_php && version_compare( PHP_VERSION, $required_php, '<' ) ) {
			$result['compatible']   = false;
			$result['message']      = sprintf(
				'WordPress %s requires PHP min %s or higher. Current PHP version is %s.',
				$wp_version,
				$required_php,
				PHP_VERSION
			);
			$result['required_php'] = $required_php;
		}

		return $result;
	}

	/**
	 * Check MySQL compatibility for WordPress version.
	 *
	 * @param string $wp_version Target WordPress version.
	 *
	 * @return array Compatibility result.
	 */
	private static function check_mysql_compatibility_for_wp( string $wp_version ): array {
		global $wpdb;

		$current_mysql = $wpdb->db_version();

		$result = array(
			'compatible'    => true,
			'message'       => '',
			'current_mysql' => $current_mysql,
		);

		$required_mysql = self::get_mysql_requirement_for_wp( $wp_version );

		if ( $required_mysql && version_compare( $current_mysql, $required_mysql, '<' ) ) {
			$result['compatible']     = false;
			$result['message']        = sprintf(
				'WordPress %s requires MySQL min %s or higher. Current MySQL version is %s.',
				$wp_version,
				$required_mysql,
				$current_mysql
			);
			$result['required_mysql'] = $required_mysql;
		}

		return $result;
	}

	/**
	 * Get PHP requirement for WordPress version.
	 *
	 * Uses WordPress.org API first, falls back to static data only if API fails.
	 *
	 * @param string $wp_version WordPress version.
	 *
	 * @return string|null Required PHP version or null.
	 */
	private static function get_php_requirement_for_wp( string $wp_version ): ?string {
		// Try to get from WordPress.org API first.
		$api_requirement = self::fetch_wp_version_requirements( $wp_version );

		if ( isset( $api_requirement['php'] ) && ! empty( $api_requirement['php'] ) ) {
			return $api_requirement['php'];
		}

		// Fallback to static data for common versions.
		return self::get_requirement_for_version( $wp_version, self::$fallback_php_requirements );
	}

	/**
	 * Get MySQL requirement for WordPress version.
	 *
	 * Uses WordPress.org API first, falls back to static data only if API fails.
	 *
	 * @param string $wp_version WordPress version.
	 *
	 * @return string|null Required MySQL version or null.
	 */
	private static function get_mysql_requirement_for_wp( string $wp_version ): ?string {
		// Try to get from WordPress.org API first.
		$api_requirement = self::fetch_wp_version_requirements( $wp_version );

		if ( isset( $api_requirement['mysql'] ) && ! empty( $api_requirement['mysql'] ) ) {
			return $api_requirement['mysql'];
		}

		// Fallback to static data for common versions.
		return self::get_requirement_for_version( $wp_version, self::$fallback_mysql_requirements );
	}

	/**
	 * Fetch WordPress version requirements from API.
	 *
	 * Gets PHP and MySQL requirements for a specific WordPress version.
	 * Results are cached in transient for 24 hours.
	 *
	 * @param string $wp_version WordPress version.
	 *
	 * @return array|null Requirements array with 'php' and 'mysql' keys, or null on failure.
	 */
	private static function fetch_wp_version_requirements( string $wp_version ): ?array {
		// Check transient cache first.
		$cache_key = 'wptw_wp_reqs_' . sanitize_key( $wp_version );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Fetch from WordPress.org API.
		$response = wp_remote_get(
			'https://api.wordpress.org/core/version-check/1.7/',
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['offers'] ) || ! is_array( $data['offers'] ) ) {
			return null;
		}

		// Find the specific version in offers.
		foreach ( $data['offers'] as $offer ) {
			if ( isset( $offer['version'] ) && $offer['version'] === $wp_version ) {
				$requirements = array(
					'php'   => $offer['php_version'] ?? null,
					'mysql' => $offer['mysql_version'] ?? null,
				);

				// Cache for 24 hours.
				set_transient( $cache_key, $requirements, DAY_IN_SECONDS );

				return $requirements;
			}
		}

		// Version not found in offers - cache empty result to avoid repeated API calls.
		$empty_result = array(
			'php'   => null,
			'mysql' => null,
		);
		set_transient( $cache_key, $empty_result, HOUR_IN_SECONDS );

		return null;
	}

	/**
	 * Get requirement for closest matching version.
	 *
	 * @param string $version      Version to check.
	 * @param array  $requirements Requirements array keyed by version.
	 *
	 * @return string|null Requirement value or null.
	 */
	private static function get_requirement_for_version( string $version, array $requirements ): ?string {
		// First try exact match.
		if ( isset( $requirements[ $version ] ) ) {
			return $requirements[ $version ];
		}

		// Try major.minor match.
		$parts       = explode( '.', $version );
		$major_minor = $parts[0] . '.' . ( $parts[1] ?? '0' );
		if ( isset( $requirements[ $major_minor ] ) ) {
			return $requirements[ $major_minor ];
		}

		// Find closest lower version.
		$closest = null;
		foreach ( $requirements as $req_version => $requirement ) {
			if ( version_compare( $version, $req_version, '>=' ) ) {
				$closest = $requirement;
				break;
			}
		}

		return $closest;
	}

	/**
	 * Get active plugins with their data.
	 *
	 * @return array Active plugins data.
	 */
	private static function get_active_plugins_data(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$result         = array();

		foreach ( $active_plugins as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$result[ $plugin_file ] = $all_plugins[ $plugin_file ];
			}
		}

		return $result;
	}

	/**
	 * Get active theme data.
	 *
	 * @return array Active theme data.
	 */
	private static function get_active_theme_data(): array {
		$theme = wp_get_theme();

		return array(
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'stylesheet'   => $theme->get_stylesheet(),
			'template'     => $theme->get_template(),
			'requires_wp'  => $theme->get( 'RequiresWP' ),
			'requires_php' => $theme->get( 'RequiresPHP' ),
		);
	}

	/**
	 * Check plugin compatibility with WordPress version.
	 *
	 * @param array  $plugin_data      Plugin data array.
	 * @param string $target_wp_version Target WordPress version.
	 *
	 * @return array Compatibility result.
	 */
	private static function check_plugin_wp_compatibility( array $plugin_data, string $target_wp_version ): array {
		$result = array(
			'compatible'      => true,
			'severity'        => 'none',
			'reason'          => '',
			'action_required' => 'none',
		);

		$requires_wp = $plugin_data['RequiresWP'] ?? null;
		$tested_wp   = $plugin_data['TestedUpTo'] ?? null;

		// Check minimum WordPress requirement.
		if ( $requires_wp && version_compare( $target_wp_version, $requires_wp, '<' ) ) {
			$result['compatible']      = false;
			$result['severity']        = 'critical';
			$result['reason']          = sprintf(
				'Plugin requires WordPress %s or higher. Target version is %s.',
				$requires_wp,
				$target_wp_version
			);
			$result['requires_wp']     = $requires_wp;
			$result['action_required'] = 'deactivate';
			return $result;
		}

		// Check tested up to (warning only).
		if ( $tested_wp && version_compare( $target_wp_version, $tested_wp, '>' ) ) {
			$result['compatible']      = false;
			$result['severity']        = 'warning';
			$result['reason']          = sprintf(
				'Plugin only tested up to WordPress %s. Target version is %s.',
				$tested_wp,
				$target_wp_version
			);
			$result['tested_wp']       = $tested_wp;
			$result['action_required'] = 'review';
			return $result;
		}

		return $result;
	}

	/**
	 * Check theme compatibility with WordPress version.
	 *
	 * @param array  $theme_data       Theme data array.
	 * @param string $target_wp_version Target WordPress version.
	 *
	 * @return array Compatibility result.
	 */
	private static function check_theme_wp_compatibility( array $theme_data, string $target_wp_version ): array {
		$result = array(
			'compatible'      => true,
			'severity'        => 'none',
			'reason'          => '',
			'action_required' => 'none',
		);

		$requires_wp = $theme_data['requires_wp'] ?? null;

		// Check minimum WordPress requirement.
		if ( $requires_wp && version_compare( $target_wp_version, $requires_wp, '<' ) ) {
			$result['compatible']      = false;
			$result['severity']        = 'critical';
			$result['reason']          = sprintf(
				'Theme requires WordPress %s or higher. Target version is %s.',
				$requires_wp,
				$target_wp_version
			);
			$result['requires_wp']     = $requires_wp;
			$result['action_required'] = 'switch_theme';
			return $result;
		}

		return $result;
	}

	/**
	 * Get plugin information from WordPress.org API.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return array|null Plugin info or null on failure.
	 */
	private static function get_plugin_api_info( string $slug ): ?array {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'requires'         => true,
					'requires_php'     => true,
					'requires_plugins' => true,
					'tested'           => true,
					'versions'         => true,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return array( 'error' => $api->get_error_message() );
		}

		return (array) $api;
	}

	/**
	 * Get theme information from WordPress.org API.
	 *
	 * @param string $slug Theme slug.
	 *
	 * @return array|null Theme info or null on failure.
	 */
	private static function get_theme_api_info( string $slug ): ?array {
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'requires'     => true,
					'requires_php' => true,
					'versions'     => true,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return array( 'error' => $api->get_error_message() );
		}

		return (array) $api;
	}

	/**
	 * Check if plugin version exists.
	 *
	 * Uses a multi-tier approach because many WordPress.org plugins do not tag
	 * every release in SVN. The `versions` array from the API only lists
	 * explicitly tagged versions, so untagged releases (including the current
	 * stable version served from trunk) would be falsely reported as missing.
	 *
	 * Tier 1: Check the SVN-tagged versions array (fastest, no network call).
	 * Tier 2: Compare against the API's current stable version field.
	 * Tier 3: HTTP HEAD probe on the download URL (last resort).
	 *
	 * @param array  $plugin_info Plugin API info.
	 * @param string $version     Version to check.
	 *
	 * @return bool True if version exists.
	 */
	private static function check_plugin_version_exists( array $plugin_info, string $version ): bool {
		// Tier 1: Check tagged versions array.
		if ( isset( $plugin_info['versions'] ) && is_array( $plugin_info['versions'] )
			&& array_key_exists( $version, $plugin_info['versions'] ) ) {
			return true;
		}

		// Tier 2: Check if target matches the current stable version.
		// Many plugins release from trunk without creating SVN tags,
		// so the version field reports the latest stable release even though
		// it is absent from the versions array.
		if ( isset( $plugin_info['version'] ) && $plugin_info['version'] === $version ) {
			return true;
		}

		// Tier 3: HTTP HEAD probe on the download URL as last resort.
		// This catches intermediate untagged versions between what is in
		// the versions array and what is reported as the current version.
		$slug = $plugin_info['slug'] ?? null;
		if ( $slug ) {
			$check_url = sprintf(
				'https://downloads.wordpress.org/plugin/%s.%s.zip',
				sanitize_key( $slug ),
				sanitize_text_field( $version )
			);
			$response  = wp_remote_head(
				$check_url,
				array(
					'timeout'     => 5,
					'redirection' => 5,
				)
			);

			if ( ! is_wp_error( $response )
				&& 200 === wp_remote_retrieve_response_code( $response ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if theme version exists.
	 *
	 * Uses a multi-tier approach because some WordPress.org themes may not tag
	 * every release in SVN. The `versions` array from the API only lists
	 * explicitly tagged versions.
	 *
	 * Tier 1: Check the SVN-tagged versions array (fastest, no network call).
	 * Tier 2: Compare against the API's current stable version field.
	 * Tier 3: HTTP HEAD probe on the download URL (last resort).
	 *
	 * @param array  $theme_info Theme API info.
	 * @param string $version    Version to check.
	 *
	 * @return bool True if version exists.
	 */
	private static function check_theme_version_exists( array $theme_info, string $version ): bool {
		// Tier 1: Check tagged versions array.
		if ( isset( $theme_info['versions'] ) && is_array( $theme_info['versions'] )
			&& array_key_exists( $version, $theme_info['versions'] ) ) {
			return true;
		}

		// Tier 2: Check if target matches the current stable version.
		if ( isset( $theme_info['version'] ) && $theme_info['version'] === $version ) {
			return true;
		}

		// Tier 3: HTTP HEAD probe on the download URL as last resort.
		$slug = $theme_info['slug'] ?? null;
		if ( $slug ) {
			$check_url = sprintf(
				'https://downloads.wordpress.org/theme/%s.%s.zip',
				sanitize_key( $slug ),
				sanitize_text_field( $version )
			);
			$response  = wp_remote_head(
				$check_url,
				array(
					'timeout'     => 5,
					'redirection' => 5,
				)
			);

			if ( ! is_wp_error( $response )
				&& 200 === wp_remote_retrieve_response_code( $response ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find plugins that depend on a given plugin.
	 *
	 * Uses WP_Plugin_Dependencies if available (WordPress 6.5+).
	 *
	 * @param string $plugin_slug Plugin slug to check dependents for.
	 *
	 * @return array Array of dependent plugins.
	 */
	private static function find_dependent_plugins( string $plugin_slug ): array {
		$dependents = array();

		// Use WP_Plugin_Dependencies if available (WordPress 6.5+).
		if ( class_exists( 'WP_Plugin_Dependencies' ) && method_exists( 'WP_Plugin_Dependencies', 'get_dependents' ) ) {
			if ( method_exists( 'WP_Plugin_Dependencies', 'initialize' ) ) {
				\WP_Plugin_Dependencies::initialize();
			}

			$dependent_files = \WP_Plugin_Dependencies::get_dependents( $plugin_slug );

			if ( is_array( $dependent_files ) && ! empty( $dependent_files ) ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$all_plugins = get_plugins();

				foreach ( $dependent_files as $dep_file ) {
					if ( isset( $all_plugins[ $dep_file ] ) ) {
						$dependents[] = array(
							'file'      => $dep_file,
							'name'      => $all_plugins[ $dep_file ]['Name'],
							'is_active' => is_plugin_active( $dep_file ),
						);
					}
				}
			}
		}

		return $dependents;
	}

	/**
	 * Check if required plugins are installed and active.
	 *
	 * @param array $required_slugs Array of required plugin slugs.
	 *
	 * @return array Array of missing/inactive dependencies.
	 */
	private static function check_required_plugins( array $required_slugs ): array {
		$missing = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $required_slugs as $required_slug ) {
			$found  = false;
			$active = false;

			foreach ( $all_plugins as $plugin_file => $plugin_data ) {
				$plugin_folder = dirname( $plugin_file );
				if ( '.' === $plugin_folder ) {
					$plugin_folder = pathinfo( $plugin_file, PATHINFO_FILENAME );
				}

				if ( $plugin_folder === $required_slug ) {
					$found  = true;
					$active = is_plugin_active( $plugin_file );
					break;
				}
			}

			if ( ! $found ) {
				$missing[] = array(
					'slug'   => $required_slug,
					'status' => 'not installed',
				);
			} elseif ( ! $active ) {
				$missing[] = array(
					'slug'   => $required_slug,
					'status' => 'installed but not active',
				);
			}
		}

		return $missing;
	}

	/**
	 * Find child themes for a parent theme.
	 *
	 * @param string $parent_slug Parent theme slug.
	 *
	 * @return array Array of child themes.
	 */
	private static function find_child_themes( string $parent_slug ): array {
		$child_themes = array();
		$all_themes   = wp_get_themes();

		foreach ( $all_themes as $theme_slug => $theme ) {
			if ( $theme->get_template() === $parent_slug && $theme->get_stylesheet() !== $parent_slug ) {
				$child_themes[] = array(
					'slug'    => $theme_slug,
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
				);
			}
		}

		return $child_themes;
	}

	/**
	 * Generate recommended actions for core version change.
	 *
	 * @param array $data Compatibility data.
	 *
	 * @return array Recommended actions.
	 */
	private static function generate_core_actions( array $data ): array {
		$actions = array();

		// Always recommend backup first.
		$actions[] = array(
			'priority'    => 1,
			'action'      => 'create_backup',
			'description' => 'Create full site backup (database + files).',
			'required'    => true,
		);

		// Handle incompatible plugins.
		$plugins_to_deactivate = array();
		foreach ( $data['affected_dependencies'] as $dep ) {
			if ( 'plugin' === $dep['type'] && 'critical' === $dep['severity'] ) {
				$plugins_to_deactivate[] = $dep['name'];
			}
		}

		if ( ! empty( $plugins_to_deactivate ) ) {
			$actions[] = array(
				'priority'    => 2,
				'action'      => 'deactivate_plugins',
				'description' => 'Deactivate incompatible plugins: ' . implode( ', ', $plugins_to_deactivate ),
				'required'    => true,
				'plugins'     => $plugins_to_deactivate,
			);
		}

		// Handle incompatible theme.
		foreach ( $data['affected_dependencies'] as $dep ) {
			if ( 'theme' === $dep['type'] && 'critical' === $dep['severity'] ) {
				$actions[] = array(
					'priority'    => 2,
					'action'      => 'switch_theme',
					'description' => sprintf( "Switch from '%s' to a compatible theme.", $dep['name'] ),
					'required'    => true,
				);
				break;
			}
		}

		// Execute action.
		$actions[] = array(
			'priority'    => 3,
			'action'      => 'execute_change',
			'description' => sprintf( 'Change WordPress core to version %s.', $data['target_version'] ),
			'required'    => true,
		);

		// Post-rollback testing.
		$actions[] = array(
			'priority'    => 4,
			'action'      => 'test_site',
			'description' => 'Test site functionality after version change.',
			'required'    => true,
		);

		return $actions;
	}

	/**
	 * Generate recommended actions for plugin version change.
	 *
	 * @param array $data Compatibility data.
	 *
	 * @return array Recommended actions.
	 */
	private static function generate_plugin_actions( array $data ): array {
		$actions = array();

		// Backup database.
		$actions[] = array(
			'priority'    => 1,
			'action'      => 'backup_database',
			'description' => 'Backup database before proceeding.',
			'required'    => true,
		);

		// Handle dependent plugins.
		$dependents_to_notify = array();
		foreach ( $data['affected_dependencies'] as $dep ) {
			if ( 'plugin' === $dep['type'] ) {
				$dependents_to_notify[] = $dep['name'];
			}
		}

		if ( ! empty( $dependents_to_notify ) ) {
			$actions[] = array(
				'priority'    => 2,
				'action'      => 'review_dependents',
				'description' => 'Review dependent plugins: ' . implode( ', ', $dependents_to_notify ),
				'required'    => false,
			);
		}

		// Execute action.
		$actions[] = array(
			'priority'    => 3,
			'action'      => 'execute_change',
			'description' => sprintf( 'Install plugin version %s.', $data['target_version'] ),
			'required'    => true,
		);

		// Test plugin functionality.
		$actions[] = array(
			'priority'    => 4,
			'action'      => 'test_plugin',
			'description' => 'Test plugin functionality.',
			'required'    => true,
		);

		return $actions;
	}

	/**
	 * Generate recommended actions for theme version change.
	 *
	 * @param array $data Compatibility data.
	 *
	 * @return array Recommended actions.
	 */
	private static function generate_theme_actions( array $data ): array {
		$actions   = array();
		$is_parent = false;

		foreach ( $data['affected_dependencies'] as $dep ) {
			if ( 'child_theme' === $dep['type'] ) {
				$is_parent = true;
				break;
			}
		}

		// Backup customizer settings.
		$actions[] = array(
			'priority'    => 1,
			'action'      => 'backup_customizer',
			'description' => 'Export customizer settings.',
			'required'    => true,
		);

		// Handle child themes.
		if ( $is_parent ) {
			$actions[] = array(
				'priority'    => 2,
				'action'      => 'review_child_themes',
				'description' => 'Review child themes that may be affected.',
				'required'    => false,
			);
		}

		// Execute action.
		$actions[] = array(
			'priority'    => 3,
			'action'      => 'execute_change',
			'description' => sprintf( 'Install theme version %s.', $data['target_version'] ),
			'required'    => true,
		);

		// Test theme.
		$actions[] = array(
			'priority'    => 4,
			'action'      => 'test_theme',
			'description' => 'Test theme appearance and functionality.',
			'required'    => true,
		);

		// Restore customizer.
		$actions[] = array(
			'priority'    => 5,
			'action'      => 'restore_customizer',
			'description' => 'Restore compatible customizer settings.',
			'required'    => false,
		);

		return $actions;
	}
}
