<?php
/**
 * Tailwatch - Maintenance Mode
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Restore
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Restore;

use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the site closed while a restore rewrites it, and guarantees it opens again.
 *
 * ## Engaging is privileged, lifting is not
 *
 * Callers gate engage(); nothing here does. lift() is deliberately unauthenticated,
 * because a safety exit that can fail authorization is a safety exit that can strand a
 * site — every real caller runs inside a WP-Cron worker where get_current_user_id() is 0,
 * so a capability check there returns 403 on every teardown and leaves the site behind a
 * permanent 503. Lifting only removes files this class wrote.
 *
 * ## Four independent exit guarantees
 *
 * 1. The owning worker calls lift() on every completion, abort and exception path.
 * 2. arm_fatal_guard() lifts from a shutdown handler when the worker dies on an
 *    uncatchable fatal (OOM / max_execution_time), which no try/catch can reach.
 * 3. A watchdog cron lifts once the maintenance file goes stale.
 * 4. The generated gate SELF-HEALS: if its own mtime is older than MAINTENANCE_TTL the
 *    owning job is gone, so it restores the original .htaccess and deletes itself. This
 *    is the only layer that survives WP-Cron itself being dead, so it is the backstop
 *    for all of the above.
 *
 * heartbeat() refreshes the file's mtime each tick, so the TTL measures "time since the
 * job was last alive" rather than total runtime — a long restore never expires early.
 *
 * ## On-disk compatibility
 *
 * The filenames, backup suffix and signature are fixed and must not change: a site can be
 * mid-maintenance when the plugin updates, and the new code has to be able to lift a gate
 * the old code engaged.
 */
class MaintenanceMode {

	/**
	 * Seconds of worker silence after which maintenance mode is considered abandoned.
	 */
	const MAINTENANCE_TTL = 1800;

	/**
	 * Marker written into our generated .htaccess so the lift can tell our file apart
	 * from one the site owner replaced it with.
	 */
	const HTACCESS_SIGNATURE = '# BEGIN Tailwatch Maintenance';

	/**
	 * Suffix for the on-disk copy of the pre-maintenance .htaccess.
	 */
	const BACKUP_SUFFIX = '.tailwatch-migration-bak';

	/**
	 * Cron hook that tears down a stale maintenance mode.
	 */
	const WATCHDOG_HOOK = 'tailwatch_maintenance_watchdog';

	/**
	 * Guards against arming more than one shutdown handler per request.
	 *
	 * @var bool
	 */
	private static $fatal_guard_armed = false;

	/**
	 * Enables maintenance mode: backs up .htaccess, writes rewrite rules and a
	 * standalone maintenance PHP file.
	 *
	 * @return array Response with success status and HTTP code.
	 */
	public static function engage() {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( empty( $wp_filesystem ) ) {
			Log::error(
				'Migration start failed: WP_Filesystem unavailable.',
				array(
					'feature' => 'migration',
					'action'  => 'migration_start_failed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Filesystem is not available. Cannot enter migration mode.', 'tailwatch' ),
			);
		}

		$htaccess_path    = self::htaccess_path();
		$backup_path      = self::backup_path();
		$maintenance_file = self::maintenance_path();

		// Read current .htaccess for in-memory rollback if either write fails.
		$original_htaccess = $wp_filesystem->exists( $htaccess_path ) ? $wp_filesystem->get_contents( $htaccess_path ) : '';
		if ( false === $original_htaccess ) {
			$original_htaccess = '';
		}

		// Persist a backup of the original on disk (idempotent — don't overwrite
		// an existing backup, so re-entering migration mode doesn't clobber the
		// pre-migration .htaccess we still need to restore from).
		//
		// The backup is written even when there was NO original .htaccess, in
		// which case it is empty. That makes "a backup exists" an unambiguous
		// signal on the way out, so the lift never has to guess whether the
		// site had an .htaccess of its own.
		if ( ! $wp_filesystem->exists( $backup_path ) ) {
			if ( ! $wp_filesystem->put_contents( $backup_path, $original_htaccess, FS_CHMOD_FILE ) ) {
				Log::error(
					'Migration start failed: could not write .htaccess backup.',
					array(
						'feature' => 'migration',
						'action'  => 'migration_start_failed',
						'origin'  => 'system',
					)
				);
				return array(
					'success' => false,
					'code'    => 500,
					'message' => __( 'Failed to back up .htaccess. Cannot enter migration mode.', 'tailwatch' ),
				);
			}
		}

		$htaccess_content    = self::build_htaccess_content();
		$maintenance_content = self::build_maintenance_php();

		// Validate generated PHP syntax BEFORE writing — refuse to drop an
		// unparseable file at the WordPress root.
		if ( ! self::is_valid_php_content( $maintenance_content ) ) {
			Log::error(
				'Migration start failed: generated maintenance PHP failed syntax check.',
				array(
					'feature' => 'migration',
					'action'  => 'migration_start_failed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Generated maintenance file failed syntax validation.', 'tailwatch' ),
			);
		}

		// Write maintenance PHP first. If this fails, we have not touched
		// .htaccess yet so the site is unchanged.
		if ( false === $wp_filesystem->put_contents( $maintenance_file, $maintenance_content, FS_CHMOD_FILE ) ) {
			Log::error(
				'Migration start failed: could not write maintenance PHP file.',
				array(
					'feature' => 'migration',
					'action'  => 'migration_start_failed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to write maintenance file.', 'tailwatch' ),
			);
		}

		// Now write the .htaccess. If this fails, roll back the maintenance
		// file we just created and restore the original .htaccess (which we
		// still have in memory).
		if ( false === $wp_filesystem->put_contents( $htaccess_path, $htaccess_content, FS_CHMOD_FILE ) ) {
			if ( '' !== $original_htaccess ) {
				$wp_filesystem->put_contents( $htaccess_path, $original_htaccess, FS_CHMOD_FILE );
			}
			if ( $wp_filesystem->exists( $maintenance_file ) ) {
				$wp_filesystem->delete( $maintenance_file );
			}
			Log::error(
				'Migration start failed: could not write .htaccess; rolled back.',
				array(
					'feature' => 'migration',
					'action'  => 'migration_start_failed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to write .htaccess. Original restored.', 'tailwatch' ),
			);
		}

		// Teardown layer 3: a watchdog that lifts once the worker goes quiet.
		// Layer 4 (the file's own self-heal) covers the case where cron is dead
		// too, so this is an accelerator rather than the guarantee.
		if ( ! wp_next_scheduled( self::WATCHDOG_HOOK ) ) {
			wp_schedule_single_event( time() + self::MAINTENANCE_TTL, self::WATCHDOG_HOOK );
		}

		return array(
			'success' => true,
			'code'    => 200,
			'message' => __( 'Migration mode enabled.', 'tailwatch' ),
		);
	}

	/**
	 * The site's own path, as requests for it begin.
	 *
	 * Shared by the two layers of the gate — the .htaccess rules and the standalone PHP
	 * file they rewrite to — so both agree on where this WordPress lives. If they
	 * disagreed, a request could be turned away by one and let through by the other.
	 *
	 * @return string Trailing-slashed path, or '/'.
	 */
	private static function base_path() {
		// Defensive base_path resolution. home_url() comes from the WP `home`
		// option which an admin can set to any string via Settings -> General.
		// We refuse to interpolate anything outside a strict path allowlist
		// into the .htaccess content to avoid breaking Apache parsing.
		$home_url_parsed = wp_parse_url( home_url() );
		$candidate_path  = isset( $home_url_parsed['path'] ) && '/' !== $home_url_parsed['path']
			? trailingslashit( $home_url_parsed['path'] )
			: '/';

		return preg_match( '#^[A-Za-z0-9_\-./]+$#', $candidate_path ) ? $candidate_path : '/';
	}

	/**
	 * The REST namespaces the gate keeps open while the site is closed.
	 *
	 * This plugin's own, and whatever else asks. It does not name the pro plugin: free
	 * never references pro by name anywhere else — pro hooks free — and the gate is no
	 * exception, so the pro plugin adds its own namespace here the way it adds everything
	 * else. On a site without it the list is one entry long and nothing is missing.
	 *
	 * Each is matched in full rather than as a prefix, so another plugin whose namespace
	 * merely BEGINS with one of these — tailwatchers, say — is not let through the gate
	 * on the strength of a shared opening.
	 *
	 * @return string[]
	 */
	private static function api_namespaces() {
		$namespaces = apply_filters( 'tailwatch_maintenance_api_namespaces', array( 'tailwatch' ) );
		$out        = array();

		foreach ( (array) $namespaces as $namespace ) {
			// Tested rather than cast. Casting an array here raises a conversion notice
			// AND yields the string 'Array', which then passes the check below and is
			// written into the live rewrite rules as a namespace.
			if ( ! is_string( $namespace ) ) {
				continue;
			}

			// A filtered value is interpolated into an Apache CondPattern and into the
			// generated PHP file. Anything that is not a plain namespace is DROPPED
			// rather than escaped: a rewrite rule that fails to parse takes the whole
			// site down with a 500, and this one is written while a restore is running.
			if ( '' !== $namespace && preg_match( '#^[A-Za-z0-9_-]+$#', $namespace ) ) {
				$out[] = $namespace;
			}
		}

		// This plugin's own API drives the restore, so it is never removable.
		if ( ! in_array( 'tailwatch', $out, true ) ) {
			array_unshift( $out, 'tailwatch' );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Where this plugin's REST API begins, as a request path.
	 *
	 * Both forms are answered with, and both are needed.
	 *
	 * The base one is the site's own, for a WordPress in a subdirectory. The bare one is
	 * the root form, kept because the base is read out of the `home` option — and during
	 * a MIGRATION restore that option still describes the site this backup came FROM, so
	 * a gate anchored only on it would lock out the very API driving the restore and
	 * strand the site behind a permanent 503. Being slightly wide here costs nothing: a
	 * root-form request on a subdirectory install is one WordPress answers with a 404.
	 *
	 * @return string[] Path prefixes, each ending in the separator that must follow.
	 */
	private static function api_path_prefixes() {
		$base = self::base_path();
		$out  = array();

		foreach ( self::api_namespaces() as $namespace ) {
			$out[] = '/wp-json/' . $namespace . '/';

			if ( '/' !== $base ) {
				$out[] = $base . 'wp-json/' . $namespace . '/';
			}
		}

		return $out;
	}

	/**
	 * The .htaccess that puts the site behind the maintenance gate.
	 *
	 * Split out of engage() so the generated text can be asserted on directly.
	 * Nothing here touches the filesystem.
	 *
	 * @return string
	 */
	public static function build_htaccess_content() {
		$base_path = self::base_path();

		// Apache reads a CondPattern as a PCRE, so the base path is interpolated into one.
		// Its allowlist above admits a dot, which would otherwise match any character.
		$base_regex = str_replace( '.', '\.', $base_path );
		$api_prefix = '/' === $base_path ? '/' : '(/|' . $base_regex . ')';

		// Validated in api_namespaces(), so this is a plain alternation of plain names.
		$api_namespaces = '(' . implode( '|', self::api_namespaces() ) . ')';

		// wp-content URL path segment — derived from WP_CONTENT_DIR's basename
		// so non-default `WP_CONTENT_DIR` setups (renamed/relocated wp-content)
		// still get their content URLs allowlisted during maintenance mode.
		// wp-admin and wp-includes are not officially relocatable in WP core,
		// so those stay hardcoded.
		$content_basename = wp_basename( WP_CONTENT_DIR );
		$content_basename = preg_match( '#^[A-Za-z0-9_\-]+$#', $content_basename ) ? $content_basename : 'wp-content';

		return self::HTACCESS_SIGNATURE . "
<IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
        RewriteBase {$base_path}

        # Maintenance Mode - Block all non-essential requests
        RewriteCond %{REQUEST_URI} !/wp-admin [NC]
        RewriteCond %{REQUEST_URI} !/wp-login\.php\$ [NC]
        RewriteCond %{REQUEST_URI} !/wp-cron\.php\$ [NC]
        RewriteCond %{REQUEST_URI} !/{$content_basename} [NC]
        RewriteCond %{REQUEST_URI} !/wp-includes [NC]
        RewriteCond %{REQUEST_URI} !^/wp-admin/admin-ajax\.php\$ [NC]
        RewriteCond %{REQUEST_URI} !/tailwatch_maintenance\.php\$ [NC]
        RewriteCond %{REQUEST_URI} !^{$api_prefix}wp-json/{$api_namespaces}/ [NC]
        RewriteCond %{QUERY_STRING} !(^|&)rest_route=(/|[%]2F){$api_namespaces}(/|[%]2F|&|\$) [NC]
        RewriteCond %{REQUEST_URI} !/index\.php\$ [NC]
        RewriteRule ^.*\$ tailwatch_maintenance.php [L]

        # Allowlisted requests (the plugin's own REST API) fall past the maintenance
        # rule above; route pretty-permalink ones through WordPress instead of letting
        # Apache 404 them.
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule . index.php [L]
        </IfModule>

        <Files .htaccess>
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order Allow,Deny
                Deny from all
            </IfModule>
        </Files>
        ";
	}

	/**
	 * The standalone PHP file the gate rewrites every blocked request to.
	 *
	 * Split out of engage() for the same reason as build_htaccess_content(), and
	 * with more at stake: this string is PHP source that will run before
	 * WordPress exists, so it is validated by is_valid_php_content() before it is
	 * ever written. Being able to build it without writing it is what lets a test
	 * check the redirect guard and the baked-in translations.
	 *
	 * @return string
	 */
	public static function build_maintenance_php() {
		// The maintenance page is the only visitor-facing output either plugin
		// produces, and it renders before WordPress exists — __() is unavailable
		// there. Resolve the strings HERE, where the text domain is loaded, and
		// write them into the page's HTML, outside any PHP block. Escaped for HTML
		// they cannot open a PHP tag, and nothing in them is ever evaluated.
		$source_strings = array(
			'title' => 'Site Under Maintenance',
			'head'  => 'We will be right back!',
			'body'  => 'Our website is currently undergoing maintenance. Please check back soon.',
		);
		$page_strings   = array(
			'title' => esc_html__( 'Site Under Maintenance', 'tailwatch' ),
			'head'  => esc_html__( 'We will be right back!', 'tailwatch' ),
			'body'  => esc_html__( 'Our website is currently undergoing maintenance. Please check back soon.', 'tailwatch' ),
		);
		foreach ( $page_strings as $key => $value ) {
			// A damaged .mo file can yield invalid UTF-8, which escaping turns into an
			// empty string. The English source is better than an empty page.
			if ( '' === $value ) {
				$page_strings[ $key ] = esc_html( $source_strings[ $key ] );
			}
		}
		$page_lang = esc_attr( str_replace( '_', '-', get_locale() ) );
		if ( '' === $page_lang ) {
			$page_lang = 'en';
		}

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, PluginCheck.CodeAnalysis.EnqueuedResources -- The string below is written to a standalone maintenance PHP file that runs BEFORE/INSTEAD of WordPress during migration mode, so wp_enqueue_style() is not available - inline <style> is the only option for the visitor-facing maintenance page.
		return "<?php
        // SELF-HEAL. The owning worker refreshes this file's mtime every tick.
        // If it has gone stale the job is gone, so restore the original .htaccess
        // and delete ourselves instead of leaving the site behind a permanent 503.
        // This is the only teardown layer that survives WP-Cron itself being dead.
        \$tailwatch_ttl      = " . (int) self::MAINTENANCE_TTL . ";
        \$tailwatch_htaccess = dirname(__FILE__) . '/.htaccess';
        \$tailwatch_backup   = \$tailwatch_htaccess . '" . self::BACKUP_SUFFIX . "';

        clearstatcache(true, __FILE__);
        \$tailwatch_mtime = @filemtime(__FILE__);
        if (false !== \$tailwatch_mtime && (time() - \$tailwatch_mtime) > \$tailwatch_ttl) {
            \$tailwatch_healed = false;
            if (file_exists(\$tailwatch_backup)) {
                \$tailwatch_original = @file_get_contents(\$tailwatch_backup);
                if (false !== \$tailwatch_original) {
                    // An empty backup means the site had no .htaccess before we
                    // engaged, so removing ours is what restores the original state.
                    \$tailwatch_healed = '' === trim(\$tailwatch_original)
                        ? @unlink(\$tailwatch_htaccess)
                        : (false !== @file_put_contents(\$tailwatch_htaccess, \$tailwatch_original));
                }
                if (\$tailwatch_healed) {
                    @unlink(\$tailwatch_backup);
                }
            } elseif (file_exists(\$tailwatch_htaccess)) {
                \$tailwatch_current = @file_get_contents(\$tailwatch_htaccess);
                if (false !== \$tailwatch_current && false !== strpos(\$tailwatch_current, '" . self::HTACCESS_SIGNATURE . "')) {
                    \$tailwatch_healed = @unlink(\$tailwatch_htaccess);
                }
            }
            if (\$tailwatch_healed) {
                @unlink(__FILE__);
                // Same-origin path only. Never reflect REQUEST_URI into a Location
                // header without proving it cannot become absolute or protocol-relative.
                \$tailwatch_target = isset(\$_SERVER['REQUEST_URI']) ? \$_SERVER['REQUEST_URI'] : '/';
                // Browsers normalise a backslash to a forward slash for http(s),
                // so '/\\evil.com' is protocol-relative to them but passes a naive
                // '//' prefix test. Fold them first, then test.
                if (is_string(\$tailwatch_target)) {
                    \$tailwatch_target = str_replace('\\\\', '/', \$tailwatch_target);
                }
                if (!is_string(\$tailwatch_target) || '' === \$tailwatch_target
                    || '/' !== \$tailwatch_target[0] || 0 === strpos(\$tailwatch_target, '//')) {
                    \$tailwatch_target = '/';
                }
                header('Location: ' . \$tailwatch_target, true, 302);
                exit;
            }
        }

        // Let the namespaces above through, in both the pretty (/wp-json/...) and plain
        // (?rest_route=/...) permalink forms, so restore control and monitoring keep
        // working under maintenance. They are baked in at the moment the gate is built,
        // because this file runs before WordPress and cannot ask for them itself.
        \$request_uri = isset(\$_SERVER['REQUEST_URI']) ? (string) \$_SERVER['REQUEST_URI'] : '';
        \$rest_route = isset(\$_GET['rest_route']) && is_string(\$_GET['rest_route']) ? \$_GET['rest_route'] : '';
        \$is_api_request = false;

        // PHP's REQUEST_URI carries the query string; Apache's %{REQUEST_URI} does not.
        // Without cutting it off, any URL with the API path sitting in a PARAMETER reads
        // as a request for the API — and is handed to WordPress mid-restore, which is the
        // one thing this gate exists to prevent.
        \$tailwatch_query_at = strpos(\$request_uri, '?');
        \$tailwatch_path = false === \$tailwatch_query_at ? \$request_uri : substr(\$request_uri, 0, \$tailwatch_query_at);

        foreach (" . self::export_php_array( self::api_path_prefixes() ) . " as \$tailwatch_prefix) {
            if (0 === strpos(\$tailwatch_path, \$tailwatch_prefix)) {
                \$is_api_request = true;
                break;
            }
        }

        // \$_GET is already decoded, so ?rest_route=%2Ftailwatch%2F... is recognised too.
        // Matched to the end of the namespace, so another plugin's route cannot get in on
        // a shared opening — the same reason the pretty form lists its namespaces in full.
        foreach (" . self::export_php_array( self::rest_route_prefixes() ) . " as \$tailwatch_namespace) {
            if (0 === strpos(\$rest_route, \$tailwatch_namespace)) {
                \$is_api_request = true;
                break;
            }
        }

        // Hand an API request to WordPress to answer. wp-blog-header.php runs the request;
        // wp-load.php alone would start WordPress and send back an empty response.
        if (\$is_api_request) {
            if (file_exists(dirname(__FILE__) . '/wp-blog-header.php')) {
                if (!defined('WP_USE_THEMES')) {
                    define('WP_USE_THEMES', false);
                }
                require dirname(__FILE__) . '/wp-blog-header.php';
            }
            exit;
        }

        // Not an API request, show maintenance page
        http_response_code(503);
        header('Retry-After: 3600');
        ?>
        <!DOCTYPE html>
        <html lang='" . $page_lang . "'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>" . $page_strings['title'] . "</title>
            <style>
                body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; background: #f9f9f9; }
                .container { text-align: center; }
                h1 { color: #555; }
            </style>
        </head>
        <body>
            <div class='container'>
                <h1>" . $page_strings['head'] . "</h1>
                <p>" . $page_strings['body'] . "</p>
            </div>
        </body>
        </html>";
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, PluginCheck.CodeAnalysis.EnqueuedResources
	}

	/**
	 * The same namespaces, as a plain-permalink route begins.
	 *
	 * @return string[]
	 */
	private static function rest_route_prefixes() {
		$out = array();

		foreach ( self::api_namespaces() as $namespace ) {
			$out[] = '/' . $namespace . '/';
		}

		return $out;
	}

	/**
	 * Render a list of strings as PHP source for the generated file.
	 *
	 * The values are this class's own, but they are built from the `home` option, so they
	 * are quoted rather than concatenated in: a stray quote in a hand-edited option would
	 * otherwise end the literal and break the one file visitors can still reach.
	 *
	 * @param string[] $values Values to render.
	 * @return string
	 */
	private static function export_php_array( array $values ) {
		$parts = array();

		foreach ( $values as $value ) {
			$parts[] = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
		}

		return 'array(' . implode( ', ', $parts ) . ')';
	}

	/**
	 * Disables maintenance mode: validates the backup is readable BEFORE removing
	 * the current .htaccess, then atomically swaps content and recreates API rules.
	 *
	 * @return array Response with success status and HTTP code.
	 */
	public static function lift() {
		// Deliberately NOT authorization-gated. Every caller runs inside a
		// WP-Cron worker where get_current_user_id() is 0, so a capability check
		// here returns 403 on every real teardown and strands the site behind a
		// permanent 503. Lifting only removes files this class wrote, and the
		// failure mode of lifting too eagerly (a site briefly reachable) is far
		// cheaper than the failure mode of not lifting at all.
		$htaccess_path    = self::htaccess_path();
		$backup_path      = self::backup_path();
		$maintenance_file = self::maintenance_path();

		wp_clear_scheduled_hook( self::WATCHDOG_HOOK );

		// WP_Filesystem is preferred but must not be a prerequisite: it can be
		// unavailable in a cron context, and a safety exit that depends on an
		// optional subsystem is not a safety exit. Fall back to direct calls.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) && function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$restored = self::restore_original_htaccess( $htaccess_path, $backup_path );

		// The maintenance file goes regardless: with the rewrite gone it is inert,
		// and leaving it behind would let a stale copy answer requests.
		$maintenance_removed = true;
		if ( file_exists( $maintenance_file ) ) {
			$maintenance_removed = self::delete_path( $maintenance_file );
		}

		if ( ! $restored || ! $maintenance_removed ) {
			Log::error(
				'Migration stop: teardown incomplete; the maintenance file self-heal will retry.',
				array(
					'feature'             => 'migration',
					'action'              => 'migration_stop_partial',
					'origin'              => 'system',
					'htaccess_restored'   => $restored,
					'maintenance_removed' => $maintenance_removed,
				)
			);
			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Could not fully exit migration mode. The site will recover automatically.', 'tailwatch' ),
			);
		}

		return array(
			'success' => true,
			'code'    => 200,
			'message' => __( 'Migration mode disabled.', 'tailwatch' ),
		);
	}

	/**
	 * Put the .htaccess back the way it was before maintenance mode engaged.
	 *
	 * Handles all four on-disk states rather than only the happy one:
	 *
	 * - backup present and non-empty -> write it back
	 * - backup present and empty     -> the site had no .htaccess, so delete ours
	 * - backup missing/unreadable    -> delete ours only if it carries our
	 *                                   signature, otherwise leave it alone
	 * - our .htaccess already gone   -> nothing to do
	 *
	 * The signature check is what stops us clobbering an .htaccess the site owner
	 * replaced while maintenance mode was engaged.
	 *
	 * @param string $htaccess_path Absolute path to the live .htaccess.
	 * @param string $backup_path   Absolute path to our backup copy.
	 * @return bool True when the live .htaccess no longer contains our rules.
	 */
	private static function restore_original_htaccess( $htaccess_path, $backup_path ) {
		$backup_content = false;
		if ( file_exists( $backup_path ) ) {
			$backup_content = self::read_path( $backup_path );
		}

		if ( is_string( $backup_content ) ) {
			$ok = '' === trim( $backup_content )
				? ( ! file_exists( $htaccess_path ) || self::delete_path( $htaccess_path ) )
				: self::write_path( $htaccess_path, $backup_content );

			if ( $ok ) {
				self::delete_path( $backup_path );
				return true;
			}
			return false;
		}

		// No usable backup. Only remove the file if we can prove it is ours.
		if ( ! file_exists( $htaccess_path ) ) {
			return true;
		}
		$current = self::read_path( $htaccess_path );
		if ( is_string( $current ) && false !== strpos( $current, self::HTACCESS_SIGNATURE ) ) {
			return self::delete_path( $htaccess_path );
		}

		// Someone else's .htaccess is in place; ours is already gone.
		return is_string( $current ) ? true : false;
	}

	/**
	 * Refresh the maintenance file's mtime so the TTL tracks worker liveness.
	 *
	 * Called once per worker tick. Uses touch() rather than rewriting the file so
	 * a heartbeat can never truncate or corrupt the gate it is keeping alive.
	 *
	 * @return void
	 */
	public static function heartbeat() {
		$maintenance_file = self::maintenance_path();
		if ( file_exists( $maintenance_file ) ) {
			@touch( $maintenance_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem has no mtime-refresh primitive; a failed heartbeat must not interrupt the job, the TTL simply expires sooner.
		}
	}

	/**
	 * Watchdog: tear down a maintenance mode whose worker has gone quiet.
	 *
	 * @return void
	 */
	public static function watchdog_run() {
		$maintenance_file = self::maintenance_path();
		if ( ! file_exists( $maintenance_file ) ) {
			return;
		}

		clearstatcache( true, $maintenance_file );
		$mtime = filemtime( $maintenance_file );
		if ( false !== $mtime && ( time() - $mtime ) <= self::MAINTENANCE_TTL ) {
			// Still alive — re-arm and let it work.
			wp_schedule_single_event( time() + self::MAINTENANCE_TTL, self::WATCHDOG_HOOK );
			return;
		}

		Log::error(
			'Migration watchdog: maintenance mode went stale; forcing teardown.',
			array(
				'feature' => 'migration',
				'action'  => 'migration_watchdog_teardown',
				'origin'  => 'system',
			)
		);
		self::lift();
	}

	/**
	 * Arm a shutdown handler that lifts maintenance mode if the worker dies on an
	 * uncatchable fatal (OOM / max_execution_time), which no try/catch can reach.
	 *
	 * @return void
	 */
	public static function arm_fatal_guard() {
		if ( self::$fatal_guard_armed ) {
			return;
		}
		self::$fatal_guard_armed = true;

		register_shutdown_function(
			static function () {
				$error = error_get_last();
				if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}
				if ( ! file_exists( self::maintenance_path() ) ) {
					return;
				}
				self::lift();
			}
		);
	}

	/**
	 * Whether maintenance mode is currently engaged.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return file_exists( self::maintenance_path() );
	}

	/**
	 * Absolute path to the live .htaccess.
	 *
	 * @return string
	 */
	private static function htaccess_path() {
		return ABSPATH . '.htaccess';
	}

	/**
	 * Absolute path to our pre-maintenance .htaccess backup.
	 *
	 * @return string
	 */
	private static function backup_path() {
		return self::htaccess_path() . self::BACKUP_SUFFIX;
	}

	/**
	 * Absolute path to the generated maintenance gate.
	 *
	 * @return string
	 */
	private static function maintenance_path() {
		return ABSPATH . 'tailwatch_maintenance.php';
	}

	/**
	 * Read a file, preferring WP_Filesystem and falling back to a direct read.
	 *
	 * @param string $path Absolute path.
	 * @return string|false Contents, or false on failure.
	 */
	private static function read_path( $path ) {
		global $wp_filesystem;
		if ( ! empty( $wp_filesystem ) ) {
			return $wp_filesystem->get_contents( $path );
		}
		return @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- safety-exit fallback when WP_Filesystem is unavailable in cron; failure is handled by the caller.
	}

	/**
	 * Write a file, preferring WP_Filesystem and falling back to a direct write.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content Contents to write.
	 * @return bool
	 */
	private static function write_path( $path, $content ) {
		global $wp_filesystem;
		if ( ! empty( $wp_filesystem ) ) {
			return (bool) $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
		}
		return false !== @file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- safety-exit fallback when WP_Filesystem is unavailable in cron; failure is handled by the caller.
	}

	/**
	 * Delete a file, preferring WP_Filesystem and falling back to a direct unlink.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function delete_path( $path ) {
		if ( ! file_exists( $path ) ) {
			return true;
		}
		global $wp_filesystem;
		if ( ! empty( $wp_filesystem ) ) {
			return (bool) $wp_filesystem->delete( $path );
		}
		return @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- safety-exit fallback when WP_Filesystem is unavailable in cron; failure is handled by the caller.
	}

	/**
	 * Validate that a string is parseable PHP via token_get_all() with TOKEN_PARSE.
	 *
	 * Also implemented in FilesPermissionController and ChangePrefixController; keep the
	 * three in step.
	 *
	 * @param string $content Source code (opening tag added if missing).
	 * @return bool True if syntax is valid, false otherwise.
	 */
	private static function is_valid_php_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}
		if ( 0 !== strpos( ltrim( $content ), '<?php' ) && 0 !== strpos( ltrim( $content ), '<?' ) ) {
			$content = '<?php ' . $content;
		}
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- token_get_all may emit warnings; ParseError caught below.
			@token_get_all( $content, TOKEN_PARSE );
			return true;
		} catch ( \ParseError $e ) {
			return false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
