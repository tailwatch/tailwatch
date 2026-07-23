<?php

namespace Tailwatch\Admin\App\Api\Controllers\Integration\GeoIp2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Files\FilesDownloadingController;
use Tailwatch\Admin\App\Api\Controllers\Integration\IntegrationController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;

class GeoLiteTwoController {

	const DATABASE           = 'GeoLite2-Country';
	const DATABASE_EXTENSION = '.mmdb';
	const DATABASE_SUFFIX    = 'tar.gz';
	const DATABASE_URL       = 'https://download.maxmind.com/app/geoip_download';
	const FOLDER_NAME        = 'geoip';

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_download_geo_lite_two_database', array( $this, 'wptw_download_geo_lite_two_database' ) );
		// Register the 'every_30_minutes' interval used by the download retry cron.
		$hook_controller->add_filter_hook( 'cron_schedules', array( $this, 'wptw_register_every_30_minutes_interval' ) );
	}

	/**
	 * Register the 'every_30_minutes' custom cron interval.
	 *
	 * Used exclusively by the MaxMind download retry cron scheduled in
	 * wptw_download_geo_lite_two_database() when a download attempt fails.
	 * The retry cron self-clears on success or after 3 failed attempts.
	 *
	 * All other recurring jobs route through the CronJobs framework which
	 * registers its own per-job schedules, so this is the only remaining
	 * consumer of the raw 'every_30_minutes' interval.
	 *
	 * @since 1.0.0
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified schedules.
	 */
	public function wptw_register_every_30_minutes_interval( $schedules ) {
		// wptw_-prefixed name is the canonical one (PrefixAllGlobals). The bare
		// `every_30_minutes` key is retained as an alias for one release cycle
		// so pre-existing scheduled events continue to reschedule successfully;
		// remove the alias after that cycle.
		$schedules['wptw_every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 Minutes', 'tailwatch' ),
		);
		$schedules['every_30_minutes'] = $schedules['wptw_every_30_minutes'];
		return $schedules;
	}

	public static function wptw_is_geo_lite_db_file_exist() {
		$database_file = WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME . '/' . self::DATABASE . self::DATABASE_EXTENSION;
		return file_exists( $database_file );
	}

	public static function wptw_geo_lite_db_file_path() {
		return WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME . '/' . self::DATABASE . self::DATABASE_EXTENSION;
	}

	public function wptw_is_geo_lite_connected_or_exist() {
		try {
			$integration_controller = new IntegrationController();
			$integration_response   = $integration_controller->wptw_get_integration_data( 'maxmind' );
			$integration_data       = $integration_response['integration_data'] ?? array();

			$license_key       = $integration_data['license_key'] ?? '';
			$is_connected_flag = (bool) ( $integration_data['is_connected'] ?? false );
			$retry_attempts    = intval( $integration_data['retry_attempts'] ?? 0 );
			$file_exists       = self::wptw_is_geo_lite_db_file_exist();

			// Stop early if maximum retry attempts reached
			if ( $retry_attempts >= 3 ) {
				return array(
					'code'              => 400,
					'is_connected'      => false,
					'file_exists'       => $file_exists,
					'retry_attempts'    => $retry_attempts,
					'max_retry_reached' => true,
					'message'           => __( 'Maximum retry attempts reached', 'tailwatch' ),
				);
			}

			$is_connected = ( $is_connected_flag || $file_exists ) && ! empty( $license_key );

			if ( empty( $license_key ) && ! $file_exists ) {
				return array(
					'code'              => 400,
					'is_connected'      => false,
					'file_exists'       => $file_exists,
					'retry_attempts'    => $retry_attempts,
					'max_retry_reached' => false,
					'message'           => __( 'License key missing and database file not found.', 'tailwatch' ),
				);
			}

			return array(
				'code'              => 200,
				'is_connected'      => $is_connected,
				'file_exists'       => $file_exists,
				'retry_attempts'    => $retry_attempts,
				'max_retry_reached' => false,
				'message'           => $is_connected ? __( 'GeoLite2 connected or file exists.', 'tailwatch' ) : __( 'GeoLite2 not connected yet.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'code'              => 500,
				'is_connected'      => false,
				'file_exists'       => false,
				'retry_attempts'    => 0,
				'max_retry_reached' => false,
				// translators: %s is the error message.
				'message'           => sprintf( __( 'Server error: %s', 'tailwatch' ), $e->getMessage() ),
			);
		}
	}

	public function wptw_download_geo_lite_two_database( $is_user_initiated = false ) {
		$integration_controller = new IntegrationController();
		$integration_data       = $integration_controller->wptw_get_integration_data( 'maxmind' );

		if ( empty( $integration_data['integration_data'] ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'Integration data not found.', 'tailwatch' ),
				'result'  => array(),
			);
		}

		$integration_info = $integration_data['integration_data'];
		$license_key      = $integration_info['license_key'] ?? '';
		$is_connected     = $integration_info['is_connected'] ?? false;
		$retry_attempts   = $integration_info['retry_attempts'] ?? 0;
		$message          = $integration_info['message'] ?? '';
		$previous_key     = $integration_info['previous_key'] ?? '';

		// When triggered by an explicit user save, reset the retry counter so users can
		// always re-attempt after past network failures.
		// The 3-attempt limit applies only to unattended cron retries.
		if ( $is_user_initiated ) {
			$retry_attempts = 0;
		}

		if ( empty( $license_key ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'License key is required', 'tailwatch' ),
				'result'  => array(),
			);
		}

		// Validate MaxMind license key format (old 16-char or new 40-char with _mmk suffix).
		if ( ! preg_match( '/^[0-9a-zA-Z]{16}$/', $license_key )
			&& ! preg_match( '/^[0-9a-zA-Z]{6}_[0-9a-zA-Z]{29}_mmk$/', $license_key )
		) {
			return array(
				'code'    => 400,
				'message' => __( 'Invalid MaxMind license key format.', 'tailwatch' ),
				'result'  => array(),
			);
		}

		$file_exist = self::wptw_is_geo_lite_db_file_exist();
		if ( $previous_key == $license_key && $file_exist ) {
			return array(
				'code'    => 200,
				'message' => __( 'GeoLite2 Database File Already Exist.', 'tailwatch' ),
				'result'  => array(),
			);
		}

		if ( $previous_key != $license_key ) {
			$retry_attempts = 0;
		}

		if ( $retry_attempts >= 3 ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( 'wptw_download_geo_lite_two_database' );
			}
			return array(
				'code'    => 400,
				'message' => __( 'Maximum retry attempts reached', 'tailwatch' ),
				'result'  => array(),
			);
		}

		$download_uri = add_query_arg(
			array(
				'edition_id'  => self::DATABASE,
				'license_key' => sanitize_text_field( $license_key ),
				'suffix'      => self::DATABASE_SUFFIX,
			),
			self::DATABASE_URL
		);

		$save_path = WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME . '/' . self::DATABASE . '.' . self::DATABASE_SUFFIX;

		$download_controller = new FilesDownloadingController();
		$download_result     = $download_controller->wptw_download_file( $download_uri, $save_path );

		$integration_controller->wptw_update_integration_data(
			wp_json_encode(
				array(
					'integration_data' => array(
						'maxmind' => array(
							'retry_attempts' => $retry_attempts + 1,
							'message'        => $download_result['error_message'] ?? '',
							'is_connected'   => $download_result['is_downloaded'] ?? false,
							'previous_key'   => $license_key,
						),
					),
				)
			)
		);

		if ( $download_result['is_downloaded'] ) {
			$extract_result = $this->wptw_extract_and_move_file( $save_path );
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( 'wptw_download_geo_lite_two_database' );
			}

			// Persist MD5 fingerprint and download date for the weekly update-check cron
			if ( ( $extract_result['status'] ?? '' ) === 'success' ) {
				$db_path = self::wptw_geo_lite_db_file_path();
				$integration_controller->wptw_update_integration_data(
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
		} else {
			if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
				if ( ! wp_next_scheduled( 'wptw_download_geo_lite_two_database' ) ) {
					wp_schedule_event( time() + 1800, 'wptw_every_30_minutes', 'wptw_download_geo_lite_two_database' );
				}
			}

			return array(
				'code'    => 400,
				'message' => __( 'Failed to download GeoLite2 database', 'tailwatch' ),
				'result'  => $download_result,
			);
		}
	}

	/**
	 * Weekly update check: download latest database, compare MD5 against installed file,
	 * replace only when the hash differs. Called by GeoLiteUpdateCronJob.
	 *
	 * Flow: download → extract to temp → compare MD5 → replace if changed → cleanup temp.
	 */
	public function wptw_check_and_update_database() {
		$integration_controller = new IntegrationController();
		$integration_data       = $integration_controller->wptw_get_integration_data( 'maxmind' );
		$info                   = $integration_data['integration_data'] ?? array();
		$license_key            = $info['license_key'] ?? '';
		$is_connected           = (bool) ( $info['is_connected'] ?? false );

		if ( empty( $license_key ) || ! $is_connected ) {
			return array(
				'code'    => 400,
				'message' => __( 'Not connected or no license key.', 'tailwatch' ),
				'updated' => false,
			);
		}

		if ( ! self::wptw_is_geo_lite_db_file_exist() ) {
			return array(
				'code'    => 400,
				'message' => __( 'No existing database file — run initial setup first.', 'tailwatch' ),
				'updated' => false,
			);
		}

		$temp_dir     = WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME . '/temp';
		$temp_archive = $temp_dir . '/' . self::DATABASE . '.' . self::DATABASE_SUFFIX;

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$download_uri = add_query_arg(
			array(
				'edition_id'  => self::DATABASE,
				'license_key' => sanitize_text_field( $license_key ),
				'suffix'      => self::DATABASE_SUFFIX,
			),
			self::DATABASE_URL
		);

		$download_controller = new FilesDownloadingController();
		$download_result     = $download_controller->wptw_download_file( $download_uri, $temp_archive );

		if ( ! ( $download_result['is_downloaded'] ?? false ) ) {
			$this->wptw_rrmdir( $temp_dir );
			return array(
				'code'    => 400,
				// translators: %s is the error message.
				'message' => sprintf( __( 'Failed to download update: %s', 'tailwatch' ), $download_result['error_message'] ?? '' ),
				'updated' => false,
			);
		}

		$extracted = $this->wptw_extract_archive( $temp_archive, $temp_dir );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of a temp archive; failure is non-fatal.
		@wp_delete_file( $temp_archive );

		if ( ! $extracted ) {
			$this->wptw_rrmdir( $temp_dir );
			return array(
				'code'    => 400,
				'message' => __( 'Failed to extract downloaded update.', 'tailwatch' ),
				'updated' => false,
			);
		}

		$new_mmdb = $this->wptw_find_mmdb_in_dir( $temp_dir );
		if ( null === $new_mmdb ) {
			$this->wptw_rrmdir( $temp_dir );
			return array(
				'code'    => 400,
				'message' => __( 'Extracted update file not found.', 'tailwatch' ),
				'updated' => false,
			);
		}

		$existing_path = self::wptw_geo_lite_db_file_path();
		$new_md5       = md5_file( $new_mmdb );
		$existing_md5  = md5_file( $existing_path );

		if ( $new_md5 === $existing_md5 ) {
			$this->wptw_rrmdir( $temp_dir );
			return array(
				'code'    => 200,
				'message' => __( 'Database is already up to date.', 'tailwatch' ),
				'updated' => false,
			);
		}

		// MD5 differs — replace the installed file with the updated one
		if ( file_exists( $existing_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort delete before replacement; move() below is the authoritative operation.
			@wp_delete_file( $existing_path );
		}
		$fs = FilesystemService::get_filesystem();
		if ( ! $fs || ! $fs->move( $new_mmdb, $existing_path, true ) ) {
			// Prefer WP_Filesystem copy; native copy() only if WP_Filesystem is unavailable.
			$copied = $fs ? $fs->copy( $new_mmdb, $existing_path, true ) : false;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Last-resort fallback when WP_Filesystem is unavailable; target is the plugin-owned geoip dir under WPTW_LOGS_DIRECTORY.
			if ( ! $copied && ! copy( $new_mmdb, $existing_path ) ) {
				$this->wptw_rrmdir( $temp_dir );
				return array(
					'code'    => 500,
					'message' => __( 'Failed to replace database file with update.', 'tailwatch' ),
					'updated' => false,
				);
			}
		}

		$this->wptw_rrmdir( $temp_dir );

		$integration_controller->wptw_update_integration_data(
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
	 * Remove the GeoLite2 database file and clear any GeoLite-related scheduled hooks.
	 *
	 * Called by IntegrationController::wptw_execute_integration_delete_function when the
	 * MaxMind integration is deleted. Clears the on-failure retry cron and the weekly
	 * update cron, deletes the installed .mmdb file, and wipes the geoip folder so no
	 * leftover archives or extracted subdirectories remain.
	 *
	 * @since 1.0.0
	 *
	 * @return array Status array with 'code' and 'message'.
	 */
	public function wptw_remove_geo_lite_database() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'wptw_download_geo_lite_two_database' );
			wp_clear_scheduled_hook( 'wptw_geo_lite_update_cron' );
		}

		$geoip_dir = WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME;
		if ( is_dir( $geoip_dir ) ) {
			$this->wptw_rrmdir( $geoip_dir );
		}

		return array(
			'code'    => 200,
			'message' => __( 'GeoLite2 database removed.', 'tailwatch' ),
		);
	}

	public function wptw_extract_and_move_file( $zip_path ) {
		$target_directory = WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME;

		if ( ! is_dir( $target_directory ) ) {
			wp_mkdir_p( $target_directory );
		}

		$extracted = $this->wptw_extract_archive( $zip_path, $target_directory );

		if ( ! $extracted ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Extraction did not complete.', 'tailwatch' ),
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of the extracted zip; failure is non-fatal.
		@wp_delete_file( $zip_path );

		$source_mmdb = $this->wptw_find_mmdb_in_dir( $target_directory );
		if ( null === $source_mmdb ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Extracted database file not found after extraction.', 'tailwatch' ),
			);
		}

		$dest_mmdb = $target_directory . '/' . self::DATABASE . self::DATABASE_EXTENSION;
		$subdir    = dirname( $source_mmdb );

		if ( file_exists( $dest_mmdb ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort delete before replacement; overwrite below is the authoritative operation.
			@wp_delete_file( $dest_mmdb );
		}

		$fs = FilesystemService::get_filesystem();
		if ( ! $fs || ! $fs->move( $source_mmdb, $dest_mmdb, true ) ) {
			// Prefer WP_Filesystem copy; native copy() only if WP_Filesystem is unavailable.
			$copied = $fs ? $fs->copy( $source_mmdb, $dest_mmdb, true ) : false;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Last-resort fallback when WP_Filesystem is unavailable; target is the plugin-owned geoip dir under WPTW_LOGS_DIRECTORY.
			if ( ! $copied && ! copy( $source_mmdb, $dest_mmdb ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to move database file to destination.', 'tailwatch' ),
				);
			}
		}

		$this->wptw_rrmdir( $subdir );

		return array(
			'status'  => 'success',
			'message' => __( 'Database file moved successfully.', 'tailwatch' ),
			'path'    => $dest_mmdb,
		);
	}

	/**
	 * Extract a .tar.gz archive into $target_dir. Returns true on success.
	 */
	private function wptw_extract_archive( $zip_path, $target_dir ) {
		try {
			$tar = new \PharData( $zip_path );
			return (bool) $tar->extractTo( $target_dir, null, true );
		} catch ( \Throwable $e ) {
			// For .tar.gz: decompress to .tar first, then extract
			try {
				if ( substr( $zip_path, -7 ) === '.tar.gz' ) {
					$phar     = new \PharData( $zip_path );
					$tar_path = substr( $zip_path, 0, -3 ); // strip .gz
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
	 * Find the GeoLite2-Country.mmdb inside the versioned subfolder that MaxMind
	 * creates after extraction (e.g. GeoLite2-Country_20250325/).
	 * Returns the full path to the .mmdb, or null if not found.
	 */
	private function wptw_find_mmdb_in_dir( $dir ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Silenced because a non-existent extraction dir here is expected (not-yet-downloaded state); dirlist() is heavier for this pattern-match enumeration.
		$entries = @scandir( $dir ) ?: array();
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$subdir_path = $dir . '/' . $entry;
			if ( is_dir( $subdir_path ) && strpos( $entry, self::DATABASE . '_' ) === 0 ) {
				$mmdb_path = $subdir_path . '/' . self::DATABASE . self::DATABASE_EXTENSION;
				if ( file_exists( $mmdb_path ) ) {
					return $mmdb_path;
				}
			}
		}
		return null;
	}

	protected function wptw_rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Recursive delete of plugin-owned GeoLite2 extraction tree; dirlist() would materialize the whole tree at once.
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->wptw_rrmdir( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort recursive cleanup; per-file failure is non-fatal.
				@wp_delete_file( $path );
			}
		}
		$fs = FilesystemService::get_filesystem();
		if ( $fs ) {
			$fs->rmdir( $dir, false );
		}
	}
}
