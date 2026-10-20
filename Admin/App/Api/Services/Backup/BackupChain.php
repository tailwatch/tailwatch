<?php
/**
 * Tailwatch - Incremental backup chains
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and validates the ancestry of an incremental backup.
 *
 * An increment is only meaningful next to the backups it was taken against, so the
 * question this answers is always the same one: given a folder, which backups must be
 * restored, in what order, and is any of them missing.
 *
 * **The chain lives in the completion markers on disk, not in an option.** That is a
 * deliberate choice and the single most important one here. A chain recorded in the
 * database describes a set of archives, and the operation it exists to serve — a restore
 * — replaces the database. Recording it there means the chain is destroyed by the very
 * act it was written to make safe, and a backup set moved to another host or recovered
 * from cold storage would arrive with no ancestry at all. Every fact needed to walk the
 * chain therefore travels inside the marker, in the folder, next to the archives.
 *
 * Sorting archives by filename and applying them in that order, with nothing recording
 * which full backup an increment was taken against, makes a missing or out-of-order
 * increment undetectable: the restore completes, reports success, and leaves a site
 * assembled from files that were never simultaneously true. Here each link names the
 * digest of the one before it, so a hole in the chain is a refusal rather than a silent
 * wrong answer.
 */
class BackupChain {

	/**
	 * Highest number of links to walk before giving up.
	 *
	 * A bound on a structure read off disk, where a corrupt or hand-edited marker could
	 * otherwise point at its own ancestor and loop. Set well above the length the planner
	 * permits, so it is a safety net and never the thing that ends a legitimate chain.
	 */
	const MAX_WALK = 64;

	/**
	 * Runs whose backups exist to be scanned, not to be built on.
	 *
	 * Mirrors BackupMaintainController::SCAN_DRIVEN_PROCESS_RUNS, which decides the
	 * separate retention pool these live in. Duplicated rather than imported so this
	 * class stays free of a controller dependency; the two must move together.
	 *
	 * @var array<string>
	 */
	const SCAN_DRIVEN_RUNS = array( 'malware', 'files_integrity' );

	/**
	 * Stable identity of a completed backup.
	 *
	 * Derived from what the backup CONTAINS — every archive part's content digest and
	 * every SQL file's digest — rather than from its folder name or the time it ran. Two
	 * consequences follow, and both matter:
	 *
	 * - renaming or copying a backup folder does not change its identity, so a chain
	 *   survives being moved between hosts;
	 * - altering an archive changes what this WOULD compute, which is what makes the
	 *   digest worth recording at all.
	 *
	 * Note where that second property is actually enforced: `all()` reads the digest the
	 * marker already carries rather than recomputing it, so planning trusts the marker.
	 * The archives themselves are re-fingerprinted at RESTORE time, by
	 * {@see RestoreValidator::validate_archives()}, which is the point where being wrong
	 * about it would cost something. Recomputing on every planning pass would mean
	 * hashing every archive on the site to decide whether to take a backup.
	 *
	 * @param array $marker Completion marker.
	 * @return string 64-char digest, or '' when the marker records no content.
	 */
	public static function digest( array $marker ) {
		$parts = array();

		if ( ! empty( $marker['keys'] ) && is_array( $marker['keys'] ) ) {
			foreach ( $marker['keys'] as $key => $info ) {
				if ( empty( $info['part_files'] ) || ! is_array( $info['part_files'] ) ) {
					continue;
				}
				foreach ( $info['part_files'] as $part ) {
					if ( ! is_array( $part ) || empty( $part['digest'] ) ) {
						continue;
					}
					$parts[] = (string) $key . ':' . (string) $part['file'] . ':' . (string) $part['digest'];
				}
			}
		}

		if ( ! empty( $marker['database']['file_digests'] ) && is_array( $marker['database']['file_digests'] ) ) {
			foreach ( $marker['database']['file_digests'] as $name => $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['sha256'] ) ) {
					continue;
				}
				$parts[] = 'db:' . (string) $name . ':' . (string) $entry['sha256'];
			}
		}

		if ( empty( $parts ) ) {
			// An increment on a quiet site legitimately contains nothing, and it still has
			// to have an identity — without one it drops out of the chain entirely and
			// reports "this backup did not finish" for a run that finished perfectly. Its
			// identity then comes from what it IS rather than what it holds: unique per run,
			// and still stable under a folder rename.
			//
			// A hash of nothing is what must not happen — every contents-free backup would
			// share one identity, and an increment could be applied to an unrelated base.
			$identity = (string) ( isset( $marker['zip_id'] ) ? $marker['zip_id'] : '' )
				. '|' . (string) ( isset( $marker['folder_date'] ) ? $marker['folder_date'] : '' )
				. '|' . (string) ( isset( $marker['parent'] ) ? $marker['parent'] : '' );

			return ( '||' === $identity ) ? '' : hash( 'sha256', 'empty:' . $identity );
		}

		// Sorted so the digest depends on the set, not on the order a marker happens to
		// have serialised it in.
		sort( $parts, SORT_STRING );

		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Every completed backup on disk, keyed by folder, newest last.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		// Both trees. A backup can be files-only or database-only — the marker writer says
		// so explicitly, and the validator handles either — and enumerating only files/
		// would make a database-only set invisible here. Every restore goes through the
		// chain, so such a set would be refused with "this backup did not finish", which is
		// not true and gives the operator nothing to act on.
		$roots  = array( TAILWATCH_BACKUP_DIR . '/files/', TAILWATCH_BACKUP_DIR . '/database/' );
		$folders = array();

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$entries = scandir( $root );
			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry || ! is_dir( $root . $entry ) ) {
					continue;
				}
				// Keyed, so a set present in both trees is considered once.
				$folders[ $entry ] = true;
			}
		}

		$found = array();
		foreach ( array_keys( $folders ) as $folder ) {
			$marker = BackupReconciler::tailwatch_read_completion_marker( $folder );
			if ( null === $marker ) {
				continue; // never finished; not part of any chain.
			}

			if ( ! self::is_chainable( $marker ) ) {
				continue;
			}

			$digest = isset( $marker['digest'] ) ? (string) $marker['digest'] : self::digest( $marker );
			if ( '' === $digest ) {
				continue;
			}

			$found[ $folder ] = array(
				'folder'      => $folder,
				'digest'      => $digest,
				'parent'      => isset( $marker['parent'] ) ? (string) $marker['parent'] : '',
				'incremental' => ! empty( $marker['incremental'] ),
				'generated'   => isset( $marker['generated'] ) ? (string) $marker['generated'] : '',
				'bytes'       => self::folder_bytes( $folder ),
				'deleted'     => isset( $marker['deleted'] ) && is_array( $marker['deleted'] ) ? $marker['deleted'] : array(),
				'restorable'  => ! empty( $marker['restorable'] ),
			);
		}

		// Folder names are date-stamped, so this is chronological — but only as a
		// presentation order. Ancestry is resolved by digest, never by this sort.
		ksort( $found, SORT_STRING );

		return $found;
	}

	/**
	 * Whether a completed backup may take part in a chain at all.
	 *
	 * Two exclusions, both of which would otherwise produce a chain that restores to
	 * something that was never the site:
	 *
	 * - **Scan backups.** A malware or integrity run writes into the same tree through the
	 *   same finalize path, and its marker looks perfectly healthy — `restorable` is true,
	 *   because dropping oversize files is intentional and so leaves no reconciliation gap.
	 *   But a malware backup omits every file over its size cap, and an integrity backup
	 *   archives the scanner's snapshot directory rather than the live site. An increment
	 *   built on either inherits those omissions silently. Retention makes it worse: the
	 *   scan pool keeps exactly one, so the base is deleted by the next scan and every
	 *   increment on it becomes unrestorable.
	 * - **Another site's backups.** The backup directory is under wp-content, which on a
	 *   network is shared, while the change index is per-site. A subsite increment parented
	 *   on another site's backup would name a foreign parent for a set of files that were
	 *   never in it.
	 *
	 * A marker without `process_run` predates the field and is treated as a regular
	 * backup, which is what it describes; the `site_url` check still applies.
	 *
	 * The one exception to the site check is a MIGRATED set, which records the source's
	 * URL by design and carries an explicit `adopted_by` naming this site.
	 *
	 * @param array $marker Completion marker.
	 * @return bool
	 */
	private static function is_chainable( array $marker ) {
		$process_run = isset( $marker['process_run'] ) ? (string) $marker['process_run'] : 'backup';
		if ( in_array( $process_run, self::SCAN_DRIVEN_RUNS, true ) ) {
			return false;
		}

		$site = isset( $marker['site_url'] ) ? (string) $marker['site_url'] : '';
		if ( '' === $site || $site === site_url() ) {
			return true;
		}

		// A migrated set legitimately records ANOTHER site's URL — that is the whole point:
		// it describes the data, which is about to replace this site's. The destination
		// stamps `adopted_by` when it publishes the transfer, and only then, so this stays a
		// deliberate act rather than something a copied folder could claim for itself.
		$adopted = isset( $marker['adopted_by'] ) ? (string) $marker['adopted_by'] : '';

		return '' !== $adopted && $adopted === site_url();
	}

	/**
	 * The ordered set of backups needed to restore one folder, base first.
	 *
	 * A full backup answers with itself. An increment answers with its whole ancestry, or
	 * refuses — there is no partial restore of a chain, because applying an increment
	 * without the backup underneath it produces a site made of whatever the previous
	 * contents happened to be.
	 *
	 * @param string $folder_date Backup folder to restore.
	 * @return array{ok:bool,links:array,error:string}
	 */
	public static function chain_for( $folder_date ) {
		$out = array(
			'ok'    => false,
			'links' => array(),
			'error' => '',
		);

		$all    = self::all();
		$folder = (string) $folder_date;

		if ( ! isset( $all[ $folder ] ) ) {
			$out['error'] = __( 'This backup did not finish, so its contents cannot be established.', 'tailwatch' );
			return $out;
		}

		// Ancestry is resolved by digest, so build the reverse index once.
		$by_digest = array();
		foreach ( $all as $entry ) {
			$by_digest[ $entry['digest'] ] = $entry;
		}

		$links = array();
		$seen  = array();
		$node  = $all[ $folder ];

		for ( $step = 0; $step < self::MAX_WALK; $step++ ) {
			if ( isset( $seen[ $node['digest'] ] ) ) {
				// Only reachable through a corrupt or edited marker, but a cycle here
				// would otherwise be an unbounded loop inside a restore.
				$out['error'] = __( 'This backup\'s history refers back to itself and cannot be trusted.', 'tailwatch' );
				return $out;
			}
			$seen[ $node['digest'] ] = true;

			array_unshift( $links, $node );

			if ( ! $node['incremental'] || '' === $node['parent'] ) {
				// Reached a full backup: the chain is complete.
				$out['ok']    = true;
				$out['links'] = $links;
				return $out;
			}

			if ( ! isset( $by_digest[ $node['parent'] ] ) ) {
				$out['error'] = sprintf(
					/* translators: %s: name of the backup folder whose parent is missing. */
					__( 'The backup this one builds on is missing, so "%s" cannot be restored on its own.', 'tailwatch' ),
					sanitize_text_field( $node['folder'] )
				);
				return $out;
			}

			$node = $by_digest[ $node['parent'] ];
		}

		$out['error'] = __( 'This backup has too long a history to restore safely.', 'tailwatch' );
		return $out;
	}

	/**
	 * The chain the NEXT backup would extend, base first.
	 *
	 * The newest completed backup and everything it builds on. Used for planning, where
	 * the question is how long the current chain already is rather than how to restore it.
	 *
	 * @return array<array<string,mixed>>
	 */
	public static function current() {
		$all = self::all();
		if ( empty( $all ) ) {
			return array();
		}

		// Only a restorable backup can be built on. Extending one that finished with
		// missing files would bake that loss into everything after it, and the increment
		// would carry no copy of the files the base failed to record.
		$candidate = '';
		foreach ( $all as $folder => $entry ) {
			if ( ! empty( $entry['restorable'] ) ) {
				$candidate = $folder;
			}
		}

		if ( '' === $candidate ) {
			return array();
		}

		$chain = self::chain_for( $candidate );

		return empty( $chain['ok'] ) ? array() : $chain['links'];
	}

	/**
	 * Total bytes of the archives in one backup folder.
	 *
	 * @param string $folder Backup folder name.
	 * @return int
	 */
	private static function folder_bytes( $folder ) {
		$dir = TAILWATCH_BACKUP_DIR . '/files/' . $folder . '/';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$total = 0;
		foreach ( (array) glob( $dir . '*.zip' ) as $file ) {
			if ( is_file( $file ) ) {
				$total += (int) filesize( $file );
			}
		}

		return $total;
	}

	/**
	 * Everything the chain says should no longer exist, as ABSPATH-relative paths.
	 *
	 * Deletion entries are recorded in exactly the form a zip entry takes — the same
	 * prefixed, forward-slashed path the extractor writes relative to ABSPATH — so that
	 * "was this file shipped again later?" is a set lookup against archive entry names
	 * rather than a path-shape conversion that could disagree in either direction.
	 *
	 * Two rules, and each exists because the obvious implementation is wrong:
	 *
	 * 1. **Applied after every archive has been extracted, never per increment.** A file
	 *    deleted in one increment can be re-created by a later one. Applying deletions as
	 *    each increment is reached removes it and never puts it back.
	 * 2. **Only a LATER increment cancels a deletion.** This is replayed in chain order for
	 *    that reason. Subtracting one flat set of everything any increment supplied looks
	 *    equivalent and is not: a file shipped by increment 1, deleted by the operator, and
	 *    recorded as deleted by increment 2 would have its deletion cancelled by increment
	 *    1's own copy — so extraction would put the file back and the deletion would be
	 *    discarded. For a file removed as malware, that restores it.
	 *
	 * `$supplied` is gathered from the increments' own indexes at restore time rather than
	 * stored in the markers — storing it would mean writing the whole file list into every
	 * marker, which is the size the manifest exists to avoid.
	 *
	 * @param array $links    Chain from chain_for(), base first.
	 * @param array $supplied Entry names present in each link's archives, keyed by the
	 *                        link's position in $links.
	 * @return array<string> ABSPATH-relative paths to remove.
	 */
	public static function pending_deletions( array $links, array $supplied = array() ) {
		$state = array();

		foreach ( array_values( $links ) as $position => $link ) {
			// This link's archives first: anything it ships cancels a deletion recorded
			// EARLIER in the chain, and nothing later.
			if ( ! empty( $supplied[ $position ] ) && is_array( $supplied[ $position ] ) ) {
				foreach ( array_keys( $supplied[ $position ] ) as $name ) {
					unset( $state[ self::normalise_entry( $name ) ] );
				}
			}

			if ( empty( $link['deleted'] ) || ! is_array( $link['deleted'] ) ) {
				continue;
			}
			foreach ( $link['deleted'] as $path ) {
				$path = self::normalise_entry( $path );
				if ( '' !== $path ) {
					$state[ $path ] = true;
				}
			}
		}

		unset( $state[''] );

		return array_keys( $state );
	}

	/**
	 * A deletion or archive entry reduced to one comparable form.
	 *
	 * Refuses anything that could escape the install root. A deletion list is the one part
	 * of a backup that names files WITHOUT carrying them, so nothing downstream can catch
	 * a bad entry by failing to read it — a `../` here is a delete outside the site with no
	 * second chance to notice.
	 *
	 * @param string $path Entry name.
	 * @return string Normalised path, or '' when it must not be acted on.
	 */
	public static function normalise_entry( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		// Refused, not relativised. The extractor refuses an absolute entry outright, and
		// two components deciding differently about the same string is how a path one of
		// them rejected reaches the other in a form it accepts. Our own writer never
		// produces one — it builds every entry as prefix + '/' + relative path.
		if ( '' === $path || '/' === $path[0] || false !== strpos( '/' . $path . '/', '/../' ) ) {
			return '';
		}

		// A drive letter or scheme would make the path absolute again once joined.
		if ( preg_match( '#^[a-zA-Z]:#', $path ) || false !== strpos( $path, '://' ) ) {
			return '';
		}

		return $path;
	}
}
