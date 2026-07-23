<?php
/**
 * Plugin Manager Service
 *
 * Handles detailed logic for plugin activation, deactivation, installation, and deletion.
 *
 * @package    Tailwatch
 * @subpackage Services/PluginTheme/Plugin
 */

namespace Tailwatch\Admin\App\Api\Services\PluginTheme\Plugin;

use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PluginManagerService
 *
 * Service class for plugin management operations.
 *
 */
class PluginManagerService {

	/**
	 * Activate a list of plugins.
	 *
	 * @param array $plugins             List of plugin paths to activate.
	 * @param bool  $network_wide        Whether to activate network-wide on multisite.
	 * @param bool  $rollback_on_failure Whether to rollback previous activations if one fails.
	 *
	 * @return array Result array with activated and failed lists.
	 */
	public static function wptw_activate_plugins(
		array $plugins = array(),
		bool $network_wide = false,
		bool $rollback_on_failure = false
	): array {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( empty( $plugins ) ) {
			return array(
				'activated' => array(),
				'failed'    => array(
					array(
						'plugin' => 'none',
						'error'  => 'No plugins provided',
					),
				),
			);
		}

		$is_multisite = is_multisite();
		$activated    = array();
		$failed       = array();

		foreach ( $plugins as $plugin ) {
			// Sanitize and validate.
			$plugin = sanitize_text_field( trim( $plugin ) );

			// Validate plugin path format.
			if ( ! self::validate_plugin_path( $plugin ) ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Invalid plugin path format',
				);
				continue;
			}

			$path = WP_PLUGIN_DIR . '/' . $plugin;

			// Check if file exists.
			if ( ! file_exists( $path ) ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Plugin file not found',
				);
				continue;
			}

			// Check compatibility (WP & PHP version).
			$compatibility = self::check_compatibility( $plugin );
			if ( ! $compatibility['compatible'] ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => $compatibility['reason'],
				);
				continue;
			}

			// Check if already active.
			if ( is_plugin_active( $plugin ) ) {
				$plugin_data = self::get_plugin_info( $plugin );
				$activated[] = array(
					'plugin'  => $plugin,
					'name'    => $plugin_data['name'],
					'version' => $plugin_data['version'],
					'status'  => 'already_active',
				);
				continue;
			}

			try {
				$network_flag = ( $is_multisite && $network_wide ) ? true : false;
				$result       = activate_plugin( $plugin, '', $network_flag, false );

				if ( is_wp_error( $result ) ) {
					$failed[] = array(
						'plugin' => $plugin,
						'error'  => $result->get_error_message(),
					);

					// If rollback is enabled and we have failures, rollback previous activations.
					if ( $rollback_on_failure && ! empty( $activated ) ) {
						self::rollback_activations( $activated );
						return array(
							'activated'   => array(),
							'failed'      => array_merge(
								$failed,
								array(
									array(
										'plugin' => 'rollback',
										'error'  => 'Rolled back all activations due to failure',
									),
								)
							),
							'rolled_back' => $activated,
						);
					}
				} else {
					$plugin_data = self::get_plugin_info( $plugin );
					$activated[] = array(
						'plugin'  => $plugin,
						'name'    => $plugin_data['name'],
						'version' => $plugin_data['version'],
						'status'  => 'activated',
					);

				}
			} catch ( \Throwable $e ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Exception: ' . $e->getMessage(),
				);
			}
		}

		if ( ! empty( $activated ) ) {
			wp_cache_flush();
		}

		return array(
			'activated' => $activated,
			'failed'    => $failed,
		);
	}

	/**
	 * Get all installed plugins.
	 *
	 * @param array $args Filter and pagination arguments.
	 *
	 * @return array List of installed plugins and metadata.
	 */
	public static function wptw_get_all_plugins( array $args = array() ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Default arguments.
		$defaults = array(
			'include_inactive'  => true,
			'page'              => 1,
			'limit'             => 10,
			'protected_plugins' => array(),
			'updates_only'      => false,  // Filter to show only plugins with available updates.
		);

		$args = array_merge( $defaults, $args );

		// Sanitize arguments.
		$include_inactive  = (bool) $args['include_inactive'];
		$page              = max( 1, (int) $args['page'] );
		$per_page          = max( 1, min( 100, (int) $args['limit'] ) );
		$protected_plugins = is_array( $args['protected_plugins'] ) ? $args['protected_plugins'] : array();
		$updates_only      = (bool) $args['updates_only'];

		wp_cache_flush();

		$all_plugins  = get_plugins();
		$plugins_list = array();

		// Get update information.
		$update_plugins = get_site_transient( 'update_plugins' );

		// Initialize WP_Plugin_Dependencies if available (WordPress 6.5+).
		$has_dependency_support = class_exists( 'WP_Plugin_Dependencies' );
		if ( $has_dependency_support && method_exists( 'WP_Plugin_Dependencies', 'initialize' ) ) {
			\WP_Plugin_Dependencies::initialize();
		}

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active         = is_plugin_active( $plugin_file );
			$is_network_active = is_plugin_active_for_network( $plugin_file );

			if ( ! $include_inactive && ! $is_active && ! $is_network_active ) {
				continue;
			}

			// Check compatibility (WP/PHP Version).
			$compatibility = self::check_compatibility( $plugin_file );

			$update_available = isset( $update_plugins->response[ $plugin_file ] );
			$update_version   = $update_available ? $update_plugins->response[ $plugin_file ]->new_version : null;

			// Get dependency information.
			$dependency_info = self::get_plugin_dependency_info( $plugin_file, $has_dependency_support );

			// Determine action availability.
			$action_status = self::get_plugin_action_status(
				$plugin_file,
				$is_active,
				$dependency_info,
				$has_dependency_support
			);

			// If incompatible, block activation.
			if ( ! $compatibility['compatible'] ) {
				$action_status['can_activate']              = false;
				$action_status['activation_blocked_reason'] = $compatibility['reason'];
			}

			// Determine protection status (includes ALL blocked actions).
			$is_protected = in_array( $plugin_file, $protected_plugins, true ) ||
							! $action_status['can_deactivate'];

			$plugin_slug = dirname( $plugin_file );
			if ( '.' === $plugin_slug ) {
				$plugin_slug = pathinfo( $plugin_file, PATHINFO_FILENAME );
			}

			$plugins_list[] = array(
				'plugin'                      => $plugin_file,
				'name'                        => $plugin_data['Name'],
				'slug'                        => $plugin_slug,
				'version'                     => $plugin_data['Version'],
				'author'                      => $plugin_data['Author'],
				'description'                 => $plugin_data['Description'],
				'text_domain'                 => $plugin_data['TextDomain'],
				'is_active'                   => $is_active,
				'is_network_active'           => $is_network_active,
				'update_available'            => $update_available,
				'update_version'              => $update_version,
				'compatibility'               => $compatibility, // Add compatibility info.
				'dependency_info'             => $dependency_info,
				'is_protected'                => $is_protected,
				'can_activate'                => $action_status['can_activate'],
				'can_deactivate'              => $action_status['can_deactivate'],
				'can_delete'                  => $action_status['can_delete'],
				'activation_blocked_reason'   => $action_status['activation_blocked_reason'],
				'deactivation_blocked_reason' => $action_status['deactivation_blocked_reason'],
				'deletion_blocked_reason'     => $action_status['deletion_blocked_reason'],
			);
		}

		// Filter for updates only if requested.
		if ( $updates_only ) {
			$plugins_list = array_filter(
				$plugins_list,
				function ( $plugin ) {
					return true === $plugin['update_available'];
				}
			);
			// Re-index array after filtering.
			$plugins_list = array_values( $plugins_list );
		}

		// Calculate pagination.
		$total       = count( $plugins_list );
		$total_pages = $total > 0 ? ceil( $total / $per_page ) : 0;
		$offset      = ( $page - 1 ) * $per_page;

		// Slice array for pagination.
		$paginated_plugins = array_slice( $plugins_list, $offset, $per_page );

		return array(
			'success'    => true,
			'plugins'    => $paginated_plugins,
			'filters'    => array(
				'updates_only'     => $updates_only,
				'include_inactive' => $include_inactive,
			),
			'pagination' => array(
				'total'       => $total,
				'page'        => $page,
				'limit'       => $per_page,
				'total_pages' => $total_pages,
			),
		);
	}

	/**
	 * Get plugin information (including requirements).
	 *
	 * @param string $plugin Plugin path.
	 *
	 * @return array Plugin details.
	 */
	private static function get_plugin_info( string $plugin ): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

		return array(
			'name'         => $plugin_data['Name'] ?? 'Unknown',
			'version'      => $plugin_data['Version'] ?? 'Unknown',
			'author'       => $plugin_data['Author'] ?? 'Unknown',
			'description'  => $plugin_data['Description'] ?? '',
			'requires_wp'  => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		);
	}

	/**
	 * Check plugin compatibility with current WP and PHP versions.
	 *
	 * @param string $plugin Plugin path.
	 *
	 * @return array Compatibility status.
	 */
	private static function check_compatibility( string $plugin ): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin;
		if ( ! file_exists( $plugin_path ) ) {
			return array(
				'compatible' => false,
				'reason'     => 'Plugin file not found',
			);
		}

		$data        = get_plugin_data( $plugin_path );
		$wp_version  = get_bloginfo( 'version' );
		$php_version = phpversion();

		// Check WP Version.
		if ( ! empty( $data['RequiresWP'] ) && version_compare( $wp_version, $data['RequiresWP'], '<' ) ) {
			return array(
				'compatible' => false,
				'reason'     => sprintf( 'Requires WordPress %s (Current: %s)', $data['RequiresWP'], $wp_version ),
			);
		}

		// Check PHP Version.
		if ( ! empty( $data['RequiresPHP'] ) && version_compare( $php_version, $data['RequiresPHP'], '<' ) ) {
			return array(
				'compatible' => false,
				'reason'     => sprintf( 'Requires PHP %s (Current: %s)', $data['RequiresPHP'], $php_version ),
			);
		}

		return array(
			'compatible' => true,
			'reason'     => '',
		);
	}

	/**
	 * Deactivate a list of plugins.
	 *
	 * @param array $plugins      List of plugin paths to deactivate.
	 * @param bool  $network_wide Whether to deactivate network-wide on multisite.
	 *
	 * @return array Result array with deactivated and failed lists.
	 */
	public static function wptw_deactivate_plugins( array $plugins = array(), bool $network_wide = false ): array {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( empty( $plugins ) ) {
			return array(
				'deactivated' => array(),
				'failed'      => array(
					array(
						'plugin' => 'none',
						'error'  => 'No plugins provided',
					),
				),
			);
		}

		$is_multisite = is_multisite();
		$deactivated  = array();
		$failed       = array();

		$has_dependency_support = class_exists( 'WP_Plugin_Dependencies' );
		if ( $has_dependency_support && method_exists( 'WP_Plugin_Dependencies', 'initialize' ) ) {
			\WP_Plugin_Dependencies::initialize();
		}

		foreach ( $plugins as $plugin ) {
			// Sanitize and validate.
			$plugin = sanitize_text_field( trim( $plugin ) );

			if ( ! self::validate_plugin_path( $plugin ) ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Invalid plugin path format',
				);
				continue;
			}

			$was_active         = is_plugin_active( $plugin );
			$was_network_active = $is_multisite && is_plugin_active_for_network( $plugin );

			if ( ! $was_active && ! $was_network_active ) {
				$plugin_data   = self::get_plugin_info( $plugin );
				$deactivated[] = array(
					'plugin' => $plugin,
					'name'   => $plugin_data['name'],
					'status' => 'already_inactive',
				);
				continue;
			}

			if ( $has_dependency_support ) {
				$dependency_info = self::get_plugin_dependency_info( $plugin, $has_dependency_support );

				if ( ! $dependency_info['can_deactivate'] ) {
					$plugin_data = self::get_plugin_info( $plugin );

					$failed[] = array(
						'plugin'                  => $plugin,
						'name'                    => $plugin_data['name'],
						'error'                   => $dependency_info['deactivate_blocked_reason'],
						'blocked_by_dependencies' => true,
						'active_dependents'       => $dependency_info['required_by_active'],
					);

					continue;
				}
			}

			try {
				$network_flag = ( $is_multisite && $network_wide ) ? true : false;

				// Get plugin data before deactivation.
				$plugin_data = self::get_plugin_info( $plugin );

				// Deactivate plugin.
				deactivate_plugins( $plugin, false, $network_flag );

				// Verify deactivation.
				$still_active         = is_plugin_active( $plugin );
				$still_network_active = $network_flag && is_plugin_active_for_network( $plugin );

				if ( ( $was_active && ! $still_active ) || ( $was_network_active && ! $still_network_active ) ) {
					$deactivated[] = array(
						'plugin'  => $plugin,
						'name'    => $plugin_data['name'],
						'version' => $plugin_data['version'],
						'status'  => 'deactivated',
					);

				} else {
					$failed[] = array(
						'plugin' => $plugin,
						'error'  => 'Deactivation verification failed',
					);
				}
			} catch ( \Throwable $e ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Exception: ' . $e->getMessage(),
				);
			}
		}

		if ( ! empty( $deactivated ) ) {
			wp_cache_flush();
		}

		return array(
			'deactivated' => $deactivated,
			'failed'      => $failed,
		);
	}

	/**
	 * Delete a list of plugins.
	 *
	 * @param array $plugins List of plugin paths to delete.
	 *
	 * @return array Result array with deleted and failed lists.
	 */
	public static function wptw_delete_plugins( array $plugins = array() ): array {
		if ( ! function_exists( 'delete_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( empty( $plugins ) ) {
			return array(
				'deleted' => array(),
				'failed'  => array(
					array(
						'plugin' => 'none',
						'error'  => 'No plugins provided',
					),
				),
			);
		}

		$deleted = array();
		$failed  = array();

		foreach ( $plugins as $plugin ) {
			// Sanitize and validate.
			$plugin = sanitize_text_field( trim( $plugin ) );

			if ( ! self::validate_plugin_path( $plugin ) ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Invalid plugin path format',
				);
				continue;
			}

			// Check if plugin exists.
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Plugin file not found',
				);
				continue;
			}

			// Prevent deletion of active plugins.
			if ( is_plugin_active( $plugin ) ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Cannot delete active plugin. Please deactivate it first.',
				);
				continue;
			}

			try {
				// Get plugin data before deletion.
				$plugin_data = self::get_plugin_info( $plugin );
				$plugin_name = $plugin_data['name'];

				// Attempt deletion.
				$result = delete_plugins( array( $plugin ) );

				if ( is_wp_error( $result ) ) {
					$failed[] = array(
						'plugin' => $plugin,
						'error'  => $result->get_error_message(),
					);
				} else {
					$deleted[] = array(
						'plugin' => $plugin,
						'name'   => $plugin_name,
						'status' => 'deleted',
					);

				}
			} catch ( \Throwable $e ) {
				$failed[] = array(
					'plugin' => $plugin,
					'error'  => 'Exception: ' . $e->getMessage(),
				);
			}
		}

		if ( ! empty( $deleted ) ) {
			wp_cache_flush();
		}

		return array(
			'deleted' => $deleted,
			'failed'  => $failed,
		);
	}

	/**
	 * Validate plugin path format.
	 *
	 * @param string $plugin Plugin path (e.g., 'folder/file.php').
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_plugin_path( string $plugin ): bool {
		// Check for directory traversal.
		if ( strpos( $plugin, '..' ) !== false || strpos( $plugin, './' ) !== false ) {
			return false;
		}

		// Validate format: either "file.php" or "folder/file.php"
		// Allow alphanumeric, underscores, hyphens, and dots in names.
		if ( ! preg_match( '#^([a-zA-Z0-9_.-]+/)?[a-zA-Z0-9_.-]+\.php$#', $plugin ) ) {
			return false;
		}

		// Additional security: prevent multiple slashes or leading/trailing slashes.
		if ( preg_match( '#/{2,}|^/|/$#', $plugin ) ) {
			return false;
		}

		return true;
	}



	/**
	 * Rollback plugin activations.
	 *
	 * @param array $activated_plugins List of plugins to deactivate.
	 *
	 * @return void
	 */
	private static function rollback_activations( array $activated_plugins ): void {
		foreach ( $activated_plugins as $plugin_info ) {
			if ( isset( $plugin_info['plugin'] ) && 'activated' === $plugin_info['status'] ) {
				deactivate_plugins( $plugin_info['plugin'] );
			}
		}
	}

	/**
	 * Get plugin dependency information.
	 *
	 * @param string $plugin_file            Plugin file path.
	 * @param bool   $has_dependency_support Whether dependency support class exists.
	 *
	 * @return array Dependency information.
	 */
	private static function get_plugin_dependency_info( string $plugin_file, bool $has_dependency_support ): array {
		$dependency_info = array(
			'has_dependencies'          => false,
			'requires'                  => array(),
			'has_unmet_dependencies'    => false,
			'unmet_dependencies'        => array(),
			'is_required_by'            => false,
			'required_by'               => array(),
			'required_by_active'        => array(),
			'can_deactivate'            => true,
			'deactivate_blocked_reason' => '',
		);

		if ( ! $has_dependency_support ) {
			return $dependency_info;
		}

		try {
			// Check if this plugin has dependencies (requires other plugins).
			if ( method_exists( 'WP_Plugin_Dependencies', 'has_dependencies' ) ) {
				$dependency_info['has_dependencies'] = \WP_Plugin_Dependencies::has_dependencies( $plugin_file );
			}

			// Get list of plugins this plugin requires.
			if ( $dependency_info['has_dependencies'] && method_exists( 'WP_Plugin_Dependencies', 'get_dependencies' ) ) {
				$required_slugs = \WP_Plugin_Dependencies::get_dependencies( $plugin_file );

				if ( is_array( $required_slugs ) && ! empty( $required_slugs ) ) {
					foreach ( $required_slugs as $required_slug ) {
						$dependency_info['requires'][] = array(
							'slug' => $required_slug,
							'name' => self::get_plugin_name_by_slug( $required_slug ),
						);
					}
				}
			}

			// Check for unmet dependencies.
			if ( $dependency_info['has_dependencies'] && method_exists( 'WP_Plugin_Dependencies', 'has_unmet_dependencies' ) ) {
				$dependency_info['has_unmet_dependencies'] = \WP_Plugin_Dependencies::has_unmet_dependencies( $plugin_file );
			}

			// Get which plugins require this plugin (dependents).
			$plugin_slug = self::get_plugin_slug_from_file( $plugin_file );

			if ( $plugin_slug && method_exists( 'WP_Plugin_Dependencies', 'get_dependents' ) ) {
				$dependents = \WP_Plugin_Dependencies::get_dependents( $plugin_slug );

				if ( is_array( $dependents ) && ! empty( $dependents ) ) {
					$dependency_info['is_required_by'] = true;

					foreach ( $dependents as $dependent_file ) {
						$dependent_name      = self::get_plugin_name_by_file( $dependent_file );
						$is_dependent_active = is_plugin_active( $dependent_file );

						$dependency_info['required_by'][] = array(
							'plugin'    => $dependent_file,
							'name'      => $dependent_name,
							'is_active' => $is_dependent_active,
						);

						// Track active dependents separately.
						if ( $is_dependent_active ) {
							$dependency_info['required_by_active'][] = array(
								'plugin' => $dependent_file,
								'name'   => $dependent_name,
							);
						}
					}

					// Determine if deactivation should be blocked.
					if ( ! empty( $dependency_info['required_by_active'] ) ) {
						$dependency_info['can_deactivate'] = false;

						$active_dependent_names                       = array_column( $dependency_info['required_by_active'], 'name' );
						$dependency_info['deactivate_blocked_reason'] = sprintf(
							'This plugin is required by %d active plugin(s): %s. Please deactivate the dependent plugin(s) first.',
							count( $active_dependent_names ),
							implode( ', ', $active_dependent_names )
						);
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Silently ignore exceptions and return default status.
			unset( $e );
		}

		return $dependency_info;
	}

	/**
	 * Resolve a plugin slug to its installed plugin file path.
	 *
	 * Handles every slug shape the codebase encounters, in order:
	 *   1. A full plugin file path already keyed in get_plugins() (returned as-is).
	 *   2. Folder-based plugins:    "akismet"     -> "akismet/akismet.php"
	 *   3. Single-file plugins:     "hello"       -> "hello.php"
	 *   4. WP.org canonical slug:   "hello-dolly" -> "hello.php"
	 *
	 * For case (4), the resolver consults the `update_plugins` site transient,
	 * where each entry carries the canonical WordPress.org slug under
	 * `$entry->slug` -- the same source WordPress itself uses to map installed
	 * plugins to their repository identity. This is what makes single-file
	 * plugins like Hello Dolly resolvable from their WP.org slug.
	 *
	 * @param string $slug Plugin slug or full plugin file path.
	 *
	 * @return string|null Plugin file path keyed in get_plugins(), or null when not installed.
	 */
	public static function get_plugin_file_by_slug( string $slug ): ?string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return null;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();

		// (1) Caller already passed a full plugin file path.
		if ( isset( $all_plugins[ $slug ] ) ) {
			return $slug;
		}

		// (2) & (3) Match by folder name, or by single-file basename when there is no folder.
		foreach ( $all_plugins as $plugin_file => $_unused ) {
			$folder = dirname( $plugin_file );
			if ( '.' === $folder ) {
				if ( $slug === pathinfo( $plugin_file, PATHINFO_FILENAME ) ) {
					return $plugin_file;
				}
			} elseif ( $slug === $folder ) {
				return $plugin_file;
			}
		}

		// (4) Resolve via the update_plugins transient (canonical WP.org slug).
		$update_plugins = get_site_transient( 'update_plugins' );
		if ( $update_plugins ) {
			foreach ( array( 'response', 'no_update' ) as $bucket ) {
				if ( empty( $update_plugins->{$bucket} ) || ! is_array( $update_plugins->{$bucket} ) ) {
					continue;
				}
				foreach ( $update_plugins->{$bucket} as $plugin_file => $entry ) {
					if ( isset( $entry->slug ) && $slug === $entry->slug && isset( $all_plugins[ $plugin_file ] ) ) {
						return $plugin_file;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Resolve an installed plugin file to its canonical WordPress.org slug.
	 *
	 * The canonical slug is what api.wordpress.org expects (e.g. "hello-dolly"
	 * for the file "hello.php"). It is NOT always derivable from the file path
	 * alone -- a single-file plugin's filename rarely matches its repo slug.
	 *
	 * Resolution order:
	 *   1. `update_plugins` site transient -- WordPress stores the canonical
	 *      WP.org slug there for every installed plugin it has seen
	 *      (`$update_plugins->no_update[$plugin_file]->slug`, or the same
	 *      under `->response`). This is the authoritative source.
	 *   2. `WP_Plugin_Dependencies::convert_to_slug()` (WP 6.5+) -- a coarser
	 *      file-path-based derivation used by core's dependency system.
	 *   3. Folder name (`dirname`) for folder-based plugins, or
	 *      filename-without-extension for single-file plugins.
	 *
	 * The fallbacks are best-effort: for plugins not in the WP.org repo
	 * (custom/private), there is no canonical slug and the result is just a
	 * locally-meaningful identifier.
	 *
	 * @param string $plugin_file Plugin file path as keyed in get_plugins().
	 *
	 * @return string|null Canonical WP.org slug, or null when unresolvable.
	 */
	public static function get_wporg_slug_by_file( string $plugin_file ): ?string {
		$plugin_file = trim( $plugin_file );
		if ( '' === $plugin_file ) {
			return null;
		}

		// (1) Authoritative: update_plugins transient.
		$update_plugins = get_site_transient( 'update_plugins' );
		if ( $update_plugins ) {
			foreach ( array( 'response', 'no_update' ) as $bucket ) {
				if ( isset( $update_plugins->{$bucket}[ $plugin_file ]->slug ) ) {
					$candidate = (string) $update_plugins->{$bucket}[ $plugin_file ]->slug;
					if ( '' !== $candidate ) {
						return $candidate;
					}
				}
			}
		}

		// (2) WP 6.5+ core helper.
		if ( class_exists( 'WP_Plugin_Dependencies' ) && method_exists( 'WP_Plugin_Dependencies', 'convert_to_slug' ) ) {
			try {
				$candidate = \WP_Plugin_Dependencies::convert_to_slug( $plugin_file );
				if ( is_string( $candidate ) && '' !== $candidate ) {
					return $candidate;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// (3) Best-effort fallback from the file path itself.
		$folder = dirname( $plugin_file );
		if ( '.' === $folder ) {
			$basename = pathinfo( $plugin_file, PATHINFO_FILENAME );
			return '' !== $basename ? $basename : null;
		}
		return '' !== $folder ? $folder : null;
	}

	/**
	 * Get plugin slug from file path.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return string|null Plugin slug or null.
	 */
	private static function get_plugin_slug_from_file( string $plugin_file ): ?string {
		if ( class_exists( 'WP_Plugin_Dependencies' ) && method_exists( 'WP_Plugin_Dependencies', 'convert_to_slug' ) ) {
			try {
				return \WP_Plugin_Dependencies::convert_to_slug( $plugin_file );
			} catch ( \Throwable $e ) {
				// Fallback to manual extraction below.
				unset( $e );
			}
		}

		// Fallback: extract slug from file path.
		$parts = explode( '/', $plugin_file );
		return ! empty( $parts[0] ) ? $parts[0] : null;
	}

	/**
	 * Get plugin name by slug.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return string Plugin name.
	 */
	private static function get_plugin_name_by_slug( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$plugin_slug = self::get_plugin_slug_from_file( $plugin_file );
			if ( $plugin_slug === $slug ) {
				return $plugin_data['Name'];
			}
		}

		return $slug;
	}

	/**
	 * Get plugin name by file.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return string Plugin name.
	 */
	private static function get_plugin_name_by_file( string $plugin_file ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		if ( isset( $all_plugins[ $plugin_file ]['Name'] ) ) {
			return $all_plugins[ $plugin_file ]['Name'];
		}

		return $plugin_file;
	}

	/**
	 * Check if a plugin is installed and get its status.
	 *
	 * @param string $slug              Plugin slug.
	 * @param array  $installed_plugins List of installed plugins.
	 *
	 * @return array Installation status details.
	 */
	private static function check_plugin_install_status( string $slug, array $installed_plugins ): array {
		$status = array(
			'is_installed'      => false,
			'is_active'         => false,
			'installed_version' => null,
			'plugin_file'       => null,
			'status'            => 'not_installed', // Possible values: not_installed, installed, active.
			'update_available'  => false,
		);

		try {
			foreach ( $installed_plugins as $plugin_file => $plugin_data ) {
				$plugin_folder = dirname( $plugin_file );

				if ( $plugin_folder === $slug ) {
					$status['is_installed']      = true;
					$status['plugin_file']       = $plugin_file;
					$status['installed_version'] = $plugin_data['Version'] ?? null;

					// Check if active.
					if ( is_plugin_active( $plugin_file ) ) {
						$status['is_active'] = true;
						$status['status']    = 'active';
					} else {
						$status['status'] = 'installed';
					}

					// Check if update is available.
					$update_plugins = get_site_transient( 'update_plugins' );
					if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
						$status['update_available'] = true;
					}

					break;
				}
			}
		} catch ( \Throwable $e ) {
			// Silently ignore exceptions and return default status.
			unset( $e );
		}

		return $status;
	}

	/**
	 * Check dependency status for repository plugins.
	 * Determines if required plugins are installed and active.
	 *
	 * @param array $required_slugs    Array of required plugin slugs from WordPress.org.
	 * @param array $installed_plugins Array of installed plugins from get_plugins().
	 *
	 * @return array Dependency status information.
	 */
	private static function check_repo_plugin_dependencies( array $required_slugs, array $installed_plugins ): array {
		$dependency_info = array(
			'has_dependencies'          => false,
			'requires'                  => array(),
			'has_unmet_dependencies'    => false,
			'unmet_dependencies'        => array(),
			'can_activate'              => true,
			'can_install_and_activate'  => true,
			'activation_blocked_reason' => '',
		);

		if ( empty( $required_slugs ) ) {
			return $dependency_info;
		}

		try {
			$dependency_info['has_dependencies'] = true;

			foreach ( $required_slugs as $required_slug ) {
				$required_slug = sanitize_text_field( $required_slug );

				// Check if this required plugin is installed.
				$dependency_status = self::check_plugin_install_status( $required_slug, $installed_plugins );

				$dependency_detail = array(
					'slug'              => $required_slug,
					'name'              => self::get_plugin_name_by_slug( $required_slug ),
					'is_installed'      => $dependency_status['is_installed'],
					'is_active'         => $dependency_status['is_active'],
					'installed_version' => $dependency_status['installed_version'],
					'status'            => $dependency_status['status'],
				);

				$dependency_info['requires'][] = $dependency_detail;

				// Check if dependency is not active (either not installed or installed but inactive).
				if ( ! $dependency_status['is_active'] ) {
					$dependency_info['has_unmet_dependencies'] = true;
					$dependency_info['can_activate']           = false;

					// If not installed at all, cannot install and activate automatically.
					if ( ! $dependency_status['is_installed'] ) {
						$dependency_info['can_install_and_activate'] = false;
					}

					$dependency_info['unmet_dependencies'][] = $dependency_detail;
				}
			}

			// Build human-readable blocked reason.
			if ( $dependency_info['has_unmet_dependencies'] ) {
				$parent_names        = array_column( $dependency_info['unmet_dependencies'], 'name' );
				$not_installed_count = count(
					array_filter(
						$dependency_info['unmet_dependencies'],
						function ( $dep ) {
							return ! $dep['is_installed'];
						}
					)
				);
				$inactive_count      = count(
					array_filter(
						$dependency_info['unmet_dependencies'],
						function ( $dep ) {
							return $dep['is_installed'] && ! $dep['is_active'];
						}
					)
				);

				$reasons = array();
				if ( $not_installed_count > 0 ) {
					$reasons[] = sprintf(
						'%d required plugin(s) not installed: %s',
						$not_installed_count,
						implode(
							', ',
							array_map(
								function ( $dep ) {
									return $dep['is_installed'] ? null : $dep['name'];
								},
								$dependency_info['unmet_dependencies']
							)
						)
					);
				}
				if ( $inactive_count > 0 ) {
					$reasons[] = sprintf(
						'%d required plugin(s) installed but inactive: %s',
						$inactive_count,
						implode(
							', ',
							array_map(
								function ( $dep ) {
									return ( $dep['is_installed'] && ! $dep['is_active'] ) ? $dep['name'] : null;
								},
								$dependency_info['unmet_dependencies']
							)
						)
					);
				}

				$dependency_info['activation_blocked_reason'] = 'Cannot activate: ' . implode( '; ', array_filter( $reasons ) );
			}
		} catch ( \Throwable $e ) {
			// Silently ignore exceptions and return default status.
			unset( $e );
		}

		return $dependency_info;
	}

	/**
	 * Determine what actions can be performed on a plugin.
	 *
	 * @param string $plugin_file            Plugin file path.
	 * @param bool   $is_active              Whether plugin is currently active.
	 * @param array  $dependency_info        Dependency information from get_plugin_dependency_info().
	 * @param bool   $has_dependency_support Whether WP_Plugin_Dependencies is available.
	 *
	 * @return array Action availability status.
	 */
	private static function get_plugin_action_status(
		string $plugin_file,
		bool $is_active,
		array $dependency_info,
		bool $has_dependency_support
	): array {
		// Parameters kept for future extensibility and API consistency.
		unset( $plugin_file, $has_dependency_support );

		$action_status = array(
			'can_activate'                => true,
			'can_deactivate'              => true,
			'can_delete'                  => true,
			'activation_blocked_reason'   => '',
			'deactivation_blocked_reason' => '',
			'deletion_blocked_reason'     => '',
		);

		try {
			// If plugin is active, it cannot be activated again.
			if ( $is_active ) {
				$action_status['can_activate']              = false;
				$action_status['activation_blocked_reason'] = 'Plugin is already active';
			} elseif ( $dependency_info['has_unmet_dependencies'] ) {
				// If plugin is inactive, check if it has unmet dependencies.
				$action_status['can_activate'] = false;

				$parent_names                               = array_column( $dependency_info['requires'], 'name' );
				$action_status['activation_blocked_reason'] = sprintf(
					'This plugin requires %d parent plugin(s) to be active: %s. Please activate the parent plugin(s) first.',
					count( $parent_names ),
					implode( ', ', $parent_names )
				);
			}

			// Check deactivation (if active and has active dependents).
			if ( $is_active && ! $dependency_info['can_deactivate'] ) {
				$action_status['can_deactivate']              = false;
				$action_status['deactivation_blocked_reason'] = $dependency_info['deactivate_blocked_reason'];
			}

			// Check deletion.
			// Cannot delete if active.
			if ( $is_active ) {
				$action_status['can_delete']              = false;
				$action_status['deletion_blocked_reason'] = 'Cannot delete active plugin. Please deactivate it first.';
			} elseif ( $dependency_info['is_required_by'] && ! empty( $dependency_info['required_by'] ) ) {
				// Cannot delete if other plugins (active or inactive) depend on it.
				$action_status['can_delete'] = false;

				$dependent_names                          = array_column( $dependency_info['required_by'], 'name' );
				$action_status['deletion_blocked_reason'] = sprintf(
					'This plugin is required by %d installed plugin(s): %s. Please delete the dependent plugin(s) first.',
					count( $dependent_names ),
					implode( ', ', $dependent_names )
				);
			}
		} catch ( \Throwable $e ) {
			// Silently ignore exceptions and return default status.
			unset( $e );
		}

		return $action_status;
	}
}
