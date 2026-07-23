<?php
/**
 * History Hook Controller
 *
 * Captures plugin, theme, and core actions performed outside the plugin interface.
 *
 * @package    Tailwatch
 * @subpackage Controllers/History
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Every glob() in this file scans a plugin/theme directory for its main *.php entry file; the wildcard pattern is what makes glob() the right tool here.

namespace Tailwatch\Admin\App\Api\Controllers\History;

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\Common\HistoryService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HistoryHookController
 *
 * Hooks into WordPress actions to track plugin, theme, and core changes performed outside the plugin.
 *
 */
class HistoryHookController {

	/**
	 * Constructor - Initialize hooks.
	 *
	 */
	public function __construct() {
		$hook_controller = new HookControllers();

		// Plugin hooks.
		$hook_controller->add_action_hook( 'activated_plugin', array( $this, 'track_plugin_activation' ), 10, 2 );
		$hook_controller->add_action_hook( 'deactivated_plugin', array( $this, 'track_plugin_deactivation' ), 10, 2 );
		$hook_controller->add_action_hook( 'upgrader_process_complete', array( $this, 'track_upgrader_complete' ), 10, 2 );
		$hook_controller->add_action_hook( 'delete_plugin', array( $this, 'track_plugin_deletion' ), 10, 1 );

		// Theme hooks.
		$hook_controller->add_action_hook( 'switch_theme', array( $this, 'track_theme_switch' ), 10, 3 );
		$hook_controller->add_action_hook( 'delete_theme', array( $this, 'track_theme_deletion' ), 10, 1 );

		// Core hooks.
		$hook_controller->add_action_hook( '_core_updated_successfully', array( $this, 'track_core_update' ), 10, 1 );
	}

	/**
	 * Check if action was performed through our plugin.
	 *
	 * Uses a transient flag set by our controllers to prevent duplicate tracking.
	 * Note: We check the flag but don't clear it immediately, as multiple hooks
	 * may fire for the same action (e.g., upgrader_process_complete and _core_updated_successfully).
	 * The flag will expire naturally after 30 seconds.
	 *
	 * @return bool True if action from our plugin, false otherwise.
	 */
	private function is_action_from_our_plugin() {
		// Check if our plugin set a flag.
		// Check both the generic flag and action-specific flags.
		$flag_generic       = get_transient( 'wptw_history_tracking_in_progress' );
		$flag_core_update   = get_transient( 'wptw_history_tracking_core_update' );
		$flag_core_rollback = get_transient( 'wptw_history_tracking_core_rollback' );
		if ( $flag_generic || $flag_core_update || $flag_core_rollback ) {
			// Don't clear the flag here - let it expire naturally.
			// Multiple hooks may fire for the same action, and we want to skip all of them.
			return true;
		}
		return false;
	}

	/**
	 * Capture current plugin state.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return array|null Plugin state or null if not found.
	 */
	private function capture_plugin_state( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_file_path ) ) {
			return null;
		}

		$plugin_data = get_plugin_data( $plugin_file_path, false );
		$update_info = get_site_transient( 'update_plugins' );

		return array(
			'version'           => $plugin_data['Version'] ?? 'N/A',
			'name'              => $plugin_data['Name'] ?? $plugin_file,
			'is_active'         => is_plugin_active( $plugin_file ),
			'is_network_active' => is_plugin_active_for_network( $plugin_file ),
			'update_available'  => isset( $update_info->response[ $plugin_file ] ),
			'update_version'    => isset( $update_info->response[ $plugin_file ] )
				? $update_info->response[ $plugin_file ]->new_version
				: null,
			'author'            => $plugin_data['Author'] ?? 'N/A',
			'description'       => $plugin_data['Description'] ?? '',
		);
	}

	/**
	 * Capture current theme state.
	 *
	 * @param string $theme_slug Theme slug.
	 *
	 * @return array|null Theme state or null if not found.
	 */
	private function capture_theme_state( $theme_slug ) {
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return null;
		}

		$current_theme = wp_get_theme();
		$update_info   = get_site_transient( 'update_themes' );

		return array(
			'version'          => $theme->get( 'Version' ),
			'name'             => $theme->get( 'Name' ),
			'is_active'        => ( $theme_slug === $current_theme->get_stylesheet() ),
			'is_parent'        => ! empty( $theme->get( 'Template' ) ),
			'parent_theme'     => $theme->get( 'Template' ),
			'update_available' => isset( $update_info->response[ $theme_slug ] ),
			'update_version'   => isset( $update_info->response[ $theme_slug ] )
				? $update_info->response[ $theme_slug ]['new_version']
				: null,
			'author'           => $theme->get( 'Author' ),
			'description'      => $theme->get( 'Description' ),
		);
	}

	/**
	 * Capture current core state.
	 *
	 * @return array Core state.
	 */
	private function capture_core_state() {
		global $wp_version;

		$update_info      = get_site_transient( 'update_core' );
		$update_available = false;
		$update_version   = null;

		if ( $update_info && ! empty( $update_info->updates ) ) {
			foreach ( $update_info->updates as $update ) {
				if ( 'upgrade' === $update->response ) {
					$update_available = true;
					$update_version   = $update->version;
					break;
				}
			}
		}

		return array(
			'version'          => $wp_version,
			'update_available' => $update_available,
			'update_version'   => $update_version,
			'php_version'      => PHP_VERSION,
			'mysql_version'    => $GLOBALS['wpdb']->db_version(),
		);
	}

	/**
	 * Track plugin activation from WordPress admin.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param bool   $network_wide Whether network-wide activation.
	 *
	 * @return void
	 */
	public function track_plugin_activation( $plugin_file, $network_wide = false ) {
		// Skip if action was performed through our plugin (prevent duplicate).
		if ( $this->is_action_from_our_plugin() ) {
			return;
		}

		// Capture state AFTER activation (plugin is now active).
		$after_state = $this->capture_plugin_state( $plugin_file );
		if ( ! $after_state ) {
			return;
		}

		// Before state would be inactive (we can infer this).
		$before_state = array(
			'version'           => $after_state['version'],
			'name'              => $after_state['name'],
			'is_active'         => false,
			'is_network_active' => false,
		);

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'plugin_activate',
				'item_type'     => 'plugin',
				'item_slug'     => $plugin_file,
				'item_name'     => $after_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'network_wide' => $network_wide,
					'source'       => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track plugin deactivation from WordPress admin.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @param bool   $network_wide Whether network-wide deactivation.
	 *
	 * @return void
	 */
	public function track_plugin_deactivation( $plugin_file, $network_wide = false ) {
		// Skip if action was performed through our plugin.
		if ( $this->is_action_from_our_plugin() ) {
			return;
		}

		// Capture state BEFORE deactivation (plugin is still active at this point).
		// Note: Hook fires BEFORE deactivation, so we can capture current state.
		$before_state = $this->capture_plugin_state( $plugin_file );
		if ( ! $before_state ) {
			return;
		}

		// After state would be inactive.
		$after_state = array(
			'version'           => $before_state['version'],
			'name'              => $before_state['name'],
			'is_active'         => false,
			'is_network_active' => false,
		);

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'plugin_deactivate',
				'item_type'     => 'plugin',
				'item_slug'     => $plugin_file,
				'item_name'     => $before_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'network_wide' => $network_wide,
					'source'       => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track plugin, theme, or core updates from WordPress admin.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $hook_extra Extra arguments.
	 *
	 * @return void
	 */
	public function track_upgrader_complete( $upgrader, $hook_extra ) {
		// Skip if action was performed through our plugin.
		if ( $this->is_action_from_our_plugin() ) {
			return;
		}

		// Determine type and action.
		$type   = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : '';

		// Handle core updates - SKIP THIS HOOK for core updates.
		// Core updates are handled by _core_updated_successfully hook only.
		// This prevents duplicate recording since both hooks fire for core updates.
		if ( 'core' === $type && 'update' === $action ) {
			// Skip - _core_updated_successfully hook will handle core updates.
			return;
		}

		// Handle plugin updates.
		if ( 'plugin' === $type && 'update' === $action ) {
			$plugin_file = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
			if ( empty( $plugin_file ) ) {
				// Bulk update - get all updated plugins.
				$updated_plugins = isset( $hook_extra['plugins'] ) ? $hook_extra['plugins'] : array();
				foreach ( $updated_plugins as $plugin ) {
					$this->track_plugin_update( $plugin );
				}
			} else {
				$this->track_plugin_update( $plugin_file );
			}
		}

		// Handle plugin installation.
		if ( 'plugin' === $type && 'install' === $action ) {
			// WordPress may pass plugin file in different ways.
			// Try hook_extra['plugin'] first, then check upgrader result.
			$plugin_file = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';

			// If plugin file is not in hook_extra, try to get it from upgrader result.
			if ( empty( $plugin_file ) && isset( $upgrader->result ) && is_array( $upgrader->result ) ) {
				// The result might contain the plugin directory name.
				if ( isset( $upgrader->result['destination_name'] ) ) {
					$plugin_dir = $upgrader->result['destination_name'];
					// Try to find the main plugin file.
					$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_dir;
					if ( is_dir( $plugin_path ) ) {
						// Look for main plugin file (usually same name as directory or index.php).
						$plugin_files = glob( $plugin_path . '/*.php' );
						if ( ! empty( $plugin_files ) ) {
							// Use the first PHP file found, or try to find the main file.
							$main_file = $plugin_dir . '/' . basename( $plugin_files[0] );
							// Check if it's a valid plugin file.
							if ( file_exists( WP_PLUGIN_DIR . '/' . $main_file ) ) {
								$plugin_file = $main_file;
							}
						}
					}
				} elseif ( isset( $upgrader->result['destination'] ) ) {
					// Extract plugin file from destination path.
					$destination = $upgrader->result['destination'];
					if ( strpos( $destination, WP_PLUGIN_DIR ) !== false ) {
						$plugin_dir = str_replace( trailingslashit( WP_PLUGIN_DIR ), '', $destination );
						// Try to find the main plugin file.
						$plugin_files = glob( $destination . '/*.php' );
						if ( ! empty( $plugin_files ) ) {
							$main_file = $plugin_dir . '/' . basename( $plugin_files[0] );
							if ( file_exists( WP_PLUGIN_DIR . '/' . $main_file ) ) {
								$plugin_file = $main_file;
							}
						}
					}
				}
			}

			// If still no plugin file, try to get it from the upgrader's skin.
			if ( empty( $plugin_file ) && isset( $upgrader->skin ) ) {
				if ( isset( $upgrader->skin->result ) && is_array( $upgrader->skin->result ) ) {
					if ( isset( $upgrader->skin->result['destination_name'] ) ) {
						$plugin_dir  = $upgrader->skin->result['destination_name'];
						$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_dir;
						if ( is_dir( $plugin_path ) ) {
							$plugin_files = glob( $plugin_path . '/*.php' );
							if ( ! empty( $plugin_files ) ) {
								$main_file = $plugin_dir . '/' . basename( $plugin_files[0] );
								if ( file_exists( WP_PLUGIN_DIR . '/' . $main_file ) ) {
									$plugin_file = $main_file;
								}
							}
						}
					} elseif ( isset( $upgrader->skin->result['destination'] ) ) {
						$destination = $upgrader->skin->result['destination'];
						if ( strpos( $destination, WP_PLUGIN_DIR ) !== false ) {
							$plugin_dir   = str_replace( trailingslashit( WP_PLUGIN_DIR ), '', $destination );
							$plugin_files = glob( $destination . '/*.php' );
							if ( ! empty( $plugin_files ) ) {
								$main_file = $plugin_dir . '/' . basename( $plugin_files[0] );
								if ( file_exists( WP_PLUGIN_DIR . '/' . $main_file ) ) {
									$plugin_file = $main_file;
								}
							}
						}
					}
				}
			}

			// Last resort: try to detect from recently installed plugins.
			if ( empty( $plugin_file ) ) {
				// Get recently installed plugins by checking modification time.
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$all_plugins   = get_plugins();
				$recent_plugin = null;
				$recent_time   = 0;
				$current_time  = time();
				foreach ( $all_plugins as $plugin_path => $plugin_data ) {
					$plugin_dir = dirname( $plugin_path );
					if ( '.' === $plugin_dir ) {
						// Skip plugins in root (shouldn't happen, but just in case).
						continue;
					}
					$full_path = WP_PLUGIN_DIR . '/' . $plugin_dir;
					if ( is_dir( $full_path ) ) {
						$mod_time = filemtime( $full_path );
						// Check if plugin was modified in the last 60 seconds.
						if ( $mod_time > $recent_time && $mod_time > ( $current_time - 60 ) ) {
							$recent_time   = $mod_time;
							$recent_plugin = $plugin_path;
						}
					}
				}
				if ( $recent_plugin ) {
					$plugin_file = $recent_plugin;
				}
			}

			if ( $plugin_file ) {
				$this->track_plugin_install( $plugin_file );
			}
		}

		// Handle theme updates.
		if ( 'theme' === $type && 'update' === $action ) {
			$theme_slug = isset( $hook_extra['theme'] ) ? $hook_extra['theme'] : '';
			if ( empty( $theme_slug ) ) {
				// Bulk update.
				$updated_themes = isset( $hook_extra['themes'] ) ? $hook_extra['themes'] : array();
				foreach ( $updated_themes as $theme ) {
					$this->track_theme_update( $theme );
				}
			} else {
				$this->track_theme_update( $theme_slug );
			}
		}

		// Handle theme installation.
		if ( 'theme' === $type && 'install' === $action ) {
			// WordPress may pass theme slug in different ways.
			// Try hook_extra['theme'] first, then check upgrader result.
			$theme_slug = isset( $hook_extra['theme'] ) ? $hook_extra['theme'] : '';

			// If theme slug is not in hook_extra, try to get it from upgrader result.
			if ( empty( $theme_slug ) && isset( $upgrader->result ) && is_array( $upgrader->result ) ) {
				// The result might contain the theme directory name.
				if ( isset( $upgrader->result['destination_name'] ) ) {
					$theme_slug = $upgrader->result['destination_name'];
				} elseif ( isset( $upgrader->result['destination'] ) ) {
					// Extract theme slug from destination path.
					$destination = $upgrader->result['destination'];
					$theme_root  = get_theme_root();
					if ( $theme_root && strpos( $destination, $theme_root ) !== false ) {
						$theme_slug = str_replace( trailingslashit( $theme_root ), '', $destination );
					}
				}
			}

			// If still no theme slug, try to get it from the upgrader's skin.
			if ( empty( $theme_slug ) && isset( $upgrader->skin ) ) {
				if ( isset( $upgrader->skin->result ) && is_array( $upgrader->skin->result ) ) {
					if ( isset( $upgrader->skin->result['destination_name'] ) ) {
						$theme_slug = $upgrader->skin->result['destination_name'];
					} elseif ( isset( $upgrader->skin->result['destination'] ) ) {
						$destination = $upgrader->skin->result['destination'];
						$theme_root  = get_theme_root();
						if ( $theme_root && strpos( $destination, $theme_root ) !== false ) {
							$theme_slug = str_replace( trailingslashit( $theme_root ), '', $destination );
						}
					}
				}
			}

			// Last resort: try to detect from recently installed themes.
			if ( empty( $theme_slug ) ) {
				// Get recently installed themes by checking modification time.
				$themes       = wp_get_themes();
				$recent_theme = null;
				$recent_time  = 0;
				$current_time = time();
				foreach ( $themes as $slug => $theme ) {
					$theme_dir = $theme->get_stylesheet_directory();
					if ( is_dir( $theme_dir ) ) {
						$mod_time = filemtime( $theme_dir );
						// Check if theme was modified in the last 60 seconds.
						if ( $mod_time > $recent_time && $mod_time > ( $current_time - 60 ) ) {
							$recent_time  = $mod_time;
							$recent_theme = $slug;
						}
					}
				}
				if ( $recent_theme ) {
					$theme_slug = $recent_theme;
				}
			}

			if ( $theme_slug ) {
				$this->track_theme_install( $theme_slug );
			}
		}
	}

	/**
	 * Track plugin update from WordPress admin.
	 *
	 * Note: When this method is called from upgrader_process_complete hook,
	 * the update has already completed, so we can only capture the new version.
	 * The old version is not reliably available in the update transient.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return void
	 */
	private function track_plugin_update( $plugin_file ) {
		// Note: WordPress does not store old_version in update transients.
		// By the time this hook fires, the plugin has already been updated.
		// We can only capture the current (new) version reliably.
		$old_version = null;

		// Capture state AFTER update.
		$after_state = $this->capture_plugin_state( $plugin_file );
		if ( ! $after_state ) {
			return;
		}

		// Build before state.
		$before_state = $after_state;
		if ( $old_version ) {
			$before_state['version'] = $old_version;
		}

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'plugin_update',
				'item_type'     => 'plugin',
				'item_slug'     => $plugin_file,
				'item_name'     => $after_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'old_version' => $old_version ?? $before_state['version'] ?? 'N/A',
					'new_version' => $after_state['version'] ?? 'N/A',
					'source'      => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track plugin installation from WordPress admin.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return void
	 */
	private function track_plugin_install( $plugin_file ) {
		// Capture state AFTER installation.
		$after_state = $this->capture_plugin_state( $plugin_file );
		if ( ! $after_state ) {
			return;
		}

		// Before state is null (not installed).
		$before_state = null;

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'plugin_install',
				'item_type'     => 'plugin',
				'item_slug'     => $plugin_file,
				'item_name'     => $after_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'source' => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track plugin deletion from WordPress admin.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return void
	 */
	public function track_plugin_deletion( $plugin_file ) {
		// Skip if action was performed through our plugin.
		if ( $this->is_action_from_our_plugin() ) {
			return;
		}

		// Capture state BEFORE deletion (plugin still exists).
		$before_state = $this->capture_plugin_state( $plugin_file );
		if ( ! $before_state ) {
			return;
		}

		// After state is null (deleted).
		$after_state = null;

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'plugin_delete',
				'item_type'     => 'plugin',
				'item_slug'     => $plugin_file,
				'item_name'     => $before_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'source' => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track theme switch from WordPress admin.
	 *
	 * @param string    $new_name New theme name.
	 * @param \WP_Theme $new_theme New theme object.
	 * @param \WP_Theme $old_theme Old theme object.
	 *
	 * @return void
	 */
	public function track_theme_switch( $new_name, $new_theme, $old_theme ) {
		// Skip if action was performed through our plugin.
		if ( $this->is_action_from_our_plugin() ) {
			return;
		}

		// Capture states.
		$old_theme_slug = $old_theme->get_stylesheet();
		$new_theme_slug = $new_theme->get_stylesheet();

		$before_state_old = $this->capture_theme_state( $old_theme_slug );
		$after_state_new  = $this->capture_theme_state( $new_theme_slug );

		// Record history for OLD theme (deactivated).
		if ( $before_state_old ) {
			HistoryService::record_action(
				array(
					'action_type'   => 'theme_deactivate',
					'item_type'     => 'theme',
					'item_slug'     => $old_theme_slug,
					'item_name'     => $old_theme->get( 'Name' ),
					'action_status' => 'success',
					'triggered_by'  => 'wp_admin',
					'before_state'  => $before_state_old,
					'after_state'   => array_merge( $before_state_old, array( 'is_active' => false ) ),
					'metadata'      => array(
						'source'      => 'wordpress_admin',
						'switched_to' => $new_theme_slug,
					),
				),
				false
			);
		}

		// Record history for NEW theme (activated).
		if ( $after_state_new ) {
			HistoryService::record_action(
				array(
					'action_type'   => 'theme_activate',
					'item_type'     => 'theme',
					'item_slug'     => $new_theme_slug,
					'item_name'     => $new_theme->get( 'Name' ),
					'action_status' => 'success',
					'triggered_by'  => 'wp_admin',
					'before_state'  => array_merge( $after_state_new, array( 'is_active' => false ) ),
					'after_state'   => $after_state_new,
					'metadata'      => array(
						'source'        => 'wordpress_admin',
						'switched_from' => $old_theme_slug,
					),
				),
				false
			);
		}
	}

	/**
	 * Track theme update from WordPress admin.
	 *
	 * Note: When this method is called from upgrader_process_complete hook,
	 * the update has already completed, so we can only capture the new version.
	 * The old version is not reliably available in the update transient.
	 *
	 * @param string $theme_slug Theme slug.
	 *
	 * @return void
	 */
	private function track_theme_update( $theme_slug ) {
		// Note: WordPress does not store old_version in update transients.
		// By the time this hook fires, the theme has already been updated.
		// We can only capture the current (new) version reliably.
		$old_version = null;

		// Capture state AFTER update.
		$after_state = $this->capture_theme_state( $theme_slug );
		if ( ! $after_state ) {
			return;
		}

		// Build before state.
		$before_state = $after_state;
		if ( $old_version ) {
			$before_state['version'] = $old_version;
		}

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'theme_update',
				'item_type'     => 'theme',
				'item_slug'     => $theme_slug,
				'item_name'     => $after_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'old_version' => $old_version ?? $before_state['version'] ?? 'N/A',
					'new_version' => $after_state['version'] ?? 'N/A',
					'source'      => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track theme installation from WordPress admin.
	 *
	 * @param string $theme_slug Theme slug.
	 *
	 * @return void
	 */
	private function track_theme_install( $theme_slug ) {
		// Capture state AFTER installation.
		$after_state = $this->capture_theme_state( $theme_slug );
		if ( ! $after_state ) {
			return;
		}

		// Before state is null (not installed).
		$before_state = null;

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'theme_install',
				'item_type'     => 'theme',
				'item_slug'     => $theme_slug,
				'item_name'     => $after_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'source' => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track theme deletion from WordPress admin.
	 *
	 * @param string $theme Theme slug.
	 *
	 * @return void
	 */
	public function track_theme_deletion( $theme ) {
		// Skip if action was performed through our plugin.
		if ( $this->is_action_from_our_plugin() ) {
			return;
		}

		// Capture state BEFORE deletion.
		$before_state = $this->capture_theme_state( $theme );
		if ( ! $before_state ) {
			return;
		}

		// After state is null (deleted).
		$after_state = null;

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'theme_delete',
				'item_type'     => 'theme',
				'item_slug'     => $theme,
				'item_name'     => $before_state['name'],
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'source' => 'wordpress_admin',
				),
			),
			false
		);
	}

	/**
	 * Track WordPress core update from WordPress admin.
	 *
	 * @param string $new_version New WordPress version.
	 *
	 * @return void
	 */
	public function track_core_update( $new_version ) {
		// Skip if action was performed through our plugin.
		// This hook fires AFTER upgrader_process_complete.
		// If our plugin performed the update, the flag should still be set.
		if ( $this->is_action_from_our_plugin() ) {
			// Clear the flags now that we've confirmed it's from our plugin.
			// This prevents any further duplicate checks.
			delete_transient( 'wptw_history_tracking_in_progress' );
			delete_transient( 'wptw_history_tracking_core_update' );
			return;
		}

		// Capture states.
		$before_state           = $this->capture_core_state();
		$after_state            = $this->capture_core_state();
		$after_state['version'] = $new_version;

		// Record history.
		HistoryService::record_action(
			array(
				'action_type'   => 'core_update',
				'item_type'     => 'core',
				'item_slug'     => 'wordpress',
				'item_name'     => 'WordPress',
				'action_status' => 'success',
				'triggered_by'  => 'wp_admin',
				'before_state'  => $before_state,
				'after_state'   => $after_state,
				'metadata'      => array(
					'old_version' => $before_state['version'] ?? 'N/A',
					'new_version' => $new_version,
					'source'      => 'wordpress_admin',
				),
			),
			false
		);
	}
}
