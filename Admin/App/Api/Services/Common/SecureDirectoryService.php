<?php
/**
 * Secure Directory Service
 *
 * Creates private plugin directories under wp-content/ with automatic
 * .htaccess + index.php + web.config deny rules. Centralizes the
 * "create + protect" pattern so call sites can't forget to drop deny files.
 *
 * @package    Tailwatch
 * @subpackage Services/Common
 */

namespace Tailwatch\Admin\App\Api\Services\Common;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SecureDirectoryService
 *
 * Shared service for creating private directories with web-access denial.
 * Used by both free and pro plugins (pro consumes via class_alias).
 *
 * Defense layers dropped into each protected directory:
 *
 * - .htaccess  — Apache 2.4 (Require all denied) + Apache 2.2 fallback
 *                (Order allow,deny / Deny from all) + Options -Indexes.
 *                Works on Apache regardless of version, ignored on nginx/IIS.
 * - index.php  — Server-agnostic directory-listing defense. Stops the
 *                "index of /" page on Apache/nginx/IIS when directory
 *                browsing is otherwise enabled.
 * - web.config — IIS authorization deny-all. Works on IIS, ignored elsewhere.
 *
 */
class SecureDirectoryService {

	/**
	 * Marker string in .htaccess used by is_protected() to verify content
	 * (not just existence). Bumping this also lets us detect older deny files
	 * written by previous service versions if we ever need to migrate.
	 */
	private const HTACCESS_MARKER = '# WPTW-SecureDirectory v1';

	/**
	 * Marker string in index.php used by is_protected() to verify content.
	 */
	private const INDEX_MARKER = '// WPTW-SecureDirectory v1';

	/**
	 * Marker string in web.config used by is_protected() to verify content.
	 */
	private const WEBCONFIG_MARKER = '<!-- WPTW-SecureDirectory v1 -->';

	/**
	 * .htaccess body. Combines Apache 2.4 (Require all denied), Apache 2.2
	 * (Order allow,deny / Deny from all), and Options -Indexes for the case
	 * where authz directives are ignored but Indexes can still be turned off.
	 */
	private const HTACCESS_CONTENT = "# WPTW-SecureDirectory v1 — do not edit; managed by the plugin.\nOptions -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	/**
	 * index.php body. Server-agnostic — content doesn't matter beyond
	 * preventing a directory-listing "index of /" page.
	 */
	private const INDEX_CONTENT = "<?php\n// WPTW-SecureDirectory v1 — Silence is golden.\n";

	/**
	 * web.config body. IIS authorization deny-all.
	 */
	private const WEBCONFIG_CONTENT = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- WPTW-SecureDirectory v1 -->\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

	/**
	 * Create directory if missing.
	 *
	 * For the ROOT of a private directory tree (e.g. tailwatch/wptw-backup/
	 * under wp-content), pass $drop_deny_files = true to also drop .htaccess +
	 * index.php + web.config.
	 *
	 * For SUBDIRECTORIES under an already-protected root (e.g.
	 * tailwatch/wptw-backup/files/temporary_xxx/), leave $drop_deny_files
	 * as the default false — the ancestor .htaccess already covers them under
	 * Apache, and adding per-subdir deny files just clutters the tree.
	 *
	 * Idempotent: safe to call repeatedly on the same path.
	 * Path MUST be inside wp-content or the uploads directory — otherwise rejected.
	 *
	 * @param string $path             Absolute filesystem path to the directory.
	 * @param bool   $drop_deny_files  True only at the root of a private tree.
	 *
	 * @return bool True on success, false on validation failure or mkdir error.
	 */
	public static function ensure( $path, $drop_deny_files = false ) {
		if ( ! self::is_valid_path( $path ) ) {
			return false;
		}

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return false;
		}

		// Re-validate AFTER mkdir using realpath, in case the path or any
		// ancestor was a symlink that escaped the allowed storage roots.
		if ( ! self::realpath_inside_allowed_bases( $path ) ) {
			return false;
		}

		if ( $drop_deny_files ) {
			self::drop_deny_files( $path );
		}
		return true;
	}

	/**
	 * Shortcut for ensure( $path, true ).
	 *
	 * @param string $path Absolute filesystem path to the root directory.
	 *
	 * @return bool True on success.
	 */
	public static function ensure_private_root( $path ) {
		return self::ensure( $path, true );
	}

	/**
	 * Drop deny files into an EXISTING directory.
	 *
	 * Used to retro-protect directories created before the service existed.
	 * No-op if the directory doesn't exist (returns false).
	 *
	 * @param string $path Absolute filesystem path to the directory.
	 *
	 * @return bool True if directory exists and protection was attempted.
	 */
	public static function protect_existing( $path ) {
		if ( ! self::is_valid_path( $path ) || ! is_dir( $path ) ) {
			return false;
		}
		if ( ! self::realpath_inside_allowed_bases( $path ) ) {
			return false;
		}
		self::drop_deny_files( $path );
		return true;
	}

	/**
	 * Check whether all deny files exist AND contain the service's marker.
	 *
	 * Marker check (not just file_exists) catches the case where the deny
	 * file was overwritten with empty/invalid content — e.g. by a different
	 * plugin or a partial restore. is_protected() returns false in that
	 * case, allowing a follow-up protect_existing() to repair it.
	 *
	 * Note: this does NOT verify file content beyond the marker. An attacker
	 * who can write to the directory can craft a file with the marker but
	 * different deny semantics. That's an out-of-scope filesystem-level
	 * concern.
	 *
	 * @param string $path Absolute filesystem path to the directory.
	 *
	 * @return bool True if all three deny files are present and valid.
	 */
	public static function is_protected( $path ) {
		if ( ! is_string( $path ) || ! is_dir( $path ) ) {
			return false;
		}

		$dir = trailingslashit( $path );
		return self::has_marker( $dir . '.htaccess', self::HTACCESS_MARKER )
			&& self::has_marker( $dir . 'index.php', self::INDEX_MARKER )
			&& self::has_marker( $dir . 'web.config', self::WEBCONFIG_MARKER );
	}

	/**
	 * Validate that a path is safe to operate on (string-level checks).
	 *
	 * Rejects:
	 * - Empty or non-string paths.
	 * - Paths containing ".." (traversal).
	 * - Paths containing null bytes (truncation attacks).
	 * - Paths outside the allowed storage roots (string prefix match).
	 *
	 * Symlink resolution is handled separately by realpath_inside_allowed_bases()
	 * which runs AFTER mkdir, because realpath() returns false for paths
	 * that don't exist yet.
	 *
	 * @param string $path Absolute filesystem path.
	 *
	 * @return bool True if the path passes string-level validation.
	 */
	private static function is_valid_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		$normalized = wp_normalize_path( $path );
		foreach ( self::allowed_bases() as $base ) {
			if ( $normalized === $base || strpos( $normalized, $base . '/' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filesystem roots a private directory may live under: the wp-content
	 * directory and the uploads base directory. The uploads base is resolved at
	 * call time via wp_get_upload_dir() so custom upload locations are honored.
	 *
	 * @return string[] Normalized absolute base paths (no trailing slash).
	 */
	private static function allowed_bases() {
		$bases   = array( wp_normalize_path( WP_CONTENT_DIR ) );
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			$bases[] = wp_normalize_path( $uploads['basedir'] );
		}
		return $bases;
	}

	/**
	 * Verify the realpath of an existing directory resolves inside one of the
	 * allowed storage roots. This catches symlinks that escape those roots —
	 * something string-level validation cannot detect.
	 *
	 * Only callable AFTER the directory exists. realpath() returns false
	 * for paths that don't exist, so this is invoked post-mkdir.
	 *
	 * @param string $path Absolute filesystem path to an existing directory.
	 *
	 * @return bool True if realpath($path) resolves inside an allowed root.
	 */
	private static function realpath_inside_allowed_bases( $path ) {
		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}
		$real = wp_normalize_path( $real );

		foreach ( self::allowed_bases() as $base ) {
			$real_base = wp_normalize_path( realpath( $base ) ? realpath( $base ) : $base );
			if ( $real === $real_base || strpos( $real, $real_base . '/' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a file exists AND its content contains the given marker.
	 *
	 * Read is capped at 8 KB — deny files are small (~200 bytes); reading
	 * more wastes memory and opens DoS via huge file injection.
	 *
	 * ## Why this method uses native file_get_contents instead of WP_Filesystem
	 *
	 * Deny-file marker checks run during early plugin bootstrap and during
	 * cron tasks, both of which can execute before WP_Filesystem is fully
	 * initialized (admin-only includes/file.php may not be loaded). Falling
	 * back to native file_get_contents lets this defense-in-depth check run
	 * in every context, including contexts where instantiating WP_Filesystem
	 * would fail. We additionally cap the read at 8 KB with the $offset/
	 * $maxlen parameters — a capability WP_Filesystem::get_contents does not
	 * expose — to prevent a planted multi-MB "deny file" from being slurped
	 * into PHP memory. Errors are silenced with `@` because is_readable()
	 * already gates the call and a transient race (file deleted between
	 * is_readable and read) is non-fatal — we treat it as marker-absent.
	 *
	 * @param string $file   Absolute filesystem path.
	 * @param string $marker Substring expected in the file content.
	 *
	 * @return bool True if the file exists and contains the marker.
	 */
	private static function has_marker( $file, $marker ) {
		if ( ! is_readable( $file ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a known plugin-owned guard file; WP_Filesystem adds no value here. Silenced because is_readable() already gates this and a transient race (file deleted between is_readable and read) is non-fatal — we just treat it as marker-absent.
		$head = @file_get_contents( $file, false, null, 0, 8192 );
		if ( false === $head ) {
			return false;
		}
		return false !== strpos( $head, $marker );
	}

	/**
	 * Drop .htaccess + index.php + web.config into the directory if missing.
	 *
	 * Best-effort: failures (e.g. permissions) are silenced because the deny
	 * files are defense-in-depth, not strictly required for the caller's
	 * primary operation to succeed.
	 *
	 * Existing deny files are PRESERVED — we never overwrite. This protects
	 * any custom deny rules an admin may have placed and keeps the operation
	 * truly idempotent.
	 *
	 * @param string $path Absolute filesystem path to the directory.
	 *
	 * @return void
	 */
	private static function drop_deny_files( $path ) {
		$dir = trailingslashit( $path );

		$files = array(
			'.htaccess'  => self::HTACCESS_CONTENT,
			'index.php'  => self::INDEX_CONTENT,
			'web.config' => self::WEBCONFIG_CONTENT,
		);

		foreach ( $files as $name => $content ) {
			$file = $dir . $name;
			if ( ! file_exists( $file ) ) {
				self::write_deny_file( $file, $content );
			}
		}
	}

	/**
	 * Write a deny file using WP_Filesystem when available, with a raw
	 * file_put_contents fallback for early-bootstrap / cron contexts where
	 * WP_Filesystem may not be initialized.
	 *
	 * Prefer the filesystem abstraction, fall back to raw I/O so the write
	 * still has a chance to succeed.
	 *
	 * ## Why the raw file_put_contents fallback is necessary
	 *
	 * WP_Filesystem requires admin-area includes (wp-admin/includes/file.php)
	 * to be loaded before it can be instantiated, and even then can fail
	 * silently on hosts where the configured filesystem method (Direct, FTP,
	 * SSH2) is not actually available. Deny-file writes are defense-in-depth
	 * — they harden plugin-owned upload/log directories against direct
	 * browser access — and skipping them when WP_Filesystem is unavailable
	 * (e.g., very early plugin activation, certain WP-CLI contexts, or
	 * misconfigured FTP credentials) would silently weaken security. The
	 * primary path uses WP_Filesystem; only when it returns false do we
	 * fall through to native file_put_contents so the deny file still has
	 * a chance to be written. The `@` silences a non-fatal write failure
	 * (return value is checked explicitly below).
	 *
	 * @param string $file    Absolute filesystem path to write.
	 * @param string $content File content.
	 *
	 * @return bool True if the file was successfully written.
	 */
	private static function write_deny_file( $file, $content ) {
		$fs = FilesystemService::get_filesystem();
		if ( $fs ) {
			$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			if ( $fs->put_contents( $file, $content, $mode ) ) {
				return true;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort fallback when WP_Filesystem is unavailable; deny files are defense-in-depth; return value is checked explicitly.
		return false !== @file_put_contents( $file, $content );
	}
}
