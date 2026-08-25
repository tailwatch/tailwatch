<?php
/**
 * GeoLite2 Controller
 *
 * Downloads and maintains the MaxMind GeoLite2-Country database using the site
 * administrator's own MaxMind license key. Every network request is USER-INITIATED:
 * the initial download runs when the admin saves a license key, and updates run only
 * when the admin clicks "Check for updates". There is no background cron and no
 * automatic/unattended download. The database is written under the plugin's uploads
 * storage directory (deny-protected) at the exact path GeoIPService reads.
 *
 * External service: this contacts download.maxmind.com only, with the admin's own
 * license key, and only on the explicit actions above. See readme.txt "External
 * Services". The GeoLite2 data is provided by MaxMind under its GeoLite2 EULA.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Integration\GeoIp2
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Integration\GeoIp2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Files\FilesDownloadingController;
use Tailwatch\Admin\App\Api\Controllers\Integration\IntegrationController;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;

class GeoLiteTwoController {

	const DATABASE_SUFFIX = 'tar.gz';
	const DATABASE_URL    = 'https://download.maxmind.com/app/geoip_download';

	/**
	 * Absolute path to the installed GeoLite2-Country .mmdb.
	 *
	 * Delegates to GeoIPService so the download target and the reader stay in sync.
	 *
	 * @return string
	 */
	public static function tailwatch_geo_lite_db_file_path() {
		return GeoIPService::tailwatch_geo_lite_db_file_path();
	}

	/**
	 * Whether the installed database file exists.
	 *
	 * @return bool
	 */
	public static function tailwatch_is_geo_lite_db_file_exist() {
		return file_exists( self::tailwatch_geo_lite_db_file_path() );
	}

	/**
	 * The plugin-owned geoip storage directory (parent of the .mmdb).
	 *
	 * @return string
	 */
	private static function tailwatch_geoip_dir() {
		return dirname( self::tailwatch_geo_lite_db_file_path() );
	}

	/**
	 * Report whether MaxMind is connected / the database is present.
	 *
	 * @return array
	 */
	public function tailwatch_is_geo_lite_connected_or_exist() {
		try {
			$integration_controller = new IntegrationController();
			$integration_response   = $integration_controller->tailwatch_get_integration_data( 'maxmind' );
			$integration_data       = $integration_response['integration_data'] ?? array();

			$license_key  = $integration_data['license_key'] ?? '';
			$connected     = (bool) ( $integration_data['is_connected'] ?? false );
			$file_exists  = self::tailwatch_is_geo_lite_db_file_exist();
			$is_connected = ( $connected || $file_exists ) && ! empty( $license_key );

			if ( empty( $license_key ) && ! $file_exists ) {
				return array(
					'code'         => 400,
					'is_connected' => false,
					'file_exists'  => $file_exists,
					'message'      => __( 'License key missing and database file not found.', 'tailwatch' ),
				);
			}

			return array(
				'code'         => 200,
				'is_connected' => $is_connected,
				'file_exists'  => $file_exists,
				'message'      => $is_connected ? __( 'GeoLite2 connected or file exists.', 'tailwatch' ) : __( 'GeoLite2 not connected yet.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'code'         => 500,
				'is_connected' => false,
				'file_exists'  => false,
				'message'      => __( 'Server error while checking GeoLite2 status.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Download the GeoLite2-Country database for the stored MaxMind license key.
	 *
	 * Called only on an explicit user action (saving the license key). No cron, no
	 * automatic retry: on failure the admin can retry from the UI.
	 *
	 * @return array
	 */
	public function tailwatch_download_geo_lite_two_database() {
		$integration_controller = new IntegrationController();
		$integration_data       = $integration_controller->tailwatch_get_integration_data( 'maxmind' );

		if ( empty( $integration_data['integration_data'] ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'Integration data not found.', 'tailwatch' ),
				'result'  => array(),
			);
		}

		$integration_info = $integration_data['integration_data'];
		$license_key      = $integration_info['license_key'] ?? '';
		$previous_key     = $integration_info['previous_key'] ?? '';

		if ( empty( $license_key ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'License key is required', 'tailwatch' ),
				'result'  => array(),
			);
		}

		// Validate MaxMind license key format (old 16-char or new 40-char *_mmk).
		if ( ! preg_match( '/^[0-9a-zA-Z]{16}$/', $license_key )
			&& ! preg_match( '/^[0-9a-zA-Z]{6}_[0-9a-zA-Z]{29}_mmk$/', $license_key )
		) {
			return array(
				'code'    => 400,
				'message' => __( 'Invalid MaxMind license key format.', 'tailwatch' ),
				'result'  => array(),
			);
		}

		if ( $previous_key === $license_key && self::tailwatch_is_geo_lite_db_file_exist() ) {
			return array(
				'code'    => 200,
				'message' => __( 'GeoLite2 database file already exists.', 'tailwatch' ),
				'result'  => array(),
			);
		}

		$download_uri = add_query_arg(
			array(
				'edition_id'  => GeoIPService::DATABASE,
				'license_key' => sanitize_text_field( $license_key ),
				'suffix'      => self::DATABASE_SUFFIX,
			),
			self::DATABASE_URL
		);

		$save_path = self::tailwatch_geoip_dir() . '/' . GeoIPService::DATABASE . '.' . self::DATABASE_SUFFIX;

		$download_controller = new FilesDownloadingController();
		$download_result     = $download_controller->tailwatch_download_file( $download_uri, $save_path );

		$integration_controller->tailwatch_update_integration_data(
			wp_json_encode(
				array(
					'integration_data' => array(
						'maxmind' => array(
							'message'      => $download_result['error_message'] ?? '',
							'is_connected' => $download_result['is_downloaded'] ?? false,
							'previous_key' => $license_key,
						),
					),
				)
			)
		);

		if ( ! empty( $download_result['is_downloaded'] ) ) {
			$extract_result = $this->tailwatch_extract_and_move_file( $save_path );

			if ( 'success' === ( $extract_result['status'] ?? '' ) ) {
				$db_path = self::tailwatch_geo_lite_db_file_path();
				$integration_controller->tailwatch_update_integration_data(
					wp_json_encode(
						array(
							'integration_data' => array(
								'maxmind' => array(
									'db_md5'  => file_exists( $db_path ) ? md5_file( $db_path ) : '',
									'db_date' => gmdate( 'Y-m-d H:i:s' ),
								),
							),
						)
					)
				);
			}

			return array(
				'code'    => 200,
				'message' => __( 'GeoLite2 database downloaded successfully', 'tailwatch' ),
				'result'  => $download_result,
			);
		}

		return array(
			'code'    => 400,
			'message' => __( 'Failed to download GeoLite2 database', 'tailwatch' ),
			'result'  => $download_result,
		);
	}

	/**
	 * User-initiated update check: download the latest database to a temp dir, compare
	 * its MD5 against the installed file, and replace only when it differs. Triggered by
	 * the "Check for updates" button — never by a schedule.
	 *
	 * @return array
	 */
	public function tailwatch_check_and_update_database() {
		$integration_controller = new IntegrationController();
		$integration_data       = $integration_controller->tailwatch_get_integration_data( 'maxmind' );
		$info                   = $integration_data['integration_data'] ?? array();
		$license_key            = $info['license_key'] ?? '';

		if ( empty( $license_key ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'No license key configured.', 'tailwatch' ),
				'updated' => false,
			);
		}

		if ( ! self::tailwatch_is_geo_lite_db_file_exist() ) {
			return array(
				'code'    => 400,
				'message' => __( 'No existing database file — run initial setup first.', 'tailwatch' ),
				'updated' => false,
			);
		}

		$temp_dir     = self::tailwatch_geoip_dir() . '/temp';
		$temp_archive = $temp_dir . '/' . GeoIPService::DATABASE . '.' . self::DATABASE_SUFFIX;

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$download_uri = add_query_arg(
			array(
				'edition_id'  => GeoIPService::DATABASE,
				'license_key' => sanitize_text_field( $license_key ),
				'suffix'      => self::DATABASE_SUFFIX,
			),
			self::DATABASE_URL
		);

		$download_controller = new FilesDownloadingController();
		$download_result     = $download_controller->tailwatch_download_file( $download_uri, $temp_archive );

		if ( empty( $download_result['is_downloaded'] ) ) {
			$this->tailwatch_rrmdir( $temp_dir );
			return array(
				'code'    => 400,
				/* translators: %s is the error message. */
				'message' => sprintf( __( 'Failed to download update: %s', 'tailwatch' ), $download_result['error_message'] ?? '' ),
				'updated' => false,
			);
		}

		$extracted = $this->tailwatch_extract_archive( $temp_archive, $temp_dir );
		wp_delete_file( $temp_archive );

		if ( ! $extracted ) {
			$this->tailwatch_rrmdir( $temp_dir );
			return array(
				'code'    => 400,
				'message' => __( 'Failed to extract downloaded update.', 'tailwatch' ),
				'updated' => false,
			);
		}

		$new_mmdb = $this->tailwatch_find_mmdb_in_dir( $temp_dir );
		if ( null === $new_mmdb ) {
			$this->tailwatch_rrmdir( $temp_dir );
			return array(
				'code'    => 400,
				'message' => __( 'Extracted update file not found.', 'tailwatch' ),
				'updated' => false,
			);
		}

		$existing_path = self::tailwatch_geo_lite_db_file_path();
		$new_md5       = md5_file( $new_mmdb );
		$existing_md5  = md5_file( $existing_path );

		if ( $new_md5 === $existing_md5 ) {
			$this->tailwatch_rrmdir( $temp_dir );
			return array(
				'code'    => 200,
				'message' => __( 'Database is already up to date.', 'tailwatch' ),
				'updated' => false,
			);
		}

		if ( file_exists( $existing_path ) ) {
			wp_delete_file( $existing_path );
		}
		$fs = FilesystemService::get_filesystem();
		if ( ! $fs || ! $fs->move( $new_mmdb, $existing_path, true ) ) {
			$copied = $fs ? $fs->copy( $new_mmdb, $existing_path, true ) : false;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Last-resort fallback when WP_Filesystem is unavailable; target is the plugin-owned geoip dir under uploads.
			if ( ! $copied && ! copy( $new_mmdb, $existing_path ) ) {
				$this->tailwatch_rrmdir( $temp_dir );
				return array(
					'code'    => 500,
					'message' => __( 'Failed to replace database file with update.', 'tailwatch' ),
					'updated' => false,
				);
			}
		}

		$this->tailwatch_rrmdir( $temp_dir );

		$integration_controller->tailwatch_update_integration_data(
			wp_json_encode(
				array(
					'integration_data' => array(
						'maxmind' => array(
							'db_md5'  => $new_md5,
							'db_date' => gmdate( 'Y-m-d H:i:s' ),
						),
					),
				)
			)
		);

		return array(
			'code'    => 200,
			'message' => __( 'Database updated successfully.', 'tailwatch' ),
			'updated' => true,
		);
	}

	/**
	 * Remove the installed GeoLite2 database and wipe the geoip storage folder.
	 *
	 * @return array
	 */
	public function tailwatch_remove_geo_lite_database() {
		$geoip_dir = self::tailwatch_geoip_dir();
		if ( is_dir( $geoip_dir ) ) {
			$this->tailwatch_rrmdir( $geoip_dir );
		}

		return array(
			'code'    => 200,
			'message' => __( 'GeoLite2 database removed.', 'tailwatch' ),
		);
	}

	/**
	 * Extract the downloaded archive and move the .mmdb to its installed path.
	 *
	 * @param string $archive_path Path to the downloaded tar.gz archive.
	 * @return array
	 */
	public function tailwatch_extract_and_move_file( $archive_path ) {
		$target_directory = self::tailwatch_geoip_dir();

		if ( ! is_dir( $target_directory ) ) {
			wp_mkdir_p( $target_directory );
		}

		$extracted = $this->tailwatch_extract_archive( $archive_path, $target_directory );

		if ( ! $extracted ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Extraction did not complete.', 'tailwatch' ),
			);
		}

		wp_delete_file( $archive_path );

		$source_mmdb = $this->tailwatch_find_mmdb_in_dir( $target_directory );
		if ( null === $source_mmdb ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Extracted database file not found after extraction.', 'tailwatch' ),
			);
		}

		$dest_mmdb = self::tailwatch_geo_lite_db_file_path();
		$subdir    = dirname( $source_mmdb );

		if ( file_exists( $dest_mmdb ) ) {
			wp_delete_file( $dest_mmdb );
		}

		$fs = FilesystemService::get_filesystem();
		if ( ! $fs || ! $fs->move( $source_mmdb, $dest_mmdb, true ) ) {
			$copied = $fs ? $fs->copy( $source_mmdb, $dest_mmdb, true ) : false;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Last-resort fallback when WP_Filesystem is unavailable; target is the plugin-owned geoip dir under uploads.
			if ( ! $copied && ! copy( $source_mmdb, $dest_mmdb ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to move database file to destination.', 'tailwatch' ),
				);
			}
		}

		$this->tailwatch_rrmdir( $subdir );

		return array(
			'status'  => 'success',
			'message' => __( 'Database file moved successfully.', 'tailwatch' ),
			'path'    => $dest_mmdb,
		);
	}

	/**
	 * Extract a .tar.gz archive into $target_dir. Returns true on success.
	 *
	 * MaxMind serves the database only as .tar.gz, which WordPress core's unzip_file()
	 * (ZIP only) cannot handle; PharData is PHP's standard tar/gz reader and is used
	 * purely to read a plugin-downloaded data archive (no code execution).
	 *
	 * @param string $archive_path Archive path.
	 * @param string $target_dir   Extraction directory.
	 * @return bool
	 */
	private function tailwatch_extract_archive( $archive_path, $target_dir ) {
		try {
			$tar = new \PharData( $archive_path );
			return (bool) $tar->extractTo( $target_dir, null, true );
		} catch ( \Throwable $e ) {
			try {
				if ( '.tar.gz' === substr( $archive_path, -7 ) ) {
					$phar     = new \PharData( $archive_path );
					$tar_path = substr( $archive_path, 0, -3 );
					if ( ! file_exists( $tar_path ) ) {
						$phar->decompress();
					}
					$tar = new \PharData( $tar_path );
					return (bool) $tar->extractTo( $target_dir, null, true );
				}
			} catch ( \Throwable $e2 ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Find GeoLite2-Country.mmdb inside the versioned subfolder MaxMind creates on
	 * extraction (e.g. GeoLite2-Country_20250325/). Returns the full path or null.
	 *
	 * @param string $dir Directory to scan.
	 * @return string|null
	 */
	private function tailwatch_find_mmdb_in_dir( $dir ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- A missing extraction dir is an expected not-yet-downloaded state; dirlist() is heavier for this pattern match.
		$entries = @scandir( $dir ) ?: array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$subdir_path = $dir . '/' . $entry;
			if ( is_dir( $subdir_path ) && 0 === strpos( $entry, GeoIPService::DATABASE . '_' ) ) {
				$mmdb_path = $subdir_path . '/' . GeoIPService::DATABASE . GeoIPService::DATABASE_EXTENSION;
				if ( file_exists( $mmdb_path ) ) {
					return $mmdb_path;
				}
			}
		}
		return null;
	}

	/**
	 * Recursively remove a plugin-owned directory tree.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	protected function tailwatch_rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Recursive delete of the plugin-owned GeoLite2 extraction tree; dirlist() would materialize the whole tree at once.
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->tailwatch_rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		$fs = FilesystemService::get_filesystem();
		if ( $fs ) {
			$fs->rmdir( $dir, false );
		}
	}
}
