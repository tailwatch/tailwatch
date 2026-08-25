<?php
/**
 * Plugin Theme Service
 *
 * Provides services for plugin and theme details retrieval and rollback operations.
 *
 * @package    Tailwatch
 * @subpackage Services/PluginTheme
 */

namespace Tailwatch\Admin\App\Api\Services\PluginTheme;

use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\PluginTheme\Plugin\PluginManagerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PluginThemeService
 *
 * Handles logic for retrieving plugin/theme details and performing rollbacks.
 *
 */
class PluginThemeService {

	/**
	 * Get details for a plugin or theme.
	 *
	 * @param string $slug Plugin or Theme slug.
	 * @param string $type Type ('plugin' or 'theme').
	 *
	 * @return array Detail information array.
	 */
	public function tailwatch_plugin_theme_details( $slug, $type ) {
		try {
			if ( empty( $slug ) || empty( $type ) ) {
				return array(
					'message' => __( 'Slug or type not provided.', 'tailwatch' ),
					'success' => false,
				);
			}

			$slug = sanitize_text_field( $slug );
			$type = sanitize_text_field( $type );

			$update_version  = 'N/A';
			$current_version = 'N/A';
			$is_active       = false;
			$is_installed    = false;

			if ( 'plugin' === $type ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';

				// Resolve slug -> installed plugin file. Handles folder-based plugins
				// ("akismet"), single-file plugins by basename ("hello"), and the
				// canonical WP.org slug carried in the update_plugins transient
				// ("hello-dolly" -> "hello.php").
				$plugin_file = PluginManagerService::get_plugin_file_by_slug( $slug );
				$is_installed = ( null !== $plugin_file );

				if ( null !== $plugin_file ) {
					$all_plugins = get_plugins();
					if ( isset( $all_plugins[ $plugin_file ]['Version'] ) ) {
						$current_version = $all_plugins[ $plugin_file ]['Version'];
					}
					$is_active = is_plugin_active( $plugin_file );
				}

				$update_plugins = get_site_transient( 'update_plugins' );
				if ( false === $update_plugins ) {
					wp_update_plugins();
					$update_plugins = get_site_transient( 'update_plugins' );
				}

				if ( $plugin_file && $update_plugins && isset( $update_plugins->response[ $plugin_file ]->new_version ) ) {
					$update_version = $update_plugins->response[ $plugin_file ]->new_version;
				}

				$response = wp_remote_get( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug ) . '&request[fields][icons]=1' );
			} elseif ( 'theme' === $type ) {
				$all_themes = wp_get_themes();
				$is_installed = isset( $all_themes[ $slug ] );
				if ( isset( $all_themes[ $slug ] ) ) {
					$current_version = $all_themes[ $slug ]->get( 'Version' );
				}

				// Check if theme is active.
				$active_theme = wp_get_theme();
				$is_active    = ( $active_theme->get_stylesheet() === $slug || $active_theme->get_template() === $slug );

				$update_themes = get_site_transient( 'update_themes' );
				if ( false === $update_themes ) {
					wp_update_themes();
					$update_themes = get_site_transient( 'update_themes' );
				}
				if ( $update_themes && isset( $update_themes->response[ $slug ] ) ) {
					$update_version = $update_themes->response[ $slug ]['new_version'];
				}

				$response = wp_remote_get( 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=' . rawurlencode( $slug ) );
			} else {
				return array(
					'message' => __( 'Invalid type provided.', 'tailwatch' ),
					'success' => false,
				);
			}

			if ( is_wp_error( $response ) ) {
				// Genuine connection failure (DNS, timeout, cURL error, etc.).
				return array(
					'message'    => __( 'Unable to reach WordPress.org. Please check your internet connection and try again.', 'tailwatch' ),
					'error_type' => 'network_error',
					'success'    => false,
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$data          = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $response_code ) {
				// 404 with an error body means the item doesn't exist in the WP.org repository.
				if ( 404 === $response_code && $data && isset( $data['error'] ) ) {
					return array(
						'name'                 => ucfirst( $slug ),
						'current_version'      => $current_version,
						'update_version'       => 'N/A',
						'update_available'     => false,
						'is_active'            => $is_active,
						'is_installed'         => $is_installed,
						'is_custom'            => true,
						'last_updated'         => 'N/A',
						'active_installations' => 'N/A',
						'requires_wp'          => 'N/A',
						'tested_up_to'         => 'N/A',
						'requires_php'         => 'N/A',
						'description'          => 'This ' . $type . ' is not available in the WordPress.org repository.',
						'changelog'            => 'N/A',
						'icon'                 => '',
						'screenshot_url'       => 'N/A',
						'message'              => __( 'This ', 'tailwatch' ) . $type . ' does not appear to be available in the WordPress.org repository.',
						'success'              => true,
					);
				}

				// Any other non-200 (5xx, etc.) is an unexpected server/network error.
				return array(
					'message'    => __( 'Unable to retrieve ', 'tailwatch' ) . $type . ' information from WordPress.org. Please try again later.',
					'error_type' => 'network_error',
					'success'    => false,
				);
			}

			// HTTP 200 — check body for API-level errors (e.g. slug not found returns 200 on some endpoints).
			if ( ! $data || isset( $data['error'] ) || ! isset( $data['name'] ) ) {
				// Plugin/theme not found in WordPress.org repository - it's a custom/private item.
				// Return basic details from local installation.
				return array(
					'name'                 => ucfirst( $slug ),
					'current_version'      => $current_version,
					'update_version'       => 'N/A',
					'update_available'     => false,
					'is_active'            => $is_active,
					'is_installed'         => $is_installed,
					'is_custom'            => true,
					'last_updated'         => 'N/A',
					'active_installations' => 'N/A',
					'requires_wp'          => 'N/A',
					'tested_up_to'         => 'N/A',
					'requires_php'         => 'N/A',
					'description'          => 'This ' . $type . ' is not available in the WordPress.org repository.',
					'changelog'            => 'N/A',
					'icon'                 => '',
					'screenshot_url'       => 'N/A',
					'message'              => __( 'This ', 'tailwatch' ) . $type . ' does not appear to be available in the WordPress.org repository.',
					'success'              => true,
				);
			}

			$update_available = ( 'N/A' !== $update_version && version_compare( $current_version, $update_version, '<' ) );

			if ( isset( $data['name'] ) ) {
				// Extract description from sections.
				$description = isset( $data['sections']['description'] ) ? wp_trim_words( $data['sections']['description'], 200, '...' ) : 'No description available';

				// Handle different API response structures for plugins vs themes.
				if ( 'plugin' === $type ) {
					// Plugin API: has 'contributors' array, 'icons', and 'sections.changelog'.
					$changelog = isset( $data['sections']['changelog'] ) ? $data['sections']['changelog'] : 'No Change Logs available';

					// Get plugin icon from icons array (prefer 2x, then 1x, then svg, then default).
					$icon = '';
					if ( isset( $data['icons'] ) && is_array( $data['icons'] ) ) {
						if ( isset( $data['icons']['2x'] ) ) {
							$icon = $data['icons']['2x'];
						} elseif ( isset( $data['icons']['1x'] ) ) {
							$icon = $data['icons']['1x'];
						} elseif ( isset( $data['icons']['svg'] ) ) {
							$icon = $data['icons']['svg'];
						} elseif ( isset( $data['icons']['default'] ) ) {
							$icon = $data['icons']['default'];
						}
					}

					$active_installations = isset( $data['active_installs'] ) ? $data['active_installs'] : 'N/A';
					$tested_up_to         = isset( $data['tested'] ) ? $data['tested'] : 'N/A';
				} else {
					// Theme API: has 'author' array (not 'contributors'), no changelog, uses 'downloaded' instead of 'active_installs'.
					// Get changelog from local theme files since Theme API doesn't provide it.
					$changelog = $this->get_theme_changelog( $slug );

					// Themes use screenshot_url as their icon/preview image.
					$icon = isset( $data['screenshot_url'] ) ? $data['screenshot_url'] : '';

					// Themes use 'downloaded' count instead of 'active_installs'.
					$active_installations = isset( $data['downloaded'] ) ? $data['downloaded'] : 'N/A';
					// Themes don't have 'tested' field.
					$tested_up_to = 'N/A';
				}

				$details = array(
					'name'                 => $data['name'],
					'current_version'      => $current_version,
					'update_version'       => $update_version,
					'update_available'     => $update_available,
					'is_active'            => $is_active,
					'is_installed'         => $is_installed,
					'is_custom'            => false, // From WordPress.org - rollback and update available.
					'last_updated'         => isset( $data['last_updated'] ) ? $data['last_updated'] : 'N/A',
					'active_installations' => $active_installations,
					'requires_wp'          => isset( $data['requires'] ) ? $data['requires'] : 'N/A',
					'tested_up_to'         => $tested_up_to,
					'requires_php'         => isset( $data['requires_php'] ) ? $data['requires_php'] : 'N/A',
					'description'          => $description,
					'changelog'            => $changelog,
					'icon'                 => $icon,
					'screenshot_url'       => isset( $data['screenshot_url'] ) ? $data['screenshot_url'] : 'N/A',
					'message'              => ucfirst( $type ) . ' details retrieved successfully.',
					'success'              => true,
				);
				return $details;
			}
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Failed to retrieve details.', 'tailwatch' ),
				'success' => false,
			);
		}
	}

	/**
	 * Rollback a plugin or theme to a specific version.
	 *
	 * @param string $type    Type ('plugin' or 'theme').
	 * @param string $slug    Plugin or Theme slug.
	 * @param string $version Version to rollback to.
	 *
	 * @return array Result array with success status and message.
	 */
	public function tailwatch_rollback( $type, $slug, $version ) {
		try {
			if ( ! $type || ! $slug || ! $version ) {
				return array(
					'message' => __( 'Invalid parameters.', 'tailwatch' ),
					'success' => false,
				);
			}

			$type    = sanitize_text_field( $type );
			$slug    = sanitize_text_field( $slug );
			$version = sanitize_text_field( $version );

			if ( 'plugin' === $type ) {
				$item_directory   = explode( '/', $slug )[0];
				$destination      = WP_PLUGIN_DIR . '/' . $item_directory;
				$backup_directory = $destination . '_tailwatch_rollback_backup';
			} elseif ( 'theme' === $type ) {
				$item_directory   = basename( $slug );
				$destination      = get_theme_root() . '/' . $item_directory;
				$backup_directory = $destination . '_tailwatch_rollback_backup';
			} else {
				return array(
					'message' => __( 'Invalid type specified.', 'tailwatch' ),
					'success' => false,
				);
			}

			// Initialize WP_Filesystem for file operations using direct method.
			if ( ! FilesystemService::setup_filesystem() ) {
				return array(
					'message' => __( 'Failed to initialize filesystem.', 'tailwatch' ),
					'success' => false,
				);
			}

			// Verify the destination exists before proceeding.
			if ( ! file_exists( $destination ) || ! is_dir( $destination ) ) {
				return array(
					'message' => ucfirst( $type ) . ' directory not found.',
					'success' => false,
				);
			}

			// Clean up any existing backup from previous failed rollback.
			if ( file_exists( $backup_directory ) ) {
				$this->delete_directory( $backup_directory );
			}

			// Create backup by copying (more reliable than move on Windows/cross-filesystem).
			// Note: $wp_filesystem->copy() is for files only, so we use recursive copy_directory().
			if ( ! $this->copy_directory( $destination, $backup_directory ) ) {
				return array(
					'message' => __( 'Failed to create backup of current ', 'tailwatch' ) . $type . '.',
					'success' => false,
				);
			}

			// Verify backup was created successfully.
			if ( ! file_exists( $backup_directory ) || ! is_dir( $backup_directory ) ) {
				return array(
					'message' => __( 'Backup verification failed.', 'tailwatch' ),
					'success' => false,
				);
			}

			// Delete the original directory completely before installing new version.
			if ( ! $this->delete_directory( $destination ) ) {
				// Restore backup on failure.
				$this->delete_directory( $backup_directory );
				return array(
					'message' => __( 'Failed to remove current ', 'tailwatch' ) . $type . ' for rollback.',
					'success' => false,
				);
			}

			// Verify deletion was successful.
			if ( file_exists( $destination ) ) {
				// Directory still exists, restore from backup.
				$this->copy_directory( $backup_directory, $destination );
				$this->delete_directory( $backup_directory );
				return array(
					'message' => __( 'Failed to completely remove current ', 'tailwatch' ) . $type . '. Files may be locked.',
					'success' => false,
				);
			}

			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/misc.php';
			include_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';

			// Use Automatic_Upgrader_Skin for silent operation.
			$skin = new \Automatic_Upgrader_Skin();

			if ( 'plugin' === $type ) {
				$url      = "https://downloads.wordpress.org/plugin/{$item_directory}.{$version}.zip";
				$upgrader = new \Plugin_Upgrader( $skin );
				$result   = $upgrader->install( $url );
			} else {
				$url      = "https://downloads.wordpress.org/theme/{$item_directory}.{$version}.zip";
				$upgrader = new \Theme_Upgrader( $skin );
				$result   = $upgrader->install( $url );
			}

			// Check if installation failed.
			// Some plugins (e.g., wp-file-manager) have nested ZIPs which cause Plugin_Upgrader to return false
			// even though the files were extracted to wp-content/upgrade/ folder.
			$installation_failed = is_wp_error( $result ) || false === $result || null === $result;

			if ( $installation_failed ) {
				// Installation failed - check wp-content/upgrade/ folder for nested ZIP case.
				$upgrade_folder_result = $this->handle_upgrade_folder_nested_zip( $item_directory, $version, $destination );

				if ( is_wp_error( $upgrade_folder_result ) ) {
					// Could not recover from upgrade folder - restore backup.
					$this->copy_directory( $backup_directory, $destination );
					$this->delete_directory( $backup_directory );
					$error_message = is_wp_error( $result ) ? $result->get_error_message() : $upgrade_folder_result->get_error_message();
					return array(
						'message' => __( 'Rollback failed: ', 'tailwatch' ) . $error_message . '. Original version restored.',
						'success' => false,
					);
				}
				// Successfully recovered from upgrade folder - continue to success.
			}

			// Verify the installation succeeded.
			if ( ! file_exists( $destination ) || ! is_dir( $destination ) ) {
				$this->copy_directory( $backup_directory, $destination );
				$this->delete_directory( $backup_directory );
				return array(
					'message' => __( 'Rollback installation verification failed. Original version restored.', 'tailwatch' ),
					'success' => false,
				);
			}

			// Success - clean up backup.
			$this->delete_directory( $backup_directory );

			return array(
				'message' => ucfirst( $type ) . ' rolled back to version ' . $version . ' successfully.',
				'success' => true,
			);
		} catch ( \Throwable $e ) {
			// Attempt to restore backup on exception.
			if ( isset( $backup_directory ) && file_exists( $backup_directory ) ) {
				if ( isset( $destination ) && ! file_exists( $destination ) ) {
					$this->copy_directory( $backup_directory, $destination );
				}
				$this->delete_directory( $backup_directory );
			}

			return array(
				'message' => __( 'Exception during rollback.', 'tailwatch' ),
				'success' => false,
			);
		}
	}

	/**
	 * Get changelog for a theme from local files.
	 *
	 * Checks for changelog.txt first, then falls back to readme.txt.
	 * WordPress.org Theme API doesn't provide changelog, so we read it from local files.
	 *
	 * @param string $slug Theme slug.
	 *
	 * @return string Changelog HTML content or 'N/A' if not found.
	 */
	private function get_theme_changelog( $slug ) {
		$theme_dir = get_theme_root() . '/' . $slug;

		// Check if theme directory exists.
		if ( ! file_exists( $theme_dir ) || ! is_dir( $theme_dir ) ) {
			return 'N/A';
		}

		// Initialize WP_Filesystem so we can read theme files through WordPress's
		// filesystem abstraction instead of native file_get_contents.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// First, check for changelog.txt file.
		$changelog_file = $theme_dir . '/changelog.txt';
		if ( $wp_filesystem && $wp_filesystem->exists( $changelog_file ) && $wp_filesystem->is_readable( $changelog_file ) ) {
			$content = $wp_filesystem->get_contents( $changelog_file );
			if ( ! empty( $content ) ) {
				return $this->format_changelog_content( $content );
			}
		}

		// Second, check for readme.txt file and extract changelog section.
		$readme_file = $theme_dir . '/readme.txt';
		if ( $wp_filesystem && $wp_filesystem->exists( $readme_file ) && $wp_filesystem->is_readable( $readme_file ) ) {
			$content = $wp_filesystem->get_contents( $readme_file );
			if ( ! empty( $content ) ) {
				$changelog = $this->extract_changelog_from_readme( $content );
				if ( ! empty( $changelog ) ) {
					return $changelog;
				}
			}
		}

		return 'N/A';
	}

	/**
	 * Extract changelog section from readme.txt content.
	 *
	 * WordPress readme.txt files use the format:
	 * == Changelog ==
	 * = version =
	 * * Change item
	 *
	 * @param string $content Readme file content.
	 *
	 * @return string Changelog HTML content or empty string if not found.
	 */
	private function extract_changelog_from_readme( $content ) {
		// Look for == Changelog == section (case-insensitive).
		$pattern = '/==\s*Changelog\s*==(.*?)(?:==\s*[^=]|$)/is';

		if ( preg_match( $pattern, $content, $matches ) ) {
			$changelog_text = trim( $matches[1] );
			if ( ! empty( $changelog_text ) ) {
				return $this->format_changelog_content( $changelog_text );
			}
		}

		return '';
	}

	/**
	 * Format changelog content to HTML.
	 *
	 * Converts WordPress readme.txt changelog format to HTML:
	 * = version = becomes <h4>version</h4>
	 * * item becomes <li>item</li>
	 *
	 * @param string $content Raw changelog content.
	 *
	 * @return string Formatted HTML changelog.
	 */
	private function format_changelog_content( $content ) {
		// Sanitize the content first.
		$content = wp_kses_post( $content );

		// Convert = version = or = version - date = to <h4>version</h4>.
		$content = preg_replace( '/^=\s*(.+?)\s*=\s*$/m', '<h4>$1</h4>', $content );

		// Convert * item to list items.
		$lines        = explode( "\n", $content );
		$in_list      = false;
		$result_lines = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Check if line starts with * or -.
			if ( preg_match( '/^[\*\-]\s+(.+)$/', $trimmed, $matches ) ) {
				if ( ! $in_list ) {
					$result_lines[] = '<ul>';
					$in_list        = true;
				}
				$result_lines[] = '<li>' . trim( $matches[1] ) . '</li>';
			} else {
				if ( $in_list ) {
					$result_lines[] = '</ul>';
					$in_list        = false;
				}
				if ( ! empty( $trimmed ) ) {
					$result_lines[] = $trimmed;
				}
			}
		}

		// Close any open list.
		if ( $in_list ) {
			$result_lines[] = '</ul>';
		}

		return implode( "\n", $result_lines );
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source      Source directory path.
	 * @param string $destination Destination directory path.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function copy_directory( $source, $destination ) {
		if ( ! file_exists( $source ) || ! is_dir( $source ) ) {
			return false;
		}

		// Get WP_Filesystem using direct method for API context.
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		// Create destination directory.
		if ( ! file_exists( $destination ) ) {
			if ( ! $wp_filesystem->mkdir( $destination, FS_CHMOD_DIR ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- Streamed recursive copy of a plugin/theme upgrade tree; dirlist() would materialize the whole tree at once and blow memory on large plugins.
		$dir = opendir( $source );
		if ( false === $dir ) {
			return false;
		}

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.WP.AlternativeFunctions.file_system_operations_readdir -- Standard readdir pattern; paired with opendir() above.
		while ( false !== ( $item = readdir( $dir ) ) ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source_path      = $source . DIRECTORY_SEPARATOR . $item;
			$destination_path = $destination . DIRECTORY_SEPARATOR . $item;

			if ( is_dir( $source_path ) ) {
				if ( ! $this->copy_directory( $source_path, $destination_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- Closes the opendir() handle above.
					closedir( $dir );
					return false;
				}
			} elseif ( ! $wp_filesystem->copy( $source_path, $destination_path, true ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- Closes the opendir() handle above.
				closedir( $dir );
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- Closes the opendir() handle above.
		closedir( $dir );
		return true;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function delete_directory( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return true;
		}

		// Get WP_Filesystem using direct method for API context.
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		// Handle single file deletion.
		if ( ! is_dir( $dir ) ) {
			return $wp_filesystem->delete( $dir );
		}

		// Handle directory - recurse through contents first.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Enumerating plugin-owned directory during recursive delete; dirlist() is heavier and returns a different structure.
		$items = scandir( $dir );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( ! $this->delete_directory( $dir . DIRECTORY_SEPARATOR . $item ) ) {
				return false;
			}
		}

		// Delete the now-empty directory.
		return $wp_filesystem->delete( $dir );
	}

	/**
	 * Handle nested ZIP in wp-content/upgrade/ folder.
	 *
	 * When Plugin_Upgrader fails due to nested ZIP structure, the files are still
	 * extracted to wp-content/upgrade/ folder. This method finds the nested ZIP,
	 * extracts it, and copies files to the destination.
	 *
	 * @param string $item_directory Plugin/theme directory name.
	 * @param string $version        Version being installed.
	 * @param string $destination    Final destination path.
	 *
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	private function handle_upgrade_folder_nested_zip( $item_directory, $version, $destination ) {
		$upgrade_dir = WP_CONTENT_DIR . '/upgrade/';

		// Look for upgrade folder matching this item.
		$possible_folders = array(
			$upgrade_dir . $item_directory . '.' . $version,
			$upgrade_dir . $item_directory,
		);

		$found_upgrade_folder = null;
		foreach ( $possible_folders as $folder ) {
			if ( file_exists( $folder ) && is_dir( $folder ) ) {
				$found_upgrade_folder = $folder;
				break;
			}
		}

		// Also scan upgrade directory for any folder starting with item_directory.
		if ( null === $found_upgrade_folder && file_exists( $upgrade_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Enumerating WP core's own /upgrade/ staging tree; short-lived enumeration.
			$items = scandir( $upgrade_dir );
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				if ( 0 === strpos( $item, $item_directory ) && is_dir( $upgrade_dir . $item ) ) {
					$found_upgrade_folder = $upgrade_dir . $item;
					break;
				}
			}
		}

		if ( null === $found_upgrade_folder ) {
			return new \WP_Error( 'no_upgrade_folder', 'No upgrade folder found for this item.' );
		}

		// Find the item folder inside the upgrade folder.
		$item_folder = $found_upgrade_folder . '/' . $item_directory;
		if ( ! file_exists( $item_folder ) || ! is_dir( $item_folder ) ) {
			// Try to find any subdirectory.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Fallback enumeration of upgrade folder when the expected name is not present.
			$items = scandir( $found_upgrade_folder );
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				if ( is_dir( $found_upgrade_folder . '/' . $item ) ) {
					$item_folder = $found_upgrade_folder . '/' . $item;
					break;
				}
			}
		}

		if ( ! file_exists( $item_folder ) || ! is_dir( $item_folder ) ) {
			$this->delete_directory( $found_upgrade_folder );
			return new \WP_Error( 'no_item_folder', 'No item folder found in upgrade directory.' );
		}

		// Check for nested ZIP in the item folder.
		$nested_zip = null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Enumerating WP core upgrade item folder for nested-zip discovery.
		$items      = scandir( $item_folder );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$file_path = $item_folder . '/' . $item;
			if ( is_file( $file_path ) && preg_match( '/\.zip$/i', $item ) ) {
				$nested_zip = $file_path;
				break;
			}
		}

		if ( null === $nested_zip ) {
			// No nested ZIP - maybe the files are directly here, try to copy them.
			if ( ! wp_mkdir_p( $destination ) ) {
				$this->delete_directory( $found_upgrade_folder );
				return new \WP_Error( 'mkdir_failed', 'Could not create destination directory.' );
			}

			if ( ! $this->copy_directory( $item_folder, $destination ) ) {
				$this->delete_directory( $found_upgrade_folder );
				return new \WP_Error( 'copy_failed', 'Could not copy files to destination.' );
			}

			$this->delete_directory( $found_upgrade_folder );
			return true;
		}

		// Extract the nested ZIP into the uploads dir, not the WP upgrade dir.
		include_once ABSPATH . 'wp-admin/includes/file.php';

		$upload_dir       = wp_get_upload_dir();
		$temp_extract_dir = trailingslashit( $upload_dir['basedir'] ) . 'tailwatch-temp/nested_extract_' . time();
		if ( ! wp_mkdir_p( $temp_extract_dir ) ) {
			$this->delete_directory( $found_upgrade_folder );
			return new \WP_Error( 'mkdir_failed', 'Could not create temp extraction directory.' );
		}

		$extract_result = unzip_file( $nested_zip, $temp_extract_dir );
		if ( is_wp_error( $extract_result ) ) {
			$this->delete_directory( $temp_extract_dir );
			$this->delete_directory( $found_upgrade_folder );
			return $extract_result;
		}

		// Find the extracted folder.
		$extracted_folder = null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Enumerating our own uploads/tailwatch-temp extraction dir; short-lived, we own the contents.
		$items            = scandir( $temp_extract_dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$item_path = $temp_extract_dir . '/' . $item;
			if ( is_dir( $item_path ) ) {
				$extracted_folder = $item_path;
				break;
			}
		}

		if ( null === $extracted_folder ) {
			// Files might be directly in temp_extract_dir.
			$extracted_folder = $temp_extract_dir;
		}

		// Create destination and copy files.
		if ( ! wp_mkdir_p( $destination ) ) {
			$this->delete_directory( $temp_extract_dir );
			$this->delete_directory( $found_upgrade_folder );
			return new \WP_Error( 'mkdir_failed', 'Could not create destination directory.' );
		}

		if ( ! $this->copy_directory( $extracted_folder, $destination ) ) {
			$this->delete_directory( $temp_extract_dir );
			$this->delete_directory( $found_upgrade_folder );
			return new \WP_Error( 'copy_failed', 'Could not copy extracted files to destination.' );
		}

		// Clean up temp extraction + upgrade folder.
		$this->delete_directory( $temp_extract_dir );
		$this->delete_directory( $found_upgrade_folder );

		return true;
	}
}
