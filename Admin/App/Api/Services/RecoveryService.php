<?php
namespace Tailwatch\Admin\App\Api\Services;

use Tailwatch\Admin\App\Api\Models\RecoveryLogModel;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RecoveryService {

	/**
	 * @var RecoveryLogModel
	 */
	private $logModel;

	/**
	 * @var ProcessManager
	 */
	private $processManager;

	public function __construct() {
		$this->logModel       = new RecoveryLogModel();
		$this->processManager = new ProcessManager();
	}

	/**
	 * Emit a recovery-system trace line to the PHP error log only when WP_DEBUG_LOG
	 * is enabled. Use this for the verbose decision tracing — major events go
	 * through the unified Log:: system instead and are queryable from the admin UI.
	 *
	 * @param string $message Pre-formatted trace line.
	 * @return void
	 */
	private function debug_trace( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated by WP_DEBUG_LOG; production-silent.
			error_log( $message );
		}
	}

	public function attempt_recovery( $process_id ) {
		$process_data = $this->processManager->get_process( $process_id );
		Log::info(
			'Attempting recovery for process: ' . $process_id,
			array(
				'feature'    => 'process_recovery',
				'action'     => 'attempt_recovery',
				'process_id' => $process_id,
			)
		);

		if ( ! $process_data ) {
			$this->debug_trace( '[TAILWATCH Recovery] attempt_recovery: process not found — ID: ' . $process_id );
			return array(
				'success' => false,
				'message' => __( 'Process not found', 'tailwatch' ),
			);
		}

		// Step 1: Verify process is actually stuck.
		if ( ! $this->processManager->is_process_stuck( $process_id ) ) {
			$this->debug_trace( sprintf(
				'[TAILWATCH Recovery] attempt_recovery: process NOT stuck — ID: %s | State: %s | Last heartbeat: %s',
				$process_id,
				$process_data['state'] ?? 'unknown',
				$process_data['last_heartbeat'] ?? 'unknown'
			) );
			return array(
				'success' => false,
				'message' => __( 'Process is not stuck', 'tailwatch' ),
			);
		}

		// Step 2: Check per-process throttling (progressive intervals).
		$retry_count = $process_data['retry_count'] ?? 0;

		$metadata              = $process_data['metadata'] ?? array();
		$last_recovery_attempt = $metadata['last_recovery_attempt'] ?? null;

		if ( $last_recovery_attempt ) {
			$time_since_last_recovery = current_time( 'timestamp' ) - strtotime( $last_recovery_attempt );
			$required_wait_time       = $this->get_progressive_wait_time( $retry_count );

			if ( $time_since_last_recovery < $required_wait_time ) {
				$this->debug_trace( sprintf(
					'[TAILWATCH Recovery] attempt_recovery THROTTLED — ID: %s | Retry #%d | Last attempt: %s | Must wait %ds more',
					$process_id,
					$retry_count,
					$last_recovery_attempt,
					$required_wait_time - $time_since_last_recovery
				) );
				return array(
					'success'        => false,
					'message'        => "Too soon to retry. Wait {$required_wait_time}s between attempts.",
					'wait_remaining' => $required_wait_time - $time_since_last_recovery,
				);
			}
		}

		// Step 3: Check if we can retry (max 3 attempts).
		if ( ! $this->processManager->can_retry( $process_id ) ) {
			$this->debug_trace( sprintf(
				'[TAILWATCH Recovery] attempt_recovery: MAX RETRIES reached — ID: %s | Type: %s | Retries: %d',
				$process_id,
				$process_data['process_type'] ?? 'unknown',
				$retry_count
			) );
			$this->processManager->mark_failed( $process_id, 'Max retry attempts reached' );

			$this->log_recovery(
				$process_id,
				array(
					'process_type'     => $process_data['process_type'],
					'recovery_attempt' => $retry_count + 1,
					'reason'           => 'Max retries exceeded',
					'action_taken'     => 'Marked process as failed',
					'recovery_success' => false,
				)
			);

			// Let the owning feature react to a permanent give-up (e.g. backup moves
			// its UI state to 'failed' so the spinner resolves). Generic by design.
			do_action( 'tailwatch_recovery_process_failed', $process_data );

			return array(
				'success' => false,
				'message' => __( 'Max retry attempts reached. Process marked as failed.', 'tailwatch' ),
			);
		}

		// Step 4: Calculate stuck duration and preserve original heartbeat for logging.
		$original_heartbeat = $process_data['last_heartbeat'];
		$last_heartbeat     = strtotime( $original_heartbeat );
		// Use current_time('timestamp') \u2014 WordPress local timezone \u2014 to match how
		// heartbeats are stored via current_time('mysql'). Using time() (UTC) here
		// gives a wrong/negative duration on servers where WP timezone != UTC.
		$now            = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local wallclock seconds for scheduling comparisons.
		$stuck_duration = $now - $last_heartbeat;

		$this->debug_trace( sprintf(
			'[TAILWATCH Recovery] Attempting recovery — ID: %s | Type: %s | State: %s | Stuck for: %ds | Retry attempt: %d | Cron: %s',
			$process_id,
			$process_data['process_type'] ?? 'unknown',
			$process_data['state'] ?? 'unknown',
			$stuck_duration,
			$retry_count + 1,
			$process_data['current_cron'] ?? 'n/a'
		) );

		// Step 5: Prepare for recovery attempt.
		$retry_attempt      = $retry_count + 1;
		$recovery_timestamp = current_time( 'mysql' );

		// Step 6: Perform process-specific recovery.
		$recovery_result = $this->recover_by_process_type( $process_data );

		// Step 7: Prepare updated process data (SINGLE UPDATE).
		$current_metadata  = $process_data['metadata'] ?? array();
		$recovery_attempts = $current_metadata['recovery_attempts'] ?? array();

		// Add this attempt to history.
		$recovery_attempts[] = array(
			'attempt'        => $retry_attempt,
			'timestamp'      => $recovery_timestamp,
			'action'         => $recovery_result['action'] ?? 'Unknown',
			'success'        => $recovery_result['success'],
			'message'        => $recovery_result['message'] ?? '',
			'stuck_duration' => $stuck_duration,
		);

		// Update process data object with ALL changes at once.
		$process_data['retry_count']    = $retry_attempt;
		$process_data['last_retry_at']  = $recovery_timestamp;
		$process_data['last_heartbeat'] = $recovery_timestamp;
		$process_data['metadata']       = array_merge(
			$current_metadata,
			array(
				'last_recovery_attempt' => $recovery_timestamp,
				'recovery_attempts'     => $recovery_attempts,
			)
		);

		// Update state based on recovery result.
		if ( $recovery_result['success'] ) {
			$process_data['state'] = 'pending'; // Recovery succeeded, ready to run.
		} else {
			$process_data['state'] = 'stuck';
		}

		// SINGLE DATABASE UPDATE with all changes.
		$this->processManager->update_process( $process_id, $process_data );

		// Step 8: Log recovery attempt (database log, separate from process record).
		$log_data = array(
			'process_type'           => $process_data['process_type'],
			'recovery_attempt'       => $retry_attempt,
			'reason'                 => "No heartbeat for {$stuck_duration} seconds",
			'last_heartbeat_time'    => $original_heartbeat,
			'stuck_duration_seconds' => $stuck_duration,
			'action_taken'           => $recovery_result['action'] ?? 'Unknown',
			'recovery_success'       => $recovery_result['success'],
			'recovery_timestamp'     => $recovery_timestamp,
			'details'                => $recovery_result['details'] ?? '',
		);

		$this->log_recovery( $process_id, $log_data );

		// Step 9: Log activity/error for visibility.
		if ( $recovery_result['success'] ) {
			$this->debug_trace( sprintf(
				'[TAILWATCH Recovery] SUCCESS — ID: %s | Attempt: %d | Action: %s',
				$process_id,
				$retry_attempt,
				$recovery_result['action'] ?? 'unknown'
			) );
			Log::info(
				"Process {$process_id} recovered (attempt {$retry_attempt})",
				array(
					'feature'    => 'process_recovery',
					'action'     => 'recovery_success',
					'process_id' => $process_id,
				)
			);
		} else {
			$this->debug_trace( sprintf(
				'[TAILWATCH Recovery] FAILED — ID: %s | Attempt: %d | Action: %s | Reason: %s',
				$process_id,
				$retry_attempt,
				$recovery_result['action'] ?? 'unknown',
				$recovery_result['message'] ?? 'unknown'
			) );
			Log::error(
				"Failed to recover process {$process_id}: " . ( $recovery_result['message'] ?? '' ),
				array(
					'feature'    => 'process_recovery',
					'action'     => 'recovery_failed',
					'process_id' => $process_id,
				)
			);
		}

		return $recovery_result;
	}

	private function get_progressive_wait_time( $retry_count ) {
		switch ( $retry_count ) {
			case 0:
				return 0; // First attempt: immediate.
			case 1:
			case 2:
			case 3:
			case 4:
			case 5:
				return 300; // Subsequent attempts: wait 5 minutes.
			default:
				return 600;
		}
	}

	/**
	 * Dispatch recovery to a process-type-specific handler.
	 *
	 * Backup is the only type that needs special logic — its handler picks
	 * the correct sub-step cron (db scan / db optimize / files / etc.) based
	 * on which stage the backup got stuck in. That stage is NOT derivable
	 * from current_cron alone (e.g. the backup may have been initiated from
	 * the browser with current_cron='manual_start'), so it stays a hard-coded
	 * dispatch.
	 *
	 * For every OTHER process type we trust the current_cron field that was
	 * recorded by ProcessManager::get_or_create_process(). This means new
	 * process types — including ones added by the pro plugin — automatically
	 * recover without any change to this file. The pro plugin no longer has
	 * to be referenced from the free plugin.
	 *
	 * If current_cron is missing or empty (very old process row, or row
	 * created with a non-cron sentinel like 'manual_start' for a non-backup
	 * type), recover_generic_process() will return an error result and the
	 * row stays in 'stuck' state for the next watchdog tick to retry.
	 *
	 * @param array $process_data Full process row from the model.
	 * @return array Recovery result with success / action / message keys.
	 */
	private function recover_by_process_type( $process_data ) {
		$process_type = $process_data['process_type'];
		Log::info(
			'Recovering process type: ' . $process_type,
			array(
				'feature'      => 'process_recovery',
				'action'       => 'recover_by_type',
				'process_type' => $process_type,
			)
		);

		// Backup uses stateful sub-step recovery — must stay a special case.
		if ( 'backup' === $process_type ) {
			return $this->recover_backup_process();
		}

		// Allow consumers / pro plugin to register custom recovery handlers
		// for their own process types via a filter. Returning a non-null array
		// short-circuits the generic recovery path. Default null = use generic.
		$custom = apply_filters( 'tailwatch_recover_process', null, $process_type, $process_data );
		if ( is_array( $custom ) && isset( $custom['success'] ) ) {
			return $custom;
		}

		// Generic path: trust the current_cron the controller recorded when it
		// created the process. Works for every type that uses a single cron
		// hook to drive its workflow (db_optimize, search_replace, integrity,
		// migration, malware_scan, restore, etc.).
		$cron_hook = $process_data['current_cron'] ?? null;
		$this->debug_trace( sprintf(
			'[TAILWATCH Recovery] recover_generic_process — ID: %s | Type: %s | Cron hook: %s',
			$process_data['process_id'] ?? 'unknown',
			$process_type,
			$cron_hook ?? 'MISSING'
		) );
		return $this->recover_generic_process( $cron_hook, $process_data );
	}

	private function recover_backup_process() {
		try {
			// Get current backup data from wp_tw_settings.
			$dbModel      = new DBModel();
			$backup_data  = $dbModel->get_recent_data( 'scan_backp', 'default_backup_scan' );
			$cancel_pause = $dbModel->get_recent_data( 'backup_cancel_pause', 'default_backup_scan' );

			if ( empty( $backup_data ) ) {
				return array(
					'success' => false,
					'action'  => 'None',
					'message' => __( 'Backup data not found', 'tailwatch' ),
					'details' => 'Cannot determine backup state',
				);
			}

			if ( ! empty( $cancel_pause ) && isset( $cancel_pause['scan_state'] ) && in_array( $cancel_pause['scan_state'], array( 'pause', 'cancel', 'failed' ), true ) ) {
				return array(
					'success' => false,
					'action'  => 'None',
					'message' => __( 'Backup is paused, cancelled, or failed', 'tailwatch' ),
					'details' => 'Recovery not scheduled due to terminal/paused state',
				);
			}

			// Route the resume through the backup-type handler first; the generic branches
			// below assume a database phase that not every backup type has.
			$backup_type  = isset( $backup_data['backupType'] ) ? $backup_data['backupType'] : '';
			$type_handled = apply_filters( 'tailwatch_handle_premium_backup_cron', false, $backup_type, $backup_data );
			if ( false !== $type_handled ) {
				return array(
					'success' => true,
					'action'  => 'Scheduled backup recovery',
					'message' => "Backup recovery scheduled for type: {$backup_type}",
					'details' => 'Resume routed by backup type handler',
				);
			}

			// A "Files Only" backup has no database phase; keep the DB branches off it.
			$db_in_scope = ( 'Files Only' !== $backup_type );

			$cron_to_schedule = null;

			if ( isset( $backup_data['database_optimize'] ) && $backup_data['database_optimize'] === true && isset( $backup_data['optimize_completed'] ) && $backup_data['optimize_completed'] === false ) {
				$cron_to_schedule = 'tailwatch_auto_db_optimize';
			}

			if ( ! $cron_to_schedule && $db_in_scope && ! isset( $backup_data['tables'] ) ) {
				$cron_to_schedule = 'tailwatch_scan_db_tables_cron';
			}

			if ( ! $cron_to_schedule && $db_in_scope && isset( $backup_data['tables'] ) ) {
				$tables_completed = isset( $backup_data['tables']['completed'] ) ? $backup_data['tables']['completed'] : false;
				if ( $tables_completed === false ) {
					$cron_to_schedule = 'tailwatch_create_db_backup_cron';
				}
			}

			if ( ! $cron_to_schedule ) {
				$cron_to_schedule = 'tailwatch_backup_daily_scan';
			}

			// Schedule the cron.
			if ( ! wp_next_scheduled( $cron_to_schedule ) ) {
				wp_schedule_single_event( time() + 10, $cron_to_schedule );

				return array(
					'success' => true,
					'action'  => "Rescheduled {$cron_to_schedule}",
					'message' => __( 'Backup process recovery initiated', 'tailwatch' ),
					'details' => "Scheduled cron: {$cron_to_schedule} to run in 10 seconds",
				);
			} else {
				return array(
					'success' => true,
					'action'  => 'Cron already scheduled',
					'message' => "{$cron_to_schedule} is already scheduled",
					'details' => 'No action needed',
				);
			}
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'action'  => 'Exception occurred',
				'message' => __( 'An error occurred.', 'tailwatch' ),
				'details' => $e->getTraceAsString(),
			);
		}
	}

	private function recover_generic_process( $cron_hook, $process_data = array() ) {
		if ( ! $cron_hook || ! is_string( $cron_hook ) ) {
			return array(
				'success' => false,
				'action'  => 'None',
				'message' => __( 'No cron hook specified for process', 'tailwatch' ),
			);
		}

		// Check pause/cancel state from process config data.
		if ( ! empty( $process_data['config'] ) ) {
			$config      = $process_data['config'];
			$data_key    = $config['cancel_pause_key'] ?? ( $config['data_key'] ?? null );
			$data_option = $config['cancel_pause_option'] ?? null;

			if ( $data_key && $data_option ) {
				$dbModel      = new DBModel();
				$cancel_pause = $dbModel->get_recent_data( $data_option, $data_key );

				if ( ! empty( $cancel_pause ) && isset( $cancel_pause['scan_state'] ) &&
					in_array( $cancel_pause['scan_state'], array( 'pause', 'cancel', 'failed' ), true ) ) {
					return array(
						'success' => false,
						'action'  => 'None',
						'message' => __( 'Process is paused, cancelled, or failed', 'tailwatch' ),
						'details' => 'Recovery not scheduled due to terminal/paused state',
					);
				}
			}
		}

		try {
			if ( ! wp_next_scheduled( $cron_hook ) ) {
				wp_schedule_single_event( time() + 10, $cron_hook );
				$this->debug_trace( '[TAILWATCH Recovery] Cron RESCHEDULED — hook: ' . $cron_hook . ' (runs in 10s)' );
				Log::info(
					'Scheduled ' . $cron_hook . ' to run in 10 seconds',
					array(
						'feature'   => 'process_recovery',
						'action'    => 'reschedule_cron',
						'cron_hook' => $cron_hook,
					)
				);
				return array(
					'success' => true,
					'action'  => "Rescheduled {$cron_hook}",
					'message' => __( 'Generic process recovery initiated', 'tailwatch' ),
				);
			}

			$this->debug_trace( '[TAILWATCH Recovery] Cron ALREADY SCHEDULED — hook: ' . $cron_hook . ' (no reschedule needed)' );
			return array(
				'success' => true,
				'action'  => 'Cron already scheduled',
				'message' => __( 'Process cron already scheduled', 'tailwatch' ),
			);
		} catch ( \Exception $e ) {
			$this->debug_trace( '[TAILWATCH Recovery] Exception in recover_generic_process — ' . $e->getMessage() );
			return array(
				'success' => false,
				'action'  => 'Exception occurred',
				'message' => __( 'An error occurred.', 'tailwatch' ),
			);
		}
	}

	private function log_recovery( $process_id, $log_data ) {
		$log_data['user_id']    = get_current_user_id();
		$log_data['process_id'] = $process_id;

		$this->logModel->log_recovery( $process_id, $log_data );
	}

	public function get_recovery_logs( $process_id ) {
		return $this->logModel->get_process_recovery_logs( $process_id );
	}

	public function get_recovery_summary() {
		return $this->logModel->get_recovery_summary();
	}
}
