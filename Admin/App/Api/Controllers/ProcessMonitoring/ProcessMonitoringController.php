<?php
namespace Tailwatch\Admin\App\Api\Controllers\ProcessMonitoring;

use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\RecoveryMonitor;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkStatus;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Controllers\Visit\RecommendedFeaturesController;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProcessMonitoringController {

	public function wptw_get_process_monitoring_status() {
		try {
			$processManager  = new ProcessManager();
			$recoveryMonitor = new RecoveryMonitor();

			$monitoring_status = $recoveryMonitor->get_status();

			$active_processes = $processManager->get_all_active_processes();

			$stuck_processes = $processManager->get_stuck_processes( 300 );

			// Format response
			return array(
				'code'    => 200,
				'message' => __( 'Process monitoring status retrieved successfully', 'tailwatch' ),
				'data'    => array(
					'monitoring'            => array(
						'enabled'           => $monitoring_status['monitoring_enabled'],
						'last_check'        => $monitoring_status['last_check_time'],
						'next_check_in'     => $monitoring_status['next_check_in_seconds'],
						'throttle_interval' => $monitoring_status['throttle_interval'],
					),
					'active_processes'      => $this->format_processes_for_display( $active_processes ),
					'stuck_processes_count' => count( $stuck_processes ),
					'has_issues'            => count( $stuck_processes ) > 0,
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'process_monitoring',
					'action'    => 'process_monitoring_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to retrieve process monitoring status.', 'tailwatch' ),
				'data'    => array(),
			);
		}
	}

	public function wptw_mobile_get_process_status() {
		try {
			$processManager = new ProcessManager();

			$active_processes = $processManager->get_all_active_processes();

			$formatted_processes = array();
			foreach ( $active_processes as $process ) {
				$formatted_processes[] = array(
					'process_id'  => $process['process_id'] ?? null,
					'type'        => $process['process_type'] ?? 'unknown',
					'type_label'  => $this->get_process_type_label( $process['process_type'] ?? 'unknown' ),
					'state'       => $process['state'] ?? 'unknown',
					'state_label' => $this->get_state_label( $process['state'] ?? 'unknown' ),
					'started_at'  => $process['started_at'] ?? null,
					'retry_count' => $process['retry_count'] ?? 0,
					'is_stuck'    => $this->is_process_stuck( $process ),
					'can_cancel'  => true,
					'can_retry'   => ( $process['retry_count'] ?? 0 ) < 3,
					'progress'    => $this->calculate_progress( $process ),
				);
			}

			return array(
				'code'    => 200,
				'message' => __( 'Process status retrieved successfully', 'tailwatch' ),
				'data'    => array(
					'processes'    => $formatted_processes,
					'total_active' => count( $formatted_processes ),
					'has_issues'   => $this->has_issues( $formatted_processes ),
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'process_monitoring',
					'action'    => 'mobile_process_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to retrieve process status', 'tailwatch' ),
				'data'    => array(),
			);
		}
	}

	private function format_processes_for_display( $processes ) {
		$formatted = array();

		foreach ( $processes as $process ) {
			// Process data is already decoded (no need to json_decode)
			$formatted[] = array(
				'id'             => $process['process_id'] ?? null,
				'type'           => $process['process_type'] ?? 'unknown',
				'type_label'     => $this->get_process_type_label( $process['process_type'] ?? 'unknown' ),
				'state'          => $process['state'] ?? 'unknown',
				'state_label'    => $this->get_state_label( $process['state'] ?? 'unknown' ),
				'started_at'     => $process['started_at'] ?? null,
				'last_heartbeat' => $process['last_heartbeat'] ?? null,
				'retry_count'    => $process['retry_count'] ?? 0,
				'cron_hook'      => $process['current_cron'] ?? null,
				'progress'       => $this->calculate_progress( $process ),
				'time_running'   => $this->calculate_time_running( $process['started_at'] ?? null ),
				'is_stuck'       => $this->is_process_stuck( $process ),
			);
		}

		return $formatted;
	}

	private function get_process_type_label( $type ) {
		$labels = array(
			'backup'           => 'Backup',
			'db_optimize'      => 'Database Optimizer',
			'search_replace'   => 'Search & Replace',
			'migration'        => 'Migration',
			'restore'          => 'Restore',
			'malware_scan'     => 'Malware Scan',
			'malware_restore'  => 'Malware Restore',
			'migration_backup' => 'Migration Backup',
		);

		return $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
	}

	private function get_state_label( $state ) {
		$labels = array(
			'pending'     => 'Pending',
			'in_progress' => 'In Progress',
			'stuck'       => 'Stuck',
			'failed'      => 'Failed',
			'completed'   => 'Completed',
		);

		return $labels[ $state ] ?? ucfirst( str_replace( '_', ' ', $state ) );
	}

	private function calculate_progress( $process_data ) {
		if ( isset( $process_data['metadata']['progress'] ) ) {
			return intval( $process_data['metadata']['progress'] );
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'backup' ) {
			try {
				$backup_controller = new BackupController();
				$cancel_pause      = $backup_controller->wptw_backup_cancel_pause_data();
				return isset( $cancel_pause['progress'] ) ? intval( $cancel_pause['progress'] ) : 0;
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'db_optimize' ) {
			try {
				$db_controller = new DatabaseOptimizerController();
				$cancel_pause  = $db_controller->wptw_optimization_cancel_pause();
				return isset( $cancel_pause['progress'] ) ? intval( $cancel_pause['progress'] ) : 0;
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'search_replace' ) {
			try {
				$db_controller = new SearchReplaceController();
				$cancel_pause  = $db_controller->search_replace_cancel_pause_data();
				return isset( $cancel_pause['progress'] ) ? intval( $cancel_pause['progress'] ) : 0;
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'broken_link_checker' ) {
			try {
				$db_controller = new BrokenLinkStatus();
				$cancel_pause  = $db_controller->broken_link_cancel_pause_data();
				return isset( $cancel_pause['progress'] ) ? intval( $cancel_pause['progress'] ) : 0;
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'reset_all' ) {
			try {
				$reset_id = $process_data['metadata']['reset_id'] ?? null;
				if ( ! $reset_id ) {
					return 0;
				}
				$batch_state = get_transient( $reset_id );
				if ( ! $batch_state || ! isset( $batch_state['option_keys'] ) ) {
					return 0;
				}
				$total   = count( $batch_state['option_keys'] );
				$current = $batch_state['current_index'] ?? 0;
				if ( $total <= 0 ) {
					return 0;
				}
				return intval( ( $current / $total ) * 100 );
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'settings_import' ) {
			try {
				$import_id = $process_data['metadata']['import_id'] ?? null;
				if ( ! $import_id ) {
					return 0;
				}
				$batch_state = get_transient( $import_id );
				if ( ! $batch_state || ! isset( $batch_state['data'] ) ) {
					return 0;
				}
				$total   = count( $batch_state['data'] );
				$current = $batch_state['current_index'] ?? 0;
				if ( $total <= 0 ) {
					return 0;
				}
				return intval( ( $current / $total ) * 100 );
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		if ( isset( $process_data['process_type'] ) && $process_data['process_type'] === 'recommended_features' ) {
			try {
				$controller = new RecommendedFeaturesController();
				$status     = $controller->wptw_recommended_features_process();
				return isset( $status['progress'] ) ? intval( $status['progress'] ) : 0;
			} catch ( \Exception $e ) {
				return 0;
			}
		}

		return 0;
	}

	private function calculate_time_running( $started_at ) {
		if ( ! $started_at ) {
			return null;
		}

		// Convert local MySQL datetime (from current_time('mysql')) to UTC
		// timestamp so arithmetic is consistent regardless of PHP timezone.
		$start_timestamp = (int) get_gmt_from_date( $started_at, 'U' );
		$elapsed_seconds = time() - $start_timestamp;

		return array(
			'seconds'   => $elapsed_seconds,
			'formatted' => $this->format_duration( $elapsed_seconds ),
		);
	}

	private function format_duration( $seconds ) {
		$hours   = floor( $seconds / 3600 );
		$minutes = floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%dh %dm %ds', $hours, $minutes, $secs );
		} elseif ( $minutes > 0 ) {
			return sprintf( '%dm %ds', $minutes, $secs );
		} else {
			return sprintf( '%ds', $secs );
		}
	}

	private function is_process_stuck( $process_data ) {
		if ( ! isset( $process_data['last_heartbeat'] ) ) {
			return false;
		}

		$last_heartbeat       = (int) get_gmt_from_date( $process_data['last_heartbeat'], 'U' );
		$time_since_heartbeat = time() - $last_heartbeat;

		// Stuck if no heartbeat for 5+ minutes (300 seconds).
		return $time_since_heartbeat >= 300;
	}

	private function has_issues( $processes ) {
		foreach ( $processes as $process ) {
			if ( $process['is_stuck'] || $process['state'] === 'failed' ) {
				return true;
			}
		}
		return false;
	}
}
