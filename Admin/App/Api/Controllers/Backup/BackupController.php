<?php
/**
 * Backup Controller
 *
 * Handles backup creation, cron scheduling, ZIP creation, and backup lifecycle.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Backup
 */

namespace Tailwatch\Admin\App\Api\Controllers\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Visit\RecommendedFeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Features\FeaturesController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Services\ZipUtility\ZipCreation;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\BackupModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Disk\DiskSpaceController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessStatusService;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

/**
 * Class BackupController
 *
 * Main backup feature controller. Schedules crons, runs backup process, manages ZIP creation.
 *
 */
class BackupController extends BaseController {

	/**
	 * Feature keys exempt from feature check for backup flows.
	 *
	 * @var array
	 */
	protected $wptw_feature_check_exemptions = array();

	/**
	 * Returns backup feature status (wrapper for get_backup_feature_data).
	 *
	 * @return array Backup feature data.
	 */
	protected function wptw_get_feature_status() {
		return $this->get_backup_feature_data();
	}

	/** @since 1.0.0 @var string */
	private $log_directory = WPTW_LOGS_DIRECTORY . '/backup-logs';
	/** @since 1.0.0 @var string */
	private $get_live_logs = WPTW_LOGS_DIRECTORY . '/backup-logs/create_backup';
	/** @since 1.0.0 @var string */
	private $backup_directory = WPTW_BACKUP_DIR . '/files/';
	/** @since 1.0.0 @var string */
	private $db_directory = WPTW_BACKUP_DIR . '/database/';
	/** @since 1.0.0 @var string */
	private $get_migration_progress = WPTW_BACKUP_DIR . '/migrator/import/migration_data.json';
	/** @since 1.0.0 @var \Tailwatch\Admin\App\Api\Services\ProcessManager */
	private $process_manager;
	/** @since 1.0.0 @var int|null */
	private $current_process_id;

	/**
	 * Constructor. Registers cron hooks and instantiates BackupDbController.
	 *
	 */
	public function __construct() {
		$this->process_manager = new ProcessManager();
		$this->register_process_monitoring();

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_backup_daily_scan', array( $this, 'wptw_run_backup_cron' ) );
		$hook_controller->add_action_hook( 'wptw_verify_backup_cron_hook', array( $this, 'wptw_verify_backup_cron_status' ) );
		$hook_controller->add_action_hook( 'wptw_delete_backup_files_entry', array( $this, 'wptw_delete_files_entry_with_cron' ) );
		$hook_controller->add_action_hook( 'wptw_recovery_process_failed', array( $this, 'wptw_on_recovery_failed' ) );

		new BackupDbController();
	}

	/**
	 * Registers backup process with ProcessManager for monitoring.
	 *
	 */
	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'        => 'backup',
				// Informational: backup uses a dedicated recover_backup_process() handler
				// in RecoveryService that picks the correct sub-step cron from backup
				// state, so this list is not consulted by the generic recovery dispatch.
				'cron_hooks'          => array( 'wptw_backup_daily_scan', 'wptw_scan_db_tables_cron', 'wptw_create_db_backup_cron' ),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_backup_scan',
				'data_option'         => 'scan_backp',
				'cancel_pause_key'    => 'default_backup_scan',
				'cancel_pause_option' => 'backup_cancel_pause',
				'stuck_threshold'     => 300, // 5 minutes.
				'max_retries'         => 10,
				// Settings ProcessGuard freezes while this process runs. Backup may run db_optimize
				// as its optimize-before-backup phase (wptw_database_optimize_start); that phase's
				// own lock releases when it finishes, so we keep the optimizer feature locked for
				// the whole backup to prevent settings drift mid-backup.
				'locks_features'      => array( 'default_backup_enable', 'default_database_optimizer' ),
				// Process types that, when running, prevent a user from starting
				// a new backup. db_optimize is intentionally NOT listed because
				// the optimizer-adoption logic in wptw_calculate_folder_sizes
				// allows backup to subsume a running optimizer instead of
				// duplicating it.
				'cannot_start_while'  => array(
					'restore',
					'malware_scan',
					'malware_restore',
					'files_integrity',
					'migration',
					'search_replace',
					// System-level settings operations rewrite feature config
					// site-wide; backup must wait for them to finish.
					'settings_import',
					'reset_all',
				),
			)
		);
	}

	/**
	 * Returns the path to the current backup log file.
	 *
	 * @return string Log file path.
	 */
	public function wptw_get_log_file_path() {
		$backup_data = $this->wptw_get_scan_backup_data();
		$file_key    = isset( $backup_data['zipId'] ) ? $backup_data['zipId'] : '';
		return $this->get_live_logs . '_' . $file_key . '.json';
	}

	/**
	 * Verifies backup cron status and reschedules daily scan if no backup cron is scheduled.
	 *
	 */
	public function wptw_verify_backup_cron_status() {
		$next_daily     = wp_next_scheduled( 'wptw_backup_daily_scan' );
		$next_recurring = wp_next_scheduled( 'wptw_backup_schedule_run' );
		if ( ! $next_daily && ! $next_recurring ) {
			wp_schedule_single_event( time() + 5, 'wptw_backup_daily_scan' );
		}
	}

	/**
	 * Executes backup cron if it failed or was not running (e.g. after recovery).
	 *
	 * @return array|null Response array on early return, null otherwise.
	 */
	public function wptw_execute_cron_if_failed() {
		try {
			$get_backup_data = $this->wptw_get_scan_backup_data();
			$cancel_pause    = $this->wptw_backup_cancel_pause_data();

			if ( empty( $get_backup_data ) || ! is_array( $get_backup_data ) ) {
				return null;
			}

			// Only an in-progress run whose cron actually died may be revived here. This recovery
			// keys off cron_running=false — but a user pause/cancel and a failed/completed run ALSO
			// leave cron_running=false, so without gating those out the poll-driven recovery would
			// reschedule the DB cron and defeat the pause (or resurrect a finished/failed run).
			if ( isset( $cancel_pause['scan_state'] )
				&& in_array( $cancel_pause['scan_state'], array( 'pause', 'cancel', 'failed', 'completed' ), true ) ) {
				return array(
					'code'    => 200,
					'data'    => array(),
					// translators: %s is the backup status.
					'message' => sprintf( __( 'Backup is %s; recovery not attempted.', 'tailwatch' ), $cancel_pause['scan_state'] ),
				);
			}

			if ( false === $cancel_pause['cron_running'] ) {
				$get_backup_type = $get_backup_data['backupType'];
				$cron_scheduled  = false;

				$premium_handled = apply_filters( 'wptw_handle_premium_backup_cron', false, $get_backup_type, $get_backup_data );
				if ( false !== $premium_handled ) {
					// Validate hook response format.
					if ( is_array( $premium_handled ) && isset( $premium_handled['code'] ) && isset( $premium_handled['message'] ) ) {
						return $premium_handled;
					} else {
						Log::error(
							'Premium backup hook returned invalid format',
							array(
								'feature' => 'backup',
								'action'  => 'premium_hook_invalid_response',
							)
						);
					}
				}

				$valid_backup_types = apply_filters( 'wptw_valid_backup_types', array( 'Complete Backup' ), $get_backup_type );

				if ( in_array( $get_backup_type, $valid_backup_types, true ) ) {
					if ( true === $get_backup_data['database_optimize'] && false === $get_backup_data['optimize_completed'] ) {
						Log::info(
							'As part of backup process: starting database optimization',
							array(
								'feature' => 'backup',
								'action'  => 'backup_if_cron_failed',
							)
						);
						$db_optimizer = new DatabaseOptimizerController();
						return $db_optimizer->wptw_db_optimization_cron_if_failed();
					}
				}

				switch ( $get_backup_type ) {
					case 'Complete Backup':
						if ( ! isset( $get_backup_data['tables'] ) ) {
							$database_backup = wp_next_scheduled( 'wptw_scan_db_tables_cron' );
							if ( ! $database_backup ) {
								$cron_scheduled = wp_schedule_single_event( time() + 5, 'wptw_scan_db_tables_cron' );
							} else {
								Log::info(
									'Complete backup DB scan cron already running',
									array(
										'feature' => 'backup',
										'action'  => 'backup_if_cron_failed',
									)
								);
								return array(
									'data'    => '',
									'message' => __( 'Complete backup DB scan cron already running', 'tailwatch' ),
									'code'    => 200,
								);
							}
						} else {
							$database_backup = wp_next_scheduled( 'wptw_create_db_backup_cron' );
							if ( ! $database_backup ) {
								$cron_scheduled = wp_schedule_single_event( time() + 5, 'wptw_create_db_backup_cron' );
							} else {
								Log::info(
									'Complete backup DB creation cron already running',
									array(
										'feature' => 'backup',
										'action'  => 'backup_if_cron_failed',
									)
								);
								return array(
									'data'    => '',
									'message' => __( 'Complete backup DB creation cron already running', 'tailwatch' ),
									'code'    => 200,
								);
							}
						}
						break;

					default:
						// Let premium plugin handle unknown backup types.
						$premium_result = apply_filters( 'wptw_backup_unknown_type', null, $get_backup_type, $get_backup_data );
						if ( null !== $premium_result ) {
							return $premium_result;
						}

						Log::error(
							'Unexpected backup type: ' . $get_backup_type,
							array(
								'feature' => 'backup',
								'action'  => 'backup_if_cron_failed_on_attempt',
							)
						);
						return array(
							'code'    => 400,
							'data'    => array(),
							'message' => __( 'Error: Unexpected backup type.', 'tailwatch' ),
						);
				}

				if ( $cron_scheduled ) {
					Log::info(
						'Backup cron has been rescheduled after failure',
						array(
							'feature' => 'backup',
							'action'  => 'backup_if_cron_failed',
						)
					);
					return array(
						'data'    => '',
						'message' => __( 'Again attempting to run the cron', 'tailwatch' ),
						'code'    => 200,
					);
				} else {
					Log::error(
						'Failed to schedule backup cron job',
						array(
							'feature' => 'backup',
							'action'  => 'backup_if_cron_failed_on_attempt',
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
					'Attempted to run backup cron while another instance was running',
					array(
						'feature' => 'backup',
						'action'  => 'backup_if_cron_failed',
					)
				);
				return array(
					'data'    => '',
					'message' => __( 'Cron already running', 'tailwatch' ),
					'code'    => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_if_cron_failed_on_attempt',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while starting backup.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Whether to trigger push notification on backup completion.
	 *
	 * @return bool True to trigger notification.
	 */
	public function backup_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_backup_enable';
		$field_name        = 'field_1';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Returns backup feature enable/disable and parent feature state.
	 *
	 * @return array Feature data with feature_enable, parent_enable.
	 */
	public function get_backup_feature_data() {
		$get_backup_option = new BackupMaintainController();
		$backup_data       = $get_backup_option->wptw_get_backup_settings();

		if ( empty( $backup_data ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = $backup_data['backupsEnabled'] ?? false;

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

	/**
	 * Returns root folder paths to exclude from backup (e.g. wp-admin, wp-includes).
	 *
	 * @return array List of root folder paths.
	 */
	public function get_root_folders_name() {
		$dir = new \DirectoryIterator( realpath( ABSPATH ) );

		$exclude_root_folders = array();
		$skip_root_paths      = array(
			realpath( ABSPATH . 'wp-admin' ),
			realpath( ABSPATH . WPINC ),
			realpath( WP_CONTENT_DIR ),
		);

		foreach ( $dir as $fileinfo ) {
			if ( $fileinfo->isDir() && ! $fileinfo->isDot() ) {
				$dirname = $fileinfo->getRealPath();
				if ( ! in_array( $dirname, $skip_root_paths, true ) ) {
					$exclude_root_folders[] = $dirname;
				}
			}
		}
		$result = array_merge( $exclude_root_folders, $skip_root_paths );

		return $result;
	}

	/**
	 * Gets the current scan/backup state from the database.
	 *
	 * @return array Scan backup data.
	 */
	public function wptw_get_scan_backup_data() {
		$feature_controller = new DBModel();
		$wptw_key           = 'default_backup_scan';
		$option             = 'scan_backp';
		$get_data           = $feature_controller->get_recent_data( $option, $wptw_key );
		return $get_data;
	}

	/**
	 * Keep an already-set stop-state (pause/cancel/failed) from being reverted by a stale write.
	 *
	 * The file + DB crons persist state repeatedly across a ~20s loop from a copy captured at tick
	 * start; a pause/cancel/failure set mid-tick by a separate request is otherwise lost when that
	 * stale copy is written back (proven: the run keeps going and verify keeps reporting
	 * in-progress). When the payload carries a NON-terminal scan_state but the row already holds an
	 * active stop-state, re-read and keep the stop-state. Terminal writes (pause/cancel/failed/
	 * completed) and deliberate resumes ($allow_state_change) pass through untouched.
	 *
	 * @param array  $options            Payload about to be written.
	 * @param bool   $allow_state_change Resume opt-in: write scan_state verbatim.
	 * @param string $option             'scan_backp' or 'backup_cancel_pause'.
	 * @return array Payload, with scan_state coerced back to the active stop-state when applicable.
	 */
	private function wptw_guard_stop_state( array $options, $allow_state_change, $option ) {
		if ( $allow_state_change || ! isset( $options['scan_state'] )
			|| in_array( $options['scan_state'], array( 'pause', 'cancel', 'failed', 'completed' ), true ) ) {
			return $options;
		}
		$current = ( 'scan_backp' === $option ) ? $this->wptw_get_scan_backup_data() : $this->wptw_backup_cancel_pause_data();
		if ( isset( $current['scan_state'] )
			&& in_array( $current['scan_state'], array( 'pause', 'cancel', 'failed' ), true ) ) {
			$options['scan_state'] = $current['scan_state'];
		}
		return $options;
	}

	/**
	 * Updates the stored backup scan data (e.g. progress, state).
	 *
	 * @param array $options            Data to store (will be JSON-encoded).
	 * @param bool  $allow_state_change Pass true only for a deliberate resume (see wptw_guard_stop_state).
	 */
	public function update_backup_data( array $options, $allow_state_change = false ) {
		$options  = $this->wptw_guard_stop_state( $options, $allow_state_change, 'scan_backp' );
		$db_model = new DBModel();
		$wptw_key = 'default_backup_scan';
		$option   = 'scan_backp';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	/**
	 * Updates the backup scan state (e.g. in-progress, cancel, pause, completed).
	 *
	 * @param string $scan_state         New state value.
	 * @param bool   $allow_state_change Pass true only for a deliberate resume (see wptw_guard_stop_state).
	 */
	public function update_backup_scan_state( $scan_state, $allow_state_change = false ) {
		$backup_data = $this->wptw_get_scan_backup_data();
		if ( empty( $backup_data ) || ! is_array( $backup_data ) ) {
			return;
		}
		if ( ! isset( $backup_data['scan_state'] ) || $backup_data['scan_state'] !== $scan_state ) {
			$backup_data['scan_state'] = $scan_state;
			$this->update_backup_data( $backup_data, $allow_state_change );
		}
	}

	/**
	 * Gets cancel/pause and progress data for the current backup.
	 *
	 * @return array Cancel/pause and progress data.
	 */
	public function wptw_backup_cancel_pause_data() {
		$feature_controller = new DBModel();
		$wptw_key           = 'default_backup_scan';
		$option             = 'backup_cancel_pause';
		return $feature_controller->get_recent_data( $option, $wptw_key );
	}

	/**
	 * Updates the backup cancel/pause and progress record.
	 *
	 * @param array $options            Data to store (will be JSON-encoded).
	 * @param bool  $allow_state_change Pass true only for a deliberate resume (see wptw_guard_stop_state).
	 */
	public function update_backup_cancel_pause( array $options, $allow_state_change = false ) {
		$options  = $this->wptw_guard_stop_state( $options, $allow_state_change, 'backup_cancel_pause' );
		$db_model = new DBModel();
		$wptw_key = 'default_backup_scan';
		$option   = 'backup_cancel_pause';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	/**
	 * Calculates folder sizes and starts the backup process (creates initial scan data and schedules cron).
	 *
	 * @param string|null $user_name          Current user login.
	 * @param string|null $user_role          Current user role(s).
	 * @param string|null $scan_type          Scan type (e.g. automatically, on-demand).
	 * @param string      $process_run        Process run context (e.g. backup, files_integrity).
	 * @param bool        $database_optimize   Whether to run DB optimization before backup.
	 * @param string|null $backup_type       Backup type (e.g. Complete Backup).
	 */
	public function wptw_calculate_folder_sizes( $user_name = null, $user_role = null, $scan_type = null, $process_run = 'backup', $database_optimize = false, $backup_type = null ) {
		try {
			$this->wptw_delete_files_entry_with_cron();

			$db_model = new DBModel();

			$get_backup_option = new BackupMaintainController();
			$backup_data       = $get_backup_option->wptw_get_backup_settings();

			if ( ! $backup_data ) {
				throw new \Exception( 'Failed to retrieve backup settings' );
			}

			if ( null !== $backup_type ) {
				$get_backup_type = $backup_type;
			} else {
				$get_backup_type = ! empty( $backup_data['backupType'] ) ? $backup_data['backupType'] : null;
			}

			// Allow premium plugin to validate premium backup types.
			$valid_backup_types = apply_filters( 'wptw_valid_backup_types', array( 'Complete Backup' ), $get_backup_type );
			if ( ! in_array( $get_backup_type, $valid_backup_types, true ) ) {
				Log::error(
					'Invalid backup type provided: ' . $get_backup_type,
					array(
						'feature' => 'backup',
						'action'  => 'backup_invalid_type',
					)
				);
				throw new \Exception( 'Invalid backup type: ' . $get_backup_type );
			}

			$sizes = array(
				'completed'          => false,
				'userData'           => array(
					'userName' => ! empty( $user_name ) ? $user_name : 'Auto Run',
					'userRole' => ! empty( $user_role ) ? $user_role : 'Auto Run',
					'scanType' => ! empty( $scan_type ) ? $scan_type : 'Automatically',
				),
				'zipId'              => time(),
				'folderDate'         => current_time( 'Y-m-d_H-i-s' ),
				'scan_state'         => 'in-progress',
				'site_url'           => site_url(),
				'started_time'       => time(),
				'backupType'         => $get_backup_type,
				'process_run'        => $process_run,
				'database_optimize'  => $database_optimize,
				'optimize_completed' => false,
			);

			$data_value = wp_json_encode( $sizes );
			if ( false === $data_value ) {
				throw new \Exception( 'Failed to encode backup data' );
			}

			$scan_type_is = ! empty( $scan_type ) ? $scan_type : 'automatically';
			$cancel_pause = array(
				'scan_state'   => 'in-progress',
				'cron_running' => false,
				'progress'     => 1,
				'scan_type'    => $scan_type_is,
				'folderDate'   => $sizes['folderDate'],
				'started_time' => $sizes['started_time'],
				'backup_type'  => $sizes['backupType'],
				'site_size'    => $this->wptw_estimation_site_size( $sizes['backupType'] ),
			);

			$process_id               = $this->process_manager->get_or_create_process(
				'backup',
				'wptw_backup_daily_scan',
				array(
					'zipId'      => $sizes['zipId'],
					'folderDate' => $sizes['folderDate'],
					'backupType' => $sizes['backupType'],
				)
			);
			$this->current_process_id = $process_id;
			$this->process_manager->heart_beat( $process_id );
			$this->process_manager->update_state( $process_id, 'in_progress' );
			$cancel_pause['process_id'] = $process_id;
			$backup_id = 'backup_' . $sizes['folderDate'];

			$db_data_is = array(
				array(
					'user_id'       => '1',
					'child_of'      => '0',
					'key'           => 'default_backup_scan',
					'option'        => 'scan_backp',
					'value'         => $data_value,
					'type'          => $backup_id,
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
				array(
					'user_id'       => '1',
					'child_of'      => '0',
					'key'           => 'default_backup_scan',
					'option'        => 'backup_cancel_pause',
					'value'         => wp_json_encode( $cancel_pause ),
					'type'          => $backup_id,
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
			);

			$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

			$result         = false;
			$insert_results = array();
			foreach ( $db_data_is as $db_data ) {
				try {
					$result = $db_model->insert_row( $db_data, $db_data_format );
					if ( ! $result ) {
						throw new \Exception( 'Failed to insert backup data row' );
					}

					$insert_results[ $db_data['option'] ] = array(
						'insert_id' => $result,
						'option'    => $db_data['option'],
						'key'       => $db_data['key'],
					);

				} catch ( \Throwable $e ) {
					// Re-throw so the outermost catch logs/notifies once, instead
					// of cascading 'backup_process_failed' through every level.
					throw $e;
				}
			}

			$db_optimizer = new DatabaseOptimizerController();

			if ( $result ) {
				$message   = 'Backup process started.';
				$live_logs = new LiveLogsController();
				$live_logs->insert_live_logs_records( $message, $this->log_directory, $this->wptw_get_log_file_path() );

				try {
					// Allow premium plugin to handle premium backup execution.
					$premium_executed = apply_filters( 'wptw_premium_backup_execution', false, $get_backup_type, $backup_data, $database_optimize );
					if ( false !== $premium_executed ) {
						// Validate hook response format.
						if ( is_array( $premium_executed ) && isset( $premium_executed['code'] ) && isset( $premium_executed['message'] ) ) {
							return $premium_executed;
						} else {
							Log::error(
								'Premium backup execution hook returned invalid format',
								array(
									'feature' => 'backup',
									'action'  => 'premium_execution_hook_invalid_response',
								)
							);
						}
					}

					switch ( $get_backup_type ) {
						case 'Complete Backup':
							if ( true === $database_optimize ) {
								// If a database optimization is already running (e.g. user
								// triggered it manually before requesting a backup), adopt
								// it for this backup instead of inserting a duplicate row
								// and abandoning the running run's progress. The adoption
								// works by tagging the existing optimizer row with
								// process_run='db_backup' so its completion handler at
								// DatabaseOptimizerController::wptw_global_db_optimize_with_monitoring
								// will flip this backup's optimize_completed flag and
								// schedule the DB-scan cron when it finishes.
								$status_service = new ProcessStatusService();

								if ( $status_service->is_running( 'db_optimize' ) ) {
									$db_optimizer->wptw_mark_optimizer_for_backup_completion();
									$this->update_logs_records( 'Adopting in-progress database optimization for this backup' );
								} else {
									$db_optimizer->wptw_database_optimize_start( 'on-demand', 'db_backup' );
								}
							} else {
								$database_backup = wp_next_scheduled( 'wptw_scan_db_tables_cron' );
								if ( ! $database_backup ) {
									wp_schedule_single_event( time() + 5, 'wptw_scan_db_tables_cron' );
								}
							}
							break;

						default:
							// Allow premium plugin to handle unknown backup types.
							$unknown_handled = apply_filters( 'wptw_backup_unknown_type', null, $get_backup_type, $backup_data );
							if ( null !== $unknown_handled ) {
								if ( is_array( $unknown_handled ) && isset( $unknown_handled['code'] ) && isset( $unknown_handled['message'] ) ) {
									return $unknown_handled;
								} else {
									Log::error(
										'Unknown backup type hook returned invalid format',
										array(
											'feature' => 'backup',
											'action'  => 'unknown_backup_type_hook_invalid_response',
										)
									);
								}
							}
							throw new \Exception( 'Unexpected backup type: ' . $get_backup_type );
					}
				} catch ( \Throwable $e ) {
					// Re-throw so the outermost catch logs/notifies once, instead
					// of cascading 'backup_process_failed' through every level.
					throw $e;
				}

				return array(
					'code'           => 200,
					'message'        => __( 'Backup process started successfully', 'tailwatch' ),
					'insert_results' => $insert_results,
					'zipId'          => $sizes['zipId'],
					'folderDate'     => $sizes['folderDate'],
				);
			} else {
				throw new \Exception( 'Failed to initialize backup process' );
			}
		} catch ( \Throwable $e ) {
			$backup_type_label = isset( $get_backup_type ) ? $get_backup_type : 'website';
			$start_fail_impact = 'Your previous backup is still safe in the Vault. You can try again, or restore from the last successful backup if you need to.';
			Log::error(
				"Your {$backup_type_label} couldn't start. This is usually a storage limit, server restriction, or connection issue. {$start_fail_impact}",
				array(
					'feature'   => 'backup',
					'action'    => 'backup_process_failed',
					'title'     => 'Backup failed to start',
					'exception' => $e,
					'detail'    => $e->getMessage(),
					'meta_data' => array(
						'feature'     => 'Backup Vault',
						'event'       => 'Failed',
						'impact'      => $start_fail_impact,
						'backup_type' => $backup_type_label,
						'phase'       => 'start',
						'reason'      => 'exception',
					),
				)
			);
			throw $e;
		}
	}

	/**
	 * Estimates total site size for backup type (files + DB minus wptw-backup folder).
	 *
	 * @param string $backup_type Backup type (e.g. Complete Backup).
	 * @return int|float Total size in bytes.
	 */
	public function wptw_estimation_site_size( $backup_type ) {
		$disk_space_controller = new DiskSpaceController();
		if ( 'Complete Backup' === $backup_type ) {
			$file_size   = $disk_space_controller->calculate_directory_sizes();
			$db_size     = $disk_space_controller->get_database_info();
			$wptw_backup = DiskSpaceController::calculate_folder_size( WPTW_BACKUP_DIR );
			$total_size  = $file_size['files_size'] - $wptw_backup;
			$total_size += $db_size['db_size'];
		} else {
			$total_size = 0;
		}
		return $total_size;
	}

	/**
	 * Updates live log records and optionally triggers malware/scan log hook.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (e.g. 'INFO', 'OK', 'RESULT'). Default 'INFO'.
	 */
	public function update_logs_records( $message, $level = 'INFO' ) {

		$live_logs = new LiveLogsController();
		$live_logs->update_live_logs_records( $message, $this->wptw_get_log_file_path(), $level );

		$existing_data = $this->wptw_get_scan_backup_data();
		if ( isset( $existing_data['process_run'] ) && in_array( $existing_data['process_run'], array( 'files_integrity', 'malware' ), true ) ) {
			// Allow pro plugin (MalwareScanner) to handle log records.
			do_action( 'wptw_backup_malware_scan_logs', $message, $level );
		}
	}

	/**
	 * Recursively gets directory size in bytes, optionally skipping paths.
	 *
	 * @param string $directory Directory path.
	 * @param array  $skip      Paths to skip.
	 * @return int Size in bytes.
	 */
	public function wptw_get_directory_size( $directory, $skip = array() ) {
		$size = 0;

		$directory_iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS ) );

		foreach ( $directory_iterator as $file ) {
			// Skip specified directories.
			foreach ( $skip as $skip_dir ) {
				if ( 0 === strpos( $file->getRealPath(), realpath( $skip_dir ) ) ) {
					continue 2;
				}
			}

			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Verifies that required scanner/backup folder structure exists and returns verified paths.
	 *
	 * @return array Map of folder key to path for verified folders.
	 */
	public function wptw_verify_required_folders() {
		// Use WP_CONTENT_DIR basename so Bedrock-style layouts (/app) snapshot under the real folder name.
		$candidate_basename = wp_basename( WP_CONTENT_DIR );
		$content_basename   = is_string( $candidate_basename )
			&& '' !== $candidate_basename
			&& preg_match( '#^[A-Za-z0-9_-]+$#', $candidate_basename )
				? $candidate_basename
				: 'wp-content';

		$folders = array(
			'others'      => realpath( WPTW_BACKUP_DIR . '/wptw-scanner/' ),
			'wp-admin'    => WPTW_BACKUP_DIR . '/wptw-scanner/wp-admin',
			'wp-includes' => WPTW_BACKUP_DIR . '/wptw-scanner/wp-includes',
			'wp-content'  => WPTW_BACKUP_DIR . '/wptw-scanner/' . $content_basename,
			'plugins'     => WPTW_BACKUP_DIR . '/wptw-scanner/' . $content_basename . '/plugins',
			'themes'      => WPTW_BACKUP_DIR . '/wptw-scanner/' . $content_basename . '/themes',
			'uploads'     => WPTW_BACKUP_DIR . '/wptw-scanner/' . $content_basename . '/uploads',
		);

		$verified_paths = array();

		foreach ( $folders as $key => $path ) {
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Enumerating plugin-owned snapshot tree; WP_Filesystem->dirlist() returns a heavier assoc structure than needed here.
				foreach ( scandir( $path ) as $item ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}

					if ( 'others' === $key ) {
						if ( $content_basename === $item || 'wp-includes' === $item || 'wp-admin' === $item ) {
							continue;
						}
					}

					if ( 'wp-content' === $key ) {
						if ( 'wptw-backup' === $item || 'plugins' === $item || 'themes' === $item || 'uploads' === $item ) {
							continue;
						}
					}

					if ( file_exists( $path ) ) {
						$verified_paths[ $key ] = $path;
					}
				}
			}
		}

		return $verified_paths;
	}

	/**
	 * Main backup cron callback (hook 'wptw_backup_daily_scan'). Thin wrapper that
	 * holds a single-worker lock around the real tick so the verify-hook and the
	 * recovery watchdog cannot run a SECOND worker on the same scan_backp row — two
	 * concurrent workers do interleaved read-modify-write and clobber progress.
	 */
	public function wptw_run_backup_cron() {
		if ( ! $this->wptw_acquire_worker_lock() ) {
			return; // Another worker is mid-tick — skip this injected run.
		}
		try {
			$this->wptw_run_backup_cron_worker();
		} finally {
			$this->wptw_release_worker_lock();
		}
	}

	/**
	 * Acquire the single-worker lock. TTL = the PHP max_execution_time, so it
	 * never expires mid-legitimate-tick (the tick dies by then and the shutdown hook
	 * releases it). The finally releases on every normal/exception return; the
	 * shutdown hook releases on a timeout/fatal that bypasses finally; the TTL is the
	 * last-resort backstop for a hard SIGKILL where even shutdown does not run.
	 * Sequential +3s ticks never block each other — each releases on return.
	 *
	 * @return bool True if this worker got the lock, false if one is already running.
	 */
	private function wptw_acquire_worker_lock() {
		if ( get_transient( 'wptw_backup_worker_lock' ) ) {
			return false;
		}
		set_transient( 'wptw_backup_worker_lock', time(), 600 );
		register_shutdown_function( array( $this, 'wptw_release_worker_lock' ) );
		return true;
	}

	/**
	 * Release the single-worker lock. Idempotent (deleting an absent transient is a
	 * no-op), so the finally + shutdown-hook double release is safe.
	 *
	 * @return void
	 */
	public function wptw_release_worker_lock() {
		delete_transient( 'wptw_backup_worker_lock' );
	}

	/**
	 * The real backup tick: maintains backups, loads state, and runs folder backup or
	 * finalization. Runs only while holding the single-worker lock (see the wrapper).
	 */
	private function wptw_run_backup_cron_worker() {

		$feature_controller = new DBModel();

		$wptw_key        = 'default_backup_scan';
		$option          = 'scan_backp';
		$backup_maintain = new BackupMaintainController();
		$backup_maintain->maintain_backups( $wptw_key, $option );

		$get_data      = $feature_controller->get_recent_data( $option, $wptw_key );
		$existing_data = $get_data;

		if ( empty( $existing_data ) || ! is_array( $existing_data ) ) {
			return;
		}

		$cancel_pause = $this->wptw_backup_cancel_pause_data();

		// This prevents recovery/watchdog from re-running a paused, cancelled, or
		// hard-failed process. 'failed' is terminal — bail without doing work or
		// rescheduling so a marked-failed backup can't be revived into a loop.
		if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] || 'failed' === $cancel_pause['scan_state'] ) {
			$timestamp = wp_next_scheduled( 'wptw_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wptw_backup_daily_scan' );
			}
			$this->update_backup_scan_state( $cancel_pause['scan_state'] );
			return;
		}

		$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : ( $this->current_process_id ?? null );
		$this->process_manager->heart_beat( $process_id );
		$this->process_manager->update_state( $process_id, 'in_progress' );

		if ( false === $cancel_pause['cron_running'] ) {
			$cancel_pause['cron_running'] = true;
			$this->update_backup_cancel_pause( $cancel_pause );
		}

		$this->wptw_backup_function_started();

		// Resolve dynamic core paths reliably
		$upload_dir      = wp_upload_dir();
		$uploads_basedir = $upload_dir['basedir'];
		$content_basename = wp_basename( WP_CONTENT_DIR );

		if ( 'files_integrity' === $existing_data['process_run'] ) {
			$folders = $this->wptw_verify_required_folders();

			$backup_dir_base = trailingslashit( WPTW_BACKUP_DIR ) . 'wptw-scanner/';

			$exclude_wp_content_folders = array(
				$backup_dir_base . $content_basename . '/plugins',
				$backup_dir_base . $content_basename . '/themes',
				$backup_dir_base . $content_basename . '/uploads',
				$backup_dir_base . $content_basename . '/tailwatch',
			);

			$exclude_root_folders = array(
				$backup_dir_base . 'wp-admin',
				$backup_dir_base . 'wp-includes',
				$backup_dir_base . $content_basename,
			);
		} else {
			$folders = array(
				'wp-admin'    => trailingslashit( ABSPATH ) . 'wp-admin',
				'wp-includes' => trailingslashit( ABSPATH ) . WPINC,
				'wp-content'  => WP_CONTENT_DIR,
				'plugins'     => WP_PLUGIN_DIR,
				'themes'      => get_theme_root(),
				'uploads'     => $uploads_basedir,
				'others'      => ABSPATH,
			);

			$exclude_wp_content_folders = array(
				WP_PLUGIN_DIR,
				get_theme_root(),
				$uploads_basedir,
				defined( 'WPTW_CONTENT_DIR_BASE' ) ? WPTW_CONTENT_DIR_BASE : '',
			);

			$exclude_root_folders = $this->get_root_folders_name();
		}

		try {
			foreach ( $folders as $key => $folder_path ) {
				if ( isset( $existing_data[ $key ] ) && isset( $existing_data[ $key ]['completed'] ) && $existing_data[ $key ]['completed'] ) {
					continue; // Skip if already completed
				}

				$this->process_folder_backup( $key, $folder_path, $existing_data, $feature_controller, $wptw_key, $option, $exclude_wp_content_folders, $exclude_root_folders );

				// Check again in case scan_state changed during execution: user cancel/pause,
				// OR a hard failure (manifest build / part packing hit its cap) marked the run
				// 'failed'. In all three we stop WITHOUT rescheduling the next tick.
				$cancel_pause = $this->wptw_backup_cancel_pause_data();
				if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] || 'failed' === $cancel_pause['scan_state'] ) {
					$timestamp = wp_next_scheduled( 'wptw_backup_daily_scan' );
					if ( $timestamp ) {
						wp_unschedule_event( $timestamp, 'wptw_backup_daily_scan' );
					}

					$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
					if ( $timestamp_backup_status ) {
						wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
					}

					if ( true === $cancel_pause['cron_running'] ) {
						$cancel_pause['cron_running'] = false;
						$this->update_backup_cancel_pause( $cancel_pause );
					}
					$this->update_backup_scan_state( $cancel_pause['scan_state'] );
				} else {
					wp_schedule_single_event( time() + 3, 'wptw_backup_daily_scan' );
				}
				$this->wptw_backup_function_complete();
				$this->process_manager->heart_beat( $process_id );
				return;
			}

			// Finalize the backup process after all folders are processed.
			$this->check_and_finalize_backup( $existing_data, $feature_controller, $wptw_key, $option, $process_id );
			$this->wptw_backup_function_complete();
		} catch ( \Throwable $e ) {
			$this->process_manager->mark_failed( $process_id, $e->getMessage() );
			$backup_type_label = isset( $existing_data['backupType'] ) ? $existing_data['backupType'] : 'website';
			$loop_fail_impact  = 'Your previous backup is still safe in the Vault. You can try again, or restore from the older snapshot if you need to.';
			Log::error(
				"Your {$backup_type_label} stopped partway through. This is usually a storage, memory, or timeout issue mid-process. {$loop_fail_impact}",
				array(
					'feature'   => 'backup',
					'action'    => 'backup_process_failed',
					'title'     => 'Backup interrupted',
					'exception' => $e,
					'detail'    => $e->getMessage(),
					'meta_data' => array(
						'feature'     => 'Backup Vault',
						'event'       => 'Failed',
						'impact'      => $loop_fail_impact,
						'backup_type' => $backup_type_label,
						'phase'       => 'scan_loop',
						'reason'      => 'exception',
					),
				)
			);
		}
	}

	/**
	 * Marks backup function as started in cancel/pause data.
	 *
	 */
	public function wptw_backup_function_started() {
		$cancel_pause                       = $this->wptw_backup_cancel_pause_data();
		$cancel_pause['function_completed'] = false;
		$cancel_pause['function_started']   = true;
		$this->update_backup_cancel_pause( $cancel_pause );
	}

	/**
	 * Marks backup function as complete in cancel/pause data.
	 *
	 */
	public function wptw_backup_function_complete() {
		$cancel_pause                         = $this->wptw_backup_cancel_pause_data();
		$cancel_pause['function_completed']   = true;
		$cancel_pause['function_started']     = false;
		$cancel_pause['completion_timestamp'] = time();
		$this->update_backup_cancel_pause( $cancel_pause );
	}

	/**
	 * Tier-1 engine: walk a folder ONCE and stream an ordered manifest of the files to
	 * back up, replacing the O(N^2) approach (old planner walk + large-file walk + a
	 * full re-walk per part). Two newline-delimited sidecars are written atomically:
	 *  - $manifest_file: "relPath\tsize" for each file <= the size limit, in iteration
	 *    order. Read back by byte offset across ticks (resume cursor) and packed into
	 *    parts greedily — so num_parts here EQUALS the writer's part count by
	 *    construction (no planner/writer mismatch).
	 *  - $large_file_manifest: "relPath\tsize" for each file > the limit (zipped
	 *    one-per-tick on the existing single-file path).
	 * relPath is forward-slashed and relative to $folder_path; the writer prepends the
	 * key's path_prefix to form the in-zip path the restore expects.
	 *
	 * @param string $folder_path         Folder root to walk.
	 * @param array  $exclude             Absolute paths to exclude (realpath prefix match).
	 * @param string $manifest_file       Destination for the <=limit file list.
	 * @param string $large_file_manifest Destination for the >limit file list.
	 * @param int    $zip_size_limit      Per-part byte limit (default 50MB).
	 * @param int    $scan_max_size       If >0, skip any file larger than this entirely (the
	 *                                    scan-backup cap for malware/integrity). 0 = no cap.
	 *                                    Sourced from a filter at the call site → future option.
	 * @return array|false { files, large, total_size, num_parts, skipped_oversize } or false on failure.
	 */
	private function wptw_build_backup_manifest( $folder_path, array $exclude, $manifest_file, $large_file_manifest, $zip_size_limit = 52428800, $scan_max_size = 0 ) {
		$exclude = array_filter( array_map( 'realpath', $exclude ) );

		$tmp  = $manifest_file . '.tmp';
		$ltmp = $large_file_manifest . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed write of a large cursor file; WP_Filesystem has no append/stream API; @ suppresses transient host warnings, false-check follows.
		$handle = @fopen( $tmp, 'wb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed write; see above.
		$lhandle = @fopen( $ltmp, 'wb' );
		if ( false === $handle || false === $lhandle ) {
			if ( false !== $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streamed cursor-file handle opened with fopen above; WP_Filesystem has no stream API.
				fclose( $handle );
			}
			if ( false !== $lhandle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streamed cursor-file handle opened with fopen above; WP_Filesystem has no stream API.
				fclose( $lhandle );
			}
			return false;
		}

		$stats     = array( 'files' => 0, 'large' => 0, 'total_size' => 0, 'num_parts' => 0, 'skipped_oversize' => 0 );
		$part_size = 0;
		$root_len  = strlen( rtrim( str_replace( '\\', '/', $folder_path ), '/' ) ) + 1;

		try {
			$dir_iterator = new \RecursiveDirectoryIterator( $folder_path, \RecursiveDirectoryIterator::SKIP_DOTS );

			// Prune excluded directories BEFORE descending so we never enumerate the contents
			// of plugins/themes/uploads (each backed up under its own key) while walking their
			// parent. Returning false for an excluded dir skips its whole subtree — far cheaper
			// than visiting then skipping every file inside it. The per-file boundary check in
			// the loop stays as a safety net (e.g. an exclude that resolves to a file/symlink).
			if ( ! empty( $exclude ) ) {
				$dir_iterator = new \RecursiveCallbackFilterIterator(
					$dir_iterator,
					static function ( $current ) use ( $exclude ) {
						if ( ! $current->isDir() ) {
							return true; // files are handled (and boundary-checked) in the loop.
						}
						$dir_real = $current->getRealPath();
						if ( false === $dir_real ) {
							return true;
						}
						return ! in_array( $dir_real, $exclude, true ); // false → prune this subtree.
					}
				);
			}

			$iterator = new \RecursiveIteratorIterator(
				$dir_iterator,
				\RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue; // intermediate dirs are recreated on extract from each file's path.
				}
				$abs = $file->getRealPath();
				if ( false === $abs ) {
					continue;
				}
				foreach ( $exclude as $skip_folder ) {
					// Match on a directory boundary, NOT a bare prefix: without the trailing
					// separator a sibling like "uploads-old" matches the excluded "uploads"
					// and gets silently dropped from the backup (data loss). $abs is always a
					// file under $folder_path, so it can never equal $skip_folder itself.
					if ( 0 === strpos( $abs, $skip_folder . DIRECTORY_SEPARATOR ) ) {
						continue 2;
					}
				}

				$size = (int) $file->getSize();

				// Scan-backup cap: drop oversize files entirely (not written to EITHER
				// manifest) so they never enter a backup part / upload / scan / restore —
				// every stage stays in lockstep. 0 = no cap (regular full backup). The cap
				// comes from a filter at the call site, so a future user-selectable option
				// can change or disable it without touching this method.
				if ( $scan_max_size > 0 && $size > $scan_max_size ) {
					++$stats['skipped_oversize'];
					continue;
				}

				$rel  = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), $root_len ), '/' );
				$line = $rel . "\t" . $size . "\n";

				if ( $size > $zip_size_limit ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed write; see fopen above.
					fwrite( $lhandle, $line );
					++$stats['large'];
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed write; see fopen above.
				fwrite( $handle, $line );
				++$stats['files'];
				$stats['total_size'] += $size;

				// Greedy bin count — MUST match the writer's packing so num_parts is exact.
				if ( $part_size > 0 && ( $part_size + $size ) > $zip_size_limit ) {
					++$stats['num_parts'];
					$part_size = 0;
				}
				$part_size += $size;
			}

			if ( $part_size > 0 ) {
				++$stats['num_parts'];
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
			fclose( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
			fclose( $lhandle );
			wp_delete_file( $tmp );
			wp_delete_file( $ltmp );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $lhandle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish of the manifest; @ swallows host warnings, false return is checked.
		if ( ! @rename( $tmp, $manifest_file ) ) {
			wp_delete_file( $tmp );
			wp_delete_file( $ltmp );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish of the large-file manifest; @ swallows host warnings, false return is checked.
		if ( ! @rename( $ltmp, $large_file_manifest ) ) {
			wp_delete_file( $ltmp );
			return false;
		}

		return $stats;
	}

	/**
	 * Tier-1 engine: pack ONE zip part from the manifest, resuming at a byte offset
	 * instead of re-walking the tree (the O(N^2) cost). Reads files greedily from
	 * $offset until the next would overflow the size limit (matching the manifest's
	 * bin boundaries), adds each as $path_prefix + '/' + relPath (the in-zip layout
	 * restore expects), closes, and returns the cursor for the next part. A file that
	 * vanished since the manifest was built is skipped but its size is still counted,
	 * so part boundaries stay aligned with the manifest's bin count.
	 *
	 * @param string $manifest_file  Manifest sidecar (relPath\tsize lines).
	 * @param int    $offset         Byte offset to resume from (0 = first part).
	 * @param string $folder_path    Folder root the rel paths are under.
	 * @param string $path_prefix    In-zip prefix for this key (e.g. 'wp-content/plugins', '' for root).
	 * @param string $destination    Zip file to (over)write for this part.
	 * @param int    $zip_size_limit Per-part byte limit (default 50MB).
	 * @return array|false { next_offset, added, part_size, eof, closed } or false on open failure.
	 */
	private function wptw_pack_part_from_manifest( $manifest_file, $offset, $folder_path, $path_prefix, $destination, $zip_size_limit = 52428800 ) {
		if ( ! file_exists( $manifest_file ) ) {
			return false;
		}
		// Prefer ZipArchive (the zip extension); fall back to WordPress-bundled PclZip
		// (pure PHP, needs only zlib) when the zip extension is disabled, so backups still
		// complete. Both write a standard .zip that our restore already reads.
		$use_ziparchive = class_exists( 'ZipArchive' );
		if ( ! $use_ziparchive ) {
			if ( ! class_exists( 'PclZip' ) && file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			}
			if ( ! class_exists( 'PclZip' ) ) {
				return false;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- partial sequential read of a large cursor file; WP_Filesystem cannot seek; @ swallows host warnings, false-check follows.
		$handle = @fopen( $manifest_file, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$zip = null;
		if ( $use_ziparchive ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
				fclose( $handle );
				return false;
			}
		}

		$folder_root = rtrim( str_replace( '\\', '/', $folder_path ), '/' );
		$prefix      = '' === $path_prefix ? '' : rtrim( $path_prefix, '/' ) . '/';
		$part_size   = 0;
		$added       = 0;
		$eof         = false;
		$pcl_groups  = array(); // PclZip only: [source_dir][in_zip_dir][] = abs path.

		while ( true ) {
			$line_pos = ftell( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- streaming read; see fopen above.
			$line = fgets( $handle );
			if ( false === $line ) {
				$eof = true;
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$tab = strrpos( $line, "\t" );
			if ( false === $tab ) {
				continue;
			}
			$rel  = substr( $line, 0, $tab );
			$size = (int) substr( $line, $tab + 1 );

			// A file that would overflow a non-empty part belongs to the NEXT part —
			// rewind so the next call starts exactly at it (same bins as the manifest).
			if ( $part_size > 0 && ( $part_size + $size ) > $zip_size_limit ) {
				fseek( $handle, $line_pos );
				break;
			}

			$abs = $folder_root . '/' . $rel;
			if ( is_file( $abs ) && is_readable( $abs ) ) {
				if ( $use_ziparchive ) {
					$zip->addFile( $abs, $prefix . $rel );
				} else {
					// Group by (source dir, in-zip dir) so PclZip's REMOVE_PATH/ADD_PATH
					// reproduce the exact in-zip path as ZipArchive's addFile().
					$in_zip = $prefix . $rel;
					$pcl_groups[ dirname( $abs ) ][ dirname( $in_zip ) ][] = $abs;
				}
				++$added;
			}
			$part_size += $size; // count even if missing, to keep boundaries aligned.
		}

		$next_offset = ftell( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream close.
		fclose( $handle );
		$closed = $use_ziparchive ? $zip->close() : $this->wptw_pclzip_write_part( $destination, $pcl_groups );

		return array(
			'next_offset' => (int) $next_offset,
			'added'       => $added,
			'part_size'   => $part_size,
			'eof'         => $eof,
			'closed'      => (bool) $closed,
		);
	}

	/**
	 * Write a backup part with PclZip — fallback when the zip extension is off.
	 * Adds files per (source dir, in-zip dir) group via REMOVE_PATH/ADD_PATH so the in-zip
	 * layout is identical to the ZipArchive path; restore stays byte-compatible. An empty
	 * part (all files missing) writes no file — the caller's zip-validity check handles it.
	 *
	 * @param string $destination Part zip path to (over)write.
	 * @param array  $groups      [source_dir][in_zip_dir][] = abs path.
	 * @return bool True on success.
	 */
	private function wptw_pclzip_write_part( $destination, $groups ) {
		if ( ! class_exists( 'PclZip' ) && file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
		if ( ! class_exists( 'PclZip' ) ) {
			return false;
		}
		// PclZip's add() appends; start from a clean file so a resumed/retried part is not doubled.
		if ( file_exists( $destination ) ) {
			wp_delete_file( $destination );
		}
		$pclzip = new \PclZip( $destination );
		foreach ( $groups as $source_dir => $in_zip_dirs ) {
			foreach ( $in_zip_dirs as $in_zip_dir => $files ) {
				$res = ( '' === $in_zip_dir || '.' === $in_zip_dir )
					? $pclzip->add( $files, PCLZIP_OPT_REMOVE_PATH, $source_dir )
					: $pclzip->add( $files, PCLZIP_OPT_REMOVE_PATH, $source_dir, PCLZIP_OPT_ADD_PATH, $in_zip_dir );
				if ( 0 === $res ) {
					return false;
				}
			}
		}
		return true;
	}


	/**
	 * In-zip path prefix for a folder key — the directory each key's files extract to.
	 * MUST match the layout the restore expects (same mapping the single-file zipper
	 * uses). 'others' = site root.
	 *
	 * @param string $key Folder key.
	 * @return string Prefix (no trailing slash), '' for root.
	 */
	private function wptw_path_prefix_for_key( $key ) {
		$content_base = basename( WP_CONTENT_DIR );
		switch ( $key ) {
			case 'wp-admin':
				return 'wp-admin';
			case 'wp-includes':
				return 'wp-includes';
			case 'wp-content':
				return $content_base;
			case 'plugins':
				return $content_base . '/plugins';
			case 'themes':
				return $content_base . '/themes';
			case 'uploads':
				return $content_base . '/uploads';
			case 'others':
				return '';
			default:
				return $key;
		}
	}

	/**
	 * Load the large-file manifest into the {realpath,pathname,filename,size} entry
	 * shape the existing large-file loop + create_single_file_zip() consume, so the v2
	 * engine reuses that path unchanged. Usually a small list (few >50MB files).
	 *
	 * @param string $large_manifest Large-file manifest sidecar (relPath\tsize lines).
	 * @param string $folder_path    Folder the rel paths are under.
	 * @return array List of large-file entries.
	 */
	private function wptw_load_large_files_from_manifest( $large_manifest, $folder_path ) {
		$out = array();
		if ( ! file_exists( $large_manifest ) ) {
			return $out;
		}
		$root  = rtrim( str_replace( '\\', '/', $folder_path ), '/' );
		$lines = file( $large_manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		foreach ( (array) $lines as $line ) {
			$tab = strrpos( $line, "\t" );
			if ( false === $tab ) {
				continue;
			}
			$rel = substr( $line, 0, $tab );
			$abs = $root . '/' . $rel;
			$out[] = array(
				'realpath' => $abs,
				'pathname' => $abs,
				'filename' => basename( $rel ),
				'size'     => (int) substr( $line, $tab + 1 ),
			);
		}
		return $out;
	}



	/**
	 * Whether a usable zip engine is available for the file backup — ZipArchive (the zip
	 * extension) or the WordPress-bundled PclZip fallback (pure PHP, needs only zlib).
	 *
	 * @return bool
	 */
	private function wptw_zip_engine_available() {
		if ( class_exists( 'ZipArchive' ) || class_exists( 'PclZip' ) ) {
			return true;
		}
		return file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
	}

	public function process_folder_backup( $key, $folder_path, &$existing_data, $feature_controller, $wptw_key, $option, $exclude_wp_content_folders, $exclude_root_folders ) {
		if ( ! isset( $existing_data['zipId'] ) || ! isset( $existing_data['folderDate'] ) ) {
			return;
		}

		$zip_size_limit = 52428800; // 50MB
		if ( ! isset( $existing_data[ $key ] ) || ( ! isset( $existing_data[ $key ]['started_message'] ) || true !== $existing_data[ $key ]['started_message'] ) ) {
			$this->update_logs_records( "Backing up {$key}" );
			$existing_data[ $key ]['started_message'] = true;
		}

		// File backup needs a zip engine: ZipArchive (the zip extension) or the
		// WordPress-bundled PclZip fallback. If neither is usable, fail fast with a clear
		// message instead of the generic "failed to write a part".
		if ( ! $this->wptw_zip_engine_available() ) {
			$this->update_logs_records( 'Backup needs the PHP "zip" extension (ZipArchive) or PclZip; neither is available on this server. Enable the PHP zip extension to continue.', 'ERROR' );
			$this->wptw_mark_backup_failed( 'no_zip_engine' );
			return;
		}

		$cron_start_time                     = time();
		$existing_data[ $key ]['cron_start'] = $cron_start_time;
		$zip_unique_id                       = $existing_data['zipId'];
		$folder_date                         = $existing_data['folderDate'];

		$backup_directory = WPTW_BACKUP_DIR . '/';
		if ( ! is_dir( $backup_directory ) ) {
			// Seal the backup root with deny files so archives are not reachable over the web.
			SecureDirectoryService::ensure_private_root( $backup_directory );
		}
		$files_directory = $backup_directory . 'files/';
		if ( ! file_exists( $files_directory ) ) {
			wp_mkdir_p( $files_directory );
		}
		$daily_backup_directory = $files_directory . $folder_date . '/';
		if ( ! file_exists( $daily_backup_directory ) ) {
			wp_mkdir_p( $daily_backup_directory );
		}

		$normal_files_count = 0;

		$batch = isset( $existing_data[ $key ]['batch'] ) ? $existing_data[ $key ]['batch'] : array();

		// ONE manifest build replaces the old large-file walk + part-count walk AND the
		// per-part re-walk. First tick builds it and returns (one tick to plan).
		if ( empty( $existing_data[ $key ]['manifest_built'] ) ) {
			if ( 'wp-content' === $key ) {
				$folder_exclude = $exclude_wp_content_folders;
			} elseif ( 'others' === $key ) {
				$folder_exclude = $exclude_root_folders;
			} elseif ( 'uploads' === $key ) {
				// Skip the plugin's own logs and generated data (including the
				// GeoIP database) so backups do not carry regenerable files.
				$folder_exclude = array( WPTW_LOGS_DIRECTORY );
			} else {
				$folder_exclude = array();
			}
			$manifest_file  = $daily_backup_directory . "{$key}_{$zip_unique_id}.manifest";
			$large_manifest = $daily_backup_directory . "{$key}_{$zip_unique_id}.large";

			// Malware/integrity scan backups cap file size — skip oversize files (media/
			// archives Imunify won't deep-scan) to cut upload+scan cost. Restore is unaffected:
			// a skipped file never enters the backup → never uploaded/scanned/restored. The
			// regular full backup keeps everything (cap stays 0). The cap value is FILTERABLE,
			// so a future user-selectable option just hooks 'wptw_malware_scan_max_file_size'
			// (return 0 to disable). Default 52428800 reuses the engine's 50MB large-file line.
			$scan_process_run = isset( $existing_data['process_run'] ) ? $existing_data['process_run'] : '';
			$scan_max_size    = 0;
			if ( in_array( $scan_process_run, array( 'malware', 'files_integrity' ), true ) ) {
				$scan_max_size = (int) apply_filters( 'wptw_malware_scan_max_file_size', 52428800, $scan_process_run, $key );
			}

			$stats = $this->wptw_build_backup_manifest( $folder_path, $folder_exclude, $manifest_file, $large_manifest, $zip_size_limit, $scan_max_size );
			if ( false === $stats ) {
				// Same hard-failure cap as the packing loop: a persistent manifest build
				// failure (disk full, can't write the sidecars) would otherwise be re-kicked
				// forever with the UI stuck 'in-progress'. Give up cleanly after 3 consecutive
				// attempts via the terminal 'failed' path. (Healthy folders plan in one tick.)
				$plan_fail = isset( $existing_data[ $key ]['plan_fail_attempts'] ) ? (int) $existing_data[ $key ]['plan_fail_attempts'] : 0;
				++$plan_fail;
				$existing_data[ $key ]['plan_fail_attempts'] = $plan_fail;
				$this->update_backup_data( $existing_data );
				if ( $plan_fail >= 3 ) {
					$this->update_logs_records( "Failed to plan {$key} (manifest build) after {$plan_fail} attempts — aborting backup", 'ERROR' );
					$this->wptw_mark_backup_failed( "manifest_build_failed:{$key}" );
				}
				return; // build failed — retried next tick until the cap.
			}
			if ( ! empty( $stats['skipped_oversize'] ) ) {
				$this->update_logs_records( "Skipped {$stats['skipped_oversize']} file(s) over " . (int) round( $scan_max_size / 1048576 ) . "MB from the {$scan_process_run} scan backup" );
			}
			$existing_data[ $key ]['manifest_built']  = true;
			$existing_data[ $key ]['manifest_file']   = $manifest_file;
			$existing_data[ $key ]['num_zips']        = $stats['num_parts'];
			$existing_data[ $key ]['manifest_offset'] = 0;
			$existing_data[ $key ]['large_files']     = $this->wptw_load_large_files_from_manifest( $large_manifest, $folder_path );
			$this->update_backup_data( $existing_data );
			return;
		}
		$num_zips    = (int) $existing_data[ $key ]['num_zips'];
		$large_files = isset( $existing_data[ $key ]['large_files'] ) ? $existing_data[ $key ]['large_files'] : array();
		if ( $num_zips >= 1 ) {
			if ( ! isset( $existing_data[ $key ]['archive_message'] ) ) {
				$this->update_logs_records( "Creating {$num_zips} archive parts for {$key}" );
				$existing_data[ $key ]['archive_message'] = true;
			}
		}
		$current_zip_index = isset( $existing_data[ $key ]['current_zip'] ) ? $existing_data[ $key ]['current_zip'] : 1;

			// Handle large files.
		$current_large_file_index = isset( $existing_data[ $key ]['current_large_file'] ) ? $existing_data[ $key ]['current_large_file'] : 0;

		if ( $current_large_file_index < count( $large_files ) ) {
			$file         = $large_files[ $current_large_file_index ];
			$file_size    = $file['size'];
			$file_size_mb = round( $file_size / ( 1024 * 1024 ), 2 );
			$this->update_logs_records( "Archiving large file {$file['filename']} ({$file_size_mb}MB)" );
			$estimated_time       = ceil( $file_size / ( 1024 * 1024 ) );
			$buffer_time          = 180;
			$total_scheduled_time = $estimated_time + $buffer_time;

			if ( ! wp_next_scheduled( 'wptw_verify_backup_cron_hook' ) ) {
				wp_schedule_single_event( time() + $total_scheduled_time, 'wptw_verify_backup_cron_hook' );
			}

			$timestamp_run = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );

			$large_zips          = $current_large_file_index + 1;
			$large_zip_name      = "{$key}_large_part_{$large_zips}_{$zip_unique_id}.zip";
			$large_zip_file_path = $daily_backup_directory . $large_zip_name;

			if ( ! isset( $existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'] ) ) {
				$existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'] = 0;
			}

			// Check if the number of attempts has exceeded 3.
			if ( $existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'] >= 3 ) {
				$large_zip_name = basename( $large_zip_name, '.zip' );
				$this->remove_related_incomplete_files( $daily_backup_directory, $large_zip_name );
				$this->update_logs_records( "Failed to archive {$file['filename']} after 3 attempts", 'ERROR' );
				$batch[ "large_zip_{$large_zips}" ]          = array(
					'status'    => 'failed',
					'timestamp' => current_time( 'mysql' ),
					'attempts'  => $existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'],
				);
				$existing_data[ $key ]['batch']              = $batch;
				$existing_data[ $key ]['current_large_file'] = $current_large_file_index + 1;
				$this->update_backup_data( $existing_data );
				return;
			}

			++$existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'];
			$this->update_backup_data( $existing_data );

			if ( ! $this->wptw_is_valid_zip( $large_zip_file_path ) ) {
				// Delete a truncated/corrupt large-file zip so this attempt rebuilds it.
				if ( file_exists( $large_zip_file_path ) ) {
					wp_delete_file( $large_zip_file_path );
				}

				$this->create_single_file_zip( $file, $key, $large_zips, $folder_path, $daily_backup_directory, $zip_unique_id );
			}

			if ( $this->wptw_is_valid_zip( $large_zip_file_path ) ) {
				$batch[ "large_zip_{$large_zips}" ] = array(
					'size'      => filesize( $large_zip_file_path ),
					'path'      => $large_zip_file_path,
					'status'    => 'completed',
					'timestamp' => current_time( 'mysql' ),
					'attempts'  => $existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'],
				);

				$existing_data[ $key ]['batch']              = $batch;
				$existing_data[ $key ]['current_large_file'] = $current_large_file_index + 1;
				$this->update_backup_data( $existing_data );

				$this->update_logs_records( "Archived {$file['filename']}", 'OK' );
			} else {
				$batch[ "large_zip_{$large_zips}" ] = array(
					'status'    => 'failed',
					'timestamp' => current_time( 'mysql' ),
					'attempts'  => $existing_data[ $key ]['batch'][ "large_zip_{$large_zips}" ]['attempts'],
				);
				$existing_data[ $key ]['batch']     = $batch;
			}

			$cancel_pause = $this->wptw_backup_cancel_pause_data();
			if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) {
				$timestamp = wp_next_scheduled( 'wptw_backup_daily_scan' );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'wptw_backup_daily_scan' );
				}

				$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
				if ( $timestamp_backup_status ) {
					wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
				}

				if ( true === $cancel_pause['cron_running'] ) {
					$cancel_pause['cron_running'] = false;
					$this->update_backup_cancel_pause( $cancel_pause );
				}
				$this->update_backup_scan_state( $cancel_pause['scan_state'] );
				return;
			} else {
				$timestamp = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'wptw_verify_backup_cron_hook' );
				}
				wp_schedule_single_event( time() + 3, 'wptw_backup_daily_scan' );
				return;
			}
		}

		// Pack parts from the manifest cursor in a time-budgeted loop (many parts/tick),
		// advancing offset + index in lockstep — no per-part-retry desync. Falls through
		// to folder completion once every part is written.
		if ( ! wp_next_scheduled( 'wptw_verify_backup_cron_hook' ) ) {
			wp_schedule_single_event( time() + 230, 'wptw_verify_backup_cron_hook' );
		}
		$cursor_offset   = (int) ( isset( $existing_data[ $key ]['manifest_offset'] ) ? $existing_data[ $key ]['manifest_offset'] : 0 );
		$manifest_path = isset( $existing_data[ $key ]['manifest_file'] ) ? $existing_data[ $key ]['manifest_file'] : '';
		$in_zip_prefix   = $this->wptw_path_prefix_for_key( $key );
		$tick_deadline = time() + 25;
		$paused   = false;

		// No-progress guard: if this folder's manifest cursor has not advanced since the
		// previous tick, one part is failing to complete every tick — e.g. a large part whose
		// ZipArchive::close() is killed before the cursor / fail-counter (both updated only AFTER
		// the pack) can advance, so it would re-pack forever. Persist BEFORE packing so a kill
		// still counts; fail cleanly after the cap. A slow-but-completing part always advances.
		$last_tick_offset = isset( $existing_data[ $key ]['last_tick_offset'] ) ? (int) $existing_data[ $key ]['last_tick_offset'] : -1;
		$stuck_ticks      = isset( $existing_data[ $key ]['stuck_ticks'] ) ? (int) $existing_data[ $key ]['stuck_ticks'] : 0;
		$stuck_ticks      = ( $last_tick_offset === $cursor_offset ) ? ( $stuck_ticks + 1 ) : 0;
		if ( $stuck_ticks >= 3 ) {
			$this->update_logs_records( "Backup of {$key} stalled at part {$current_zip_index} of {$num_zips} (a single part is exceeding the host time limit) — aborting backup", 'ERROR' );
			$this->wptw_mark_backup_failed( "part_pack_no_progress:{$key}:{$current_zip_index}" );
			return;
		}
		$existing_data[ $key ]['last_tick_offset'] = $cursor_offset;
		$existing_data[ $key ]['stuck_ticks']      = $stuck_ticks;
		$this->update_backup_data( $existing_data ); // persist the guard BEFORE packing so a kill counts

		// Throttled so a long folder shows steady movement (~20 lines) instead of looking
		// frozen between "Creating N parts" and "Completed".
		$progress_step = max( 10, (int) ceil( $num_zips / 20 ) );

		while ( $current_zip_index <= $num_zips && time() < $tick_deadline ) {
			$zip_file_path = $daily_backup_directory . "{$key}_part_{$current_zip_index}_{$zip_unique_id}.zip";
			$result        = $this->wptw_pack_part_from_manifest( $manifest_path, $cursor_offset, $folder_path, $in_zip_prefix, $zip_file_path, $zip_size_limit );
			if ( false === $result ) {
				// Hard packer failure (manifest gone, disk full, or the part zip can't be
				// created). The cursor hasn't advanced, so a persistent error would re-break
				// at the same offset every tick and loop the worker forever with the UI stuck
				// 'in-progress'. Cap consecutive failures and give up cleanly via the terminal
				// 'failed' path so the spinner resolves and the user is told. (Healthy backups
				// never hit this — false only comes from catastrophic I/O.)
				$pack_fail = isset( $existing_data[ $key ]['pack_fail_attempts'] ) ? (int) $existing_data[ $key ]['pack_fail_attempts'] : 0;
				++$pack_fail;
				$existing_data[ $key ]['pack_fail_attempts'] = $pack_fail;
				$this->update_backup_data( $existing_data );
				if ( $pack_fail >= 3 ) {
					$this->update_logs_records( "Failed to write a part for {$key} after {$pack_fail} attempts — aborting backup", 'ERROR' );
					$this->wptw_mark_backup_failed( "part_pack_failed:{$key}" );
					return;
				}
				break;
			}
			// Successful pack — clear the consecutive hard-failure counter.
			$existing_data[ $key ]['pack_fail_attempts'] = 0;
			if ( $this->wptw_is_valid_zip( $zip_file_path ) ) {
				$batch[ "zip_{$current_zip_index}" ] = array(
					'size'      => filesize( $zip_file_path ),
					'path'      => $zip_file_path,
					'status'    => 'completed',
					'timestamp' => current_time( 'mysql' ),
				);
			} else {
				$batch[ "zip_{$current_zip_index}" ] = array(
					'status'    => 'failed',
					'timestamp' => current_time( 'mysql' ),
				);
			}
			$cursor_offset = (int) $result['next_offset'];
			++$current_zip_index;
			$existing_data[ $key ]['batch']           = $batch;
			$existing_data[ $key ]['current_zip']     = $current_zip_index;
			$existing_data[ $key ]['manifest_offset'] = $cursor_offset;
			$this->update_backup_data( $existing_data );

			// Show steady progress so a long folder doesn't look frozen (throttled).
			$done_parts = $current_zip_index - 1; // parts completed so far in this folder
			if ( $done_parts > 0 && 0 === ( $done_parts % $progress_step ) ) {
				$this->update_logs_records( "Archived {$done_parts} of {$num_zips} parts for {$key}" );
			}

			$cancel_pause = $this->wptw_backup_cancel_pause_data();
			if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) {
				$paused = true;
				break;
			}
		}
		
		if ( $paused ) {
			$timestamp = wp_next_scheduled( 'wptw_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wptw_backup_daily_scan' );
			}
			$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
			}
			$cancel_pause = $this->wptw_backup_cancel_pause_data();
			if ( true === $cancel_pause['cron_running'] ) {
				$cancel_pause['cron_running'] = false;
				$this->update_backup_cancel_pause( $cancel_pause );
			}
			$this->update_backup_scan_state( $cancel_pause['scan_state'] );
			return;
		}
		
		if ( $current_zip_index <= $num_zips ) {
			$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
			}
			wp_schedule_single_event( time() + 3, 'wptw_backup_daily_scan' );
			return;
		}

		$this->wptw_update_scan_state_unschedule_cron();

		// If all ZIPs are created, mark the process as complete.
		$existing_data[ $key ]['path']      = $folder_path;
		$existing_data[ $key ]['completed'] = true;
		$this->update_backup_data( $existing_data );

		$this->update_logs_records( "Completed {$key}", 'OK' );
	}

	/**
	 * Updates scan state and unschedules backup-related crons.
	 *
	 */
	public function wptw_update_scan_state_unschedule_cron() {
		$cancel_pause = $this->wptw_backup_cancel_pause_data();
		if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) {
			$timestamp = wp_next_scheduled( 'wptw_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wptw_backup_daily_scan' );
			}

			$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
			}

			if ( true === $cancel_pause['cron_running'] ) {
				$cancel_pause['cron_running'] = false;
				$this->update_backup_cancel_pause( $cancel_pause );
			}
			$this->update_backup_scan_state( $cancel_pause['scan_state'] );
			return;
		} else {
			$timestamp = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wptw_verify_backup_cron_hook' );
			}
			wp_schedule_single_event( time() + 3, 'wptw_backup_daily_scan' );
			return;
		}
	}

	/**
	 * Removes .part files for a given large ZIP base name in a directory.
	 *
	 * @param string $directory      Directory path.
	 * @param string $large_zip_name Base name of the large ZIP.
	 */
	private function remove_related_incomplete_files( $directory, $large_zip_name ) {
		if ( is_dir( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- Streamed enumeration of plugin-owned .part fragments; dirlist() would materialize the whole tree at once.
			$handle = opendir( $directory );
			if ( false !== $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readdir -- Paired with opendir() above.
				$file = readdir( $handle );
				while ( false !== $file ) {
					if ( '.' !== $file && '..' !== $file ) {
						$file_path = $directory . '/' . $file;
						if ( 0 === strpos( $file, $large_zip_name ) && false !== strpos( $file, '.part' ) ) {
							wp_delete_file( $file_path );
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readdir -- Paired with opendir() above.
					$file = readdir( $handle );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- Closes the opendir() handle above.
				closedir( $handle );
			}
		}
	}

	/**
	 * Creates a ZIP containing a single (large) file.
	 *
	 * @param array  $file          File info with pathname.
	 * @param string $key           Folder key for path prefix.
	 * @param int    $large_zips    Large ZIP index.
	 * @param string $source        Source path.
	 * @param string $destination   Destination directory.
	 * @param string $zip_unique_id Unique ID for ZIP name.
	 * @return bool True on success.
	 */
	public function create_single_file_zip( $file, $key, $large_zips, $source, $destination, $zip_unique_id ) {
		if ( ! isset( $file['pathname'] ) || ! file_exists( $file['pathname'] ) ) {
			return false;
		}

		if ( ! file_exists( $destination ) ) {
			if ( ! wp_mkdir_p( $destination ) ) {
				return false;
			}
		}

		$file_path            = $file['pathname'];
		$file_size            = filesize( $file_path );
		$single_file_zip_name = "{$key}_large_part_{$large_zips}_{$zip_unique_id}.zip";
		$single_file_zip_path = $destination . DIRECTORY_SEPARATOR . $single_file_zip_name;
		$log_file_path        = WPTW_LOGS_DIRECTORY . '/backup_zip_file_log.txt';

		// Log if file is >50MB to confirm processing.
		if ( $file_size > 52428800 ) {
			$wp_filesystem = FilesystemService::get_filesystem();
			if ( $wp_filesystem ) {
				$existing = $wp_filesystem->get_contents( $log_file_path );
				$new_line = current_time( 'mysql' ) . " - Processing large single file (>50MB): {$file_path} (size: {$file_size} bytes)\n";
				$wp_filesystem->put_contents( $log_file_path, ( $existing ? $existing . $new_line : $new_line ), FS_CHMOD_FILE );
			}
		}

		// Determine path prefix.
		$content_base = basename( WP_CONTENT_DIR );
		$path_prefix  = '';
		switch ( $key ) {
			case 'wp-admin':
				$path_prefix = 'wp-admin';
				break;
			case 'wp-includes':
				$path_prefix = 'wp-includes';
				break;
			case 'wp-content':
				$path_prefix = $content_base;
				break;
			case 'plugins':
				$path_prefix = $content_base . '/plugins';
				break;
			case 'themes':
				$path_prefix = $content_base . '/themes';
				break;
			case 'uploads':
				$path_prefix = $content_base . '/uploads';
				break;
			case 'others':
				$path_prefix = '';
				break;
			default:
				$path_prefix = $key;
				break;
		}

		// Call global ZIP function, skipping 50MB limit for single file.
		$create_zip = new ZipCreation();
		return $create_zip->wptw_create_zip_global(
			$file_path,
			$single_file_zip_path,
			$path_prefix,
			array(),
			0,
			null,
			$log_file_path,
			true
		);
	}


	/**
	 * Listener for the generic 'wptw_recovery_process_failed' action (fired by
	 * RecoveryService when a process exhausts its retries). Only acts on backups.
	 *
	 * @param array $process_data The failed process row (must carry process_type).
	 * @return void
	 */
	public function wptw_on_recovery_failed( $process_data ) {
		if ( ! empty( $process_data['process_type'] ) && 'backup' === $process_data['process_type'] ) {
			$this->wptw_mark_backup_failed( 'recovery_max_retries' );
		}
	}

	/**
	 * Put a wedged backup into the terminal 'failed' state so the UI spinner resolves
	 * (it otherwise stays 'in-progress' forever after recovery gives up). Stops the
	 * worker/watchdog crons and notifies the user honestly. 'failed' is handled by the
	 * status reader, history list, and cleanup cron (24h TTL); the partial files are
	 * left until then so the user can see the failed run. Only converts a still-running
	 * backup — never overrides a user pause/cancel or an already-finished run.
	 *
	 * @param string $reason Short machine reason for logs.
	 * @return void
	 */
	public function wptw_mark_backup_failed( $reason = '' ) {
		$cancel_pause = $this->wptw_backup_cancel_pause_data();
		if ( empty( $cancel_pause ) || ! isset( $cancel_pause['scan_state'] ) || 'in-progress' !== $cancel_pause['scan_state'] ) {
			return;
		}

		// Stop the worker chain + watchdog + DB sub-step crons.
		foreach ( array( 'wptw_backup_daily_scan', 'wptw_verify_backup_cron_hook', 'wptw_create_db_backup_cron', 'wptw_scan_db_tables_cron' ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		$cancel_pause['scan_state']   = 'failed';
		$cancel_pause['cron_running'] = false;
		$this->update_backup_cancel_pause( $cancel_pause );

		$scan_backp = $this->wptw_get_scan_backup_data();
		if ( ! empty( $scan_backp ) && is_array( $scan_backp ) ) {
			$scan_backp['scan_state'] = 'failed'; // completed stays false → cleanup GCs it after 24h.
			$this->update_backup_data( $scan_backp );
		}

		// Drop the process-monitor row out of the "active" set so the recovery watchdog
		// stops re-picking it as stuck (it otherwise churns every ~2 min and, mid DB phase,
		// could resurrect the run). Without this the row lingers in_progress indefinitely.
		$failed_process_id = ! empty( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : $this->current_process_id;
		if ( ! empty( $failed_process_id ) ) {
			$this->process_manager->mark_failed( $failed_process_id, '' !== $reason ? $reason : 'backup marked failed' );
		}
		$this->current_process_id = null;

		$backup_type = isset( $scan_backp['backupType'] ) ? $scan_backp['backupType'] : 'website';
		$impact      = 'The backup stopped partway through and could not be completed. Your previous backups are still safe — please try again.';
		Log::error(
			"Your {$backup_type} could not be completed and was marked as failed. {$impact}",
			array(
				'feature'   => 'backup',
				'action'    => 'backup_process_failed', // routes to backup_push_notification, like the scan-loop catch.
				'title'     => 'Backup failed',
				'meta_data' => array(
					'feature'     => 'Backup Vault',
					'event'       => 'Failed',
					'impact'      => $impact,
					'backup_type' => $backup_type,
					'reason'      => $reason,
				),
			)
		);
	}

	/**
	 * Whether a zip part on disk is structurally valid (not a truncated/corrupt file
	 * left by an OOM mid-write). Without this, a bare file_exists() check sees the
	 * truncated file, skips recreation, and marks a corrupt part complete. When
	 * ext-zip is present we confirm the central directory opens; on PclZip-only hosts
	 * we can only confirm non-empty size. (Real WP folders add directory entries to
	 * every part, so a valid data-empty part still opens — only a truncated file fails.)
	 *
	 * @param string $path Absolute zip path.
	 * @return bool True if the file exists and is a readable archive.
	 */
	private function wptw_is_valid_zip( $path ) {
		if ( ! file_exists( $path ) || filesize( $path ) <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $path ) ) {
				$zip->close();
				return true;
			}
			return false; // truncated / corrupt central directory.
		}
		return true; // PclZip-only host: size>0 is the best signal available.
	}

	/**
	 * Collect the parts that permanently failed (marked status='failed' after the
	 * 3-attempt cap) across every folder's batch. Pure read — no side effects — so
	 * finalize can report an incomplete backup honestly. Returns "folder/part" keys.
	 *
	 * @param array $existing_data Backup state.
	 * @return array List of "folderKey/partKey" strings (empty when all parts succeeded).
	 */
	private function wptw_collect_failed_parts( array $existing_data ) {
		$failed_parts = array();
		foreach ( $existing_data as $folder_key => $folder_data ) {
			if ( ! is_array( $folder_data ) || empty( $folder_data['batch'] ) || ! is_array( $folder_data['batch'] ) ) {
				continue;
			}
			foreach ( $folder_data['batch'] as $part_key => $part ) {
				if ( isset( $part['status'] ) && 'failed' === $part['status'] ) {
					$failed_parts[] = $folder_key . '/' . $part_key;
				}
			}
		}
		return $failed_parts;
	}

	/**
	 * Checks if all folders are completed and finalizes backup (mark completed, notification, process manager).
	 *
	 * @param array    $existing_data       Backup state (by reference).
	 * @param mixed    $feature_controller  DB model instance.
	 * @param string   $wptw_key            Option key.
	 * @param string   $option              Option name.
	 * @param int|null $process_id        Process ID for process manager.
	 */
	public function check_and_finalize_backup( &$existing_data, $feature_controller, $wptw_key, $option, $process_id ) {
		$all_completed = true;
		$cancel_pause  = $this->wptw_backup_cancel_pause_data();

		foreach ( $existing_data as $folder => $data ) {

			if ( 'userData' === $folder ) {
				continue;
			}

			if ( is_array( $data ) && ( ! isset( $data['completed'] ) || false === $data['completed'] ) ) {
				$all_completed = false;
				break;
			}
		}

		if ( true === $all_completed ) {
			$this->update_logs_records( 'Backup complete', 'SUCCESS' );

			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				// No action when scan state is cancel or pause.
			}

			// Phase 1a — find parts that permanently failed (hit the 3-attempt cap and
			// were marked status='failed') so an incomplete backup is reported honestly
			// instead of as a clean success. scan_state STAYS 'completed' (its enum is
			// consumed by ~14 hardcoded sites; a new value would silently break the
			// status reader, list filter, and cleanup) — the truth lives in these
			// separate flags and the notification text.
			$failed_parts = $this->wptw_collect_failed_parts( $existing_data );
			$had_failures = ! empty( $failed_parts );

			$existing_data['had_failures'] = $had_failures;
			$existing_data['failed_parts'] = $failed_parts;
			$existing_data['completed']    = true;
			$existing_data['scan_state']   = 'completed';
			$this->update_backup_data( $existing_data );

			if ( 'in-progress' === $cancel_pause['scan_state'] ) {
				$cancel_pause['scan_state'] = 'completed';
				$this->update_backup_cancel_pause( $cancel_pause );
			}

			// Mark process as completed in process monitor.
			if ( ! empty( $process_id ) ) {
				$this->process_manager->mark_completed( $process_id );

				// Clear the current process ID.
				$this->current_process_id = null;
			}

			// Log backup completion (also triggers notification via NotificationActions).
			// Routing stays on action 'backup_complete' (→ backup_push_notification) in
			// BOTH cases; only the human text + meta change when parts permanently
			// failed, so the user is told the truth instead of a false "ready".
			$complete_backup_type = isset( $existing_data['backupType'] ) ? $existing_data['backupType'] : 'website';
			if ( $had_failures ) {
				$failed_count   = count( $failed_parts );
				$partial_impact = 'Some files could not be archived, so this backup may be incomplete. We recommend running a new backup.';
				Log::warning(
					"Your {$complete_backup_type} finished, but {$failed_count} part(s) could not be archived — your backup may be incomplete.",
					array(
						'feature'   => 'backup',
						'action'    => 'backup_complete',
						'title'     => 'Backup completed with warnings',
						'meta_data' => array(
							'feature'      => 'Backup Vault',
							'event'        => 'Completed',
							'impact'       => $partial_impact,
							'backup_type'  => $complete_backup_type,
							'partial'      => true,
							'failed_count' => $failed_count,
						),
					)
				);
			} else {
				$complete_impact = 'If anything ever happens to your site, you can restore from this backup in minutes.';
				Log::info(
					"Your {$complete_backup_type} is ready and saved to the Vault. {$complete_impact}",
					array(
						'feature'   => 'backup',
						'action'    => 'backup_complete',
						'title'     => 'Backup ready',
						'meta_data' => array(
							'feature'     => 'Backup Vault',
							'event'       => 'Completed',
							'impact'      => $complete_impact,
							'backup_type' => $complete_backup_type,
						),
					)
				);
			}

			wp_delete_file( WPTW_LOGS_DIRECTORY . '/backup_zip_file_log.txt' );

			// Allow pro plugin (MalwareScanner) to handle backup completion notification.
			do_action( 'wptw_backup_completed_notification', $existing_data );

			$live_logs = new LiveLogsController();
			$live_logs->wptw_live_logs_completed( true, $this->wptw_get_log_file_path() );

			$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
			}

			Log::info(
				'Feature status resolved',
				array(
					'feature' => 'backup',
					'action'  => 'update_errors_feature_status',
				)
			);

			wp_clear_scheduled_hook( 'wptw_backup_daily_scan' );
		}
	}

	/**
	 * Returns live logs for backup feature (delegates to LiveLogsController).
	 *
	 * @param string $post_data JSON-encoded POST data.
	 * @return array Response with data or error.
	 */
	public function wptw_get_live_logs( $post_data ) {
		try {
			$backup_data  = $this->wptw_backup_cancel_pause_data();
			$feature_type = 'create_backup';

			$params = array(
				'backup_size' => $this->wptw_get_specfic_backup_size( $backup_data ),
			);

			$livelogs = new LiveLogsController();
			$result   = $livelogs->wptw_import_live_logs( $post_data, $this->wptw_get_log_file_path(), $backup_data, $feature_type, $params );

			return $result;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_get_live_logs_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while retrieving live logs.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Calculates backup size for a given backup (files + DB dirs or premium hook).
	 *
	 * @param array $backup_data Cancel/pause backup data with folderDate, backup_type.
	 * @return int|float Size in bytes.
	 */
	public function wptw_get_specfic_backup_size( $backup_data ) {
		$premium_size = apply_filters( 'wptw_premium_backup_size_calculation', false, $backup_data );
		if ( false !== $premium_size ) {
			// Validate hook response (should be numeric size in bytes).
			if ( is_numeric( $premium_size ) && $premium_size >= 0 ) {
				return $premium_size;
			} else {
				Log::error(
					'Premium backup size hook returned invalid format (type: ' . gettype( $premium_size ) . ')',
					array(
						'feature' => 'backup',
						'action'  => 'premium_size_hook_invalid_response',
					)
				);
			}
		}

		$files_directory    = $this->backup_directory . $backup_data['folderDate'];
		$database_directory = $this->db_directory . $backup_data['folderDate'];
		switch ( $backup_data['backup_type'] ) {
			case 'Complete Backup':
				$backup_size  = DiskSpaceController::calculate_folder_size( $files_directory );
				$backup_size += DiskSpaceController::calculate_folder_size( $database_directory );
				break;
			default:
				$backup_size = 0;
		}
		return $backup_size;
	}

	/**
	 * Starts an instant/on-demand backup scan (validates cron, then starts backup).
	 *
	 * @param string $post_data JSON-encoded POST data with instant_scan.
	 * @return array Response with code and message.
	 */
	public function wptw_instant_backup_scanner( $post_data ) {
		try {
			// Refuse to start if a conflicting process is currently running.
			// See ProcessGuard for the cannot_start_while declaration on the
			// 'backup' process registration.
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'backup' );
			if ( null !== $blocked ) {
				return $blocked;
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Invalid input data provided',
					array(
						'feature' => 'backup',
						'action'  => 'backup_start_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( isset( $data['instant_scan'] ) && true === $data['instant_scan'] ) {

				$cron_status = ( new CronHealthService() )->test( 'backup' );
				if ( ! $cron_status['success'] ) {
					Log::error(
						'Cron access test failed: ' . ( isset( $cron_status['message'] ) ? $cron_status['message'] : '' ),
						array(
							'feature' => 'backup',
							'action'  => 'backup_start_failed',
						)
					);
					return array(
						'message' => __( 'Failed to run the Backup due to an issue with the cron.', 'tailwatch' ),
						'error'   => isset( $cron_status['message'] ) ? $cron_status['message'] : '',
						'code'    => 400,
					);
				}

				$optimize_skip     = true;
				$database_optimize = false;

				$get_backup_option = new BackupMaintainController();
				$backup_data       = $get_backup_option->wptw_get_backup_settings();

				// Allow premium plugin to add backup types that support optimization.
				$optimization_supported_types = apply_filters( 'wptw_backup_types_supporting_optimization', array( 'Complete Backup' ), $backup_data );

				if ( true === $backup_data['optimizeDatabase'] && in_array( $backup_data['backupType'], $optimization_supported_types, true ) ) {
					$database_clean = new DatabaseOptimizerController();
					$get_options    = $database_clean->wptw_db_optimize_options();

					if (
						empty( $get_options ) || ! isset( $get_options['field_1']['options']['option']['selected'] ) ||
						false === $get_options['field_1']['options']['option']['selected']
					) {
						$optimize_skip = false;
					} else {
						$optimize_skip     = false;
						$database_optimize = true;
						$this->wptw_start_backup_creation( $database_optimize, $optimize_skip, 'on-demand' );
					}
				} else {
					$this->wptw_start_backup_creation( $database_optimize, $optimize_skip, 'on-demand' );
				}

				Log::info(
					'Instant backup scanner started successfully',
					array(
						'feature' => 'backup',
						'action'  => 'backup_started',
					)
				);

				$response = array(
					'instant_scan'      => $data['instant_scan'],
					'optimize_skip'     => $optimize_skip,
					'database_optimize' => $database_optimize,
					'message'           => __( 'Successfully Run Backup process and Cron Schedule Reset.', 'tailwatch' ),
					'code'              => 200,
				);
			} else {
				Log::error(
					'instant_scan parameter is false',
					array(
						'feature' => 'backup',
						'action'  => 'backup_start_failed',
					)
				);
				$response = array(
					'data'    => array(),
					'message' => __( 'Failed to run backup process: instant_scan is false.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_start_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while starting backup.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Resolve whether the database optimizer should run before a backup.
	 *
	 * Encapsulates the full gate chain used by the user-initiated entry
	 * point (`wptw_backup_api_cron_start`) so the recurring cron path
	 * (`BackupCronJob::execute`) can honour the same setting. Prior to
	 * 1.0.1 the cron path hardcoded the `$database_optimize` argument
	 * to `false`, which silently disabled the "Optimize Database Before
	 * Backup" toggle for every scheduled backup. This helper restores
	 * symmetry between the two entry points.
	 *
	 * Gates (all must pass to return `true`):
	 *   - `optimizeDatabase` is true in the backup settings (the
	 *     opt-in toggle for the backup feature).
	 *   - The selected `backupType` is in the list of types that
	 *     support optimization (Complete Backup by default, with
	 *     companion plugins free to add their own types via the
	 *     `wptw_backup_types_supporting_optimization` filter).
	 *   - The Database Optimizer feature itself is configured AND
	 *     enabled (no point running an optimizer the user has
	 *     turned off elsewhere).
	 *
	 * @param array $backup_data Settings array from
	 *                           {@see BackupMaintainController::wptw_get_backup_settings()}.
	 * @return bool True if the optimizer should run before the backup, false otherwise.
	 */
	public function should_optimize_database_for_backup( $backup_data ) {
		if ( ! is_array( $backup_data ) ) {
			return false;
		}

		if ( true !== ( isset( $backup_data['optimizeDatabase'] ) ? $backup_data['optimizeDatabase'] : null ) ) {
			return false;
		}

		$supported_types = apply_filters(
			'wptw_backup_types_supporting_optimization',
			array( 'Complete Backup' ),
			$backup_data
		);

		$backup_type = isset( $backup_data['backupType'] ) ? $backup_data['backupType'] : '';
		if ( ! in_array( $backup_type, $supported_types, true ) ) {
			return false;
		}

		$db_optimizer = new DatabaseOptimizerController();
		$options      = $db_optimizer->wptw_db_optimize_options();

		if ( empty( $options )
			|| ! isset( $options['field_1']['options']['option']['selected'] )
			|| false === $options['field_1']['options']['option']['selected'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Unschedules recurring backup and starts backup process (folder sizes + cron).
	 *
	 * @param bool   $database_optimize Whether to run DB optimization.
	 * @param bool   $optimize_skip     Whether to skip optimization.
	 * @param string $scan_type         Scan type (e.g. automatically, on-demand).
	 */
	public function wptw_start_backup_creation( $database_optimize, $optimize_skip, $scan_type = 'automatically' ) {
		$db_optimize  = true === $database_optimize && false === $optimize_skip;
		$current_user = wp_get_current_user();
		$user_name    = $current_user->user_login;
		$user_role    = implode( ', ', $current_user->roles );

		// Unschedule recurring backup so it does not fire during this manual/instant run.
		$backup_job = CronJobManager::get_instance()->get_cron_job( 'backup' );
		if ( null !== $backup_job ) {
			$backup_job->unschedule();
		}
		$this->wptw_calculate_folder_sizes( $user_name, $user_role, $scan_type, 'backup', $db_optimize );
	}

	/**
	 * Parses POST data and starts backup with or without DB optimization based on settings.
	 *
	 * @param string $post_data JSON-encoded POST data.
	 * @return array Response with code and message.
	 */
	public function wptw_start_backup_with_optimize_or_not( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Invalid input data provided',
					array(
						'feature' => 'backup',
						'action'  => 'backup_start_backup_with_optimize_or_not_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( isset( $data['optimize_skip'] ) && false === $data['optimize_skip'] ) {
				$optimizer_enable = $this->wptw_enable_database_optimizer();

				if ( $optimizer_enable ) {
					Log::info(
						'Backup process started with database optimization',
						array(
							'feature' => 'backup',
							'action'  => 'backup_start_backup_with_optimize_or_not',
						)
					);
					return array(
						'optimize_skip' => $data['optimize_skip'],
						'message'       => __( 'Backup process started with database optimization.', 'tailwatch' ),
						'code'          => 200,
					);
				} else {
					Log::error(
						'Failed to enable database optimizer',
						array(
							'feature' => 'backup',
							'action'  => 'backup_start_backup_with_optimize_or_not_failed',
						)
					);
					return array(
						'optimize_skip' => $data['optimize_skip'],
						'message'       => __( 'Failed to enable database optimizer. Please try again.', 'tailwatch' ),
						'code'          => 400,
					);
				}
			} else {
				$this->wptw_start_backup_creation( false, true, 'on-demand' );
				Log::info(
					'Backup process started without database optimization',
					array(
						'feature' => 'backup',
						'action'  => 'backup_start_backup_with_optimize_or_not',
					)
				);
				return array(
					'optimize_skip' => $data['optimize_skip'],
					'message'       => __( 'Backup process started without database optimization.', 'tailwatch' ),
					'code'          => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_start_backup_with_optimize_or_not_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while starting backup.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_enable_database_optimizer() {
		$recommended_features = new RecommendedFeaturesController();
		$feature_data         = $recommended_features->wptw_get_feature_id( 'default_database_optimizer' );
		if ( ! $feature_data ) {
			return false;
		}

		$id_is     = $feature_data['id'];
		$is_active = $feature_data['is_active'];

		$parent_status = $recommended_features->wptw_update_parent_active_status( $id_is, $is_active );

		if ( $parent_status ) {
			$options[] = array(
				'id'       => 'enable_database_optimizer',
				'selected' => true,
				'value'    => '',
			);

			$options_data = array(
				'id'      => $id_is,
				'options' => $options,
			);

			$features_controller = new FeaturesController();
			$is_updated          = $features_controller->wptw_update_inner_feature( null, $options_data );

			$this->wptw_start_backup_creation( true, false, 'on-demand' );

			return true;
		}

		return false;
	}

	/**
	 * Verifies backup status (e.g. for API) and returns backup state/data.
	 *
	 * @return array Response with backup status.
	 */
	public function wptw_verify_backup_status() {
		try {
			$existing_data = $this->wptw_backup_cancel_pause_data();
			$backup_data   = $this->wptw_get_scan_backup_data();

			$download_status        = apply_filters( 'wptw_get_backup_downloading_status', array(), false );
			$backup_download_status = false;
			if ( ! empty( $download_status ) && is_array( $download_status ) ) {
				$backup_download_status = $download_status['in_progress'] ?? false;
			}

			// No cancel/pause record means there is no tracked backup process.
			if ( empty( $existing_data ) ) {
				return array(
					'is_completed'       => true,
					'message'            => __( 'Currently no process is in the running.', 'tailwatch' ),
					'backup_downloading' => $backup_download_status,
					'code'               => 200,
				);
			}

			// cancel_pause is the authoritative CURRENT scan state — check it BEFORE the folderDate
			// / missing-scan_backp branches below, so we never report "completed" while the cron is
			// still in-progress or paused.
			$scan_state = $existing_data['scan_state'] ?? null;

			if ( 'pause' === $scan_state ) {
				return array(
					'is_completed'       => false,
					'scan_state'         => 'pause',
					'progress'           => $existing_data['progress'] ?? 0,
					'scan_type'          => $existing_data['scan_type'] ?? '',
					'message'            => __( 'Backup was paused.', 'tailwatch' ),
					'backup_downloading' => $backup_download_status,
					'code'               => 200,
				);
			}

			if ( 'in-progress' === $scan_state ) {
				return array(
					'is_completed'       => false,
					'scan_state'         => 'in-progress',
					'progress'           => $existing_data['progress'] ?? 0,
					'scan_type'          => $existing_data['scan_type'] ?? '',
					'message'            => __( 'Backup is in progress.', 'tailwatch' ),
					'backup_downloading' => $backup_download_status,
					'code'               => 200,
				);
			}

			if ( 'completed' === $scan_state ) {
				return array(
					'is_completed'       => true,
					'scan_state'         => 'completed',
					'progress'           => $existing_data['progress'] ?? 0,
					'message'            => __( 'Backup Successfully Created.', 'tailwatch' ),
					'backup_downloading' => $backup_download_status,
					'code'               => 200,
				);
			}

			// Terminal failure (recovery gave up). Resolve the spinner with an honest
			// message instead of leaving it stuck on 'in-progress' forever.
			if ( 'failed' === $scan_state ) {
				return array(
					'is_completed'       => true,
					'scan_state'         => 'failed',
					'progress'           => $existing_data['progress'] ?? 0,
					'message'            => __( 'Backup failed — please try again.', 'tailwatch' ),
					'backup_downloading' => $backup_download_status,
					'code'               => 200,
				);
			}

			// scan_state is unknown / not set — fall back to scan_backp comparison.
			if ( ! empty( $backup_data ) ) {
				if ( isset( $existing_data['folderDate'], $backup_data['folderDate'] )
					&& $existing_data['folderDate'] === $backup_data['folderDate']
					&& true === ( $backup_data['completed'] ?? false ) ) {
					return array(
						'is_completed'       => true,
						'message'            => __( 'Currently no process is in the running.', 'tailwatch' ),
						'backup_downloading' => $backup_download_status,
						'code'               => 200,
					);
				}
			}

			// Default — indeterminate state, treat as not running.
			return array(
				'is_completed'       => true,
				'message'            => __( 'Currently no process is in the running.', 'tailwatch' ),
				'backup_downloading' => $backup_download_status,
				'code'               => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_verify_backup_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while verifying backup status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_resume_backup() {
		try {
			$existing_data = $this->wptw_get_scan_backup_data();
			if ( ! empty( $existing_data ) && ! empty( $existing_data['backupType'] ) ) {

				// Resume is the one deliberate stop -> in-progress transition; force past the
				// stop-state guard that otherwise keeps a pause/cancel from being reverted.
				$this->update_backup_scan_state( 'in-progress', true );
				$cancel_pause               = $this->wptw_backup_cancel_pause_data();
				$cancel_pause['scan_state'] = 'in-progress';
				$this->update_backup_cancel_pause( $cancel_pause, true );

				// Update process state to in_progress when resumed
				$process_id = $cancel_pause['process_id'] ?? null;
				if ( $process_id ) {
					$this->process_manager->update_state( $process_id, 'in_progress' );
					$this->process_manager->heart_beat( $process_id );
				}

				if ( in_array( $existing_data['backupType'], array( 'Complete Backup' ), true ) ) {
					if ( $existing_data['database_optimize'] === true && $existing_data['optimize_completed'] === false ) {
						$db_optimizer = new DatabaseOptimizerController();
						return $db_optimizer->wptw_resume_db_optimize();
					}
				}

				/**
				 * Filter: wptw_premium_backup_resume
				 *
				 * @param bool  $handled      Whether premium plugin handled the resume (default: false).
				 * @param array $backup_data  Complete backup data for resuming.
				 * @return bool True if handled by premium plugin, false if not handled.
				 */
				$premium_resume = apply_filters( 'wptw_premium_backup_resume', false, $existing_data );
				if ( false === $premium_resume ) {
					switch ( $existing_data['backupType'] ) {
						case 'Complete Backup':
							// Resume the phase the run was actually in. Until the table-scan has built
							// the list, resume the SCAN cron; jumping straight to the dump would find an
							// empty list and mark the DB "complete" — silently skipping it. Mirrors
							// wptw_execute_cron_if_failed().
							if ( ! isset( $existing_data['tables'] ) ) {
								if ( ! wp_next_scheduled( 'wptw_scan_db_tables_cron' ) ) {
									wp_schedule_single_event( time() + 5, 'wptw_scan_db_tables_cron' );
								}
							} elseif ( ! wp_next_scheduled( 'wptw_create_db_backup_cron' ) ) {
								wp_schedule_single_event( time() + 5, 'wptw_create_db_backup_cron' );
							}
							break;
						default:
							break;
					}
				}

				Log::info(
					'Backup resumed successfully',
					array(
						'feature' => 'backup',
						'action'  => 'backup_resume',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Backup Resume Successfully', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				Log::error(
					'No backup data found or backup type is empty',
					array(
						'feature' => 'backup',
						'action'  => 'backup_resume_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Already Backup Schedule', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_resume_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while resuming backup.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_pause_backup_creation( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Invalid input data provided',
					array(
						'feature' => 'backup',
						'action'  => 'backup_pause_cancel_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( ! empty( $data['scan_state'] ) && ( 'pause' === $data['scan_state'] || 'cancel' === $data['scan_state'] ) ) {

				$cancel_pause            = $this->wptw_backup_cancel_pause_data();
				$timestamp_backup_status = wp_next_scheduled( 'wptw_verify_backup_cron_hook' );
				if ( $timestamp_backup_status ) {
					wp_unschedule_event( $timestamp_backup_status, 'wptw_verify_backup_cron_hook' );
				}

				$cancel_pause['scan_state'] = $data['scan_state'];
				$this->update_backup_cancel_pause( $cancel_pause );
				$this->update_backup_scan_state( $data['scan_state'] );

				$backup_data = $this->wptw_get_scan_backup_data();
				if ( $backup_data['optimize_completed'] === false ) {
					$database_clean = new DatabaseOptimizerController();
					$database_clean->wptw_pause_db_optimize( wp_json_encode( array( 'scan_state' => $data['scan_state'] ) ) );
				}

				$timestamp = wp_next_scheduled( 'wptw_backup_daily_scan' );
				if ( true ) {
					wp_unschedule_event( $timestamp, 'wptw_backup_daily_scan' );

					// Handle process state based on cancel/pause
					$process_id = $cancel_pause['process_id'] ?? null;

					if ( 'cancel' === $data['scan_state'] ) {
						// Mark process as failed when cancelled
						if ( $process_id ) {
							$this->process_manager->mark_failed( $process_id, 'Backup cancelled by user' );
						}

						$message = __( 'Backup cancel successfully, and previous backup files have been deleted.', 'tailwatch' );
						Log::info(
							'Backup cancelled successfully',
							array(
								'feature' => 'backup',
								'action'  => 'backup_cancel',
							)
						);

						wp_schedule_single_event( time() + 5, 'wptw_delete_backup_files_entry' );
					} elseif ( 'pause' === $data['scan_state'] ) {
						// Keep state as in_progress when paused (for recovery)
						// Just send final heartbeat
						if ( $process_id ) {
							$this->process_manager->update_state( $process_id, 'pause' );
						}

						$message = __( 'Backup paused successfully. You can resume it later.', 'tailwatch' );
						Log::info(
							'Backup paused successfully',
							array(
								'feature' => 'backup',
								'action'  => 'backup_pause',
							)
						);

						$cancel_pause['cron_running'] = false;
						$this->update_backup_cancel_pause( $cancel_pause );
					}

					return array(
						'data'    => array(),
						'message' => $message,
						'code'    => 200,
					);
				} else {
					Log::error(
						'No scheduled backup found to pause or stop',
						array(
							'feature' => 'backup',
							'action'  => 'backup_pause_cancel_failed',
						)
					);

					return array(
						'data'    => array(),
						'message' => __( 'No scheduled backup found to pause or stop.', 'tailwatch' ),
						'code'    => 404,
					);
				}
			} else {
				Log::error(
					'Stop type is missing or invalid',
					array(
						'feature' => 'backup',
						'action'  => 'backup_pause_cancel_failed',
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
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_pause_cancel_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred while pausing/cancelling backup.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_delete_files_entry_with_cron() {

		$feature_controller = new DBModel();
		$key                = 'default_backup_scan';
		$existing_data      = $this->wptw_get_scan_backup_data();
		$backup_progress    = $this->wptw_backup_cancel_pause_data();

		$is_folder_deleted = array(
			'folder_deleted' => false,
			'message'        => __( 'No action taken.', 'tailwatch' ),
			'code'           => 400,
		);

		if (
			! empty( $existing_data )
			&& is_array( $backup_progress )
			&& isset( $backup_progress['scan_state'] )
			&& in_array( $backup_progress['scan_state'], array( 'in-progress', 'pause', 'cancel' ), true )
			&& false === $existing_data['completed']
		) {
			if ( isset( $backup_progress['folderDate'] ) && $existing_data['folderDate'] === $backup_progress['folderDate'] ) {
				$is_folder_deleted = $this->wptw_cleanup_incomplete_backup( $existing_data );
				if ( isset( $is_folder_deleted['folder_deleted'] ) && true === $is_folder_deleted['folder_deleted'] ) {
					$delete_entries = array( 'scan_backp', 'backup_cancel_pause' );

					foreach ( $delete_entries as $delete_entry ) {
						$feature_controller->delete_recent_row( $key, $delete_entry );
					}
				}
			}
		} else {
			return array(
				'message' => __( 'No valid stop type or incomplete backup found for deletion.', 'tailwatch' ),
				'code'    => 400,
			);
		}

		return $is_folder_deleted;
	}

	public function delete_backup_json_file( $delete_file ) {
		if ( file_exists( $delete_file ) ) {
			wp_delete_file( $delete_file );
		}
	}

	public function wptw_delete_backup_entries( $delete_entries ) {
		foreach ( $delete_entries as $delete_entry ) {
			$this->wptw_delete_backup_entry( $delete_entry );
		}
	}

	public function wptw_delete_backup_folders( $existing_data ) {
		$folder_name = isset( $existing_data['folderDate'] ) ? $existing_data['folderDate'] : '';

		// Empty folderDate is bad input (we can't tell which folder to touch) -> signal failure.
		// The both-folders-already-gone case is NOT handled here: the per-folder branches below
		// treat "absent" as success and the union returns true, so out-of-band cleanup (auto-cron
		// race, external delete) still proceeds to delete the stale DB rows.
		if ( empty( $folder_name ) ) {
			return false;
		}

		$delete_folder_files = $this->backup_directory . $folder_name . '/';
		$delete_folder_db    = $this->db_directory . $folder_name . '/';

		if ( file_exists( $delete_folder_files ) ) {
			$folder_deleted_files = $this->delete_backup_folder( $delete_folder_files );
		} else {
			$folder_deleted_files = true;
		}

		if ( file_exists( $delete_folder_db ) ) {
			$folder_deleted_db = $this->delete_backup_folder( $delete_folder_db );
		} else {
			$folder_deleted_db = true;
		}

		return $folder_deleted_files && $folder_deleted_db;
	}

	public function wptw_cleanup_incomplete_backup( $existing_data ) {
		$is_deleted = $this->wptw_delete_backup_folders( $existing_data );
		if ( $is_deleted ) {
			return array(
				'folder_deleted' => true,
				'message'        => __( 'Incomplete backup folders and their files have been deleted successfully.', 'tailwatch' ),
				'code'           => 200,
			);
		} else {
			return array(
				'folder_deleted' => false,
				'message'        => __( 'Some folders could not be deleted or did not exist.', 'tailwatch' ),
				'code'           => 400,
			);
		}
	}

	public function delete_backup_folder( $folder_path ) {
		if ( empty( $folder_path ) || ! file_exists( $folder_path ) ) {
			return true;
		}

		$wp_filesystem = FilesystemService::get_filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		return $wp_filesystem->delete( $folder_path, true );
	}

	public function wptw_auto_cleanup_incomplete_backup() {
		// Allow pro plugin (MalwareScanner) to provide scanner data.
		$scanner_data = apply_filters( 'wptw_get_malware_scanner_progress_data', array() );

		if ( file_exists( $this->get_migration_progress ) ) {
			$fs             = FilesystemService::get_filesystem();
			$migration_raw  = $fs ? $fs->get_contents( $this->get_migration_progress ) : false;
			$migration_data = ( false === $migration_raw ) ? array() : (array) json_decode( $migration_raw, true );

			if ( $migration_data['is_completed'] === false ) {
				return;
			}
		} elseif ( ! empty( $scanner_data ) && isset( $scanner_data['all_completed'] ) && $scanner_data['all_completed'] === false ) {
			return;
		}

		$feature_controller = new DBModel();
		$wptw_key           = 'default_backup_scan';
		$option             = 'scan_backp';

		$backup_maintain = new BackupMaintainController();
		$backup_maintain->maintain_backups( $wptw_key, $option );

		$all_backups          = $feature_controller->get_log_value( $wptw_key, $option );
		$all_backups_progress = $feature_controller->get_log_value( $wptw_key, 'backup_cancel_pause' );

		if ( ! empty( $all_backups ) ) {
			$all_backup_dates = array();
			foreach ( $all_backups as $backup_data ) {
				$backup_json        = json_decode( $backup_data['value'], true );
				$all_backup_dates[] = $backup_json['folderDate'];
			}

			foreach ( $all_backups as $backup_data ) {
				$backup_json = json_decode( $backup_data['value'], true );
				$folder_date = $backup_json['folderDate'];
				$backup_logs = array();

				if ( $backup_json['completed'] === false ) {
					$has_progress_entry = false;

					foreach ( $all_backups_progress as $backup_progress ) {
						$backup_progress_json = json_decode( $backup_progress['value'], true );

						if ( $backup_progress_json['folderDate'] === $folder_date ) {
							$has_progress_entry = true;

							// Handle cancel state
							if ( $backup_progress_json['scan_state'] === 'cancel' ) {
								$this->wptw_delete_backup_folders( $backup_json );
								$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
								$this->wptw_delete_backup_entries( $delete_entries );

								$index = array_search( $folder_date, $all_backup_dates );
								if ( $index !== false ) {
									unset( $all_backup_dates[ $index ] );
									$all_backup_dates = array_values( $all_backup_dates );
								}
							}

							// Handle paused backups older than 12 hours
							if ( $backup_progress_json['scan_state'] === 'pause' && isset( $backup_json['zipId'] ) ) {
								$time_elapsed = time() - $backup_json['zipId'];
								if ( $time_elapsed >= 43200 ) { // 12 hours
									$this->wptw_delete_backup_folders( $backup_json );
									$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
									$this->wptw_delete_backup_entries( $delete_entries );

									$index = array_search( $folder_date, $all_backup_dates );
									if ( $index !== false ) {
										unset( $all_backup_dates[ $index ] );
										$all_backup_dates = array_values( $all_backup_dates );
									}
								} else {
									$backup_logs[] = 'create_backup_' . $backup_json['zipId'] . '.json';
								}
							}

							// Handle in-progress backups older than 24 hours
							if ( $backup_progress_json['scan_state'] === 'in-progress' && isset( $backup_json['zipId'] ) ) {
								$time_elapsed = time() - $backup_json['zipId'];
								if ( $time_elapsed >= 86400 ) { // 12 hours
									$this->wptw_delete_backup_folders( $backup_json );
									$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
									$this->wptw_delete_backup_entries( $delete_entries );

									$index = array_search( $folder_date, $all_backup_dates );
									if ( $index !== false ) {
										unset( $all_backup_dates[ $index ] );
										$all_backup_dates = array_values( $all_backup_dates );
									}
								} else {
									$backup_logs[] = 'create_backup_' . $backup_json['zipId'] . '.json';
								}
							}

							// Handle failed backups older than 24 hours. The failed run stays
							// visible in history until then so the user can see it; after the
							// TTL its partial files + records are auto-cleaned.
							if ( $backup_progress_json['scan_state'] === 'failed' && isset( $backup_json['zipId'] ) ) {
								$time_elapsed = time() - $backup_json['zipId'];
								if ( $time_elapsed >= 86400 ) { // 24 hours
									$this->wptw_delete_backup_folders( $backup_json );
									$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
									$this->wptw_delete_backup_entries( $delete_entries );

									$index = array_search( $folder_date, $all_backup_dates );
									if ( $index !== false ) {
										unset( $all_backup_dates[ $index ] );
										$all_backup_dates = array_values( $all_backup_dates );
									}
								} else {
									$backup_logs[] = 'create_backup_' . $backup_json['zipId'] . '.json';
								}
							}

							if ( $backup_progress_json['scan_state'] === 'completed' && isset( $backup_json['slug_requested_at'] ) ) {
								$time_elapsed = time() - $backup_json['slug_requested_at'];
								if ( $time_elapsed >= 86400 ) { // 24 hours
									$export_dir  = WPTW_BACKUP_DIR . '/migrator/export/';
									$folder_name = $backup_json['folderDate'];
									$zip_files   = array(
										$export_dir . 'wptw_files_backup_' . $folder_name . '.zip',
										$export_dir . 'wptw_database_backup_' . $folder_name . '.zip',
									);

									foreach ( $zip_files as $zip_file ) {
										if ( file_exists( $zip_file ) ) {
											wp_delete_file( $zip_file );
										}
									}
								}
							}
						}
					}

					if ( ! $has_progress_entry ) {
						$this->wptw_delete_backup_folders( $backup_json );
						$this->wptw_delete_backup_entries( array( $backup_data['id'] ) );

						$index = array_search( $folder_date, $all_backup_dates );
						if ( $index !== false ) {
							unset( $all_backup_dates[ $index ] );
							$all_backup_dates = array_values( $all_backup_dates );
						}
					}
				}
			}

			foreach ( $all_backups_progress as $backup_progress ) {
				$backup_progress_json = json_decode( $backup_progress['value'], true );
				$folderDate           = $backup_progress_json['folderDate'];

				if ( ! in_array( $folderDate, $all_backup_dates ) ) {
					$this->wptw_delete_backup_entries( array( $backup_progress['id'] ) );
				}
			}

			$directories = array(
				'files'    => $this->backup_directory,
				'database' => $this->db_directory,
			);

			foreach ( $directories as $type => $directoryPath ) {
				if ( ! is_dir( $directoryPath ) ) {
					continue;
				}

				foreach ( new \DirectoryIterator( $directoryPath ) as $folder ) {
					if ( $folder->isDir() && ! $folder->isDot() ) {
						$folderName = $folder->getFilename();
						if ( ! in_array( $folderName, $all_backup_dates ) ) {
							$this->delete_folder_and_files( $directoryPath . $folderName );
						}
					}
				}
			}

			foreach ( new \DirectoryIterator( $this->log_directory ) as $folder ) {
				if ( ! $folder->isDot() ) {
					$folderName = $folder->getFilename();
					if ( ! in_array( $folderName, $backup_logs ) ) {
						$this->delete_backup_json_file( $this->log_directory . '/' . $folderName );
					}
				}
			}
		}
	}

	public function delete_folder_and_files( $folder ) {
		if ( ! file_exists( $folder ) ) {
			return false;
		}

		$wp_filesystem = FilesystemService::get_filesystem();
		if ( ! $wp_filesystem ) {
			return false;
		}

		return $wp_filesystem->delete( $folder, true );
	}

	public function wptw_delete_backup_entry( $ids ) {
		$backup_model = new BackupModel();
		$ids          = is_array( $ids ) ? $ids : array( $ids );
		foreach ( $ids as $id ) {
			$backup_model->delete_backup_by_id( (int) $id );
		}
		return true;
	}

	public function wptw_get_backup_folders_info( $post_data ) {
		try {

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new \Exception( 'Invalid JSON data provided' );
			}

			$limit  = isset( $data['limit'] ) && is_numeric( $data['limit'] ) && $data['limit'] > 0 ? (int) $data['limit'] : 10;
			$page   = isset( $data['page'] ) && is_numeric( $data['page'] ) && $data['page'] > 0 ? (int) $data['page'] : 1;
			$offset = ( $page - 1 ) * $limit;

			$directories = array(
				'files'    => $this->backup_directory,
				'database' => $this->db_directory,
			);

			// Consumers (e.g. pro) can exclude internal-flow backup rows from
			// this user-facing list. Same list goes to both queries so
			// pagination stays accurate.
			$exclude_process_runs = apply_filters( 'wptw_backup_list_exclude_process_runs', array() );
			if ( ! is_array( $exclude_process_runs ) ) {
				$exclude_process_runs = array();
			}

			$backup_model  = new BackupModel();
			$dbFoldersData = $backup_model->get_all_backup_folders( 'default_backup_scan', 'scan_backp', $limit, $offset, $exclude_process_runs );
			$total         = $backup_model->get_all_backup_folders_count( 'default_backup_scan', 'scan_backp', $exclude_process_runs );

			if ( ! $dbFoldersData ) {
				Log::error(
					'Database query returned no backup folders',
					array(
						'feature' => 'backup',
						'action'  => 'backup_folders_info_failed',
					)
				);
				return array(
					'data'       => array(),
					'message'    => __( 'No backup folders found in the database.', 'tailwatch' ),
					'code'       => 404,
					'pagination' => null,
				);
			}

			$backup_ids       = array();
			$download_backups = apply_filters( 'wptw_get_backup_downloading_status', array(), true );
			if ( ! empty( $download_backups ) && is_array( $download_backups ) ) {
				$download_status = $download_backups['in_progress'] ?? false;

				if ( $download_status ) {
					$backup_ids = isset( $download_backups['backup_ids'] ) ? $download_backups['backup_ids'] : array();
				}
			}

			$dbFolders = array();
			foreach ( $dbFoldersData as $dbFolder ) {
				if (
					isset( $dbFolder['folderDate'], $dbFolder['scan_state'] )
					&& in_array( $dbFolder['scan_state'], array( 'completed', 'in-progress', 'pause', 'failed' ) )
				) {
					$dbFolders[ $dbFolder['folderDate'] ] = array(
						'scan_state'  => $dbFolder['scan_state'],
						'backupType'  => $dbFolder['backupType'],
						'id'          => $dbFolder['id'],
						'process_run' => $dbFolder['process_run'],
					);
				}
			}

			$folders            = array();
			$processedBackupIds = array();

			foreach ( $directories as $type => $directoryPath ) {
				if ( ! is_dir( $directoryPath ) ) {
					Log::error(
						'Directory does not exist: ' . $directoryPath,
						array(
							'feature' => 'backup',
							'action'  => 'backup_folders_info_failed',
						)
					);
					continue;
				}

				foreach ( new \DirectoryIterator( $directoryPath ) as $folder ) {
					if ( $folder->isDir() && ! $folder->isDot() ) {
						$folderName = $folder->getFilename();
						if ( isset( $dbFolders[ $folderName ] ) ) {
							$backupId      = $dbFolders[ $folderName ]['id'];
							$isDownloading = in_array( $backupId, $backup_ids );

							try {
								// Skip file existence check if backup is currently being downloaded
								if ( ! $isDownloading ) {
									// Verify that the folder contains actual backup files
									$folderPath = $directoryPath . '/' . $folderName;
									$hasFiles   = false;

									try {
										$fileIterator = new \RecursiveIteratorIterator(
											new \RecursiveDirectoryIterator( $folderPath, \FilesystemIterator::SKIP_DOTS ),
											\RecursiveIteratorIterator::SELF_FIRST
										);

										foreach ( $fileIterator as $file ) {
											if ( $file->isFile() ) {
												$hasFiles = true;
												break;
											}
										}
									} catch ( \Throwable $e ) {
										Log::error(
											'Failed to read folder: ' . $folderPath . ' - ' . $e->getMessage(),
											array(
												'feature' => 'backup',
												'action'  => 'backup_folders_verification_failed',
												'exception' => $e,
											)
										);
										continue;
									}

									// Skip if no files found
									if ( ! $hasFiles ) {
										Log::error(
											'No backup files found in folder: ' . $folderPath,
											array(
												'feature' => 'backup',
												'action'  => 'backup_folders_empty',
											)
										);
										continue;
									}
								}

								$folderSize    = $this->get_folder_size( $directoryPath . '/' . $folderName );
								$existingIndex = array_search( $backupId, array_column( $folders, 'id' ) );

								if ( $existingIndex !== false ) {
									$folders[ $existingIndex ]['folder_size'] += $folderSize;
								} else {
									$folderData = array(
										'id'            => $backupId,
										'backupType'    => $dbFolders[ $folderName ]['backupType'],
										'process_run'   => $dbFolders[ $folderName ]['process_run'],
										'folder_name'   => $folderName,
										'folder_size'   => $folderSize,
										'creation_time' => gmdate( 'Y-m-d H:i:s', $folder->getCTime() ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate
										'status'        => $dbFolders[ $folderName ]['scan_state'],
									);

									// Only add is_downloading if backup is actually downloading
									if ( $isDownloading ) {
										$folderData['is_downloading'] = true;
									}

									$folders[]            = $folderData;
									$processedBackupIds[] = $backupId;
								}
							} catch ( \Throwable $e ) {
								Log::error(
									'Failed to calculate folder size: ' . $e->getMessage(),
									array(
										'feature'   => 'backup',
										'action'    => 'backup_folders_info_failed',
										'exception' => $e,
									)
								);
							}
						}
					}
				}
			}

			// Add downloading backups that might not have folders yet
			foreach ( $backup_ids as $downloadingBackupId ) {
				if ( ! in_array( $downloadingBackupId, $processedBackupIds ) ) {
					// Find this backup in dbFoldersData
					foreach ( $dbFoldersData as $dbFolder ) {
						if ( isset( $dbFolder['id'] ) && (int) $dbFolder['id'] === (int) $downloadingBackupId ) {
							$folders[] = array(
								'id'             => $dbFolder['id'],
								'backupType'     => isset( $dbFolder['backupType'] ) ? $dbFolder['backupType'] : 'unknown',
								'process_run'    => isset( $dbFolder['process_run'] ) ? $dbFolder['process_run'] : 'unknown',
								'folder_name'    => isset( $dbFolder['folderDate'] ) ? $dbFolder['folderDate'] : 'Unknown',
								'folder_size'    => 0,
								'creation_time'  => isset( $dbFolder['created_at'] ) ? $dbFolder['created_at'] : current_time( 'mysql' ),
								'status'         => isset( $dbFolder['scan_state'] ) ? $dbFolder['scan_state'] : 'in-progress',
								'is_downloading' => true,
							);
							break;
						}
					}
				}
			}

			if ( empty( $folders ) ) {
				Log::error(
					'No backup folders found in the backup directory',
					array(
						'feature' => 'backup',
						'action'  => 'backup_folders_info_failed',
					)
				);
				return array(
					'data'       => array(),
					'message'    => __( 'No matching backup folders found.', 'tailwatch' ),
					'code'       => 404,
					'pagination' => null,
				);
			}

			usort( $folders, fn( $a, $b ) => strtotime( $b['creation_time'] ) - strtotime( $a['creation_time'] ) );

			foreach ( $folders as &$folder ) {
				$folder['folder_size'] = $this->format_size_units( $folder['folder_size'] );
			}

			$total_pages = ceil( $total / $limit );


			return array(
				'data'             => $folders,
				'message'          => __( 'Successfully retrieved all matching folders.', 'tailwatch' ),
				'code'             => 200,
				'pagination'       => array(
					'total'       => $total,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => $total_pages,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_folders_info_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while retrieving backup folders.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function get_folder_size( $folder_path ) {
		$size = 0;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $folder_path ) ) as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}
		return $size;
	}

	public function format_size_units( $bytes ) {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		} elseif ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 2 ) . ' KB';
		} elseif ( $bytes > 1 ) {
			return $bytes . ' bytes';
		} elseif ( $bytes === 1 ) {
			return '1 byte';
		} else {
			return '0 bytes';
		}
	}

	/**
	 * Returns verified list of files for a backup folder (files + DB that exist on disk).
	 *
	 * @param string $post_data JSON with folder_name and id.
	 * @return array Response with data (file list) or error.
	 */
	public function wptw_get_backup_folder_files( $post_data ) {
		$json_data   = isset( $post_data ) ? wp_unslash( $post_data ) : '';
		$data        = json_decode( $json_data, true );
		$folder_name = isset( $data['folder_name'] ) ? sanitize_text_field( $data['folder_name'] ) : '';
		$backup_id   = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

		if ( empty( $folder_name ) || 0 === $backup_id ) {
			return array(
				'data'    => array(),
				'message' => __( 'Folder name and ID are required.', 'tailwatch' ),
				'code'    => 400,
			);
		}

		// Folder names are minted from current_time('Y-m-d_H-i-s'); reject anything outside [A-Za-z0-9_.-]
		// so a forged folder_name can't traverse out of the backup dir into DirectoryIterator.
		$folder_name = basename( $folder_name );
		if ( '' === $folder_name || ! preg_match( '/^[A-Za-z0-9_.-]+$/', $folder_name ) ) {
			return array(
				'data'    => array(),
				'message' => __( 'Invalid folder name.', 'tailwatch' ),
				'code'    => 400,
			);
		}

		$directories = array(
			'files'    => $this->backup_directory . $folder_name . '/',
			'database' => $this->db_directory . $folder_name . '/',
		);

		$valid_directories = array_filter( $directories, fn( $path ) => file_exists( $path ) );
		if ( empty( $valid_directories ) ) {
			return array(
				'data'    => array(),
				'message' => __( 'Backup folder does not exist.', 'tailwatch' ),
				'code'    => 404,
			);
		}

		$feature_controller = new DBModel();
		$value              = $feature_controller->get_feature_value( $backup_id );
		if ( empty( $value ) ) {
			return array(
				'data'    => array(),
				'message' => __( 'No backup data found for the specified folder.', 'tailwatch' ),
				'code'    => 404,
			);
		}

		$db_results = json_decode( $value, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'data'    => array(),
				'message' => __( 'Error decoding JSON data.', 'tailwatch' ),
				'code'    => 500,
			);
		}

		$db_files = array();
		if ( isset( $db_results['tables']['db_tables'] ) && is_array( $db_results['tables']['db_tables'] ) ) {
			foreach ( $db_results['tables']['db_tables'] as $table_name => $table_data ) {
				if ( isset( $table_data['batch'] ) && is_array( $table_data['batch'] ) ) {
					foreach ( $table_data['batch'] as $batch_part => $batch_data ) {
						if ( isset( $batch_data['path'] ) ) {
							$db_files[] = basename( $batch_data['path'] );
						}
					}
				}
			}
		}

		foreach ( $db_results as $section => $details ) {
			if ( isset( $details['batch'] ) ) {
				foreach ( $details['batch'] as $zip_key => $zip_details ) {
					if ( isset( $zip_details['path'] ) ) {
						$db_files[] = basename( $zip_details['path'] );
					}
				}
			}
		}

		$verified_files = array();
		foreach ( $valid_directories as $type => $directory_path ) {
			foreach ( new \DirectoryIterator( $directory_path ) as $file ) {
				if ( $file->isFile() ) {
					$file_name = $file->getFilename();

					if ( in_array( $file_name, $db_files, true ) ) {
						$file_size = $file->getSize();
						$file_url  = WPTW_BACKUP_URL . '/' . $type . '/' . $folder_name . '/' . $file_name;

						$verified_files[] = array(
							'file_name'         => $file_name,
							'file_path'         => $directory_path . $file_name,
							'file_size'         => $this->format_size_units( $file_size ),
							'modification_time' => gmdate( 'Y-m-d H:i:s', $file->getMTime() ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate
							'download_slug'     => $file_url,
							'type'              => $type,
						);
					}
				}
			}
		}

		if ( empty( $verified_files ) ) {
			return array(
				'data'    => array(),
				'message' => __( 'No matching files found in the backup folders.', 'tailwatch' ),
				'code'    => 404,
			);
		}

		return array(
			'data'    => $verified_files,
			'message' => __( 'Successfully retrieved verified folder files.', 'tailwatch' ),
			'code'    => 200,
		);
	}
}
