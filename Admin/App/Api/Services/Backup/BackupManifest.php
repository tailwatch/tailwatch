<?php
/**
 * Tailwatch - Backup Manifest
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
use Tailwatch\Admin\App\Api\Services\ZipUtility\StreamZipWriter;
use Tailwatch\Admin\App\Api\Services\TickBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plans, writes and verifies the file side of a backup.
 *
 * The manifest is the contract between planning and packing: the tree is walked once
 * into a `relPath<TAB>size` sidecar, and every later stage resumes from a byte offset
 * into it rather than re-walking. Everything here is stateless — inputs are paths and
 * offsets, outputs are cursors and counts — which is what lets restore reuse the same
 * reading and verification logic without instantiating a backup controller.
 *
 * BackupController keeps thin wrappers around these methods, so its internal callers
 * and the test suite still address them through the controller.
 */
class BackupManifest {

	/**
	 * Maximum file names recorded per part when files are skipped during packing.
	 *
	 * Every skip is counted; only the names are capped, so the log stays useful on a
	 * normal run without growing without bound when a whole tree disappears mid-backup.
	 */
	const SKIP_SAMPLE_LIMIT = 25;

	/**
	 * Entries walked between two calls of a file-list build's progress callback.
	 */
	const PROGRESS_EVERY = 500;

	/**
	 * Tier-1 engine: walk a folder ONCE and stream an ordered manifest of the files to
	 * back up, so no later stage re-walks the tree (a full re-walk per part would be
	 * O(N^2)). Two newline-delimited sidecars are written atomically:
	 *  - $manifest_file: "relPath\tsize" for each file <= the size limit, in iteration
	 *    order. Read back by byte offset across ticks (resume cursor) and packed into
	 *    parts greedily — so num_parts here EQUALS the writer's part count by
	 *    construction (no planner/writer mismatch).
	 *  - $large_file_manifest: "relPath\tsize" for each file > the limit (zipped
	 *    one-per-tick on the single-file path).
	 * relPath is forward-slashed and relative to $folder_path; the writer prepends the
	 * key's path_prefix to form the in-zip path the restore expects.
	 *
	 * @param string        $folder_path         Folder root to walk.
	 * @param array         $exclude             Absolute paths to exclude (realpath prefix match).
	 * @param string        $manifest_file       Destination for the <=limit file list.
	 * @param string        $large_file_manifest Destination for the >limit file list.
	 * @param int           $zip_size_limit      Per-part byte limit (default 50MB).
	 * @param int           $scan_max_size       If >0, skip any file larger than this entirely (the
	 *                                           scan-backup cap for malware/integrity). 0 = no cap.
	 *                                           Sourced from a filter at the call site.
	 * @param int           $large_file_limit    Files over this go to the large-file list. 0 = the part limit.
	 * @param callable|null $on_progress         Called every PROGRESS_EVERY entries with the number of files
	 *                                           found so far. Returning false stops the walk: nothing is
	 *                                           written and array( 'aborted' => true ) is returned.
	 * @return array|false { files, large, total_size, num_parts, skipped_oversize } or false on failure.
	 */
	public static function tailwatch_build_backup_manifest( $folder_path, array $exclude, $manifest_file, $large_file_manifest, $zip_size_limit = 52428800, $scan_max_size = 0, $large_file_limit = 0, $on_progress = null ) {
		$large_file_limit = $large_file_limit > 0 ? (int) $large_file_limit : (int) $zip_size_limit;
		$exclude          = array_filter( array_map( 'realpath', $exclude ) );
		$on_progress      = is_callable( $on_progress ) ? $on_progress : null;

		$tmp  = $manifest_file . '.tmp';
		$ltmp = $large_file_manifest . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed write of a large cursor file; WP_Filesystem has no append/stream API; @ suppresses transient host warnings, false-check follows.
		$handle = @fopen( $tmp, 'wb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed write; see above.
		$lhandle = @fopen( $ltmp, 'wb' );
		if ( false === $handle || false === $lhandle ) {
			if ( false !== $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streamed cursor-file handle opened with fopen above; WP_Filesystem has no stream API.
				fclose( $handle );
			}
			if ( false !== $lhandle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streamed cursor-file handle opened with fopen above; WP_Filesystem has no stream API.
				fclose( $lhandle );
			}
			return false;
		}

		$stats     = array( 'files' => 0, 'large' => 0, 'total_size' => 0, 'num_parts' => 0, 'skipped_oversize' => 0, 'unreadable_dirs' => array(), 'linked_dirs' => array() );
		$part_size = 0;
		$root_len  = strlen( rtrim( str_replace( '\\', '/', $folder_path ), '/' ) ) + 1;
		$seen      = 0;
		$aborted   = false;

		try {
			self::$unreadable_dirs = array();
			self::$linked_dirs     = array();

			$dir_iterator = new \RecursiveDirectoryIterator( $folder_path, \RecursiveDirectoryIterator::SKIP_DOTS );

			// Applied unconditionally, not only when there are excludes: the filter also
			// prunes directories the walk cannot open, which would otherwise throw and fail
			// the entire backup.
			$dir_iterator = self::filtered_iterator( $dir_iterator, $exclude );

			$iterator = new \RecursiveIteratorIterator(
				$dir_iterator,
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				++$seen;
				if ( null !== $on_progress && 0 === $seen % self::PROGRESS_EVERY
					&& false === call_user_func( $on_progress, $stats['files'] + $stats['large'] + $stats['skipped_oversize'] ) ) {
					$aborted = true;
					break;
				}

				if ( ! $file->isFile() ) {
					continue; // intermediate dirs are recreated on extract from each file's path.
				}
				$abs = $file->getRealPath();
				if ( false === $abs ) {
					continue;
				}
				foreach ( $exclude as $skip_folder ) {
					// Match on a directory boundary, NOT a bare prefix: without the trailing
					// separator a sibling like "uploads-old" matches the excluded "uploads"
					// and gets silently dropped from the backup (data loss). $abs is always a
					// file under $folder_path, so it can never equal $skip_folder itself.
					if ( 0 === strpos( $abs, $skip_folder . DIRECTORY_SEPARATOR ) ) {
						continue 2;
					}
				}

				$size = (int) $file->getSize();

				// Scan-backup cap: drop oversize files entirely (not written to EITHER
				// manifest) so they never enter a backup part / upload / scan / restore —
				// every stage stays in lockstep. 0 = no cap (regular full backup). The cap
				// comes from a filter at the call site, so it can be changed or disabled
				// without touching this method.
				if ( $scan_max_size > 0 && $size > $scan_max_size ) {
					++$stats['skipped_oversize'];
					continue;
				}

				$rel  = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), $root_len ), '/' );
				$line = $rel . "\t" . $size . "\n";

				if ( $size > $large_file_limit ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed write; see fopen above.
					fwrite( $lhandle, $line );
					++$stats['large'];
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed write; see fopen above.
				fwrite( $handle, $line );
				++$stats['files'];
				$stats['total_size'] += $size;

				// Greedy bin count — MUST match the writer's packing so num_parts is exact.
				if ( $part_size > 0 && ( $part_size + $size ) > $zip_size_limit ) {
					++$stats['num_parts'];
					$part_size = 0;
				}
				$part_size += $size;
			}

			if ( $part_size > 0 ) {
				++$stats['num_parts'];
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
			fclose( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
			fclose( $lhandle );
			wp_delete_file( $tmp );
			wp_delete_file( $ltmp );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $lhandle );

		if ( $aborted ) {
			wp_delete_file( $tmp );
			wp_delete_file( $ltmp );
			return array( 'aborted' => true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish of the manifest; @ swallows host warnings, false return is checked.
		if ( ! @rename( $tmp, $manifest_file ) ) {
			wp_delete_file( $tmp );
			wp_delete_file( $ltmp );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish of the large-file manifest; @ swallows host warnings, false return is checked.
		if ( ! @rename( $ltmp, $large_file_manifest ) ) {
			wp_delete_file( $ltmp );
			return false;
		}

		$stats['unreadable_dirs'] = self::tailwatch_take_unreadable_dirs();
		$stats['linked_dirs']     = self::tailwatch_take_linked_dirs();

		return $stats;
	}

	/**
	 * Directories the walk could not open, from the run in progress.
	 *
	 * Static because the pruning filter is a closure inside an iterator and has nowhere else
	 * to record what it learns; taken and cleared by the builders around each walk.
	 *
	 * @var array<string>
	 */
	private static $unreadable_dirs = array();

	/**
	 * Symlinked directories the walk did not descend into, from the run in progress.
	 *
	 * @var array<string>
	 */
	private static $linked_dirs = array();

	/**
	 * Wrap a directory iterator so it prunes excluded AND unreadable subtrees.
	 *
	 * The unreadable half is the one that matters. `RecursiveDirectoryIterator` throws
	 * `UnexpectedValueException` the moment it cannot `opendir()` a subdirectory, and that
	 * throw propagates out of the walk — so one root-owned directory anywhere beneath an
	 * area would fail the manifest build on every retry and mark the ENTIRE backup failed,
	 * with a log that names the area but never the folder.
	 *
	 * An unreadable directory is therefore treated like an unreadable FILE: skipped,
	 * recorded by name, and carried through reconciliation into the completion marker so
	 * `restorable` tells the truth.
	 *
	 * Pruning here rather than setting `RecursiveIteratorIterator::CATCH_GET_CHILD` is
	 * deliberate. That flag also prevents the throw, but silently — and silence is what turns
	 * this from a reported gap into unnoticed data loss.
	 *
	 * @param \RecursiveDirectoryIterator $dir_iterator Iterator to wrap.
	 * @param array                      $exclude      Real paths to prune.
	 * @return \RecursiveCallbackFilterIterator
	 */
	private static function filtered_iterator( $dir_iterator, array $exclude ) {
		return new \RecursiveCallbackFilterIterator(
			$dir_iterator,
			static function ( $current ) use ( $exclude ) {
				if ( ! $current->isDir() ) {
					return true; // files are handled (and boundary-checked) in the loop.
				}

				$dir_real = $current->getRealPath();

				// Excluded subtrees are pruned BEFORE descending so we never enumerate the
				// contents of plugins/themes/uploads — each backed up under its own key —
				// while walking their parent.
				if ( false !== $dir_real && in_array( $dir_real, $exclude, true ) ) {
					return false;
				}

				// Descending into this would throw and take the whole run with it.
				if ( ! $current->isReadable() ) {
					self::$unreadable_dirs[] = ( false !== $dir_real ) ? $dir_real : $current->getPathname();
					return false;
				}

				// A symlinked directory NESTED inside an area is not descended into — the
				// iterator is deliberately built without FOLLOW_SYMLINKS, which is what makes
				// a directory cycle impossible. Each WP area is opened as its own root, so a
				// symlinked wp-content, plugins, themes or uploads IS followed and backed up;
				// this is only the rarer nested case, such as a single plugin symlinked to
				// shared storage.
				//
				// It is recorded rather than followed. Following would mean loop detection,
				// and following without it is what lets a self-referential link exhaust the
				// run. Recording it is what matters: otherwise such a directory is absent from
				// the manifest and from the skipped count, and the backup still reports itself
				// fully restorable.
				if ( $current->isLink() ) {
					self::$linked_dirs[] = $current->getPathname();
					return false;
				}

				return true;
			}
		);
	}

	/**
	 * Take and reset the directories the last walk could not open.
	 *
	 * @return array<string>
	 */
	public static function tailwatch_take_unreadable_dirs() {
		$dirs = array_values( array_unique( self::$unreadable_dirs ) );

		self::$unreadable_dirs = array();

		return $dirs;
	}

	/**
	 * Take and reset the symlinked directories the last walk did not descend into.
	 *
	 * @return array<string>
	 */
	public static function tailwatch_take_linked_dirs() {
		$dirs = array_values( array_unique( self::$linked_dirs ) );

		self::$linked_dirs = array();

		return $dirs;
	}

	/**
	 * Tier-1 engine: pack ONE zip part from the manifest, resuming at a byte offset
	 * instead of re-walking the tree (the O(N^2) cost). Reads files greedily from
	 * $offset until the next would overflow the size limit (matching the manifest's
	 * bin boundaries), adds each as $path_prefix + '/' + relPath (the in-zip layout
	 * restore expects), closes, and returns the cursor for the next part. A file that
	 * vanished since the manifest was built is skipped but its size is still counted,
	 * so part boundaries stay aligned with the manifest's bin count.
	 *
	 * @param string $manifest_file  Manifest sidecar (relPath\tsize lines).
	 * @param int    $offset         Byte offset to resume from (0 = first part).
	 * @param string $folder_path    Folder root the rel paths are under.
	 * @param string $path_prefix    In-zip prefix for this key (e.g. 'wp-content/plugins', '' for root).
	 * @param string $destination    Zip file to (over)write for this part.
	 * @param int    $zip_size_limit Per-part byte limit (default 50MB).
	 * @param int    $deadline       Unix timestamp to stop filling at (0 = no limit). The
	 *                               part is closed short at a file boundary, so parts can
	 *                               outnumber the manifest's plan — callers must therefore
	 *                               decide completion from 'eof', never from a part count.
	 * @return array|false { next_offset, added, part_size, eof, closed } or false on open failure.
	 */
	public static function tailwatch_pack_part_from_manifest( $manifest_file, $offset, $folder_path, $path_prefix, $destination, $zip_size_limit = 52428800, $deadline = 0 ) {
		if ( ! file_exists( $manifest_file ) ) {
			return false;
		}
		// Prefer ZipArchive (the zip extension); fall back to WordPress-bundled PclZip
		// (pure PHP, needs only zlib) when the zip extension is disabled, so backups still
		// complete. Both write a standard .zip that our restore already reads.
		$use_ziparchive = class_exists( 'ZipArchive' );
		if ( ! $use_ziparchive && ! ArchiveReader::ensure_pclzip() ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- partial sequential read of a large cursor file; WP_Filesystem cannot seek; @ swallows host warnings, false-check follows.
		$handle = @fopen( $manifest_file, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$zip = null;
		if ( $use_ziparchive ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $handle );
				return false;
			}
		}

		$folder_root = rtrim( str_replace( '\\', '/', $folder_path ), '/' );
		$prefix      = '' === $path_prefix ? '' : rtrim( $path_prefix, '/' ) . '/';
		$part_size      = 0;
		$added          = 0;
		$skipped        = 0;
		$skipped_sample = array();
		$eof            = false;
		$pcl_groups     = array(); // PclZip only: [source_dir][in_zip_dir][] = abs path.

		while ( true ) {
			$line_pos = ftell( $handle );
			$line = fgets( $handle );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$tab = strrpos( $line, "\t" );
			if ( false === $tab ) {
				continue;
			}
			$rel  = substr( $line, 0, $tab );
			$size = (int) substr( $line, $tab + 1 );

			// Out of tick budget: close a short part here rather than running past the
			// host's execution limit and being killed mid-write. Checking only between parts
			// would let one full-size part overrun arbitrarily, and on a host slow enough that
			// no part finishes in a tick the no-progress guard would abort the whole backup.
			// A short part keeps the cursor moving, so such a host completes with more,
			// smaller parts.
			//
			// $added > 0 matters: breaking on the first file would write an empty part,
			// leave the cursor where it was, and stall exactly like the case above.
			//
			// Memory counts as spent budget too. A part accumulates a ZipArchive entry list
			// that grows with the file count, and an out-of-memory fatal here does not close
			// the zip at all — so the part is discarded, the cursor never moves, and the
			// no-progress guard aborts a backup that only needed a shorter part.
			if ( $added > 0 && TickBudget::spent( $deadline ) ) {
				fseek( $handle, $line_pos );
				break;
			}

			// A file that would overflow a non-empty part belongs to the NEXT part —
			// rewind so the next call starts exactly at it (same bins as the manifest).
			if ( $part_size > 0 && ( $part_size + $size ) > $zip_size_limit ) {
				fseek( $handle, $line_pos );
				break;
			}

			$abs      = $folder_root . '/' . $rel;
			$readable = is_file( $abs ) && is_readable( $abs );
			// ZipArchive turns down a path it cannot open (on Windows, one longer than 260
			// characters). Counting it as added would leave the closed part short and fail
			// verification; it is a skipped file like an unreadable one.
			$archived = $readable && ( ! $use_ziparchive || $zip->addFile( $abs, $prefix . $rel ) );
			if ( $archived ) {
				if ( ! $use_ziparchive ) {
					// Group by (source dir, in-zip dir) so PclZip's REMOVE_PATH/ADD_PATH
					// reproduce the exact in-zip path as ZipArchive's addFile().
					$in_zip = $prefix . $rel;
					$pcl_groups[ dirname( $abs ) ][ dirname( $in_zip ) ][] = $abs;
				}
				++$added;
			} else {
				// Present when the manifest was built, gone or unreadable now. The part still
				// closes as a structurally valid zip and passes the validity check, so without
				// this count a backup missing arbitrarily many files would report a clean
				// success. Count every one, and keep a bounded sample of names so the log
				// stays useful without unbounded growth on a pathological run.
				++$skipped;
				if ( count( $skipped_sample ) < self::SKIP_SAMPLE_LIMIT ) {
					if ( $readable ) {
						$skipped_sample[] = $rel . ' (could not be archived)';
					} else {
						$skipped_sample[] = $rel . ( is_file( $abs ) ? ' (unreadable)' : ' (missing)' );
					}
				}
			}
			$part_size += $size; // count even if missing, to keep boundaries aligned.
		}

		$next_offset = ftell( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );
		$closed = $use_ziparchive ? $zip->close() : self::tailwatch_pclzip_write_part( $destination, $pcl_groups );

		return array(
			'next_offset'    => (int) $next_offset,
			'added'          => $added,
			'skipped'        => $skipped,
			'skipped_sample' => $skipped_sample,
			'part_size'      => $part_size,
			'eof'            => $eof,
			'closed'         => (bool) $closed,
		);
	}

	/**
	 * Pack one part, continuing it across as many ticks as it takes.
	 *
	 * The difference from tailwatch_pack_part_from_manifest(), which the scan-driven runs
	 * use:
	 *
	 * That one writes a whole part per call. When the tick deadline arrives it CLOSES a
	 * short part and moves the cursor on, so a slow host produces MORE parts than a fast
	 * one for the same site, and the step-down then shrinks the limit further - leaving
	 * many more files to carry by hand.
	 *
	 * Here the deadline PAUSES the part. The next tick continues the same archive, and the
	 * same entry if one was mid-write, so a part reaches its full size whatever the host's
	 * speed. The file count does not depend on how slow the server is.
	 *
	 * Nothing is held in memory: StreamZipWriter moves 512KB at a time and there is no
	 * close() that has to re-process the archive, so no 2x disk and no time cliff.
	 *
	 * @param string $manifest_file  Manifest sidecar (relPath<TAB>size lines).
	 * @param int    $offset         Byte offset into the manifest to read from.
	 * @param string $folder_path    Folder root the relative paths are under.
	 * @param string $path_prefix    In-zip prefix for this key.
	 * @param string $destination    Archive to write.
	 * @param int    $zip_size_limit Bytes of source content per part.
	 * @param int    $entry_limit    Entries per part. Keeps the central directory small and
	 *                               stays under ZIP's 16-bit entry count, so no Zip64.
	 * @param int    $deadline       Unix time to stop at; 0 for no limit.
	 * @param array  $resume         State returned by a previous call that paused.
	 * @return array|false
	 */
	public static function tailwatch_stream_part_from_manifest( $manifest_file, $offset, $folder_path, $path_prefix, $destination, $zip_size_limit = 52428800, $entry_limit = 50000, $deadline = 0, array $resume = array() ) {
		if ( ! file_exists( $manifest_file ) ) {
			return false;
		}

		$resuming = ! empty( $resume );
		$writer   = new StreamZipWriter( $destination );
		$opened   = $writer->open( $resuming );
		if ( true !== $opened ) {
			return false;
		}

		$part_size      = $resuming && isset( $resume['part_size'] ) ? (int) $resume['part_size'] : 0;
		$added          = $resuming && isset( $resume['added'] ) ? (int) $resume['added'] : 0;
		$skipped        = $resuming && isset( $resume['skipped'] ) ? (int) $resume['skipped'] : 0;
		$skipped_sample = array();
		$eof            = false;

		// Counted for THIS call only. The guard below exists to guarantee every call makes
		// progress, and $added carries over from earlier ticks - so using it would be true
		// on entry to a resumed call and pause before doing anything, every time, for ever.
		$moved = 0;

		$pause = function ( $cursor, $entry ) use ( $writer, &$part_size, &$added, &$skipped, &$skipped_sample ) {
			$writer->pause();

			return array(
				'next_offset'    => (int) $cursor,
				'added'          => $added,
				'skipped'        => $skipped,
				'skipped_sample' => $skipped_sample,
				'part_size'      => $part_size,
				'eof'            => false,
				'closed'         => false,
				'part_open'      => true,
				'resume'         => array(
					'part_size' => $part_size,
					'added'     => $added,
					'skipped'   => $skipped,
					'entry'     => $entry,
				),
			);
		};

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- partial sequential read of a large cursor file; WP_Filesystem cannot seek.
		$handle = @fopen( $manifest_file, 'rb' );
		if ( false === $handle ) {
			$writer->pause();
			return false;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		// An entry that was mid-write when the last tick ended. Its manifest line is already
		// behind the cursor, so it is finished here before any new line is read.
		if ( $resuming && ! empty( $resume['entry'] ) ) {
			$open_entry = $resume['entry'];

			// The previous tick was killed outright and never recorded where it had reached,
			// so the part-written bytes cannot be trusted. Start that entry again.
			if ( ! $writer->partial_survived() ) {
				$open_entry['offset'] = 0;
				$open_entry['crc']    = 0;
			}

			$state = $writer->add_file(
				$open_entry['source'],
				$open_entry['name'],
				(int) $open_entry['offset'],
				(int) $open_entry['crc'],
				$deadline
			);

			if ( '' !== $state['error'] ) {
				// It was readable when the part started and is not now. Counted like any
				// other skip rather than failing a part whose other contents are sound.
				++$skipped;
				++$moved;
				$skipped_sample[] = $open_entry['name'] . ' (vanished mid-part)';
			} elseif ( ! $state['done'] ) {
				$open_entry['offset'] = $state['offset'];
				$open_entry['crc']    = $state['crc'];
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $handle );
				return $pause( $offset, $open_entry );
			} else {
				++$added;
				++$moved;
			}
		}

		while ( true ) {
			$line_pos = ftell( $handle );
			$line     = fgets( $handle );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$tab = strrpos( $line, "\t" );
			if ( false === $tab ) {
				continue;
			}
			$rel  = substr( $line, 0, $tab );
			$size = (int) substr( $line, $tab + 1 );

			// Out of budget before starting another file: pause rather than close short.
			// The part continues next tick, which is the whole point of this method.
			if ( $moved > 0 && TickBudget::spent( $deadline ) ) {
				fseek( $handle, $line_pos );
				$cursor = ftell( $handle );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $handle );
				return $pause( $cursor, null );
			}

			// Full by bytes or by entry count. The entry cap keeps the central directory
			// small on restore and keeps us under ZIP's 16-bit count, so Zip64 is never
			// needed and the archive opens in any tool.
			$full = ( $part_size > 0 && ( $part_size + $size ) > $zip_size_limit )
				|| ( $entry_limit > 0 && $added >= $entry_limit );
			if ( $full ) {
				fseek( $handle, $line_pos );
				break;
			}

			$abs   = rtrim( str_replace( chr( 92 ), '/', $folder_path ), '/' ) . '/' . $rel;
			$name  = ( '' === $path_prefix ? '' : rtrim( $path_prefix, '/' ) . '/' ) . $rel;
			$state = $writer->add_file( $abs, $name, 0, 0, $deadline );

			if ( '' !== $state['error'] ) {
				++$skipped;
				++$moved;
				if ( count( $skipped_sample ) < self::SKIP_SAMPLE_LIMIT ) {
					$reason           = 'too_large' === $state['error'] ? ' (4 GB or larger)' : ( is_file( $abs ) ? ' (unreadable)' : ' (missing)' );
					$skipped_sample[] = $rel . $reason;
				}
			} elseif ( ! $state['done'] ) {
				// The deadline landed inside this file. Its line is already consumed, so the
				// entry travels in the resume state instead of the cursor.
				$cursor = ftell( $handle );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $handle );
				$part_size += $size;

				return $pause(
					$cursor,
					array(
						'name'   => $name,
						'source' => $abs,
						'offset' => $state['offset'],
						'crc'    => $state['crc'],
					)
				);
			} else {
				++$added;
				++$moved;
			}

			$part_size += $size; // Counted even when missing, so part boundaries stay aligned.
		}

		$next_offset = ftell( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );

		$writer->close();

		return array(
			'next_offset'    => (int) $next_offset,
			'added'          => $added,
			'skipped'        => $skipped,
			'skipped_sample' => $skipped_sample,
			'part_size'      => $part_size,
			'eof'            => $eof,
			'closed'         => true,
			'part_open'      => false,
			'resume'         => array(),
		);
	}

	/**
	 * Reconcile one finished part against the manifest slice it was packed from.
	 *
	 * A part can close as a structurally valid zip while holding fewer files than
	 * intended, so zip validity has never been evidence of completeness. This reads
	 * back the exact manifest range the packer consumed and compares it to the
	 * archive's own index.
	 *
	 * Two outcomes are deliberately weighted differently:
	 *
	 * - a COUNT mismatch fails the part — files were intended and are not there
	 * - a SIZE mismatch only warns — a file that legitimately grew or shrank between
	 *   the manifest walk and packing (an active log, a cache file) is still archived
	 *   correctly, just at a different size, and failing a backup for that would be
	 *   wrong
	 *
	 * @param string $zip_path       Finished part.
	 * @param string $manifest_file  Manifest sidecar.
	 * @param int    $start          Byte offset the part started at.
	 * @param int    $end            Byte offset the packer stopped at.
	 * @param string $path_prefix    In-zip prefix for this key.
	 * @param int    $expected_added Files the packer reported adding.
	 * @return array{ok:bool,entries:int,expected:int,drift:int,error:string}
	 */
	public static function tailwatch_verify_part_against_manifest( $zip_path, $manifest_file, $start, $end, $path_prefix, $expected_added ) {
		$out = array(
			'ok'             => false,
			'entries'        => 0,
			'expected'       => 0,
			'drift'          => 0,
			'missing'        => 0,
			'missing_sample' => array(),
			'digest'         => '',
			'error'          => '',
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- ranged read of the manifest cursor file; WP_Filesystem cannot seek.
		$handle = @fopen( $manifest_file, 'rb' );
		if ( false === $handle ) {
			$out['error'] = 'manifest unreadable';
			return $out;
		}
		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$prefix = '' === $path_prefix ? '' : rtrim( $path_prefix, '/' ) . '/';
		$wanted = array();
		while ( ftell( $handle ) < $end ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$tab = strrpos( $line, "\t" );
			if ( false === $tab ) {
				continue;
			}
			$wanted[ $prefix . substr( $line, 0, $tab ) ] = (int) substr( $line, $tab + 1 );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );

		$out['expected'] = count( $wanted );

		$actual = ArchiveReader::read_index( $zip_path );
		if ( null === $actual ) {
			$out['error'] = 'archive unreadable';
			return $out;
		}
		$out['entries'] = count( $actual );

		// MORE entries than the packer added means this is not the part it thinks it is.
		// FEWER is the case this has to survive: the archive library can accept a file and
		// still leave it out when the part is closed — on Windows, a path past its
		// 260-character limit — and that loss is counted below instead of failing a part
		// whose contents are otherwise sound.
		if ( $out['entries'] > (int) $expected_added ) {
			$out['error'] = "archive holds {$out['entries']} entries, packer reported {$expected_added}";
			return $out;
		}

		foreach ( $actual as $name => $size ) {
			if ( ! array_key_exists( $name, $wanted ) ) {
				$out['error'] = 'archive holds an entry not present in the manifest slice: ' . $name;
				return $out;
			}
			if ( $wanted[ $name ] !== $size ) {
				++$out['drift'];
			}
		}

		// What the slice described and the finished part does not hold, by name, so the
		// caller can count it as a skip and say which files the site does not have a copy of.
		$prefix_length = strlen( $prefix );
		foreach ( array_keys( $wanted ) as $name ) {
			if ( array_key_exists( $name, $actual ) ) {
				continue;
			}
			++$out['missing'];
			if ( count( $out['missing_sample'] ) < self::SKIP_SAMPLE_LIMIT ) {
				$out['missing_sample'][] = substr( $name, $prefix_length );
			}
		}

		// Fingerprint the finished part so a restore can prove its CONTENTS are the ones
		// this run wrote, not merely that it holds the right number of entries.
		$digest = ArchiveReader::content_digest( $zip_path );
		if ( is_string( $digest ) ) {
			$out['digest'] = $digest;
		}

		$out['ok'] = true;
		return $out;
	}

	/**
	 * Write a backup part with PclZip — fallback when the zip extension is off.
	 * Adds files per (source dir, in-zip dir) group via REMOVE_PATH/ADD_PATH so the in-zip
	 * layout is identical to the ZipArchive path; restore stays byte-compatible. An empty
	 * part (all files missing) writes no file — the caller's zip-validity check handles it.
	 *
	 * @param string $destination Part zip path to (over)write.
	 * @param array  $groups      [source_dir][in_zip_dir][] = abs path.
	 * @return bool True on success.
	 */
	public static function tailwatch_pclzip_write_part( $destination, $groups ) {
		if ( ! ArchiveReader::ensure_pclzip() ) {
			return false;
		}
		// PclZip's add() appends; start from a clean file so a resumed/retried part is not doubled.
		if ( file_exists( $destination ) ) {
			wp_delete_file( $destination );
		}
		$pclzip = new \PclZip( $destination );
		foreach ( $groups as $source_dir => $in_zip_dirs ) {
			foreach ( $in_zip_dirs as $in_zip_dir => $files ) {
				$res = ( '' === $in_zip_dir || '.' === $in_zip_dir )
					? $pclzip->add( $files, PCLZIP_OPT_REMOVE_PATH, $source_dir )
					: $pclzip->add( $files, PCLZIP_OPT_REMOVE_PATH, $source_dir, PCLZIP_OPT_ADD_PATH, $in_zip_dir );
				if ( 0 === $res ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Load the large-file manifest into the {realpath,pathname,filename,size} entry
	 * shape the large-file loop + create_single_file_zip() consume, so the manifest
	 * engine shares that path. Usually a small list (few >50MB files).
	 *
	 * @param string $large_manifest Large-file manifest sidecar (relPath\tsize lines).
	 * @param string $folder_path    Folder the rel paths are under.
	 * @return array List of large-file entries.
	 */
	public static function tailwatch_load_large_files_from_manifest( $large_manifest, $folder_path ) {
		$out = array();
		if ( ! file_exists( $large_manifest ) ) {
			return $out;
		}
		$root  = rtrim( str_replace( '\\', '/', $folder_path ), '/' );
		$lines = file( $large_manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		foreach ( (array) $lines as $line ) {
			$tab = strrpos( $line, "\t" );
			if ( false === $tab ) {
				continue;
			}
			$rel = substr( $line, 0, $tab );
			$abs = $root . '/' . $rel;
			$out[] = array(
				'realpath' => $abs,
				'pathname' => $abs,
				'filename' => basename( $rel ),
				'size'     => (int) substr( $line, $tab + 1 ),
			);
		}
		return $out;
	}
}
