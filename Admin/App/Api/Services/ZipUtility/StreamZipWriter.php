<?php
/**
 * Write a standard ZIP archive incrementally, across as many requests as it takes.
 *
 * @package Tailwatch
 */

namespace Tailwatch\Admin\App\Api\Services\ZipUtility;

defined( 'ABSPATH' ) || exit;

/**
 * ## Why this exists
 *
 * `ZipArchive::addFile()` only registers a NAME. libzip reads, compresses and writes every
 * queued file inside `close()`, so the whole part has to finish within one
 * `max_execution_time`, it cannot be resumed, and libzip writes a temp copy alongside
 * needing 2x the part size free. That caps the part size and turns a large site into
 * many files to carry by hand.
 *
 * Here every byte is written as it is read. Peak memory is one block. A tick that runs out
 * of time records a byte offset and the next one continues the same entry. There is no
 * close() cost, so there is no ceiling forcing small parts.
 *
 * ## Why still ZIP
 *
 * Because a customer must be able to recover without this plugin. A format of our own
 * would buy one-file convenience at the price of needing our software to read it. A `.zip`
 * opens in any tool on any OS, and our own ArchiveReader already reads it on hosts with
 * or without ext-zip.
 *
 * ## How an entry can be written before its size is known
 *
 * General-purpose bit 3. The local header carries zeros for CRC and both sizes, and the
 * real values follow the data in a data descriptor. That is what makes streaming possible.
 *
 * ## Resuming an entry across requests
 *
 * A hash context cannot be serialised between PHP requests, so a CRC cannot simply be
 * carried on. Each tick hashes only its own slice and the partial CRCs are combined
 * mathematically - the same combination zlib's crc32_combine performs.
 *
 * DEFLATE cannot be resumed at all: the compressor holds a sliding window that does not
 * survive the request. So anything large enough to span a tick is STORED, and only entries
 * that finish inside one tick are deflated. The cost is near zero because large files on a
 * WordPress site are overwhelmingly media, which does not compress.
 *
 * ## No extension required
 *
 * fopen/fwrite, plus zlib's deflate_init when it is available. Hosts without ext-zip -
 * which elsewhere fall back to PclZip - are served by the same path as everyone else.
 */
class StreamZipWriter {

	/**
	 * Bytes moved per read/write. The only memory this class holds.
	 */
	const BLOCK = 524288;

	/**
	 * ZIP compression methods.
	 */
	const METHOD_STORE   = 0;
	const METHOD_DEFLATE = 8;

	/**
	 * Above this, an entry is STORED rather than deflated.
	 *
	 * Not a tuning choice: a deflate stream cannot be resumed across requests, so anything
	 * that might span a tick has to be stored for its resume to be byte-exact.
	 *
	 * The two halves of that rule work together - a deflated entry is never interrupted
	 * (see add_file), so this is also the most a tick can overrun by, which is a fraction
	 * of a second.
	 */
	const DEFLATE_MAX_BYTES = 8388608;

	/**
	 * Extensions whose contents are already compressed. Deflating them costs CPU for
	 * nothing and occasionally makes them larger.
	 */
	const STORED_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff',
		'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'ogg', 'ogv', 'wav', 'flac',
		'zip', 'gz', 'bz2', 'xz', '7z', 'rar', 'tgz',
		'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx',
	);

	/**
	 * Signatures.
	 */
	const SIG_LOCAL      = 0x04034b50;
	const SIG_DESCRIPTOR = 0x08074b50;
	const SIG_CENTRAL    = 0x02014b50;
	const SIG_EOCD       = 0x06054b50;

	/**
	 * Absolute path of the archive being written.
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Write handle, held open for the life of one tick.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Completed entries, each one central-directory ready.
	 *
	 * @var array<int,array>
	 */
	private $entries = array();

	/**
	 * Where the central directory begins - the first byte after the last entry's data.
	 *
	 * A tick ends by writing the directory here so the archive on disk always opens. The
	 * next tick truncates back to this offset before appending, which removes that
	 * directory and any partial entry written after it in one step.
	 *
	 * @var int
	 */
	private $cd_offset = 0;

	/**
	 * Sidecar path. Holds one JSON object per completed entry.
	 *
	 * Append-only so a crash mid-write costs at most the last line, beside the archive so
	 * it cannot drift from it, and removed once the archive is finished.
	 *
	 * @var string
	 */
	private $sidecar = '';

	/**
	 * Bind the writer to an archive path.
	 *
	 * @param string $path Absolute path of the archive to write.
	 */
	public function __construct( $path ) {
		$this->path    = (string) $path;
		$this->sidecar = $this->path . '.twidx';
	}

	/**
	 * Open the archive, continuing an existing one when asked.
	 *
	 * @param bool $resume Continue the archive already on disk.
	 * @return true|string True, or why it could not be opened.
	 */
	public function open( $resume = false ) {
		if ( $resume && file_exists( $this->path ) ) {
			$loaded = $this->load_sidecar();
			if ( is_string( $loaded ) ) {
				return $loaded;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- byte-exact append to a partially written archive; WP_Filesystem cannot seek or truncate.
			$handle = @fopen( $this->path, 'r+b' );
			if ( false === $handle ) {
				return 'archive not writable';
			}

			// Everything after the last complete entry is the previous tick's central
			// directory, and possibly a partial entry. Both go.
			if ( ! ftruncate( $handle, $this->cd_offset ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $handle );
				return 'archive could not be trimmed for resume';
			}
			fseek( $handle, $this->cd_offset );
			$this->handle = $handle;

			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed archive write; WP_Filesystem has no stream API.
		$handle = @fopen( $this->path, 'wb' );
		if ( false === $handle ) {
			return 'archive not writable';
		}

		$this->handle    = $handle;
		$this->entries    = array();
		$this->cd_offset  = 0;
		$this->entry_open = false;

		if ( file_exists( $this->sidecar ) ) {
			wp_delete_file( $this->sidecar );
		}

		return true;
	}

	/**
	 * Add one file, for as long as the deadline allows.
	 *
	 * Call again with the returned offset and crc to continue the same entry.
	 *
	 * @param string $source     Absolute path to read.
	 * @param string $entry_name Name inside the archive; always forward slashes.
	 * @param int    $offset     Bytes of the source already written.
	 * @param int    $crc        CRC of those bytes; 0 on the first call.
	 * @param int    $deadline   Unix time to stop at; 0 for no limit.
	 * @return array{done:bool,offset:int,crc:int,error:string}
	 */
	public function add_file( $source, $entry_name, $offset = 0, $crc = 0, $deadline = 0 ) {
		$out = array(
			'done'   => false,
			'offset' => (int) $offset,
			'crc'    => (int) $crc,
			'error'  => '',
		);

		$size = @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that vanished mid-backup is skipped, not fatal.
		if ( false === $size ) {
			$out['error'] = 'unreadable';
			return $out;
		}

		// Entries carry 32-bit sizes and offsets (no ZIP64), so a file that is 4 GB or more,
		// or whose entry would end past 4 GB in the archive, would corrupt it. It is refused
		// before its header is written and reported as skipped. A resumed entry was checked
		// on the tick that started it.
		$ends_at = 0 === (int) $offset && is_resource( $this->handle )
			? (int) ftell( $this->handle ) + 30 + strlen( $entry_name ) + $size + 16
			: 0;
		if ( $size >= 0xFFFFFFFF || $ends_at >= 0xFFFFFFFF ) {
			$out['error'] = 'too_large';
			return $out;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed read of a backup source; WP_Filesystem cannot seek.
		$in = @fopen( $source, 'rb' );
		if ( false === $in ) {
			$out['error'] = 'unreadable';
			return $out;
		}

		$method = $this->method_for( $source, $size );

		// The header goes down once, on the tick that starts the entry. Its offset is what
		// the central directory will point at.
		if ( 0 === $offset ) {
			$this->entry_start = ftell( $this->handle );
			$this->write( $this->local_header( $entry_name, $method, @filemtime( $source ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable mtime falls back to now.
			$this->entry_compressed = 0;
			$this->entry_open       = true;
		}

		if ( $offset > 0 && -1 === fseek( $in, $offset ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
			fclose( $in );
			$out['error'] = 'could not resume read';
			return $out;
		}

		$deflate = null;
		if ( self::METHOD_DEFLATE === $method ) {
			// Only reached for entries that fit in one tick, so the context never has to
			// survive a request - which it could not.
			$deflate = deflate_init( ZLIB_ENCODING_RAW );
		}

		$written = (int) $offset;

		while ( $written < $size ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming read; see fopen above.
			$block = fread( $in, self::BLOCK );
			if ( false === $block || '' === $block ) {
				break;
			}

			// Each tick hashes only its own slice; the partial results are combined below,
			// because a hash context cannot cross a request boundary.
			$slice_crc = crc32( $block );
			$out['crc'] = ( 0 === $written ) ? $slice_crc : self::crc32_combine( $out['crc'], $slice_crc, strlen( $block ) );

			$payload = ( null === $deflate ) ? $block : deflate_add( $deflate, $block, ZLIB_NO_FLUSH );
			if ( '' !== $payload && ! $this->write( $payload ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $in );
				$out['error'] = 'write failed';
				return $out;
			}
			$this->entry_compressed += strlen( $payload );
			$written                += strlen( $block );

			// Only a STORED entry may be left part-written. A deflate stream cannot be
			// picked up in the next request, so an entry being deflated runs to the end
			// whatever the clock says - it is at most DEFLATE_MAX_BYTES, which costs a
			// fraction of a second, and the alternative is a corrupt entry.
			if ( null === $deflate && $deadline > 0 && time() >= $deadline && $written < $size ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $in );
				$out['offset'] = $written;
				return $out; // Not done: the same entry continues next tick.
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $in );

		if ( null !== $deflate ) {
			$tail = deflate_add( $deflate, '', ZLIB_FINISH );
			if ( '' !== $tail ) {
				$this->write( $tail );
				$this->entry_compressed += strlen( $tail );
			}
		}

		// The values the header could not carry, written after the data it describes.
		$this->write( pack( 'VVVV', self::SIG_DESCRIPTOR, $out['crc'], $this->entry_compressed, $written ) );

		$this->entry_open = false;
		$this->record_entry( $entry_name, $method, $out['crc'], $this->entry_compressed, $written, @filemtime( $source ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.

		$out['done']   = true;
		$out['offset'] = $written;

		return $out;
	}

	/**
	 * Offset of the entry currently being written.
	 *
	 * @var int
	 */
	private $entry_start = 0;

	/**
	 * Bytes written for the entry currently being written.
	 *
	 * @var int
	 */
	private $entry_compressed = 0;

	/**
	 * Whether a header has been written whose data descriptor has not.
	 *
	 * An entry in that state is real bytes on disk that a resume has to KEEP, which is the
	 * opposite of what the previous tick's central directory needs. Both sit after the last
	 * completed entry, so telling them apart is what this flag is for.
	 *
	 * @var bool
	 */
	private $entry_open = false;

	/**
	 * Write the central directory so the archive on disk is complete and openable.
	 *
	 * Called at the end of every tick, not only at the end of the job: an archive that is
	 * still being built should never be a file nothing can read.
	 *
	 * @return bool
	 */
	public function finalize() {
		if ( ! is_resource( $this->handle ) ) {
			return false;
		}

		// Where the directory starts is also where the next tick truncates back to.
		$this->cd_offset = ftell( $this->handle );

		$directory = '';
		foreach ( $this->entries as $entry ) {
			$directory .= $this->central_header( $entry );
		}

		$this->write( $directory );
		$this->write(
			pack(
				'VvvvvVVv',
				self::SIG_EOCD,
				0,
				0,
				count( $this->entries ),
				count( $this->entries ),
				strlen( $directory ),
				$this->cd_offset,
				0
			)
		);

		return true;
	}

	/**
	 * Finish and release the handle. The sidecar is scaffolding, so it goes.
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->handle ) ) {
			$this->finalize();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
			fclose( $this->handle );
			$this->handle = null;
		}
		if ( file_exists( $this->sidecar ) ) {
			wp_delete_file( $this->sidecar );
		}
	}

	/**
	 * End this tick without finishing the archive: directory written, sidecar kept.
	 *
	 * @return void
	 */
	public function pause() {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}

		$this->finalize();

		// Where to come back to, written after the entry lines so it is the last word. A
		// crash that never reaches here leaves no marker, and the resume falls back to the
		// last completed entry - losing the part-written one rather than trusting it.
		$marker = wp_json_encode(
			array(
				'_at'    => (int) $this->cd_offset,
				'_open'  => (bool) $this->entry_open,
				'_start' => (int) $this->entry_start,
				'_csize' => (int) $this->entry_compressed,
			)
		);
		if ( is_string( $marker ) ) {
			@file_put_contents( $this->sidecar, $marker . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- append-only sidecar beside the archive, never a WP-managed path; a sidecar that cannot be written only costs resumability.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $this->handle );
		$this->handle = null;
	}

	/**
	 * Whether a part-written entry survived the resume.
	 *
	 * False means the caller must start that entry again from byte zero: the tick that was
	 * writing it never got to record where it had reached.
	 *
	 * @return bool
	 */
	public function partial_survived() {
		return $this->entry_open;
	}

	/**
	 * How many entries the archive holds.
	 *
	 * @return int
	 */
	public function entry_count() {
		return count( $this->entries );
	}

	/**
	 * Bytes written so far, excluding the central directory.
	 *
	 * @return int
	 */
	public function data_bytes() {
		return is_resource( $this->handle ) ? (int) ftell( $this->handle ) : (int) $this->cd_offset;
	}

	/**
	 * Join two CRC-32 values, given the length of the second run.
	 *
	 * zlib's crc32_combine. A hash context cannot survive a request, so each tick hashes
	 * its own slice and the results are combined here instead.
	 *
	 * @param int $crc1 CRC of the first run.
	 * @param int $crc2 CRC of the second run.
	 * @param int $len2 Length in bytes of the second run.
	 * @return int
	 */
	public static function crc32_combine( $crc1, $crc2, $len2 ) {
		if ( $len2 <= 0 ) {
			return (int) $crc1;
		}

		$even = array_fill( 0, 32, 0 );
		$odd  = array_fill( 0, 32, 0 );

		$odd[0] = 0xEDB88320; // The CRC-32 polynomial, bit-reversed.
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row       = ( $row << 1 ) & 0xFFFFFFFF;
		}

		self::gf2_square( $even, $odd );
		self::gf2_square( $odd, $even );

		$crc1 = (int) $crc1 & 0xFFFFFFFF;
		$len2 = (int) $len2;

		do {
			self::gf2_square( $even, $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}

			self::gf2_square( $odd, $even );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );

		return ( $crc1 ^ ( (int) $crc2 & 0xFFFFFFFF ) ) & 0xFFFFFFFF;
	}

	/**
	 * Multiply a vector by a GF(2) matrix.
	 *
	 * @param array $mat Matrix.
	 * @param int   $vec Vector.
	 * @return int
	 */
	private static function gf2_times( array $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		$vec = $vec & 0xFFFFFFFF;

		while ( 0 !== $vec && $i < 32 ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec = ( $vec >> 1 ) & 0x7FFFFFFF;
			$i++;
		}

		return $sum & 0xFFFFFFFF;
	}

	/**
	 * Square a GF(2) matrix into another.
	 *
	 * @param array $square Destination.
	 * @param array $mat    Source.
	 * @return void
	 */
	private static function gf2_square( array &$square, array $mat ) {
		for ( $n = 0; $n < 32; $n++ ) {
			$square[ $n ] = self::gf2_times( $mat, $mat[ $n ] );
		}
	}

	/**
	 * STORE or DEFLATE for one file.
	 *
	 * @param string $source Absolute path.
	 * @param int    $size   Size in bytes.
	 * @return int
	 */
	private function method_for( $source, $size ) {
		if ( ! function_exists( 'deflate_init' ) ) {
			return self::METHOD_STORE;
		}

		// Large enough to span a tick, and a deflate stream cannot be resumed.
		if ( $size > self::DEFLATE_MAX_BYTES ) {
			return self::METHOD_STORE;
		}

		$extension = strtolower( (string) pathinfo( $source, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::STORED_EXTENSIONS, true ) ? self::METHOD_STORE : self::METHOD_DEFLATE;
	}

	/**
	 * Local file header, with bit 3 set and the sizes left for the data descriptor.
	 *
	 * @param string $name   Entry name.
	 * @param int    $method Compression method.
	 * @param int    $mtime  Modified time.
	 * @return string
	 */
	private function local_header( $name, $method, $mtime ) {
		list( $time, $date ) = self::dos_time( $mtime );

		return pack( 'VvvvvvVVVvv', self::SIG_LOCAL, 20, 0x0008, $method, $time, $date, 0, 0, 0, strlen( $name ), 0 ) . $name;
	}

	/**
	 * One central directory record.
	 *
	 * @param array $entry Recorded entry.
	 * @return string
	 */
	private function central_header( array $entry ) {
		list( $time, $date ) = self::dos_time( $entry['mtime'] );

		return pack(
			'VvvvvvvVVVvvvvvVV',
			self::SIG_CENTRAL,
			20,
			20,
			0x0008,
			$entry['method'],
			$time,
			$date,
			$entry['crc'],
			$entry['csize'],
			$entry['usize'],
			strlen( $entry['name'] ),
			0,
			0,
			0,
			0,
			0,
			$entry['offset']
		) . $entry['name'];
	}

	/**
	 * DOS time and date, which is what ZIP carries.
	 *
	 * @param int|false $mtime Unix time.
	 * @return array{0:int,1:int}
	 */
	private static function dos_time( $mtime ) {
		$stamp = ( false === $mtime || $mtime <= 0 ) ? time() : (int) $mtime;
		$parts = getdate( $stamp );

		if ( $parts['year'] < 1980 ) {
			return array( 0, 33 ); // 1980-01-01, the earliest ZIP can express.
		}

		$time = ( $parts['hours'] << 11 ) | ( $parts['minutes'] << 5 ) | ( (int) ( $parts['seconds'] / 2 ) );
		$date = ( ( $parts['year'] - 1980 ) << 9 ) | ( $parts['mon'] << 5 ) | $parts['mday'];

		return array( $time, $date );
	}

	/**
	 * Remember a finished entry, in memory and in the sidecar.
	 *
	 * @param string $name   Entry name.
	 * @param int    $method Compression method.
	 * @param int    $crc    CRC-32.
	 * @param int    $csize  Compressed size.
	 * @param int    $usize  Uncompressed size.
	 * @param int    $mtime  Modified time.
	 * @return void
	 */
	private function record_entry( $name, $method, $crc, $csize, $usize, $mtime ) {
		$entry = array(
			'name'   => $name,
			'method' => (int) $method,
			'crc'    => (int) $crc,
			'csize'  => (int) $csize,
			'usize'  => (int) $usize,
			'mtime'  => (int) ( false === $mtime ? time() : $mtime ),
			'offset' => (int) $this->entry_start,
			// Where the archive stands now. This is what the next tick truncates back to,
			// which removes this tick's central directory and any partial entry at once.
			'end'    => (int) ftell( $this->handle ),
		);

		$this->entries[] = $entry;
		$this->cd_offset = $entry['end'];

		$line = wp_json_encode( $entry );
		if ( is_string( $line ) ) {
			@file_put_contents( $this->sidecar, $line . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- append-only sidecar; a partial last line is discarded on read, and a sidecar that cannot be written only costs resumability.
		}
	}

	/**
	 * Rebuild the entry list from the sidecar.
	 *
	 * A line that does not parse is where the last crash landed, so reading stops there and
	 * everything after it is treated as never written.
	 *
	 * @return true|string True, or why the archive cannot be resumed.
	 */
	private function load_sidecar() {
		$this->entries    = array();
		$this->cd_offset  = 0;
		$this->entry_open = false;

		if ( ! file_exists( $this->sidecar ) ) {
			return 'no resume index beside the archive';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- line-by-line read of the resume index.
		$handle = @fopen( $this->sidecar, 'rb' );
		if ( false === $handle ) {
			return 'resume index unreadable';
		}

		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$entry = json_decode( $line, true );
			if ( ! is_array( $entry ) ) {
				break; // Truncated by the crash that ended the last tick.
			}

			// The marker a clean pause leaves. It comes after the entries, so it has the
			// final say on where writing resumes - and it counts any part-written entry,
			// which the entry lines by definition cannot.
			if ( isset( $entry['_at'] ) ) {
				$this->cd_offset        = (int) $entry['_at'];
				$this->entry_open       = ! empty( $entry['_open'] );
				$this->entry_start      = isset( $entry['_start'] ) ? (int) $entry['_start'] : 0;
				$this->entry_compressed = isset( $entry['_csize'] ) ? (int) $entry['_csize'] : 0;
				continue;
			}

			if ( ! isset( $entry['name'], $entry['offset'], $entry['end'] ) ) {
				break;
			}

			$this->entries[] = $entry;
			$this->cd_offset = (int) $entry['end'];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );

		return true;
	}

	/**
	 * Write, and say whether all of it landed.
	 *
	 * @param string $bytes Bytes to write.
	 * @return bool
	 */
	private function write( $bytes ) {
		if ( '' === $bytes ) {
			return true;
		}

		$put = @fwrite( $this->handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed archive write; WP_Filesystem has no stream API and a full disk is reported by the return value.

		return false !== $put && strlen( $bytes ) === $put;
	}
}
