<?php
/**
 * Files Downloading Controller
 *
 * Streams a remote file to disk via WordPress core's HTTP API
 * (wp_remote_get with 'stream' => true) so large binary payloads never load into
 * PHP memory. Used by the user-initiated MaxMind GeoLite2 database download.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Files
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilesDownloadingController {

	/**
	 * Download a remote file to disk.
	 *
	 * @param string $url  File URL.
	 * @param string $path Local save path.
	 * @return array{is_downloaded:bool,status_code:int,error_message:string}
	 */
	public function tailwatch_download_file( $url, $path ) {
		return $this->tailwatch_download_file_with_wp_remote_get( $url, $path );
	}

	/**
	 * Stream a remote file to disk with wp_remote_get.
	 *
	 * On error, any partial file on disk is removed so callers get a clean
	 * is_downloaded=false result.
	 *
	 * @param string $url  File URL.
	 * @param string $path Local save path.
	 * @return array{is_downloaded:bool,status_code:int,error_message:string}
	 */
	public function tailwatch_download_file_with_wp_remote_get( $url, $path ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return array(
				'is_downloaded' => false,
				'status_code'   => 0,
				'error_message' => 'Failed to create directory for file: ' . $path,
			);
		}

		// Stream directly to disk so large files never load into memory.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $path,
				'headers'  => array(
					'Accept' => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return array(
				'is_downloaded' => false,
				'status_code'   => 0,
				'error_message' => $response->get_error_message(),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return array(
				'is_downloaded' => false,
				'status_code'   => $status_code,
				'error_message' => 'Download request failed with status code: ' . $status_code,
			);
		}

		if ( ! file_exists( $path ) || 0 === filesize( $path ) ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return array(
				'is_downloaded' => false,
				'status_code'   => 0,
				'error_message' => 'File not downloaded or is empty.',
			);
		}

		return array(
			'is_downloaded' => true,
			'status_code'   => 200,
			'error_message' => '',
		);
	}

	/**
	 * Convert an HTTP status code / transport error into a user-facing message.
	 *
	 * @param int    $status_code   HTTP status code (0 = transport error).
	 * @param string $error_message Raw error message.
	 * @return string
	 */
	public function get_user_friendly_download_error( $status_code, $error_message = '' ) {
		switch ( $status_code ) {
			case 401:
			case 403:
				return __( 'Permission denied. Check the MaxMind license key and account permissions.', 'tailwatch' );
			case 404:
				return __( 'File not found. The requested database edition may be unavailable for this license.', 'tailwatch' );
			case 429:
				return __( 'Too many requests. MaxMind is rate limiting downloads. Please try again later.', 'tailwatch' );
			case 500:
			case 502:
			case 503:
			case 504:
				return __( 'Server error. MaxMind is experiencing issues. Please try again later.', 'tailwatch' );
			case 0:
				return __( 'Network error. The server could not be reached. Check your connection or firewall settings.', 'tailwatch' );
			default:
				/* translators: %s is the raw error message. */
				return sprintf( __( 'An error occurred: %s', 'tailwatch' ), $error_message );
		}
	}
}
