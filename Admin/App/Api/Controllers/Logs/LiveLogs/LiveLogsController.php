<?php
/**
 * Live Logs Controller
 *
 * Handles live log import and progress updates for backup and other features.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Logs\LiveLogs
 */

namespace Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs;

use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkStatus;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LiveLogsController
 *
 */
class LiveLogsController {

	public function tailwatch_import_live_logs( $post_data, $live_logs, $get_progress, $feature_type, $extra_params = null ) {
		try {

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON input for live logs import: ' . json_last_error_msg(),
					array(
						'feature' => 'live_logs',
						'action'  => 'live_logs_process_failed',
					)
				);
				return array(
					'new_logs'       => array(),
					'progress'       => 0,
					'scan_state'     => null,
					'scan_type'      => null,
					'cron_running'   => false,
					'is_completed'   => false,
					'last_log_index' => 0,
					'message'        => __( 'Invalid JSON input.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			if ( ! file_exists( $live_logs ) ) {
				Log::error(
					"Live logs file does not exist: {$live_logs}",
					array(
						'feature' => 'live_logs',
						'action'  => 'live_logs_process_failed',
					)
				);
				return array(
					'new_logs'       => array(),
					'progress'       => 0,
					'scan_state'     => null,
					'scan_type'      => null,
					'cron_running'   => false,
					'is_completed'   => false,
					'last_log_index' => 0,
					'message'        => __( 'Live logs file not found.', 'tailwatch' ),
					'code'           => 404,
				);
			}

			$fs            = FilesystemService::get_filesystem();
			$existing_logs = $fs ? $fs->get_contents( $live_logs ) : false;
			if ( $existing_logs === false ) {
				Log::error(
					"Failed to read live logs file: {$live_logs}",
					array(
						'feature' => 'live_logs',
						'action'  => 'live_logs_process_failed',
					)
				);
				return array(
					'new_logs'       => array(),
					'progress'       => 0,
					'scan_state'     => null,
					'scan_type'      => null,
					'cron_running'   => false,
					'is_completed'   => false,
					'last_log_index' => 0,
					'message'        => __( 'Failed to read live logs file.', 'tailwatch' ),
					'code'           => 500,
				);
			}

			$logs_data = json_decode( $existing_logs, true );
			if ( $logs_data === null && json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON format in live logs file: ' . json_last_error_msg(),
					array(
						'feature' => 'live_logs',
						'action'  => 'live_logs_process_failed',
					)
				);
					return array(
						'new_logs'       => array(),
						'progress'       => 0,
						'scan_state'     => null,
						'scan_type'      => null,
						'cron_running'   => false,
						'is_completed'   => false,
						'last_log_index' => 0,
						'message'        => __( 'Invalid logs file format.', 'tailwatch' ),
						'code'           => 500,
					);
			}

			$client_last_index = isset( $data['last_log_index'] ) ? (int) $data['last_log_index'] : 0;

			$new_logs          = array();
			$current_log_index = 0;

			if ( isset( $logs_data['message'] ) && is_array( $logs_data['message'] ) ) {
				$all_logs   = $logs_data['message'];
				$total_logs = count( $all_logs );
				if ( $client_last_index === 0 ) {
					$new_logs = $all_logs;
				} elseif ( $client_last_index < $total_logs ) {
					$new_logs = array_slice( $all_logs, $client_last_index );
				}
				$current_log_index = $total_logs;
			}

			$is_completed = isset( $logs_data['is_completed'] ) ? $logs_data['is_completed'] : false;
			$progress     = isset( $get_progress['progress'] ) ? $get_progress['progress'] : 1;

			if ( $is_completed == true ) {
				$progress                 = 100;
				$get_progress['progress'] = $progress;
			} elseif ( ! empty( $new_logs ) && $progress < 95 ) {
				$progress                += 1;
				$get_progress['progress'] = $progress;
			}

			try {
				$this->tailwatch_update_progress_status( $get_progress, $feature_type );
			} catch ( \Throwable $e ) {
				Log::error(
					'Failed to update progress status: ' . $e->getMessage(),
					array(
						'feature'   => 'live_logs',
						'action'    => 'live_logs_process_failed',
						'exception' => $e,
					)
				);
			}

			$scan_state   = isset( $get_progress['scan_state'] ) ? $get_progress['scan_state'] : null;
			$scan_type    = isset( $get_progress['scan_type'] ) ? $get_progress['scan_type'] : null;
			$cron_running = isset( $get_progress['cron_running'] ) ? $get_progress['cron_running'] : false;
			$timezone     = wp_timezone_string();

			$estimation_time = null;
			if ( ! $is_completed && $scan_state === 'in-progress' ) {
				$estimation_data = $this->tailwatch_calculate_estimated_completion_time( $feature_type, $get_progress, $extra_params );
				$estimation_time = $estimation_data['estimated_completion_timestamp'];
			}

			$response = array(
				'new_logs'                  => $new_logs,
				'progress'                  => $progress,
				'scan_state'                => $scan_state,
				'scan_type'                 => $scan_type,
				'cron_running'              => $cron_running,
				'is_completed'              => $is_completed,
				'last_log_index'            => $current_log_index,
				'message'                   => '',
				'started_time'              => isset( $get_progress['started_time'] ) ? $get_progress['started_time'] : null,
				'function_started'          => isset( $get_progress['function_started'] ) ? $get_progress['function_started'] : false,
				'function_completed'        => isset( $get_progress['function_completed'] ) ? $get_progress['function_completed'] : false,
				'completion_timestamp'      => isset( $get_progress['completion_timestamp'] ) ? $get_progress['completion_timestamp'] : null,
				'current_timestamp'         => time(),
				'estimated_completion_time' => $estimation_time,
				'timezone'                  => $timezone,
				'code'                      => 200,
			);

			if ( $extra_params !== null ) {
				$response['extra_params'] = $extra_params;
			}

			return $response;

		} catch ( \Throwable $e ) {
			Log::error(
				'Exception during live logs process: ' . $e->getMessage(),
				array(
					'feature'   => 'live_logs',
					'action'    => 'live_logs_process_failed',
					'exception' => $e,
				)
			);
			return array(
				'new_logs'       => array(),
				'progress'       => 0,
				'scan_state'     => null,
				'scan_type'      => null,
				'cron_running'   => false,
				'is_completed'   => false,
				'last_log_index' => 0,
				'message'        => __( 'An unexpected error occurred during live logs import.', 'tailwatch' ),
				'code'           => 500,
			);
		}
	}

	public function tailwatch_calculate_estimated_completion_time( $feature_type, $get_progress, $extra_params = array() ) {
		// Use time() (UTC) because all controllers store started_time as time() (UTC).
		// Using current_time('timestamp') here would add the timezone offset to the
		// elapsed calculation, producing wrong ETA on non-UTC servers.
		$current_time = time();
		$started_time = isset( $get_progress['started_time'] ) ? (int) $get_progress['started_time'] : $current_time;
		$elapsed_time = max( 1, $current_time - $started_time );
		$progress     = max( 0.1, isset( $get_progress['progress'] ) ? (float) $get_progress['progress'] : 0.1 );

		$estimated_remaining = 0;

		switch ( $feature_type ) {
			case 'create_backup':
				if ( isset( $get_progress['site_size'] ) && isset( $extra_params['backup_size'] ) ) {
					$site_size      = $get_progress['site_size']; // Total size in bytes
					$backup_size    = $extra_params['backup_size']; // Current backup size in bytes
					$remaining_size = max( 0, $site_size - $backup_size ); // Remaining size in bytes

					if ( $backup_size > 0 && $site_size > 0 && $elapsed_time > 10 ) {
						// Calculate backup rate (bytes per second) using recent progress
						$backup_rate = $backup_size / $elapsed_time;

						// Apply a smoothing factor to avoid overreacting to initial slow progress
						$min_backup_rate = 1024 * 1024 / 2; // 0.5 MB/s as a minimum reasonable speed
						$backup_rate     = max( $backup_rate, $min_backup_rate );

						// Estimate remaining time
						$estimated_remaining = $backup_rate > 0 ? ceil( $remaining_size / $backup_rate ) : 300;

						// Add a small buffer (e.g., 30 seconds) for finalization
						$estimated_remaining += 30;

						// Cap the estimate to prevent extreme values
						$max_remaining_time  = max( 300, $remaining_size / ( 1024 * 1024 ) * 1.5 ); // 1.5 seconds per MB
						$estimated_remaining = min( $estimated_remaining, $max_remaining_time );
					} else {
						// Fallback for early stages or missing data
						$remaining_size_mb   = $remaining_size / ( 1024 * 1024 );
						$estimated_remaining = ceil( $remaining_size_mb * 1.5 ) + 60; // 1.5 seconds per MB + 60s buffer
					}
				} elseif ( isset( $extra_params['total_size'] ) && $extra_params['total_size'] > 0 ) {
					// Fallback when only total_size is provided
					$total_size_mb       = $extra_params['total_size'] / ( 1024 * 1024 );
					$estimated_remaining = ceil( $total_size_mb * 1.5 ) + 60; // 1.5 seconds per MB + 60s buffer
				} elseif ( $progress >= 10 ) {
					// Progress-based estimation
					$total_estimated_time = ( $elapsed_time * 100 ) / $progress;
					$estimated_remaining  = max( 30, $total_estimated_time - $elapsed_time );
				} else {
					// Default fallback for early stages
					$estimated_remaining = max( 120, min( $elapsed_time * 5, 600 ) ); // Reduced from 1800s cap
				}
				break;

			// Other cases (database_optimizer, etc.) remain unchanged
			case 'database_optimizer':
				if ( $progress >= 10 ) {
					$total_estimated_time = ( $elapsed_time * 100 ) / $progress;
					$estimated_remaining  = max( 30, $total_estimated_time - $elapsed_time );
				} else {
					$estimated_remaining = max( 120, min( $elapsed_time * 8, 900 ) );
				}
				break;

			case 'files_integrity':
				if ( $progress >= 10 ) {
					$total_estimated_time = ( $elapsed_time * 100 ) / $progress;
					$estimated_remaining  = max( 30, $total_estimated_time - $elapsed_time );
				} else {
					$estimated_remaining = max( 90, min( $elapsed_time * 6, 600 ) );
				}
				break;

			case 'hardening_audit':
				// 17 short checks; even a worst-case slow run wraps in a
				// few minutes, so keep the early-stage cap tight.
				if ( $progress >= 10 ) {
					$total_estimated_time = ( $elapsed_time * 100 ) / $progress;
					$estimated_remaining  = max( 15, $total_estimated_time - $elapsed_time );
				} else {
					$estimated_remaining = max( 30, min( $elapsed_time * 4, 180 ) );
				}
				break;

			case 'search_replace':
			case 'migration_process':
			case 'restore_process':
				if ( $progress >= 10 ) {
					$total_estimated_time = ( $elapsed_time * 100 ) / $progress;
					$estimated_remaining  = max( 30, $total_estimated_time - $elapsed_time );
				} else {
					$estimated_remaining = max( 60, min( $elapsed_time * 5, 480 ) );
				}
				break;

			default:
				$estimated_remaining = max( 60, min( $elapsed_time * 3, 300 ) );
		}

		$estimated_remaining            = ceil( $estimated_remaining );
		$estimated_completion_timestamp = $current_time + $estimated_remaining;

		return array(
			'estimated_remaining_seconds'    => $estimated_remaining,
			'estimated_completion_timestamp' => $estimated_completion_timestamp,
		);
	}

	public function tailwatch_update_progress_status( $get_progress, $feature_type ) {
		$premium_handled = apply_filters( 'tailwatch_handle_premium_progress_update', false, $get_progress, $feature_type );
		if ( $premium_handled !== false ) {
			return;
		}

		switch ( $feature_type ) {
			case 'create_backup':
				$backup_controller = new BackupController();
				$backup_controller->update_backup_cancel_pause( $get_progress );
				break;
			case 'database_optimizer':
				$database_clean = new DatabaseOptimizerController();
				$database_clean->update_db_optimization_cancel_pause( $get_progress );
				break;
			case 'search_replace':
				$search_replace = new SearchReplaceController();
				$search_replace->update_search_replace_cancel_pause( $get_progress );
				break;
			case 'files_integrity':
				$files_integrity = new IntegrityWatchController();
				$files_integrity->update_files_integrity_cancel_pause( $get_progress );
				break;
			case 'hardening_audit':
				$hardening_audit = new HardeningAuditController();
				$hardening_audit->update_cancel_pause_data( $get_progress );
				break;
			case 'broken_links':
				$broken_links = new BrokenLinkStatus();
				$broken_links->update_broken_link_cancel_pause( $get_progress );
				break;
			default:
				break;
		}
	}

	public function insert_live_logs_records( $message, $logs_directory, $get_live_logs, $level = 'INFO' ) {

		if ( ! file_exists( $logs_directory ) ) {
			wp_mkdir_p( $logs_directory );
		}

		$timestamp    = current_time( 'mysql' );
		$message_Data = '[' . $timestamp . '] [' . $level . '] ' . $message;

		$data_value = wp_json_encode(
			array(
				'message'      => array( $message_Data ),
				'is_completed' => false,
			),
			JSON_PRETTY_PRINT
		);

		if ( ! file_exists( $get_live_logs ) ) {
			$fs = FilesystemService::get_filesystem();
			if ( $fs ) {
				$fs->put_contents( $get_live_logs, $data_value, FS_CHMOD_FILE );
			}
		}
	}

	public function update_live_logs_records( $message, $get_live_logs, $level = 'INFO' ) {
		$timestamp = current_time( 'mysql' );

		$message_Data = '[' . $timestamp . '] [' . $level . '] ' . $message;

		$fs            = FilesystemService::get_filesystem();
		$existing_logs = $fs ? $fs->get_contents( $get_live_logs ) : false;
		$get_data      = ( false === $existing_logs ) ? null : json_decode( $existing_logs, true );

		if ( ! $get_data || ! isset( $get_data['message'] ) ) {
			$get_data = array( 'message' => array() );
		}

		$get_data['message'][] = $message_Data;

		$updated_logs = wp_json_encode( $get_data );
		if ( $fs ) {
			$fs->put_contents( $get_live_logs, $updated_logs, FS_CHMOD_FILE );
		}
	}

	/**
	 * Append MANY messages in a single read+write. Same on-disk shape as
	 * update_live_logs_records (one `[ts] [level] msg` line per message), but it
	 * reads/decodes/encodes/writes the file ONCE for the whole batch instead of
	 * once per message — turning an O(N^2) per-file log loop into O(N). Use this
	 * when emitting a list (e.g. thousands of changed/deleted files in one scan
	 * batch); single messages keep using update_live_logs_records.
	 *
	 * @param array  $messages      Plain message strings (no timestamp/level prefix).
	 * @param string $get_live_logs Live-log file path.
	 * @param string $level         Log level applied to every message.
	 * @return void
	 */
	public function append_live_logs_records( array $messages, $get_live_logs, $level = 'INFO' ) {
		if ( empty( $messages ) ) {
			return;
		}

		$timestamp = current_time( 'mysql' );

		$fs            = FilesystemService::get_filesystem();
		$existing_logs = $fs ? $fs->get_contents( $get_live_logs ) : false;
		$get_data      = ( false === $existing_logs ) ? null : json_decode( $existing_logs, true );

		if ( ! $get_data || ! isset( $get_data['message'] ) ) {
			$get_data = array( 'message' => array() );
		}

		foreach ( $messages as $message ) {
			$get_data['message'][] = '[' . $timestamp . '] [' . $level . '] ' . $message;
		}

		$updated_logs = wp_json_encode( $get_data );
		if ( $fs ) {
			$fs->put_contents( $get_live_logs, $updated_logs, FS_CHMOD_FILE );
		}
	}

	public function tailwatch_live_logs_completed( $is_completed, $get_live_logs, $clear_message = false ) {
		$fs            = FilesystemService::get_filesystem();
		$existing_logs = $fs ? $fs->get_contents( $get_live_logs ) : false;
		$get_data      = ( false === $existing_logs ) ? array() : (array) json_decode( $existing_logs, true );

		if ( $clear_message === true ) {
			$get_data['message'] = array();
		}

		$get_data['is_completed'] = $is_completed;
		$updated_logs            = wp_json_encode( $get_data );
		if ( $fs ) {
			$fs->put_contents( $get_live_logs, $updated_logs, FS_CHMOD_FILE );
		}
	}
}
