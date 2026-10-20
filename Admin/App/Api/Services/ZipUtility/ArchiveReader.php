<?php
/**
 * Tailwatch - Archive Reader
 *
 * Engine selection and read-side access for backup archives.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\ZipUtility
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\ZipUtility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads archives without caring which engine wrote them.
 *
 * Backups are written with ZipArchive when the zip extension is present and with
 * WordPress-bundled PclZip when it is not, so every reader has to handle both. Keeping
 * that selection — plus the PclZip bootstrap — in one place stops a fallback path from
 * being fixed in one copy and not the others.
 *
 * Read-only by design: writing stays with the callers that own their formats
 * (ZipCreation, the backup part packer), so this can be reused by restore and
 * verification without dragging write concerns along.
 */
class ArchiveReader {

	/**
	 * Engine identifiers returned by engine().
	 */
	const ENGINE_ZIPARCHIVE = 'ziparchive';
	const ENGINE_PCLZIP     = 'pclzip';
	const ENGINE_NONE       = 'none';

	/**
	 * Which archive engine is usable in this request.
	 *
	 * @return string One of the ENGINE_* constants.
	 */
	public static function engine() {
		if ( class_exists( 'ZipArchive' ) ) {
			return self::ENGINE_ZIPARCHIVE;
		}
		if ( self::ensure_pclzip() ) {
			return self::ENGINE_PCLZIP;
		}
		return self::ENGINE_NONE;
	}

	/**
	 * Whether any archive engine is available at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return self::ENGINE_NONE !== self::engine();
	}

	/**
	 * Load WordPress-bundled PclZip if it is not already present.
	 *
	 * @return bool True when PclZip can be used.
	 */
	public static function ensure_pclzip() {
		if ( class_exists( 'PclZip' ) ) {
			return true;
		}
		$bundled = ABSPATH . 'wp-admin/includes/class-pclzip.php';
		if ( file_exists( $bundled ) ) {
			require_once $bundled;
		}
		return class_exists( 'PclZip' );
	}

	/**
	 * Read an archive's entry index: in-zip name => uncompressed size.
	 *
	 * Reads only the central directory, so the cost is a seek and a small read no matter
	 * how large the archive is — nothing is decompressed. Directory entries are excluded
	 * so counts are comparable against a manifest, which lists files only.
	 *
	 * @param string $path Archive to read.
	 * @return array<string,int>|null Name => size, or null when the archive cannot be read.
	 */
	public static function read_index( $path ) {
		if ( '' === (string) $path || ! file_exists( $path ) ) {
			return null;
		}

		if ( class_exists( 'ZipArchive' ) ) {
			return self::read_index_ziparchive( $path );
		}
		if ( self::ensure_pclzip() ) {
			return self::read_index_pclzip( $path );
		}
		return null;
	}

	/**
	 * Count file entries in an archive, INCLUDING duplicate names.
	 *
	 * read_index() keys by entry name, so duplicates collapse into one. Zip permits
	 * repeated names and extraction lets the later entry win, which means a duplicate
	 * can replace the content of a legitimate file while leaving the indexed entry
	 * count unchanged. Comparing this against count(read_index()) is what makes that
	 * visible.
	 *
	 * @param string $path Archive to count.
	 * @return int|null Entry count, or null when the archive cannot be read.
	 */
	public static function count_entries( $path ) {
		if ( '' === (string) $path || ! file_exists( $path ) ) {
			return null;
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return null;
			}
			$count = 0;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false !== $stat && '/' !== substr( $stat['name'], -1 ) ) {
					++$count;
				}
			}
			$zip->close();
			return $count;
		}

		if ( ! self::ensure_pclzip() ) {
			return null;
		}
		$list = ( new \PclZip( $path ) )->listContent();
		if ( ! is_array( $list ) ) {
			return null;
		}
		$count = 0;
		foreach ( $list as $entry ) {
			if ( empty( $entry['folder'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Fingerprint an archive's contents from its central directory.
	 *
	 * Entry counts prove an archive has the right SHAPE; they say nothing about its
	 * contents. A file swapped for another of the same name leaves the count, the names
	 * and even the sizes untouched. Zip already stores a CRC32 per entry, so a digest
	 * over (name, size, crc) detects that for the cost of reading the central directory
	 * — no decompression, no re-reading the source files.
	 *
	 * Built from a sorted LIST rather than a name-keyed map, so entry order cannot change
	 * the result and duplicate names contribute twice instead of collapsing.
	 *
	 * @param string $path Archive to fingerprint.
	 * @return string|null Hex digest, or null when the archive cannot be read.
	 */
	public static function content_digest( $path ) {
		$entries = self::read_entries( $path );
		if ( null === $entries ) {
			return null;
		}

		$lines = array();
		foreach ( $entries as $entry ) {
			$lines[] = $entry['name'] . "\0" . $entry['size'] . "\0" . $entry['crc'];
		}
		sort( $lines, SORT_STRING );

		return hash( 'sha256', implode( "\n", $lines ) );
	}

	/**
	 * Read every file entry as an ordered list.
	 *
	 * Each element carries the engine's OWN entry index alongside the name. That matters
	 * for anything that later addresses an entry by index: directory entries are excluded
	 * here, and PclZip archives built from a directory contain them, so a position in
	 * this list is not the same number as the archive's index.
	 *
	 * @param string $path Archive to read.
	 * @return array<int,array{index:int,name:string,size:int,crc:int}>|null
	 */
	public static function read_entries( $path ) {
		if ( '' === (string) $path || ! file_exists( $path ) ) {
			return null;
		}

		$entries = array();

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return null;
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false === $stat || '/' === substr( $stat['name'], -1 ) ) {
					continue;
				}
				$entries[] = array(
					'index' => $i,
					'name'  => (string) $stat['name'],
					'size'  => (int) $stat['size'],
					'crc'   => (int) $stat['crc'],
				);
			}
			$zip->close();
			return $entries;
		}

		if ( ! self::ensure_pclzip() ) {
			return null;
		}
		$list = ( new \PclZip( $path ) )->listContent();
		if ( ! is_array( $list ) ) {
			return null;
		}
		foreach ( $list as $entry ) {
			if ( ! empty( $entry['folder'] ) ) {
				continue;
			}
			$entries[] = array(
				// PclZip's own index, not our position: folder entries are skipped above
				// and archives built from a directory contain them.
				'index' => isset( $entry['index'] ) ? (int) $entry['index'] : count( $entries ),
				'name'  => (string) $entry['stored_filename'],
				'size'  => (int) $entry['size'],
				'crc'   => isset( $entry['crc'] ) ? (int) $entry['crc'] : 0,
			);
		}
		return $entries;
	}

	/**
	 * Read an index via the zip extension.
	 *
	 * @param string $path Archive path.
	 * @return array<string,int>|null
	 */
	private static function read_index_ziparchive( $path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}

		$index = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			if ( '/' === substr( $stat['name'], -1 ) ) {
				continue; // Directory entry.
			}
			$index[ $stat['name'] ] = (int) $stat['size'];
		}
		$zip->close();

		return $index;
	}

	/**
	 * Read an index via PclZip.
	 *
	 * @param string $path Archive path.
	 * @return array<string,int>|null
	 */
	private static function read_index_pclzip( $path ) {
		$archive = new \PclZip( $path );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			return null;
		}

		$index = array();
		foreach ( $list as $entry ) {
			if ( ! empty( $entry['folder'] ) ) {
				continue;
			}
			$index[ $entry['stored_filename'] ] = (int) $entry['size'];
		}

		return $index;
	}
}
