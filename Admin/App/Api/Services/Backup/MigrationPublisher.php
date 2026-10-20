<?php
/**
 * Migration Publisher
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 */

namespace Tailwatch\Admin\App\Api\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Services\ZipUtility\ArchiveReader;

/**
 * Turns a folder of verified backup parts into a backup this site recognises and can
 * restore. It is the last step of any migration, whichever way the parts arrived.
 *
 * ## Why this exists at all
 *
 * Parts on disk are not a backup. Two things are missing until this runs, and either alone
 * makes a completed transfer useless:
 *
 * 1. **A completion marker.** `RestoreValidator` reads it first and refuses outright
 *    without one — it is the only evidence that a backup finished rather than being
 *    abandoned half-written.
 * 2. **A backup row.** The list walks the folders on disk and keeps only those with a
 *    matching row, so parts with no row are invisible, and the restore button — the only
 *    way anyone starts a restore — never appears.
 *
 * ## Why the marker is built here rather than copied from the source
 *
 * The source's own marker carries facts that are true THERE and false here: a deletion
 * list computed against the source's backup chain, and incremental ancestry naming parent
 * backups this site has never held. Copying it would import a chain that cannot resolve
 * and a list of files to delete that was never about this site. Building it from the parts
 * that actually arrived means every digest in it is one this site verified itself.
 *
 * ## Why it lives in free
 *
 * Both migration paths end here — the pro automatic transfer and the free manual one — and
 * a marker written two slightly different ways is a backup that restores two slightly
 * different ways. It depends on nothing but free classes, so there is no reason for the
 * definitive version to sit behind the licence.
 */
class MigrationPublisher {

	/**
	 * Record a transferred set as a restorable backup.
	 *
	 * @param string $folder      Backup folder name, as it sits under files/ and database/.
	 * @param array  $parts       Verified parts: each with area, file, size, digest.
	 * @param string $origin_url  The site the DATA describes — not the site now holding it.
	 * @param string $origin_pfx  The table prefix the dump was taken with.
	 * @return true|string True, or a message.
	 */
	public static function publish( $folder, array $parts, $origin_url, $origin_pfx ) {
		$folder = (string) $folder;

		$keys     = array();
		$database = array(
			'tables'       => 0,
			'files'        => array(),
			'file_digests' => array(),
			'read_failed'  => array(),
		);

		$index = 0;
		foreach ( $parts as $part ) {
			if ( 'database' === $part['area'] ) {
				$database['files'][]                       = $part['file'];
				$database['file_digests'][ $part['file'] ] = array(
					'size'   => (int) $part['size'],
					'sha256' => (string) $part['digest'],
				);
				continue;
			}

			++$index;
			$keys['migrated']['part_files'][ 'zip_' . $index ] = array(
				'file'    => $part['file'],
				'size'    => (int) $part['size'],
				// Unknown, and deliberately left null: the validator skips the entry-count
				// comparison for a null rather than us inventing a number for it to match.
				'entries' => null,
				'digest'  => (string) $part['digest'],
				'status'  => 'completed',
			);
		}

		if ( ! empty( $keys ) ) {
			$keys['migrated']['expected'] = $index;
			$keys['migrated']['packed']   = $index;
			$keys['migrated']['skipped']  = 0;
			$keys['migrated']['parts']    = $index;
		}

		$marker = array(
			'schema'            => 1,
			'plugin_version'    => defined( 'TAILWATCH_VERSION' ) ? TAILWATCH_VERSION : '',
			'generated'         => current_time( 'mysql' ),
			// The site the DATA describes, not the site now holding it. The restore reads
			// this to know which URLs to rewrite.
			'site_url'          => (string) $origin_url,
			'table_prefix'      => (string) $origin_pfx,
			// The site that adopted it. Without this the chain reader refuses the set as
			// belonging to somebody else — which is the right default, and wrong here.
			'adopted_by'        => site_url(),
			'folder_date'       => $folder,
			'zip_id'            => '',
			'backup_type'       => 'Complete Backup',
			'process_run'       => 'backup',
			'complete'          => true,
			'restorable'        => true,
			'had_failures'      => false,
			'files_missing'     => 0,
			'files_unaccounted' => 0,
			'failed_parts'      => array(),
			'keys'              => $keys,
			'database'          => $database,
			// A migrated set is self-contained by construction — neither path offers an
			// incremental one — so it names no parent and owes no deletions.
			'incremental'       => false,
			'parent'            => '',
			'deleted'           => array(),
		);

		$marker['digest'] = BackupChain::digest( $marker );

		$payload = wp_json_encode( $marker, JSON_PRETTY_PRINT );
		if ( false === $payload ) {
			return __( 'The transferred backup could not be recorded on this site.', 'tailwatch' );
		}

		$written = false;
		foreach ( array( 'files', 'database' ) as $tree ) {
			$dir = TAILWATCH_BACKUP_DIR . '/' . $tree . '/' . $folder . '/';
			if ( ! is_dir( $dir ) ) {
				continue; // A files-only or database-only set has only the one tree.
			}

			// Write-then-rename, so a marker is never observed half-written — a truncated
			// one reads as a corrupt backup rather than an unfinished transfer.
			$tmp = $dir . BackupReconciler::COMPLETION_MARKER . '.tmp';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish paired with rename(), for which WP_Filesystem has no primitive; the false return is checked.
			if ( false === @file_put_contents( $tmp, $payload ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish; failure is cleaned up immediately.
			if ( @rename( $tmp, $dir . BackupReconciler::COMPLETION_MARKER ) ) {
				$written = true;
			} else {
				wp_delete_file( $tmp );
			}
		}

		if ( ! $written ) {
			return __( 'The transferred backup could not be recorded on this site.', 'tailwatch' );
		}

		self::register_backup_row( $folder, $marker );

		return true;
	}

	/**
	 * Whether an address is this very site.
	 *
	 * Migrating a site onto itself is always a mistake, and left unchecked it is a
	 * destructive one: source and destination resolve to the same backup directory, so
	 * the transfer writes each part over the very file it is reading. The set that was
	 * being copied is the set that gets damaged, and the operator's own backup is the
	 * casualty.
	 *
	 * Compared on host AND path, because a subdirectory multisite puts two genuinely
	 * different sites on one host — example.com/a and example.com/b must stay distinct.
	 * The scheme is ignored: a site reached over http and https is one site.
	 *
	 * @param string $url Address to test.
	 * @return bool
	 */
	public static function is_this_site( $url ) {
		$candidate = self::normalise_site( $url );
		return '' !== $candidate && $candidate === self::normalise_site( site_url() );
	}

	/**
	 * Reduce an address to the part that identifies the site.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function normalise_site( $url ) {
		$url = strtolower( trim( (string) $url ) );
		$url = preg_replace( '#^https?://#', '', $url );
		return untrailingslashit( (string) $url );
	}

	/**
	 * Check a part that has landed on disk, and remove it if it is wrong.
	 *
	 * Every way a part can arrive ends here: pulled by the destination, pushed by the
	 * source, or carried by hand and uploaded in chunks. Keeping one implementation is
	 * what stops those three drifting until the least-used one is quietly the weakest.
	 *
	 * A part that fails is deleted rather than left. On disk it is indistinguishable
	 * from a good one to the restore that follows, which is the entire reason for
	 * verifying it in the first place.
	 *
	 * @param string $target Absolute path to the landed file.
	 * @param array  $part   Manifest entry: area, file, size, digest.
	 * @return true|string True, or a message describing what was wrong.
	 */
	public static function verify_part( $target, array $part ) {
		$expected = isset( $part['size'] ) ? (int) $part['size'] : 0;
		$declared = isset( $part['digest'] ) ? (string) $part['digest'] : '';

		// Neither a size nor a fingerprint is not a part that passed its checks — it is a
		// part nothing was checked against, accepted on the word of whoever sent it. Every
		// build that records either records both, for file parts and database parts alike,
		// so a manifest carrying neither describes a backup taken before they existed. The
		// remedy is a fresh backup on the source, not an archive taken on trust.
		if ( $expected <= 0 && '' === $declared ) {
			self::discard( $target );

			return sprintf(
				/* translators: %s: backup part file name. */
				__( 'The part "%s" arrived with nothing to verify it against and was discarded. Take a fresh backup on the source site, then migrate again.', 'tailwatch' ),
				$part['file']
			);
		}

		// A silent truncation is what produces an unrestorable backup on this side.
		if ( $expected > 0 && (int) filesize( $target ) !== $expected ) {
			self::discard( $target );
			return sprintf(
				/* translators: %s: backup part file name. */
				__( 'The part "%s" arrived incomplete and was discarded.', 'tailwatch' ),
				$part['file']
			);
		}

		$digest = isset( $part['digest'] ) ? (string) $part['digest'] : '';
		if ( '' !== $digest && ! self::digest_matches( $target, $part ) ) {
			self::discard( $target );
			return sprintf(
				/* translators: %s: backup part file name. */
				__( 'The part "%s" did not match the fingerprint recorded for it and was discarded.', 'tailwatch' ),
				$part['file']
			);
		}

		return true;
	}

	/**
	 * Whether a landed part matches the fingerprint recorded for it.
	 *
	 * Archives are fingerprinted by their content index — the same digest the backup
	 * recorded at verification time, which is stable across re-zipping — and database
	 * dumps by sha256 of the file.
	 *
	 * @param string $path Local path.
	 * @param array  $part Manifest entry.
	 * @return bool
	 */
	private static function digest_matches( $path, array $part ) {
		if ( 'database' === $part['area'] ) {
			$hash = hash_file( 'sha256', $path );
			return is_string( $hash ) && hash_equals( (string) $part['digest'], $hash );
		}

		$digest = ArchiveReader::content_digest( $path );

		return is_string( $digest ) && hash_equals( (string) $part['digest'], $digest );
	}

	/**
	 * Remove a part that failed verification.
	 *
	 * @param string $path Local path.
	 * @return void
	 */
	private static function discard( $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * The parts a completion marker describes, in the shape a transfer moves them in.
	 *
	 * The inverse of what publish() writes, and it lives beside it for that reason:
	 * both ends of every migration read and write the same two shapes, and a reader
	 * that drifted from the writer would produce a manifest the destination verifies
	 * happily and cannot restore.
	 *
	 * @param array $marker A completion marker.
	 * @return array List of parts: area, file, size, digest.
	 */
	public static function parts_from_marker( array $marker ) {
		$parts = array();

		// The marker's per-area entries live under 'keys' — the same shape
		// RestoreValidator reads. Getting this name wrong returns a manifest that
		// silently omits every file part and lists only the database.
		foreach ( (array) ( $marker['keys'] ?? array() ) as $info ) {
			foreach ( (array) ( $info['part_files'] ?? array() ) as $part ) {
				if ( empty( $part['file'] ) ) {
					continue;
				}
				$parts[] = array(
					'area'   => 'files',
					'file'   => wp_basename( (string) $part['file'] ),
					'size'   => isset( $part['size'] ) ? (int) $part['size'] : 0,
					'digest' => isset( $part['digest'] ) ? (string) $part['digest'] : '',
				);
			}
		}

		foreach ( (array) ( $marker['database']['files'] ?? array() ) as $name ) {
			$base    = wp_basename( (string) $name );
			$digests = $marker['database']['file_digests'][ $base ] ?? array();
			$parts[] = array(
				'area'   => 'database',
				'file'   => $base,
				'size'   => isset( $digests['size'] ) ? (int) $digests['size'] : 0,
				'digest' => isset( $digests['sha256'] ) ? (string) $digests['sha256'] : '',
			);
		}

		return $parts;
	}

	/**
	 * Give the transferred folder a row, so the backup list can see it.
	 *
	 * A CATALOGUE entry and never a job. It shares a key with the live backup state, so it
	 * has to be impossible to mistake for one: `migrated` says what it is, `zipId` is empty
	 * because the parts were named on the site that made them, and `site_url` is that site.
	 * BackupStateTrait::tailwatch_backup_run_state() refuses a row on any of those three,
	 * and must go on doing so - a backup that adopts this row writes every part under the
	 * same name and never finishes.
	 *
	 * @param string $folder Backup folder name.
	 * @param array  $marker The marker just written.
	 * @return void
	 */
	private static function register_backup_row( $folder, array $marker ) {
		$model = new DBModel();

		// A re-run must not create a second row for the same folder.
		foreach ( (array) $model->get_log_value( 'default_backup_scan', 'scan_backp' ) as $row ) {
			$decoded = json_decode( (string) ( $row['value'] ?? '' ), true );
			if ( is_array( $decoded ) && (string) ( $decoded['folderDate'] ?? '' ) === $folder ) {
				return;
			}
		}

		$value = wp_json_encode(
			array(
				'completed'    => true,
				'scan_state'   => 'completed',
				'folderDate'   => $folder,
				'zipId'        => '',
				'site_url'     => (string) $marker['site_url'],
				'backupType'   => 'Complete Backup',
				'process_run'  => 'backup',
				'migrated'     => true,
				'started_time' => time(),
				'userData'     => array(
					'userName' => 'Migration',
					'userRole' => 'Migration',
					'scanType' => 'Migration',
				),
			)
		);

		if ( false === $value ) {
			return;
		}

		$model->insert_row(
			array(
				'user_id'       => '1',
				'child_of'      => '0',
				'key'           => 'default_backup_scan',
				'option'        => 'scan_backp',
				'value'         => $value,
				'type'          => 'backup_' . $folder,
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => true,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}
}
