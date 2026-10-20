<?php
/**
 * Tailwatch - Post-Restore Guard
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
 * Keeps the owner able to reach the site a restore just handed back to them.
 *
 * A restore replaces `wp_users`, `wp_usermeta` and `active_plugins` wholesale, so the
 * person who clicked the button is signed out and lands on whatever login the RESTORED
 * database describes. Two ways that ends badly, and neither is theoretical:
 *
 * 1. A login-hiding, captcha or SSL-forcing plugin comes back configured for the site as
 *    it was. If its slug has moved on, its captcha keys are domain-locked, or the site
 *    no longer serves TLS, the owner cannot reach wp-login.php at all — and the plugin
 *    locking them out is the one they would have to log in to change.
 * 2. An mu-plugin in the restored tree fatals. WordPress core's recovery mode (5.2+)
 *    pauses plugins and themes; it does NOT cover mu-plugins, because `wp-settings.php`
 *    `include_once`s them unconditionally before the machinery that would pause them
 *    exists. A fatal there is a white screen with no route back in.
 *
 * The deactivation list is product knowledge rather than architecture: the plugins known
 * to strand an owner after a site moves.
 *
 * The mu-plugin guard RENAMES rather than deletes, so the owner keeps their code; and it only acts on a failure that
 * happened while mu-plugins were still loading, so an out-of-memory kill on some heavy
 * page later in the request cannot make it rename a healthy host mu-plugin.
 *
 * This is a backstop, not the primary defence. Not restoring another host's mu-plugins
 * in the first place is cheaper and non-destructive — see RestoreExtractor's foreign
 * mu-plugin exclusions.
 */
class PostRestoreGuard {

	/**
	 * Filename for the guard mu-plugin.
	 *
	 * The name is a sort key, not a label. `wp_get_mu_plugins()` collects `*.php` and
	 * calls plain `sort()` (wp-includes/load.php), so load order is byte order on the
	 * filename and the guard has to sort ahead of whatever might fatal.
	 *
	 * `0-0-` sorts ahead of the real-world neighbour that matters: a SQLite database
	 * loader commonly installed as `0-sqlite.php`, which a plain `00-` prefix loses to.
	 * It does NOT beat a name starting `!`, `#`, `-` or `.`; those sort lower still.
	 * That residue is accepted rather than chased — a guard that loses the race
	 * degrades to doing nothing, which is no worse than having no guard at all.
	 */
	const MU_GUARD_FILE = '0-0-tailwatch-restore-guard.php';

	/**
	 * Marker the guard writes when it has had to disable something.
	 *
	 * A file rather than an option: the guard runs during a fatal, at a point where
	 * `$wpdb` exists but the options cache and much of core do not, so a database write
	 * is not safe to attempt.
	 */
	const INCIDENT_FILE = '0-0-tailwatch-restore-guard.incident.json';

	/**
	 * Suffix given to an mu-plugin the guard had to disable.
	 *
	 * WordPress only loads files ending `.php` from the mu-plugins directory, so renaming
	 * is enough to take one out of the boot path while leaving it intact on disk.
	 */
	const DISABLED_SUFFIX = '.tailwatch-disabled';

	/**
	 * How long the guard stays armed after its restore's last tick, in seconds.
	 *
	 * A shutdown handler that renames files has no business living forever. It covers the
	 * boots while a restore runs and right after it, judges its own age from its mtime,
	 * which every tick refreshes, and retires itself — which also means a restore that
	 * dies hard still cleans this up.
	 */
	const GUARD_TTL = 1800;

	/**
	 * Plugins that can lock an administrator out of a site they just restored.
	 *
	 * Login-hiding, admin-renaming, login captcha (keys are domain-locked, so a captcha
	 * that worked on the source host silently rejects every login on the target) and
	 * multisite/domain-mapping plugins. Nothing here is deactivated unless the RESTORED
	 * database says it was active, and every deactivation is logged and surfaced.
	 *
	 * @var array<string>
	 */
	const LOCKOUT_SLUGS = array(
		// Login hiding / admin renaming.
		'wps-hide-login',
		'hide-my-wp',
		'hide-my-wp-ghost',
		'hide-my-wordpress',
		'rename-wp-login',
		'change-wp-admin-login',
		'hide-login-page',
		'lockdown-wp-admin',
		'wp-hide-security-enhancer',
		'wp-hide-security-enhancer-pro',
		'better-wp-security',
		'ithemes-security-pro',
		'wp-simple-firewall',
		// Login captchas: the site keys are issued per domain.
		'invisible-recaptcha',
		'google-captcha',
		'login-recaptcha',
		'advanced-google-recaptcha',
		'simple-google-recaptcha',
		// Multisite / domain mapping.
		'wordpress-mu-domain-mapping',
		'join-my-multisite',
		'multisite-clone-duplicator',
		'pro-sites',
		'wordpress-starter',
	);

	/**
	 * Plugins that force HTTPS, which strand a site no longer serving it.
	 *
	 * Only acted on when the address this site is served from is plain http. Deliberately
	 * excludes mixed-content rewriters: those rewrite asset URLs, they do not redirect, so
	 * on a non-TLS site they are inert and deactivating them buys nothing.
	 *
	 * @var array<string>
	 */
	const SSL_SLUGS = array(
		'really-simple-ssl',
		'wp-force-ssl',
		'https-redirection',
		'wordpress-https',
		'force-https-littlebizzy',
		'one-click-ssl',
	);

	/**
	 * Drop the plugins that would lock the owner out of the restored site.
	 *
	 * Operates on the list rather than calling `deactivate_plugins()`: this runs in the
	 * same request as the database cutover, where the plugin API's own notion of what is
	 * active was invalidated by the rename. Returning the filtered list lets the caller
	 * write it once alongside its own changes.
	 *
	 * @param array<string> $active Plugin basenames from the restored active_plugins.
	 * @return array{0: array<string>, 1: array<string>} Filtered list, and what was removed.
	 */
	public static function filter_lockout_plugins( array $active ) {
		$slugs = self::deactivation_slugs( $active );
		if ( empty( $slugs ) ) {
			return array( $active, array() );
		}

		$kept    = array();
		$dropped = array();

		foreach ( $active as $basename ) {
			if ( ! is_string( $basename ) || '' === $basename ) {
				continue;
			}
			if ( in_array( self::slug_of( $basename ), $slugs, true ) ) {
				$dropped[] = $basename;
				continue;
			}
			$kept[] = $basename;
		}

		if ( ! empty( $dropped ) ) {
			Log::warning(
				'Restore deactivated plugins that could lock the administrator out: ' . implode( ', ', $dropped ),
				array(
					'feature' => 'restore',
					'action'  => 'restore_deactivated_lockout_plugins',
					'origin'  => 'system',
				)
			);
		}

		return array( array_values( $kept ), $dropped );
	}

	/**
	 * Do the same for network-activated plugins, and for options that strand a site.
	 *
	 * Network activation lives in the `active_sitewide_plugins` SITE option, keyed by
	 * basename — a different shape and a different store from `active_plugins`. Without
	 * this, the entries here that are network-only by nature (domain mapping above all)
	 * would be listed and never actually matched.
	 *
	 * @return array<string> What was deactivated or reset, for reporting.
	 */
	public static function harden_network_and_options() {
		$changed = array();

		if ( is_multisite() ) {
			$sitewide = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $sitewide ) && ! empty( $sitewide ) ) {
				$slugs = self::deactivation_slugs( array_keys( $sitewide ) );
				$kept  = array();
				foreach ( $sitewide as $basename => $activated_at ) {
					if ( in_array( self::slug_of( (string) $basename ), $slugs, true ) ) {
						$changed[] = (string) $basename;
						continue;
					}
					$kept[ $basename ] = $activated_at;
				}
				if ( ! empty( $changed ) ) {
					update_site_option( 'active_sitewide_plugins', $kept );
				}
			}
		}

		// A store's checkout can be forced onto https by an option, not a plugin. A
		// store restored from an https-era backup onto a site without TLS redirects its
		// own checkout to a scheme it cannot serve.
		if ( ! self::restored_site_is_https() && 'yes' === get_option( 'woocommerce_force_ssl_checkout' ) ) {
			update_option( 'woocommerce_force_ssl_checkout', 'no' );
			$changed[] = 'woocommerce_force_ssl_checkout';
		}

		if ( ! empty( $changed ) ) {
			Log::warning(
				'Restore relaxed network/SSL settings that would have stranded the site: ' . implode( ', ', $changed ),
				array(
					'feature' => 'restore',
					'action'  => 'restore_hardened_network_options',
					'origin'  => 'system',
				)
			);
		}

		return $changed;
	}

	/**
	 * The slug list in force for this restore.
	 *
	 * @param array<string> $active Context for the filter.
	 * @return array<string>
	 */
	private static function deactivation_slugs( array $active ) {
		$slugs = self::LOCKOUT_SLUGS;

		if ( ! self::restored_site_is_https() ) {
			$slugs = array_merge( $slugs, self::SSL_SLUGS );
		}

		/**
		 * Filter the plugin slugs deactivated after a restore.
		 *
		 * Return an empty array to disable this behaviour entirely. Slugs are matched
		 * against the DIRECTORY part of a plugin basename, so 'wps-hide-login' covers
		 * 'wps-hide-login/anything.php' — which matters, because several of these ship a
		 * main file whose name does not match its directory.
		 *
		 * @param array<string> $slugs  Plugin directory slugs.
		 * @param array<string> $active The restored active plugin list.
		 */
		$slugs = apply_filters( 'tailwatch_restore_deactivate_plugins', $slugs, $active );

		return is_array( $slugs ) ? array_map( 'strval', $slugs ) : array();
	}

	/**
	 * Directory slug of a plugin basename.
	 *
	 * Matching on the directory rather than the whole basename is deliberate: a plugin
	 * can rename its main file (for example `hide-my-wp/index.php` rather than
	 * `hide-my-wp/hide-my-wp.php`) and an exact-basename match would miss it.
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	private static function slug_of( $basename ) {
		$parts = explode( '/', $basename );
		return ( count( $parts ) > 1 ) ? $parts[0] : wp_basename( $basename, '.php' );
	}

	/**
	 * Whether the address this site is served from is https.
	 *
	 * Reads the stored `home` (or `siteurl`). By the time this runs the restore has written
	 * the addresses it recorded at the start back over the backup's, so this is the address
	 * the site is served from, not the one recorded in the backup.
	 *
	 * Deliberately not `is_ssl()`. That reads `$_SERVER['HTTPS']` and `SERVER_PORT`,
	 * which say nothing useful here: this runs in a WP-Cron worker, and under a system
	 * cron or the command line there is no request at all — so `is_ssl()` reports false on a
	 * perfectly healthy TLS site and we would deactivate its SSL plugin. Behind a
	 * TLS-terminating proxy it is false even in a browser.
	 *
	 * The address the site is served from is what actually predicts a redirect loop.
	 *
	 * @return bool
	 */
	private static function restored_site_is_https() {
		$home = get_option( 'home' );
		if ( ! is_string( $home ) || '' === $home ) {
			$home = get_option( 'siteurl' );
		}
		if ( ! is_string( $home ) || '' === $home ) {
			// Nothing to judge from. Assume https so nothing is deactivated on a guess.
			return true;
		}

		return 0 === stripos( $home, 'https://' );
	}

	/**
	 * Write the mu-plugin that survives a fatal in the restored mu-plugins tree.
	 *
	 * Failure is not propagated. The guard covers a case that may never happen; a site
	 * whose mu-plugins directory cannot be written is not a site whose restore should
	 * fail over it.
	 *
	 * @return bool Whether the guard is now in place.
	 */
	public static function install_mu_guard() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return false;
		}

		$dir = WPMU_PLUGIN_DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$path    = trailingslashit( $dir ) . self::MU_GUARD_FILE;
		$content = self::guard_source();

		// Never drop an unparseable file into a directory core include_once's
		// unconditionally — that would CAUSE the outage this exists to prevent.
		if ( ! self::is_valid_php( $content ) ) {
			Log::error(
				'Restore guard not installed: generated mu-plugin failed its syntax check.',
				array(
					'feature' => 'restore',
					'action'  => 'restore_guard_invalid',
					'origin'  => 'system',
				)
			);
			return false;
		}

		$written = self::write_file( $path, $content );
		if ( ! $written ) {
			Log::warning(
				'Restore guard could not be written; a fatal in a restored mu-plugin will not self-heal.',
				array(
					'feature' => 'restore',
					'action'  => 'restore_guard_write_failed',
					'origin'  => 'system',
				)
			);
		}

		return $written;
	}

	/**
	 * Remove the guard once the restore has finished, either way.
	 *
	 * @return bool
	 */
	public static function remove_mu_guard() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return false;
		}

		$path = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_GUARD_FILE;
		if ( ! file_exists( $path ) ) {
			return true;
		}

		return self::delete_file( $path );
	}

	/**
	 * Refresh the guard's mtime so its TTL tracks the restore's liveness.
	 *
	 * Called once per worker tick, beside the maintenance heartbeat. Touches an existing
	 * guard only, so one a restore has removed is never brought back.
	 *
	 * @return void
	 */
	public static function heartbeat() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}

		$path = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_GUARD_FILE;
		clearstatcache( true, $path );
		if ( file_exists( $path ) ) {
			@touch( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem has no mtime-refresh primitive; a failed heartbeat must not interrupt the restore, the guard simply retires sooner.
		}
	}

	/**
	 * Whether the guard is currently installed.
	 *
	 * @return bool
	 */
	public static function guard_installed() {
		return defined( 'WPMU_PLUGIN_DIR' )
			&& file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_GUARD_FILE );
	}

	/**
	 * Read and clear the record of anything the guard had to disable.
	 *
	 * The guard cannot log — it runs during a fatal, before the logger exists — so it
	 * leaves a file. This is how that reaches the operator.
	 *
	 * @return array<string> Files that were disabled, newest first.
	 */
	public static function consume_incidents() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return array();
		}

		$path = trailingslashit( WPMU_PLUGIN_DIR ) . self::INCIDENT_FILE;
		if ( ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local marker file written by a pre-WordPress shutdown handler, not a remote URL; WP_Filesystem may be unavailable in this cron context.
		$raw = @file_get_contents( $path );
		self::delete_file( $path );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$files = array();
		foreach ( $decoded as $entry ) {
			if ( is_array( $entry ) && isset( $entry['file'] ) && is_string( $entry['file'] ) ) {
				$files[] = sanitize_text_field( $entry['file'] );
			}
		}

		if ( ! empty( $files ) ) {
			Log::warning(
				'The restore guard disabled mu-plugins that prevented the site from booting: ' . implode( ', ', $files ),
				array(
					'feature' => 'restore',
					'action'  => 'restore_guard_disabled_mu_plugin',
					'origin'  => 'system',
				)
			);
		}

		return $files;
	}

	/**
	 * Source of the guard mu-plugin.
	 *
	 * Runs before almost all of WordPress, so it uses core PHP plus `add_action`, which
	 * `wp-settings.php` has already loaded by the time mu-plugins are included. No
	 * translation, no logger, no filesystem API. Its one job is to make the next request
	 * boot.
	 *
	 * @return string
	 */
	private static function guard_source() {
		$ttl      = (int) self::GUARD_TTL;
		$suffix   = self::DISABLED_SUFFIX;
		$myself   = self::MU_GUARD_FILE;
		$incident = self::INCIDENT_FILE;

		return "<?php
/**
 * Tailwatch restore guard - generated, safe to delete.
 *
 * Sorts first in the mu-plugins directory so its shutdown handler is registered before
 * any sibling is included. If one of them stops the site from booting, this renames it
 * so the next request succeeds. Renames rather than deletes: the owner keeps their code,
 * they just get their site back.
 *
 * WordPress core's recovery mode cannot help here — it pauses plugins and themes, and
 * mu-plugins are included unconditionally before that machinery exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Self-retiring. A handler that renames files has no business living forever, so it
// judges its own age and steps aside. This also cleans up after a restore that died.
\$tailwatch_guard_age = @filemtime( __FILE__ );
if ( false !== \$tailwatch_guard_age && ( time() - \$tailwatch_guard_age ) > {$ttl} ) {
	@unlink( __FILE__ );
	return;
}

// THE decisive gate. Only a failure that happened while mu-plugins were still loading
// can be blamed on an mu-plugin. Without this, any later fatal — an out-of-memory kill
// on a heavy page, a max_execution_time timeout — reports whichever mu-plugin happened
// to be on the stack, and a healthy host mu-plugin gets renamed for someone else's bug.
\$GLOBALS['tailwatch_mu_boot_finished'] = false;
if ( function_exists( 'add_action' ) ) {
	add_action(
		'muplugins_loaded',
		function () {
			\$GLOBALS['tailwatch_mu_boot_finished'] = true;
		},
		-PHP_INT_MAX
	);
}

register_shutdown_function(
	function () {
		if ( ! empty( \$GLOBALS['tailwatch_mu_boot_finished'] ) ) {
			return;
		}

		\$tailwatch_error = error_get_last();
		if ( null === \$tailwatch_error || ! isset( \$tailwatch_error['type'], \$tailwatch_error['file'] ) ) {
			return;
		}
		// E_PARSE matters most: a truncated or half-extracted mu-plugin is the likeliest
		// damage an interrupted restore leaves behind, and it names the broken file exactly.
		if ( E_ERROR !== \$tailwatch_error['type'] && E_COMPILE_ERROR !== \$tailwatch_error['type'] && E_PARSE !== \$tailwatch_error['type'] ) {
			return;
		}

		\$tailwatch_file = \$tailwatch_error['file'];
		if ( ! is_string( \$tailwatch_file ) || '' === \$tailwatch_file ) {
			return;
		}

		// Only ever touch a file inside the mu-plugins directory, and never this one.
		// Both sides go through realpath and the separator is required, so a sibling
		// directory such as mu-plugins-backup cannot match.
		\$tailwatch_dir  = realpath( dirname( __FILE__ ) );
		\$tailwatch_real = realpath( \$tailwatch_file );
		if ( false === \$tailwatch_dir || false === \$tailwatch_real ) {
			return;
		}
		if ( 0 !== strpos( \$tailwatch_real, \$tailwatch_dir . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		\$tailwatch_base = basename( \$tailwatch_real );
		if ( '{$myself}' === \$tailwatch_base || '{$incident}' === \$tailwatch_base ) {
			return;
		}

		// One per request. A cascade would mean something systemic is wrong, and taking
		// the whole directory apart one file per request is not a recovery.
		if ( ! @rename( \$tailwatch_real, \$tailwatch_real . '{$suffix}' ) ) {
			return;
		}

		// The owner has to learn this happened. A file, not an option: the options cache
		// is not dependable at this point in the boot.
		\$tailwatch_marker  = \$tailwatch_dir . DIRECTORY_SEPARATOR . '{$incident}';
		\$tailwatch_records = array();
		if ( is_readable( \$tailwatch_marker ) ) {
			\$tailwatch_prev = json_decode( (string) @file_get_contents( \$tailwatch_marker ), true );
			if ( is_array( \$tailwatch_prev ) ) {
				\$tailwatch_records = \$tailwatch_prev;
			}
		}
		array_unshift(
			\$tailwatch_records,
			array(
				'file' => \$tailwatch_base,
				'time' => time(),
			)
		);
		@file_put_contents( \$tailwatch_marker, json_encode( array_slice( \$tailwatch_records, 0, 20 ) ) );
	}
);
";
	}

	/**
	 * Whether generated PHP parses.
	 *
	 * @param string $content Source.
	 * @return bool
	 */
	private static function is_valid_php( $content ) {
		if ( ! function_exists( 'token_get_all' ) || ! defined( 'TOKEN_PARSE' ) ) {
			return true;
		}
		try {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- token_get_all warns as well as throwing on invalid source; only the exception is wanted.
			return null !== @token_get_all( $content, TOKEN_PARSE );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Write a file through WP_Filesystem, falling back to direct writes.
	 *
	 * The fallback exists because every caller runs inside a WP-Cron worker, where
	 * WP_Filesystem can fail to initialise for want of credentials — the same reason
	 * MaintenanceMode carries one.
	 *
	 * @param string $path    Absolute path.
	 * @param string $content Contents.
	 * @return bool
	 */
	private static function write_file( $path, $content ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) && function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! empty( $wp_filesystem ) ) {
			return (bool) $wp_filesystem->put_contents( $path, $content, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is unavailable in this cron context; see the note above.
		return false !== @file_put_contents( $path, $content );
	}

	/**
	 * Delete a file through WP_Filesystem, falling back to direct removal.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function delete_file( $path ) {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) && function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! empty( $wp_filesystem ) ) {
			return (bool) $wp_filesystem->delete( $path );
		}

		wp_delete_file( $path );
		return ! file_exists( $path );
	}
}
