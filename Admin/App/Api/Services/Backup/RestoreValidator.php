<?php
/**
 * Tailwatch - Restore Validator
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a backup set is safe to restore from, before anything is destroyed.
 *
 * Nothing in this class writes. It is the gate a restore must pass first, because a
 * restore that discovers a problem halfway through has already overwritten the site it
 * was supposed to protect.
 *
 * Errors block a restore; warnings do not. The distinction matters: a backup that
 * finished with known losses is still the best copy an operator has, and refusing to
 * restore it would be its own kind of data loss. What must never happen is restoring
 * from it *unknowingly*.
 */
class RestoreValidator {

	/**
	 * Multiplier applied to the extracted size when checking free disk space.
	 *
	 * Extraction is not the only cost — the old copies stay on disk until the swap
	 * completes, so a restore needs meaningfully more headroom than the payload.
	 */
	const DISK_SAFETY_FACTOR = 2.5;

	/**
	 * Validate a backup set.
	 *
	 * @param string $folder_date Backup folder name.
	 * @return array {
	 *     @type bool   $ok         No blocking errors; a restore may proceed.
	 *     @type bool   $restorable The backup itself reported no losses.
	 *     @type array  $errors     Blocking problems.
	 *     @type array  $warnings   Non-blocking problems the operator should see.
	 *     @type array  $archives   Verified archive paths, in restore order.
	 *     @type int    $bytes      Total uncompressed bytes the archives will produce.
	 *     @type array  $top_folders Top-level folders each verified archive writes into, keyed by its path.
	 *     @type string $table_prefix Table prefix the backup's database was taken under; empty for a set
	 *                                with no database, or one that records none.
	 *     @type string $prefix_error Why that database cannot be fitted to this site's prefix; empty when
	 *                                it can. Kept out of `errors` because it applies only to a restore
	 *                                that puts the database back, which the caller knows and this does not.
	 * }
	 */
	public static function validate( $folder_date, $collect_entries = false ) {
		$out = array(
			'ok'           => false,
			'restorable'   => false,
			'errors'       => array(),
			'warnings'     => array(),
			'archives'     => array(),
			'bytes'        => 0,
			'entries'      => array(),
			'deletions'    => array(),
			'write_dirs'   => array(),
			'top_folders'  => array(),
			'table_prefix' => '',
			'prefix_error' => '',
			'collect'      => (bool) $collect_entries,
		);

		$folder_date = (string) $folder_date;
		if ( '' === $folder_date || false !== strpos( $folder_date, '/' ) || false !== strpos( $folder_date, '\\' ) || false !== strpos( $folder_date, '..' ) ) {
			$out['errors'][] = __( 'Invalid backup identifier.', 'tailwatch' );
			return $out;
		}

		$marker = BackupReconciler::tailwatch_read_completion_marker( $folder_date );
		if ( null === $marker ) {
			// No marker means the run never reached finalize. The folder may hold
			// perfectly good archives, but nothing recorded what the set should contain,
			// so there is no way to tell a complete backup from an abandoned one.
			$out['errors'][] = __( 'This backup never finished, so it cannot be verified.', 'tailwatch' );
			return $out;
		}

		$out['restorable'] = ! empty( $marker['restorable'] );
		if ( ! $out['restorable'] ) {
			$missing     = isset( $marker['files_missing'] ) ? (int) $marker['files_missing'] : 0;
			$unaccounted = isset( $marker['files_unaccounted'] ) ? (int) $marker['files_unaccounted'] : 0;
			$out['warnings'][] = sprintf(
				/* translators: 1: number of files missing from the backup, 2: number of files that could not be accounted for. */
				__( 'This backup finished with %1$d file(s) missing and %2$d unaccounted for. Restoring it will not fully reproduce the site.', 'tailwatch' ),
				$missing,
				$unaccounted
			);
		}
		if ( ! empty( $marker['failed_parts'] ) && is_array( $marker['failed_parts'] ) ) {
			/* translators: %d: number of archive parts that failed. */
			$out['warnings'][] = sprintf( __( '%d archive part(s) failed during this backup.', 'tailwatch' ), count( $marker['failed_parts'] ) );
		}
		if ( ! empty( $marker['database']['read_failed'] ) && is_array( $marker['database']['read_failed'] ) ) {
			$out['warnings'][] = sprintf(
				/* translators: %s: comma-separated list of database table names. */
				__( 'These tables could not be read when the backup ran: %s', 'tailwatch' ),
				implode( ', ', array_map( 'sanitize_text_field', $marker['database']['read_failed'] ) )
			);
		}

		self::validate_prefix( $marker, $out );
		self::validate_archives( $folder_date, $marker, $out );
		self::validate_database_files( $folder_date, $marker, $out );
		self::validate_disk_space( $out );
		self::validate_writability( $out );

		if ( ! empty( $marker['deleted'] ) && is_array( $marker['deleted'] ) ) {
			$out['deletions'] = array_values( array_map( 'strval', $marker['deleted'] ) );
		}

		unset( $out['collect'], $out['write_dirs'] );
		$out['ok'] = empty( $out['errors'] );
		return $out;
	}

	/**
	 * Validate a backup together with everything it builds on.
	 *
	 * A full backup is its own chain of one, so this is the entry point for every restore
	 * rather than a special case bolted on beside the ordinary one — a second path would
	 * be a second place for the rules to drift.
	 *
	 * Three things come out of it that a single-folder validation cannot produce:
	 *
	 * - **archives in application order**, base first. Later increments overwrite earlier
	 *   files simply by being extracted after them, which is why order is the whole
	 *   mechanism and why an unordered set cannot be restored correctly even if every
	 *   archive in it is intact.
	 * - **a refusal when the chain has a hole in it.** Applying whatever archives are found
	 *   would restore silently to a site assembled from files that were never simultaneously
	 *   true.
	 * - **the deletions still owed**, minus any a later increment has cancelled by
	 *   shipping the file again.
	 *
	 * @param string $folder_date Backup folder to restore.
	 * @return array Same shape as validate(), plus 'chain' and 'deletions'.
	 */
	public static function validate_chain( $folder_date ) {
		$chain = BackupChain::chain_for( $folder_date );

		if ( empty( $chain['ok'] ) ) {
			// The folder itself may simply be unfinished, which the single-backup check
			// already explains precisely. Defer to it when it has something to say, so the
			// operator gets the actual reason rather than a second, vaguer one layered on
			// top; the chain's own error is for a history that is genuinely broken.
			$single = self::validate( $folder_date );
			$single['chain']       = array();
			$single['deletions']   = array();
			$single['archives']    = array();
			$single['top_folders'] = array();
			$single['bytes']       = 0;
			$single['ok']          = false;

			// Only when the single-backup check found nothing to say. Both would otherwise
			// report the same unfinished folder in slightly different words, which reads as
			// two problems.
			if ( empty( $single['errors'] ) ) {
				$single['errors'][] = '' !== $chain['error'] ? $chain['error'] : __( 'This backup cannot be restored on its own.', 'tailwatch' );
			}

			return $single;
		}

		$out = array(
			'ok'           => false,
			'restorable'   => true,
			'errors'       => array(),
			'warnings'     => array(),
			'archives'     => array(),
			'bytes'        => 0,
			'top_folders'  => array(),
			'chain'        => array(),
			'deletions'    => array(),
			'table_prefix' => '',
			'prefix_error' => '',
		);

		$links    = $chain['links'];
		$supplied = array();
		$last     = count( $links ) - 1;

		foreach ( $links as $position => $link ) {
			$folder = (string) $link['folder'];
			$out['chain'][] = $folder;

			// Entry names are needed only from the increments — the base cannot cancel a
			// deletion recorded after it.
			$report = self::validate( $folder, $position > 0 );

			// Only the newest backup's database is ever imported, so only its prefix matters.
			if ( $position === $last ) {
				$out['table_prefix'] = (string) $report['table_prefix'];
				$out['prefix_error'] = (string) $report['prefix_error'];
			}

			if ( ! empty( $report['errors'] ) ) {
				foreach ( $report['errors'] as $error ) {
					$out['errors'][] = ( $position === $last )
						? $error
						/* translators: 1: name of an earlier backup in the chain, 2: the problem found with it. */
						: sprintf( __( 'In the earlier backup "%1$s" this one builds on: %2$s', 'tailwatch' ), sanitize_text_field( $folder ), $error );
				}
			}

			foreach ( $report['warnings'] as $warning ) {
				$out['warnings'][] = $warning;
			}

			if ( empty( $report['restorable'] ) ) {
				$out['restorable'] = false;
			}

			foreach ( $report['archives'] as $archive ) {
				$out['archives'][] = $archive;
			}
			$out['bytes']       += (int) $report['bytes'];
			$out['top_folders'] += $report['top_folders'];

			// Kept PER LINK, not merged: only an increment that comes AFTER a deletion can
			// cancel it, and a flat set loses exactly that ordering.
			if ( ! empty( $report['entries'] ) ) {
				$supplied[ $position ] = $report['entries'];
			}
		}

		$out['deletions'] = BackupChain::pending_deletions( $links, $supplied );
		$out['ok']        = empty( $out['errors'] );

		return $out;
	}

	/**
	 * Read the table prefix a backup's database was taken under, and whether it fits here.
	 *
	 * The dump's statements carry the table names the source site used, and the prefix is
	 * stored inside the data as well. A restore gives both this site's prefix as it stages
	 * them (see RestorePrefix); what cannot be fitted that way is reported here, before
	 * anything is touched, because restoring it as written would leave a complete set of
	 * tables this site never reads behind a restore that reported success.
	 *
	 * A files-only set has no dump, so no table names to fit, and a marker that records no
	 * prefix is not second-guessed.
	 *
	 * @param array $marker Completion marker.
	 * @param array $out    Result, by reference.
	 * @return void
	 */
	private static function validate_prefix( array $marker, array &$out ) {
		if ( empty( $marker['database']['files'] ) ) {
			return;
		}

		$recorded = ( isset( $marker['table_prefix'] ) && is_string( $marker['table_prefix'] ) ) ? $marker['table_prefix'] : '';

		$out['table_prefix'] = $recorded;
		$out['prefix_error'] = (string) RestorePrefix::refusal( $recorded );
	}

	/**
	 * Check every archive the marker says this backup produced.
	 *
	 * @param string $folder_date Backup folder name.
	 * @param array  $marker      Completion marker.
	 * @param array  $out         Result, by reference.
	 * @return void
	 */
	private static function validate_archives( $folder_date, array $marker, array &$out ) {
		if ( empty( $marker['keys'] ) || ! is_array( $marker['keys'] ) ) {
			return; // A database-only backup has no archives.
		}

		$dir = TAILWATCH_BACKUP_DIR . '/files/' . $folder_date . '/';

		// One cause, not one symptom per part. The marker is read from whichever half of
		// the backup is on disk, so a run whose database half survived and whose files half
		// did not still produces a marker naming every file part — and reporting each of
		// them as "missing" describes a damaged backup, which sends the operator looking
		// for the wrong thing. The folder simply is not there, and saying so, with the path
		// checked, is the difference between a diagnosis and a list of symptoms.
		if ( ! is_dir( $dir ) ) {
			$out['errors'][] = sprintf(
				/* translators: %s: path of the missing archive folder, relative to the backup directory. */
				__( 'This backup has no file archives on this site: the folder "%s" does not exist. Its database was found, so the files half either never finished or has been removed since. Restore from a backup that has both, or take a fresh one.', 'tailwatch' ),
				sanitize_text_field( 'files/' . $folder_date )
			);
			return;
		}

		foreach ( $marker['keys'] as $key => $info ) {
			if ( empty( $info['part_files'] ) || ! is_array( $info['part_files'] ) ) {
				// The marker recorded this key but named no parts. Either it archived
				// nothing, or the record is from an older schema — say so rather than
				// silently restoring an incomplete set.
				if ( ! empty( $info['packed'] ) ) {
					/* translators: 1: number of files recorded, 2: name of the backup area, e.g. "plugins". */
					$out['errors'][] = sprintf( __( 'Backup records %1$d file(s) for "%2$s" but names no archive parts.', 'tailwatch' ), (int) $info['packed'], sanitize_text_field( (string) $key ) );
				}
				continue;
			}

			foreach ( $info['part_files'] as $part_key => $part ) {
				$label = sanitize_text_field( (string) $key ) . '/' . sanitize_text_field( (string) $part_key );

				if ( isset( $part['status'] ) && 'completed' !== $part['status'] ) {
					/* translators: %s: archive part label, e.g. "plugins/part-2". */
					$out['warnings'][] = sprintf( __( 'Archive part %s did not complete and will be skipped.', 'tailwatch' ), $label );
					continue;
				}

				$name = wp_basename( (string) $part['file'] );
				$file = $dir . $name;
				if ( ! is_file( $file ) ) {
					$out['errors'][] = sprintf(
						/* translators: 1: archive part label, e.g. "plugins/zip_2". 2: the file name looked for. */
						__( 'Archive part %1$s is missing from this backup — no file named "%2$s" is in its folder.', 'tailwatch' ),
						$label,
						sanitize_text_field( $name )
					);
					continue;
				}

				$index = ArchiveReader::read_index( $file );
				if ( null === $index ) {
					/* translators: %s: archive part label, e.g. "plugins/part-2". */
					$out['errors'][] = sprintf( __( 'Archive part %s cannot be opened.', 'tailwatch' ), $label );
					continue;
				}

				// Zip permits repeated entry names and extraction lets the later one win,
				// so a duplicate can replace a legitimate file's content while the indexed
				// count stays the same. Our packer never writes duplicates, so any archive
				// that has them is not one we produced.
				$raw = ArchiveReader::count_entries( $file );
				if ( null !== $raw && $raw !== count( $index ) ) {
					$out['errors'][] = sprintf(
						/* translators: %s: archive part label, e.g. "plugins/part-2". */
						__( 'Archive part %s contains duplicate entry names and will not be extracted.', 'tailwatch' ),
						$label
					);
					continue;
				}

				// Compare against what verification observed when the part was written.
				// A part that opens but holds a different number of entries than it did
				// at backup time has been altered or truncated since.
				if ( isset( $part['entries'] ) && null !== $part['entries'] && (int) $part['entries'] !== count( $index ) ) {
					$out['errors'][] = sprintf(
						/* translators: 1: archive part label, 2: number of entries found now, 3: number of entries recorded at backup time. */
						__( 'Archive part %1$s holds %2$d entries but %3$d were recorded when it was written.', 'tailwatch' ),
						$label,
						count( $index ),
						(int) $part['entries']
					);
					continue;
				}

				// Counts prove the archive has the right shape; only the digest proves it
				// still holds the right CONTENTS. A file swapped for another of the same
				// name and size leaves every count identical.
				//
				// Absent for backups taken before digests were recorded — an older backup
				// is verified as far as it can be rather than refused.
				if ( ! empty( $part['digest'] ) ) {
					$digest = ArchiveReader::content_digest( $file );
					if ( null === $digest || ! hash_equals( (string) $part['digest'], (string) $digest ) ) {
						$out['errors'][] = sprintf(
							/* translators: %s: archive part label, e.g. "plugins/part-2". */
							__( 'Archive part %s has changed since the backup was taken.', 'tailwatch' ),
							$label
						);
						continue;
					}
				} else {
					$out['warnings'][] = sprintf(
						/* translators: %s: archive part label, e.g. "plugins/part-2". */
						__( 'Archive part %s predates content fingerprinting and can only be checked by entry count.', 'tailwatch' ),
						$label
					);
				}

				$unsafe = self::unsafe_entries( $index );
				if ( ! empty( $unsafe ) ) {
					// Fail the whole set, not just the entry. An archive containing even
					// one traversal path is not trustworthy, and extracting "the safe
					// parts" of a hostile archive is how a partial compromise happens.
					$out['errors'][] = sprintf(
						/* translators: 1: archive part label, 2: comma-separated list of the unsafe entry paths. */
						__( 'Archive part %1$s contains unsafe paths and will not be extracted: %2$s', 'tailwatch' ),
						$label,
						implode( ', ', array_map( 'sanitize_text_field', $unsafe ) )
					);
					continue;
				}

				$out['archives'][] = $file;
				$out['bytes']     += array_sum( $index );

				// Every directory this archive will write into. Gathered here because the
				// index is already open — reading it a second time later would double the
				// cost of the most expensive part of validation.
				$top = array();
				foreach ( array_keys( $index ) as $entry_path ) {
					// Deliberately not $dir: that holds the folder every part is looked for
					// in, and overwriting it here would send every archive after the first
					// to a path built from the previous one's last entry.
					$entry_dir = dirname( (string) $entry_path );
					if ( '.' !== $entry_dir && '' !== $entry_dir ) {
						$out['write_dirs'][ $entry_dir ]              = true;
						$top[ strstr( $entry_dir . '/', '/', true ) ] = true;
					}
				}
				// Where this archive's files sit, so a restore can tell before writing anything
				// whether they belong where this site keeps its own.
				$out['top_folders'][ $file ] = array_keys( $top );

				if ( ! empty( $out['collect'] ) ) {
					// Only requested for the increments in a chain, where the names decide
					// which recorded deletions a later increment has already cancelled.
					// Collecting them for a full base would hold the site's entire file list
					// in memory to answer a question the base cannot be asked.
					foreach ( array_keys( $index ) as $entry_name ) {
						$out['entries'][ (string) $entry_name ] = true;
					}
				}
			}
		}
	}

	/**
	 * Check the SQL parts the marker says this backup produced.
	 *
	 * @param string $folder_date Backup folder name.
	 * @param array  $marker      Completion marker.
	 * @param array  $out         Result, by reference.
	 * @return void
	 */
	private static function validate_database_files( $folder_date, array $marker, array &$out ) {
		if ( empty( $marker['database']['files'] ) || ! is_array( $marker['database']['files'] ) ) {
			return; // A files-only backup has no dump.
		}

		$dir     = TAILWATCH_BACKUP_DIR . '/database/' . $folder_date . '/';
		$digests = ( isset( $marker['database']['file_digests'] ) && is_array( $marker['database']['file_digests'] ) )
			? $marker['database']['file_digests']
			: array();

		foreach ( $marker['database']['files'] as $name ) {
			$base = wp_basename( (string) $name );
			$file = $dir . $base;
			$safe = sanitize_text_field( $base );
			if ( ! is_file( $file ) ) {
				/* translators: %s: database dump file name. */
				$out['errors'][] = sprintf( __( 'Database file %s is missing from this backup.', 'tailwatch' ), $safe );
				continue;
			}

			$size = (int) filesize( $file );
			if ( 0 === $size ) {
				/* translators: %s: database dump file name. */
				$out['errors'][] = sprintf( __( 'Database file %s is empty.', 'tailwatch' ), $safe );
				continue;
			}

			// The dump is what RestoreSqlImporter executes statement by statement, so
			// it is the higher-value target of the two trees. Same contract as the
			// archive path: an explicit mismatch blocks, and a marker with nothing
			// recorded is called out rather than passed.
			$recorded = isset( $digests[ $base ] ) && is_array( $digests[ $base ] ) ? $digests[ $base ] : null;
			if ( null === $recorded ) {
				/* translators: %s: database dump file name. */
				$out['warnings'][] = sprintf( __( 'Database file %s predates dump fingerprinting and can only be checked for presence.', 'tailwatch' ), $safe );
			} else {
				if ( isset( $recorded['size'] ) && (int) $recorded['size'] !== $size ) {
					$out['errors'][] = sprintf(
						/* translators: 1: database dump file name, 2: size on disk now in bytes, 3: size recorded at backup time in bytes. */
						__( 'Database file %1$s is %2$d bytes but %3$d were recorded when it was written.', 'tailwatch' ),
						$safe,
						$size,
						(int) $recorded['size']
					);
					continue;
				}

				if ( empty( $recorded['sha256'] ) ) {
					/* translators: %s: database dump file name. */
					$out['warnings'][] = sprintf( __( 'Database file %s was too large to fingerprint at backup time; only its size could be checked.', 'tailwatch' ), $safe );
				} else {
					$actual = hash_file( 'sha256', $file );
					if ( ! is_string( $actual ) || ! hash_equals( (string) $recorded['sha256'], $actual ) ) {
						/* translators: %s: database dump file name. */
						$out['errors'][] = sprintf( __( 'Database file %s has changed since the backup was taken.', 'tailwatch' ), $safe );
						continue;
					}
				}
			}

			$out['bytes'] += $size;
		}
	}

	/**
	 * The nearest existing folder on the disk a restore prepares its files on.
	 *
	 * @return string
	 */
	public static function restore_disk_dir() {
		$dir = defined( 'TAILWATCH_RESTORE_DIR' ) ? TAILWATCH_RESTORE_DIR : ABSPATH;
		while ( ! is_dir( $dir ) && dirname( $dir ) !== $dir ) {
			$dir = dirname( $dir );
		}
		return $dir;
	}

	/**
	 * Refuse to start a restore that cannot fit.
	 *
	 * @param array $out Result, by reference.
	 * @return void
	 */
	private static function validate_disk_space( array &$out ) {
		if ( $out['bytes'] <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled or restricted on some hosts; a false return is handled below.
		$free = @disk_free_space( self::restore_disk_dir() );
		if ( false === $free || null === $free ) {
			$out['warnings'][] = __( 'Free disk space could not be determined, so a restore may run out of room.', 'tailwatch' );
			return;
		}

		$needed = (int) ceil( $out['bytes'] * self::DISK_SAFETY_FACTOR );
		if ( $free < $needed ) {
			$out['errors'][] = sprintf(
				/* translators: 1: human-readable size the restore needs, 2: human-readable size available. */
				__( 'Not enough free disk space: %1$s needed, %2$s available.', 'tailwatch' ),
				size_format( $needed ),
				size_format( (int) $free )
			);
		}
	}

	/**
	 * Largest number of directories to write-probe.
	 *
	 * A full site can name thousands, and each probe is a create and a delete. The area
	 * roots are probed first and always, so the cap only ever truncates the long tail of
	 * deep subdirectories — which almost always inherit the permissions of a parent that has
	 * already been tested.
	 */
	const WRITE_PROBE_LIMIT = 750;

	/**
	 * Prove the restore can write where it is about to write.
	 *
	 * Without this the first unwritable file is discovered mid-extraction — after
	 * `destructive` is set and files have already been replaced — and the restore aborts,
	 * leaving a site that is neither the old one nor the new one. A root-owned directory, a
	 * hardened `wp-config.php`, or an immutable flag is enough to cause it, and none of that
	 * is visible from the archive.
	 *
	 * Two things are deliberately not done the obvious way:
	 *
	 * - **`is_writable()` is not trusted on its own.** It reports the permission bits, not
	 *   whether a write will succeed, and on some hosts `is_writable()` returns true while
	 *   writing fails. So a real file is created and removed.
	 * - **The probe file is a `.php`.** Server security modules can block PHP file creation
	 *   while leaving the directory writable by every other measure, and a restore writes PHP
	 *   into `wp-admin` and `wp-includes` on its first tick. A probe with any other extension
	 *   would pass and tell us nothing.
	 *
	 * Containment is checked in the same pass, because a symlinked area resolving outside
	 * every legitimate root would otherwise fail later, at write time, in exactly the same
	 * half-applied way.
	 *
	 * @param array $out Result, by reference.
	 * @return void
	 */
	private static function validate_writability( array &$out ) {
		$root = realpath( ABSPATH );
		if ( false === $root ) {
			$out['errors'][] = __( 'The site directory could not be resolved, so the restore cannot verify where it would write.', 'tailwatch' );
			return;
		}
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		// The area roots first, because they are where a permission problem actually lives and
		// the cap must never truncate them away.
		$targets = array( '' );
		foreach ( array_keys( $out['write_dirs'] ) as $dir ) {
			$targets[] = (string) $dir;
		}
		$targets = array_values( array_unique( $targets ) );

		$blocked  = array();
		$escaping = array();
		$probed   = 0;

		foreach ( $targets as $relative ) {
			if ( $probed >= self::WRITE_PROBE_LIMIT ) {
				break;
			}

			$path = ( '' === $relative ) ? $root : $root . '/' . ltrim( $relative, '/' );

			// A directory that does not exist yet will be created during extraction, and what
			// decides whether that succeeds is its nearest existing ancestor.
			$existing = $path;
			while ( '' !== $existing && ! is_dir( $existing ) ) {
				$parent = dirname( $existing );
				if ( $parent === $existing ) {
					break;
				}
				$existing = $parent;
			}

			$real = realpath( $existing );
			if ( false === $real ) {
				continue;
			}
			$real = rtrim( str_replace( '\\', '/', $real ), '/' );

			if ( ! RestoreExtractor::is_contained( $real, $root ) ) {
				if ( count( $escaping ) < 5 ) {
					$escaping[] = $relative;
				}
				continue;
			}

			++$probed;
			if ( ! self::can_write_php( $real ) && count( $blocked ) < 10 ) {
				$blocked[] = ( '' === $relative ) ? '/' : $relative;
			}
		}

		if ( ! empty( $blocked ) ) {
			$out['errors'][] = sprintf(
				/* translators: %s: comma-separated list of directories that could not be written to. */
				__( 'The restore cannot write to these locations, so it has been stopped before changing anything: %s', 'tailwatch' ),
				implode( ', ', array_map( 'sanitize_text_field', $blocked ) )
			);
		}

		if ( ! empty( $escaping ) ) {
			$out['errors'][] = sprintf(
				/* translators: %s: comma-separated list of paths that resolve outside the site. */
				__( 'These locations resolve outside the site and will not be written to: %s', 'tailwatch' ),
				implode( ', ', array_map( 'sanitize_text_field', $escaping ) )
			);
		}
	}

	/**
	 * Whether a PHP file can actually be created in a directory and removed again.
	 *
	 * @param string $dir Absolute, existing directory.
	 * @return bool
	 */
	private static function can_write_php( $dir ) {
		$probe = $dir . '/.tailwatch-write-probe-' . uniqid( '', true ) . '.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a deliberate write probe; WP_Filesystem would answer the credentials question, not this one, and @ is the point.
		$written = @file_put_contents( $probe, '<?php // tailwatch write probe' );

		if ( false === $written ) {
			return false;
		}

		wp_delete_file( $probe );

		return true;
	}

	/**
	 * Entry names that must never be extracted.
	 *
	 * Checked across the WHOLE archive before any of it is written, so a hostile or
	 * corrupt entry is caught while the site is still untouched. Rejecting at extract
	 * time would mean the earlier entries had already landed.
	 *
	 * @param array<string,int> $index Archive index.
	 * @return array Offending names (bounded).
	 */
	private static function unsafe_entries( array $index ) {
		$unsafe = array();

		foreach ( array_keys( $index ) as $name ) {
			$normalised = str_replace( '\\', '/', (string) $name );

			$bad = ( '' === $normalised )
				|| ( '/' === $normalised[0] )                        // POSIX absolute
				|| (bool) preg_match( '#^[A-Za-z]:#', $normalised )  // Windows drive
				|| '..' === $normalised
				|| 0 === strpos( $normalised, '../' )
				|| false !== strpos( $normalised, '/../' )
				|| ( strlen( $normalised ) > 3 && '/..' === substr( $normalised, -3 ) );

			if ( $bad ) {
				$unsafe[] = $normalised;
				if ( count( $unsafe ) >= 5 ) {
					break; // Enough to identify the problem; the set is rejected either way.
				}
			}
		}

		return $unsafe;
	}
}
