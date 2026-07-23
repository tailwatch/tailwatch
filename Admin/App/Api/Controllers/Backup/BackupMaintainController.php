<?php
/**
 * Backup Maintain Controller
 *
 * Handles backup settings retrieval and maintenance (retention, cleanup).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Backup
 */

namespace Tailwatch\Admin\App\Api\Controllers\Backup;

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\BackupModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BackupMaintainController
 *
 */
class BackupMaintainController {

	/**
	 * process_run values produced by scan-driven backups (malware scanner /
	 * integrity handoff). These are partial snapshots and must not be allowed
	 * to evict user/scheduled complete backups from the retention pool.
	 */
	const SCAN_DRIVEN_PROCESS_RUNS = array( 'malware', 'files_integrity' );

	/**
	 * Maximum number of scan-driven backups to retain. Kept small because
	 * each one is partial (7 DB tables, optionally only changed files) and
	 * is mainly useful as an immediate rollback target for the scan that
	 * produced it.
	 */
	const SCAN_BACKUP_RETENTION_LIMIT = 1;

	/**
	 * Get feature options for backup (wrapper for OptionsController).
	 *
	 * @return array
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_backup_enable';
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	public function wptw_get_backup_settings() {
		$existing_settings = $this->get_features_options();

		if ( $existing_settings ) {
			$enable_backup     = isset( $existing_settings['field_1']['options']['option']['selected'] ) ? $existing_settings['field_1']['options']['option']['selected'] : null;
			$backup_type       = null;
			$backup_option     = null;
			$secret_key        = null;
			$access_key        = null;
			$backup_interval   = null;
			$backup_maintain   = 1;
			$optimize_database = isset( $existing_settings['field_1']['sub_options']['field_8']['options']['option']['selected'] ) ? $existing_settings['field_1']['sub_options']['field_8']['options']['option']['selected'] : null;

			if ( isset( $existing_settings['field_1']['sub_options']['field_2']['options'] ) ) {
				foreach ( $existing_settings['field_1']['sub_options']['field_2']['options'] as $backup_is ) {
					if ( isset( $backup_is['selected'] ) && true === $backup_is['selected'] ) {
						$backup_type = $backup_is['value'];
					}
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_3']['options'] ) ) {
				$backup_options = $existing_settings['field_1']['sub_options']['field_3']['options'];

				foreach ( $backup_options as $option_key => $option_value ) {
					if ( isset( $option_value['selected'] ) && 1 === (int) $option_value['selected'] ) {
						$backup_option = $option_value['value'];

						if ( 'Digital Ocean' === $backup_option && isset( $option_value['sub_options'] ) ) {
							$secret_key = isset( $option_value['sub_options']['field_4']['options']['option']['value'] ) ? $option_value['sub_options']['field_4']['options']['option']['value'] : null;
							$access_key = isset( $option_value['sub_options']['field_5']['options']['option']['value'] ) ? $option_value['sub_options']['field_5']['options']['option']['value'] : null;
						}
					}
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_6']['options'] ) ) {
				foreach ( $existing_settings['field_1']['sub_options']['field_6']['options'] as $interval_option ) {
					if ( isset( $interval_option['selected'] ) && true === $interval_option['selected'] ) {
						$backup_interval = $interval_option['value'];
					}
				}
			}

			if ( isset( $existing_settings['field_1']['sub_options']['field_7']['options'] ) ) {
				foreach ( $existing_settings['field_1']['sub_options']['field_7']['options'] as $maintain_option ) {
					if ( isset( $maintain_option['selected'] ) && true === $maintain_option['selected'] ) {
						if ( 'Custom Number' === $maintain_option['value'] && isset( $maintain_option['sub_options']['field_8']['options']['option']['value'] ) ) {
							$backup_maintain = $maintain_option['sub_options']['field_8']['options']['option']['value'];
						} else {
							$backup_maintain = $maintain_option['value'];
						}
					}
				}
			}

			$response_data = array(
				'backupsEnabled'   => $enable_backup,
				'backupType'       => $backup_type,
				'backupOption'     => $backup_option,
				'secretKey'        => $secret_key,
				'accessKey'        => $access_key,
				'backupInterval'   => $backup_interval,
				'backupMaintain'   => $backup_maintain,
				'optimizeDatabase' => $optimize_database,
			);
		} else {
			$response_data = array();
		}

		return $response_data;
	}

	public function maintain_backups( $key, $option ) {
		$feature_controller = new DBModel();
		$backups_raw        = $feature_controller->get_log_value( $key, $option );
		$backups            = is_array( $backups_raw ) ? $backups_raw : array();

		$user_pool = array();
		$scan_pool = array();
		foreach ( $backups as $backup ) {
			$backup_data = json_decode( $backup['value'], true );
			if ( ! isset( $backup_data['completed'] ) || $backup_data['completed'] !== true ) {
				continue;
			}

			$process_run = isset( $backup_data['process_run'] ) ? $backup_data['process_run'] : 'backup';
			if ( in_array( $process_run, self::SCAN_DRIVEN_PROCESS_RUNS, true ) ) {
				$scan_pool[] = $backup;
			} else {
				$user_pool[] = $backup;
			}
		}

		$get_backup_setting = $this->wptw_get_backup_settings();
		$user_backup_limit  = isset( $get_backup_setting['backupMaintain'] ) ? (int) $get_backup_setting['backupMaintain'] : 2;

		$this->prune_backup_pool( $user_pool, $user_backup_limit, $feature_controller );
		$this->prune_backup_pool( $scan_pool, self::SCAN_BACKUP_RETENTION_LIMIT, $feature_controller );
	}

	/**
	 * Delete oldest backups in the given pool until its size is within the limit.
	 *
	 * @param array   $pool               Backup rows (raw DB rows with id + value).
	 * @param int     $limit              Maximum entries to keep.
	 * @param DBModel $feature_controller Shared model instance for related-entry cleanup.
	 */
	private function prune_backup_pool( array $pool, $limit, $feature_controller ) {
		$pool_size = count( $pool );
		if ( $pool_size <= $limit ) {
			return;
		}

		$backups_to_delete = $pool_size - $limit;

		usort(
			$pool,
			function ( $a, $b ) {
				$data_a = json_decode( $a['value'], true );
				$data_b = json_decode( $b['value'], true );
				return strtotime( $data_a['folderDate'] ) <=> strtotime( $data_b['folderDate'] );
			}
		);

		foreach ( $pool as $index => $backup ) {
			if ( $index >= $backups_to_delete ) {
				break;
			}

			$backup_data = json_decode( $backup['value'], true );
			$folder_name = $backup_data['folderDate'] ?? '';

			$delete_folder = WPTW_BACKUP_DIR . '/files/' . $folder_name . '/';

			$backup_controller  = new BackupController();
			$delete_backup_file = $backup_controller->delete_folder_and_files( $delete_folder );

			if ( $delete_backup_file ) {
				$backup_model = new BackupModel();
				$this->remove_related_entries( $feature_controller, $folder_name );
				$backup_model->delete_backup_by_id( (int) $backup['id'] );
			}
		}
	}

	private function remove_related_entries( $feature_controller, $folder_name ) {
		$related_entries = array(
			array(
				'key'    => 'default_backup_scan',
				'option' => 'backup_cancel_pause',
			),
			array(
				'key'    => 'default_backup_scan',
				'option' => 'scan_backup_payload',
			),
		);

		foreach ( $related_entries as $entry ) {
			$related_backups_raw = $feature_controller->get_log_value( $entry['key'], $entry['option'] );
			$related_backups     = is_array( $related_backups_raw ) ? $related_backups_raw : array();
			foreach ( $related_backups as $related_backup ) {
				$related_backup_data = json_decode( $related_backup['value'], true );
				if ( isset( $related_backup_data['folderDate'] ) && $related_backup_data['folderDate'] === $folder_name ) {
					$backup_model = new BackupModel();
					$backup_model->delete_backup_by_id( (int) $related_backup['id'] );
				}
			}
		}
	}

	public function wptw_delete_backup_folder( $post_data ) {
		try {
			// Block while any consumer of the backup artifacts is mid-flight:
			// a running backup writes to a folder we might rmdir; download /
			// restore / migration read a folder we might rmdir; manual upload
			// writes to the same paths. Strict for both single-id and
			// is_delete-all because identifying "which exact backup is in-flight"
			// requires per-module state that isn't worth the complexity for a
			// rare user action during long scans.
			$blocked = ( new ProcessGuard() )->ensure_can_modify_artifacts(
				array(
					'backup',
					'backup_download',
					'restore',
					'migration',
					'migration_backup',
					'migration_backup_upload',
				)
			);
			if ( null !== $blocked ) {
				return $blocked;
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Backup folder deletion failed: Invalid input data provided',
					array(
						'feature' => 'backup',
						'action'  => 'backup_folder_delete_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$feature_controller = new DBModel();
			$success_count      = 0;
			$failed_ids         = array();
			$failed_messages    = array();

			if ( isset( $data['is_delete'] ) && true === $data['is_delete'] ) {
				$wptw_key = 'default_backup_scan';
				$option   = 'scan_backp';

				$all_backups_raw = $feature_controller->get_log_value( $wptw_key, $option );
				$all_backups     = is_array( $all_backups_raw ) ? $all_backups_raw : array();
				if ( empty( $all_backups ) ) {
					Log::error(
						'Backup folder deletion failed: No backups found to delete',
						array(
							'feature' => 'backup',
							'action'  => 'backup_folder_delete_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'No backups found to delete.', 'tailwatch' ),
						'code'    => 404,
					);
				}

				// Extract IDs from all backups.
				$ids = array_column( $all_backups, 'id' );
			} else {
				if ( ! isset( $data['ids'] ) || ! is_array( $data['ids'] ) ) {
					Log::error(
						'Backup folder deletion failed: IDs must be provided as an array',
						array(
							'feature' => 'backup',
							'action'  => 'backup_folder_delete_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'IDs must be provided as an array, or use is_delete:true to delete all backups.', 'tailwatch' ),
						'code'    => 400,
					);
				}

				$ids = $data['ids'];

				if ( empty( $ids ) ) {
					Log::error(
						'Backup folder deletion failed: No IDs provided in the request',
						array(
							'feature' => 'backup',
							'action'  => 'backup_folder_delete_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'No IDs provided in the request.', 'tailwatch' ),
						'code'    => 400,
					);
				}
			}

			foreach ( $ids as $backup_id ) {
				$value = $feature_controller->get_feature_value( (int) $backup_id );

				if ( empty( $value ) ) {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = "No backup data found for ID: $backup_id";
					continue;
				}

				$folder_data = json_decode( $value, true );
				$folder_date = isset( $folder_data['folderDate'] ) ? $folder_data['folderDate'] : null;

				if ( empty( $folder_date ) ) {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = 'Folder name not found in backup data for ID: ' . $backup_id;
					continue;
				}

				$backup_directory    = WPTW_BACKUP_DIR . '/files/' . $folder_date . '/';
				$backup_directory_db = WPTW_BACKUP_DIR . '/database/' . $folder_date . '/';
				$backup_controller   = new BackupController();

				$delete_backup_file = $backup_controller->delete_backup_folder( $backup_directory );
				$delete_backup_db   = $backup_controller->delete_backup_folder( $backup_directory_db );

				if ( $delete_backup_file && $delete_backup_db ) {
					$this->remove_related_entries( $feature_controller, $folder_date );
					$backup_model = new BackupModel();
					if ( false !== $backup_model->delete_backup_by_id( (int) $backup_id ) ) {
						++$success_count;
					} else {
						$failed_ids[]      = $backup_id;
						$failed_messages[] = "Failed to delete the main backup entry for ID: $backup_id";
					}
				} else {
					$failed_ids[]      = $backup_id;
					$failed_messages[] = "Failed to delete the backup folder for ID: $backup_id";
				}
			}

			// Prepare response.
			$total_count       = count( $ids );
			$delete_all_impact = 'Make sure you still have a recent backup in the Vault — without one, you can\'t restore your site if something goes wrong.';
			if ( $success_count === $total_count ) {
				$noun_all = ( 1 === $success_count ) ? 'backup' : 'backups';
				Log::info(
					"You removed {$success_count} {$noun_all} from your Vault. {$delete_all_impact}",
					array(
						'feature'   => 'backup',
						'action'    => 'backup_folder_deleted',
						'title'     => "{$success_count} {$noun_all} removed",
						'count'     => $success_count,
						'meta_data' => array(
							'feature'       => 'Backup Vault',
							'event'         => 'Removed',
							'impact'        => $delete_all_impact,
							'success_count' => (int) $success_count,
							'failed_count'  => 0,
							'total'         => $total_count,
						),
					)
				);
				return array(
					'data'    => array(
						'success_count' => $success_count,
						'total'         => $total_count,
					),
					'message' => __( 'All backup folders and associated entries deleted successfully.', 'tailwatch' ),
					'code'    => 200,
				);
			} elseif ( $success_count > 0 ) {
				$failed_count          = count( $failed_ids );
				$delete_partial_impact = 'Some backups couldn\'t be deleted — check the Vault to see which ones are still there before you free up space again.';
				Log::info(
					"You removed {$success_count} of {$total_count} backups; {$failed_count} couldn't be deleted. {$delete_partial_impact}",
					array(
						'feature'       => 'backup',
						'action'        => 'backup_folder_deleted',
						'title'         => "{$success_count} of {$total_count} backups removed",
						'success_count' => $success_count,
						'failed_count'  => $failed_count,
						'meta_data'     => array(
							'feature'       => 'Backup Vault',
							'event'         => 'Partially removed',
							'impact'        => $delete_partial_impact,
							'success_count' => (int) $success_count,
							'failed_count'  => $failed_count,
							'total'         => $total_count,
						),
					)
				);
				return array(
					'data'    => array(
						'success_count'   => $success_count,
						'failed_count'    => count( $failed_ids ),
						'failed_ids'      => $failed_ids,
						'failed_messages' => $failed_messages,
						'total'           => count( $ids ),
					),
					'message' => sprintf(
						/* translators: 1: number of successfully deleted backups, 2: total number of backups */
						__( 'Deleted %1$d out of %2$d backup folders.', 'tailwatch' ),
						$success_count,
						count( $ids )
					),
					'code'    => 207, // Multi-Status
				);
			} else {
				Log::error(
					'Backup folder deletion failed: Failed to delete any backup folders',
					array(
						'feature'  => 'backup',
						'action'   => 'backup_folder_delete_failed',
						'messages' => $failed_messages,
					)
				);
				return array(
					'data'    => array(
						'failed_ids'      => $failed_ids,
						'failed_messages' => $failed_messages,
						'total'           => count( $ids ),
					),
					'message' => __( 'Failed to delete any backup folders.', 'tailwatch' ),
					'code'    => 500,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Backup folder deletion failed: ' . $e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_folder_delete_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while deleting backup folders.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_get_backup_status() {
		try {
			$backup_controller = new BackupController();
			$is_backup_enable  = $backup_controller->get_backup_feature_data();
			if ( ! $is_backup_enable['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_backup_enable['feature_enable'],
					'parent_enable'  => $is_backup_enable['parent_enable'],
					'message'        => __( 'Backup feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$get_backup_option = $this->wptw_get_backup_settings();

			$started_key        = 'default_backup_scan';
			$started_option     = 'scan_backp';
			$feature_controller = new DBModel();
			$json_data          = $feature_controller->get_recent_data( $started_option, $started_key );

			$started_time = ! empty( $json_data ) && isset( $json_data['started_time'] )
				? gmdate( 'Y-m-d H:i:s', $json_data['started_time'] ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate
				: 'Not Yet Started';

			$backup_type     = $get_backup_option['backupType'] ?? 'N/A';
			$backup_option   = $get_backup_option['backupOption'] ?? 'N/A';
			$backup_interval = $get_backup_option['backupInterval'] ?? 'N/A';
			$backup_maintain = $get_backup_option['backupMaintain'] ?? 'N/A';

			$next_scheduled          = wp_next_scheduled( 'wptw_backup_schedule_run' );
			$current_time            = time();
			$next_schedule_formatted = $next_scheduled ? gmdate( 'Y-m-d H:i:s', $next_scheduled ) : 'Not Scheduled'; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate

			$next_run = $next_scheduled ? human_time_diff( $current_time, $next_scheduled ) : __( 'Not scheduled', 'tailwatch' );

			$return_data = array(
				'started_time'   => $started_time,
				'backup_type'    => $backup_type,
				'backup_option'  => $backup_option,
				'time_interval'  => $backup_interval,
				'backupMaintain' => $backup_maintain,
				'next_schedule'  => $next_schedule_formatted,
				'next_run'       => $next_run,
			);
			return array(
				'code'    => 200,
				'data'    => $return_data,
				'message' => __( 'Data retrieved successfully', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Backup status retrieval failed: ' . $e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_status_retrieval_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while retrieving backup status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}
}
