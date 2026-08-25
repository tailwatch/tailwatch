<?php
/**
 * Theme Manager Service
 *
 * Handles detailed logic for theme activation, installation, and deletion.
 *
 * @package    Tailwatch
 * @subpackage Services/PluginTheme/Theme
 */

namespace Tailwatch\Admin\App\Api\Services\PluginTheme\Theme;

use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ThemeManagerService
 *
 * Service class for theme management operations.
 *
 */
class ThemeManagerService {

	/**
	 * Activate a theme.
	 *
	 * @param string $theme_slug Theme slug to activate.
	 *
	 * @return array {
	 *     Result array.
	 *
	 *     @type bool   $success        Operation success status.
	 *     @type string $theme          Theme slug.
	 *     @type string $name           Theme name.
	 *     @type string $version        Theme version.
	 *     @type string $previous_theme Name of the previously active theme (on success).
	 *     @type string $status         Status of activation (e.g., 'activated', 'already_active').
	 *     @type string $error          Error message (on failure).
	 * }
	 */
	public static function tailwatch_activate_theme( string $theme_slug ): array {
		$theme_slug = sanitize_text_field( $theme_slug );

		// Check if theme exists.
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return array(
				'success' => false,
				'error'   => 'Theme not found.',
			);
		}

		// Check compatibility (WP & PHP version).
		$compatibility = self::check_compatibility( $theme );
		if ( ! $compatibility['compatible'] ) {
			return array(
				'success' => false,
				'error'   => $compatibility['reason'],
			);
		}

		// Check if already active.
		$current_theme = wp_get_theme();
		if ( $current_theme->get_stylesheet() === $theme_slug ) {
			return array(
				'success' => true,
				'theme'   => $theme_slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'status'  => 'already_active',
			);
		}

		try {
			// Get old theme info for logging.
			$old_theme_name = $current_theme->get( 'Name' );

			// Switch theme.
			switch_theme( $theme_slug );

			// Verify theme was switched.
			$new_current_theme = wp_get_theme();
			if ( $new_current_theme->get_stylesheet() !== $theme_slug ) {
				return array(
					'success' => false,
					'error'   => 'Failed to activate theme.',
				);
			}

			wp_cache_flush();

			return array(
				'success'        => true,
				'theme'          => $theme_slug,
				'name'           => $theme->get( 'Name' ),
				'version'        => $theme->get( 'Version' ),
				'previous_theme' => $old_theme_name,
				'status'         => 'activated',
			);

		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => 'Exception: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Delete a theme.
	 *
	 * @param string $theme_slug Theme slug to delete.
	 *
	 * @return array {
	 *     Result array.
	 *
	 *     @type bool   $success Operation success status.
	 *     @type string $theme   Theme slug (on success).
	 *     @type string $name    Theme name (on success).
	 *     @type string $status  Deletion status (e.g., 'deleted').
	 *     @type string $error   Error message (on failure).
	 * }
	 */
	public static function tailwatch_delete_theme( string $theme_slug ): array {
		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$theme_slug = sanitize_text_field( $theme_slug );

		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return array(
				'success' => false,
				'error'   => 'Theme not found.',
			);
		}

		$current_theme = wp_get_theme();
		if ( $current_theme->get_stylesheet() === $theme_slug ) {
			return array(
				'success' => false,
				'error'   => 'Cannot delete the active theme. Please switch to another theme first.',
			);
		}

		// Refuse if other installed themes list this one as their Template (parent).
		// WP core's delete_theme() only removes the passed stylesheet, so orphaned
		// children would appear in Site Health as "Broken Themes: parent missing".
		$dependent_children = self::find_child_themes( $theme_slug );
		if ( ! empty( $dependent_children ) ) {
			return array(
				'success'    => false,
				'error_code' => 'has_child_themes',
				'children'   => $dependent_children,
				'error'      => sprintf(
					'Cannot delete "%1$s": it is the parent of %2$d child theme(s) (%3$s). Delete the child theme(s) first to avoid broken-theme warnings.',
					$theme->get( 'Name' ),
					count( $dependent_children ),
					implode( ', ', $dependent_children )
				),
			);
		}

		try {
			$theme_name = $theme->get( 'Name' );
			$theme_root = $theme->get_theme_root();
			$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;

			// First pass: WP core.
			$result   = delete_theme( $theme_slug );
			$wp_error = is_wp_error( $result ) ? $result : null;

			// WP core relies on $wp_filesystem->delete($dir, true) which can partially
			// succeed — some files unlinked, others left behind due to file locks
			// (Windows/XAMPP, antivirus), read-only flags, or ownership mismatches on
			// shared hosts. That leaves a half-deleted folder that WP's header parser
			// later flags as a broken theme. Verify what's on disk and run a fallback
			// recursive cleanup if anything remains.
			clearstatcache();
			if ( is_dir( $theme_dir ) ) {
				self::force_recursive_delete( $theme_dir );
				clearstatcache();
			}

			if ( is_dir( $theme_dir ) ) {
				// Even fallback failed. Report the real state so the UI can surface
				// an actionable message instead of a false-positive "deleted".
				$residue_msg = 'Theme files could not be fully removed. Some files may be held open by another process (editor, antivirus, PHP worker). Please retry, or remove the folder manually: ' . $theme_dir;
				return array(
					'success' => false,
					'theme'   => $theme_slug,
					'name'    => $theme_name,
					'error'   => $wp_error ? $wp_error->get_error_message() . ' ' . $residue_msg : $residue_msg,
				);
			}

			// Directory is gone. If WP core reported failure but fallback finished
			// the job, run the post-delete cleanup steps that WP core's delete_theme()
			// normally performs (lines 108-137 of wp-admin/includes/theme.php). When
			// WP core succeeds these already happened; we only need them on recovery.
			if ( null !== $wp_error ) {
				self::post_delete_cleanup( $theme_slug );
				Log::warning(
					'Theme deletion required fallback cleanup: ' . $theme_slug,
					array(
						'feature'    => 'themes',
						'action'     => 'theme_delete_fallback_cleanup',
						'theme_slug' => $theme_slug,
						'wp_error'   => $wp_error->get_error_message(),
					)
				);
			}

			wp_clean_themes_cache();
			wp_cache_flush();

			return array(
				'success' => true,
				'theme'   => $theme_slug,
				'name'    => $theme_name,
				'status'  => 'deleted',
			);

		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => 'Exception: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Find child themes whose Template header points at $parent_slug.
	 *
	 * @param string $parent_slug Parent stylesheet slug.
	 *
	 * @return array<string,string> Map of child slug => display name.
	 */
	private static function find_child_themes( string $parent_slug ): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$children = array();
		foreach ( wp_get_themes() as $child_slug => $child_theme ) {
			if ( $child_slug === $parent_slug ) {
				continue;
			}
			if ( $child_theme->get( 'Template' ) === $parent_slug ) {
				$children[ $child_slug ] = $child_theme->get( 'Name' );
			}
		}
		return $children;
	}

	/**
	 * Run the post-delete cleanup that WP core's delete_theme() performs on success.
	 *
	 * Only needed on the fallback-recovery path — when WP core returned WP_Error
	 * but our force_recursive_delete() managed to clear the residue. WP core's
	 * delete_theme() bails at the filesystem-delete step and never reaches these
	 * cleanup lines, so we mirror them here to stay 100% consistent with core.
	 *
	 * Mirrors wp-admin/includes/theme.php lines 108–137.
	 *
	 * @param string $theme_slug Stylesheet slug of the deleted theme.
	 *
	 * @return void
	 */
	private static function post_delete_cleanup( string $theme_slug ): void {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) || ! is_object( $wp_filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		// Translation files (.po / .mo / .l10n.php / *.json) in WP_LANG_DIR/themes/.
		if ( function_exists( 'wp_get_installed_translations' ) && defined( 'WP_LANG_DIR' ) ) {
			$theme_translations = wp_get_installed_translations( 'themes' );
			if ( ! empty( $theme_translations[ $theme_slug ] ) ) {
				$lang_dir = trailingslashit( WP_LANG_DIR ) . 'themes/';
				foreach ( $theme_translations[ $theme_slug ] as $translation => $data ) {
					$base = $lang_dir . $theme_slug . '-' . $translation;
					foreach ( array( '.po', '.mo', '.l10n.php' ) as $ext ) {
						if ( file_exists( $base . $ext ) ) {
							if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
								$wp_filesystem->delete( $base . $ext );
							} else {
								// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback path.
								@unlink( $base . $ext );
							}
						}
					}

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Wildcard scan for JSON translation shards; dirlist() can't express the theme-slug/translation pattern.
					$json_files = glob( $lang_dir . $theme_slug . '-' . $translation . '-*.json' );
					if ( $json_files ) {
						foreach ( $json_files as $json_file ) {
							if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
								$wp_filesystem->delete( $json_file );
							} else {
								// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback path.
								@unlink( $json_file );
							}
						}
					}
				}
			}
		}

		// Multisite: drop the theme from the network-allowed list.
		if ( is_multisite() && class_exists( 'WP_Theme' ) && method_exists( 'WP_Theme', 'network_disable_theme' ) ) {
			\WP_Theme::network_disable_theme( $theme_slug );
		}

		// Force-refresh the updates transient so the deleted theme stops appearing
		// in "updates available" lists until WP's next scheduled check.
		delete_site_transient( 'update_themes' );
	}

	/**
	 * Recursively delete a directory as a fallback to WP core's delete_theme().
	 *
	 * Runs WP_Filesystem first so FTP/SSH transports on managed hosts still work,
	 * then falls back to native PHP with chmod + unlink. The second pass mops up
	 * cases where a transient file lock on the first pass has resolved by the
	 * time we re-enter (common on Windows/XAMPP with antivirus or editor handles).
	 *
	 * Guardrail: refuses to operate outside wp-content so a bad caller can never
	 * recurse into something dangerous.
	 *
	 * @param string $dir Absolute path to the directory to remove.
	 *
	 * @return void
	 */
	private static function force_recursive_delete( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$allowed = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		$target  = wp_normalize_path( trailingslashit( $dir ) );
		if ( 0 !== strpos( $target, $allowed ) ) {
			return;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		// Pass 1: WP_Filesystem.
		if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
			$wp_filesystem->delete( $dir, true );
			clearstatcache();
			if ( ! is_dir( $dir ) ) {
				return;
			}
		}

		// Pass 2: native recursion. chmod before unlink to handle read-only files.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Fallback path; scandir may fail on permission denied and we tolerate it.
		$items = @scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::force_recursive_delete( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Fallback path; WP_Filesystem already tried and failed.
				@chmod( $path, 0666 );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Fallback path; WP_Filesystem already tried and failed.
				@unlink( $path );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Fallback path; WP_Filesystem already tried and failed.
		@rmdir( $dir );
	}

	/**
	 * Get all installed themes.
	 *
	 * @param array $args Filter and pagination arguments.
	 *
	 * @return array {
	 *     Result array.
	 *
	 *     @type bool  $success    Operation success status.
	 *     @type array $themes     List of installed themes.
	 *     @type array $filters    Applied filters.
	 *     @type array $pagination Pagination data.
	 * }
	 */
	public static function tailwatch_get_all_themes( array $args = array() ): array {
		// Default arguments.
		$defaults = array(
			'page'         => 1,
			'limit'        => 10,
			'updates_only' => false, // Filter to show only themes with available updates.
		);

		$args = array_merge( $defaults, $args );

		// Sanitize arguments.
		$page         = max( 1, (int) $args['page'] );
		$per_page     = max( 1, min( 100, (int) $args['limit'] ) );
		$updates_only = (bool) $args['updates_only'];

		$all_themes  = wp_get_themes();
		$themes_list = array();

		// Get current active theme.
		$current_theme      = wp_get_theme();
		$current_stylesheet = $current_theme->get_stylesheet();

		// Get update information.
		$update_themes = get_site_transient( 'update_themes' );

		foreach ( $all_themes as $theme_slug => $theme_data ) {
			$is_active = ( $theme_slug === $current_stylesheet );

			$update_available = isset( $update_themes->response[ $theme_slug ] );
			$update_version   = $update_available ? $update_themes->response[ $theme_slug ]['new_version'] : null;

			// Check compatibility (WP/PHP Version).
			$compatibility = self::check_compatibility( $theme_data );

			$themes_list[] = array(
				'theme'                     => $theme_slug,
				'name'                      => $theme_data->get( 'Name' ),
				'version'                   => $theme_data->get( 'Version' ),
				'author'                    => $theme_data->get( 'Author' ),
				'description'               => $theme_data->get( 'Description' ),
				'screenshot'                => $theme_data->get_screenshot(),
				'is_active'                 => $is_active,
				'update_available'          => $update_available,
				'update_version'            => $update_version,
				'compatibility'             => $compatibility, // Add compatibility info.
				'can_activate'              => $compatibility['compatible'] && ! $is_active,
				'activation_blocked_reason' => $compatibility['compatible'] ? '' : $compatibility['reason'],
			);
		}

		// Filter for updates only if requested.
		if ( $updates_only ) {
			$themes_list = array_filter(
				$themes_list,
				function ( $theme ) {
					return true === $theme['update_available'];
				}
			);
			// Re-index array after filtering.
			$themes_list = array_values( $themes_list );
		}

		// Calculate pagination.
		$total       = count( $themes_list );
		$total_pages = $total > 0 ? ceil( $total / $per_page ) : 0;
		$offset      = ( $page - 1 ) * $per_page;

		// Slice array for pagination.
		$paginated_themes = array_slice( $themes_list, $offset, $per_page );

		return array(
			'success'    => true,
			'themes'     => $paginated_themes,
			'filters'    => array(
				'updates_only' => $updates_only,
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
	 * Check if a theme is installed and get its status.
	 *
	 * @param string $slug               Theme slug.
	 * @param array  $installed_themes   List of installed themes.
	 * @param string $current_theme_slug Slug of the currently active theme.
	 *
	 * @return array Installation status details.
	 */
	private static function check_theme_install_status(
		string $slug,
		array $installed_themes,
		string $current_theme_slug
	): array {
		$status = array(
			'is_installed'      => false,
			'is_active'         => false,
			'installed_version' => null,
			'status'            => 'not_installed', // Possible values: 'not_installed', 'installed', 'active'.
			'update_available'  => false,
		);

		try {
			// Check if theme is installed.
			if ( isset( $installed_themes[ $slug ] ) ) {
				$theme                       = $installed_themes[ $slug ];
				$status['is_installed']      = true;
				$status['installed_version'] = $theme->get( 'Version' );

				// Check if active.
				if ( $slug === $current_theme_slug ) {
					$status['is_active'] = true;
					$status['status']    = 'active';
				} else {
					$status['status'] = 'installed';
				}

				// Check if update is available.
				$update_themes = get_site_transient( 'update_themes' );
				if ( isset( $update_themes->response[ $slug ] ) ) {
					$status['update_available'] = true;
				}
			}
		} catch ( \Throwable $e ) {
			// Silently ignore exceptions and return default status.
			unset( $e );
		}

		return $status;
	}

	/**
	 * Check theme compatibility with current WP and PHP versions.
	 *
	 * @param \WP_Theme $theme Theme object.
	 *
	 * @return array Compatibility status.
	 */
	private static function check_compatibility( $theme ): array {
		$wp_version  = get_bloginfo( 'version' );
		$php_version = phpversion();

		// Check WP Version.
		$requires_wp = $theme->get( 'RequiresWP' );
		if ( ! empty( $requires_wp ) && version_compare( $wp_version, $requires_wp, '<' ) ) {
			return array(
				'compatible' => false,
				'reason'     => sprintf( 'Requires WordPress %s (Current: %s)', $requires_wp, $wp_version ),
			);
		}

		// Check PHP Version.
		$requires_php = $theme->get( 'RequiresPHP' );
		if ( ! empty( $requires_php ) && version_compare( $php_version, $requires_php, '<' ) ) {
			return array(
				'compatible' => false,
				'reason'     => sprintf( 'Requires PHP %s (Current: %s)', $requires_php, $php_version ),
			);
		}

		return array(
			'compatible' => true,
			'reason'     => '',
		);
	}
}
