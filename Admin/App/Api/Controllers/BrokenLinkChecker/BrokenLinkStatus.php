<?php

namespace Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Redirections\RedirectionsManager;

class BrokenLinkStatus {

	private $feature       = 'broken_link_checker';
	private $get_live_logs = TAILWATCH_LOGS_DIRECTORY . '/broken-links' . '/broken-links-logs.json';
	private $processManager;

	public function __construct() {
		$this->processManager = new ProcessManager();
	}

	public function broken_link_cancel_pause_data() {
		$feature_controller = new DBModel();
		$tailwatch_key           = 'default_broken_link_checker';
		$option             = 'broken_link_cancel_pause';
		// By id, as update_broken_link_cancel_pause() writes. Each scan adds a row, and by
		// date an older one can sort newest once the site's timezone moves back.
		return $feature_controller->get_recent_data_by_id( $option, $tailwatch_key, TAILWATCH_DB_LOGS_TABLE_NAME );
	}

	/**
	 * Stores the scan's status row.
	 *
	 * @param array $options            The row to store.
	 * @param bool  $allow_state_change Resume only: put a stopped scan back to work.
	 */
	public function update_broken_link_cancel_pause( array $options, $allow_state_change = false ) {
		$options = $this->tailwatch_keep_stop_state( $options, $allow_state_change );

		$DBModel  = new DBModel();
		$tailwatch_key = 'default_broken_link_checker';
		$option   = 'broken_link_cancel_pause';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$DBModel->update_recent_row( $db_data, $tailwatch_key, $option, TAILWATCH_DB_LOGS_TABLE_NAME );
	}

	/**
	 * Keep a pause, cancel or finish from being undone by an older copy of this row.
	 *
	 * The live-log poll and the scan tick write back copies they read earlier. One read
	 * before the user paused, or before the scan finished, would put 'in-progress' back: the
	 * scan would carry on after Pause, or the screen would spin at 100%. A finished row is kept
	 * whole, since nothing in an older copy is newer than it. Only resume moves a stopped
	 * scan back to work, and it says so.
	 *
	 * @param array $options            The row about to be stored.
	 * @param bool  $allow_state_change Resume only.
	 * @return array
	 */
	private function tailwatch_keep_stop_state( array $options, $allow_state_change ) {
		if ( $allow_state_change || ! isset( $options['scan_state'] ) || 'in-progress' !== $options['scan_state'] ) {
			return $options;
		}

		$current = $this->broken_link_cancel_pause_data();
		if ( ! is_array( $current ) || ! isset( $current['scan_state'] ) ) {
			return $options;
		}
		if ( in_array( $current['scan_state'], array( 'completed', 'failed' ), true ) ) {
			return $current;
		}
		if ( in_array( $current['scan_state'], array( 'pause', 'cancel' ), true ) ) {
			$options['scan_state'] = $current['scan_state'];
		}

		return $options;
	}

	public function update_broken_link_logs_records( $message, $level = 'INFO' ) {
		$live_logs = new LiveLogsController();
		$live_logs->update_live_logs_records( $message, $this->get_live_logs, $level );
	}

	public function tailwatch_get_log_file_path() {
		$broken_link_controller = new BrokenLinkChecker();
		$broken_link_data       = $broken_link_controller->tailwatch_get_broken_link_data();
		$broken_data            = json_decode( $broken_link_data->value, true );
		return $this->get_live_logs . '_' . $broken_data['file_key'] . '.json';
	}

	public function tailwatch_broken_link_checker_live_logs( $post_data ) {
		try {
			$broken_link_controller = new BrokenLinkChecker();
			$is_enabled             = $broken_link_controller->tailwatch_broken_link_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Broken Link feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$cancel_pause = $this->broken_link_cancel_pause_data();
			$feature_type = 'broken_links';

			$livelogs = new LiveLogsController();
			return $livelogs->tailwatch_import_live_logs( $post_data, $this->get_live_logs, $cancel_pause, $feature_type );
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_broken_link_checker_live_logs: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => 'broken_link_check_live_logs_failed',
					'title'  => 'Broken Links Live Logs Failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . Log::trace( $e ),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to import Broken Links Live Logs.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Listener for 'tailwatch_recovery_process_failed': recovery gave up on a stuck scan.
	 *
	 * @param array $process_data The process recovery gave up on.
	 * @return void
	 */
	public function tailwatch_on_recovery_failed( $process_data ) {
		if ( is_array( $process_data ) && isset( $process_data['process_type'] ) && 'broken_link_checker' === $process_data['process_type'] ) {
			$this->tailwatch_mark_run_failed( $process_data['process_id'] ?? null );
		}
	}

	/**
	 * Put a scan that stopped on an error into 'failed', so its screen stops showing it running.
	 *
	 * Only a scan still in progress is converted, and only the one the failed process belongs
	 * to, so a newer scan is never touched.
	 *
	 * @param string|null $process_id The process that failed.
	 * @return void
	 */
	public function tailwatch_mark_run_failed( $process_id ) {
		$row = $this->broken_link_cancel_pause_data();
		if ( ! is_array( $row ) || 'in-progress' !== ( $row['scan_state'] ?? '' ) ) {
			return;
		}
		if ( ! empty( $process_id ) && ! empty( $row['process_id'] ) && $row['process_id'] !== $process_id ) {
			return;
		}

		$timestamp = wp_next_scheduled( 'tailwatch_broken_link_checker' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'tailwatch_broken_link_checker' );
		}

		$row['scan_state']   = 'failed';
		$row['cron_running'] = false;
		$this->update_broken_link_cancel_pause( $row );
	}

	public function tailwatch_stop_broken_link_cron() {
		$cancel_pause = $this->broken_link_cancel_pause_data();

		if ( ! empty( $cancel_pause['scan_state'] ) && in_array( $cancel_pause['scan_state'], array( 'pause', 'cancel', 'failed' ), true ) ) {

			$timestamp = wp_next_scheduled( 'tailwatch_broken_link_checker' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_broken_link_checker' );
			}

			if ( $cancel_pause['cron_running'] === true ) {
				$cancel_pause['cron_running'] = false;
				$this->update_broken_link_cancel_pause( $cancel_pause );
			}

			return true;
		}

		return false;
	}

	public function tailwatch_resume_broken_link_checker() {
		$user_context  = 'system';
		$action_failed = 'broken_link_check_resume_failed';
		try {
			$broken_link_controller = new BrokenLinkChecker();
			$is_enabled             = $broken_link_controller->tailwatch_broken_link_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Broken Link feature is not enabled',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Broken Link feature is not enabled',
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Broken Link feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$cancel_pause = $this->broken_link_cancel_pause_data();

			if ( ! empty( $cancel_pause ) && ! empty( $cancel_pause['scan_state'] ) && $cancel_pause['scan_state'] === 'pause' ) {
				// Resumed only while nothing that cannot run beside it is running or paused.
				$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'broken_link_checker' );
				if ( null !== $blocked ) {
					return $blocked;
				}

				wp_schedule_single_event( time() + 10, 'tailwatch_broken_link_checker' );

				$cancel_pause['scan_state'] = 'in-progress';
				$this->update_broken_link_cancel_pause( $cancel_pause, true );

				$process_id = $cancel_pause['process_id'] ?? null;
				if ( $process_id ) {
					$this->processManager->update_state( $process_id, 'in_progress' );
					$this->processManager->heart_beat( $process_id );
				}

				Log::info(
					'The Broken Links process was resumed.',
					array(
						'feature' => $this->feature,
						'action'  => 'broken_link_check_resumed',
						'title'  => 'Broken Links',
						'origin'  => 'system',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Broken Links Resume Successfully', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				Log::error(
					'No paused Broken Links process to resume.',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'No paused Broken Links process already running',
						'origin'   => $user_context,
						'severity' => 'medium',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Broken Links Already Schedule', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_resume_broken_link_checker: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => $action_failed,
					'title'  => 'Broken Links',
					'detail'   => $e->getMessage() . "\nStack Trace: " . Log::trace( $e ),
					'origin'   => $user_context,
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error resuming Broken Links.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_cancel_pause_broken_link( $post_data ) {
		$user_context  = 'system';
		$action_failed = 'broken_link_check_cancel_pause_failed';
		try {
			$broken_link_controller = new BrokenLinkChecker();
			$is_enabled             = $broken_link_controller->tailwatch_broken_link_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Broken Link feature is not enabled',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Broken Link feature is not enabled',
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Broken Link feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$cancel_pause = $this->broken_link_cancel_pause_data();

			if ( ! empty( $data['scan_state'] ) && ( $data['scan_state'] === 'pause' || $data['scan_state'] === 'cancel' ) ) {
				$current_state = ( is_array( $cancel_pause ) && isset( $cancel_pause['scan_state'] ) ) ? $cancel_pause['scan_state'] : '';
				// A finished scan is not reopened or marked failed by a late click, and Pause only
				// means something to a scan that is running.
				if ( 'completed' === $current_state || ( 'pause' === $data['scan_state'] && 'in-progress' !== $current_state ) ) {
					return array(
						'data'    => array(),
						'message' => ( 'completed' === $current_state )
							? __( 'This run has already finished, so there is nothing to pause or cancel.', 'tailwatch' )
							: __( 'Only a run that is in progress can be paused.', 'tailwatch' ),
						'code'    => 409,
					);
				}

				$cancel_pause['scan_state'] = $data['scan_state'];
				$this->update_broken_link_cancel_pause( $cancel_pause );

				$timestamp = wp_next_scheduled( 'tailwatch_broken_link_checker' );
				if ( true ) {
					wp_unschedule_event( $timestamp, 'tailwatch_broken_link_checker' );
					$process_id = $cancel_pause['process_id'] ?? null;

					if ( $data['scan_state'] === 'cancel' ) {
						if ( $process_id ) {
							$this->processManager->mark_failed( $process_id, 'Broken Link cancelled by user' );
						}
						$message = __( 'Broken Links canceled successfully.', 'tailwatch' );
						Log::info(
							$message,
							array(
								'feature' => $this->feature,
								'action'  => 'broken_link_check_cancelled',
								'title'  => 'Broken Links',
								'origin'  => 'system',
							)
						);
					} elseif ( $data['scan_state'] === 'pause' ) {
						if ( $process_id ) {
							$this->processManager->update_state( $process_id, 'pause' );
							Log::info(
								'Broken Link Checker paused. Process ID: ' . $process_id,
								array(
									'feature' => $this->feature,
									'action'  => 'broken_link_check_paused',
									'title'  => 'Broken Links',
									'origin'  => 'system',
								)
							);
						}
						$message = __( 'Broken Linker Checker paused successfully. You can resume it later.', 'tailwatch' );
						Log::info(
							$message,
							array(
								'feature' => $this->feature,
								'action'  => 'broken_link_check_paused',
								'title'  => 'Broken Links',
								'origin'  => 'system',
							)
						);
						$cancel_pause['cron_running'] = false;
						$this->update_broken_link_cancel_pause( $cancel_pause );
					} else {
						Log::error(
							'Scan state must be pause or cancel. Received: ' . $data['scan_state'],
							array(
								'feature'  => $this->feature,
								'action'   => $action_failed,
								'title'  => 'Broken Links',
								'detail'   => 'Scan state must be pause or cancel. Received: ' . $data['scan_state'],
								'origin'   => $user_context,
								'severity' => 'high',
							)
						);
						return array(
							'data'    => array(),
							'message' => __( 'Invalid stop type provided.', 'tailwatch' ),
							'code'    => 400,
						);
					}

					return array(
						'data'       => array(),
						'process_id' => $process_id,
						'message'    => $message,
						'code'       => 200,
					);
				} else {
					Log::error(
						'No scheduled Broken Links found to pause or stop',
						array(
							'feature'  => $this->feature,
							'action'   => $action_failed,
							'title'  => 'Broken Links',
							'detail'   => 'No scheduled Broken Links found to pause or stop',
							'origin'   => $user_context,
							'severity' => 'medium',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'No Scheduled Broken Linker Checker found to pause or stop.', 'tailwatch' ),
						'code'    => 404,
					);
				}
			} else {
				Log::error(
					'Stop type is missing in input data',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Stop type is missing in input data',
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Stop type is missing.', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_cancel_pause_broken_link: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => $action_failed,
					'title'  => 'Broken Links',
					'detail'   => $e->getMessage() . "\nStack Trace: " . Log::trace( $e ),
					'origin'   => $user_context,
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error pausing or cancelling Broken Links.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_broken_links_cron_if_failed() {
		$user_context     = 'system';
		$action_failed    = 'broken_link_check_cron_if_failed_on_attempt';
		$action_completed = 'broken_link_check_started';
		try {
			$broken_link_controller = new BrokenLinkChecker();
			$is_enabled             = $broken_link_controller->tailwatch_broken_link_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Broken Link feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$cancel_pause = $this->broken_link_cancel_pause_data();
			if ( ! is_array( $cancel_pause ) ) {
				return array(
					'data'    => '',
					'message' => __( 'There is no run to restart.', 'tailwatch' ),
					'code'    => 409,
				);
			}
			// A scan that stopped on an error stays stopped: the screen asks for this while it
			// polls, and a tick scheduled here would run the failed step again.
			if ( in_array( $cancel_pause['scan_state'] ?? '', array( 'failed', 'completed' ), true ) ) {
				return array(
					'data'    => '',
					'message' => __( 'This run has stopped, so there is nothing to restart.', 'tailwatch' ),
					'code'    => 409,
				);
			}
			if ( ( $cancel_pause['cron_running'] ?? null ) === false ) {
				if ( ! wp_next_scheduled( 'tailwatch_broken_link_checker' ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, 'tailwatch_broken_link_checker' );

					if ( $cron_scheduled ) {
						Log::info(
							'Successfully scheduled a new Broken Links cron job.',
							array(
								'feature' => $this->feature,
								'action'  => $action_completed,
								'title'  => 'Broken Links',
								'origin'  => 'system',
							)
						);
						return array(
							'data'    => '',
							'message' => __( 'Again attempt to run the cron', 'tailwatch' ),
							'code'    => 200,
						);
					} else {
						Log::error(
							'Failed to schedule tailwatch_broken_link_checker',
							array(
								'feature'  => $this->feature,
								'action'   => $action_failed,
								'title'  => 'Broken Links',
								'detail'   => 'Failed to schedule tailwatch_broken_link_checker',
								'origin'   => $user_context,
								'severity' => 'high',
							)
						);
						return array(
							'code'    => 500,
							'data'    => array(),
							'message' => __( 'Error: Cron job could not be scheduled. Please try again.', 'tailwatch' ),
						);
					}
				} else {
					Log::info(
						'Cron job is already scheduled for Broken Links.',
						array(
							'feature' => $this->feature,
							'action'  => $action_completed,
							'title'  => 'Broken Links',
							'origin'  => 'system',
						)
					);
					return array(
						'data'    => '',
						'message' => __( 'Cron job is already scheduled.', 'tailwatch' ),
						'code'    => 200,
					);
				}
			} else {
				Log::info(
					'Broken Links cron job is currently running.',
					array(
						'feature' => $this->feature,
						'action'  => $action_completed,
						'title'  => 'Broken Links',
						'origin'  => 'system',
					)
				);
				return array(
					'data'    => '',
					'message' => __( 'Cron job is already running.', 'tailwatch' ),
					'code'    => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_broken_links_cron_if_failed: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => $action_failed,
					'title'  => 'Broken Links',
					'detail'   => $e->getMessage() . "\nStack Trace: " . Log::trace( $e ),
					'origin'   => $user_context,
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error rescheduling cron job.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_verify_broken_link_status() {
		try {
			$broken_link_controller = new BrokenLinkChecker();
			$is_enabled             = $broken_link_controller->tailwatch_broken_link_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Broken Link feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$cancel_pause = $this->broken_link_cancel_pause_data();

			if ( ! empty( $cancel_pause ) && isset( $cancel_pause['scan_state'] ) ) {

				$scan_type = $cancel_pause['scan_type'] ?? null;
				$progress  = $cancel_pause['progress'] ?? 0;

				if ( $cancel_pause['scan_state'] === 'pause' ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'pause',
						'scan_type'    => $scan_type,
						'progress'     => $progress,
						'message'      => __( 'Broken Links was paused. You can resume it later.', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( $cancel_pause['scan_state'] === 'in-progress' ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'in-progress',
						'scan_type'    => $scan_type,
						'progress'     => $progress,
						'message'      => __( 'Broken Links is in progress. Please wait.', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( $cancel_pause['scan_state'] === 'completed' ) {
					return array(
						'is_completed' => true,
						'scan_state'   => 'completed',
						'scan_type'    => $scan_type,
						'progress'     => $progress,
						'message'      => __( 'Broken Links was completed.', 'tailwatch' ),
						'code'         => 200,
					);
				} else {
					return array(
						'is_completed' => true,
						'message'      => __( 'Currently no process is in the running.', 'tailwatch' ),
						'code'         => 200,
					);
				}
			} else {
				return array(
					'is_completed' => true,
					'message'      => __( 'Currently no process is in the running.', 'tailwatch' ),
					'code'         => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_verify_broken_link_status: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => 'broken_link_check_status_verify_failed',
					'title'  => 'Broken Links Status Failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . Log::trace( $e ),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to verify Broken Links status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_get_broken_links_logs( $post_data ) {
		$user_context  = 'system';
		$action_failed = 'broken_link_check_live_logs_failed';
		try {
			$broken_link_controller = new BrokenLinkChecker();
			$is_enabled             = $broken_link_controller->tailwatch_broken_link_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Broken Link feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			if ( empty( $post_data ) || ! is_string( $post_data ) ) {
				Log::error(
					'Invalid or empty input data.',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Invalid or empty input data.',
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid or empty input data.', 'tailwatch' ),
				);
			}

			$json_data = json_decode( wp_unslash( $post_data ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Invalid JSON format: ' . json_last_error_msg(),
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Invalid JSON format: ' . json_last_error_msg(),
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 400,
						/* translators: %s: JSON error message */
					'message' => sprintf( __( 'Invalid JSON format: %s', 'tailwatch' ), json_last_error_msg() ),
				);
			}

			$limit = isset( $json_data['limit'] ) ? absint( $json_data['limit'] ) : 10;
			$page  = isset( $json_data['page'] ) ? absint( $json_data['page'] ) : 1;
			if ( $limit <= 0 || $page <= 0 ) {
				Log::error(
					'Invalid limit or page number.',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Invalid limit or page number.',
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid limit or page number.', 'tailwatch' ),
				);
			}

			$feature_controller = new DBModel();
			$rules              = $feature_controller->getAllRedirectRules( 'default_broken_link_logs', 'active', $limit, $page, TAILWATCH_DB_LOGS_TABLE_NAME );
			if ( $rules['data'] ) {
				$redirection_manager = new RedirectionsManager();

				return array(
					'code'        => 200,
					'data'        => $rules['data'],
					'redirections_feature' => $redirection_manager->tailwatch_redirection_feature_enable(),
					'total'       => $rules['total'],
					'page'        => $rules['page'],
					'limit'       => $rules['limit'],
					'total_pages' => $rules['total_pages'],
					'message'     => __( 'Broken Link Logs retrieved successfully.', 'tailwatch' ),
				);
			} else {
				return array(
					'code'    => 404,
					'message' => __( 'No Broken Link Logs found.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_get_broken_links_logs: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => $action_failed,
					'title'  => 'Broken Links',
					'detail'   => $e->getMessage() . "\nStack Trace: " . Log::trace( $e ),
					'origin'   => $user_context,
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Error retrieving Broken Link Logs.', 'tailwatch' ),
			);
		}
	}
}
