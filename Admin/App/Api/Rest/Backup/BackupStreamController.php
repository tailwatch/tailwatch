<?php
/**
 * Tailwatch - Authenticated backup streaming
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Rest\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Rest\Backup;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Backup\BackupDownloadToken;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves a backup file over an authenticated, resumable, path-pinned endpoint.
 *
 * The storage tree is sealed, so a backup is not reachable by URL. Without this endpoint
 * a migration would have nothing to transport and a user no way to take their own data
 * with them.
 *
 * An authenticated channel is only as private as its payload: a signed transfer of a file
 * anyone could download by guessing its path protects nothing. An authenticated way to
 * read a backup is therefore a PREREQUISITE for the transport, not a companion to it.
 *
 * Deciding and delivering are separated, and they live on different planes:
 *
 *   action `tailwatch_issue_backup_download`   decides, and mints a short-lived grant
 *   GET    /backup/download                    presents the grant, streams the bytes
 *
 * The split exists because the thing doing the reading is frequently not the thing that
 * can authenticate: a browser following a download link, a download manager resuming after
 * an hour, a receiving site pulling with `wp_remote_get()`. Rather than weaken the
 * management plane's credential so it can travel in a URL, a much weaker credential is
 * issued for exactly one file, for five minutes, once.
 *
 * Minting is an action rather than a REST route of its own: the connect plane authorises
 * exactly one route, `/dispatch`, which routes by action, so only an action is reachable
 * from the dashboard and the mobile app as well as from a browser. Deciding whether a
 * caller may export a backup is a management decision like every other, and
 * {@see grant_for()} is what the action and this class share.
 */
class BackupStreamController {

	/**
	 * REST namespace, shared with the rest of the connect plane.
	 */
	const REST_NAMESPACE = 'tailwatch/v1';

	/**
	 * Bytes pushed per read. Large enough not to syscall per kilobyte, small enough that
	 * a 4GB archive never approaches the memory limit.
	 */
	const CHUNK_BYTES = 262144;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the streaming route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/backup/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stream' ),
				'permission_callback' => array( $this, 'check_grant' ),
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Mint a grant for one file, and say where to present it.
	 *
	 * The caller names a backup folder and a file WITHIN it rather than a path, and both
	 * are reduced to their basename before anything is joined — so there is no arrangement
	 * of the request that can address a file outside the storage tree, independently of
	 * the containment check that follows.
	 *
	 * Deliberately says nothing about WHO may ask. That is a management-plane decision and
	 * belongs with the action, where it is made once for every caller — browser, dashboard
	 * or app — rather than differently per transport.
	 *
	 * @param string $folder Backup folder name.
	 * @param string $file   File within it.
	 * @param string $area   'files' or 'database'.
	 * @return array{url:string,expires:int}|null Null when there is nothing servable there.
	 */
	public static function grant_for( $folder, $file, $area = 'files' ) {
		$folder = wp_basename( (string) $folder );
		$file   = wp_basename( (string) $file );
		$area   = ( 'database' === $area ) ? 'database' : 'files';

		if ( '' === $folder || '' === $file ) {
			return null;
		}

		$issued = BackupDownloadToken::issue( TAILWATCH_BACKUP_DIR . '/' . $area . '/' . $folder . '/' . $file );
		if ( null === $issued ) {
			return null;
		}

		Log::info(
			'Issued a backup download grant.',
			array(
				'feature'   => 'backup',
				'action'    => 'backup_download_token_issued',
				'meta_data' => array(
					'feature' => 'Backup Vault',
					'event'   => 'Download authorised',
					'backup'  => $folder,
				),
			)
		);

		return array(
			'url'     => add_query_arg(
				'token',
				rawurlencode( $issued['token'] ),
				rest_url( self::REST_NAMESPACE . '/backup/download' )
			),
			'expires' => (int) $issued['expires'],
		);
	}

	/**
	 * Admit a request only when it carries a live grant.
	 *
	 * The grant is the authorisation: the reader is often something that cannot log in.
	 * It is checked here without being spent, because WordPress may call this more than
	 * once for one request and a refused request must not burn a grant its holder still
	 * needs. {@see stream()} spends it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_grant( WP_REST_Request $request ) {
		if ( null === BackupDownloadToken::validate( (string) $request->get_param( 'token' ) ) ) {
			return self::denied();
		}

		return true;
	}

	/**
	 * Stream the file a grant authorises.
	 *
	 * Takes over the response rather than returning one: a WP_REST_Response would be
	 * JSON-encoded, which for a multi-gigabyte archive is both wrong and fatal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function stream( WP_REST_Request $request ) {
		$path = BackupDownloadToken::redeem( (string) $request->get_param( 'token' ) );

		if ( null === $path ) {
			return self::denied();
		}

		$size = (int) filesize( $path );
		$name = wp_basename( $path );

		// Range support is what makes a large transfer survivable: a receiving site or a
		// download manager can resume rather than start a 4GB archive again.
		$range = $this->parse_range( $request->get_header( 'range' ), $size );

		// Nothing may have been emitted before this point, or the body is corrupt.
		if ( function_exists( 'ob_get_level' ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( null === $range ) {
			header( 'Content-Length: ' . $size );
			$start  = 0;
			$length = $size;
		} else {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size );
			header( 'Content-Length: ' . $range['length'] );
			$start  = $range['start'];
			$length = $range['length'];
		}

		$this->emit( $path, $start, $length );
		exit;
	}

	/**
	 * The one refusal this route gives, whichever check failed.
	 *
	 * @return WP_Error
	 */
	private static function denied() {
		return new WP_Error(
			'tailwatch_download_denied',
			__( 'This download link is invalid or has expired.', 'tailwatch' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Parse a single-range `Range` header.
	 *
	 * Only one range is supported. Multipart ranges would need a multipart/byteranges body
	 * for a case no client of this endpoint produces, and answering a multi-range request
	 * with the whole file is a correct response.
	 *
	 * @param string|null $header Raw header value.
	 * @param int         $size   File size.
	 * @return array{start:int,end:int,length:int}|null
	 */
	private function parse_range( $header, $size ) {
		if ( ! is_string( $header ) || '' === $header || $size <= 0 ) {
			return null;
		}
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $m ) ) {
			return null;
		}

		$has_start = '' !== $m[1];
		$has_end   = '' !== $m[2];

		if ( ! $has_start && ! $has_end ) {
			return null;
		}

		if ( ! $has_start ) {
			// A suffix range: the last N bytes.
			$length = min( (int) $m[2], $size );
			$start  = $size - $length;
			$end    = $size - 1;
		} else {
			$start = (int) $m[1];
			$end   = $has_end ? (int) $m[2] : $size - 1;
			$end   = min( $end, $size - 1 );
		}

		if ( $start < 0 || $start > $end ) {
			return null;
		}

		return array(
			'start'  => $start,
			'end'    => $end,
			'length' => ( $end - $start ) + 1,
		);
	}

	/**
	 * Push bytes to the client.
	 *
	 * Reads and flushes in fixed chunks. `readfile()` would be shorter and would hold the
	 * whole file in the output buffer, which is precisely the failure mode a backup
	 * endpoint has to avoid.
	 *
	 * @param string $path   Absolute path.
	 * @param int    $start  First byte.
	 * @param int    $length Bytes to send.
	 * @return void
	 */
	private function emit( $path, $start, $length ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a multi-gigabyte archive; WP_Filesystem has no streaming read and would load the whole file into memory.
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return;
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Fixed-size streaming read; WP_Filesystem offers no streaming API and its get_contents() would load a multi-gigabyte archive into memory.
			$read = fread( $handle, (int) min( self::CHUNK_BYTES, $remaining ) );
			if ( false === $read || '' === $read ) {
				break;
			}
			$remaining -= strlen( $read );

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw archive bytes; escaping would corrupt the file being downloaded.
			echo $read;

			if ( function_exists( 'ob_get_level' ) && ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();

			// A client that hung up should not keep the worker reading a 4GB file.
			if ( connection_aborted() ) {
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streaming handle opened above.
		fclose( $handle );
	}
}
