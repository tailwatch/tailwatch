<?php
/**
 * Database Optimizer Controller
 *
 * Handles database optimization cron, cleanup tasks, and status.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Database\DBOptimizer
 */

namespace Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer;

use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DatabaseOptimizerController
 *
 */
class DatabaseOptimizerController {

	private $log_directory = WPTW_LOGS_DIRECTORY . '/db-optimizer-logs';
	private $get_live_logs = WPTW_LOGS_DIRECTORY . '/db-optimizer-logs' . '/database-optimize';
	private $process_manager;
	private $current_process_id;

	public function __construct() {
		$this->process_manager = new ProcessManager();

		$this->register_process_monitoring();

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_auto_db_optimize', array( $this, 'wptw_global_db_optimize_with_monitoring' ) );
		$hook_controller->add_action_hook( 'wptw_delete_optimizer_garbage_data', array( $this, 'wptw_delete_optimizer_garbage_data' ) );
	}

	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'        => 'db_optimize',
				'cron_hooks'          => array( 'wptw_auto_db_optimize', 'wptw_start_database_optimization' ),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_dboptimize_scan',
				'data_option'         => 'scan_dbtables',
				'cancel_pause_key'    => 'default_dboptimize_scan',
				'cancel_pause_option' => 'dboptimize_cancel_pause',
				'stuck_threshold'     => 120, // 5 minutes.
				'max_retries'         => 3,
				// Locks the optimizer feature itself, plus the backup feature
				// because backup-with-optimize-before-backup runs db_optimize
				// from inside a backup flow.
				'locks_features'      => array( 'default_database_optimizer', 'default_backup_enable' ),
				// Process types that, when running, prevent a user from starting
				// a manual db_optimize. Note backup is NOT listed: backup may
				// invoke db_optimize as its optimize-before-backup phase
				// (handled by adoption logic), but a *manual* optimize while
				// backup runs would clash with that phase.
				'cannot_start_while'  => array(
					'files_integrity',
					'migration',
					'restore',
					'malware_scan',
					'malware_restore',
					// System-level settings operations rewrite feature config
					// site-wide; db_optimize must wait for them to finish.
					'settings_import',
					'reset_all',
				),
			)
		);
	}

	public function wptw_db_optimize_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_database_optimizer';
		$is_active          = true;
		$options_controller = new OptionsController();
		$db_data            = $options_controller->get_features_options( $key, $option, $is_active );
		return $db_data;
	}

	public function wptw_db_optimizer_feature_enable() {
		$feature_enable = $this->wptw_db_optimize_options();

		if ( empty( $feature_enable ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = isset( $feature_enable['field_1']['options']['option']['selected'] ) ? $feature_enable['field_1']['options']['option']['selected'] : false;

		if ( true === $selected ) {
			return array(
				'parent_enable'  => true,
				'feature_enable' => true,
			);
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => false,
		);
	}

	public function database_optimizer_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_database_optimizer';
		$field_name        = 'field_1';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	public function wptw_get_optimization_data() {
		$wptw_key = 'default_dboptimize_scan';
		$option   = 'scan_dbtables';
		$db_model = new DBModel();
		return $db_model->get_recent_data( $option, $wptw_key );
	}

	public function update_db_optimization_data( array $options ) {
		$wptw_key = 'default_dboptimize_scan';
		$option   = 'scan_dbtables';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model = new DBModel();
		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	/**
	 * Tag the most-recent optimization data row as backup-driven so its
	 * completion handler triggers the backup proceed flow when it finishes.
	 * Used to "adopt" an already in-progress optimization for a backup that
	 * was started with optimize-before-backup, instead of inserting a duplicate
	 * row (which abandons the running optimizer's progress and confuses the
	 * cron — see wptw_database_optimize_start, which always inserts).
	 *
	 * Only the process_run field is mutated; all other fields (table progress,
	 * file_key, etc.) are preserved so the running cron continues from where
	 * it was. The next time the completion handler at
	 * wptw_global_db_optimize_with_monitoring() reads the row and checks
	 * `process_run === 'db_backup'`, it will flip the backup row's
	 * `optimize_completed` flag and schedule the DB-scan cron.
	 *
	 * @return bool True if the row was updated (or already correctly tagged),
	 *              false if no row existed to update.
	 */
	public function wptw_mark_optimizer_for_backup_completion() {
		$current = $this->wptw_get_optimization_data();
		if ( empty( $current ) || ! is_array( $current ) ) {
			return false;
		}

		if ( isset( $current['process_run'] ) && 'db_backup' === $current['process_run'] ) {
			return true;
		}

		$current['process_run'] = 'db_backup';
		$this->update_db_optimization_data( $current );
		return true;
	}

	public function wptw_optimization_cancel_pause() {
		$wptw_key = 'default_dboptimize_scan';
		$option   = 'dboptimize_cancel_pause';

		$db_model = new DBModel();
		return $db_model->get_recent_data( $option, $wptw_key );
	}

	public function update_db_optimization_cancel_pause( array $options ) {
		$wptw_key = 'default_dboptimize_scan';
		$option   = 'dboptimize_cancel_pause';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model = new DBModel();
		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	public function wptw_get_log_file_path() {
		$optimize_data = $this->wptw_get_optimization_data();
		return $this->get_live_logs . '_' . $optimize_data['file_key'] . '.json';
	}

	public function wptw_db_optimization_cron_if_failed() {
		try {
			$is_enabled = $this->wptw_db_optimizer_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Database Optimize feature is not enabled',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_cron_if_failed_on_attempt',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$dboptimize_state = $this->wptw_optimization_cancel_pause();
			if ( false === $dboptimize_state['cron_running'] ) {
				if ( ! wp_next_scheduled( 'wptw_auto_db_optimize' ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, 'wptw_auto_db_optimize' );

					if ( $cron_scheduled ) {
						Log::info(
							'Successfully scheduled a new Database Optimizer cron job.',
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_if_cron_failed',
							)
						);

						return array(
							'data'    => '',
							'message' => __( 'Again attempt to run the cron', 'tailwatch' ),
							'code'    => 200,
						);
					} else {
						Log::error(
							'Database Optimizer Cron scheduling failed.',
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_cron_if_failed_on_attempt',
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
						'Cron job is already scheduled for Database Optimizer.',
						array(
							'feature' => 'database_optimizer',
							'action'  => 'database_optimizer_if_cron_failed',
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
					'Database Optimizer cron job is currently running.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_if_cron_failed',
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
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'database_optimizer',
					'action'  => 'database_optimizer_cron_if_failed_on_attempt',
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Error checking cron status.', 'tailwatch' ),
			);
		}
	}

	public function wptw_stop_db_optimization_cron() {
		$dboptimize_state = $this->wptw_optimization_cancel_pause();

		if ( ! empty( $dboptimize_state['scan_state'] ) && ( 'pause' === $dboptimize_state['scan_state'] || 'cancel' === $dboptimize_state['scan_state'] ) ) {

			// Handle process state based on cancel/pause.
			$process_id = isset( $dboptimize_state['process_id'] ) ? $dboptimize_state['process_id'] : null;
			if ( $process_id ) {
				if ( 'cancel' === $dboptimize_state['scan_state'] ) {
					// Mark as failed when cancelled.
					$this->process_manager->mark_failed( $process_id, 'Database optimization cancelled by user' );
				} elseif ( 'pause' === $dboptimize_state['scan_state'] ) {
					$this->process_manager->update_state( $process_id, 'pause' );
				}
			}

			$timestamp = wp_next_scheduled( 'wptw_auto_db_optimize' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wptw_auto_db_optimize' );
			}

			if ( true === $dboptimize_state['cron_running'] ) {
				$dboptimize_state['cron_running'] = false;
				$this->update_db_optimization_cancel_pause( $dboptimize_state );
			}

			return true;
		}
	}

	public function wptw_optimize_maintain_data() {
		$optimize_data = $this->wptw_db_optimize_options();

		$revisions_maintain = '1 Day';
		if ( isset( $optimize_data['field_1']['sub_options']['field_16']['options'] ) ) {
			foreach ( $optimize_data['field_1']['sub_options']['field_16']['options'] as $maintain_option ) {
				if ( isset( $maintain_option['selected'] ) && true === $maintain_option['selected'] ) {
					if ( 'Select Days' === $maintain_option['value'] && isset( $maintain_option['sub_options']['field_17']['options']['option']['value'] ) ) {
						$revisions_field    = $maintain_option['sub_options']['field_17']['options']['option']['value'];
						$revisions_maintain = $revisions_field . ' days';
					} else {
						$revisions_maintain = $maintain_option['value'];
					}
				}
			}
		}
		return $revisions_maintain;
	}

	public function wptw_db_insert_data( $option, $key ) {
		$db_model      = new DBModel();
		$json_data     = $db_model->get_recent_data( $option, $key );
		$selected_data = array();

		// Fields to skip
		$fields_to_skip = array( 'field_15', 'field_16' );

		$skip_field_1 = 'field_1';

		if ( ! empty( $json_data['options'] ) ) {
			foreach ( $json_data['options'] as $option_key => $option_value ) {
				// Skip the specified fields, except for 'field_1' where we need to handle its sub-options.
				if ( in_array( $option_key, $fields_to_skip ) ) {
					continue;
				}

				// Process 'field_1' sub-options but skip the main 'field_1'.
				if ( $option_key === $skip_field_1 && ! empty( $option_value['sub_options'] ) ) {
					foreach ( $option_value['sub_options'] as $sub_key => $sub_value ) {
						if ( in_array( $sub_key, $fields_to_skip ) ) {
							continue;
						}

						// Check if the sub-option is selected.
						if ( ! empty( $sub_value['values']['option']['selected'] ) && 1 === (int) $sub_value['values']['option']['selected'] ) {
							$selected_data[ $sub_value['key'] ] = array(
								'is_completed'   => false,
								'rows_processed' => 0,
							);
						}
					}
					continue;
				}

				// For all other fields, check if selected and add to the data
				if ( ! empty( $option_value['values']['option']['selected'] ) && 1 === (int) $option_value['values']['option']['selected'] ) {
					$selected_data[ $option_value['key'] ] = array(
						'is_completed'   => false,
						'rows_processed' => 0,
					);
				}

				// Handle sub-options for fields other than 'field_1'.
				if ( ! empty( $option_value['sub_options'] ) ) {
					foreach ( $option_value['sub_options'] as $sub_key => $sub_value ) {
						// Skip the specified sub-fields.
						if ( in_array( $sub_key, $fields_to_skip ) ) {
							continue;
						}

						if ( ! empty( $sub_value['values']['option']['selected'] ) && 1 === (int) $sub_value['values']['option']['selected'] ) {
							$selected_data[ $sub_value['key'] ] = array(
								'is_completed'   => false,
								'rows_processed' => 0,
							);
						}
					}
				}
			}
		}

		return $selected_data;
	}

	public function wptw_database_optimize( $post_data ) {
		try {
			// Refuse to start if a conflicting process is currently running.
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'db_optimize' );
			if ( null !== $blocked ) {
				return $blocked;
			}

			$is_enabled = $this->wptw_db_optimizer_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Database Optimizer feature is not enabled',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_start_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'JSON decode failed.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_start_failed',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( isset( $data['instant_scan'] ) && true === $data['instant_scan'] ) {

				$cron_status = apply_filters(
					'wptw_test_http_cron_access_db_optimizer',
					( new CronHealthService() )->test( 'db_optimizer' )
				);
				if ( ! is_array( $cron_status ) || empty( $cron_status['success'] ) ) {
					$cron_status = array(
						'success' => false,
						'message' => isset( $cron_status['message'] ) ? $cron_status['message'] : __( 'Cron access check failed.', 'tailwatch' ),
					);
				}
				if ( ! $cron_status['success'] ) {
					return array(
						'message' => __( 'Failed to run the Database Optimizer due to an issue with the cron.', 'tailwatch' ),
						'error'   => $cron_status['message'],
						'code'    => 400,
					);
				}

				$db_optimizer_job = CronJobManager::get_instance()->get_cron_job( 'database_optimizer' );
				if ( $db_optimizer_job !== null ) {
					$db_optimizer_job->unschedule();
				}

				$scan_type = 'on-demand';
				return $this->wptw_database_optimize_start( $scan_type );
			} else {
				Log::error(
					'Instant scan not enabled.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_start_failed',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Failed to run Database Optimizer: instant_scan is false.', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_start_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Database optimization failed.', 'tailwatch' ),
			);
		}
	}

	public function wptw_database_optimize_start( $scan_type, $process_run = 'db_optimize' ) {
		try {
			$key       = 'default_feature_settings';
			$option    = 'default_database_optimizer';
			$unique_id = time();

			$selected_data_json                 = $this->wptw_db_insert_data( $option, $key );
			$selected_data_json['process_run']  = $process_run;
			$selected_data_json['file_key']     = $unique_id;

			$process_id = $this->process_manager->get_or_create_process( 'db_optimize', 'wptw_auto_db_optimize' );

			$this->current_process_id = $process_id;

			$cancel_pause = array(
				'scan_state'   => 'in-progress',
				'cron_running' => false,
				'progress'     => 1,
				'process_run'   => $process_run,
				'scan_type'    => $scan_type,
				'started_time' => time(),
				'process_id'   => $process_id,
			);

			$db_data_is = array(
				array(
					'user_id'       => '1',
					'child_of'      => $unique_id,
					'key'           => 'default_dboptimize_scan',
					'option'        => 'scan_dbtables',
					'value'         => wp_json_encode( $selected_data_json ),
					'type'          => 'JSON',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
				array(
					'user_id'       => '1',
					'child_of'      => $unique_id,
					'key'           => 'default_dboptimize_scan',
					'option'        => 'dboptimize_cancel_pause',
					'value'         => wp_json_encode( $cancel_pause ),
					'type'          => 'JSON',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
			);

			$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

			foreach ( $db_data_is as $db_data ) {
				$db_model = new DBModel();
				$result   = $db_model->insert_row( $db_data, $db_data_format );
			}

			if ( $result ) {
				if ( ! wp_next_scheduled( 'wptw_auto_db_optimize' ) ) {
					$cron_scheduled = wp_schedule_single_event( time(), 'wptw_auto_db_optimize' );

					if ( ! file_exists( WPTW_LOGS_DIRECTORY ) ) {
						wp_mkdir_p( WPTW_LOGS_DIRECTORY );
					}

					if ( $cron_scheduled ) {
						$message   = 'Starting database optimization task';
						$live_logs = new LiveLogsController();
						$live_logs->insert_live_logs_records( $message, $this->log_directory, $this->wptw_get_log_file_path() );

						if ( 'db_backup' === $process_run ) {
							$backup_controller = new BackupController();
							$backup_controller->update_logs_records( $message );
						}

						$this->process_manager->heart_beat( $process_id );
						$this->process_manager->update_state( $process_id, 'in_progress' );

						Log::info(
							'Tables scanned successfully. Optimization Started.',
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_started',
							)
						);

						return array(
							'code'    => 200,
							'data'    => array(),
							'message' => __( 'Database optimization started', 'tailwatch' ),
						);
					} else {
						Log::error(
							'Cron scheduling failed.',
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_start_failed',
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
						'Tables scanned successfully. Optimization is already scheduled.',
						array(
							'feature' => 'database_optimizer',
							'action'  => 'database_optimizer_started',
						)
					);

					return array(
						'code'    => 200,
						'data'    => array(),
						'message' => __( 'Tables scanned successfully. Optimization is already scheduled.', 'tailwatch' ),
					);
				}
			} else {
				Log::error(
					'Failed to insert database optimization data.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_start_failed',
					)
				);
				return array(
					'code'    => 500,
					'data'    => array(),
					'message' => __( 'Error: Tables were not scanned. Database insertion failed.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_start_failed',
					'exception' => $e,
				)
			);
			$this->process_manager->mark_failed( $process_id, $e->getMessage() );
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Error: Failed to start database optimization.', 'tailwatch' ),
			);
		}
	}

	public function wptw_global_db_optimize_with_monitoring() {
		$cancel_pause = $this->wptw_optimization_cancel_pause();
		if ( isset( $cancel_pause['scan_state'] ) && ( $cancel_pause['scan_state'] === 'pause' || $cancel_pause['scan_state'] === 'cancel' ) ) {
			return;
		}

		$process_id               = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : ( isset( $this->current_process_id ) ? $this->current_process_id : null );
		$this->current_process_id = $process_id;

		$this->process_manager->heart_beat( $process_id );
		$this->process_manager->update_state( $process_id, 'in_progress' );

		try {
			$this->wptw_global_db_optimize();
			$this->process_manager->heart_beat( $process_id );
		} catch ( \Exception $e ) {
			$this->process_manager->mark_failed( $process_id, $e->getMessage() );

			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'db_optimize_cron_exception',
					'exception' => $e,
				)
			);
		}
	}

	public function wptw_global_db_optimize() {
		try {
			$json_data    = $this->wptw_get_optimization_data();
			$cancel_pause = $this->wptw_optimization_cancel_pause();

			if ( false === $cancel_pause['cron_running'] ) {
				$cancel_pause['cron_running'] = true;
			}

			$cancel_pause['function_completed'] = false;
			$cancel_pause['function_started']   = true;
			$this->update_db_optimization_cancel_pause( $cancel_pause );

			$backup_controller = new BackupController();
			if ( 'db_backup' === $json_data['process_run'] ) {
				$cancel_pause = $backup_controller->wptw_backup_cancel_pause_data();

				if ( false === $cancel_pause['cron_running'] ) {
					$cancel_pause['cron_running'] = true;
					$backup_controller->update_backup_cancel_pause( $cancel_pause );
				}
			}

			$number_interval = 500;
			$db_key          = '';
			foreach ( $json_data as $key => $db_data ) {
				if ( is_array( $db_data ) && array_key_exists( 'is_completed', $db_data ) ) {
					if ( false === $db_data['is_completed'] ) {
						$db_key = $key;
						break;
					}
				}
			}

			// Stop execution if the user cancel or pause the database optimization.
			$stop_execution = $this->wptw_stop_db_optimization_cron();
			if ( true === $stop_execution ) {
				return;
			}

			$optimize_database = new TablesOptimizeController();

			if ( 'spam_comments' === $db_key ) {
				$optimize_database->wptw_clean_all_spam_comments( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'trashed_posts' === $db_key ) {
				$optimize_database->wptw_clean_trash_posts( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'trashed_comments' === $db_key ) {
				$optimize_database->wptw_clean_trash_comments( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'trackbacks_pingbacks' === $db_key ) {
				$optimize_database->wptw_clean_trackback_pingbacks( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'orphaned_post_meta' === $db_key ) {
				$optimize_database->wptw_clean_orphaned_post( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'auto_drafts' === $db_key ) {
				$optimize_database->wptw_clean_auto_drafts( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'expired_transients' === $db_key ) {
				$optimize_database->wptw_clean_expired_transients( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'logs_activity' === $db_key ) {
				$optimize_database->wptw_clean_logs_activity( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'ajax_logs' === $db_key ) {
				$optimize_database->wptw_clean_ajax_logs( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'monitoring_logs' === $db_key ) {
				$optimize_database->wptw_clean_monitoring_logs( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( 'email_logs' === $db_key ) {
				$optimize_database->wptw_clean_email_logs( $json_data, $number_interval );
				$this->wptw_render_optimizer_cron();
				return;
			} elseif ( '' !== $db_key ) {
				$handled = apply_filters( 'wptw_db_optimize_step_handler', false, $db_key, $json_data, $number_interval, $this );
				if ( ! $handled ) {
					if ( isset( $json_data[ $db_key ] ) && is_array( $json_data[ $db_key ] ) ) {
						$json_data[ $db_key ]['is_completed'] = true;
						$this->update_db_optimization_data( $json_data );
					}
					$this->wptw_optimize_logs_records( "Skipping unsupported step: {$db_key}" );
				}
				$this->wptw_render_optimizer_cron();
				return;
			} else {
				$this->wptw_optimize_logs_records( 'Database optimization completed successfully', 'SUCCESS' );

				if ( 'in-progress' === $cancel_pause['scan_state'] ) {
					$cancel_pause['scan_state'] = 'completed';
					$this->update_db_optimization_cancel_pause( $cancel_pause );

					// Mark process as completed in process monitor.
					if ( ! empty( $this->current_process_id ) ) {
						$this->process_manager->mark_completed( $this->current_process_id );
						$this->current_process_id = null;
					}

					// Log completion (also triggers notification via NotificationActions).
					Log::info(
						'Your database was successfully optimized. Unused data like revisions, spam comments, and transients were cleaned to improve performance.',
						array(
							'feature'   => 'database_optimizer',
							'action'    => 'db_optimize_complete',
							'title'     => 'Database Optimized',
							'meta_data' => array(
								'feature' => 'Database Optimizer',
								'event'   => 'Completed',
							),
						)
					);
				}
				$live_logs = new LiveLogsController();
				$live_logs->wptw_live_logs_completed( true, $this->wptw_get_log_file_path() );

				if ( 'db_backup' === $json_data['process_run'] ) {
					$database_backup = wp_next_scheduled( 'wptw_scan_db_tables_cron' );
					if ( ! $database_backup ) {
						$cancel_pause = $backup_controller->wptw_backup_cancel_pause_data();

						if ( true === $cancel_pause['cron_running'] ) {
							$cancel_pause['cron_running'] = false;
							$backup_controller->update_backup_cancel_pause( $cancel_pause );
						}

						$backup_data                       = $backup_controller->wptw_get_scan_backup_data();
						$backup_data['optimize_completed'] = true;
						$backup_controller->update_backup_data( $backup_data );

						wp_schedule_single_event( time() + 5, 'wptw_scan_db_tables_cron' );
					}
				}
			}

			Log::info(
				'Feature status resolved',
				array(
					'feature' => 'database_optimizer',
					'action'  => 'update_errors_feature_status',
				)
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'database_optimizer',
					'action'  => 'database_optimizer_process_failed',
				)
			);
		}
	}

	public function wptw_render_optimizer_cron() {
		$cancel_pause                         = $this->wptw_optimization_cancel_pause();
		$cancel_pause['function_completed']   = true;
		$cancel_pause['function_started']     = false;
		$cancel_pause['completion_timestamp'] = time();
		$this->update_db_optimization_cancel_pause( $cancel_pause );

		wp_schedule_single_event( time() + 5, 'wptw_auto_db_optimize' );
	}

	public function wptw_optimize_logs_records( $message, $level = 'INFO' ) {
		$live_logs = new LiveLogsController();
		$live_logs->update_live_logs_records( $message, $this->wptw_get_log_file_path(), $level );

		$database_optimize = $this->wptw_get_optimization_data();
		if ( 'db_backup' === $database_optimize['process_run'] ) {
			$backup_controller = new BackupController();
			$backup_controller->update_logs_records( $message, $level );
		}
	}

	public function wptw_get_optimize_live_logs( $post_data ) {
		try {
			$is_enabled = $this->wptw_db_optimizer_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Database Optimizer feature is not enabled',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_live_logs_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$optimize_data = $this->wptw_optimization_cancel_pause();
			$feature_type  = 'database_optimizer';

			$params = array(
				'process_run' => isset( $optimize_data['process_run'] ) ? $optimize_data['process_run'] : '',
			);

			$livelogs = new LiveLogsController();
			return $livelogs->wptw_import_live_logs( $post_data, $this->wptw_get_log_file_path(), $optimize_data, $feature_type, $params );
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_live_logs_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to retrieve live logs.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_pause_db_optimize( $post_data ) {
		try {
			$is_enabled = $this->wptw_db_optimizer_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Database Optimizer feature is not enabled',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_cancel_pause_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'JSON decode failed.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_cancel_pause_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$existing_data = $this->wptw_optimization_cancel_pause();

			$scan_state = isset( $data['scan_state'] ) ? sanitize_text_field( $data['scan_state'] ) : '';
			if ( in_array( $scan_state, array( 'pause', 'cancel' ), true ) ) {

				$existing_data['scan_state'] = $scan_state;
				$this->update_db_optimization_cancel_pause( $existing_data );

				$timestamp = wp_next_scheduled( 'wptw_auto_db_optimize' );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'wptw_auto_db_optimize' );

					// Handle process state based on cancel/pause.
					$process_id = isset( $existing_data['process_id'] ) ? $existing_data['process_id'] : null;

					if ( 'cancel' === $scan_state ) {
						if ( $process_id ) {
							$this->process_manager->mark_failed( $process_id, 'Database optimization cancelled by user' );
						}

						$message = 'Database Optimize cancel successfully.';
						Log::info(
							$message,
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_cancel',
							)
						);
					} elseif ( 'pause' === $scan_state ) {
						if ( $process_id ) {
							$this->process_manager->update_state( $process_id, 'pause' );
						}

						$message = 'Database Optimize paused successfully. You can resume it later.';
						Log::info(
							$message,
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_pause',
							)
						);

						$existing_data['cron_running'] = false;
						$this->update_db_optimization_cancel_pause( $existing_data );
					}

					return array(
						'data'    => array(),
						'message' => $message,
						'code'    => 200,
					);
				} else {
					// No cron scheduled (e.g., cancel after pause already
					// unscheduled it). Cancel must still close out the
					// process manager row.
					if ( 'cancel' === $scan_state ) {
						$process_id = isset( $existing_data['process_id'] ) ? $existing_data['process_id'] : null;
						if ( $process_id ) {
							$this->process_manager->mark_failed( $process_id, 'Database optimization cancelled by user' );
						}

						$message = 'Database Optimize cancel successfully.';
						Log::info(
							$message,
							array(
								'feature' => 'database_optimizer',
								'action'  => 'database_optimizer_cancel',
							)
						);
						return array(
							'data'    => array(),
							'message' => $message,
							'code'    => 200,
						);
					}

					Log::error(
						'No cron event found.',
						array(
							'feature' => 'database_optimizer',
							'action'  => 'database_optimizer_cancel_pause_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'No scheduled database optimize found to pause or stop.', 'tailwatch' ),
						'code'    => 404,
					);
				}
			} else {
				Log::error(
					'Invalid scan_state provided.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_cancel_pause_failed',
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
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'database_optimizer',
					'action'  => 'database_optimizer_cancel_pause_failed',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to pause/cancel database optimization.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_delete_optimizer_garbage_data(): void {
		try {
			$wptw_key             = 'default_dboptimize_scan';
			$cancel_pause_option  = 'dboptimize_cancel_pause';
			$scan_dbtables_option = 'scan_dbtables';

			$db_model             = new DBModel();
			$cancel_pause_entries = $db_model->get_log_value( $wptw_key, $cancel_pause_option );

			foreach ( $cancel_pause_entries as $entry ) {
				$child_of = $entry['child_of'];
				$value    = json_decode( $entry['value'], true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					Log::error(
						'JSON decode error for child_of ' . $child_of . ': ' . json_last_error_msg(),
						array(
							'feature' => 'database_optimizer',
							'action'  => 'database_optimizer_remove_entries_failed',
						)
					);

					continue;
				}
				$date_created = strtotime( $entry['date_created'] );
				$scan_state   = isset( $value['scan_state'] ) ? $value['scan_state'] : '';

				// Check if scan_state is cancel, pause, or completed.
				if ( ! in_array( $scan_state, array( 'cancel', 'pause', 'completed' ), true ) ) {
					continue;
				}

				// For pause, check if older than 12 hours.
				if ( 'pause' === $scan_state ) {
					$start_time   = isset( $value['start_time'] ) ? $value['start_time'] : $date_created;
					$time_elapsed = time() - $start_time;
					if ( $time_elapsed < 12 * 60 * 60 ) {
						continue;
					}
				}

				$where = array(
					'child_of' => $child_of,
					'key'      => $wptw_key,
				);

				$db_model = new DBModel();
				$db_model->delete_rows( $where );

				$file_path = $this->get_live_logs . '_' . $child_of . '.json';

				if ( file_exists( $file_path ) ) {
					$this->wptw_delete_optimizer_logs_file( $file_path );
				}
			}

			Log::info(
				'All Database Optimizer entries have been removed.',
				array(
					'feature' => 'database_optimizer',
					'action'  => 'database_optimizer_remove_entries',
				)
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_remove_entries_failed',
					'exception' => $e,
				)
			);
		}
	}

	public function wptw_verify_db_optimize_status() {
		try {
			$is_enabled = $this->wptw_db_optimizer_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Database Optimizer feature is not enabled',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_verify_status_failed',
					)
				);

				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$existing_data = $this->wptw_optimization_cancel_pause();
			$optimize_data = $this->wptw_get_optimization_data();

			if ( ! empty( $existing_data ) ) {

				if ( isset( $existing_data['scan_state'] ) && ( 'pause' === $existing_data['scan_state'] ) ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'pause',
						'progress'     => $existing_data['progress'],
						'scan_type'    => $existing_data['scan_type'],
						'process_run'  => $optimize_data['process_run'],
						'message'      => __( 'Database Optimize was paused.', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( isset( $existing_data['scan_state'] ) && ( 'in-progress' === $existing_data['scan_state'] ) ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'in-progress',
						'progress'     => $existing_data['progress'],
						'scan_type'    => $existing_data['scan_type'],
						'process_run'  => $optimize_data['process_run'],
						'message'      => __( 'Database Optimize is in progress.', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( isset( $existing_data['scan_state'] ) && ( 'completed' === $existing_data['scan_state'] ) ) {
					return array(
						'is_completed' => true,
						'scan_state'   => 'completed',
						// 'progress'     => $existing_data['progress'],
						// 'process_run'  => $optimize_data['process_run'],
						'message'      => __( 'Database successfully optimized.', 'tailwatch' ),
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
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_verify_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to verify database optimization status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_resume_db_optimize() {
		try {
			$is_enabled = $this->wptw_db_optimizer_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Database Optimizer feature is not enabled',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_resume_failed',
					)
				);

				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$existing_data = $this->wptw_optimization_cancel_pause();

			if ( ! empty( $existing_data ) && ! empty( $existing_data['scan_state'] ) && 'pause' === $existing_data['scan_state'] ) {
				wp_schedule_single_event( time() + 10, 'wptw_auto_db_optimize' );

				$existing_data['scan_state'] = 'in-progress';
				$this->update_db_optimization_cancel_pause( $existing_data );

				// Update process state to in_progress when resumed.
				$process_id = isset( $existing_data['process_id'] ) ? $existing_data['process_id'] : null;
				if ( $process_id ) {
					$this->process_manager->update_state( $process_id, 'in_progress' );
					$this->process_manager->heart_beat( $process_id );
				}

				Log::info(
					'The database optimization process was resumed.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_resume',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Database Optimize Resume Successfully', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				Log::error(
					'Invalid state for resume.',
					array(
						'feature' => 'database_optimizer',
						'action'  => 'database_optimizer_resume_failed',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Already Database Optimize Schedule', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'database_optimizer',
					'action'  => 'database_optimizer_resume_failed',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to resume database optimization.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_delete_optimizer_logs_file( $file_path ) {
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}
}
