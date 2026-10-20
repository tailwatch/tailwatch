<?php
/**
 * Tailwatch - Restore Extractor
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Backup;

use Tailwatch\Admin\App\Api\Services\ZipUtility\ArchiveReader;
use Tailwatch\Admin\App\Api\Services\TickBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a verified archive back onto the site, one entry at a time.
 *
 * The invariant every path here maintains: **a destination file is either its old
 * content or its new content, never absent**. Each entry is written to a temporary
 * neighbour and then moved into place, so an interrupted restore leaves a stray temp
 * file rather than a hole. Unlinking the live file and then opening it for writing would
 * instead lose it permanently to a crash between those two calls.
 *
 * Resumable and deadline-bounded like the backup packer: a restore that cannot finish
 * a part inside one PHP lifetime continues from the next entry rather than starting
 * over or being killed mid-write.
 *
 * Entry names are re-validated here even though RestoreValidator already scanned the
 * whole archive. That is deliberate duplication: this class writes to disk, so it does
 * not delegate its own safety to a caller it cannot see.
 */
class RestoreExtractor {

	/**
	 * Suffix for the temporary neighbour an entry is written to before being moved
	 * into place.
	 */
	const TEMP_SUFFIX = '.tailwatch-restore-tmp';

	/**
	 * Bytes per read when streaming an entry out of an archive.
	 */
	const STREAM_CHUNK = 262144;

	/**
	 * Entries per PclZip extract() call. PclZip rereads the central directory on every call.
	 */
	const PCLZIP_BATCH = 100;

	/**
	 * Uncompressed bytes per PclZip extract() call, so a batch of large files stays bounded.
	 */
	const PCLZIP_BATCH_BYTES = 16777216;

	/**
	 * Failed entries after which a part is given up. The restore stops at the first part with a
	 * failure anyway, and past a few the rest only repeat one cause - an unreadable archive fails
	 * every entry - while each line is written to the log and kept in the restore's state.
	 */
	const ERROR_LIMIT = 10;

	/**
	 * Extract one archive into a destination root.
	 *
	 * @param string $zip_path         Verified archive.
	 * @param string $destination_root Directory the entries are relative to.
	 * @param int    $offset           Entry index to resume from (0 = first).
	 * @param int    $deadline         Unix timestamp to stop at (0 = no limit).
	 * @param array|null $protected_dirs Directories never written into; null means the running Tailwatch plugins.
	 * @return array{next_offset:int,extracted:int,failed:int,eof:bool,errors:array}
	 */
	public static function extract_part( $zip_path, $destination_root, $offset = 0, $deadline = 0, $protected_dirs = null ) {
		$out = array(
			'next_offset' => (int) $offset,
			'extracted'   => 0,
			'failed'      => 0,
			'skipped'     => 0,
			'kept'        => 0,
			'eof'         => false,
			'errors'      => array(),
		);

		$root = self::real_root( $destination_root );
		if ( null === $root ) {
			$out['errors'][] = __( 'Restore destination does not exist.', 'tailwatch' );
			return $out;
		}

		// read_entries(), not read_index(): entries are addressed by the archive's OWN
		// index below, and that is not the same as a position in a name-keyed map — the
		// map drops directory entries, which PclZip archives built from a directory have.
		$entries = ArchiveReader::read_entries( $zip_path );
		if ( null === $entries ) {
			$out['errors'][] = __( 'Archive could not be opened for extraction.', 'tailwatch' );
			return $out;
		}

		$protected = null === $protected_dirs ? self::running_plugin_dirs() : self::normalise_dirs( (array) $protected_dirs );

		// Opened once for the whole call: every open reads the part's entire central
		// directory, so an open per entry costs the entry count squared.
		$zip = null;
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				$out['errors'][] = __( 'Archive could not be opened for extraction.', 'tailwatch' );
				return $out;
			}
		} elseif ( ! ArchiveReader::ensure_pclzip() ) {
			$out['errors'][] = __( 'Archive could not be opened for extraction.', 'tailwatch' );
			return $out;
		}

		$total   = count( $entries );
		$i       = max( 0, (int) $offset );
		$folders = array();

		if ( null === $zip ) {
			$i = self::extract_with_pclzip( $zip_path, $root, $entries, $i, (int) $offset, $deadline, $protected, $folders, $out );
		} else {
			for ( ; $i < $total; $i++ ) {
				// Stop on a whole-entry boundary so a resumed run never has to reason about
				// a partially written file. Time or memory: extraction is the phase that has
				// already set 'destructive', so a fatal here is the most expensive one there is.
				if ( $i > (int) $offset && TickBudget::spent( $deadline ) ) {
					break;
				}
				if ( $out['failed'] >= self::ERROR_LIMIT ) {
					break;
				}

				$name   = $entries[ $i ]['name'];
				$target = self::plan_entry( $root, $name, $protected, $folders, $out );
				if ( null === $target ) {
					continue;
				}

				if ( ! self::write_entry( $zip, $name, $target ) ) {
					self::record_failure(
						$out,
						/* translators: %s: path of the file that could not be written. */
						sprintf( __( 'Could not restore: %s', 'tailwatch' ), $name )
					);
					continue;
				}

				++$out['extracted'];
			}

			$zip->close();
		}

		$out['next_offset'] = $i;
		$out['eof']         = ( $i >= $total );

		// One line for what the list leaves out, after it, so the first real failure stays first.
		if ( $out['failed'] > count( $out['errors'] ) || ( $out['failed'] >= self::ERROR_LIMIT && ! $out['eof'] ) ) {
			$out['errors'][] = $out['eof']
				? sprintf(
					/* translators: 1: number of files that could not be restored, 2: number of them listed. */
					_n( '%1$d file in this archive part could not be restored; the first %2$d are listed.', '%1$d files in this archive part could not be restored; the first %2$d are listed.', $out['failed'], 'tailwatch' ),
					$out['failed'],
					self::ERROR_LIMIT
				)
				: sprintf(
					/* translators: %d: number of files that could not be restored. */
					_n( 'Stopped after %d file could not be restored. The rest of this archive part was not tried.', 'Stopped after %d files could not be restored. The rest of this archive part was not tried.', $out['failed'], 'tailwatch' ),
					$out['failed']
				);
		}

		return $out;
	}

	/**
	 * Count a failed entry, and name it while the list is still short.
	 *
	 * @param array  $out     Result counters.
	 * @param string $message What went wrong with this entry.
	 * @return void
	 */
	private static function record_failure( array &$out, $message ) {
		++$out['failed'];
		if ( count( $out['errors'] ) < self::ERROR_LIMIT ) {
			$out['errors'][] = $message;
		}
	}

	/**
	 * Decide what happens to one entry, counting it into $out unless it is to be written.
	 *
	 * @param string $root      Resolved destination root.
	 * @param string $name      Entry name.
	 * @param array  $protected Resolved protected directories.
	 * @param array  $folders   Folder resolutions cached for this call.
	 * @param array  $out       Result counters.
	 * @return string|null Destination path, or null when the entry is not written.
	 */
	private static function plan_entry( $root, $name, array $protected, array &$folders, array &$out ) {
		// A host's own mu-plugin is bound to the platform it came from, not to the
		// site. Writing one from a backup taken elsewhere is how a restore produces a
		// white screen — and catching that afterwards, as PostRestoreGuard does, is
		// strictly worse than not creating it. Skipped, not failed: nothing is wrong.
		if ( self::is_foreign_host_mu_plugin( $name ) || self::is_gate_critical( $name ) || self::is_site_config( $name ) ) {
			++$out['skipped'];
			return null;
		}

		// The plugin running this restore is never replaced by the backup's copy of it: that
		// would swap its code mid-restore and roll back any update made since the backup.
		if ( self::is_protected_entry( $root, $name, $protected, $folders ) ) {
			++$out['kept'];
			return null;
		}

		$target = self::safe_target( $root, $name, true, $folders );
		if ( null === $target ) {
			self::record_failure(
				$out,
				/* translators: %s: path of the archive entry that was refused. */
				sprintf( __( 'Refused unsafe entry: %s', 'tailwatch' ), $name )
			);
		}

		return $target;
	}

	/**
	 * Remove files an incremental chain records as deleted.
	 *
	 * Runs after every archive in the chain has been extracted, never between them: a file
	 * deleted by one increment can be shipped again by a later one, and pruning as each
	 * increment lands would remove it and never put it back.
	 *
	 * Deliberately narrow. It removes FILES only, never directories, and only paths that
	 * resolve inside the extraction root through exactly the same check an extracted entry
	 * passes. A deletion list is the one part of a backup that names files without carrying
	 * them, so nothing downstream can catch a bad entry by failing to read it — a `../` here
	 * would be a delete outside the site with no second chance to notice.
	 *
	 * @param array  $names            Entry-style relative paths to remove.
	 * @param string $destination_root Extraction root.
	 * @param int    $offset           Index to resume from.
	 * @param int    $deadline         Unix timestamp to stop at (0 = no limit).
	 * @param array|null $protected_dirs Directories never deleted from; null means the running Tailwatch plugins.
	 * @return array{next_offset:int,removed:int,skipped:int,eof:bool,errors:array}
	 */
	public static function remove_entries( array $names, $destination_root, $offset = 0, $deadline = 0, $protected_dirs = null ) {
		$out = array(
			'next_offset' => (int) $offset,
			'removed'     => 0,
			'skipped'     => 0,
			'kept'        => 0,
			'eof'         => false,
			'errors'      => array(),
		);

		$root = self::real_root( $destination_root );
		if ( null === $root ) {
			$out['errors'][] = __( 'The restore destination could not be resolved.', 'tailwatch' );
			return $out;
		}

		$protected = null === $protected_dirs ? self::running_plugin_dirs() : self::normalise_dirs( (array) $protected_dirs );

		$names = array_values( $names );
		$total = count( $names );

		for ( $index = (int) $offset; $index < $total; $index++ ) {
			$out['next_offset'] = $index + 1;

			$name = (string) $names[ $index ];

			// A managed host's own mu-plugin is never removed on the say-so of someone
			// else's backup, for the same reason it is never written from one: it belongs to
			// the platform this site is running on, not to the site the backup came from.
			if ( self::is_foreign_host_mu_plugin( $name ) || self::is_gate_critical( $name ) || self::is_site_config( $name ) ) {
				++$out['skipped'];
				continue;
			}

			if ( self::is_protected_entry( $root, $name, $protected ) ) {
				++$out['kept'];
				continue;
			}

			$target = self::safe_target( $root, $name, false );
			if ( null === $target || ! is_file( $target ) ) {
				// Already gone is the expected case, not a failure: the operator may have
				// removed it themselves, or an earlier attempt got this far.
				++$out['skipped'];
				continue;
			}

			wp_delete_file( $target );

			if ( is_file( $target ) ) {
				// Never fatal. The file the backup says should not exist still does, which
				// leaves the site with more than it should have rather than less — and
				// abandoning a restore here would leave it with a half-applied file set.
				++$out['skipped'];
				continue;
			}

			++$out['removed'];

			// Paced on the loop, not on removals. A retried prune tick sees a list where
			// most entries are already gone, so counting removals would let it run the whole
			// list past the deadline, be killed, and resume from the same offset forever — a
			// restore that never leaves this phase.
			if ( 0 === ( $index % 200 ) && TickBudget::spent( $deadline ) ) {
				return $out;
			}
		}

		$out['eof'] = true;
		return $out;
	}

	/**
	 * Directories of the Tailwatch plugins running this request, resolved.
	 *
	 * @return array<string>
	 */
	public static function running_plugin_dirs() {
		$dirs = array();
		if ( defined( 'TAILWATCH_FILE' ) && is_string( TAILWATCH_FILE ) ) {
			$dirs[] = dirname( TAILWATCH_FILE );
		}
		if ( defined( 'TAILWATCH_PRO_DIR' ) && is_string( TAILWATCH_PRO_DIR ) ) {
			$dirs[] = TAILWATCH_PRO_DIR;
		}

		return self::normalise_dirs( $dirs );
	}

	/**
	 * Resolve directories to comparable absolute paths, dropping any that do not exist.
	 *
	 * @param array $dirs Directories.
	 * @return array<string>
	 */
	private static function normalise_dirs( array $dirs ) {
		$out = array();
		foreach ( $dirs as $dir ) {
			$real = ( is_string( $dir ) && '' !== $dir ) ? realpath( $dir ) : false;
			if ( false !== $real ) {
				$out[] = rtrim( str_replace( '\\', '/', $real ), '/' );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether an archive entry would land inside one of the protected directories.
	 *
	 * Resolved through the nearest directory that exists, so nothing is created to find out
	 * and a symlinked plugins directory is still recognised. An unsafe name is never treated
	 * as protected: the caller goes on to refuse it.
	 *
	 * @param string     $root      Resolved destination root.
	 * @param string     $name      Archive entry path.
	 * @param array      $protected Resolved directories.
	 * @param array|null $folders   Folder resolutions cached for this call.
	 * @return bool
	 */
	private static function is_protected_entry( $root, $name, array $protected, &$folders = null ) {
		if ( empty( $protected ) ) {
			return false;
		}

		$rel = str_replace( '\\', '/', (string) $name );
		if ( '' === $rel || '/' === $rel[0] || preg_match( '#^[A-Za-z]:#', $rel ) || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return false;
		}

		$path = $root . '/' . $rel;
		$dir  = dirname( $path );

		if ( is_array( $folders ) && isset( $folders['protect'] ) && array_key_exists( $dir, $folders['protect'] ) ) {
			$base = $folders['protect'][ $dir ];
		} else {
			$base = self::resolve_existing( $dir );
			if ( is_array( $folders ) ) {
				$folders['protect'][ $dir ] = $base;
			}
		}

		if ( null === $base ) {
			return false;
		}

		$target = $base . '/' . wp_basename( $path );
		$fold   = ( '\\' === DIRECTORY_SEPARATOR );

		foreach ( $protected as $protected_dir ) {
			$a = $fold ? strtolower( $target ) : $target;
			$b = $fold ? strtolower( $protected_dir ) : $protected_dir;
			if ( $a === $b || 0 === strpos( $a, $b . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the destination root, or null when it is unusable.
	 *
	 * @param string $destination_root Directory.
	 * @return string|null Normalised absolute path with no trailing slash.
	 */
	private static function real_root( $destination_root ) {
		if ( '' === (string) $destination_root || ! is_dir( $destination_root ) ) {
			return null;
		}
		$real = realpath( $destination_root );
		if ( false === $real ) {
			return null;
		}
		return rtrim( str_replace( '\\', '/', $real ), '/' );
	}

	/**
	 * Roots an extracted file may legitimately land under, as resolved paths.
	 *
	 * The obvious test — "the resolved parent sits under `realpath(ABSPATH)`" — is wrong on
	 * a very common layout. `wp-content`, `uploads`, `plugins` or `themes` are frequently
	 * symlinks pointing outside the site root: release-directory deploys keep uploads on
	 * shared storage, and several managed hosts do the same. Resolving such a path lands
	 * outside ABSPATH, so every single entry destined for it fails containment — and because
	 * that check runs at write time, the restore aborts halfway through, with files already
	 * replaced.
	 *
	 * WordPress's own directory constants are therefore roots in their own right. This does
	 * not widen what a backup can write: an entry name still cannot be absolute, cannot carry
	 * a traversal segment, and cannot name a drive or a scheme, so it can only ever land
	 * beneath one of these. What changes is that we recognise the same directories when they
	 * are reached through a link.
	 *
	 * Resolved once per request — this runs for every entry in a restore.
	 *
	 * @param string $destination_root Already-resolved extraction root.
	 * @return array<string> Normalised absolute paths, no trailing slash.
	 */
	private static function allowed_roots( $destination_root ) {
		static $cache = array();

		if ( isset( $cache[ $destination_root ] ) ) {
			return $cache[ $destination_root ];
		}

		$candidates = array( $destination_root );

		// Guarded rather than referenced directly: this class is also exercised outside a
		// WordPress bootstrap, and an undefined constant there is a fatal, not a fallback.
		foreach ( array( 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
			if ( defined( $constant ) ) {
				$dir = constant( $constant );
				if ( is_string( $dir ) && '' !== $dir ) {
					$candidates[] = $dir;
				}
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			$candidates[] = get_theme_root();
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( ! empty( $uploads['basedir'] ) ) {
				$candidates[] = $uploads['basedir'];
			}
		}

		$roots = array();
		foreach ( $candidates as $dir ) {
			$real = realpath( $dir );
			if ( false === $real ) {
				continue;
			}
			$roots[] = rtrim( str_replace( '\\', '/', $real ), '/' );
		}

		$cache[ $destination_root ] = array_values( array_unique( array_filter( $roots ) ) );

		return $cache[ $destination_root ];
	}

	/**
	 * Whether a resolved directory sits inside somewhere a restore may write.
	 *
	 * @param string $real_parent      Resolved parent directory.
	 * @param string $destination_root Resolved extraction root.
	 * @return bool
	 */
	public static function is_contained( $real_parent, $destination_root ) {
		foreach ( self::allowed_roots( $destination_root ) as $root ) {
			if ( $real_parent === $root || 0 === strpos( $real_parent, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Managed-host mu-plugins that must never be written from someone else's backup.
	 *
	 * Every one of these is installed and maintained by a hosting platform and is coupled
	 * to that platform — its object cache, its edge, its sign-on. Restoring one host's
	 * backup onto another and dropping its sign-on mu-plugin into the target is a fatal
	 * on the next request, and mu-plugins are the one place WordPress core's recovery
	 * mode cannot rescue.
	 *
	 * Matching is on the file name only, because that is what identifies these — the directory they live
	 * in is the target's mu-plugins directory by definition.
	 *
	 * @var array<string>
	 */
	const FOREIGN_HOST_MU_PLUGINS = array(
		// A managed host's page, edge and browser caches.
		'endurance-page-cache.php',
		'endurance-php-edge.php',
		'endurance-browser-cache.php',
		// A managed host's system plugin.
		'gd-system-plugin.php',
		// A managed host's platform loader, sign-on and security auditor.
		'mu-plugin.php',
		'wpe-wp-sign-on-plugin.php',
		'wpengine-security-auditor.php',
		// A managed platform's loader.
		'wpcomsh-loader.php',
		// A managed host's cache loader.
		'wp-stack-cache.php',
		// A database-engine loader, which assumes a drop-in that a restore may not carry.
		'0-sqlite.php',
		'sqlite-database-integration.php',
		// Plugins that deactivate other plugins at boot, which fights a restore.
		'eos-deactivate-plugins.php',
		'aaa-wp-cerber.php',
	);

	/**
	 * Whether an archive entry would knock out the gate holding visitors off the site.
	 *
	 * The `others` area is the site root, and the directory walk skips only "." and ".."
	 * — not dotfiles — so a backup contains the root `.htaccess`. Restoring it lands on
	 * top of the maintenance rules written at engage(), and the site reopens to visitors
	 * in the middle of having its files replaced. The maintenance file itself stays, so
	 * every liveness check goes on reporting the gate as engaged while it is gone.
	 *
	 * Skipped rather than deferred, because the site's OWN `.htaccess` is the one that
	 * should survive anyway: its rewrite rules are written for this server's domain and
	 * path, and a backup from somewhere else carries somebody else's. That is the end
	 * state anyway — lift() puts the pre-restore file back — so skipping it only removes
	 * the window in the middle where the archive's copy would be live.
	 *
	 * Root only. An `.htaccess` deeper in the tree belongs to the site's content and is
	 * restored normally.
	 *
	 * @param string $name Archive entry path.
	 * @return bool
	 */
	private static function is_gate_critical( $name ) {
		$normalised = ltrim( str_replace( chr( 92 ), '/', (string) $name ), '/' );

		return in_array(
			$normalised,
			array(
				'.htaccess',
				// The gate's own artefacts, in case a backup was taken mid-restore: a
				// restored copy of either would make lift() act on the wrong generation.
				'.htaccess.tailwatch-migration-bak',
				'tailwatch_maintenance.php',
			),
			true
		);
	}

	/**
	 * Whether an archive entry is the site's own wp-config.php.
	 *
	 * The `others` area IS the site root, so every backup contains `wp-config.php`, and
	 * the switch writes each root file it finds in staging over the live one. Restoring
	 * it hands this site another machine's database credentials, salts, table prefix and
	 * `WP_SITEURL`/`WP_HOME` — after which the site reads the SOURCE's database and
	 * reports that site's pairing, backup catalogue and restore state as its own.
	 *
	 * Skipped for the same reason the root `.htaccess` is: the live file describes the
	 * server this site runs on, and a backup from anywhere else describes a different
	 * one. A backup of this same site needs no exception — its copy matches the live
	 * one, so there is nothing to put back.
	 *
	 * Root only, by exact name. `wp-config-sample.php` is WordPress's own inert template,
	 * and a `wp-config.php` deeper in the tree is ordinary content.
	 *
	 * @param string $name Archive entry path.
	 * @return bool
	 */
	private static function is_site_config( $name ) {
		$normalised = ltrim( str_replace( chr( 92 ), '/', (string) $name ), '/' );

		return 'wp-config.php' === $normalised;
	}

	/**
	 * Whether an archive entry is a managed-host mu-plugin from another platform.
	 *
	 * Only entries that land directly in the mu-plugins directory count. A file of the
	 * same name somewhere else in the tree is an ordinary file and is restored normally.
	 *
	 * @param string $name Archive entry path.
	 * @return bool
	 */
	private static function is_foreign_host_mu_plugin( $name ) {
		$normalised = str_replace( '\\', '/', (string) $name );
		if ( false === stripos( $normalised, '/mu-plugins/' ) && 0 !== stripos( $normalised, 'mu-plugins/' ) ) {
			return false;
		}

		$base = wp_basename( $normalised );

		/**
		 * Filter the managed-host mu-plugins a restore refuses to write.
		 *
		 * @param array<string> $names File names.
		 * @param string        $entry The archive entry being considered.
		 */
		$names = apply_filters( 'tailwatch_restore_skip_mu_plugins', self::FOREIGN_HOST_MU_PLUGINS, $normalised );

		return is_array( $names ) && in_array( $base, $names, true );
	}

	/**
	 * Turn an archive entry name into a destination path, or null if it is unsafe.
	 *
	 * Two separate checks, because they catch different things. The name check rejects
	 * traversal and absolute paths written into the archive. The containment check
	 * resolves the parent directory that actually exists on disk and confirms it sits
	 * inside the root — that is what stops a symlink already present in the destination
	 * from redirecting a perfectly innocent-looking relative path somewhere else.
	 *
	 * @param string     $root          Normalised destination root.
	 * @param string     $name          Entry name from the archive.
	 * @param bool       $create_parent Create a missing parent (extraction) or refuse (deletion).
	 * @param array|null $folders       Folder resolutions cached for this call.
	 * @return string|null Absolute target path, or null when unsafe.
	 */
	private static function safe_target( $root, $name, $create_parent = true, &$folders = null ) {
		$rel = str_replace( '\\', '/', (string) $name );

		if ( '' === $rel
			|| '/' === $rel[0]
			|| (bool) preg_match( '#^[A-Za-z]:#', $rel )
			|| '..' === $rel
			|| 0 === strpos( $rel, '../' )
			|| false !== strpos( $rel, '/../' )
			|| ( strlen( $rel ) > 3 && '/..' === substr( $rel, -3 ) )
		) {
			return null;
		}

		$target = $root . '/' . ltrim( $rel, '/' );
		$parent = dirname( $target );

		if ( is_array( $folders ) && isset( $folders['target'][ $parent ] ) ) {
			$real_parent = $folders['target'][ $parent ];
		} else {
			$real_parent = self::resolve_parent( $root, $parent, $create_parent );
			// Only a folder that resolved is remembered; one that did not is asked again.
			if ( null !== $real_parent && is_array( $folders ) ) {
				$folders['target'][ $parent ] = $real_parent;
			}
		}

		return null === $real_parent ? null : $real_parent . '/' . wp_basename( $target );
	}

	/**
	 * The resolved directory an entry is written into, or null when it is not usable.
	 *
	 * @param string $root          Normalised destination root.
	 * @param string $parent        Parent directory of the target.
	 * @param bool   $create_parent Create it when missing.
	 * @return string|null
	 */
	private static function resolve_parent( $root, $parent, $create_parent ) {
		if ( ! is_dir( $parent ) ) {
			// Extraction needs the directory; deletion must never create one. A prune list
			// naming files under a directory that is already gone would otherwise rebuild
			// the whole empty skeleton of the plugin it is removing.
			if ( ! $create_parent || ! wp_mkdir_p( $parent ) ) {
				return null;
			}
		}

		$real_parent = realpath( $parent );
		if ( false === $real_parent ) {
			return null;
		}
		$real_parent = rtrim( str_replace( '\\', '/', $real_parent ), '/' );

		return self::is_contained( $real_parent, $root ) ? $real_parent : null;
	}

	/**
	 * A directory resolved through its nearest existing ancestor, creating nothing.
	 *
	 * @param string $dir Directory that may not exist yet.
	 * @return string|null Normalised path, or null when no ancestor resolves.
	 */
	public static function resolve_existing( $dir ) {
		$tail = array();
		while ( ! is_dir( $dir ) ) {
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				return null;
			}
			array_unshift( $tail, wp_basename( $dir ) );
			$dir = $parent;
		}

		$real = realpath( $dir );
		if ( false === $real ) {
			return null;
		}

		$base = rtrim( str_replace( '\\', '/', $real ), '/' );

		return empty( $tail ) ? $base : $base . '/' . implode( '/', $tail );
	}

	/**
	 * Write one entry to its destination without ever leaving the target absent.
	 *
	 * The content goes to a temporary neighbour first, then replaces the target.
	 *
	 * @param \ZipArchive $zip    Archive opened by the caller.
	 * @param string      $name   Entry name.
	 * @param string      $target Absolute destination path.
	 * @return bool
	 */
	private static function write_entry( \ZipArchive $zip, $name, $target ) {
		$temp = $target . self::TEMP_SUFFIX;

		if ( ! self::stream_entry( $zip, $name, $temp ) ) {
			self::discard( $temp );
			return false;
		}

		return self::move_into_place( $temp, $target );
	}

	/**
	 * Replace the target with a complete temporary neighbour.
	 *
	 * 1. rename() — atomic where the platform allows it, including over an existing
	 *    file on both POSIX and Windows
	 * 2. copy() — the fallback, because rename() over an OPEN target fails on Windows
	 *    and a restore is exactly when other requests may hold plugin files open.
	 *    copy() overwrites in place and, crucially, leaves the original intact when it
	 *    fails rather than removing it first
	 *
	 * Nothing here ever deletes the destination before the replacement exists.
	 *
	 * @param string $temp   Complete temporary file beside the target.
	 * @param string $target Absolute destination path.
	 * @return bool
	 */
	private static function move_into_place( $temp, $target ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic replace; the copy() fallback below handles a refusal.
		if ( @rename( $temp, $target ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed copy leaves the original in place, which is the point.
		if ( @copy( $temp, $target ) ) {
			self::discard( $temp );
			return true;
		}

		self::discard( $temp );
		return false;
	}

	/**
	 * Stream one entry out of an open archive to a path.
	 *
	 * @param \ZipArchive $zip  Archive opened by the caller.
	 * @param string      $name Entry name.
	 * @param string      $temp Path to write.
	 * @return bool
	 */
	private static function stream_entry( \ZipArchive $zip, $name, $temp ) {
		$in = $zip->getStream( $name );
		if ( ! is_resource( $in ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed write of archive content; WP_Filesystem has no stream API. Checked immediately.
		$fh = @fopen( $temp, 'wb' );
		if ( false === $fh ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the archive stream opened above.
			return false;
		}

		$ok = true;
		while ( ! feof( $in ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming read from the archive handle above.
			$chunk = fread( $in, self::STREAM_CHUNK );
			if ( false === $chunk ) {
				$ok = false;
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming write; see fopen above.
			if ( '' !== $chunk && false === fwrite( $fh, $chunk ) ) {
				$ok = false;
				break;
			}
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the destination handle opened above.
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the archive stream opened above.

		return $ok;
	}

	/**
	 * Extract with PclZip, one extract() call per batch of entries.
	 *
	 * Entries are planned under the same deadline test as the ZipArchive loop, and every
	 * planned entry is written before returning, so no entry is counted twice across calls.
	 *
	 * @param string $zip_path  Archive path.
	 * @param string $root      Resolved destination root.
	 * @param array  $entries   Entries from ArchiveReader::read_entries().
	 * @param int    $i         Position to start from.
	 * @param int    $offset    Position the call resumes from.
	 * @param int    $deadline  Unix timestamp to stop at (0 = no limit).
	 * @param array  $protected Resolved protected directories.
	 * @param array  $folders   Folder resolutions cached for this call.
	 * @param array  $out       Result counters.
	 * @return int Position of the next entry to extract.
	 */
	private static function extract_with_pclzip( $zip_path, $root, array $entries, $i, $offset, $deadline, array $protected, array &$folders, array &$out ) {
		$archive = new \PclZip( $zip_path );
		$scratch = $root . '/.tailwatch-restore-batch-' . substr( md5( (string) $zip_path ), 0, 12 );
		$total   = count( $entries );
		$batch   = array();
		$bytes   = 0;

		for ( ; $i < $total; $i++ ) {
			if ( $i > $offset && TickBudget::spent( $deadline ) ) {
				break;
			}
			if ( $out['failed'] >= self::ERROR_LIMIT ) {
				break;
			}

			$name   = $entries[ $i ]['name'];
			$target = self::plan_entry( $root, $name, $protected, $folders, $out );
			if ( null === $target ) {
				continue;
			}

			$batch[] = array(
				'index'  => (int) $entries[ $i ]['index'],
				'name'   => $name,
				'target' => $target,
			);
			$bytes  += (int) $entries[ $i ]['size'];

			if ( count( $batch ) >= self::PCLZIP_BATCH || $bytes >= self::PCLZIP_BATCH_BYTES ) {
				self::write_pclzip_batch( $archive, $scratch, $batch, $out );
				$batch = array();
				$bytes = 0;
			}
		}

		if ( ! empty( $batch ) ) {
			self::write_pclzip_batch( $archive, $scratch, $batch, $out );
		}

		return $i;
	}

	/**
	 * Extract one batch into a scratch folder, then move each file into place.
	 *
	 * @param \PclZip $archive Archive.
	 * @param string  $scratch Scratch folder, emptied before and after.
	 * @param array   $batch   Planned entries: index, name, target.
	 * @param array   $out     Result counters.
	 * @return void
	 */
	private static function write_pclzip_batch( $archive, $scratch, array $batch, array &$out ) {
		self::remove_tree( $scratch );

		$result = 0;
		if ( wp_mkdir_p( $scratch ) ) {
			$result = $archive->extract(
				PCLZIP_OPT_BY_INDEX,
				implode( ',', array_column( $batch, 'index' ) ),
				PCLZIP_OPT_PATH,
				$scratch,
				PCLZIP_OPT_EXTRACT_DIR_RESTRICTION,
				$scratch,
				PCLZIP_OPT_REPLACE_NEWER
			);
		}

		foreach ( $batch as $entry ) {
			$source = $scratch . '/' . ltrim( str_replace( '\\', '/', $entry['name'] ), '/' );
			if ( ! is_array( $result ) || ! is_file( $source ) || ! self::place_file( $source, $entry['target'] ) ) {
				self::record_failure(
					$out,
					/* translators: %s: path of the file that could not be written. */
					sprintf( __( 'Could not restore: %s', 'tailwatch' ), $entry['name'] )
				);
				continue;
			}

			++$out['extracted'];
		}

		self::remove_tree( $scratch );
	}

	/**
	 * Move an extracted scratch file into place without ever leaving the target absent.
	 *
	 * @param string $source Extracted file.
	 * @param string $target Absolute destination path.
	 * @return bool
	 */
	private static function place_file( $source, $target ) {
		$temp = $target . self::TEMP_SUFFIX;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- our own scratch file; copy() covers a move across filesystems.
		if ( ! @rename( $source, $temp ) && ! @copy( $source, $temp ) ) {
			self::discard( $temp );
			return false;
		}

		return self::move_into_place( $temp, $target );
	}

	/**
	 * Remove a scratch folder this class created, ignoring failure.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- scratch directory we created; a failure here is inert.
				@rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- scratch directory we created; a failure here is inert.
		@rmdir( $dir );
	}

	/**
	 * Remove a temporary artifact, ignoring failure.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function discard( $path ) {
		if ( '' !== (string) $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
