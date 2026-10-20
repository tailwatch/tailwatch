<?php
/**
 * Tailwatch - Backup Reconciler
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
 * Decides whether a finished backup can be trusted, and records that verdict on disk.
 *
 * Two questions, deliberately kept apart:
 *
 * - what FAILED outright (parts that hit their retry cap, tables that could not be read)
 * - what is UNACCOUNTED (files the manifest intended that nothing ever observed)
 *
 * A part can close as a structurally valid zip while holding fewer files than intended,
 * so neither question can be answered from part status alone — a backup that lost files
 * would otherwise report a clean success.
 *
 * Stateless by design: every method takes the backup state array and returns a verdict,
 * so restore can reuse the same judgement without a backup controller.
 */
class BackupReconciler {

	/**
	 * Filename of the per-backup completion marker.
	 *
	 * Its presence means the run reached the end; see
	 * tailwatch_write_completion_marker() for the presence-vs-restorable contract.
	 */
	const COMPLETION_MARKER = 'tailwatch-backup.json';

	/**
	 * Total bytes of SQL dump this class will fingerprint while writing one marker.
	 *
	 * Archive parts get a digest almost free — ArchiveReader reads per-entry CRCs out
	 * of the zip's central directory without touching the payload. A .sql file has no
	 * such index, so the only honest fingerprint is a hash of the whole file, and the
	 * marker is written inside a tick that also has to finish. Files are hashed until
	 * this budget runs out; the rest record size only, and the validator says which
	 * kind of check it was able to make.
	 *
	 * hash_file() streams in C, so a gigabyte costs well under a second on any host
	 * that could have produced a gigabyte of dump in the first place.
	 */
	const DIGEST_BYTE_BUDGET = 1073741824;

	/**
	 * Collect the parts that permanently failed (marked status='failed' after the
	 * 3-attempt cap) across every folder's batch. Pure read — no side effects — so
	 * finalize can report an incomplete backup honestly. Returns "folder/part" keys.
	 *
	 * @param array $existing_data Backup state.
	 * @return array List of "folderKey/partKey" strings (empty when all parts succeeded).
	 */
	public static function tailwatch_collect_failed_parts( array $existing_data ) {
		$failed_parts = array();
		foreach ( $existing_data as $folder_key => $folder_data ) {
			if ( ! is_array( $folder_data ) || empty( $folder_data['batch'] ) || ! is_array( $folder_data['batch'] ) ) {
				continue;
			}
			foreach ( $folder_data['batch'] as $part_key => $part ) {
				if ( isset( $part['status'] ) && 'failed' === $part['status'] ) {
					$failed_parts[] = $folder_key . '/' . $part_key;
				}
			}
		}

		// DB parts live one level deeper — tables.db_tables.<table>.batch — out of reach of
		// the loop above, and a table that could not be read is flagged read_failed rather
		// than carrying a failed part. Without this pass a failed or unreadable table would
		// never appear in failed_parts and the backup would report a clean success.
		if ( ! empty( $existing_data['tables']['db_tables'] ) && is_array( $existing_data['tables']['db_tables'] ) ) {
			foreach ( $existing_data['tables']['db_tables'] as $table_name => $table_data ) {
				if ( ! is_array( $table_data ) ) {
					continue;
				}

				if ( ! empty( $table_data['read_failed'] ) ) {
					$failed_parts[] = 'database/' . $table_name;
					continue;
				}

				if ( empty( $table_data['batch'] ) || ! is_array( $table_data['batch'] ) ) {
					continue;
				}
				foreach ( $table_data['batch'] as $part_key => $part ) {
					if ( isset( $part['status'] ) && 'failed' === $part['status'] ) {
						$failed_parts[] = 'database/' . $table_name . '/' . $part_key;
					}
				}
			}
		}

		return $failed_parts;
	}

	/**
	 * Write the backup-set marker into each per-backup directory.
	 *
	 * Written LAST, only once a run has reached the end, so its presence is itself the
	 * proof that the run finished. Without it a folder holding three parts is
	 * indistinguishable from an abandoned run that was killed after its third part —
	 * the backup state row is the only thing that knows, and that row is exactly what a
	 * restore is about to overwrite.
	 *
	 * `restorable` is deliberately separate from presence:
	 *
	 * - no marker            -> the run never finished; do not restore from it
	 * - marker, restorable   -> finished and nothing was lost
	 * - marker, !restorable  -> finished but files or parts are missing; a caller may
	 *                           still choose to restore, knowingly
	 *
	 * @param array $existing_data Final backup state.
	 * @return void
	 */
	public static function tailwatch_write_completion_marker( array $existing_data ) {
		global $wpdb;

		$folder_date = isset( $existing_data['folderDate'] ) ? $existing_data['folderDate'] : '';
		if ( '' === $folder_date ) {
			return;
		}

		$keys = array();

		// What the site no longer contains. The sweep is site-wide and writes ONE list at
		// the top of the state, not one per area, so it is read from there; per area it
		// would always be empty. An empty list would let a restore resurrect every file
		// deleted since the base backup, including files removed as malware, which is the
		// case this list exists to prevent.
		$deleted = array();
		if ( ! empty( $existing_data['deleted'] ) && is_array( $existing_data['deleted'] ) ) {
			foreach ( $existing_data['deleted'] as $gone ) {
				$deleted[] = (string) $gone;
			}
		}
		foreach ( $existing_data as $key => $data ) {
			if ( ! is_array( $data ) || ! isset( $data['expected_files'] ) ) {
				continue;
			}
			// Record each part by basename plus the entry count verification observed, so
			// a restore can check the archives it finds against the archives this run
			// actually wrote. Only the basename is stored: the absolute path is a
			// property of the machine that made the backup, and a restore may well be
			// reading the set on a different one.
			$parts = array();
			if ( isset( $data['batch'] ) && is_array( $data['batch'] ) ) {
				foreach ( $data['batch'] as $part_key => $part ) {
					if ( ! is_array( $part ) || empty( $part['path'] ) ) {
						continue;
					}
					$parts[ $part_key ] = array(
						'file'    => wp_basename( $part['path'] ),
						'size'    => isset( $part['size'] ) ? (int) $part['size'] : 0,
						'entries' => isset( $part['entries'] ) ? (int) $part['entries'] : null,
						// Content fingerprint taken at verification time; a restore compares
						// against it to prove the archive still holds what it held then.
						'digest'  => isset( $part['digest'] ) ? (string) $part['digest'] : '',
						'status'  => isset( $part['status'] ) ? $part['status'] : '',
					);
				}
			}

			$keys[ $key ] = array(
				'expected'   => (int) $data['expected_files'],
				'packed'     => isset( $data['packed_files'] ) ? (int) $data['packed_files'] : 0,
				'skipped'    => isset( $data['skipped_files'] ) ? (int) $data['skipped_files'] : 0,
				'parts'      => count( $parts ),
				'part_files' => $parts,
			);
		}

		// The DB dump is not part of $keys — those entries are folder keys carrying an
		// expected_files count, and tables live a level deeper. Record the SQL parts
		// separately so a restore can confirm the dump it is about to import is the one
		// this run produced, and which tables were known to be unreadable.
		$database = array(
			'tables'       => 0,
			'files'        => array(),
			// basename => { size, sha256 }. Parallel to `files` rather than replacing
			// it, so a marker written by an older version still validates.
			'file_digests' => array(),
			'read_failed'  => array(),
		);
		$digest_budget = self::DIGEST_BYTE_BUDGET;
		// The schema part first. It holds the CREATE TABLE statements every INSERT part
		// depends on, is written directly rather than through a batch entry, and is the
		// FIRST file a restore imports — so leaving it out would have the validator check a
		// different set of files than the restore actually consumes.
		if ( ! empty( $existing_data['tables']['path'] ) ) {
			$database['files'][] = wp_basename( (string) $existing_data['tables']['path'] );
			self::record_sql_digest( (string) $existing_data['tables']['path'], $database, $digest_budget );
		}

		if ( ! empty( $existing_data['tables']['db_tables'] ) && is_array( $existing_data['tables']['db_tables'] ) ) {
			foreach ( $existing_data['tables']['db_tables'] as $table_name => $table_data ) {
				if ( ! is_array( $table_data ) ) {
					continue;
				}
				++$database['tables'];
				if ( ! empty( $table_data['read_failed'] ) ) {
					$database['read_failed'][] = (string) $table_name;
				}
				if ( empty( $table_data['batch'] ) || ! is_array( $table_data['batch'] ) ) {
					continue;
				}
				foreach ( $table_data['batch'] as $part ) {
					if ( ! is_array( $part ) || empty( $part['path'] ) ) {
						continue;
					}
					// Match the file tree's rule: a part that did not complete is not a
					// file a restore should expect to find intact. The file tree gets
					// this for free because a failed part carries no path; DB parts keep
					// theirs, so the status has to be checked explicitly.
					if ( isset( $part['status'] ) && 'completed' !== $part['status'] ) {
						continue;
					}
					$database['files'][] = wp_basename( $part['path'] );
					self::record_sql_digest( (string) $part['path'], $database, $digest_budget );
				}
			}
		}

		$database['files'] = array_values( array_unique( $database['files'] ) );

		$had_failures = ! empty( $existing_data['had_failures'] );
		$marker       = array(
			'schema'            => 1,
			'plugin_version'    => defined( 'TAILWATCH_VERSION' ) ? TAILWATCH_VERSION : '',
			'generated'         => current_time( 'mysql' ),
			'site_url'          => isset( $existing_data['site_url'] ) ? $existing_data['site_url'] : site_url(),
			// The table prefix the dump's own statements carry. A restore onto an install
			// using a different one would import the backup's tables under the backup's
			// names, leave wp-config pointing at the site's own, and change nothing at all —
			// a restore that reports success and does not happen. Recorded so that can be
			// refused instead. base_prefix, not prefix, because a network backup spans every
			// site and base_prefix is what identifies the install.
			'table_prefix'      => $wpdb->base_prefix,
			'folder_date'       => $folder_date,
			'zip_id'            => isset( $existing_data['zipId'] ) ? $existing_data['zipId'] : '',
			'backup_type'       => isset( $existing_data['backupType'] ) ? $existing_data['backupType'] : '',
			// Why this backup ran. A malware or integrity scan writes into the same tree
			// through this same finalize path, and a scan backup is NOT a thing another
			// backup can be built on: it deliberately drops every file over a size cap,
			// and the integrity variant archives a scanner snapshot rather than the live
			// site. Without this field nothing downstream can tell the difference.
			'process_run'       => isset( $existing_data['process_run'] ) ? (string) $existing_data['process_run'] : 'backup',
			'complete'          => true,
			'restorable'        => ! $had_failures,
			'had_failures'      => $had_failures,
			'files_missing'     => isset( $existing_data['files_missing'] ) ? (int) $existing_data['files_missing'] : 0,
			'files_unaccounted' => isset( $existing_data['files_unaccounted'] ) ? (int) $existing_data['files_unaccounted'] : 0,
			'failed_parts'      => isset( $existing_data['failed_parts'] ) ? $existing_data['failed_parts'] : array(),
			'keys'              => $keys,
			'database'          => $database,
			// Ancestry. Written into the marker rather than an option because the marker
			// travels with the archives: a chain recorded in the database would be destroyed
			// by the restore it exists to make safe, and a backup set copied to another host
			// would arrive with no history at all.
			'incremental'       => ! empty( $existing_data['incremental'] ),
			'parent'            => isset( $existing_data['parent'] ) ? (string) $existing_data['parent'] : '',
			'deleted'           => array_values( array_unique( $deleted ) ),
		);

		// Derived from what the backup CONTAINS, so it has to be computed once the part and
		// dump digests are in the array. It is what a later increment names as its parent,
		// and what proves that parent has not been altered since.
		$marker['digest'] = BackupChain::digest( $marker );

		$payload = wp_json_encode( $marker, JSON_PRETTY_PRINT );
		if ( false === $payload ) {
			return;
		}

		// A backup can have a files tree, a database tree, or both (files-only and
		// DB-only runs exist), so mark whichever were actually produced.
		foreach ( array( 'files', 'database' ) as $tree ) {
			$dir = TAILWATCH_BACKUP_DIR . '/' . $tree . '/' . $folder_date . '/';
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			// Write-then-rename so a marker is never observed half-written — a truncated
			// marker would read as a corrupt backup rather than an unfinished one.
			$tmp = $dir . self::COMPLETION_MARKER . '.tmp';
			if ( false === @file_put_contents( $tmp, $payload ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic marker publish paired with rename(), which WP_Filesystem has no primitive for; the false return is checked immediately.
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish; failure is cleaned up below.
			if ( ! @rename( $tmp, $dir . self::COMPLETION_MARKER ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Fingerprint one SQL dump file into the marker, while budget remains.
	 *
	 * Records the size unconditionally — that alone catches truncation, which is the
	 * most common way a dump goes bad — and the sha256 when the budget allows. A file
	 * with no sha256 entry is reported by the validator as size-checked only rather
	 * than silently treated as verified.
	 *
	 * @param string $path     Absolute path to the .sql file, as written by this run.
	 * @param array  $database Database section of the marker, by reference.
	 * @param int    $budget   Remaining hash budget in bytes, by reference.
	 * @return void
	 */
	private static function record_sql_digest( $path, array &$database, &$budget ) {
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}

		$name = wp_basename( $path );
		if ( isset( $database['file_digests'][ $name ] ) ) {
			return;
		}

		$size = (int) filesize( $path );
		$entry = array( 'size' => $size );

		if ( $size > 0 && $size <= $budget ) {
			$hash = hash_file( 'sha256', $path );
			if ( is_string( $hash ) && '' !== $hash ) {
				$entry['sha256'] = $hash;
				$budget         -= $size;
			}
		}

		$database['file_digests'][ $name ] = $entry;
	}

	/**
	 * Read a backup set's completion marker.
	 *
	 * @param string $folder_date Backup folder name.
	 * @return array|null Marker contents, or null when the run never finished.
	 */
	public static function tailwatch_read_completion_marker( $folder_date ) {
		if ( '' === (string) $folder_date ) {
			return null;
		}
		foreach ( array( 'files', 'database' ) as $tree ) {
			$file = TAILWATCH_BACKUP_DIR . '/' . $tree . '/' . $folder_date . '/' . self::COMPLETION_MARKER;
			if ( ! is_file( $file ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- small local marker; checked below.
			$raw = @file_get_contents( $file );
			if ( false === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Reconcile intended vs archived file counts for every folder key.
	 *
	 * The manifest records how many files a key intends to archive; the packer records
	 * how many it actually added and how many it had to skip. Every intended file must
	 * land in exactly one of those two buckets. Two different problems fall out:
	 *
	 * - `missing`     — counted skips. The file existed when the manifest was built and
	 *                   was gone or unreadable by the time we packed it. Known loss.
	 * - `unaccounted` — expected minus (archived + skipped). Files the packer never even
	 *                   reached, e.g. a part that failed before consuming its slice.
	 *                   Worse than `missing`, because nothing observed them.
	 *
	 * Keys whose state predates this accounting carry no `expected_files` and are
	 * skipped rather than reported as a phantom shortfall.
	 *
	 * @param array $existing_data Backup state.
	 * @return array{missing:int,unaccounted:int,detail:array}
	 */
	public static function tailwatch_reconcile_file_counts( array $existing_data ) {
		$missing     = 0;
		$unaccounted = 0;
		$detail      = array();

		foreach ( $existing_data as $folder_key => $folder_data ) {
			if ( ! is_array( $folder_data ) || ! isset( $folder_data['expected_files'] ) ) {
				continue;
			}

			$expected = (int) $folder_data['expected_files'];
			$packed   = isset( $folder_data['packed_files'] ) ? (int) $folder_data['packed_files'] : 0;
			$skipped  = isset( $folder_data['skipped_files'] ) ? (int) $folder_data['skipped_files'] : 0;
			$gap      = $expected - ( $packed + $skipped );

			if ( $skipped > 0 || $gap > 0 ) {
				$missing    += $skipped;
				$unaccounted += max( 0, $gap );
				$detail[]    = "{$folder_key}: expected {$expected}, archived {$packed}, skipped {$skipped}"
					. ( $gap > 0 ? ", unaccounted {$gap}" : '' );
			}
		}

		return array(
			'missing'     => $missing,
			'unaccounted' => $unaccounted,
			'detail'      => $detail,
		);
	}
}
