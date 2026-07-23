<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Files Downloading Controller
 *
 * Streams a remote file to disk via WordPress core's HTTP API
 * (wp_remote_get with 'stream' => true), so large binary payloads never
 * load into PHP memory. Used by GeoLite2 database downloads.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Files
 */

namespace Tailwatch\Admin\App\Api\Services\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FilesDownloadingController {

	/**
	 * Download a remote file to disk.
	 *
	 * Streams via wp_remote_get to keep memory usage flat regardless of file
	 * size. On error, any partial file on disk is removed so callers get a
	 * clean is_downloaded=false + status_code/error_message result.
	 *
	 * @param string $url  File URL.
	 * @param string $path Local save path.
	 * @return array {
	 *     Download result.
	 *
	 *     @type bool   $is_downloaded True on a completed 200 write.
	 *     @type int    $status_code   HTTP status code (0 on transport error).
	 *     @type string $error_message Empty on success, otherwise a description.
	 * }
	 */
	public function wptw_download_file( $url, $path ) {
		return $this->wptw_download_file_with_wp_remote_get( $url, $path );
	}

	/**
	 * Stream a remote file to disk with wp_remote_get.
	 *
	 * @param string $url  File URL.
	 * @param string $path Local save path.
	 * @return array Result array.
	 */
	public function wptw_download_file_with_wp_remote_get( $url, $path ) {
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

		if ( ! file_exists( $path ) || filesize( $path ) === 0 ) {
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
				return "Permission denied ($status_code). Check the MaxMind license key and account permissions.";
			case 404:
				return 'File not found (404). The requested database edition may be unavailable for this license.';
			case 429:
				return 'Too many requests (429). MaxMind is rate limiting downloads. Please try again later.';
			case 500:
			case 502:
			case 503:
			case 504:
				return "Server error ($status_code). MaxMind is experiencing issues. Please try again later.";
			case 0:
				return 'Network error. The server could not be reached. Check your connection or firewall settings.';
			default:
				return "An error occurred (code: $status_code). $error_message";
		}
	}
}
