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
use Tailwatch\Admin\App\Api\Services\Cron\CronKick;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Services\ZipUtility\ZipCreation;
use Tailwatch\Admin\App\Api\Services\ZipUtility\ArchiveReader;
use Tailwatch\Admin\App\Api\Services\Backup\BackupManifest;
use Tailwatch\Admin\App\Api\Services\Backup\BackupReconciler;
use Tailwatch\Admin\App\Api\Services\Backup\RetentionHold;
use Tailwatch\Admin\App\Api\Traits\BackupStateTrait;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\BackupModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Disk\DiskSpaceController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\TickBudget;
use Tailwatch\Admin\App\Api\Services\ProcessStatusService;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\ProcessStartClaim;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;

/**
 * Class BackupController
 *
 * Main backup feature controller. Schedules crons, runs backup process, manages ZIP creation.
 *
 */
class BackupController extends BaseController {

	use BackupStateTrait;

	/**
	 * Feature keys exempt from feature check for backup flows.
	 *
	 * @var array
	 */
	protected $tailwatch_feature_check_exemptions = array();

	/**
	 * Returns backup feature status (wrapper for get_backup_feature_data).
	 *
	 * @return array Backup feature data.
	 */
	protected function tailwatch_get_feature_status() {
		return $this->get_backup_feature_data();
	}

	/** @since 1.0.0 @var string */
	private $log_directory = TAILWATCH_LOGS_DIRECTORY . '/backup-logs';
	/** @since 1.0.0 @var string */
	private $get_live_logs = TAILWATCH_LOGS_DIRECTORY . '/backup-logs/create_backup';
	/** @since 1.0.0 @var string */
	private $backup_directory = TAILWATCH_BACKUP_DIR . '/files/';
	/** @since 1.0.0 @var string */
	private $db_directory = TAILWATCH_BACKUP_DIR . '/database/';
	/** @since 1.0.0 @var string */
	private $get_migration_progress = TAILWATCH_BACKUP_DIR . '/migrator/import/migration_data.json';
	/** @since 1.0.0 @var \Tailwatch\Admin\App\Api\Services\ProcessManager */
	private $process_manager;
	/** @since 1.0.0 @var int|null */
	private $current_process_id;
	/**
	 * Value this request wrote into the worker lock, while it holds it.
	 *
	 * @var int|null
	 */
	private $worker_lock_stamp;


	/**
	 * Seconds reserved at the end of a tick for closing the part being written.
	 *
	 * The packer stops adding files at (tick deadline - this), because closing a zip is
	 * itself real work — compressing and writing the central directory — and being killed
	 * during close leaves a corrupt part rather than a short one.
	 */
	const PART_CLOSE_RESERVE = 5;

	/**
	 * Part sizes an owner can choose, in MB, and the one used when nothing is chosen.
	 *
	 * Every part has to be carried by hand for a manual migration, and a browser drops
	 * downloads without telling anyone, so fewer, larger parts are better. The streaming
	 * writer makes that possible: a part is written as it is read, pauses on the tick
	 * deadline and continues in the next one, so it reaches its full size whatever the
	 * host's speed. 500MB is chosen rather than 1GB because the file count barely differs
	 * on a normal site - the seven area archives dominate - while the cost of a failed
	 * transfer halves, browser-side checking stays quick, and 500MB clears every practical
	 * FTP, filesystem and cloud-storage limit with room to spare.
	 *
	 * The smaller choices stay valid, so a site that saved 50 or 100 keeps exactly the
	 * behaviour it chose.
	 */
	const PART_SIZE_CHOICES_MB = array( 50, 100, 200, 400, 500 );
	const PART_SIZE_DEFAULT_MB = 500;

	/**
	 * Entries allowed in one part, whatever its size.
	 *
	 * ZIP records the entry count in a 16-bit field, so an archive past 65,535 entries
	 * needs Zip64 - which ZipArchive reads, but older unzip tools, some FTP stacks and some
	 * filesystems do not. Capping well below that means every part we write opens anywhere.
	 *
	 * It also bounds restore memory: opening an archive reads its central directory, which
	 * costs roughly 150-250 bytes per entry. 50,000 is about 10MB; a whole uploads folder in
	 * one archive would be nearer 40MB, on hosts that have the least to spare.
	 */
	const PART_ENTRY_LIMIT = 50000;

	/**
	 * The size scan backups always use, and the fallback for a run whose state records no
	 * part size. The pro scanner uploads scan parts, so their size is not the owner's to raise.
	 */
	const FIXED_PART_SIZE = 52428800;

	/**
	 * Smallest size the automatic step-down goes to before a stalled folder is given up on.
	 */
	const PART_SIZE_FLOOR = 26214400;

	/**
	 * A file over this is archived on its own, whatever the part size.
	 *
	 * Tied to the part size, a 300 MB video would share a 400 MB part, and a host too slow to
	 * close that part would step the size down to the floor and fail the whole backup. Archived
	 * alone it gets its own attempts, and a file that still cannot be written is the only
	 * thing the backup loses.
	 */
	const LARGE_FILE_SIZE = 52428800;

	/**
	 * Disk space a backup never takes: left free for the site itself.
	 *
	 * A backup that fills the disk takes the site down with it — the database cannot write,
	 * uploads and sessions fail — so it stops before it reaches this line rather than at zero.
	 */
	const DISK_RESERVE = 268435456;

	/**
	 * Option holding the most recently cancelled run until another backup starts.
	 */
	const LAST_CANCEL_OPTION = 'tailwatch_backup_last_cancel';

	/**
	 * Fraction of the host's execution limit a single tick is allowed to consume.
	 *
	 * Leaves headroom for closing the archive, flushing state, and scheduling the next
	 * tick — all of which happen after the budget is spent.
	 */
	const TICK_BUDGET_FRACTION = 0.6;

	/**
	 * Floor for a tick budget, so a hostile limit still makes forward progress.
	 */
	const TICK_BUDGET_MIN = 5;

	/**
	 * Seconds before a re-armed tick fires after a fatal.
	 */
	const REARM_DELAY = 3;

	/**
	 * Seconds the single-worker lock lasts unless its worker renews it.
	 */
	const WORKER_LOCK_TTL = 600;

	/**
	 * Seconds before a tick that found another worker mid-tick is tried again.
	 */
	const WORKER_LOCK_RETRY = 10;

	/**
	 * Seconds between check-ins while a folder's file list is built.
	 *
	 * Listing a large folder on a cold disk can take many minutes and cannot be split across
	 * ticks. Without a heartbeat in that time the process monitor reads the run as stuck, and
	 * without a renewed lock a second worker can start on the same folder.
	 */
	const LISTING_BEAT_INTERVAL = 20;

	/**
	 * Seconds between "still listing" lines in the live log.
	 */
	const LISTING_LOG_INTERVAL = 60;

	/**
	 * What the folder step returns when its file list stopped because the run was stopped or is gone.
	 */
	const LISTING_STOPPED = 'listing_stopped';

	/**
	 * Guards against arming more than one fatal re-arm per request.
	 *
	 * @var array<string,bool>
	 */
	private static $rearm_registered = array();

	/**
	 * Re-arm a backup cron hook when the request is dying on an uncatchable fatal.
	 *
	 * An OOM or max_execution_time kill is an E_ERROR, so no try/catch can reach it and
	 * the tick never gets to schedule its successor. Without this, recovery waits for the
	 * 3-minute process-recovery watchdog, which is a long stall on a job that ticks every
	 * few seconds. Re-arming here restarts within REARM_DELAY instead.
	 *
	 * Deliberately narrow:
	 * - only on a genuine fatal, so a normal tick end is untouched (it schedules its own
	 *   successor already, and double-scheduling would run two workers)
	 * - never when a successor is already scheduled
	 * - never for a backup that is finished or deliberately stopped
	 *
	 * Everything is wrapped: this runs after a fatal, where a further failure would be
	 * silent and confusing, and the failure caps (pack_fail_attempts, stuck_ticks) still
	 * bound how many times a genuinely broken backup can come back.
	 *
	 * @param string        $hook    Cron hook to re-arm.
	 * @param callable|null $is_live Answers "is this job still meant to be running".
	 *                               Defaults to the backup's own state row.
	 * @return bool True when a tick was scheduled.
	 */
	public static function tailwatch_rearm_on_fatal( $hook, $is_live = null ) {
		$error = error_get_last();
		if ( null === $error || ! isset( $error['type'] ) ) {
			return false;
		}
		if ( ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return false;
		}

		try {
			// A caller that is not the backup passes its own liveness test. Deciding every
			// caller's successor by the BACKUP's state row would stop a restore from ever
			// re-arming (a restore cannot start while a backup runs, so the row never says
			// in-progress), and would let a stale backup row schedule a tick for an
			// unrelated job.
			if ( is_callable( $is_live ) ) {
				if ( ! call_user_func( $is_live ) ) {
					return false;
				}
			} else {
				// Only resume a backup that is still meant to be running. Read the write
				// buffer directly rather than through the instance getter: constructing a
				// BackupController here would re-register its action hooks and build a
				// BackupDbController, inside a shutdown handler, after a fatal.
				$state = null !== self::$pending_backup_data
					? self::$pending_backup_data[0]
					: ( new DBModel() )->get_recent_data( 'scan_backp', 'default_backup_scan' );

				if ( ! is_array( $state ) || empty( $state['scan_state'] ) ) {
					return false;
				}
				// The buffered state is the dying tick's own run; once another run has replaced
				// it, the hook is that run's to schedule.
				if ( null !== self::$pending_backup_data && ! empty( $state['folderDate'] ) ) {
					$newest = ( new DBModel() )->get_recent_data_by_id( 'backup_cancel_pause', 'default_backup_scan' );
					if ( ! is_array( $newest ) || (string) ( $newest['folderDate'] ?? '' ) !== (string) $state['folderDate'] ) {
						return false;
					}
					// The copy was read before a stop that may have come in since; the status row has it.
					if ( 'in-progress' !== ( $newest['scan_state'] ?? '' ) ) {
						return false;
					}
				}
				if ( 'in-progress' !== $state['scan_state'] || ! empty( $state['completed'] ) ) {
					return false;
				}
			}

			if ( wp_next_scheduled( $hook ) ) {
				return false;
			}

			wp_schedule_single_event( time() + self::REARM_DELAY, $hook );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Register a one-per-request shutdown hook that re-arms $hook after a fatal.
	 *
	 * @param string $hook Cron hook to re-arm.
	 * @return void
	 */
	public static function tailwatch_arm_fatal_rearm( $hook, $is_live = null ) {
		if ( isset( self::$rearm_registered[ $hook ] ) ) {
			return;
		}
		self::$rearm_registered[ $hook ] = true;

		register_shutdown_function(
			static function () use ( $hook, $is_live ) {
				self::tailwatch_rearm_on_fatal( $hook, $is_live );
			}
		);
	}

	/**
	 * Seconds of work to attempt in one tick, bounded by the host's real limit.
	 *
	 * A fixed deadline ignores what the host actually allows. On a box with
	 * max_execution_time=10 that ignores ini_set — common on cheap shared hosting — a tick
	 * planning for 25s is killed at 10s, the cursor never advances far, and the
	 * no-progress guard eventually aborts the backup.
	 *
	 * Call AFTER tailwatch_boost_for_scanning() so the raised limit is what gets read.
	 *
	 * Note this is deliberately conservative on Linux, where max_execution_time counts
	 * CPU time rather than wall time and file I/O therefore does not count against it;
	 * erring short costs an extra tick, erring long costs a killed process.
	 *
	 * @param int $preferred Budget to use when the host imposes no meaningful limit.
	 * @return int Seconds.
	 */
	public static function tailwatch_tick_budget( $preferred, $process_type = 'backup' ) {
		// ini_get() is only one of the three things that can end a tick, and on many hosts
		// it is not the binding one — see TickBudget for why. Take the smallest of the
		// caller's preference, what PHP admits to, and what this host has actually been
		// observed to allow.
		$budget = TickBudget::budget_for( $process_type, (int) $preferred );

		return (int) max( self::TICK_BUDGET_MIN, min( (int) $preferred, $budget ) );
	}


	public function __construct() {
		$this->process_manager = new ProcessManager();
		$this->register_process_monitoring();

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'tailwatch_backup_daily_scan', array( $this, 'tailwatch_run_backup_cron' ) );
		$hook_controller->add_action_hook( 'tailwatch_verify_backup_cron_hook', array( $this, 'tailwatch_verify_backup_cron_status' ) );
		$hook_controller->add_action_hook( 'tailwatch_delete_backup_files_entry', array( $this, 'tailwatch_delete_files_entry_with_cron' ) );
		$hook_controller->add_action_hook( 'tailwatch_recovery_process_failed', array( $this, 'tailwatch_on_recovery_failed' ) );

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
				'cron_hooks'          => array( 'tailwatch_backup_daily_scan', 'tailwatch_scan_db_tables_cron', 'tailwatch_create_db_backup_cron' ),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_backup_scan',
				'data_option'         => 'scan_backp',
				'cancel_pause_key'    => 'default_backup_scan',
				'cancel_pause_option' => 'backup_cancel_pause',
				'stuck_threshold'     => 300, // 5 minutes.
				'max_retries'         => 10,
				// Settings ProcessGuard freezes while this process runs. Backup may run db_optimize
				// as its optimize-before-backup phase (tailwatch_database_optimize_start); that phase's
				// own lock releases when it finishes, so we keep the optimizer feature locked for
				// the whole backup to prevent settings drift mid-backup.
				'locks_features'      => array( 'default_backup_enable', 'default_database_optimizer' ),
				// Process types that, when running, prevent a user from starting
				// a new backup. db_optimize is intentionally NOT listed because
				// the optimizer-adoption logic in tailwatch_calculate_folder_sizes
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
	public function tailwatch_get_log_file_path() {
		$backup_data = $this->tailwatch_get_scan_backup_data();
		$file_key    = isset( $backup_data['zipId'] ) ? $backup_data['zipId'] : '';
		return $this->get_live_logs . '_' . $file_key . '.json';
	}

	/**
	 * Verifies backup cron status and reschedules daily scan if no backup cron is scheduled.
	 *
	 */
	public function tailwatch_verify_backup_cron_status() {
		$next_daily     = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
		$next_recurring = wp_next_scheduled( 'tailwatch_backup_schedule_run' );
		if ( ! $next_daily && ! $next_recurring ) {
			wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
			CronKick::accelerate();
		}
	}

	/**
	 * Executes backup cron if it failed or was not running (e.g. after recovery).
	 *
	 * @return array|null Response array on early return, null otherwise.
	 */
	public function tailwatch_execute_cron_if_failed() {
		try {
			$get_backup_data = $this->tailwatch_get_scan_backup_data();
			$cancel_pause    = $this->tailwatch_backup_cancel_pause_data();

			if ( empty( $get_backup_data ) || ! is_array( $get_backup_data ) ) {
				return null;
			}

			// No status row means no run of this site's own to revive. The newest catalogue row can
			// be a migrated backup, and the database step scheduled below would write into it.
			if ( ! is_array( $cancel_pause ) ) {
				return array(
					'code'    => 409,
					'data'    => array(),
					'message' => __( 'There is no run to restart.', 'tailwatch' ),
				);
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

			// cron_running is set true at the start of a tick and cleared on the way out, so
			// only a tick that RETURNS clears it. The failures this recovery exists for — an
			// out-of-memory kill, the OOM killer, a proxy cutting the request — are exactly
			// the ones that never reach the way out, and they leave the flag set to true
			// forever. Gating on it alone would leave the watchdog blind to its own use case.
			//
			// The heartbeat can't be falsified the same way: a dead worker stops writing it.
			// ProcessStatusService already reads it, treats a stuck row as not running, and
			// reaps an orphaned one — and it stays true while a slow-but-live tick is working,
			// which is what keeps this from scheduling a second worker alongside the first.
			// Every branch below is additionally guarded by wp_next_scheduled().
			$worker_alive = ( new ProcessStatusService() )->is_running( 'backup' );

			if ( false === ( $cancel_pause['cron_running'] ?? null ) || ! $worker_alive ) {
				$get_backup_type = $get_backup_data['backupType'];
				$cron_scheduled  = false;

				$premium_handled = apply_filters( 'tailwatch_handle_premium_backup_cron', false, $get_backup_type, $get_backup_data );
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

				$valid_backup_types = apply_filters( 'tailwatch_valid_backup_types', array( 'Complete Backup' ), $get_backup_type );

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
						return $db_optimizer->tailwatch_db_optimization_cron_if_failed();
					}
				}

				switch ( $get_backup_type ) {
					case 'Complete Backup':
						if ( ! isset( $get_backup_data['tables'] ) ) {
							$database_backup = wp_next_scheduled( 'tailwatch_scan_db_tables_cron' );
							if ( ! $database_backup ) {
								$cron_scheduled = wp_schedule_single_event( time(), 'tailwatch_scan_db_tables_cron' );
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
							$database_backup = wp_next_scheduled( 'tailwatch_create_db_backup_cron' );
							if ( ! $database_backup ) {
								$cron_scheduled = wp_schedule_single_event( time(), 'tailwatch_create_db_backup_cron' );
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
						$premium_result = apply_filters( 'tailwatch_backup_unknown_type', null, $get_backup_type, $get_backup_data );
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
		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Returns backup feature enable/disable and parent feature state.
	 *
	 * @return array Feature data with feature_enable, parent_enable.
	 */
	public function get_backup_feature_data() {
		$get_backup_option = new BackupMaintainController();
		$backup_data       = $get_backup_option->tailwatch_get_backup_settings();

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
	 * Calculates folder sizes and starts the backup process (creates initial scan data and schedules cron).
	 *
	 * @param string|null $user_name          Current user login.
	 * @param string|null $user_role          Current user role(s).
	 * @param string|null $scan_type          Scan type (e.g. automatically, on-demand).
	 * @param string      $process_run        Process run context (e.g. backup, files_integrity).
	 * @param bool        $database_optimize   Whether to run DB optimization before backup.
	 * @param string|null $backup_type       Backup type (e.g. Complete Backup).
	 */
	public function tailwatch_calculate_folder_sizes( $user_name = null, $user_role = null, $scan_type = null, $process_run = 'backup', $database_optimize = false, $backup_type = null ) {
		try {
			$db_model = new DBModel();

			$get_backup_option = new BackupMaintainController();
			$backup_data       = $get_backup_option->tailwatch_get_backup_settings();

			if ( ! $backup_data ) {
				throw new \Exception( 'Failed to retrieve backup settings' );
			}

			if ( null !== $backup_type ) {
				$get_backup_type = $backup_type;
			} else {
				$get_backup_type = ! empty( $backup_data['backupType'] ) ? $backup_data['backupType'] : null;
			}

			// Allow premium plugin to validate premium backup types.
			$valid_backup_types = apply_filters( 'tailwatch_valid_backup_types', array( 'Complete Backup' ), $get_backup_type );
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

			// Checked before the unfinished backup below is discarded, so a start refused for
			// space does not also cost the owner a paused backup they could have resumed. The
			// space that backup occupies is counted as available, because starting frees it.
			$part_size = self::tailwatch_part_size_for_run( $process_run, $backup_data['partSizeMb'] ?? null );
			$site_size = $this->tailwatch_estimation_site_size( $get_backup_type );
			$free      = self::tailwatch_backup_free_space();
			$disk      = self::tailwatch_disk_verdict(
				false === $free ? false : $free + $this->tailwatch_incomplete_backup_bytes(),
				$site_size,
				$part_size
			);
			if ( 'refuse' === $disk['verdict'] ) {
				return $this->tailwatch_refuse_start_for_disk( $disk, $get_backup_type );
			}

			// Asked again under the claim: the estimate above can take seconds, and a backup,
			// restore or migration started meanwhile by another request has its process running
			// by now. A scan that backs the site up first is the run this backup belongs to.
			$start_lock = ( new ProcessGuard() )->claim_start( 'backup', self::tailwatch_runs_inside_of( $process_run ) );
			if ( is_array( $start_lock ) ) {
				return $start_lock;
			}

			$this->tailwatch_delete_files_entry_with_cron();

			$sizes = array(
				'completed'          => false,
				'userData'           => array(
					'userName' => ! empty( $user_name ) ? $user_name : 'Auto Run',
					'userRole' => ! empty( $user_role ) ? $user_role : 'Auto Run',
					'scanType' => ! empty( $scan_type ) ? $scan_type : 'Automatically',
				),
				'zipId'              => time(),
				'folderDate'         => $this->generate_backup_folder_name(),
				'scan_state'         => 'in-progress',
				'site_url'           => site_url(),
				'started_time'       => time(),
				'backupType'         => $get_backup_type,
				'process_run'        => $process_run,
				'database_optimize'  => $database_optimize,
				'optimize_completed' => false,
				// Fixed for the whole run, so changing the setting mid-backup cannot mix sizes.
				'part_size'          => $part_size,
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
				'site_size'    => $site_size,
			);

			$process_id               = $this->process_manager->get_or_create_process(
				'backup',
				'tailwatch_backup_daily_scan',
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

			ProcessStartClaim::release( $start_lock );

			$db_optimizer = new DatabaseOptimizerController();

			if ( $result ) {
				delete_option( self::LAST_CANCEL_OPTION );
				$message   = 'Backup process started.';
				$live_logs = new LiveLogsController();
				$live_logs->insert_live_logs_records( $message, $this->log_directory, $this->tailwatch_get_log_file_path() );
				$this->tailwatch_log_disk_verdict( $disk );

				try {
					// Allow premium plugin to handle premium backup execution.
					$premium_executed = apply_filters( 'tailwatch_premium_backup_execution', false, $get_backup_type, $backup_data, $database_optimize );
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
								// DatabaseOptimizerController::tailwatch_global_db_optimize_with_monitoring
								// will flip this backup's optimize_completed flag and
								// schedule the DB-scan cron when it finishes.
								$status_service = new ProcessStatusService();

								if ( $status_service->is_running( 'db_optimize' ) ) {
									$db_optimizer->tailwatch_mark_optimizer_for_backup_completion();
									$this->update_logs_records( 'Adopting in-progress database optimization for this backup' );
								} else {
									$db_optimizer->tailwatch_database_optimize_start( 'on-demand', 'db_backup' );
								}
							} else {
								$database_backup = wp_next_scheduled( 'tailwatch_scan_db_tables_cron' );
								if ( ! $database_backup ) {
									wp_schedule_single_event( time(), 'tailwatch_scan_db_tables_cron' );
								}
							}
							break;

						default:
							// Allow premium plugin to handle unknown backup types.
							$unknown_handled = apply_filters( 'tailwatch_backup_unknown_type', null, $get_backup_type, $backup_data );
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
			ProcessStartClaim::release( isset( $start_lock ) ? $start_lock : '' );
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
	 * Estimates total site size for backup type (files + DB minus tailwatch-backup folder).
	 *
	 * @param string $backup_type Backup type (e.g. Complete Backup).
	 * @return int|float|null Total size in bytes, or null when the files could not all be
	 *                        measured in time.
	 */
	public function tailwatch_estimation_site_size( $backup_type ) {
		$disk_space_controller = new DiskSpaceController();
		$total_size            = 0;

		// A type holds the files, the database or both. One nothing describes is estimated
		// as the whole site, the larger and so the safer guess.
		$parts = self::tailwatch_backup_type_parts( $backup_type );
		if ( null === $parts || $parts['files'] ) {
			$file_size = $disk_space_controller->calculate_directory_sizes();

			// A walk that ran out of time leaves most of the site uncounted. Passed on as the
			// size, that figure would skip the disk warning and hold the time-left estimate
			// far too low for the whole run.
			if ( ! empty( $file_size['partial'] ) ) {
				return null;
			}

			$total_size += max( 0, (int) $file_size['files_size'] - (int) DiskSpaceController::calculate_folder_size( TAILWATCH_BACKUP_DIR ) );
		}
		if ( null === $parts || $parts['database'] ) {
			$db_size     = $disk_space_controller->get_database_info();
			$total_size += (int) $db_size['db_size'];
		}

		return $total_size;
	}

	/**
	 * Free space on the disk that holds the backups, in bytes, or false when it cannot be read.
	 *
	 * @return int|false
	 */
	public static function tailwatch_backup_free_space() {
		// disk_free_space() needs a directory that exists; before the first backup the backup
		// root may not, so measure the nearest ancestor that does.
		$path = TAILWATCH_BACKUP_DIR;
		while ( '' !== $path && ! is_dir( $path ) && dirname( $path ) !== $path ) {
			$path = dirname( $path );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled or restricted on some hosts; a false return is handled below.
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $path ) : false;

		/**
		 * Filter the free space a backup measures, in bytes, or false when unknown.
		 *
		 * For hosts where the account quota is smaller than the disk's free space, which is
		 * the number disk_free_space() reports.
		 *
		 * @param int|false $free Bytes free, or false.
		 */
		$free = apply_filters( 'tailwatch_backup_free_disk_space', ( false === $free || null === $free ) ? false : (int) $free );

		return ( false === $free || null === $free ) ? false : (int) $free;
	}

	/**
	 * Whether a backup has room to start.
	 *
	 * Refused only when it could not write even one part without eating into the reserve —
	 * the estimate is the site before compression, and a refusal on it would stop backups
	 * that would have fitted. Below the estimate the backup starts with a warning, and the
	 * guard stops it before the reserve is reached if it really does not fit.
	 *
	 * With no estimate the refusal still stands, since it never used one, and the rest is
	 * 'unmeasured' rather than a comparison against a number nobody measured.
	 *
	 * @param int|false $free      Bytes free, or false when unknown.
	 * @param int|null  $estimate  The site's size before compression, in bytes, or null when
	 *                             the site could not be measured.
	 * @param int       $part_size The run's archive part size, in bytes.
	 * @return array{verdict:string,free:int|false,estimate:int|null,floor:int,wanted:int|null}
	 */
	public static function tailwatch_disk_verdict( $free, $estimate, $part_size ) {
		$floor  = self::DISK_RESERVE + (int) $part_size;
		$wanted = null === $estimate ? null : self::DISK_RESERVE + (int) $estimate + (int) $part_size;

		if ( false === $free ) {
			$verdict = 'unknown';
		} elseif ( (int) $free < $floor ) {
			$verdict = 'refuse';
		} elseif ( null === $wanted ) {
			$verdict = 'unmeasured';
		} elseif ( (int) $free < $wanted ) {
			$verdict = 'warn';
		} else {
			$verdict = 'ok';
		}

		return array(
			'verdict'  => $verdict,
			'free'     => false === $free ? false : (int) $free,
			'estimate' => null === $estimate ? null : (int) $estimate,
			'floor'    => $floor,
			'wanted'   => $wanted,
		);
	}

	/**
	 * Space held by an unfinished backup that starting a new one discards.
	 *
	 * The same condition tailwatch_delete_files_entry_with_cron() acts on.
	 *
	 * @return int Bytes.
	 */
	private function tailwatch_incomplete_backup_bytes() {
		$existing = $this->tailwatch_get_scan_backup_data();
		$progress = $this->tailwatch_backup_cancel_pause_data();

		if ( empty( $existing ) || ! is_array( $progress ) || ! isset( $progress['scan_state'] )
			|| ! in_array( $progress['scan_state'], array( 'in-progress', 'pause', 'cancel' ), true )
			|| false !== ( $existing['completed'] ?? null )
			|| ! isset( $progress['folderDate'], $existing['folderDate'] )
			|| $existing['folderDate'] !== $progress['folderDate'] ) {
			return 0;
		}

		$bytes = 0;
		foreach ( array( $this->backup_directory, $this->db_directory ) as $root ) {
			$folder = $root . $existing['folderDate'];
			if ( is_dir( $folder ) ) {
				$bytes += (int) DiskSpaceController::calculate_folder_size( $folder );
			}
		}

		return $bytes;
	}

	/**
	 * Refuse to start, and say why in every place the owner might be looking.
	 *
	 * @param array  $disk        The verdict.
	 * @param string $backup_type Backup type.
	 * @return array Response with code 507.
	 */
	private function tailwatch_refuse_start_for_disk( array $disk, $backup_type ) {
		$message = sprintf(
			/* translators: 1: free disk space, 2: space needed to start, 3: space kept free for the site. */
			__( 'Not enough free disk space to start the backup: %1$s free, and at least %2$s is needed — %3$s stays free for your site, plus room to write one archive part. Free up space or choose a smaller archive part size, then try again.', 'tailwatch' ),
			size_format( (int) $disk['free'], 1 ),
			size_format( (int) $disk['floor'], 1 ),
			size_format( self::DISK_RESERVE )
		);

		Log::error(
			$message,
			array(
				'feature'   => 'backup',
				'action'    => 'backup_process_failed',
				'title'     => 'Backup did not start',
				'meta_data' => array(
					'feature'     => 'Backup Vault',
					'event'       => 'Failed',
					'impact'      => __( 'Your previous backups are still safe.', 'tailwatch' ),
					'backup_type' => (string) $backup_type,
					'phase'       => 'start',
					'reason'      => 'disk_space',
				),
			)
		);

		return array(
			'code'    => 507,
			'message' => $message,
			'refused' => 'disk_space',
			'free'    => (int) $disk['free'],
			'needed'  => (int) $disk['floor'],
		);
	}

	/**
	 * Say in the backup's own log how much room it has.
	 *
	 * @param array $disk The verdict.
	 * @return void
	 */
	private function tailwatch_log_disk_verdict( array $disk ) {
		if ( 'unknown' === $disk['verdict'] ) {
			$this->update_logs_records( 'Free disk space could not be read on this server, so the backup cannot check whether it will fit', 'WARNING' );
			return;
		}

		if ( 'unmeasured' === $disk['verdict'] ) {
			$this->update_logs_records(
				sprintf(
					'Free disk space is %1$s. The site could not be measured in time, so the backup cannot check whether it will fit; if space runs low it stops, keeping %2$s free for your site',
					size_format( (int) $disk['free'], 1 ),
					size_format( self::DISK_RESERVE )
				),
				'WARNING'
			);
			return;
		}

		if ( 'warn' === $disk['verdict'] ) {
			$this->update_logs_records(
				sprintf(
					'Free disk space is %1$s, and this backup could need up to %2$s before compression. It may not fit; if space runs low it stops, keeping %3$s free for your site',
					size_format( (int) $disk['free'], 1 ),
					size_format( (int) $disk['estimate'], 1 ),
					size_format( self::DISK_RESERVE )
				),
				'WARNING'
			);
			return;
		}

		$this->update_logs_records( 'Free disk space: ' . size_format( (int) $disk['free'], 1 ) );
	}

	/**
	 * Stop the backup before its next write would eat into the space the site needs.
	 *
	 * @param int    $next_write Bytes the next write may take.
	 * @param string $what       What that write is, for the log.
	 * @return bool True to carry on; false when the backup was stopped.
	 */
	public function tailwatch_disk_guard( $next_write, $what ) {
		$free = self::tailwatch_backup_free_space();
		if ( false === $free || $free >= self::DISK_RESERVE + (int) $next_write ) {
			return true;
		}

		$this->update_logs_records(
			sprintf(
				'Stopped before writing %1$s: %2$s is free, it may need up to %3$s, and %4$s stays free so your site keeps running. Free up space or choose a smaller archive part size, then run the backup again',
				$what,
				size_format( $free, 1 ),
				size_format( (int) $next_write, 1 ),
				size_format( self::DISK_RESERVE )
			),
			'ERROR'
		);
		$this->tailwatch_mark_backup_failed( 'disk_space_low' );

		return false;
	}

	/**
	 * Updates live log records and optionally triggers malware/scan log hook.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (e.g. 'INFO', 'OK', 'RESULT'). Default 'INFO'.
	 */
	public function update_logs_records( $message, $level = 'INFO' ) {

		$live_logs = new LiveLogsController();
		$live_logs->update_live_logs_records( $message, $this->tailwatch_get_log_file_path(), $level );

		$existing_data = $this->tailwatch_get_scan_backup_data();
		if ( isset( $existing_data['process_run'] ) && in_array( $existing_data['process_run'], array( 'files_integrity', 'malware' ), true ) ) {
			// Allow pro plugin (MalwareScanner) to handle log records.
			do_action( 'tailwatch_backup_malware_scan_logs', $message, $level );
		}
	}

	/**
	 * Recursively gets directory size in bytes, optionally skipping paths.
	 *
	 * @param string $directory Directory path.
	 * @param array  $skip      Paths to skip.
	 * @return int Size in bytes.
	 */
	public function tailwatch_get_directory_size( $directory, $skip = array() ) {
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
	public function tailwatch_verify_required_folders() {
		// Use WP_CONTENT_DIR basename so a renamed content directory (e.g. /app) snapshots under the real folder name.
		$candidate_basename = wp_basename( WP_CONTENT_DIR );
		$content_basename   = is_string( $candidate_basename )
			&& '' !== $candidate_basename
			&& preg_match( '#^[A-Za-z0-9_-]+$#', $candidate_basename )
				? $candidate_basename
				: 'wp-content';

		$folders = array(
			'others'      => realpath( TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/' ),
			'wp-admin'    => TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/wp-admin',
			'wp-includes' => TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/wp-includes',
			'wp-content'  => TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/' . $content_basename,
			'plugins'     => TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/' . $content_basename . '/plugins',
			'themes'      => TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/' . $content_basename . '/themes',
			'uploads'     => TAILWATCH_BACKUP_DIR . '/tailwatch-scanner/' . $content_basename . '/uploads',
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
						if ( 'tailwatch-backup' === $item || 'plugins' === $item || 'themes' === $item || 'uploads' === $item ) {
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
	 * Where a folder's backup stands, compared before and after a pass to tell whether it moved.
	 *
	 * Failure counters are deliberately left out: a pass that only failed again has not moved
	 * the folder on, and retrying it at once would spend the tick repeating the failure.
	 *
	 * @param array  $existing_data Backup state.
	 * @param string $key           Folder key.
	 * @return string
	 */
	public static function tailwatch_area_progress( array $existing_data, $key ) {
		$area = ( isset( $existing_data[ $key ] ) && is_array( $existing_data[ $key ] ) ) ? $existing_data[ $key ] : array();

		return implode(
			'|',
			array(
				empty( $area['manifest_built'] ) ? 0 : 1,
				isset( $area['current_large_file'] ) ? (int) $area['current_large_file'] : 0,
				isset( $area['manifest_offset'] ) ? (int) $area['manifest_offset'] : 0,
				isset( $area['current_zip'] ) ? (int) $area['current_zip'] : 0,
				empty( $area['manifest_eof'] ) ? 0 : 1,
				empty( $area['completed'] ) ? 0 : 1,
			)
		);
	}

	/**
	 * Whether another pass on a folder fits in what is left of the tick.
	 *
	 * Building a folder's file list and archiving one large file cannot stop part way, so they
	 * start only with at least half the tick left. Packing parts stops at its own deadline and
	 * needs only the minimum budget.
	 *
	 * @param array  $existing_data Backup state.
	 * @param string $key           Folder key.
	 * @param int    $deadline      Tick deadline.
	 * @param int    $budget        The tick's whole budget, in seconds.
	 * @return bool
	 */
	public static function tailwatch_pass_fits( array $existing_data, $key, $deadline, $budget ) {
		$area      = ( isset( $existing_data[ $key ] ) && is_array( $existing_data[ $key ] ) ) ? $existing_data[ $key ] : array();
		$large     = isset( $area['large_files'] ) ? count( (array) $area['large_files'] ) : 0;
		$unbounded = empty( $area['manifest_built'] ) || ( isset( $area['current_large_file'] ) ? (int) $area['current_large_file'] : 0 ) < $large;
		$needed    = $unbounded ? (int) ceil( (int) $budget / 2 ) : self::TICK_BUDGET_MIN;

		return ( (int) $deadline - time() ) >= $needed && ! TickBudget::memory_exceeded();
	}

	/**
	 * Whether every folder of the run is backed up.
	 *
	 * @param array $existing_data Backup state.
	 * @param array $keys          Folder keys of the run.
	 * @return bool
	 */
	private static function tailwatch_all_areas_done( array $existing_data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( empty( $existing_data[ $key ]['completed'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Main backup cron callback (hook 'tailwatch_backup_daily_scan'). Thin wrapper that
	 * holds a single-worker lock around the real tick so the verify-hook and the
	 * recovery watchdog cannot run a SECOND worker on the same scan_backp row — two
	 * concurrent workers do interleaved read-modify-write and clobber progress.
	 */
	public function tailwatch_run_backup_cron() {
		self::tailwatch_forget_cached_schedule();
		if ( ! $this->tailwatch_acquire_worker_lock() ) {
			// Another worker is mid-tick, maybe a cancelled run's last pass: retried shortly.
			if ( ! wp_next_scheduled( 'tailwatch_backup_daily_scan' ) ) {
				wp_schedule_single_event( time() + self::WORKER_LOCK_RETRY, 'tailwatch_backup_daily_scan' );
			}
			return;
		}
		try {
			$this->tailwatch_run_backup_cron_worker();
		} finally {
			self::tailwatch_clear_run_owner();
			$this->tailwatch_release_worker_lock();
			self::tailwatch_forget_cached_schedule();
		}

		if ( wp_next_scheduled( 'tailwatch_backup_daily_scan' ) ) {
			CronKick::accelerate();
		}
	}

	/**
	 * Read the cron schedule afresh on the next look.
	 *
	 * WordPress keeps the schedule in the request's options cache and writes all of it back
	 * whenever anything is scheduled or unscheduled. A backup step takes most of a tick, and
	 * the cron runner that called it then reschedules its other due events from that copy:
	 * events other requests removed meanwhile come back (a cancelled run's files tick), and
	 * events they added are lost (the next run's database step). Called as a step starts and
	 * as it ends.
	 *
	 * @return void
	 */
	public static function tailwatch_forget_cached_schedule() {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
	}

	/**
	 * Acquire the single-worker lock. It lasts WORKER_LOCK_TTL, and a long file-list build
	 * renews it while it walks. The finally releases on every normal/exception return; the
	 * shutdown hook releases on a timeout/fatal that bypasses finally; the TTL is the
	 * last-resort backstop for a hard SIGKILL where even shutdown does not run.
	 * Sequential +3s ticks never block each other — each releases on return.
	 *
	 * @return bool True if this worker got the lock, false if one is already running.
	 */
	private function tailwatch_acquire_worker_lock() {
		if ( get_transient( 'tailwatch_backup_worker_lock' ) ) {
			return false;
		}
		$this->worker_lock_stamp = time();
		set_transient( 'tailwatch_backup_worker_lock', $this->worker_lock_stamp, self::WORKER_LOCK_TTL );
		register_shutdown_function( array( $this, 'tailwatch_release_worker_lock' ) );

		// Relaunch the file phase if this tick dies on an OOM / time-limit kill, instead
		// of waiting up to 3 minutes for the process-recovery watchdog.
		self::tailwatch_arm_fatal_rearm( 'tailwatch_backup_daily_scan' );

		return true;
	}

	/**
	 * Release the single-worker lock. Idempotent (deleting an absent transient is a
	 * no-op), so the finally + shutdown-hook double release is safe.
	 *
	 * @return void
	 */
	public function tailwatch_release_worker_lock() {
		delete_transient( 'tailwatch_backup_worker_lock' );
		$this->worker_lock_stamp = null;
	}

	/**
	 * Extend the single-worker lock, unless the stamp this request sees in it is not its own.
	 *
	 * @return void
	 */
	private function tailwatch_renew_worker_lock() {
		if ( null === $this->worker_lock_stamp ) {
			return;
		}
		if ( (int) get_transient( 'tailwatch_backup_worker_lock' ) !== $this->worker_lock_stamp ) {
			return;
		}
		set_transient( 'tailwatch_backup_worker_lock', $this->worker_lock_stamp, self::WORKER_LOCK_TTL );
	}

	/**
	 * Whether the status row no longer describes the run a tick started on.
	 *
	 * A cancelled run's rows are deleted a few seconds after the click, and a new run may already
	 * have taken their place, so the newest row is then another run's or there is none.
	 *
	 * @param mixed  $cancel_pause Newest backup status row.
	 * @param string $folder_date  The run's folder name.
	 * @return bool
	 */
	private static function tailwatch_run_is_gone( $cancel_pause, $folder_date ) {
		if ( ! is_array( $cancel_pause ) || empty( $cancel_pause ) ) {
			return true;
		}

		return '' !== (string) $folder_date
			&& isset( $cancel_pause['folderDate'] )
			&& (string) $cancel_pause['folderDate'] !== (string) $folder_date;
	}

	/**
	 * What a folder's file-list build calls while it walks.
	 *
	 * Every LISTING_BEAT_INTERVAL seconds it stops the walk when the run was paused, cancelled or
	 * is gone, and otherwise writes the heartbeat and renews the worker lock; every
	 * LISTING_LOG_INTERVAL seconds it also adds a line to the live log.
	 *
	 * @param string $key         Folder key.
	 * @param string $process_id  Backup process id, '' when unknown.
	 * @param string $folder_date The run's folder name.
	 * @return callable Takes the files found so far and, optionally, the current time; returns
	 *                  false to stop the walk.
	 */
	private function tailwatch_listing_keep_alive( $key, $process_id, $folder_date ) {
		$last_beat = time();
		$last_line = $last_beat;

		return function ( $files_so_far, $now = null ) use ( $key, $process_id, $folder_date, &$last_beat, &$last_line ) {
			$now = null === $now ? time() : (int) $now;
			if ( $now - $last_beat < self::LISTING_BEAT_INTERVAL ) {
				return true;
			}
			$last_beat = $now;

			$cancel_pause = $this->tailwatch_backup_cancel_pause_data();
			if ( self::tailwatch_run_is_gone( $cancel_pause, $folder_date )
				|| in_array( $cancel_pause['scan_state'] ?? '', array( 'cancel', 'pause', 'failed', 'completed' ), true ) ) {
				return false;
			}

			if ( '' !== (string) $process_id ) {
				$this->process_manager->heart_beat( $process_id );
			}
			$this->tailwatch_renew_worker_lock();

			if ( $now - $last_line >= self::LISTING_LOG_INTERVAL ) {
				$last_line = $now;
				$this->update_logs_records( sprintf( 'Still listing %s: %s so far', self::tailwatch_area_label( $key ), self::tailwatch_count( $files_so_far, 'file' ) ) );
			}

			return true;
		};
	}

	/**
	 * The real backup tick: maintains backups, loads state, and runs folder backup or
	 * finalization. Runs only while holding the single-worker lock (see the wrapper).
	 */
	private function tailwatch_run_backup_cron_worker() {
		// Raise PHP memory/time limits before the heavy backup tick. Runtime-only,
		// idempotent (only raises when the current value is lower). Mirrors the scanner.
		( new PerformanceOptimizerController() )->tailwatch_boost_for_scanning();

		$feature_controller = new DBModel();

		$tailwatch_key        = 'default_backup_scan';
		$option          = 'scan_backp';
		$backup_maintain = new BackupMaintainController();
		$backup_maintain->maintain_backups( $tailwatch_key, $option );

		// Asked for THIS site's run, not merely for the newest row under this key. See
		// tailwatch_backup_run_state(): a migration leaves a catalogue row here that is not
		// a job, and running it is an endless backup that overwrites its own parts.
		$existing_data = $this->tailwatch_backup_run_state();

		if ( empty( $existing_data ) || ! is_array( $existing_data ) ) {
			// Nothing here to work on. The schedule goes with it, otherwise the tick is
			// re-entered every few seconds against the same unusable row - which is the
			// loop this exists to end, only quieter.
			$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );
			}
			return;
		}

		self::tailwatch_set_run_owner( $existing_data['folderDate'] ?? '' );
		$cancel_pause = $this->tailwatch_backup_cancel_pause_data();

		// The newest status row is another run's: the schedule is that run's too.
		if ( 'gone' === $cancel_pause['scan_state'] ) {
			return;
		}

		// This prevents recovery/watchdog from re-running a paused, cancelled, hard-failed or
		// finished process. 'failed' is terminal — bail without doing work or
		// rescheduling so a marked-failed backup can't be revived into a loop. A tick can
		// also arrive after the finaliser (a database tick that was already queued schedules
		// one), and running it would put the process back to in_progress and finalise the
		// backup a second time.
		$stop_state = $cancel_pause['scan_state'];
		if ( in_array( $stop_state, array( 'cancel', 'pause', 'failed', 'completed' ), true ) ) {
			$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );
			}
			// A finished run's rows already say so; there is nothing to mirror.
			if ( 'completed' !== $stop_state ) {
				$this->update_backup_scan_state( $stop_state );
			}
			return;
		}

		$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : ( $this->current_process_id ?? null );
		$this->process_manager->heart_beat( $process_id );
		$this->process_manager->update_state( $process_id, 'in_progress' );

		if ( false === $cancel_pause['cron_running'] ) {
			$cancel_pause['cron_running'] = true;
			$this->update_backup_cancel_pause( $cancel_pause );
		}

		$this->tailwatch_backup_function_started();

		// Resolve dynamic core paths reliably
		$upload_dir      = wp_upload_dir();
		$uploads_basedir = $upload_dir['basedir'];
		$content_basename = wp_basename( WP_CONTENT_DIR );

		if ( 'files_integrity' === $existing_data['process_run'] ) {
			$folders = $this->tailwatch_verify_required_folders();

			$backup_dir_base = trailingslashit( TAILWATCH_BACKUP_DIR ) . 'tailwatch-scanner/';

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
				defined( 'TAILWATCH_CONTENT_DIR_BASE' ) ? TAILWATCH_CONTENT_DIR_BASE : '',
			);

			$exclude_root_folders = $this->get_root_folders_name();
		}

		// A tick keeps working while its budget lasts. A folder takes several passes (its file
		// list, its large files, its parts); a pass that moves it on hands straight to the next,
		// and a finished folder to the next folder, rather than ending the tick and waiting for
		// WP-Cron to be triggered again.
		$budget   = self::tailwatch_tick_budget( 25 );
		$deadline = time() + $budget;
		$passes   = 0;

		try {
			foreach ( $folders as $key => $folder_path ) {
				while ( empty( $existing_data[ $key ]['completed'] ) ) {
					if ( $passes > 0 && ! self::tailwatch_pass_fits( $existing_data, $key, $deadline, $budget ) ) {
						break 2;
					}
					++$passes;

					$before = self::tailwatch_area_progress( $existing_data, $key );

					$pass_result = $this->process_folder_backup( $key, $folder_path, $existing_data, $feature_controller, $tailwatch_key, $option, $exclude_wp_content_folders, $exclude_root_folders, $deadline, $this->tailwatch_listing_keep_alive( $key, (string) $process_id, (string) ( $existing_data['folderDate'] ?? '' ) ) );

					// Check again in case scan_state changed during execution: user cancel/pause,
					// OR a hard failure (manifest build / part packing hit its cap) marked the run
					// 'failed'. In all three we stop WITHOUT rescheduling the next tick.
					$cancel_pause = $this->tailwatch_backup_cancel_pause_data();

					// The run was replaced by another since this tick began: the rows and the schedule
					// below are the other run's.
					if ( 'gone' === $cancel_pause['scan_state'] ) {
						self::$pending_backup_data = null;
						return;
					}

					// A file list stopped because its run is gone: the rows and the schedule below now
					// belong to another run, or to nothing. So does the newest row the buffered state
					// would be flushed onto at shutdown.
					if ( self::LISTING_STOPPED === $pass_result && self::tailwatch_run_is_gone( $cancel_pause, $existing_data['folderDate'] ?? '' ) ) {
						self::$pending_backup_data = null;
						return;
					}

					if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] || 'failed' === $cancel_pause['scan_state'] ) {
						$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
						if ( $timestamp ) {
							wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );
						}

						$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
						if ( $timestamp_backup_status ) {
							wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
						}

						if ( true === $cancel_pause['cron_running'] ) {
							$cancel_pause['cron_running'] = false;
							$this->update_backup_cancel_pause( $cancel_pause );
						}
						$this->update_backup_scan_state( $cancel_pause['scan_state'] );
						$this->tailwatch_backup_function_complete();
						$this->process_manager->heart_beat( $process_id );
						return;
					}

					$this->process_manager->heart_beat( $process_id );

					if ( self::tailwatch_area_progress( $existing_data, $key ) === $before ) {
						// Nothing moved, so repeating the pass now would only repeat what stopped it.
						break 2;
					}
				}
			}

			if ( ! $this->tailwatch_run_is_current() ) {
				self::$pending_backup_data = null;
				return;
			}

			if ( ! self::tailwatch_all_areas_done( $existing_data, array_keys( $folders ) ) ) {
				wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
				$this->tailwatch_backup_function_complete();
				return;
			}

			// A folder's last pass scheduled a tick for whatever came next, and this tick got there
			// itself. Left scheduled, that tick would find every folder done and finalize again.
			$pending = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
			if ( $pending ) {
				wp_unschedule_event( $pending, 'tailwatch_backup_daily_scan' );
			}

			// Finalize the backup process after all folders are processed.
			$this->check_and_finalize_backup( $existing_data, $feature_controller, $tailwatch_key, $option, $process_id );
			$this->tailwatch_backup_function_complete();
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
	public function tailwatch_backup_function_started() {
		$cancel_pause                       = $this->tailwatch_backup_cancel_pause_data();
		$cancel_pause['function_completed'] = false;
		$cancel_pause['function_started']   = true;
		$this->update_backup_cancel_pause( $cancel_pause );
	}

	/**
	 * Marks backup function as complete in cancel/pause data.
	 *
	 */
	public function tailwatch_backup_function_complete() {
		$cancel_pause                         = $this->tailwatch_backup_cancel_pause_data();
		$cancel_pause['function_completed']   = true;
		$cancel_pause['function_started']     = false;
		$cancel_pause['completion_timestamp'] = time();
		$this->update_backup_cancel_pause( $cancel_pause );
	}

	/**
	 * @see BackupManifest::tailwatch_build_backup_manifest()
	 *
	 * @param string        $folder_path         Folder to walk.
	 * @param array         $exclude             Absolute paths to prune.
	 * @param string        $manifest_file       Manifest sidecar to write.
	 * @param string        $large_file_manifest Oversize-file sidecar to write.
	 * @param int           $zip_size_limit      Per-part byte limit.
	 * @param int           $scan_max_size       Drop files over this size (0 = no cap).
	 * @param int           $large_file_limit    Files over this are archived on their own (0 = the part limit).
	 * @param callable|null $on_progress         Walk progress callback; false from it stops the walk.
	 * @return array|false
	 */
	private function tailwatch_build_backup_manifest( $folder_path, array $exclude, $manifest_file, $large_file_manifest, $zip_size_limit = 52428800, $scan_max_size = 0, $large_file_limit = 0, $on_progress = null ) {
		return BackupManifest::tailwatch_build_backup_manifest( $folder_path, $exclude, $manifest_file, $large_file_manifest, $zip_size_limit, $scan_max_size, $large_file_limit, $on_progress );
	}

	/**
	 * @see BackupManifest::tailwatch_pack_part_from_manifest()
	 *
	 * @param string $manifest_file  Manifest sidecar (relPath	size lines).
	 * @param int    $offset         Byte offset to resume from (0 = first part).
	 * @param string $folder_path    Folder root the rel paths are under.
	 * @param string $path_prefix    In-zip prefix for this key.
	 * @param string $destination    Zip file to (over)write for this part.
	 * @param int    $zip_size_limit Per-part byte limit (default 50MB).
	 * @param int    $deadline       Unix timestamp to stop filling at (0 = no limit).
	 * @return array|false
	 */
	private function tailwatch_pack_part_from_manifest( $manifest_file, $offset, $folder_path, $path_prefix, $destination, $zip_size_limit = 52428800, $deadline = 0 ) {
		return BackupManifest::tailwatch_pack_part_from_manifest( $manifest_file, $offset, $folder_path, $path_prefix, $destination, $zip_size_limit, $deadline );
	}

	/**
	 * Read an archive's entry index: in-zip name => uncompressed size.
	 *
	 * Reads only the central directory, so this costs a seek and a small read no
	 * matter how large the archive is — nothing is decompressed. Both engines expose
	 * the uncompressed size, so the caller does not care which one wrote the part.
	 *
	 * @param string $zip_path Archive to read.
	 * @return array<string,int>|null Name => size, or null when the archive cannot be opened.
	 */
	private function tailwatch_read_archive_index( $zip_path ) {
		return ArchiveReader::read_index( $zip_path );
	}

	/**
	 * @see BackupManifest::tailwatch_verify_part_against_manifest()
	 *
	 * @param string $zip_path       Finished part.
	 * @param string $manifest_file  Manifest sidecar.
	 * @param int    $start          Byte offset the part started at.
	 * @param int    $end            Byte offset the packer stopped at.
	 * @param string $path_prefix    In-zip prefix for this key.
	 * @param int    $expected_added Files the packer reported adding.
	 * @return array{ok:bool,entries:int,expected:int,drift:int,error:string}
	 */
	protected function tailwatch_verify_part_against_manifest( $zip_path, $manifest_file, $start, $end, $path_prefix, $expected_added ) {
		return BackupManifest::tailwatch_verify_part_against_manifest( $zip_path, $manifest_file, $start, $end, $path_prefix, $expected_added );
	}

	/**
	 * @see BackupManifest::tailwatch_pclzip_write_part()
	 *
	 * @param string $destination Part zip path to (over)write.
	 * @param array  $groups      [source_dir][in_zip_dir][] = abs path.
	 * @return bool
	 */
	private function tailwatch_pclzip_write_part( $destination, $groups ) {
		return BackupManifest::tailwatch_pclzip_write_part( $destination, $groups );
	}


	/**
	 * The content folder's name inside an archive when it cannot be placed by its path
	 * under the site root, which also starts the plugins, themes and uploads paths then.
	 * The restore reads the content folder under the same name.
	 *
	 * @return string
	 */
	public static function tailwatch_content_archive_name() {
		return basename( WP_CONTENT_DIR );
	}

	/**
	 * In-zip path prefix for a folder key — the directory each key's files extract to.
	 * MUST match the layout the restore expects (same mapping the single-file zipper
	 * uses). 'others' = site root.
	 *
	 * @param string $key Folder key.
	 * @return string Prefix (no trailing slash), '' for root.
	 */
	private function tailwatch_path_prefix_for_key( $key, $source = '' ) {
		$content_base = self::tailwatch_content_archive_name();

		switch ( $key ) {
			case 'wp-admin':
				$assumed = 'wp-admin';
				break;
			case 'wp-includes':
				$assumed = 'wp-includes';
				break;
			case 'wp-content':
				$assumed = $content_base;
				break;
			case 'plugins':
				$assumed = $content_base . '/plugins';
				break;
			case 'themes':
				$assumed = $content_base . '/themes';
				break;
			case 'uploads':
				$assumed = $content_base . '/uploads';
				break;
			case 'others':
				$assumed = '';
				break;
			default:
				$assumed = $key;
				break;
		}

		return self::tailwatch_area_prefix( $source, $assumed );
	}

	/**
	 * Where a source directory sits inside the tree being packed.
	 *
	 * A hardcoded prefix asserts where a directory OUGHT to be, and WordPress lets every
	 * one of them move: uploads via UPLOADS or the upload_path option, and per subsite on
	 * multisite; plugins via WP_PLUGIN_DIR; themes via register_theme_directory. Reading
	 * the real position keeps the archive describing the tree it was taken from.
	 *
	 * Resolved against the source rather than against the key, because two different
	 * trees are packed by this code. A site backup reads the live directories, and their
	 * root is ABSPATH. A malware or integrity scan reads a snapshot under
	 * tailwatch-scanner/, which mirrors the live layout at a fixed path — and the pro
	 * plugin's malware restore reads those archives back at exactly those fixed paths.
	 * Asking where the source is, relative to the root of its own tree, answers both
	 * without a run-context flag that a future caller would forget to pass.
	 *
	 * On a standard single site every key resolves to exactly the assumed prefix, so the
	 * archive layout is the same either way.
	 *
	 * The fallback covers a source that is missing, unreadable, or outside every tree we
	 * pack. There is no correct in-archive path for such a directory — an archive is
	 * relative to a root by construction — so the assumed layout stands rather than
	 * inventing one the restore could not honour.
	 *
	 * @param string $source  Absolute path to the directory being packed.
	 * @param string $assumed Prefix to use when the source cannot be placed.
	 * @return string Prefix with no leading or trailing slash.
	 */
	private static function tailwatch_area_prefix( $source, $assumed ) {
		if ( ! is_string( $source ) || '' === $source ) {
			return $assumed;
		}

		$real = realpath( $source );
		if ( false === $real ) {
			return $assumed;
		}
		$real = rtrim( wp_normalize_path( $real ), '/' );

		foreach ( self::tailwatch_layout_roots() as $root ) {
			if ( $real === $root ) {
				return '';
			}

			// Slash-terminated so a sibling that merely shares a prefix is not read as
			// being inside the root.
			if ( 0 === strpos( $real . '/', $root . '/' ) ) {
				$relative = trim( substr( $real, strlen( $root ) ), '/' );
				return ( '' === $relative ) ? $assumed : $relative;
			}
		}

		return $assumed;
	}

	/**
	 * Row identifiers for the backup folders that exist under either backup tree.
	 *
	 * A backup is a row AND a directory. The listing requires both, so the page count must
	 * too: counting rows alone, a vault carrying rows for folders that are no longer there
	 * — the usual causes being a migration, a manual delete, and a run that never
	 * finished — would report more pages than it could fill.
	 *
	 * Two scandir calls against directories the listing is about to walk in full anyway.
	 *
	 * @return array<string> Row `type` values, deduplicated.
	 */
	private static function tailwatch_backup_row_types_on_disk() {
		$names = array();

		foreach ( array( 'files', 'database' ) as $tree ) {
			$dir = trailingslashit( TAILWATCH_BACKUP_DIR ) . $tree;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- listing names in the plugin's own storage; dirlist() builds a far heavier structure for the same answer.
			foreach ( (array) scandir( $dir ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( is_dir( $dir . '/' . $entry ) ) {
					// A backup row's `type` is 'backup_<folderDate>', so the bare directory
					// name would match no row at all and every count would come back zero.
					$names[ 'backup_' . $entry ] = true;
				}
			}
		}

		return array_keys( $names );
	}

	/**
	 * The roots an archive's paths can be relative to, most specific first.
	 *
	 * The scanner snapshot lives inside the site, so it has to be tested before ABSPATH
	 * or every scanner path would be named relative to the site root and the archive
	 * would carry the plugin's own storage directory in every entry.
	 *
	 * @return array<string> Normalised absolute paths, no trailing slash.
	 */
	private static function tailwatch_layout_roots() {
		$roots = array();

		if ( defined( 'TAILWATCH_BACKUP_DIR' ) ) {
			$scanner = realpath( trailingslashit( TAILWATCH_BACKUP_DIR ) . 'tailwatch-scanner' );
			if ( false !== $scanner ) {
				$roots[] = rtrim( wp_normalize_path( $scanner ), '/' );
			}
		}

		$site = realpath( ABSPATH );
		if ( false !== $site ) {
			$roots[] = rtrim( wp_normalize_path( $site ), '/' );
		}

		return $roots;
	}

	/**
	 * @see BackupManifest::tailwatch_load_large_files_from_manifest()
	 *
	 * @param string $large_manifest Large-file manifest sidecar.
	 * @param string $folder_path    Folder root the rel paths are under.
	 * @return array
	 */
	private function tailwatch_load_large_files_from_manifest( $large_manifest, $folder_path ) {
		return BackupManifest::tailwatch_load_large_files_from_manifest( $large_manifest, $folder_path );
	}



	/**
	 * Whether a usable zip engine is available for the file backup — ZipArchive (the zip
	 * extension) or the WordPress-bundled PclZip fallback (pure PHP, needs only zlib).
	 *
	 * @return bool
	 */
	private function tailwatch_zip_engine_available() {
		return ArchiveReader::is_available();
	}

	public function process_folder_backup( $key, $folder_path, &$existing_data, $feature_controller, $tailwatch_key, $option, $exclude_wp_content_folders, $exclude_root_folders, $worker_deadline = 0, $on_progress = null ) {
		if ( ! isset( $existing_data['zipId'] ) || ! isset( $existing_data['folderDate'] ) ) {
			return;
		}

		// A replaced run's folder has been deleted; working on would recreate it and count
		// its missing manifest as this backup failing.
		if ( ! $this->tailwatch_run_is_current() ) {
			return self::LISTING_STOPPED;
		}

		$zip_size_limit = self::tailwatch_folder_part_size( $existing_data, $key );
		if ( ! isset( $existing_data[ $key ] ) || ( ! isset( $existing_data[ $key ]['started_message'] ) || true !== $existing_data[ $key ]['started_message'] ) ) {
			$this->update_logs_records( 'Backing up ' . self::tailwatch_area_label( $key ) );
			$existing_data[ $key ]['started_message'] = true;
		}

		// File backup needs a zip engine: ZipArchive (the zip extension) or the
		// WordPress-bundled PclZip fallback. If neither is usable, fail fast with a clear
		// message instead of the generic "failed to write a part".
		if ( ! $this->tailwatch_zip_engine_available() ) {
			$this->update_logs_records( 'Backup needs the PHP "zip" extension (ZipArchive) or PclZip; neither is available on this server. Enable the PHP zip extension to continue.', 'ERROR' );
			$this->tailwatch_mark_backup_failed( 'no_zip_engine' );
			return;
		}

		$cron_start_time                     = time();
		$existing_data[ $key ]['cron_start'] = $cron_start_time;
		$zip_unique_id                       = $existing_data['zipId'];
		$folder_date                         = $existing_data['folderDate'];

		$backup_directory = TAILWATCH_BACKUP_DIR . '/';
		if ( ! is_dir( $backup_directory ) ) {
			// Seal the backup root with deny files so archives are not reachable over the web.
			SecureDirectoryService::ensure_private_root( $backup_directory );
		}
		// Seal every level, not just the root. An ancestor .htaccess already covers
		// Apache and web.config inherits on IIS, so this is defence in depth for the
		// case where the root deny files are deleted or never written. On nginx no
		// deny file applies at any level — there the protection is the unguessable
		// path segment plus the absence of a published URL.
		$files_directory = $backup_directory . 'files/';
		if ( ! file_exists( $files_directory ) ) {
			wp_mkdir_p( $files_directory );
		}
		SecureDirectoryService::ensure( $files_directory, true );

		$daily_backup_directory = $files_directory . $folder_date . '/';
		if ( ! file_exists( $daily_backup_directory ) ) {
			wp_mkdir_p( $daily_backup_directory );
		}
		SecureDirectoryService::ensure( $daily_backup_directory, true );

		$normal_files_count = 0;

		$batch = isset( $existing_data[ $key ]['batch'] ) ? $existing_data[ $key ]['batch'] : array();

		// ONE manifest build serves the large-file list, the part count and every part, so
		// the tree is walked once. The first tick builds it and returns (one tick to plan).
		if ( empty( $existing_data[ $key ]['manifest_built'] ) ) {
			if ( 'wp-content' === $key ) {
				$folder_exclude = $exclude_wp_content_folders;
			} elseif ( 'others' === $key ) {
				$folder_exclude = $exclude_root_folders;
			} elseif ( 'uploads' === $key ) {
				// Skip the plugin's own logs and generated data (including the
				// GeoIP database) so backups do not carry regenerable files.
				$folder_exclude = array( TAILWATCH_LOGS_DIRECTORY );
			} else {
				$folder_exclude = array();
			}
			$manifest_file  = $daily_backup_directory . "{$key}_{$zip_unique_id}.manifest";
			$large_manifest = $daily_backup_directory . "{$key}_{$zip_unique_id}.large";

			// Malware/integrity scan backups cap file size — skip oversize files (media/
			// archives a scanner does not deep-scan) to cut upload+scan cost. Restore is unaffected:
			// a skipped file never enters the backup → never uploaded/scanned/restored. The
			// regular full backup keeps everything (cap stays 0). The cap value is filterable
			// through 'tailwatch_malware_scan_max_file_size' (return 0 to disable). Default
			// 52428800 reuses the engine's 50MB large-file line.
			$scan_process_run = isset( $existing_data['process_run'] ) ? $existing_data['process_run'] : '';
			$scan_max_size    = 0;
			if ( in_array( $scan_process_run, array( 'malware', 'files_integrity' ), true ) ) {
				$scan_max_size = (int) apply_filters( 'tailwatch_malware_scan_max_file_size', 52428800, $scan_process_run, $key );
			}

			$stats = $this->tailwatch_build_backup_manifest( $folder_path, $folder_exclude, $manifest_file, $large_manifest, $zip_size_limit, $scan_max_size, self::LARGE_FILE_SIZE, $on_progress );
			if ( is_array( $stats ) && ! empty( $stats['aborted'] ) ) {
				// The run was paused, cancelled or is gone; the tick loop reads which.
				return self::LISTING_STOPPED;
			}
			if ( false === $stats && ! $this->tailwatch_run_is_current() ) {
				return self::LISTING_STOPPED;
			}
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
					$this->tailwatch_mark_backup_failed( "manifest_build_failed:{$key}" );
				}
				return; // build failed — retried next tick until the cap.
			}
			// Named, not just counted. A folder the backup could not open is a hole in the
			// archive, and the operator can only fix a permission they can see.
			if ( ! empty( $stats['unreadable_dirs'] ) ) {
				$shown = array_slice( $stats['unreadable_dirs'], 0, 10 );
				$this->update_logs_records(
					count( $stats['unreadable_dirs'] ) . " folder(s) under {$key} could not be read and were skipped — " . implode( ', ', $shown ),
					'WARNING'
				);
				$existing_data[ $key ]['unreadable_dirs'] = $stats['unreadable_dirs'];
			}

			// Not an error — each WP area is opened as its own root, so a symlinked
			// wp-content, plugins, themes or uploads IS backed up. This is the nested case,
			// and it is named so its absence from the archive is a fact the operator knows
			// rather than one they discover during a restore.
			if ( ! empty( $stats['linked_dirs'] ) ) {
				$linked = array_slice( $stats['linked_dirs'], 0, 10 );
				$this->update_logs_records(
					count( $stats['linked_dirs'] ) . " symlinked folder(s) under {$key} were not followed and are not in this backup — " . implode( ', ', $linked ),
					'WARNING'
				);
				$existing_data[ $key ]['linked_dirs'] = $stats['linked_dirs'];
			}

			if ( ! empty( $stats['skipped_oversize'] ) ) {
				$this->update_logs_records( "Skipped {$stats['skipped_oversize']} file(s) over " . (int) round( $scan_max_size / 1048576 ) . "MB from the {$scan_process_run} scan backup" );
			}
			$existing_data[ $key ]['manifest_built']  = true;
			$existing_data[ $key ]['manifest_file']   = $manifest_file;
			$existing_data[ $key ]['num_zips']        = $stats['num_parts'];
			$existing_data[ $key ]['manifest_offset'] = 0;
			// The intended file count, persisted so finalize can reconcile it against what
			// was actually archived; without it a backup that lost files would still report
			// success.
			$existing_data[ $key ]['expected_files'] = (int) $stats['files'];
			$existing_data[ $key ]['expected_bytes'] = (int) $stats['total_size'];
			$existing_data[ $key ]['packed_files']   = 0;
			$existing_data[ $key ]['skipped_files']  = 0;
			$existing_data[ $key ]['large_files']     = $this->tailwatch_load_large_files_from_manifest( $large_manifest, $folder_path );
			$this->update_backup_data( $existing_data );
			return;
		}
		$num_zips    = (int) $existing_data[ $key ]['num_zips'];
		$large_files = isset( $existing_data[ $key ]['large_files'] ) ? $existing_data[ $key ]['large_files'] : array();
		$label       = self::tailwatch_area_label( $key );
		if ( ( $num_zips >= 1 || ! empty( $large_files ) ) && ! isset( $existing_data[ $key ]['archive_message'] ) ) {
			$this->update_logs_records( self::tailwatch_describe_archive_plan( $label, $existing_data[ $key ], $large_files, $zip_size_limit ) );
			$existing_data[ $key ]['archive_message'] = true;
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

			if ( ! wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' ) ) {
				wp_schedule_single_event( time() + $total_scheduled_time, 'tailwatch_verify_backup_cron_hook' );
			}

			$timestamp_run = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );

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

			if ( ! $this->tailwatch_is_valid_zip( $large_zip_file_path ) ) {
				// Delete a truncated/corrupt large-file zip so this attempt rebuilds it.
				if ( file_exists( $large_zip_file_path ) ) {
					wp_delete_file( $large_zip_file_path );
				}

				if ( ! $this->tailwatch_disk_guard( (int) $file_size, "the large file {$file['filename']}" ) ) {
					return;
				}

				$this->create_single_file_zip( $file, $key, $large_zips, $folder_path, $daily_backup_directory, $zip_unique_id );
			}

			if ( $this->tailwatch_is_valid_zip( $large_zip_file_path ) ) {
				// Fingerprint this part exactly as a manifest part is fingerprinted.
				// Without it the restore validator finds no digest and no entry count,
				// skips both checks, and reports "predates content fingerprinting" — so
				// the largest single files in a backup, the ones over the 50MB part
				// limit, would be the least verified things in the whole set.
				$large_index  = ArchiveReader::read_index( $large_zip_file_path );
				$large_digest = ArchiveReader::content_digest( $large_zip_file_path );

				$batch[ "large_zip_{$large_zips}" ] = array(
					'size'      => filesize( $large_zip_file_path ),
					'path'      => $large_zip_file_path,
					'status'    => 'completed',
					'entries'   => is_array( $large_index ) ? count( $large_index ) : null,
					'digest'    => is_string( $large_digest ) ? $large_digest : '',
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

			$cancel_pause = $this->tailwatch_backup_cancel_pause_data();
			if ( 'gone' === $cancel_pause['scan_state'] ) {
				return self::LISTING_STOPPED;
			}
			if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) {
				$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );
				}

				$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
				if ( $timestamp_backup_status ) {
					wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
				}

				if ( true === $cancel_pause['cron_running'] ) {
					$cancel_pause['cron_running'] = false;
					$this->update_backup_cancel_pause( $cancel_pause );
				}
				$this->update_backup_scan_state( $cancel_pause['scan_state'] );
				return;
			} else {
				$timestamp = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'tailwatch_verify_backup_cron_hook' );
				}
				wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
				return;
			}
		}

		// Pack parts from the manifest cursor in a time-budgeted loop (many parts/tick),
		// advancing offset + index in lockstep — no per-part-retry desync. Falls through
		// to folder completion once every part is written.
		if ( ! wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' ) ) {
			wp_schedule_single_event( time() + 230, 'tailwatch_verify_backup_cron_hook' );
		}
		$cursor_offset   = (int) ( isset( $existing_data[ $key ]['manifest_offset'] ) ? $existing_data[ $key ]['manifest_offset'] : 0 );
		$manifest_path = isset( $existing_data[ $key ]['manifest_file'] ) ? $existing_data[ $key ]['manifest_file'] : '';
		$in_zip_prefix   = $this->tailwatch_path_prefix_for_key( $key, $folder_path );
		$tick_budget   = self::tailwatch_tick_budget( 25 );
		$tick_deadline = time() + $tick_budget;

		// A pass later in the same tick packs only in what the tick has left. With less than
		// the minimum it waits for the next tick, before the no-progress guard below would
		// count a pass that never had time to pack as a stalled part.
		if ( $worker_deadline > 0 && $worker_deadline < $tick_deadline ) {
			$tick_deadline = (int) $worker_deadline;
			$tick_budget   = max( 0, $tick_deadline - time() );
			if ( $tick_budget < self::TICK_BUDGET_MIN ) {
				if ( $this->tailwatch_run_may_continue() ) {
					wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
				}
				return;
			}
		}

		// Keep the close reserve proportional on a short budget: a flat 5s off a 5s
		// budget would leave no time to add anything, so every part would hold one file.
		$pack_reserve = min( self::PART_CLOSE_RESERVE, (int) max( 1, floor( $tick_budget / 3 ) ) );
		// The loop has to stop at the same moment the packer does. Looping on to the tick
		// deadline while each part is told to stop earlier opens a window in which every
		// part closes after its first file, leaving a folder in hundreds of one-file parts.
		$pack_deadline = $tick_deadline - $pack_reserve;
		$paused        = false;

		// Which writer this run uses. Decided here rather than inside the loop because the
		// no-progress guard below responds differently depending on it.
		$process_run = isset( $existing_data['process_run'] ) ? (string) $existing_data['process_run'] : '';
		$use_stream  = ! in_array( $process_run, BackupMaintainController::SCAN_DRIVEN_PROCESS_RUNS, true );

		// No-progress guard: if this folder's manifest cursor has not advanced since the
		// previous tick, one part is failing to complete every tick — e.g. a large part whose
		// ZipArchive::close() is killed before the cursor / fail-counter (both updated only AFTER
		// the pack) can advance, so it would re-pack forever. Persist BEFORE packing so a kill
		// still counts; fail cleanly after the cap. A slow-but-completing part always advances.
		$last_tick_offset = isset( $existing_data[ $key ]['last_tick_offset'] ) ? (int) $existing_data[ $key ]['last_tick_offset'] : -1;
		$stuck_ticks      = isset( $existing_data[ $key ]['stuck_ticks'] ) ? (int) $existing_data[ $key ]['stuck_ticks'] : 0;
		$stuck_ticks      = ( $last_tick_offset === $cursor_offset ) ? ( $stuck_ticks + 1 ) : 0;
		if ( $stuck_ticks > 0 ) {
			// A killed close can leave libzip's temporary copy beside the part.
			self::tailwatch_remove_part_leftovers( $daily_backup_directory . "{$key}_part_{$current_zip_index}_{$zip_unique_id}.zip" );
		}
		// Only for the whole-part packer. Its close() cannot be interrupted and costs more the
		// larger the part, so shrinking is the one way out. The streaming writer PAUSES at the
		// deadline and its close is cheap, so a stuck streaming tick is not a part that is too
		// big - it is a tick killed outright, which a smaller part does not fix. Halving there
		// would permanently fragment the rest of the folder in response to something it
		// cannot mend.
		if ( $stuck_ticks > 0 && ! $use_stream && $zip_size_limit > self::PART_SIZE_FLOOR ) {
			// Closing a part is the one step the tick cannot interrupt, and its cost grows with
			// the part. Halve the size for the rest of this folder rather than retrying the same
			// part until the guard gives up; only a part that stalls at the floor fails the backup.
			$smaller = (int) max( self::PART_SIZE_FLOOR, floor( $zip_size_limit / 2 ) );
			$this->update_logs_records(
				sprintf(
					'Packing %2$s at %1$s of content per part did not finish within this host\'s time limit; continuing with %3$s per part',
					size_format( $zip_size_limit ),
					$label,
					size_format( $smaller )
				),
				'WARNING'
			);
			$existing_data[ $key ]['part_size'] = $smaller;
			$zip_size_limit                     = $smaller;
			$stuck_ticks                        = 0;
		} elseif ( $stuck_ticks >= 3 ) {
			$this->update_logs_records( "Backup of {$label} stalled at part {$current_zip_index}: a part holding " . size_format( $zip_size_limit ) . ' of content cannot be written within this host\'s time limit — aborting backup', 'ERROR' );
			$this->tailwatch_mark_backup_failed( "part_pack_no_progress:{$key}:{$current_zip_index}" );
			return;
		}
		$existing_data[ $key ]['last_tick_offset'] = $cursor_offset;
		$existing_data[ $key ]['stuck_ticks']      = $stuck_ticks;
		// Forced past the write throttle: a request killed outright skips shutdown handlers, and
		// a buffered write would take the evidence of the kill with it.
		$this->update_backup_data( $existing_data, false, true );

		// A line per part, throttled to about twenty for a folder with many.
		$progress_step = max( 1, (int) ceil( $num_zips / 20 ) );

		// Completion is decided by the manifest being exhausted ('eof'), NOT by reaching
		// the planned part count. Parts can be closed short when the tick budget runs
		// out, so a slow host legitimately produces more parts than the manifest's greedy
		// bin plan predicted — keying completion off $num_zips would mark the folder done
		// with files still unpacked.
		$manifest_eof = ! empty( $existing_data[ $key ]['manifest_eof'] );
		if ( 0 === $num_zips ) {
			$manifest_eof = true; // nothing to pack.
		}

		while ( ! $manifest_eof && ! TickBudget::spent( $pack_deadline ) ) {
			if ( ! $this->tailwatch_disk_guard( $zip_size_limit, "part {$current_zip_index} of {$label}" ) ) {
				return;
			}

			$zip_file_path = $daily_backup_directory . "{$key}_part_{$current_zip_index}_{$zip_unique_id}.zip";

			// Malware and integrity runs use the whole-part packer. They are partial rollback
			// snapshots that already drop oversize files, and their part size is an upload
			// contract with the scanner rather than ours to change.

			// A part the previous tick paused rather than closed. Its manifest slice began
			// earlier than the cursor now stands, and verification has to read from THERE,
			// so the start is remembered separately.
			$part_resume = ( $use_stream && ! empty( $existing_data[ $key ]['part_resume'] ) )
				? (array) $existing_data[ $key ]['part_resume']
				: array();
			$part_start  = ( ! empty( $part_resume ) && isset( $existing_data[ $key ]['part_start_offset'] ) )
				? (int) $existing_data[ $key ]['part_start_offset']
				: $cursor_offset;

			if ( $use_stream ) {
				$result = BackupManifest::tailwatch_stream_part_from_manifest(
					$manifest_path,
					$cursor_offset,
					$folder_path,
					$in_zip_prefix,
					$zip_file_path,
					$zip_size_limit,
					self::PART_ENTRY_LIMIT,
					$pack_deadline,
					$part_resume
				);
			} else {
				$result = $this->tailwatch_pack_part_from_manifest(
					$manifest_path,
					$cursor_offset,
					$folder_path,
					$in_zip_prefix,
					$zip_file_path,
					$zip_size_limit,
					$pack_deadline
				);
			}
			if ( false === $result && ! $this->tailwatch_run_is_current() ) {
				return self::LISTING_STOPPED;
			}
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
					$free_now = self::tailwatch_backup_free_space();
					$this->update_logs_records(
						"Failed to write a part for {$label} after {$pack_fail} attempts — aborting backup"
						. ( false !== $free_now && $free_now < self::DISK_RESERVE + $zip_size_limit ? ' (only ' . size_format( $free_now, 1 ) . ' of disk space is free)' : '' ),
						'ERROR'
					);
					$this->tailwatch_mark_backup_failed( "part_pack_failed:{$key}" );
					return;
				}
				break;
			}
			// Successful pack — clear the consecutive hard-failure counter.
			$existing_data[ $key ]['pack_fail_attempts'] = 0;

			// The part is not finished: the tick ran out and the archive was paused rather
			// than closed short. There is nothing to verify yet and the part index must not
			// move - the next tick reopens this same archive and carries on, which is what
			// stops a slow host producing more parts than a fast one.
			if ( ! empty( $result['part_open'] ) ) {
				$existing_data[ $key ]['part_resume']       = (array) $result['resume'];
				$existing_data[ $key ]['part_start_offset'] = (int) $part_start;
				$existing_data[ $key ]['manifest_offset']   = (int) $result['next_offset'];
				$cursor_offset                              = (int) $result['next_offset'];
				$this->update_backup_data( $existing_data );
				break;
			}

			// Closed. Nothing is carried into the next part.
			$existing_data[ $key ]['part_resume']       = array();
			$existing_data[ $key ]['part_start_offset'] = 0;

			// Verify the part against the manifest slice it was packed from, straight
			// after writing it. Structural validity alone does not prove completeness: a
			// part missing files still opens cleanly. $cursor_offset is still the START
			// of this part here — it is advanced below.
			// $part_start, not $cursor_offset: a part that spanned ticks began before the
			// cursor now stands, and verifying only the last tick's slice would compare the
			// whole archive against a fraction of what it holds.
			$verify = $this->tailwatch_verify_part_against_manifest(
				$zip_file_path,
				$manifest_path,
				$part_start,
				(int) $result['next_offset'],
				$in_zip_prefix,
				(int) $result['added']
			);

			if ( $this->tailwatch_is_valid_zip( $zip_file_path ) && $verify['ok'] ) {
				$batch[ "zip_{$current_zip_index}" ] = array(
					'size'      => filesize( $zip_file_path ),
					'path'      => $zip_file_path,
					'status'    => 'completed',
					'verified'  => true,
					'entries'   => (int) $verify['entries'],
					'digest'    => isset( $verify['digest'] ) ? (string) $verify['digest'] : '',
					'timestamp' => current_time( 'mysql' ),
				);

				if ( ! empty( $verify['drift'] ) ) {
					// Archived correctly, just at a different size than the manifest
					// recorded — a file that changed while the backup ran. Worth saying,
					// never worth failing a backup over.
					$this->update_logs_records(
						"{$verify['drift']} file(s) changed size while archiving part {$current_zip_index} of {$key}"
					);
				}

				if ( ! empty( $verify['missing'] ) ) {
					// Taken by the archive library and then left out when the part closed — on
					// Windows, a path past its 260-character limit. The part holds what it says
					// it holds, so it stands; the loss is named here and counted below.
					$left_out_sample = ! empty( $verify['missing_sample'] ) ? ' — ' . implode( ', ', $verify['missing_sample'] ) : '';
					$this->update_logs_records(
						"{$verify['missing']} file(s) could not be archived in part {$current_zip_index} of {$key}{$left_out_sample}",
						'WARNING'
					);
				}
			} else {
				$reason = '' !== $verify['error'] ? $verify['error'] : 'archive failed its validity check';
				$this->update_logs_records(
					"Part {$current_zip_index} of {$key} failed verification — {$reason}",
					'ERROR'
				);
				$batch[ "zip_{$current_zip_index}" ] = array(
					'status'       => 'failed',
					'verified'     => false,
					'verify_error' => $reason,
					'timestamp'    => current_time( 'mysql' ),
				);
			}
			// Accumulate what this part actually archived; finalize reconciles the running
			// totals against `expected_files`.
			// A file the library left out of the closed part is skipped, not archived, so
			// expected = archived + skipped still holds and the completion marker still
			// reports a backup that is missing files as not fully restorable.
			$left_out = isset( $verify['missing'] ) ? (int) $verify['missing'] : 0;

			$existing_data[ $key ]['packed_files']  = (int) ( $existing_data[ $key ]['packed_files'] ?? 0 ) + max( 0, (int) $result['added'] - $left_out );
			$existing_data[ $key ]['skipped_files'] = (int) ( $existing_data[ $key ]['skipped_files'] ?? 0 ) + (int) $result['skipped'] + $left_out;

			if ( ! empty( $result['skipped'] ) ) {
				$sample = ! empty( $result['skipped_sample'] ) ? ' — ' . implode( ', ', $result['skipped_sample'] ) : '';
				$this->update_logs_records(
					"Skipped {$result['skipped']} file(s) while archiving part {$current_zip_index} of {$key}{$sample}",
					'WARNING'
				);
			}

			$cursor_offset = (int) $result['next_offset'];
			$manifest_eof  = ! empty( $result['eof'] );
			++$current_zip_index;
			$existing_data[ $key ]['batch']           = $batch;
			$existing_data[ $key ]['current_zip']     = $current_zip_index;
			$existing_data[ $key ]['manifest_offset'] = $cursor_offset;
			$existing_data[ $key ]['manifest_eof']    = $manifest_eof;
			$this->update_backup_data( $existing_data );

			// Counted in files rather than parts. The file total is exact; the part total is
			// the planner's estimate, and it grows whenever a tick closes a part short.
			$done_parts = $current_zip_index - 1;
			if ( ! $manifest_eof && 0 === ( $done_parts % $progress_step ) ) {
				$this->update_logs_records(
					sprintf(
						'Archived %s of %s for %s (part %d)',
						number_format_i18n( (int) $existing_data[ $key ]['packed_files'] ),
						self::tailwatch_count( (int) ( $existing_data[ $key ]['expected_files'] ?? 0 ), 'file' ),
						$label,
						$done_parts
					)
				);
			}

			$cancel_pause = $this->tailwatch_backup_cancel_pause_data();
			if ( 'gone' === $cancel_pause['scan_state'] ) {
				return self::LISTING_STOPPED;
			}
			if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) {
				$paused = true;
				break;
			}
		}
		
		if ( $paused ) {
			$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );
			}
			$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
			}
			$cancel_pause = $this->tailwatch_backup_cancel_pause_data();
			if ( true === $cancel_pause['cron_running'] ) {
				$cancel_pause['cron_running'] = false;
				$this->update_backup_cancel_pause( $cancel_pause );
			}
			$this->update_backup_scan_state( $cancel_pause['scan_state'] );
			return;
		}
		
		// More manifest left — come back next tick. Keyed off eof, not the part count,
		// for the reason given where the loop starts. Not for a run stopped while its part was
		// packing: the loop's own stop check runs only after a part is closed. The pass loop
		// then takes the stop path.
		if ( ! $manifest_eof ) {
			if ( ! $this->tailwatch_run_may_continue() ) {
				return;
			}
			$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
			}
			wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
			return;
		}

		$this->tailwatch_update_scan_state_unschedule_cron();

		// If all ZIPs are created, mark the process as complete.
		$existing_data[ $key ]['path']      = $folder_path;
		$existing_data[ $key ]['completed'] = true;
		$this->update_backup_data( $existing_data );

		$archived = self::tailwatch_tally_parts( $existing_data[ $key ] );
		$this->update_logs_records(
			sprintf( 'Completed %s: %s in %s', $label, self::tailwatch_count( $archived['files'], 'file' ), self::tailwatch_count( $archived['parts'], 'part' ) ),
			'OK'
		);
	}

	/**
	 * The name a folder key is logged under. Every key is its directory's name except
	 * 'others', which holds whatever sits in the site root outside the WordPress folders.
	 *
	 * @param string $key Folder key.
	 * @return string
	 */
	public static function tailwatch_area_label( $key ) {
		return 'others' === $key ? 'site root files' : (string) $key;
	}

	/**
	 * The part size a new backup run uses.
	 *
	 * @param string   $process_run Run context (backup, malware, files_integrity).
	 * @param int|null $chosen_mb   The owner's setting, in MB.
	 * @return int Bytes.
	 */
	public static function tailwatch_part_size_for_run( $process_run, $chosen_mb ) {
		if ( in_array( $process_run, BackupMaintainController::SCAN_DRIVEN_PROCESS_RUNS, true ) ) {
			return self::FIXED_PART_SIZE;
		}

		$chosen_mb = (int) $chosen_mb;
		if ( ! in_array( $chosen_mb, self::PART_SIZE_CHOICES_MB, true ) ) {
			$chosen_mb = self::PART_SIZE_DEFAULT_MB;
		}

		return $chosen_mb * 1048576;
	}

	/**
	 * The part size a folder packs at right now: its own stepped-down size if it has one,
	 * otherwise the size the run started with.
	 *
	 * @param array  $existing_data Backup state.
	 * @param string $key           Folder key.
	 * @return int Bytes.
	 */
	private static function tailwatch_folder_part_size( array $existing_data, $key ) {
		if ( ! empty( $existing_data[ $key ]['part_size'] ) ) {
			return (int) $existing_data[ $key ]['part_size'];
		}
		if ( ! empty( $existing_data['part_size'] ) ) {
			return (int) $existing_data['part_size'];
		}

		return self::FIXED_PART_SIZE;
	}

	/**
	 * Delete the temporary copies libzip writes beside an archive while closing it.
	 *
	 * libzip closes by writing `<archive>.XXXXXX` and renaming it over the archive, so a close
	 * that is killed leaves that copy behind, as large as the part it was writing.
	 *
	 * @param string $zip_path The part being written.
	 * @return void
	 */
	private static function tailwatch_remove_part_leftovers( $zip_path ) {
		$prefix = basename( $zip_path ) . '.';
		foreach ( (array) glob( $zip_path . '.*' ) as $leftover ) {
			if ( 1 === preg_match( '/^[A-Za-z0-9]{6}$/', substr( basename( $leftover ), strlen( $prefix ) ) ) ) {
				wp_delete_file( $leftover );
			}
		}
	}

	/**
	 * A count with its noun, pluralised: "1 row", "2,717 rows".
	 *
	 * @param int    $count How many.
	 * @param string $noun  Singular noun.
	 * @return string
	 */
	public static function tailwatch_count( $count, $noun ) {
		$count = (int) $count;
		return number_format_i18n( $count ) . ' ' . $noun . ( 1 === $count ? '' : 's' );
	}

	/**
	 * What a folder is about to become, before its first part is written.
	 *
	 * The part count is an estimate. A part closes short when a tick runs out of time, so
	 * the real number can only match it or exceed it.
	 *
	 * @param string $label       Folder label.
	 * @param array  $area        The folder's backup state.
	 * @param array  $large_files Files over the part limit, each archived on its own.
	 * @param int    $part_size   Part size limit, in bytes.
	 * @return string
	 */
	private static function tailwatch_describe_archive_plan( $label, array $area, array $large_files, $part_size = self::FIXED_PART_SIZE ) {
		$files = (int) ( $area['expected_files'] ?? 0 ) + count( $large_files );
		$bytes = (int) ( $area['expected_bytes'] ?? 0 );
		foreach ( $large_files as $large ) {
			$bytes += is_array( $large ) ? (int) ( $large['size'] ?? 0 ) : 0;
		}
		$parts = (int) ( $area['num_zips'] ?? 0 ) + count( $large_files );

		return sprintf(
			'Archiving %s: %s%s, %s',
			$label,
			self::tailwatch_count( $files, 'file' ),
			$bytes > 0 ? ' (' . size_format( $bytes, 1 ) . ')' : '',
			// "of content", because the limit is what goes IN. The zip itself comes out
			// smaller, so quoting the figure as the file size overstates it - a 500 MB
			// part of a plugins folder lands nearer 200 MB.
			$parts > 1 ? 'about ' . self::tailwatch_count( $parts, 'part' ) . ', up to ' . size_format( (int) $part_size ) . ' of content each' : '1 part'
		);
	}

	/**
	 * What a folder's finished parts actually hold, read from the parts themselves.
	 *
	 * @param array $area The folder's backup state.
	 * @return array{files:int,parts:int,bytes:int}
	 */
	private static function tailwatch_tally_parts( array $area ) {
		$tally = array(
			'files' => (int) ( $area['packed_files'] ?? 0 ),
			'parts' => 0,
			'bytes' => 0,
		);

		foreach ( (array) ( $area['batch'] ?? array() ) as $name => $part ) {
			if ( ! is_array( $part ) || 'completed' !== ( $part['status'] ?? '' ) ) {
				continue;
			}
			++$tally['parts'];
			$tally['bytes'] += (int) ( $part['size'] ?? 0 );
			// A large file is a part of its own, and packed_files does not count it.
			if ( 0 === strpos( (string) $name, 'large_zip_' ) ) {
				++$tally['files'];
			}
		}

		return $tally;
	}

	/**
	 * One line describing everything a finished backup holds.
	 *
	 * @param array $existing_data Final backup state.
	 * @return string
	 */
	private static function tailwatch_describe_backup_totals( array $existing_data ) {
		$said   = array();
		$tables = isset( $existing_data['tables']['db_tables'] ) && is_array( $existing_data['tables']['db_tables'] ) ? $existing_data['tables']['db_tables'] : array();

		if ( ! empty( $tables ) ) {
			$rows = 0;
			foreach ( $tables as $table ) {
				$rows += is_array( $table ) ? (int) ( $table['rows_exported'] ?? 0 ) : 0;
			}
			$said[] = self::tailwatch_count( count( $tables ), 'table' ) . ' (' . self::tailwatch_count( $rows, 'row' ) . ')';
		}

		$files = 0;
		$parts = 0;
		$bytes = 0;
		foreach ( $existing_data as $area ) {
			if ( ! is_array( $area ) || ! isset( $area['expected_files'] ) ) {
				continue;
			}
			$tally  = self::tailwatch_tally_parts( $area );
			$files += $tally['files'];
			$parts += $tally['parts'];
			$bytes += $tally['bytes'];
		}

		if ( $parts > 0 ) {
			$said[] = self::tailwatch_count( $files, 'file' ) . ' in ' . self::tailwatch_count( $parts, 'archive part' ) . ' (' . size_format( $bytes, 1 ) . ')';
		}

		return empty( $said ) ? 'nothing needed archiving' : implode( ', ', $said );
	}

	/**
	 * Updates scan state and unschedules backup-related crons.
	 *
	 */
	public function tailwatch_update_scan_state_unschedule_cron() {
		$cancel_pause = $this->tailwatch_backup_cancel_pause_data();
		if ( 'gone' === $cancel_pause['scan_state'] ) {
			return;
		}
		if ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) {
			$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );
			}

			$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
			}

			if ( true === $cancel_pause['cron_running'] ) {
				$cancel_pause['cron_running'] = false;
				$this->update_backup_cancel_pause( $cancel_pause );
			}
			$this->update_backup_scan_state( $cancel_pause['scan_state'] );
			return;
		} else {
			$timestamp = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_verify_backup_cron_hook' );
			}
			wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
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
		$log_file_path        = TAILWATCH_LOGS_DIRECTORY . '/backup_zip_file_log.txt';

		// Log if file is >50MB to confirm processing.
		if ( $file_size > 52428800 ) {
			$wp_filesystem = FilesystemService::get_filesystem();
			if ( $wp_filesystem ) {
				$existing = $wp_filesystem->get_contents( $log_file_path );
				$new_line = current_time( 'mysql' ) . " - Processing large single file (>50MB): {$file_path} (size: {$file_size} bytes)\n";
				$wp_filesystem->put_contents( $log_file_path, ( $existing ? $existing . $new_line : $new_line ), FS_CHMOD_FILE );
			}
		}

		// The same map the multi-file packer uses, so single-file parts and multi-file parts
		// always agree about where a file belongs.
		$path_prefix = $this->tailwatch_path_prefix_for_key( $key, $source );

		// A single-file part carries ONE file, and the packer that writes it has only the
		// file's own name to work with — so the folders between the area root and the file
		// travel in the prefix, the one thing that reaches the entry name. Without them a
		// file in uploads/2026/01/ would restore into uploads/, and two files of the same
		// name in different folders would overwrite each other.
		$relative_dir = trim(
			str_replace( chr( 92 ), '/', (string) substr( (string) $file_path, strlen( untrailingslashit( (string) $source ) ) ) ),
			'/'
		);
		$relative_dir = ltrim( str_replace( chr( 92 ), '/', dirname( $relative_dir ) ), '/' );

		if ( '' !== $relative_dir && '.' !== $relative_dir ) {
			$path_prefix = '' === $path_prefix ? $relative_dir : $path_prefix . '/' . $relative_dir;
		}

		// Call global ZIP function, skipping 50MB limit for single file.
		$create_zip = new ZipCreation();
		return $create_zip->tailwatch_create_zip_global(
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
	 * Listener for the generic 'tailwatch_recovery_process_failed' action (fired by
	 * RecoveryService when a process exhausts its retries). Only acts on backups.
	 *
	 * @param array $process_data The failed process row (must carry process_type).
	 * @return void
	 */
	public function tailwatch_on_recovery_failed( $process_data ) {
		if ( empty( $process_data['process_type'] ) || 'backup' !== $process_data['process_type'] ) {
			return;
		}
		// Only the run whose own process gave up. A cancelled run's process can run out of
		// retries after the next run has started, and that run has not failed.
		$current   = $this->tailwatch_backup_cancel_pause_data();
		$failed_id = isset( $process_data['process_id'] ) ? (string) $process_data['process_id'] : '';
		if ( '' !== $failed_id && is_array( $current ) && ! empty( $current['process_id'] ) && (string) $current['process_id'] !== $failed_id ) {
			return;
		}
		$this->tailwatch_mark_backup_failed( 'recovery_max_retries' );
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
	public function tailwatch_mark_backup_failed( $reason = '' ) {
		$cancel_pause = $this->tailwatch_backup_cancel_pause_data();
		if ( empty( $cancel_pause ) || ! isset( $cancel_pause['scan_state'] ) || 'in-progress' !== $cancel_pause['scan_state'] ) {
			return;
		}

		// Stop the worker chain + watchdog + DB sub-step crons.
		foreach ( array( 'tailwatch_backup_daily_scan', 'tailwatch_verify_backup_cron_hook', 'tailwatch_create_db_backup_cron', 'tailwatch_scan_db_tables_cron' ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		$cancel_pause['scan_state']   = 'failed';
		$cancel_pause['cron_running'] = false;
		$this->update_backup_cancel_pause( $cancel_pause );

		$scan_backp = $this->tailwatch_get_scan_backup_data();
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
		$impact      = 0 === strpos( (string) $reason, 'disk_space' )
			? 'The server is running out of disk space, so the backup stopped before filling it. Your previous backups are still safe — free up space or choose a smaller archive part size, then try again.'
			: 'The backup stopped partway through and could not be completed. Your previous backups are still safe — please try again.';
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
	private function tailwatch_is_valid_zip( $path ) {
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
	 * @see BackupReconciler::tailwatch_collect_failed_parts()
	 *
	 * @param array $existing_data Backup state.
	 * @return array
	 */
	private function tailwatch_collect_failed_parts( array $existing_data ) {
		return BackupReconciler::tailwatch_collect_failed_parts( $existing_data );
	}

	/**
	 * @see BackupReconciler::tailwatch_write_completion_marker()
	 *
	 * @param array $existing_data Final backup state.
	 * @return void
	 */
	private function tailwatch_write_completion_marker( array $existing_data ) {
		BackupReconciler::tailwatch_write_completion_marker( $existing_data );
	}

	/**
	 * Apply the retention limit once this backup counts towards it.
	 *
	 * Pruning only at the start of a backup tick cannot see the run being made, so a
	 * limit of N would leave N + 1 backups, and a database-only run, which never reaches
	 * that tick, would never prune at all.
	 *
	 * @return void
	 */
	public function tailwatch_prune_after_completion() {
		( new BackupMaintainController() )->maintain_backups( 'default_backup_scan', 'scan_backp' );
	}

	/**
	 * Whether the newest backup record is the cancelled run, or a run older than it.
	 *
	 * Folder names begin with the date and time a run started, so they sort in that order.
	 *
	 * @param mixed $current     Newest cancel/pause record.
	 * @param mixed $last_cancel What the last cancel recorded, or false.
	 * @return bool
	 */
	public static function tailwatch_is_cancelled_run( $current, $last_cancel ) {
		if ( ! is_array( $last_cancel ) || empty( $last_cancel['folderDate'] ) ) {
			return false;
		}

		$folder = is_array( $current ) && isset( $current['folderDate'] ) ? (string) $current['folderDate'] : '';

		return '' === $folder || strcmp( $folder, (string) $last_cancel['folderDate'] ) <= 0;
	}

	/**
	 * @see BackupReconciler::tailwatch_read_completion_marker()
	 *
	 * @param string $folder_date Backup folder name.
	 * @return array|null
	 */
	public function tailwatch_read_completion_marker( $folder_date ) {
		return BackupReconciler::tailwatch_read_completion_marker( $folder_date );
	}

	/**
	 * @see BackupReconciler::tailwatch_reconcile_file_counts()
	 *
	 * @param array $existing_data Backup state.
	 * @return array{missing:int,unaccounted:int,detail:array}
	 */
	private function tailwatch_reconcile_file_counts( array $existing_data ) {
		return BackupReconciler::tailwatch_reconcile_file_counts( $existing_data );
	}

	/**
	 * Whether this run produces a database dump, and so must not finish without one.
	 *
	 * A type that holds a database does. One nothing describes does not: an unknown type
	 * reaching finalize with no database should not be held open for ever.
	 *
	 * @param array $existing_data The run's state row.
	 * @return bool
	 */
	public static function tailwatch_run_expects_database( array $existing_data ) {
		$type  = isset( $existing_data['backupType'] ) ? (string) $existing_data['backupType'] : '';
		$parts = self::tailwatch_backup_type_parts( $type );

		$with_database = apply_filters(
			'tailwatch_backup_types_with_database',
			( null !== $parts && $parts['database'] ) ? array( $type ) : array(),
			$type,
			$existing_data
		);

		return in_array( $type, (array) $with_database, true );
	}

	/**
	 * Which halves a backup type holds, or null for a type nothing describes. This
	 * plugin's own type, Complete Backup, holds both; a type an add-on offers is described
	 * by that add-on.
	 *
	 * @param string $backup_type Backup type.
	 * @return array{files: bool, database: bool}|null
	 */
	public static function tailwatch_backup_type_parts( $backup_type ) {
		$default = null;
		if ( 'Complete Backup' === $backup_type ) {
			$default = array(
				'files'    => true,
				'database' => true,
			);
		}
		$parts = apply_filters( 'tailwatch_backup_type_parts', $default, (string) $backup_type );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		return array(
			'files'    => ! empty( $parts['files'] ),
			'database' => ! empty( $parts['database'] ),
		);
	}

	/**
	 * Checks if all folders are completed and finalizes backup (mark completed, notification, process manager).
	 *
	 * @param array    $existing_data       Backup state (by reference).
	 * @param mixed    $feature_controller  DB model instance.
	 * @param string   $tailwatch_key            Option key.
	 * @param string   $option              Option name.
	 * @param int|null $process_id        Process ID for process manager.
	 */
	public function check_and_finalize_backup( &$existing_data, $feature_controller, $tailwatch_key, $option, $process_id ) {
		$all_completed = true;
		$cancel_pause  = $this->tailwatch_backup_cancel_pause_data();

		// A run that was replaced must not finish: its "completed" would name a folder that is
		// gone, and the prune that follows would count it as a backup.
		if ( 'gone' === ( $cancel_pause['scan_state'] ?? '' )
			|| ( isset( $cancel_pause['folderDate'], $existing_data['folderDate'] ) && (string) $cancel_pause['folderDate'] !== (string) $existing_data['folderDate'] ) ) {
			return;
		}

		// Only the entries that represent WORK. The state array also carries bookkeeping
		// written at the top level, and an array there has no 'completed' key — so it would
		// read as an unfinished folder and block finalize for ever. 'deleted' stays listed
		// because the state of an older run can still carry it.
		//
		// Excluded by name rather than by testing for a positive marker: an area entry
		// exists from its first tick (cron_start) but does not gain expected_files until
		// its manifest is built, so a positive test would skip an area mid-flight and
		// finalize EARLY — a far worse failure than finalizing late.
		$bookkeeping = array( 'userData', 'deleted', 'failed_parts' );

		foreach ( $existing_data as $folder => $data ) {

			if ( in_array( $folder, $bookkeeping, true ) ) {
				continue;
			}

			if ( is_array( $data ) && ( ! isset( $data['completed'] ) || false === $data['completed'] ) ) {
				$all_completed = false;
				break;
			}
		}

		// The database phase keeps its state under 'tables', and the loop above can only
		// gate on keys that EXIST - so until that phase has written anything there is
		// nothing holding finalize back. A files tick would then finish the run and write a
		// completion marker built from this row, which lists no database at all; the dumps
		// land on disk afterwards and are invisible to the manifest, the restore and the
		// backup's own file listing.
		//
		// A database phase that never starts is not stranded by this: the recovery path in
		// tailwatch_execute_cron_if_failed() reschedules the scan cron precisely when
		// 'tables' is unset, and the stuck-process detector reports a run that stops moving.
		if ( $all_completed && self::tailwatch_run_expects_database( $existing_data ) ) {
			if ( empty( $existing_data['tables'] ) || empty( $existing_data['tables']['completed'] ) ) {
				$all_completed = false;
			}
		}

		if ( true === $all_completed ) {

			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				// No action when scan state is cancel or pause.
			}

			// Find parts that permanently failed (hit the 3-attempt cap and were marked
			// status='failed') so an incomplete backup is reported honestly instead of as
			// a clean success. scan_state STAYS 'completed' (its enum is consumed by many
			// hardcoded sites; a new value would silently break the status reader, list
			// filter, and cleanup) — the truth lives in these separate flags and the
			// notification text.
			$failed_parts = $this->tailwatch_collect_failed_parts( $existing_data );

			// Reconcile intended vs archived file counts. A part can close as a
			// structurally valid zip while silently containing fewer files than intended,
			// so part status alone cannot tell us the backup is complete.
			$reconciled   = $this->tailwatch_reconcile_file_counts( $existing_data );
			$had_failures = ! empty( $failed_parts ) || $reconciled['missing'] > 0 || $reconciled['unaccounted'] > 0;

			if ( ! empty( $reconciled['detail'] ) ) {
				$this->update_logs_records(
					'File count reconciliation found gaps — ' . implode( '; ', $reconciled['detail'] ),
					'WARNING'
				);
			}

			$existing_data['had_failures']      = $had_failures;
			$existing_data['failed_parts']      = $failed_parts;
			$existing_data['files_missing']     = (int) $reconciled['missing'];
			$existing_data['files_unaccounted'] = (int) $reconciled['unaccounted'];

			// Said after the checks rather than before them, so the last line of the log
			// agrees with the notification instead of announcing success ahead of a warning.
			$totals = self::tailwatch_describe_backup_totals( $existing_data );
			if ( $had_failures ) {
				$problems = array();
				if ( ! empty( $failed_parts ) ) {
					$problems[] = self::tailwatch_count( count( $failed_parts ), 'part' ) . ' could not be archived';
				}
				$lost = (int) $reconciled['missing'] + (int) $reconciled['unaccounted'];
				if ( $lost > 0 ) {
					$problems[] = self::tailwatch_count( $lost, 'file' ) . ' could not be included';
				}
				$this->update_logs_records( 'Backup finished, but ' . implode( ' and ', $problems ) . ', so it may be incomplete — take a new backup. It holds ' . $totals, 'WARNING' );
			} else {
				$this->update_logs_records( 'Backup complete: ' . $totals, 'SUCCESS' );
			}
			RetentionHold::stamp( $existing_data, ( new BackupMaintainController() )->tailwatch_retention_count() );
			$existing_data['completed']         = true;
			$existing_data['scan_state']        = 'completed';
			// Forced past the write throttle: retention reads this row straight after, and a
			// tick that packed its last folder moments ago would otherwise still show it unfinished.
			$this->update_backup_data( $existing_data, false, true );

			// Written only once the run has reached this point, so its presence on disk
			// is the proof that the backup set is finished — the folder alone can then be
			// judged without consulting the state row.
			$this->tailwatch_write_completion_marker( $existing_data );

			$this->tailwatch_prune_after_completion();

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

			// Before the notification and the add-on hook below: the push can wait on the network
			// for seconds, and the live log must not say "still running" while the status row
			// already says "completed".
			$live_logs = new LiveLogsController();
			$live_logs->tailwatch_live_logs_completed( true, $this->tailwatch_get_log_file_path() );

			// Log backup completion (also triggers notification via NotificationActions).
			// Routing stays on action 'backup_complete' (→ backup_push_notification) in
			// BOTH cases; only the human text + meta change when parts permanently
			// failed, so the user is told the truth instead of a false "ready".
			$complete_backup_type = isset( $existing_data['backupType'] ) ? $existing_data['backupType'] : 'website';
			if ( $had_failures ) {
				$failed_count    = count( $failed_parts );
				$missing_files   = (int) $reconciled['missing'];
				$unaccounted     = (int) $reconciled['unaccounted'];
				$lost_files      = $missing_files + $unaccounted;

				// A backup can be incomplete for two independent reasons, and the message
				// has to say which — reporting "0 part(s) could not be archived" when whole
				// files went missing would pass a lossy backup off as sound.
				$reasons = array();
				if ( $failed_count > 0 ) {
					$reasons[] = "{$failed_count} part(s) could not be archived";
				}
				if ( $lost_files > 0 ) {
					$reasons[] = "{$lost_files} file(s) could not be included";
				}

				$partial_impact = 'Some files could not be archived, so this backup may be incomplete. We recommend running a new backup.';
				Log::warning(
					"Your {$complete_backup_type} finished, but " . implode( ' and ', $reasons ) . ' — your backup may be incomplete.',
					array(
						'feature'   => 'backup',
						'action'    => 'backup_complete',
						'title'     => 'Backup completed with warnings',
						'meta_data' => array(
							'feature'           => 'Backup Vault',
							'event'             => 'Completed',
							'impact'            => $partial_impact,
							'backup_type'       => $complete_backup_type,
							'partial'           => true,
							'failed_count'      => $failed_count,
							'files_missing'     => $missing_files,
							'files_unaccounted' => $unaccounted,
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

			wp_delete_file( TAILWATCH_LOGS_DIRECTORY . '/backup_zip_file_log.txt' );

			// Allow pro plugin (MalwareScanner) to handle backup completion notification.
			do_action( 'tailwatch_backup_completed_notification', $existing_data );

			$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
			if ( $timestamp_backup_status ) {
				wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
			}

			Log::info(
				'Feature status resolved',
				array(
					'feature' => 'backup',
					'action'  => 'update_errors_feature_status',
				)
			);

			wp_clear_scheduled_hook( 'tailwatch_backup_daily_scan' );
		}
	}

	/**
	 * Returns live logs for backup feature (delegates to LiveLogsController).
	 *
	 * @param string $post_data JSON-encoded POST data.
	 * @return array Response with data or error.
	 */
	public function tailwatch_get_live_logs( $post_data ) {
		try {
			$backup_data  = $this->tailwatch_backup_cancel_pause_data();
			$feature_type = 'create_backup';

			$params = array(
				'backup_size' => $this->tailwatch_get_specfic_backup_size( $backup_data ),
			);

			$livelogs = new LiveLogsController();
			$result   = $livelogs->tailwatch_import_live_logs( $post_data, $this->tailwatch_get_log_file_path(), $backup_data, $feature_type, $params );

			// The run is over once its status row says so. The live log's own flag is written a
			// step later, and is missing altogether if that request died in between.
			if ( is_array( $result ) && isset( $result['scan_state'] ) && 'completed' === $result['scan_state'] ) {
				$result['is_completed'] = true;
				$result['progress']     = 100;
			}

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
	public function tailwatch_get_specfic_backup_size( $backup_data ) {
		$premium_size = apply_filters( 'tailwatch_premium_backup_size_calculation', false, $backup_data );
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
	public function tailwatch_instant_backup_scanner( $post_data ) {
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
				$backup_data       = $get_backup_option->tailwatch_get_backup_settings();

				// Allow premium plugin to add backup types that support optimization.
				$optimization_supported_types = apply_filters( 'tailwatch_backup_types_supporting_optimization', array( 'Complete Backup' ), $backup_data );

				if ( true === $backup_data['optimizeDatabase'] && in_array( $backup_data['backupType'], $optimization_supported_types, true ) ) {
					$database_clean = new DatabaseOptimizerController();
					$get_options    = $database_clean->tailwatch_db_optimize_options();

					if (
						empty( $get_options ) || ! isset( $get_options['field_1']['options']['option']['selected'] ) ||
						false === $get_options['field_1']['options']['option']['selected']
					) {
						$optimize_skip = false;
					} else {
						$optimize_skip     = false;
						$database_optimize = true;
						$started           = $this->tailwatch_start_backup_creation( $database_optimize, $optimize_skip, 'on-demand' );
					}
				} else {
					$started = $this->tailwatch_start_backup_creation( $database_optimize, $optimize_skip, 'on-demand' );
				}

				if ( isset( $started ) && self::tailwatch_start_was_refused( $started ) ) {
					return $started;
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
	 * point (`tailwatch_backup_api_cron_start`) so the recurring cron path
	 * (`BackupCronJob::execute`) honours the same "Optimize Database Before
	 * Backup" setting.
	 *
	 * Gates (all must pass to return `true`):
	 *   - `optimizeDatabase` is true in the backup settings (the
	 *     opt-in toggle for the backup feature).
	 *   - The selected `backupType` is in the list of types that
	 *     support optimization (Complete Backup by default, with
	 *     companion plugins free to add their own types via the
	 *     `tailwatch_backup_types_supporting_optimization` filter).
	 *   - The Database Optimizer feature itself is configured AND
	 *     enabled (no point running an optimizer the user has
	 *     turned off elsewhere).
	 *
	 * @param array $backup_data Settings array from
	 *                           {@see BackupMaintainController::tailwatch_get_backup_settings()}.
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
			'tailwatch_backup_types_supporting_optimization',
			array( 'Complete Backup' ),
			$backup_data
		);

		$backup_type = isset( $backup_data['backupType'] ) ? $backup_data['backupType'] : '';
		if ( ! in_array( $backup_type, $supported_types, true ) ) {
			return false;
		}

		$db_optimizer = new DatabaseOptimizerController();
		$options      = $db_optimizer->tailwatch_db_optimize_options();

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
	public function tailwatch_start_backup_creation( $database_optimize, $optimize_skip, $scan_type = 'automatically' ) {
		$db_optimize  = true === $database_optimize && false === $optimize_skip;
		$current_user = wp_get_current_user();
		$user_name    = $current_user->user_login;
		$user_role    = implode( ', ', $current_user->roles );

		// Restart the schedule's clock, so the next automatic backup is a full interval after
		// this one. Re-armed straight away: the job's own re-arm runs only on admin_init, so a
		// site nobody opens wp-admin on would lose its schedule after the first run. A scheduled run
		// firing while this one is still going is refused by the process guard in the job.
		$backup_job = CronJobManager::get_instance()->get_cron_job( 'backup' );
		if ( null !== $backup_job ) {
			$backup_job->unschedule();
			$backup_job->maybe_schedule( true );
		}

		return $this->tailwatch_calculate_folder_sizes( $user_name, $user_role, $scan_type, 'backup', $db_optimize );
	}

	/**
	 * Whether a start answer is a refusal to begin: no room on the disk (507), or another
	 * backup starting or running (409).
	 *
	 * @param mixed $started What a start returned.
	 * @return bool
	 */
	public static function tailwatch_start_was_refused( $started ) {
		return is_array( $started ) && isset( $started['code'] ) && in_array( (int) $started['code'], array( 507, 409 ), true );
	}

	/**
	 * The running processes a backup of this kind belongs to: the scan that backs the site up
	 * before it looks at it.
	 *
	 * @param string $process_run The backup's process_run.
	 * @return array<string>
	 */
	private static function tailwatch_runs_inside_of( $process_run ) {
		return in_array( (string) $process_run, BackupMaintainController::SCAN_DRIVEN_PROCESS_RUNS, true ) ? array( 'malware_scan', 'files_integrity' ) : array();
	}

	/**
	 * Parses POST data and starts backup with or without DB optimization based on settings.
	 *
	 * @param string $post_data JSON-encoded POST data.
	 * @return array Response with code and message.
	 */
	public function tailwatch_start_backup_with_optimize_or_not( $post_data ) {
		try {
			// The same refusal as tailwatch_instant_backup_scanner(): this is the other way in.
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
				$optimizer_enable = $this->tailwatch_enable_database_optimizer();
				if ( self::tailwatch_start_was_refused( $optimizer_enable ) ) {
					return $optimizer_enable;
				}

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
				$started = $this->tailwatch_start_backup_creation( false, true, 'on-demand' );
				if ( self::tailwatch_start_was_refused( $started ) ) {
					return $started;
				}
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

	public function tailwatch_enable_database_optimizer() {
		$recommended_features = new RecommendedFeaturesController();
		$feature_data         = $recommended_features->tailwatch_get_feature_id( 'default_database_optimizer' );
		if ( ! $feature_data ) {
			return false;
		}

		$id_is     = $feature_data['id'];
		$is_active = $feature_data['is_active'];

		$parent_status = $recommended_features->tailwatch_update_parent_active_status( $id_is, $is_active );

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
			$is_updated          = $features_controller->tailwatch_update_inner_feature( null, $options_data );

			$started = $this->tailwatch_start_backup_creation( true, false, 'on-demand' );

			return self::tailwatch_start_was_refused( $started ) ? $started : true;
		}

		return false;
	}

	/**
	 * Verifies backup status (e.g. for API) and returns backup state/data.
	 *
	 * @return array Response with backup status.
	 */
	public function tailwatch_verify_backup_status() {
		try {
			$existing_data = $this->tailwatch_backup_cancel_pause_data();
			$backup_data   = $this->tailwatch_get_scan_backup_data();

			$download_status        = apply_filters( 'tailwatch_get_backup_downloading_status', array(), false );
			$backup_download_status = false;
			if ( ! empty( $download_status ) && is_array( $download_status ) ) {
				$backup_download_status = $download_status['in_progress'] ?? false;
			}

			// Removing a cancelled run's rows leaves the previous run's record as the newest,
			// which would otherwise be reported as this backup's success.
			if ( self::tailwatch_is_cancelled_run( $existing_data, get_option( self::LAST_CANCEL_OPTION ) ) ) {
				return array(
					'is_completed'       => true,
					'scan_state'         => 'cancel',
					'progress'           => 0,
					'message'            => __( 'Backup was cancelled.', 'tailwatch' ),
					'backup_downloading' => $backup_download_status,
					'code'               => 200,
				);
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

			// In progress over a backup whose own row says it finished. A stale write can leave
			// this, and nothing runs after the finaliser to correct it, so the screen would spin
			// at 100% and the process gate would take the backup for running. The finaliser
			// writes that row, so it is the one to believe.
			if ( 'in-progress' === $scan_state
				&& is_array( $backup_data )
				&& isset( $existing_data['folderDate'], $backup_data['folderDate'] )
				&& $existing_data['folderDate'] === $backup_data['folderDate']
				&& true === ( $backup_data['completed'] ?? false )
				&& 'completed' === ( $backup_data['scan_state'] ?? null ) ) {
				$existing_data['scan_state'] = 'completed';
				$this->update_backup_cancel_pause( $existing_data );
				$scan_state = 'completed';
			}

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

	public function tailwatch_resume_backup() {
		try {
			$refused = $this->tailwatch_refuse_resume_unless_paused();
			if ( null !== $refused ) {
				return $refused;
			}

			$existing_data = $this->tailwatch_get_scan_backup_data();
			if ( ! empty( $existing_data ) && ! empty( $existing_data['backupType'] ) ) {
				// Resumed only while nothing that cannot run beside a backup is running or paused.
				$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'backup', self::tailwatch_runs_inside_of( $existing_data['process_run'] ?? '' ) );
				if ( null !== $blocked ) {
					return $blocked;
				}

				// Resume is the one deliberate stop -> in-progress transition; force past the
				// stop-state guard that otherwise keeps a pause/cancel from being reverted.
				$this->update_backup_scan_state( 'in-progress', true );
				$cancel_pause               = $this->tailwatch_backup_cancel_pause_data();
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
						return $db_optimizer->tailwatch_resume_db_optimize();
					}
				}

				/**
				 * Filter: tailwatch_premium_backup_resume
				 *
				 * @param bool  $handled      Whether premium plugin handled the resume (default: false).
				 * @param array $backup_data  Complete backup data for resuming.
				 * @return bool True if handled by premium plugin, false if not handled.
				 */
				$premium_resume = apply_filters( 'tailwatch_premium_backup_resume', false, $existing_data );
				if ( false === $premium_resume ) {
					switch ( $existing_data['backupType'] ) {
						case 'Complete Backup':
							// Resume the phase the run was actually in. Until the table-scan has built
							// the list, resume the SCAN cron; jumping straight to the dump would find an
							// empty list and mark the DB "complete" — silently skipping it. Mirrors
							// tailwatch_execute_cron_if_failed().
							if ( ! isset( $existing_data['tables'] ) ) {
								if ( ! wp_next_scheduled( 'tailwatch_scan_db_tables_cron' ) ) {
									wp_schedule_single_event( time(), 'tailwatch_scan_db_tables_cron' );
								}
							} elseif ( ! wp_next_scheduled( 'tailwatch_create_db_backup_cron' ) ) {
								wp_schedule_single_event( time(), 'tailwatch_create_db_backup_cron' );
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

	/**
	 * Why a resume is refused, or null when the newest run is a paused run of this site's own.
	 *
	 * Resume writes in-progress past the guard that keeps a finished run whole, so it may act
	 * only on a run that is really paused: not on one that finished, was cancelled or failed, and
	 * not on a migrated backup's catalogue entry, which is a record rather than a run.
	 *
	 * @return array|null
	 */
	private function tailwatch_refuse_resume_unless_paused() {
		$status = $this->tailwatch_backup_cancel_pause_data();
		$state  = ( is_array( $status ) && isset( $status['scan_state'] ) ) ? (string) $status['scan_state'] : '';
		$run    = $this->tailwatch_backup_run_state();
		$done   = is_array( $run ) && ( true === ( $run['completed'] ?? false ) || 'completed' === ( $run['scan_state'] ?? '' ) );

		if ( 'pause' === $state && is_array( $run ) && ! $done
			&& (string) ( $status['folderDate'] ?? '' ) === (string) ( $run['folderDate'] ?? '' ) ) {
			return null;
		}

		if ( 'in-progress' === $state && ! $done ) {
			$message = __( 'This backup is already running.', 'tailwatch' );
		} elseif ( 'completed' === $state || $done ) {
			$message = __( 'This backup has already finished, so there is nothing to resume.', 'tailwatch' );
		} else {
			$message = __( 'There is no paused backup to resume.', 'tailwatch' );
		}

		return array(
			'data'    => array(),
			'message' => $message,
			'code'    => 409,
		);
	}

	public function tailwatch_pause_backup_creation( $post_data ) {
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

				$cancel_pause  = $this->tailwatch_backup_cancel_pause_data();
				$current_state = ( is_array( $cancel_pause ) && isset( $cancel_pause['scan_state'] ) ) ? $cancel_pause['scan_state'] : '';
				// A click that lands after the backup finished must not relabel it; Pause only means
				// something to a backup that is running. Cancelling a paused or failed one still
				// discards it, as the screen offers.
				if ( 'completed' === $current_state || ( 'pause' === $data['scan_state'] && 'in-progress' !== $current_state ) ) {
					return array(
						'data'    => array(),
						'message' => ( 'completed' === $current_state )
							? __( 'This run has already finished, so there is nothing to pause or cancel.', 'tailwatch' )
							: __( 'Only a run that is in progress can be paused.', 'tailwatch' ),
						'code'    => 409,
					);
				}
				$timestamp_backup_status = wp_next_scheduled( 'tailwatch_verify_backup_cron_hook' );
				if ( $timestamp_backup_status ) {
					wp_unschedule_event( $timestamp_backup_status, 'tailwatch_verify_backup_cron_hook' );
				}

				$cancel_pause['scan_state'] = $data['scan_state'];
				$this->update_backup_cancel_pause( $cancel_pause );
				$this->update_backup_scan_state( $data['scan_state'] );

				$backup_data = $this->tailwatch_get_scan_backup_data();
				if ( $backup_data['optimize_completed'] === false ) {
					$database_clean = new DatabaseOptimizerController();
					$database_clean->tailwatch_pause_db_optimize( wp_json_encode( array( 'scan_state' => $data['scan_state'] ) ) );
				}

				$timestamp = wp_next_scheduled( 'tailwatch_backup_daily_scan' );
				if ( true ) {
					wp_unschedule_event( $timestamp, 'tailwatch_backup_daily_scan' );

					// Handle process state based on cancel/pause
					$process_id = $cancel_pause['process_id'] ?? null;

					if ( 'cancel' === $data['scan_state'] ) {
						// Mark process as failed when cancelled
						if ( $process_id ) {
							$this->process_manager->mark_failed( $process_id, 'Backup cancelled by user' );
						}

						// Outlives the cleanup scheduled below, which removes this run's rows.
						update_option(
							self::LAST_CANCEL_OPTION,
							array(
								'folderDate' => isset( $cancel_pause['folderDate'] ) ? (string) $cancel_pause['folderDate'] : '',
								'time'       => time(),
							),
							false
						);

						$message = __( 'Backup cancel successfully, and previous backup files have been deleted.', 'tailwatch' );
						Log::info(
							'Backup cancelled successfully',
							array(
								'feature' => 'backup',
								'action'  => 'backup_cancel',
							)
						);

						// Read afresh: when a cron runner took the files tick a moment ago, the unschedule
						// above changed nothing stored, WordPress kept its cached copy, and writing that
						// copy back would put the cancelled run's tick back.
						self::tailwatch_forget_cached_schedule();

						// Named, so the cleanup cannot take a backup started after this cancel.
						wp_schedule_single_event(
							time() + 5,
							'tailwatch_delete_backup_files_entry',
							array( isset( $cancel_pause['folderDate'] ) ? (string) $cancel_pause['folderDate'] : '' )
						);
					} elseif ( 'pause' === $data['scan_state'] ) {
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

	/**
	 * Remove an unfinished backup's folder and rows.
	 *
	 * Called with no folder at the start of a backup, to clear the run before it. Called by
	 * the cancel's cleanup event with the cancelled run's folder: that event fires on the next
	 * WP-Cron run, which can be after a new backup has started, and the rows it would read then
	 * are the new backup's - so it acts only while they are still the cancelled run's.
	 *
	 * @param string $cancelled_folder The cancelled run's folder, from the cancel's event.
	 * @return array
	 */
	public function tailwatch_delete_files_entry_with_cron( $cancelled_folder = '' ) {

		$feature_controller = new DBModel();
		$key                = 'default_backup_scan';
		$existing_data      = $this->tailwatch_get_scan_backup_data();
		$backup_progress    = $this->tailwatch_backup_cancel_pause_data();

		$is_folder_deleted = array(
			'folder_deleted' => false,
			'message'        => __( 'No action taken.', 'tailwatch' ),
			'code'           => 400,
		);

		if ( is_string( $cancelled_folder ) && '' !== $cancelled_folder
			&& ( ! is_array( $backup_progress ) || ( $backup_progress['folderDate'] ?? '' ) !== $cancelled_folder ) ) {
			return $is_folder_deleted;
		}

		if (
			! empty( $existing_data )
			&& is_array( $backup_progress )
			&& isset( $backup_progress['scan_state'] )
			&& in_array( $backup_progress['scan_state'], array( 'in-progress', 'pause', 'cancel' ), true )
			&& false === $existing_data['completed']
		) {
			if ( isset( $backup_progress['folderDate'] ) && $existing_data['folderDate'] === $backup_progress['folderDate'] ) {
				$is_folder_deleted = $this->tailwatch_cleanup_incomplete_backup( $existing_data );
				if ( isset( $is_folder_deleted['folder_deleted'] ) && true === $is_folder_deleted['folder_deleted'] ) {
					// That run's own rows: a run inserted since the check above is newer, and a
					// delete of "the newest row" would take its rows instead.
					$delete_entries = array( 'scan_backp', 'backup_cancel_pause' );

					foreach ( $delete_entries as $delete_entry ) {
						$feature_controller->delete_run_row( $key, $delete_entry, (string) $existing_data['folderDate'] );
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

	public function tailwatch_delete_backup_entries( $delete_entries ) {
		foreach ( $delete_entries as $delete_entry ) {
			$this->tailwatch_delete_backup_entry( $delete_entry );
		}
	}

	public function tailwatch_delete_backup_folders( $existing_data ) {
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

	public function tailwatch_cleanup_incomplete_backup( $existing_data ) {
		$is_deleted = $this->tailwatch_delete_backup_folders( $existing_data );
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

	public function tailwatch_auto_cleanup_incomplete_backup() {
		// Allow pro plugin (MalwareScanner) to provide scanner data.
		$scanner_data = apply_filters( 'tailwatch_get_malware_scanner_progress_data', array() );

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
		$tailwatch_key           = 'default_backup_scan';
		$option             = 'scan_backp';

		$backup_maintain = new BackupMaintainController();
		$backup_maintain->maintain_backups( $tailwatch_key, $option );

		$all_backups          = $feature_controller->get_log_value( $tailwatch_key, $option );
		$all_backups_progress = $feature_controller->get_log_value( $tailwatch_key, 'backup_cancel_pause' );

		if ( ! empty( $all_backups ) ) {
			$all_backup_dates = array();
			foreach ( $all_backups as $backup_data ) {
				$backup_json        = json_decode( $backup_data['value'], true );
				$all_backup_dates[] = $backup_json['folderDate'];
				// The folder a row's label names is kept too: a damaged row's value can name
				// another folder, and its own would otherwise be swept as a leftover.
				if ( isset( $backup_data['type'] ) && 0 === strpos( (string) $backup_data['type'], 'backup_' ) ) {
					$all_backup_dates[] = substr( (string) $backup_data['type'], strlen( 'backup_' ) );
				}
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
								$this->tailwatch_delete_backup_folders( $backup_json );
								$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
								$this->tailwatch_delete_backup_entries( $delete_entries );

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
									$this->tailwatch_delete_backup_folders( $backup_json );
									$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
									$this->tailwatch_delete_backup_entries( $delete_entries );

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
								if ( $time_elapsed >= 86400 ) { // 24 hours
									$this->tailwatch_delete_backup_folders( $backup_json );
									$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
									$this->tailwatch_delete_backup_entries( $delete_entries );

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
									$this->tailwatch_delete_backup_folders( $backup_json );
									$delete_entries = array( $backup_data['id'], $backup_progress['id'] );
									$this->tailwatch_delete_backup_entries( $delete_entries );

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
									$export_dir  = TAILWATCH_BACKUP_DIR . '/migrator/export/';
									$folder_name = $backup_json['folderDate'];
									$zip_files   = array(
										$export_dir . 'tailwatch_files_backup_' . $folder_name . '.zip',
										$export_dir . 'tailwatch_database_backup_' . $folder_name . '.zip',
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
						$this->tailwatch_delete_backup_folders( $backup_json );
						$this->tailwatch_delete_backup_entries( array( $backup_data['id'] ) );

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
					$this->tailwatch_delete_backup_entries( array( $backup_progress['id'] ) );
				}
			}

			$directories = array(
				'files'    => $this->backup_directory,
				'database' => $this->db_directory,
			);

			// A migration's folder is catalogued only once its last part is sealed, so while it
			// is still receiving it has no row and looks like a failed backup's leftovers. The
			// transfers name the folders they are filling.
			$protected = (array) apply_filters( 'tailwatch_backup_protected_folders', array() );

			foreach ( $directories as $type => $directoryPath ) {
				if ( ! is_dir( $directoryPath ) ) {
					continue;
				}

				foreach ( new \DirectoryIterator( $directoryPath ) as $folder ) {
					if ( $folder->isDir() && ! $folder->isDot() ) {
						$folder_name = $folder->getFilename();
						if ( ! in_array( $folder_name, $all_backup_dates ) && ! isset( $protected[ $folder_name ] ) ) {
							$this->delete_folder_and_files( $directoryPath . $folder_name );
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

	public function tailwatch_delete_backup_entry( $ids ) {
		$backup_model = new BackupModel();
		$ids          = is_array( $ids ) ? $ids : array( $ids );
		foreach ( $ids as $id ) {
			$backup_model->delete_backup_by_id( (int) $id );
		}
		return true;
	}

	public function tailwatch_get_backup_folders_info( $post_data ) {
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
			$exclude_process_runs = apply_filters( 'tailwatch_backup_list_exclude_process_runs', array() );
			if ( ! is_array( $exclude_process_runs ) ) {
				$exclude_process_runs = array();
			}

			$backup_model  = new BackupModel();
			$dbFoldersData = $backup_model->get_all_backup_folders( 'default_backup_scan', 'scan_backp', $limit, $offset, $exclude_process_runs );

			// Counted over the folders that are actually on disk, because that is what the
			// list below returns. A raw row count would advertise pages that render empty.
			$total = $backup_model->get_all_backup_folders_count(
				'default_backup_scan',
				'scan_backp',
				$exclude_process_runs,
				self::tailwatch_backup_row_types_on_disk()
			);

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
			$download_backups = apply_filters( 'tailwatch_get_backup_downloading_status', array(), true );
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

	/**
	 * Build the per-backup folder name.
	 *
	 * A timestamp alone is enumerable: anyone who knows roughly when a backup ran
	 * can walk the directory names. The random suffix makes the path itself the
	 * last line of defence on hosts where no deny file applies — nginx ignores
	 * .htaccess entirely — so it must come from a CSPRNG, not from time()/rand().
	 *
	 * Only the leading timestamp is ever parsed back (see
	 * BackupMaintainController::backup_sort_key()); the suffix is opaque.
	 *
	 * @return string e.g. '2026-09-08_10-00-00-a1b2c3d4'
	 */
	private function generate_backup_folder_name() {
		try {
			$suffix = bin2hex( random_bytes( 4 ) );
		} catch ( \Exception $e ) {
			// random_bytes() throws only when no CSPRNG source is available at all.
			// wp_generate_password() is wp_rand()-backed, which is the best
			// remaining option; a predictable name is never acceptable here.
			$suffix = strtolower( substr( wp_generate_password( 8, false, false ), 0, 8 ) );
		}

		return current_time( 'Y-m-d_H-i-s' ) . '-' . $suffix;
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
	public function tailwatch_get_backup_folder_files( $post_data ) {
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

		// The schema dump. It is recorded at tables.path rather than in a batch, so walking
		// only the batches would leave out the file holding the CREATE TABLE statements every
		// rows part depends on, and it could not be downloaded, because the download grant
		// takes its name from this list. BackupReconciler records it first for the same reason.
		if ( ! empty( $db_results['tables']['path'] ) ) {
			$db_files[] = basename( (string) $db_results['tables']['path'] );
		}

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

						// No public URL is published for a backup artifact. The
						// archive and the SQL dump contain user rows and password
						// hashes, so the only supported way out is an authenticated
						// streaming download.
						$verified_files[] = array(
							'file_name'         => $file_name,
							'file_path'         => $directory_path . $file_name,
							'file_size'         => $this->format_size_units( $file_size ),
							'modification_time' => gmdate( 'Y-m-d H:i:s', $file->getMTime() ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate
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
