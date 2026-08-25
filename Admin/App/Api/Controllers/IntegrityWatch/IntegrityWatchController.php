<?php
/**
 * File: IntegrityWatchController.php
 *
 * Handles file integrity monitoring: scanning, comparison, progress, and cron scheduling.
 *
 * @package Tailwatch\Admin\App\Api\Controllers\IntegrityWatch
 */

namespace Tailwatch\Admin\App\Api\Controllers\IntegrityWatch;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Services\Time\TimeService;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\IntegrityWatch\FileMonModel;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;

/**
 * Controller for file integrity monitoring (scan, compare, logs, cron).
 */
class IntegrityWatchController extends BaseController {

	/**
	 * Threshold (bytes) for the "Large File Scanning" option. Files over this size
	 * are "large": in Skip mode they are not recorded or monitored; in Full scan
	 * mode they are content-hashed like any other file. 100 MB.
	 */
	private const LARGE_FILE_BYTES = 104857600;

	/**
	 * Size (bytes) above which a content hash is run under the "protected" path: an
	 * attempt counter is persisted BEFORE the hash so a request killed mid-hash (a
	 * multi-GB file vs a locked-down max_execution_time) still counts. After
	 * PROTECTED_HASH_MAX_ATTEMPTS kills, that one file degrades to a size+mtime marker
	 * instead of stalling the scan forever. Files at/below this hash directly (no kill
	 * risk, no bookkeeping). 512 MB.
	 */
	private const PROTECTED_HASH_BYTES = 536870912;

	/**
	 * Consecutive failed hash attempts on the SAME large file before it degrades to a
	 * size+mtime marker. Mirrors the backup engine's pack_fail_attempts.
	 */
	private const PROTECTED_HASH_MAX_ATTEMPTS = 3;

	/**
	 * Consecutive batch errors (thrown exceptions) in one scan before it is marked
	 * failed and stops rescheduling. Under the cap the tick retries with a backoff so a
	 * transient fault (DB hiccup, momentarily unreadable file) self-heals.
	 */
	private const TICK_MAX_ERRORS = 3;

	/**
	 * Seconds to wait before retrying after a batch error (vs the 1s budget-yield
	 * reschedule) — gives a transient fault time to clear instead of hot-retrying.
	 */
	private const TICK_ERROR_BACKOFF = 15;

	/**
	 * Max per-file "Deleted:" lines written to the live progress log. The full set
	 * is always kept in the scan record so the detail screen lists every deleted
	 * file; this only keeps the polled progress feed light on mass deletions.
	 */
	private const LIVE_LOG_DELETE_CAP = 1000;

	/**
	 * Feature check exemptions.
	 *
	 * @var array List of features exempt from the status check.
	 */
	protected $tailwatch_feature_check_exemptions = array();

	/**
	 * Whether integrity feature is enabled.
	 *
	 * @return bool
	 */
	protected function tailwatch_get_feature_status() {
		return $this->tailwatch_integrity_feature_enable();
	}

	/**
	 * Log directory path for file monitoring.
	 *
	 * @var string Absolute path to the file-monitoring-log directory.
	 */
	private $log_directory = TAILWATCH_LOGS_DIRECTORY . '/file-monitoring-log';

	/**
	 * Batch size for processing.
	 *
	 * @var int Number of files processed per batch.
	 */
	private $batch_size = 500;

	/**
	 * Scan progress JSON file path.
	 *
	 * @var string Absolute path to scan_progress.json.
	 */
	private $progress_file = TAILWATCH_LOGS_DIRECTORY . '/file-monitoring-log/scan_progress.json';

	/**
	 * Comparison progress JSON file path.
	 *
	 * @var string Absolute path to comparison_progress_file.json.
	 */
	private $comparison_progress_file = TAILWATCH_LOGS_DIRECTORY . '/file-monitoring-log/comparison_progress_file.json';

	/**
	 * Comparison logs JSON path.
	 *
	 * @var string Absolute path to comparison_logs.json.
	 */
	private $get_live_logs = TAILWATCH_LOGS_DIRECTORY . '/file-monitoring-log/comparison_logs.json';

	/**
	 * Cached "skip large files" flag for the current request, resolved once from
	 * the Large File Scanning option. Null until first read.
	 *
	 * @var bool|null
	 */
	private $large_file_skip = null;

	/**
	 * Cached integrity scan mode: 'fast' (skip re-hashing files whose size+mtime are
	 * unchanged) or 'thorough' (always hash). Resolved once per request.
	 *
	 * @var string|null
	 */
	private $scan_mode = null;

	/**
	 * True while the cron tick's time-budget loop is processing batches. The per-batch
	 * methods read this to SUPPRESS their own +5s reschedule (the loop reschedules once
	 * when the budget is hit), so we don't stack overlapping cron events.
	 *
	 * @var bool
	 */
	private $tick_loop_active = false;

	/**
	 * Seconds of work per cron tick before the loop yields + reschedules. Kept below the
	 * common 30s shared-host limit (Delicious Brains / WP Background Processing use 20s).
	 * Filterable via 'tailwatch_integrity_tick_seconds'.
	 *
	 * @var int
	 */
	private $tick_budget_seconds = 20;

	/**
	 * Set true when the batch just processed threw an exception. Reset before every
	 * batch call; read by the tick loop to drive the error retry/cap. The comparison
	 * batch sets it in its own catch; the baseline batch is wrapped by the loop.
	 *
	 * @var bool
	 */
	private $last_tick_errored = false;

	/**
	 * Process manager instance.
	 *
	 * @var ProcessManager Handles process lifecycle tracking.
	 */
	private $process_manager;

	/**
	 * Current process ID.
	 *
	 * @var int|null ID of the currently running integrity process.
	 */
	private $current_process_id;

	/**
	 * Constructor: registers cron hooks and process monitoring.
	 */
	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'tailwatch_files_integrity_scan', array( $this, 'tailwatch_start_monitoring_files' ) );
		$hook_controller->add_action_hook( 'tailwatch_delete_integrity_logs_file', array( $this, 'tailwatch_delete_integrity_logs' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'tailwatch_run_integrity_cron' ) );
		// Scheduling and handler for 'tailwatch_integrity_old_entry_cleanup' is owned by
		// IntegrityEntryMaintenanceCronJob in the CronJobs framework.
		$this->process_manager = new ProcessManager();
		$this->register_process_monitoring();
	}

	/**
	 * Registers process monitoring for files integrity cron.
	 */
	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'        => 'files_integrity',
				'cron_hooks'          => array( 'tailwatch_files_integrity_scan' ),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_files_integrity_check',
				'data_option'         => 'files_integrity_progress',
				'cancel_pause_key'    => 'default_files_integrity_check',
				'cancel_pause_option' => 'files_integrity_progress',
				'stuck_threshold'     => 300,
				'max_retries'         => 3,
				// On completion, integrity fires tailwatch_after_integrity_check_completed
				// which extensions can hook to perform follow-up work (e.g.,
				// scanning the changed files). Locking the malware feature
				// during integrity prevents the user from changing scan-related
				// settings mid-handoff.
				'locks_features'      => array(
					'default_file_integrity_check',
					'default_malware_scan',
				),
				// Process types that, when running, prevent a user from starting
				// a manual integrity check. db_optimize is intentionally NOT
				// listed (matching the spec's directional choice — db_optimize
				// blocks integrity from starting, but not vice versa).
				'cannot_start_while'  => array(
					'backup',
					'restore',
					'malware_scan',
					'malware_restore',
					'migration',
					'search_replace',
					// System-level settings operations rewrite feature config
					// site-wide; integrity must wait for them to finish.
					'settings_import',
					'reset_all',
					// The pro baseline updater rewrites the same baseline rows a new
					// comparison reads; block a manual integrity start until it ends.
					'baseline_update',
				),
			)
		);
	}

	/**
	 * Read a local plugin state file using WP Filesystem.
	 *
	 * @param string $path Absolute path to file.
	 * @return string|false File contents, or false on failure.
	 */
	private function read_file_contents( $path ) {
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( $wp_filesystem ) {
			return $wp_filesystem->get_contents( $path );
		}
		return false;
	}

	/**
	 * Write a local plugin state file using WP Filesystem.
	 *
	 * @param string $path    Absolute path to file.
	 * @param string $content Content to write.
	 * @return bool True on success, false on failure.
	 */
	private function write_file_contents( $path, $content ) {
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( $wp_filesystem ) {
			return $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
		}
		return false;
	}

	/**
	 * Gets feature options for file integrity.
	 *
	 * @return array|null
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_file_integrity_check';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Whether the "Large File Scanning" option is set to skip files over
	 * LARGE_FILE_BYTES. Default (and any unrecognized value) is Full scan = false.
	 * Resolved once per request and cached.
	 *
	 * @return bool True to skip large files, false to fully scan them.
	 */
	private function tailwatch_skip_large_files() {
		if ( null !== $this->large_file_skip ) {
			return $this->large_file_skip;
		}

		$get_feature = $this->get_features_options();
		$selected    = isset( $get_feature['field_1']['sub_options']['field_6']['options'] )
			? $get_feature['field_1']['sub_options']['field_6']['options']
			: '';

		if ( is_array( $selected ) ) {
			$selected = '';
			foreach ( $get_feature['field_1']['sub_options']['field_6']['options'] as $value ) {
				if ( ! empty( $value['selected'] ) ) {
					$selected = isset( $value['value'] ) ? $value['value'] : '';
					break;
				}
			}
		}

		$this->large_file_skip = is_string( $selected ) && false !== stripos( $selected, 'skip' );
		return $this->large_file_skip;
	}

	/**
	 * Integrity scan mode. 'fast' (default + any unrecognized value) skips re-hashing a
	 * file whose size AND mtime are unchanged from the baseline — the hash is still
	 * recomputed the moment size/mtime differ, so a real change is never missed. 'thorough'
	 * hashes every file every scan (the original behavior). Resolved once per request.
	 *
	 * @return string 'fast' or 'thorough'.
	 */
	private function tailwatch_integrity_scan_mode() {
		if ( null !== $this->scan_mode ) {
			return $this->scan_mode;
		}

		$get_feature = $this->get_features_options();
		$selected    = isset( $get_feature['field_1']['sub_options']['field_7']['options'] )
			? $get_feature['field_1']['sub_options']['field_7']['options']
			: '';

		if ( is_array( $selected ) ) {
			$selected = '';
			foreach ( $get_feature['field_1']['sub_options']['field_7']['options'] as $value ) {
				if ( ! empty( $value['selected'] ) ) {
					$selected = isset( $value['value'] ) ? $value['value'] : '';
					break;
				}
			}
		}

		// Only an explicit "Thorough" selection switches; default + unknown = fast.
		$this->scan_mode = ( is_string( $selected ) && false !== stripos( $selected, 'thorough' ) ) ? 'thorough' : 'fast';
		return $this->scan_mode;
	}

	/**
	 * Seconds of real work this cron tick may spend before yielding + rescheduling once.
	 * Filterable so a restrictive host can shrink it below its max_execution_time.
	 *
	 * @return int Clamped to [5, 120].
	 */
	private function tailwatch_integrity_tick_seconds() {
		$seconds = (int) apply_filters( 'tailwatch_integrity_tick_seconds', $this->tick_budget_seconds );
		if ( $seconds < 5 ) {
			$seconds = 5;
		} elseif ( $seconds > 120 ) {
			$seconds = 120;
		}
		return $seconds;
	}

	/**
	 * Whether the process is within ~10% of the PHP memory_limit. The time-budget loop
	 * checks this BETWEEN batches and yields when true, so a large run reschedules instead
	 * of fatalling on OOM. Mirrors WP Background Processing's memory-exceeded guard.
	 * memory_limit == -1 (unlimited) or unparseable → never exceeded.
	 *
	 * @return bool
	 */
	private function tailwatch_integrity_memory_exceeded() {
		$limit = $this->tailwatch_integrity_memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false; // -1 / unlimited / unknown — let the host decide.
		}
		// Yield once usage crosses 90% of the limit.
		return memory_get_usage( true ) >= ( $limit * 0.9 );
	}

	/**
	 * Whether the current tick has spent its time budget OR is near the memory ceiling.
	 * The batch loop checks this BETWEEN batches and yields (reschedules once) when true.
	 *
	 * @param int $deadline Unix timestamp the tick must finish work by.
	 * @return bool
	 */
	private function tailwatch_tick_budget_reached( $deadline ) {
		return ( time() >= $deadline ) || $this->tailwatch_integrity_memory_exceeded();
	}

	/**
	 * PHP memory_limit in bytes. Returns 0 for "unlimited" (-1) or an unreadable value.
	 *
	 * @return int
	 */
	private function tailwatch_integrity_memory_limit_bytes() {
		$raw = ini_get( 'memory_limit' );
		if ( false === $raw || '' === $raw ) {
			return 0;
		}
		$raw = trim( $raw );
		if ( '-1' === $raw ) {
			return 0; // Unlimited.
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;
		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}
		return $value > 0 ? $value : 0;
	}

	/**
	 * Acquire the single-worker lock for the integrity scan tick. Without it, the recurring
	 * 'tailwatch_files_integrity_schedule_run' cron and an injected 'tailwatch_files_integrity_scan'
	 * step could run two workers on the same scan_snapshot row and clobber the cursor.
	 * TTL is the PHP max_execution_time so it never expires mid-legitimate-tick; the
	 * shutdown hook + finally release it on normal/timeout/fatal exit. Mirrors the backup
	 * worker lock.
	 *
	 * @return bool True if this worker got the lock.
	 */
	private function tailwatch_acquire_scan_lock() {
		if ( get_transient( 'tailwatch_integrity_scan_lock' ) ) {
			return false;
		}
		set_transient( 'tailwatch_integrity_scan_lock', time(), 600 );
		register_shutdown_function( array( $this, 'tailwatch_release_scan_lock' ) );
		return true;
	}

	/**
	 * Release the integrity scan worker lock. Idempotent, so the finally + shutdown-hook
	 * double release is safe.
	 *
	 * @return void
	 */
	public function tailwatch_release_scan_lock() {
		delete_transient( 'tailwatch_integrity_scan_lock' );
	}

	/**
	 * Decide what the tick loop does after one batch, folding in the error retry/cap.
	 * `$this->last_tick_errored` is set when the batch threw. Errors are counted in a
	 * transient (keyed per branch) that survives a process-kill; a successful batch
	 * clears the streak. Returns one of:
	 *   'continue' — keep looping (more work, no error, budget left)
	 *   'yield'    — time/memory budget hit; reschedule and stop
	 *   'stop'     — scan complete/paused, or marked failed at the error cap
	 *   'retry'    — recoverable error under the cap; reschedule (with backoff) and stop
	 *
	 * @param bool   $more_work     Batch's bool return (true = more batches).
	 * @param string $err_key       Transient key for this branch's error streak.
	 * @param bool   $is_comparison Branch flag (drives the failed-state write).
	 * @param int    $deadline      Tick deadline (unix ts).
	 * @return string
	 */
	private function tailwatch_tick_after_batch( $more_work, $err_key, $is_comparison, $deadline ) {
		if ( $this->last_tick_errored ) {
			$errors = (int) get_transient( $err_key ) + 1;
			if ( $errors >= self::TICK_MAX_ERRORS ) {
				delete_transient( $err_key );
				$this->tailwatch_mark_scan_failed( $is_comparison, $errors );
				return 'stop';
			}
			set_transient( $err_key, $errors, HOUR_IN_SECONDS );
			return 'retry';
		}

		delete_transient( $err_key ); // Clean batch — reset any prior error streak.

		if ( ! $more_work ) {
			return 'stop'; // Completed / paused / empty.
		}

		if ( $this->tailwatch_tick_budget_reached( $deadline ) ) {
			return 'yield';
		}

		return 'continue';
	}

	/**
	 * Terminal failure after TICK_MAX_ERRORS consecutive batch errors. Marks the scan
	 * 'failed' (comparison row only — there is no baseline row), fails the process, and
	 * logs via the existing failure action. cron_running is intentionally left as-is:
	 * during a comparison it is true, so the healthcheck treats this as terminal and
	 * does NOT auto-restart the failing scan; a new scan resets state via
	 * insert_files_integrity_entry.
	 *
	 * @param bool $is_comparison Whether the failing branch is the comparison.
	 * @param int  $error_count   Consecutive errors that triggered the failure.
	 * @return void
	 */
	private function tailwatch_mark_scan_failed( $is_comparison, $error_count ) {
		$existing   = $this->get_files_integrity_progress();
		$process_id = ( is_array( $existing ) && isset( $existing['process_id'] ) )
			? $existing['process_id']
			: ( $this->current_process_id ?? null );

		if ( $is_comparison && is_array( $existing ) && ! empty( $existing ) ) {
			$existing['scan_state'] = 'failed';
			$this->update_files_integrity_cancel_pause( $existing );
		}

		if ( $process_id ) {
			$this->process_manager->mark_failed( $process_id, 'error_cap_reached' );
			$this->current_process_id = null;
		}

		Log::error(
			'File integrity scan stopped after repeated batch errors.',
			array(
				'feature' => 'files_integrity',
				'action'  => 'files_integrity_complete_failed',
				'error'   => 'Reached ' . (int) $error_count . ' consecutive batch errors; scan marked failed.',
			)
		);
	}

	/**
	 * Whether push notification is enabled for file integrity.
	 *
	 * @return bool
	 */
	public function files_integrity_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_file_integrity_check';
		$field_name        = 'field_1';
		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Unschedule any in-flight integrity scan hook when the feature is disabled.
	 *
	 * Schedule/unschedule for the recurring hooks (tailwatch_files_integrity_schedule_run
	 * and tailwatch_integrity_old_entry_cleanup) is owned by the CronJobs framework via
	 * IntegrityWatchCronJob and IntegrityEntryMaintenanceCronJob respectively. This
	 * method only needs to kill a lingering 'tailwatch_files_integrity_scan' single-event
	 * (a chained scan step) when the user disables the feature mid-scan.
	 */
	public function tailwatch_run_integrity_cron() {
		$get_feature = $this->get_features_options();
		$is_enabled  = isset( $get_feature['field_1']['options']['option']['selected'] )
			&& $get_feature['field_1']['options']['option']['selected'];

		if ( $is_enabled ) {
			return;
		}

		$integrity_next_scheduled = wp_next_scheduled( 'tailwatch_files_integrity_scan' );
		if ( $integrity_next_scheduled ) {
			wp_unschedule_event( $integrity_next_scheduled, 'tailwatch_files_integrity_scan' );
		}
	}

	/**
	 * Returns the path to monitor. Only whole-site monitoring is supported, so this
	 * is always ABSPATH regardless of the (inert) plugins/themes scope options —
	 * this also removes the old null-path edge when no scope was selected.
	 *
	 * @return string
	 */
	private function get_path_to_monitor() {
		return ABSPATH;
	}

	/**
	 * Whether the integrity feature is enabled for the given fields.
	 *
	 * @param array $field_name Field keys to check.
	 * @return array{parent_enable: bool, feature_enable: bool}
	 */
	public function tailwatch_integrity_feature_enable( $field_name = array( 'field_1' ) ) {
		$feature_enable = $this->get_features_options();

		if ( empty( $feature_enable ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$current = $feature_enable;
		foreach ( $field_name as $key ) {
			if ( ! isset( $current[ $key ] ) ) {
				return array(
					'parent_enable'  => true,
					'feature_enable' => false,
				);
			}
			$current = $current[ $key ];
		}

		$selected = true === ( $current['options']['option']['selected'] ?? false );

		return array(
			'parent_enable'  => true,
			'feature_enable' => $selected,
		);
	}

	/**
	 * Runs an instant file integrity check (on-demand scan).
	 *
	 * @param string $post_data JSON post data with instant_scan flag.
	 * @return array
	 */
	public function tailwatch_instant_files_integrity_check( $post_data ) {
		try {
			// Refuse to start if a conflicting process is currently running.
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'files_integrity' );
			if ( null !== $blocked ) {
				return $blocked;
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Invalid input data',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_start_failed',
						'error'   => 'Invalid input data for instant scan',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( isset( $data['instant_scan'] ) && true === $data['instant_scan'] ) {

				// Instant scans load/decode large baseline files; boost before that so we
				// don't OOM before the cron worker's own boost. Runtime-only, idempotent.
				( new PerformanceOptimizerController() )->tailwatch_boost_for_scanning();

				$cron_status = ( new CronHealthService() )->test( 'files_integrity' );
				if ( ! $cron_status['success'] ) {
					Log::error(
						'Cron access failure',
						array(
							'feature' => 'files_integrity',
							'action'  => 'files_integrity_start_failed',
							'error'   => $cron_status['message'],
						)
					);
					return array(
						'message' => __( 'Failed to run the FilesIntegrity due to an issue with the cron.', 'tailwatch' ),
						'error'   => $cron_status['message'],
						'code'    => 400,
					);
				}

				$timestamp = wp_next_scheduled( 'tailwatch_files_integrity_schedule_run' );
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'tailwatch_files_integrity_schedule_run' );
				}

				// Also clear the legacy hook if it still exists in the DB.
				$legacy_timestamp = wp_next_scheduled( 'file_monitoring_cron_hook' );
				if ( $legacy_timestamp ) {
					wp_unschedule_event( $legacy_timestamp, 'file_monitoring_cron_hook' );
				}

				$remove_data = $this->tailwatch_remove_garbage_entries_files();
				$comparison  = null;

				if ( $remove_data ) {
					$scan_type  = 'on-demand';
					$comparison = $this->tailwatch_start_monitoring( $scan_type );
					Log::info(
						'Files Integrity Started',
						array(
							'feature' => 'files_integrity',
							'action'  => 'files_integrity_started',
							'detail'  => 'Files Integrity instant scan started.',
						)
					);
				}

				$response = array(
					'data'       => $data,
					'comparison' => $comparison,
					'message'    => __( 'Successfully Run Files Integrity and Cron Schedule Reset.', 'tailwatch' ),
					'code'       => 200,
				);
			} else {
				Log::warning(
					'instant_scan false',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_start_failed',
						'error'   => 'Failed to run Files Integrity: instant_scan is false.',
					)
				);
				$response = array(
					'data'    => array(),
					'message' => __( 'Failed to run Files Integrity: instant_scan is false.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_instant_files_integrity_check',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_start_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while starting files integrity scan.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Whether a baseline scan has been requested and is starting/running, used to
	 * bridge the first-run window where no baseline row exists yet (count_baseline
	 * is 0 during the WP-Cron delay after the click AND during the first tick's
	 * queue build). Returns true when a scan tick is queued, or a live (non-stuck)
	 * files_integrity process exists — so the status check reports "in progress"
	 * instead of "files do not exist". Self-heals: a crashed scan stops being
	 * reported once its process goes stale and no tick is queued.
	 *
	 * @return bool
	 */
	private function tailwatch_integrity_scan_starting() {
		if ( wp_next_scheduled( 'tailwatch_files_integrity_scan' ) ) {
			return true;
		}

		foreach ( $this->process_manager->get_all_active_processes() as $proc ) {
			if ( isset( $proc['process_type'], $proc['process_id'] )
				&& 'files_integrity' === $proc['process_type']
				&& ! $this->process_manager->is_process_stuck( $proc['process_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Baseline-build progress ("X of Y files") read from the build cursor. Returns
	 * files_scanned / files_total / progress (0-100), or an empty array when the
	 * total is not known yet (queue still building) or there is no build cursor.
	 * Cheap: one small cursor-file read, no DB query and no scan of the queue file.
	 *
	 * @return array
	 */
	private function tailwatch_baseline_build_progress() {
		if ( ! file_exists( $this->progress_file ) ) {
			return array();
		}

		$progress = (array) $this->load_progress( $this->progress_file );
		$total    = isset( $progress['queue_total'] ) ? (int) $progress['queue_total'] : 0;
		$scanned  = isset( $progress['queue_processed'] ) ? (int) $progress['queue_processed'] : 0;

		if ( $total <= 0 ) {
			return array(); // Queue not built yet — no meaningful count.
		}
		if ( $scanned > $total ) {
			$scanned = $total;
		}

		return array(
			'files_scanned' => $scanned,
			'files_total'   => $total,
			'progress'      => (int) round( $scanned / $total * 100 ),
		);
	}

	/**
	 * Verifies current integrity status and returns response for UI.
	 *
	 * @return array
	 */
	public function tailwatch_verify_integrity_current_status() {
		try {
			$path_to_monitor = $this->get_path_to_monitor();
			if ( null === $path_to_monitor ) {
				return array(
					'isEnabled'  => false,
					'is_request' => false,
					'message'    => __( 'File integrity check is not enabled.', 'tailwatch' ),
					'code'       => 200,
				);
			}
			$existing_entry = $this->tailwatch_get_is_completed();

			if ( ! $existing_entry ) {
				// count_baseline is still 0. A first scan may have just been requested:
				// WP-Cron fires a moment after the click, and the first tick's queue
				// build runs before any baseline row lands — so "no rows yet" does NOT
				// mean "nothing requested". If a scan is queued or actively running,
				// report it as in progress so the UI keeps polling instead of flashing
				// "files do not exist".
				if ( $this->tailwatch_integrity_scan_starting() ) {
					$response = array_merge(
						array(
							'isEnabled'  => false,
							'is_request' => true,
							'scan_state' => 'building-baseline',
							'message'    => __( 'Setting up file monitoring. This first scan records your current files, so later scans can detect any changes.', 'tailwatch' ),
							'code'       => 200,
						),
						$this->tailwatch_baseline_build_progress()
					);
				} else {
					$response = array(
						'isEnabled'  => true,
						'is_request' => false,
						'message'    => __( 'File monitoring is not set up yet. Run a scan to record your files for the first time.', 'tailwatch' ),
						'code'       => 200,
					);
				}
			} elseif ( false === $existing_entry['is_completed'] ) {
				$response = array_merge(
					array(
						'isEnabled'  => false,
						'is_request' => true,
						'scan_state' => 'building-baseline',
						'message'    => __( 'Setting up file monitoring. This first scan records your current files, so later scans can detect any changes.', 'tailwatch' ),
						'code'       => 200,
					),
					$this->tailwatch_baseline_build_progress()
				);
			} elseif ( true === $existing_entry['is_completed'] ) {
				$existing_data = $this->get_files_integrity_progress();
				if ( ! empty( $existing_data ) && ( 'pause' === $existing_data['scan_state'] ) ) {
					$response = array(
						'isEnabled'    => false,
						'is_request'   => false,
						'progress'     => $existing_data['progress'],
						'scan_type'    => $existing_data['scan_type'],
						'is_completed' => false,
						'scan_state'   => 'pause',
						'message'      => __( 'File comparison was the pause.', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( ! empty( $existing_data ) && ( 'in-progress' === $existing_data['scan_state'] ) ) {
					$response = array(
						'isEnabled'    => false,
						'is_request'   => false,
						'progress'     => $existing_data['progress'],
						'scan_type'    => $existing_data['scan_type'],
						'is_completed' => false,
						'scan_state'   => 'in-progress',
						'message'      => __( 'Comparing your files against the saved record to check for changes.', 'tailwatch' ),
						'code'         => 200,
					);
				} else {
					$response = array(
						'isEnabled'    => true,
						'is_request'   => false,
						'is_completed' => true,
						'message'      => __( 'You can now run the file comparison.', 'tailwatch' ),
						'code'         => 200,
					);
					// Emit the terminal state so the stuck-process self-heal can detect completion.
					if ( ! empty( $existing_data['scan_state'] ) && 'completed' === $existing_data['scan_state'] ) {
						$response['scan_state'] = 'completed';
					}
				}
			}

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_verify_integrity_current_status',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_status_verify_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error verifying integrity current status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Returns comparison logs for file integrity (live logs).
	 *
	 * @param string $post_data JSON post data.
	 * @return array
	 */
	public function tailwatch_files_integrity_comparison_logs( $post_data ) {
		try {
			$get_progress = $this->get_files_integrity_progress();
			$feature_type = 'files_integrity';

			$livelogs = new LiveLogsController();
			return $livelogs->tailwatch_import_live_logs( $post_data, $this->get_live_logs, $get_progress, $feature_type );
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_files_integrity_comparison_logs',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_live_logs_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error retrieving files integrity comparison logs.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Updates integrity log records (live log message).
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (e.g. 'INFO', 'OK', 'RESULT'). Default 'INFO'.
	 */
	public function update_integrity_logs_records( $message, $level = 'INFO' ) {
		$livelogs = new LiveLogsController();
		$livelogs->update_live_logs_records( $message, $this->get_live_logs, $level );
	}

	/**
	 * Write a LIST of progress messages in one file read+write (vs one per call).
	 * Used by the per-file change/deletion loops so a batch with thousands of
	 * changes does not rewrite the growing live-log file once per file (O(N^2)).
	 *
	 * @param array  $messages Plain message strings.
	 * @param string $level    Log level.
	 * @return void
	 */
	private function update_integrity_logs_records_batch( array $messages, $level = 'INFO' ) {
		if ( empty( $messages ) ) {
			return;
		}
		( new LiveLogsController() )->append_live_logs_records( $messages, $this->get_live_logs, $level );
	}

	/**
	 * Inserts a new files integrity progress entry into the database.
	 *
	 * @param string $scan_type Scan type (e.g. on-demand, scheduled).
	 */
	public function insert_files_integrity_entry( $scan_type ) {
		$insert_progress = array(
			'scan_state'   => 'in-progress',
			'cron_running' => false,
			'progress'     => 1,
			'started_time' => time(),
			'id'           => time(),
			'scan_type'    => $scan_type,
			'process_id'   => $this->current_process_id ?? null,
		);

		$progress_data = wp_json_encode( $insert_progress );

		$db_data = array(
			'user_id'       => '1',
			'child_of'      => '0',
			'key'           => 'default_files_integrity_check',
			'option'        => 'files_integrity_progress',
			'value'         => $progress_data,
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => true,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		$db_model = new DBModel();

		$db_model->insert_row( $db_data, $db_data_format );
	}

	/**
	 * Gets current files integrity progress from database.
	 *
	 * @return array|null
	 */
	public function get_files_integrity_progress() {
		$feature_controller = new DBModel();
		$tailwatch_key           = 'default_files_integrity_check';
		$option             = 'files_integrity_progress';
		$existing_data      = $feature_controller->get_recent_data( $option, $tailwatch_key );
		return $existing_data;
	}

	/**
	 * Updates files integrity progress (cancel/pause state).
	 *
	 * @param array $options Progress options to save.
	 */
	public function update_files_integrity_cancel_pause( array $options ) {
		$db_model = new DBModel();
		$tailwatch_key = 'default_files_integrity_check';
		$option   = 'files_integrity_progress';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $tailwatch_key, $option );
	}

	/**
	 * Resumes a paused files integrity scan.
	 *
	 * @return array
	 */
	public function tailwatch_resume_files_integrity() {
		try {

			$existing_data = $this->get_files_integrity_progress();

			if ( ! empty( $existing_data ) && ! empty( $existing_data['scan_state'] ) && 'pause' === $existing_data['scan_state'] ) {
				wp_schedule_single_event( time() + 5, 'tailwatch_files_integrity_scan' );

				$existing_data['scan_state'] = 'in-progress';
				$this->update_files_integrity_cancel_pause( $existing_data );

				Log::info(
					'Files Integrity Resumed',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_resumed',
						'detail'  => 'The files integrity process was resumed.',
					)
				);

				$pid = isset( $existing_data['process_id'] ) ? $existing_data['process_id'] : ( $this->current_process_id ?? null );
				if ( $pid ) {
					$this->process_manager->update_state( $pid, 'in_progress' );
					$this->process_manager->heart_beat( $pid );
				}

				return array(
					'data'    => array(),
					'message' => __( 'Files Integrity Resume Successfully', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				Log::warning(
					'Invalid resume attempt',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_resume_failed',
						'error'   => 'No paused files integrity process already running',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Already Files Integrity Schedule', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_resume_files_integrity',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_resume_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error resuming files integrity.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Cancels or pauses the running files integrity scan.
	 *
	 * @param string $post_data JSON with scan_state (pause|cancel).
	 * @return array
	 */
	public function tailwatch_cancel_pause_integrity( $post_data ) {
		try {

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';

			$data          = json_decode( $json_data, true );
			$existing_data = $this->get_files_integrity_progress();

			if ( ! empty( $data['scan_state'] ) && ( 'pause' === $data['scan_state'] || 'cancel' === $data['scan_state'] ) ) {

				$existing_data['scan_state'] = $data['scan_state'];
				$this->update_files_integrity_cancel_pause( $existing_data );

				$timestamp = wp_next_scheduled( 'tailwatch_files_integrity_scan' );
				wp_unschedule_event( $timestamp, 'tailwatch_files_integrity_scan' );
				$process_id = isset( $existing_data['process_id'] ) ? $existing_data['process_id'] : ( $this->current_process_id ?? null );

				if ( 'cancel' === $data['scan_state'] ) {
					$message = 'Files Integrity cancel successfully.';
					Log::info(
						'Files Integrity Cancelled',
						array(
							'feature' => 'files_integrity',
							'action'  => 'files_integrity_cancelled',
							'detail'  => $message,
						)
					);
					if ( $process_id ) {
						$this->process_manager->mark_failed( $process_id, 'cancelled' );
						$this->current_process_id = null;
					}
					$eid = $existing_data['id'];
					$this->tailwatch_delete_comparison_entry( array( $eid ), false );
					// Optional: wp_schedule_single_event( time() + 5, 'tailwatch_delete_integrity_logs_file' ).
				} elseif ( 'pause' === $data['scan_state'] ) {
					$message = 'Files Integrity paused successfully. You can resume it later.';
					Log::info(
						'Files Integrity Paused',
						array(
							'feature' => 'files_integrity',
							'action'  => 'files_integrity_paused',
							'detail'  => $message,
						)
					);
					if ( $process_id ) {
						$this->process_manager->update_state( $process_id, 'pause' );
					}
					$existing_data['cron_running'] = false;
					$this->update_files_integrity_cancel_pause( $existing_data );
				}

				return array(
					'data'    => array(),
					'message' => $message,
					'code'    => 200,
				);
			} else {
				Log::error(
					'Missing scan state',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_cancel_pause_failed',
						'error'   => 'Stop type is missing in input data',
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
				'Exception in tailwatch_cancel_pause_integrity',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_cancel_pause_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error pausing or cancelling files integrity.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Removes garbage entries and resets progress for a fresh scan.
	 *
	 * @return bool
	 */
	public function tailwatch_remove_garbage_entries_files() {
		$key    = 'default_files_integrity_check';
		$option = 'files_integrity_progress';

		$feature_controller = new DBModel();
		$get_data           = $feature_controller->get_value( $option, $key );

		if ( ! empty( $get_data ) ) {
			$where = array(
				'option' => $option,
				'key'    => $key,
			);

			$feature_controller->delete_table_rows( $where );
		}

		// Drop any incomplete (interrupted) scans from the table + their sidecars.
		( new FileMonModel() )->delete_incomplete_scans();

		$this->tailwatch_delete_files_after_complete();

		return true;
	}

	/**
	 * Deletes one or all comparison entries from tw_filemon_scans (+ sidecars).
	 *
	 * @param array $ids        Entry IDs to delete.
	 * @param bool  $delete_all Whether to delete all.
	 * @return array|false
	 */
	public function tailwatch_delete_comparison_entry( $ids = array(), $delete_all = false ) {
		$file_mon = new FileMonModel();

		if ( $delete_all ) {
			$deleted_ids = $file_mon->all_scan_ids();
			$file_mon->delete_scans( $deleted_ids );
			return array(
				'success_count' => count( $deleted_ids ),
				'failed_ids'    => array(),
			);
		}

		$success_count = 0;
		$failed_ids    = array();
		$deleted_ids   = array();

		foreach ( (array) $ids as $eid ) {
			if ( null !== $file_mon->get_scan( (int) $eid ) ) {
				$deleted_ids[] = (int) $eid;
				++$success_count;
			} else {
				$failed_ids[] = $eid;
			}
		}

		if ( ! empty( $deleted_ids ) ) {
			$file_mon->delete_scans( $deleted_ids );
		}

		return array(
			'success_count' => $success_count,
			'failed_ids'    => $failed_ids,
		);
	}

	/**
	 * Deletes comparison entries by ID(s) or all (via post data).
	 *
	 * @param string $post_data JSON with ids or is_delete.
	 * @return array
	 */
	public function tailwatch_delete_comparison_by_id( $post_data ) {
		try {
			// Block while consumers of the comparison file are running:
			// files_integrity writes it; baseline_update rewrites the baselines
			// it depends on; malware_scan reads comparison_status.json for the
			// integrity-handoff and stats aggregation. Internal callers go
			// through tailwatch_delete_comparison_entry directly and bypass this
			// gate, so this only affects the user AJAX entry.
			$blocked = ( new ProcessGuard() )->ensure_can_modify_artifacts(
				array( 'files_integrity', 'baseline_update', 'malware_scan' )
			);
			if ( null !== $blocked ) {
				return $blocked;
			}

			$json_data = ! empty( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data['is_delete'] ) && ( ! isset( $data['ids'] ) || ! is_array( $data['ids'] ) || empty( $data['ids'] ) ) ) {
				Log::error(
					'Missing delete parameters',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_delete_failed',
						'error'   => 'Either is_delete must be true or a non-empty ids array must be provided.',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Either is_delete must be true or a non-empty ids array must be provided.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Handle delete all or specific IDs.
			$delete_all = isset( $data['is_delete'] ) && true === $data['is_delete'];
			$ids        = $delete_all ? array() : $data['ids'];

			$result = $this->tailwatch_delete_comparison_entry( $ids, $delete_all );

			if ( false === $result ) {
				Log::error(
					'Failed to update comparison file',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_delete_failed',
						'error'   => 'Failed to update comparison file.',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Failed to update comparison file.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			$success_count = $result['success_count'];
			$failed_ids    = $result['failed_ids'];

			Log::info(
				'Files Integrity Deleted',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_deleted',
					'detail'  => 'Comparison entries deleted successfully.',
				)
			);

			$response = array(
				'data'    => array(
					'success_count' => $success_count,
					'failed_ids'    => $failed_ids,
				),
				'message' => __( 'Comparison entries deleted successfully.', 'tailwatch' ),
				'code'    => 200,
			);

			if ( ! empty( $failed_ids ) && $success_count > 0 ) {
				$response['code']    = 207;
				$response['message'] = 'Some comparison entries were deleted successfully, but some failed.';
			} elseif ( ! empty( $failed_ids ) && 0 === $success_count ) {
				$response['code']    = 400;
				$response['message'] = 'No valid comparison entries found for the provided IDs.';
			} elseif ( $delete_all && 0 === $success_count ) {
				$response['code']    = 200;
				$response['message'] = 'No comparison entries found to delete.';
			}

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_delete_comparison_by_id',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_delete_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error deleting comparison entries.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Maintains integrity entries: removes old comparisons based on retention option.
	 *
	 * @return bool|void
	 */
	public function tailwatch_maintain_integrity_entry() {
		$is_enabled = $this->tailwatch_integrity_feature_enable();

		if ( ! $is_enabled['feature_enable'] ) {
			return;
		}

		$get_feature     = $this->get_features_options();
		$maintain_option = isset( $get_feature['field_1']['sub_options']['field_4']['options'] ) ? $get_feature['field_1']['sub_options']['field_4']['options'] : '6 Hours';

		if ( is_array( $maintain_option ) ) {
			foreach ( $maintain_option as $key => $value ) {
				if ( isset( $value['selected'] ) && $value['selected'] ) {
					$maintain_option = $value['value'];
					break;
				}
			}
		}

		if ( 'Keep All Data' === $maintain_option ) {
			return;
		}

		$hours = (int) filter_var( $maintain_option, FILTER_SANITIZE_NUMBER_INT );
		// "Now" in the SAME clock the scan_time is stored in (current_time('mysql') =
		// WP-local), so strtotime($scan_time) below compares like-for-like. Using a
		// raw time() (UTC) here would skew the age by the site's GMT offset and
		// delete scans too early/late on non-UTC sites.
		$current_time = strtotime( current_time( 'mysql' ) );

		// Drop scans older than the retention window (+ their sidecars). Scans are
		// bounded by this very job, so listing them is cheap and lets us keep the
		// exact strtotime()/time() age comparison the file path used.
		$file_mon    = new FileMonModel();
		$all         = $file_mon->list_scans( array( 'completed_only' => false, 'limit' => 100000, 'offset' => 0 ) );
		$deleted_ids = array();
		foreach ( $all['rows'] as $row ) {
			if ( ! empty( $row['scan_time'] ) ) {
				$scan_time = strtotime( $row['scan_time'] );
				if ( ( $current_time - $scan_time ) > ( $hours * 3600 ) ) {
					$deleted_ids[] = (int) $row['scan_id'];
				}
			}
		}
		if ( ! empty( $deleted_ids ) ) {
			$file_mon->delete_scans( $deleted_ids );
		}

		return true;
	}

	/**
	 * Reschedules files integrity cron if it failed or is not scheduled.
	 *
	 * @return array
	 */
	public function tailwatch_files_integrity_cron_if_failed() {
		try {
			$get_integrity_data = $this->get_files_integrity_progress();
			$cron_running       = ( is_array( $get_integrity_data ) && isset( $get_integrity_data['cron_running'] ) )
				? $get_integrity_data['cron_running']
				: null;
			if ( false === $cron_running ) {
				if ( ! wp_next_scheduled( 'tailwatch_files_integrity_scan' ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, 'tailwatch_files_integrity_scan' );

					if ( $cron_scheduled ) {
						Log::info(
							'Files Integrity Cron Scheduled',
							array(
								'feature' => 'files_integrity',
								'action'  => 'files_integrity_cron_if_failed',
								'detail'  => 'Successfully scheduled a new Files Integrity cron job.',
							)
						);
						return array(
							'data'    => '',
							'message' => __( 'Again attempt to run the cron', 'tailwatch' ),
							'code'    => 200,
						);
					} else {
						Log::error(
							'Failed to schedule Files Integrity cron job.',
							array(
								'feature' => 'files_integrity',
								'action'  => 'files_integrity_cron_if_failed_on_attempt',
								'error'   => 'Cron scheduling failed.',
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
						'Files Integrity Cron Already Scheduled',
						array(
							'feature' => 'files_integrity',
							'action'  => 'files_integrity_cron_if_failed',
							'detail'  => 'Cron job is already scheduled for Files Integrity.',
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
					'Files Integrity Cron Running',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_cron_if_failed',
						'detail'  => 'Files Integrity cron job is currently running.',
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
				'Exception in files_integrity_cron_if_failed_on_attempt',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_cron_if_failed_on_attempt',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error rescheduling cron job.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Stops or pauses the current integrity execution.
	 *
	 * @return bool|void
	 */
	public function tailwatch_stop_integrity_execution() {
		$get_integrity_data = $this->get_files_integrity_progress();

		if ( ! empty( $get_integrity_data['scan_state'] ) && ( 'pause' === $get_integrity_data['scan_state'] || 'cancel' === $get_integrity_data['scan_state'] ) ) {

			$timestamp = wp_next_scheduled( 'tailwatch_files_integrity_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_files_integrity_scan' );
			}

			if ( true === $get_integrity_data['cron_running'] ) {
				$get_integrity_data['cron_running'] = false;
				$this->update_files_integrity_cancel_pause( $get_integrity_data );
			}

			$this->tailwatch_integrity_function_completed();

			return true;
		}
	}

	/**
	 * Gets current completion status for the monitored path.
	 *
	 * @return array|null
	 */
	public function tailwatch_get_is_completed() {
		$path_to_monitor = $this->get_path_to_monitor();
		$count           = ( new FileMonModel() )->count_baseline( $path_to_monitor );

		if ( $count <= 0 ) {
			return null; // No baseline captured yet → caller builds it.
		}

		// The baseline exists; it is "completed" once the initial-build progress file
		// is gone (generate_initial_snapshot deletes it only on the final batch).
		// While the build is still running the file is present → is_completed stays
		// false so the caller keeps building rather than comparing against a partial
		// baseline.
		return array(
			'path'             => $path_to_monitor,
			'is_completed'     => ! file_exists( $this->progress_file ),
			'total_scan_files' => $count,
		);
	}

	/**
	 * Starts file integrity monitoring (initial or continued scan).
	 *
	 * @param string $scan_type Scan type (default automatically).
	 * @return bool|array
	 */
	public function tailwatch_start_monitoring( $scan_type = 'automatically' ) {
		$this->log_directory = TAILWATCH_LOGS_DIRECTORY . '/file-monitoring-log';
		if ( ! is_dir( $this->log_directory ) ) {
			wp_mkdir_p( $this->log_directory );
		}

		$existing_entry = $this->tailwatch_get_is_completed();

		$process_id               = $this->process_manager->get_or_create_process(
			'files_integrity',
			'tailwatch_files_integrity_scan',
			array(
				'scan_type' => $scan_type,
			)
		);
		$this->current_process_id = $process_id;

		if ( ! $existing_entry || false === $existing_entry['is_completed'] ) {
			$progress_file = $this->progress_file;
			$progress      = $this->load_progress( $progress_file );

			$last_scanned_file = $progress['last_scanned_file'] ?? null;

			if ( empty( $progress['process_id'] ) && ! empty( $this->current_process_id ) ) {
				$progress['process_id'] = $this->current_process_id;
				$this->save_progress( $progress_file, $last_scanned_file, $process_id );
			}
			$response = false;
		} else {
			$this->insert_files_integrity_entry( $scan_type );
			$message  = 'Initializing file integrity scan';
			$livelogs = new LiveLogsController();
			$livelogs->insert_live_logs_records( $message, $this->log_directory, $this->get_live_logs );
			$response = true;
		}
		$this->process_manager->heart_beat( $process_id );
		$this->process_manager->update_state( $process_id, 'in_progress' );
		wp_schedule_single_event( time(), 'tailwatch_files_integrity_scan' );

		return $response;
	}

	/**
	 * Cron callback: starts or continues file monitoring.
	 *
	 * Processes as many batches as fit inside one time/memory budget (the "tick"), then
	 * reschedules ONCE if work remains. This replaces the old one-batch-per-cron-event
	 * cadence (every batch paid a full WP-Cron loopback + 5s gap), which made large
	 * trees crawl. A single-worker lock prevents the recurring schedule, an injected scan
	 * step, and the healthcheck from running two workers on the same shared cursor.
	 */
	public function tailwatch_start_monitoring_files() {
		if ( ! $this->tailwatch_acquire_scan_lock() ) {
			return; // Another worker is mid-tick — skip this injected run.
		}

		// Seconds to wait before the next tick: 0 = none (done), 1 = budget yield,
		// TICK_ERROR_BACKOFF = recoverable error. Set from the loop's return.
		$reschedule_delay = 0;

		try {
			// Raise PHP limits before the integrity tick. Runtime-only, idempotent.
			( new PerformanceOptimizerController() )->tailwatch_boost_for_scanning();

			$existing_entry  = $this->tailwatch_get_is_completed();
			$path_to_monitor = $this->get_path_to_monitor();

			$this->tick_loop_active = true;
			$deadline               = time() + $this->tailwatch_integrity_tick_seconds();

			if ( ! $existing_entry || false === $existing_entry['is_completed'] ) {
				// Baseline-build loop.
				$reschedule_delay = $this->tailwatch_run_tick_loop(
					function () use ( $path_to_monitor ) {
						return $this->generate_initial_snapshot( $path_to_monitor );
					},
					'tailwatch_fim_err_baseline',
					false,
					$deadline
				);
			} else {
				// Comparison loop. Per-tick state flags are written once up front, then
				// batches run until the comparison completes/pauses/fails or the budget is hit.
				$existing_data = $this->get_files_integrity_progress();
				// A duplicate tick after completion must not reactivate the finished row or restart the scan.
				if ( ! empty( $existing_data['scan_state'] ) && 'completed' === $existing_data['scan_state'] ) {
					return;
				}
				$process_id    = isset( $existing_data['process_id'] ) ? $existing_data['process_id'] : ( $this->current_process_id ?? null );
				if ( ! $process_id ) {
					$process_id               = $this->process_manager->get_or_create_process( 'files_integrity', 'tailwatch_files_integrity_scan', array() );
					$this->current_process_id = $process_id;
					if ( ! empty( $existing_data ) ) {
						$existing_data['process_id'] = $process_id;
						$this->update_files_integrity_cancel_pause( $existing_data );
					}
				}
				$this->process_manager->heart_beat( $process_id );
				$this->process_manager->update_state( $process_id, 'in_progress' );

				if ( false === $existing_data['cron_running'] ) {
					$existing_data['cron_running'] = true;
					$this->update_files_integrity_cancel_pause( $existing_data );
				}

				$existing_data['function_completed'] = false;
				$existing_data['function_started']   = true;
				$this->update_files_integrity_cancel_pause( $existing_data );

				$reschedule_delay = $this->tailwatch_run_tick_loop(
					function () use ( $path_to_monitor ) {
						return $this->comparison_json_verification( $path_to_monitor );
					},
					'tailwatch_fim_err_comparison',
					true,
					$deadline
				);

				$this->tailwatch_integrity_function_completed();
			}
		} finally {
			$this->tick_loop_active = false;
			$this->tailwatch_release_scan_lock();
			// Reschedule AFTER releasing the lock so the next tick can acquire it.
			// Rescheduling before release risks a fast cron loopback bailing on a
			// still-held lock, stalling the scan until the healthcheck recovers it.
			if ( $reschedule_delay > 0 ) {
				wp_schedule_single_event( time() + $reschedule_delay, 'tailwatch_files_integrity_scan' );
			}
		}
	}

	/**
	 * Runs batches inside one tick until the work completes, the time/memory budget is
	 * hit, or the error cap is reached. Each batch is wrapped so a thrown exception is
	 * counted toward the retry/cap (the comparison batch flags its own catch; the
	 * baseline batch has none, so this catch covers it). A non-advancing cursor cannot
	 * spin forever — the iteration backstop reschedules instead.
	 *
	 * @param callable $batch_callable Runs one batch, returns bool (true = more work).
	 * @param string   $err_key        Transient key for this branch's error streak.
	 * @param bool     $is_comparison  Branch flag (drives the failed-state write on cap).
	 * @param int      $deadline       Tick deadline (unix ts).
	 * @return int Seconds to wait before the next tick (0 = none / done).
	 */
	private function tailwatch_run_tick_loop( $batch_callable, $err_key, $is_comparison, $deadline ) {
		// Backstop against a non-advancing cursor spinning hot for the whole budget. Far
		// above any real batch count for a 20s tick (batch_size files per iteration).
		$max_iterations = 100000;

		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$this->last_tick_errored = false;
			try {
				$more_work = (bool) $batch_callable();
			} catch ( \Throwable $e ) {
				$this->last_tick_errored = true;
				$more_work               = false;
				Log::error(
					'Exception in files_integrity batch',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_complete_failed',
						'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					)
				);
			}

			$action = $this->tailwatch_tick_after_batch( $more_work, $err_key, $is_comparison, $deadline );
			if ( 'continue' === $action ) {
				continue;
			}
			if ( 'yield' === $action ) {
				return 1; // Budget hit — reschedule soon.
			}
			if ( 'retry' === $action ) {
				return self::TICK_ERROR_BACKOFF; // Recoverable error — reschedule with backoff.
			}
			return 0; // 'stop' — completed / paused / failed.
		}

		return 1; // Iteration backstop — reschedule to keep making progress.
	}

	/**
	 * Marks integrity function as completed (cleanup, delete progress file).
	 */
	public function tailwatch_integrity_function_completed() {
		$existing_data                         = $this->get_files_integrity_progress();
		$existing_data['function_completed']   = true;
		$existing_data['function_started']     = false;
		$existing_data['completion_timestamp'] = time(); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Stored as Unix timestamp for comparison.
		$this->update_files_integrity_cancel_pause( $existing_data );
	}

	/**
	 * Generates initial snapshot for the path to monitor. Processes ONE batch.
	 *
	 * @param string $path_to_monitor Path to monitor.
	 * @return bool True if more batches remain, false when the baseline build is complete.
	 */
	private function generate_initial_snapshot( $path_to_monitor ) {
		$path_to_monitor = rtrim( $path_to_monitor, DIRECTORY_SEPARATOR );

		$progress_file = $this->progress_file;
		$progress      = $this->load_progress( $progress_file );

		$last_scanned_file = $progress['last_scanned_file'] ?? null;

		if ( empty( $progress['process_id'] ) && ! empty( $this->current_process_id ) ) {
			$progress['process_id'] = $this->current_process_id;
			$this->save_progress( $progress_file, $last_scanned_file, $this->current_process_id, $progress['scan_queue_offset'] ?? null );
		}

		$process_id = isset( $progress['process_id'] ) ? $progress['process_id'] : ( $this->current_process_id ?? null );

		if ( ! empty( $process_id ) ) {
			$this->process_manager->heart_beat( $process_id );
			$this->process_manager->update_state( $process_id, 'in_progress' );
		}

		// Discovery decouple: walk once into a queue file and resume by byte offset
		// (no per-batch full-tree re-walk). Falls back to the legacy re-walk when
		// the queue is unavailable, so behaviour degrades gracefully.
		$queued = $this->tailwatch_scan_batch_from_queue( $path_to_monitor, $progress_file, $this->batch_size );
		if ( null === $queued ) {
			$remaining_files = $this->scan_files( $path_to_monitor, $last_scanned_file, $this->batch_size );
			$queue_offset    = null;
			$queue_total     = $progress['queue_total'] ?? null; // legacy fallback path has no queue total
		} else {
			$remaining_files = $queued['files'];
			$queue_offset    = $queued['next_offset'];
			// queue_total is known only on the build tick; reuse the stored value after.
			$queue_total     = ( null !== $queued['queue_total'] ) ? $queued['queue_total'] : ( $progress['queue_total'] ?? null );
		}

		// Running count of files read from the queue so far, for the "X of Y" status.
		$queue_processed = (int) ( $progress['queue_processed'] ?? 0 ) + count( $remaining_files );

		$tree_structure = $this->build_tree_structure( $remaining_files, $path_to_monitor );

		$is_completed = ( count( $remaining_files ) < $this->batch_size );

		$this->log_file_info( $tree_structure, $path_to_monitor, $is_completed );

		$last_file_processed = end( $remaining_files );
		$this->save_progress( $progress_file, $last_file_processed, $process_id, $queue_offset, $queue_total, $queue_processed );

		if ( $process_id ) {
			$this->process_manager->update_metadata(
				$process_id,
				array(
					'last_scanned_file'   => $last_file_processed,
					'initial_batch_count' => count( $remaining_files ),
					'initial_completed'   => $is_completed,
				)
			);
		}

		if ( $is_completed ) {
			if ( ! empty( $process_id ) ) {
				$this->process_manager->mark_completed( $process_id );
				$this->current_process_id = null;
			}

			$this->tailwatch_delete_scan_queue( $progress_file );

			if ( file_exists( $this->progress_file ) ) {
				wp_delete_file( $this->progress_file );
			}

			return false; // Baseline build finished — no more work.
		} elseif ( count( $remaining_files ) === $this->batch_size ) {
			// The tick loop owns rescheduling; only self-reschedule when invoked outside it.
			if ( ! $this->tick_loop_active ) {
				wp_schedule_single_event( time() + 5, 'tailwatch_files_integrity_scan' );
			}
			if ( ! empty( $process_id ) ) {
				$this->process_manager->heart_beat( $process_id );
			}

			return true; // More batches remain.
		}

		return false; // Defensive: partial batch not flagged completed — treat as done.
	}

	/**
	 * Builds tree structure from flat file list.
	 *
	 * @param array  $files           Flat file paths.
	 * @param string $path_to_monitor Base path.
	 * @return array
	 */
	private function build_tree_structure( $files, $path_to_monitor, $baseline_map = null, $scan_mode = 'thorough' ) {
		$tree = array();

		$base_length = strlen( $path_to_monitor );
		$skip_large  = $this->tailwatch_skip_large_files();

		foreach ( $files as $file_path ) {

			// Relative path computed below (works for local and live).

			$relative_path = trim( substr( $file_path, $base_length ), DIRECTORY_SEPARATOR );

			$relative_path = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative_path );

			$path_parts = explode( DIRECTORY_SEPARATOR, $relative_path );

			$current_node = &$tree;

			foreach ( $path_parts as $index => $part ) {
				if ( $index < count( $path_parts ) - 1 ) {
					if ( ! isset( $current_node[ $part ] ) ) {
						$current_node[ $part ] = array(
							'folder_name'      => $part,
							'path'             => $path_to_monitor . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, array_slice( $path_parts, 0, $index + 1 ) ),
							'sub_folder_files' => array(),
						);
					}
					$current_node = &$current_node[ $part ]['sub_folder_files'];
				} else {
					$size = filesize( $file_path );
					// Skip mode: large files are not recorded or monitored.
					if ( $skip_large && false !== $size && $size > self::LARGE_FILE_BYTES ) {
						continue;
					}

					$mtime = filemtime( $file_path );

					// Fast-mode gate: if size AND mtime are unchanged from the baseline,
					// reuse the stored hash instead of re-reading + hashing the file. The
					// hash stays authoritative — any size/mtime difference (or 'thorough'
					// mode, or a file with no baseline row) falls through to a real hash
					// below. A benign content change always bumps mtime, so a real change is
					// never missed; worst case is an unnecessary hash (correct, just slower).
					// $baseline_map is null on the baseline build → always hashes.
					$file_hash = null;
					if ( 'fast' === $scan_mode && is_array( $baseline_map ) && isset( $baseline_map[ $file_path ] ) ) {
						$base_row = $baseline_map[ $file_path ];
						if ( isset( $base_row['size'], $base_row['modified'], $base_row['file_hash'] )
							&& (int) $base_row['size'] === (int) $size
							&& (int) $base_row['modified'] === (int) $mtime ) {
							$file_hash = $base_row['file_hash'];
						}
					}
					if ( null === $file_hash ) {
						$file_hash = $this->tailwatch_compute_file_hash_protected( $file_path, $size );
					}

					$current_node[] = array(
						'file_name'         => $part,
						'file_path'         => $file_path,
						'file_hash'         => $file_hash,
						'size'              => $size,
						'comparison_status' => false,
						'modified'          => $mtime,
						'file_permission'   => substr( sprintf( '%o', fileperms( $file_path ) ), -4 ),
					);
				}
			}
		}

		return $this->convert_tree_to_json_format( $tree );
	}

	/**
	 * Converts tree to JSON-friendly array format.
	 *
	 * @param array $tree Tree structure.
	 * @return array
	 */
	private function convert_tree_to_json_format( $tree ) {
		$output = array();

		foreach ( $tree as $folder_name => $folder_data ) {
			if ( isset( $folder_data['sub_folder_files'] ) ) {
				$output[] = array(
					'folder_name'      => $folder_name,
					'path'             => $folder_data['path'],
					'sub_folder_files' => $this->convert_tree_to_json_format( $folder_data['sub_folder_files'] ),
				);
			} else {
				$output[] = $folder_data;
			}
		}

		return $output;
	}

	/**
	 * Logs file info to the current status file.
	 *
	 * @param array  $tree_structure  Tree structure.
	 * @param string $path_to_monitor Path.
	 * @param bool   $is_completed    Whether scan is completed.
	 */
	private function log_file_info( $tree_structure, $path_to_monitor, $is_completed ) {
		// Persist this batch's file rows into tw_filemon_baseline (keyed on
		// md5(file_path) — re-scans UPDATE in place). Batch-scoped, bounded memory.
		// $is_completed is signalled separately via the progress file (see
		// tailwatch_get_is_completed), so nothing per-path is stored here.
		$this->tailwatch_baseline_db_upsert( $tree_structure, $path_to_monitor );
	}

	/**
	 * Flatten one scan batch's folder tree into baseline rows and upsert them
	 * into tw_filemon_baseline via FileMonModel. Batch-scoped, never the whole
	 * baseline.
	 *
	 * @param array  $tree_structure  Batch folder tree (file leaves carry
	 *                                file_path, file_hash, size, modified,
	 *                                file_permission).
	 * @param string $path_to_monitor Monitored root.
	 * @return void
	 */
	private function tailwatch_baseline_db_upsert( $tree_structure, $path_to_monitor ) {
		$rows = $this->tailwatch_baseline_rows_from_leaves( $this->map_files_by_path( $tree_structure ), $path_to_monitor );
		if ( ! empty( $rows ) ) {
			( new FileMonModel() )->upsert_baseline( $rows );
		}
	}

	/**
	 * Map flattened file leaves to tw_filemon_baseline row arrays.
	 *
	 * @param array  $leaves          path => leaf (file_path, file_hash, size,
	 *                                modified [unix ts], file_permission).
	 * @param string $path_to_monitor Monitored root.
	 * @return array
	 */
	private function tailwatch_baseline_rows_from_leaves( $leaves, $path_to_monitor ) {
		$rows = array();
		foreach ( (array) $leaves as $leaf ) {
			if ( empty( $leaf['file_path'] ) ) {
				continue;
			}
			$rows[] = array(
				'file_path'       => $leaf['file_path'],
				'file_name'       => isset( $leaf['file_name'] ) ? $leaf['file_name'] : basename( $leaf['file_path'] ),
				'file_hash'       => isset( $leaf['file_hash'] ) ? $leaf['file_hash'] : '',
				'file_size'       => isset( $leaf['size'] ) ? (int) $leaf['size'] : 0,
				'file_modified'   => gmdate( 'Y-m-d H:i:s', isset( $leaf['modified'] ) ? (int) $leaf['modified'] : 0 ),
				'file_permission' => isset( $leaf['file_permission'] ) ? $leaf['file_permission'] : '',
				'monitored_path'  => $path_to_monitor,
			);
		}
		return $rows;
	}

	/**
	 * Write one comparison_status block into tw_filemon_scans (+ its change-tree
	 * sidecar). Insert when the row is new, update otherwise (per-batch
	 * accumulation by generate_comparison_snapshot).
	 *
	 * @param FileMonModel $file_mon Repository.
	 * @param array        $status   A comparison_status block.
	 * @return void
	 */
	private function tailwatch_write_scan_status( $file_mon, $status ) {
		if ( ! isset( $status['id'] ) ) {
			return;
		}
		$scan_id = (int) $status['id'];
		$ref     = $file_mon->write_sidecar( $scan_id, isset( $status['folder_files'] ) ? $status['folder_files'] : array() );

		$fields = array(
			'monitored_path'       => isset( $status['path'] ) ? $status['path'] : '',
			'folder_name'          => isset( $status['folder_name'] ) ? $status['folder_name'] : '',
			'scan_time'            => isset( $status['scan_time'] ) ? $status['scan_time'] : current_time( 'mysql' ),
			'is_completed'         => empty( $status['is_completed'] ) ? 0 : 1,
			'malware_status'       => isset( $status['malware_status'] ) ? $status['malware_status'] : 'Inactive',
			'suspicious_handling'  => isset( $status['suspicious_handling'] ) ? $status['suspicious_handling'] : 'No',
			'old_total_scan_files' => isset( $status['old_total_scan_files'] ) ? (int) $status['old_total_scan_files'] : 0,
			'new_scan_file'        => isset( $status['new_scan_file'] ) ? (int) $status['new_scan_file'] : 0,
			'total_captured_file'  => isset( $status['total_captured_file'] ) ? (int) $status['total_captured_file'] : 0,
			'added_count'          => isset( $status['added_count'] ) ? (int) $status['added_count'] : 0,
			'modified_count'       => isset( $status['modified_count'] ) ? (int) $status['modified_count'] : 0,
			'deleted_count'        => isset( $status['deleted_count'] ) ? (int) $status['deleted_count'] : 0,
			'folder_files_ref'     => $ref,
		);

		if ( null === $file_mon->get_scan( $scan_id ) ) {
			$file_mon->insert_scan( $scan_id, $fields );
		} else {
			$file_mon->update_scan( $scan_id, $fields );
		}
	}

	/**
	 * Reconstruct a comparison_status block (the legacy JSON shape) from a
	 * tw_filemon_scans row plus its change-tree sidecar.
	 *
	 * @param array $row          Scan row.
	 * @param array $folder_files Sidecar change-tree.
	 * @return array
	 */
	private function tailwatch_scan_status_from_row( $row, $folder_files ) {
		return array(
			'id'                   => (int) $row['scan_id'],
			'malware_status'       => $row['malware_status'],
			'suspicious_handling'  => isset( $row['suspicious_handling'] ) ? $row['suspicious_handling'] : 'No',
			'path'                 => $row['monitored_path'],
			'folder_name'          => $row['folder_name'],
			'scan_time'            => $row['scan_time'],
			'is_completed'         => ( 1 === (int) $row['is_completed'] ),
			'comparison_json'      => true,
			'old_total_scan_files' => (int) $row['old_total_scan_files'],
			'new_scan_file'        => (int) $row['new_scan_file'],
			'total_captured_file'  => (int) $row['total_captured_file'],
			'added_count'          => (int) $row['added_count'],
			'modified_count'       => (int) $row['modified_count'],
			'deleted_count'        => (int) $row['deleted_count'],
			'folder_files'         => is_array( $folder_files ) ? $folder_files : array(),
		);
	}

	/**
	 * Public: the latest comparison (reconstructed from tw_filemon_scans + its
	 * sidecar), or null when none exists. Lets the pro malware handoff read the
	 * latest comparison without touching comparison_status.json directly.
	 *
	 * @return array|null
	 */
	public function tailwatch_get_latest_comparison() {
		$file_mon = new FileMonModel();
		$res      = $file_mon->list_scans( array( 'completed_only' => false, 'limit' => 1, 'offset' => 0 ) );
		if ( empty( $res['rows'] ) ) {
			return null;
		}
		$row = $res['rows'][0];
		return $this->tailwatch_scan_status_from_row( $row, $file_mon->read_sidecar( (int) $row['scan_id'] ) );
	}

	/**
	 * Public: aggregated integrity dashboard stats from tw_filemon_scans. Sums the
	 * per-scan counters in SQL (no folder_files trees loaded), so the pro security
	 * dashboard no longer reads/decodes the whole comparison_status.json.
	 *
	 * @param int $period_days Window, in days, for the "period" split.
	 * @return array{totals:array,period:array,last_comparison:?array}
	 */
	public function tailwatch_get_comparison_stats( $period_days = 30 ) {
		$period_days  = max( 1, (int) $period_days );
		$period_start = gmdate( 'Y-m-d H:i:s', time() - ( $period_days * DAY_IN_SECONDS ) );

		$file_mon = new FileMonModel();
		$agg      = $file_mon->aggregate_scan_stats( $period_start );

		$last_comparison = null;
		if ( ! empty( $agg['last'] ) ) {
			$row             = $agg['last'];
			$last_comparison = array(
				'id'                   => isset( $row['scan_id'] ) ? (int) $row['scan_id'] : null,
				'scan_time'            => $row['scan_time'] ?? null,
				'path'                 => $row['monitored_path'] ?? null,
				'folder_name'          => $row['folder_name'] ?? null,
				'old_total_scan_files' => isset( $row['old_total_scan_files'] ) ? (int) $row['old_total_scan_files'] : 0,
				'new_scan_file'        => isset( $row['new_scan_file'] ) ? (int) $row['new_scan_file'] : 0,
				'total_captured_file'  => isset( $row['total_captured_file'] ) ? (int) $row['total_captured_file'] : 0,
				'modified'             => isset( $row['modified_count'] ) ? (int) $row['modified_count'] : 0,
				'new'                  => isset( $row['added_count'] ) ? (int) $row['added_count'] : 0,
				'deleted'              => isset( $row['deleted_count'] ) ? (int) $row['deleted_count'] : 0,
			);
		}

		return array(
			'totals'          => $agg['totals'],
			'period'          => array(
				'days'          => $period_days,
				'comparisons'   => $agg['period']['comparisons'],
				'changes_total' => $agg['period']['changes_total'],
			),
			'last_comparison' => $last_comparison,
		);
	}

	/**
	 * Public: the comparison_status for a scan id (row + sidecar), or null. Lets
	 * the pro baseline updater read a specific comparison without decoding
	 * comparison_status.json.
	 *
	 * @param int $scan_id Comparison/scan id.
	 * @return array|null
	 */
	public function tailwatch_get_comparison_by_id( $scan_id ) {
		$scan_id = (int) $scan_id;
		if ( $scan_id <= 0 ) {
			return null;
		}
		$file_mon = new FileMonModel();
		$row      = $file_mon->get_scan( $scan_id );
		if ( empty( $row ) ) {
			return null;
		}
		return $this->tailwatch_scan_status_from_row( $row, $file_mon->read_sidecar( $scan_id ) );
	}

	/**
	 * Public: update whitelisted scalar fields on a comparison's scan row
	 * (malware_scan / malware_status). Returns true on success.
	 *
	 * @param int   $scan_id Comparison/scan id.
	 * @param array $fields  Whitelisted fields (see FileMonModel::update_scan).
	 * @return bool
	 */
	public function tailwatch_update_comparison( $scan_id, array $fields ) {
		$scan_id = (int) $scan_id;
		if ( $scan_id <= 0 || empty( $fields ) ) {
			return false;
		}
		return ( new FileMonModel() )->update_scan( $scan_id, $fields );
	}

	/**
	 * Public: apply a comparison's accepted changes to the baseline table —
	 * re-hash new/modified files from disk and upsert them, delete removed files.
	 * Replaces the pro updater's load-tree / mutate / save-tree cycle, so the whole
	 * baseline is never held in memory.
	 *
	 * @param string $monitored_path Monitored root the changes belong to.
	 * @param array  $changes        Each: file_path (absolute), status (new|modified|deleted).
	 * @return array stats { modified, new, deleted, skipped, errors }.
	 */
	public function tailwatch_baseline_apply_changes( $monitored_path, array $changes ) {
		$stats = array(
			'modified' => 0,
			'new'      => 0,
			'deleted'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);
		if ( empty( $changes ) ) {
			return $stats;
		}

		$file_mon      = new FileMonModel();
		$upsert_leaves = array();
		$delete_hashes = array();

		foreach ( $changes as $change ) {
			$file_path = isset( $change['file_path'] ) ? (string) $change['file_path'] : '';
			$status    = isset( $change['status'] ) ? $change['status'] : '';
			if ( '' === $file_path ) {
				++$stats['errors'];
				continue;
			}

			if ( 'deleted' === $status ) {
				$delete_hashes[] = FileMonModel::path_key( $file_path );
				++$stats['deleted'];
				continue;
			}

			if ( 'new' === $status || 'modified' === $status ) {
				$leaf = $this->tailwatch_baseline_leaf_from_path( $file_path );
				if ( null === $leaf ) {
					++$stats['skipped'];
					continue;
				}
				$upsert_leaves[] = $leaf;
				if ( 'new' === $status ) {
					++$stats['new'];
				} else {
					++$stats['modified'];
				}
				continue;
			}

			++$stats['errors'];
		}

		if ( ! empty( $upsert_leaves ) ) {
			$file_mon->upsert_baseline( $this->tailwatch_baseline_rows_from_leaves( $upsert_leaves, $monitored_path ) );
		}
		if ( ! empty( $delete_hashes ) ) {
			$file_mon->delete_by_hashes( $delete_hashes );
		}
		return $stats;
	}

	/**
	 * Public: re-hash existing on-disk files and upsert them into the baseline
	 * table. Used by the malware "cleaned files" path (files malware touched but
	 * integrity did not flag). Missing/oversized files are skipped, not errors.
	 *
	 * @param string $monitored_path Monitored root.
	 * @param array  $file_paths     Absolute file paths.
	 * @return array { updated, skipped }.
	 */
	public function tailwatch_baseline_upsert_paths( $monitored_path, array $file_paths ) {
		$result = array(
			'updated' => 0,
			'skipped' => 0,
		);
		if ( empty( $file_paths ) ) {
			return $result;
		}

		$leaves = array();
		foreach ( $file_paths as $file_path ) {
			$leaf = $this->tailwatch_baseline_leaf_from_path( (string) $file_path );
			if ( null === $leaf ) {
				++$result['skipped'];
				continue;
			}
			$leaves[] = $leaf;
			++$result['updated'];
		}
		if ( ! empty( $leaves ) ) {
			( new FileMonModel() )->upsert_baseline( $this->tailwatch_baseline_rows_from_leaves( $leaves, $monitored_path ) );
		}
		return $result;
	}

	/**
	 * Build a baseline leaf (hash/size/mtime/perm) from an on-disk file, or null
	 * when the file is missing or too large to hash.
	 *
	 * @param string $file_path Absolute path.
	 * @return array|null
	 */
	private function tailwatch_baseline_leaf_from_path( $file_path ) {
		if ( '' === $file_path || ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
			return null;
		}
		$size = filesize( $file_path );
		if ( false === $size ) {
			return null;
		}
		// Skip mode: do not record large files, so accept stays aligned with the scan.
		if ( $this->tailwatch_skip_large_files() && $size > self::LARGE_FILE_BYTES ) {
			return null;
		}
		$hash = $this->tailwatch_compute_file_hash_protected( $file_path, $size );
		if ( '' === $hash ) {
			return null;
		}
		return array(
			'file_path'       => $file_path,
			'file_name'       => basename( $file_path ),
			'file_hash'       => $hash,
			'size'            => (int) $size,
			'modified'        => (int) filemtime( $file_path ),
			'file_permission' => substr( sprintf( '%o', fileperms( $file_path ) ), -4 ),
		);
	}

	/**
	 * Full SHA-256 of a file's contents. The SAME formula is used on baseline
	 * build and on comparison, so an unchanged file compares equal. Large files
	 * are gated by the Large File Scanning option BEFORE this is called (Skip mode
	 * never reaches here for them). Returns '' when the file cannot be read.
	 *
	 * @param string $file_path Absolute path.
	 * @return string 64-char hash, or '' on failure.
	 */
	private function tailwatch_compute_file_hash( $file_path ) {
		// A non-file (e.g. an empty directory that slips into the walk) hashes to ''
		// either way; guard first so hash_file() does not emit an EISDIR notice.
		if ( ! is_file( $file_path ) ) {
			return '';
		}
		$hash = hash_file( 'sha256', $file_path );
		return false === $hash ? '' : $hash;
	}

	/**
	 * Content hash with a stall guard for very large files. A single SHA-256 streams
	 * every byte, so a multi-GB file can run past a locked-down host's
	 * max_execution_time and kill the request MID-HASH — before the cursor advances —
	 * making the same batch re-run and re-kill forever. For files over
	 * PROTECTED_HASH_BYTES we persist an attempt counter BEFORE hashing (so a kill still
	 * counts) and, after PROTECTED_HASH_MAX_ATTEMPTS kills, degrade THAT file to a cheap
	 * size+mtime marker (still change-detected) so the scan always makes progress. On a
	 * capable host the first attempt succeeds and the counter is cleared — identical to
	 * a normal hash. Files at/below the threshold hash directly (no overhead).
	 *
	 * @param string   $file_path Absolute path.
	 * @param int|false $size     filesize() result (false when unknown).
	 * @return string Hash, oversize marker, or '' on read failure.
	 */
	private function tailwatch_compute_file_hash_protected( $file_path, $size ) {
		if ( false === $size || $size <= self::PROTECTED_HASH_BYTES ) {
			return $this->tailwatch_compute_file_hash( $file_path );
		}

		$key      = 'tailwatch_fim_bighash_' . FileMonModel::path_key( $file_path );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= self::PROTECTED_HASH_MAX_ATTEMPTS ) {
			// Repeatedly un-hashable on this host — track by size+mtime instead of stalling.
			delete_transient( $key );
			return $this->tailwatch_oversize_marker( $file_path, $size );
		}

		// Persist the attempt BEFORE the risky hash so a process-kill still counts.
		set_transient( $key, $attempts + 1, DAY_IN_SECONDS );
		$hash = $this->tailwatch_compute_file_hash( $file_path );
		delete_transient( $key ); // Survived → clear the streak.

		return $hash;
	}

	/**
	 * Deterministic, content-free fingerprint for a file too large to hash on this host.
	 * Same size+mtime → same marker (no false "modified"); any size/mtime change → a
	 * different marker → correctly flagged. Mirrors the hash slot in a leaf row.
	 *
	 * @param string    $file_path Absolute path.
	 * @param int|false $size      filesize() result.
	 * @return string
	 */
	private function tailwatch_oversize_marker( $file_path, $size ) {
		return 'oversize:' . (int) $size . ':' . (int) filemtime( $file_path );
	}


	/**
	 * Walks a folder tree once and returns counts by file status.
	 *
	 * Returns the same total as count_files_in_tree() plus per-status
	 * breakdown — added (status='new'), modified, deleted. The per-status
	 * counts let downstream consumers (e.g. the malware scanner gate) skip
	 * work cheaply when changes are deletion-only and there is nothing
	 * scannable. Cost is identical to count_files_in_tree(): one tree walk,
	 * one branch per leaf — just three extra integer increments per file.
	 *
	 * @param array $tree Folder tree as built by build_tree().
	 * @return array Indexed [ $total, $added, $modified, $deleted ].
	 */
	private function count_files_by_status( array $tree ) {
		$total    = 0;
		$added    = 0;
		$modified = 0;
		$deleted  = 0;

		foreach ( $tree as $node ) {
			if ( isset( $node['sub_folder_files'] ) ) {
				list( $st, $sa, $sm, $sd ) = $this->count_files_by_status( $node['sub_folder_files'] );
				$total    += $st;
				$added    += $sa;
				$modified += $sm;
				$deleted  += $sd;
				continue;
			}

			++$total;
			$status = isset( $node['status'] ) ? $node['status'] : '';
			if ( 'new' === $status ) {
				++$added;
			} elseif ( 'modified' === $status ) {
				++$modified;
			} elseif ( 'deleted' === $status ) {
				++$deleted;
			}
		}

		return array( $total, $added, $modified, $deleted );
	}

	/**
	 * Loads progress from progress file.
	 *
	 * @param string $progress_file Path to progress file.
	 * @return array|null
	 */
	private function load_progress( $progress_file ) {
		if ( file_exists( $progress_file ) ) {
			return json_decode( $this->read_file_contents( $progress_file ), true );
		}
		return array( 'last_scanned_file' => null );
	}

	/**
	 * Saves progress to progress file.
	 *
	 * @param string   $progress_file     Path to progress file.
	 * @param string   $last_scanned_file Last scanned file path.
	 * @param int|null $process_id        Process ID.
	 * @param int|null $queue_offset      Discovery-queue byte offset (null = omit).
	 */
	private function save_progress( $progress_file, $last_scanned_file, $process_id = null, $queue_offset = null, $queue_total = null, $queue_processed = null ) {
		$progress_data = array(
			'last_scanned_file' => $last_scanned_file,
		);

		if ( ! empty( $process_id ) ) {
			$progress_data['process_id'] = $process_id;
		}

		if ( null !== $queue_offset ) {
			$progress_data['scan_queue_offset'] = (int) $queue_offset;
		}

		// Baseline-build progress for the "X of Y files" status display.
		if ( null !== $queue_total ) {
			$progress_data['queue_total'] = (int) $queue_total;
		}

		if ( null !== $queue_processed ) {
			$progress_data['queue_processed'] = (int) $queue_processed;
		}

		$this->write_file_contents( $progress_file, wp_json_encode( $progress_data, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Returns directories in ABSPATH that are not wp-admin, wp-includes, or wp-content.
	 *
	 * @return array
	 */
	public function get_root_folders_name() {
		$dir = new \DirectoryIterator( realpath( ABSPATH ) );

		$keep_root_paths = array(
			realpath( ABSPATH . 'wp-admin' ),
			realpath( ABSPATH . WPINC ),
			realpath( WP_CONTENT_DIR ),
		);

		$exclude_root_folders = array();

		foreach ( $dir as $fileinfo ) {
			if ( $fileinfo->isDir() && ! $fileinfo->isDot() ) {
				$dirname = $fileinfo->getRealPath();
				if ( ! in_array( $dirname, $keep_root_paths, true ) ) {
					$exclude_root_folders[] = $dirname;
				}
			}
		}

		return $exclude_root_folders;
	}

	/**
	 * Scans files in directory with optional resume from last file.
	 *
	 * @param string      $directory         Directory to scan.
	 * @param string|null $last_scanned_file Last scanned file to resume from.
	 * @param int         $batch_size        Batch size.
	 * @return array
	 */
	private function scan_files( $directory, $last_scanned_file = null, $batch_size = 500 ) {
		$files              = array();
		$continue_from_last = is_null( $last_scanned_file );

		$skip_root_folders = $this->get_root_folders_name();

		$skip_folders_in_wp_content = array(
			str_replace( '\\', '/', TAILWATCH_CONTENT_DIR_BASE ),
			str_replace( '\\', '/', TAILWATCH_LOGS_DIRECTORY ),
		);

		$skip_these_folders = array_merge( $skip_root_folders, $skip_folders_in_wp_content );

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			$file_path      = $file->getPathname();
			$file_path_skip = str_replace( '\\', '/', $file_path );

			foreach ( $skip_these_folders as $skip_folder ) {
				// Skip folder and file path used for exclusion check.
				if ( 0 === strpos( $file_path_skip, $skip_folder ) ) {
					continue 2;
				}
			}

			if ( ! $continue_from_last ) {
				if ( $file->getPathname() === $last_scanned_file ) {
					$continue_from_last = true;
					continue;
				}
				continue;
			}

			$base = strtolower( $file->getFilename() );
			if ( 'error_log' === $base || 'debug.log' === $base ) {
				continue;
			}

			$files[] = $file->getPathname();

			if ( count( $files ) >= $batch_size ) {
				break;
			}
		}

		return $files;
	}

	/**
	 * Per-scan discovery-queue path for a given progress context. Keyed by the
	 * progress file (baseline vs comparison have distinct ones), so a scan owns
	 * exactly one queue and cleanup is deterministic.
	 *
	 * @param string $progress_file Progress file this scan resumes from.
	 * @return string
	 */
	private function tailwatch_scan_queue_path( $progress_file ) {
		return $this->log_directory . '/scan_queue_' . md5( $progress_file ) . '.txt';
	}

	/**
	 * Walk the tree ONCE and stream every in-scope file path (same skip rules as
	 * scan_files) into a newline-delimited queue file, published atomically via a
	 * temp + rename. Lets batches resume by byte offset instead of re-walking and
	 * skipping file-by-file (the old O(N^2) cost). Bounded memory: the iterator is
	 * lazy and paths are written one at a time.
	 *
	 * @param string $directory  Root to walk.
	 * @param string $queue_file Destination queue path.
	 * @return int|false Number of in-scope files queued, or false on failure.
	 */
	private function tailwatch_build_scan_queue( $directory, $queue_file ) {
		$skip_these_folders = array_merge(
			$this->get_root_folders_name(),
			array(
				str_replace( '\\', '/', TAILWATCH_CONTENT_DIR_BASE ),
				str_replace( '\\', '/', TAILWATCH_LOGS_DIRECTORY ),
			)
		);

		$tmp = $queue_file . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streaming write of a large cursor file; WP_Filesystem has no append/stream API; @ swallows host warnings, false-check follows.
		$handle = @fopen( $tmp, 'wb' );
		if ( false === $handle ) {
			return false;
		}

		$count = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				$file_path      = $file->getPathname();
				$file_path_skip = str_replace( '\\', '/', $file_path );

				foreach ( $skip_these_folders as $skip_folder ) {
					if ( 0 === strpos( $file_path_skip, $skip_folder ) ) {
						continue 2;
					}
				}

				$base = strtolower( $file->getFilename() );
				if ( 'error_log' === $base || 'debug.log' === $base ) {
					continue;
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming write; see fopen above.
				fwrite( $handle, $file_path . "\n" );
				++$count;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream handle close.
			fclose( $handle );
			wp_delete_file( $tmp );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream handle close.
		fclose( $handle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- atomic publish of the queue file; @ swallows host warnings, false return is checked.
		if ( ! @rename( $tmp, $queue_file ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		// Total in-scope files, so the status check can show "X of Y". false on failure.
		return $count;
	}

	/**
	 * Return the next batch of file paths from the discovery queue, building the
	 * queue once at the start of a scan (offset 0). Resume is O(1) — seek to the
	 * stored byte offset and read batch_size lines. Returns null to signal the
	 * caller to fall back to the legacy re-walk (queue unavailable / lost), so
	 * correctness degrades gracefully to current behaviour.
	 *
	 * @param string $directory     Root to scan.
	 * @param string $progress_file Progress file (carries scan_queue_offset).
	 * @param int    $batch_size    Files per batch.
	 * @return array|null { files: string[], next_offset: int } or null.
	 */
	private function tailwatch_scan_batch_from_queue( $directory, $progress_file, $batch_size ) {
		$queue_file  = $this->tailwatch_scan_queue_path( $progress_file );
		$progress    = (array) $this->load_progress( $progress_file );
		$offset      = isset( $progress['scan_queue_offset'] ) ? (int) $progress['scan_queue_offset'] : 0;
		$queue_total = null; // Set only on the build tick; reused from storage afterwards.

		if ( 0 === $offset ) {
			// Only (re)build at a TRUE scan start. If an earlier batch already ran in
			// fallback mode (last_scanned_file set but no offset), stay in fallback —
			// rebuilding here would re-enumerate already-processed files.
			if ( ! empty( $progress['last_scanned_file'] ) ) {
				return null;
			}
			// Start of a scan: build the queue fresh for the current tree.
			$queue_total = $this->tailwatch_build_scan_queue( $directory, $queue_file );
			if ( false === $queue_total ) {
				return null;
			}
		} elseif ( ! file_exists( $queue_file ) ) {
			// Queue lost mid-scan: fall back to the legacy resume by last_scanned_file.
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- partial sequential read of a large cursor file; WP_Filesystem cannot seek; @ swallows host warnings, false-check follows.
		$handle = @fopen( $queue_file, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$files = array();
		while ( count( $files ) < $batch_size ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- streaming read; see fopen above.
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' !== $line ) {
				$files[] = $line;
			}
		}
		$next_offset = ftell( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream handle close.
		fclose( $handle );

		return array(
			'files'       => $files,
			'next_offset' => (int) $next_offset,
			'queue_total' => $queue_total,
		);
	}

	/**
	 * Remove a scan's discovery queue file (called at completion / reset).
	 *
	 * @param string $progress_file Progress file the queue is keyed by.
	 * @return void
	 */
	private function tailwatch_delete_scan_queue( $progress_file ) {
		$queue_file = $this->tailwatch_scan_queue_path( $progress_file );
		if ( file_exists( $queue_file ) ) {
			wp_delete_file( $queue_file );
		}
	}

	/**
	 * Runs one comparison batch for the monitored path.
	 *
	 * @param string $path_to_monitor Path to monitor.
	 * @return bool True if more batches remain, false when complete/paused/no-id.
	 */
	public function comparison_json_verification( $path_to_monitor ) {
		$progress_data = $this->get_files_integrity_progress();
		$next_id       = ( is_array( $progress_data ) && isset( $progress_data['id'] ) ) ? $progress_data['id'] : null;

		// No comparison id yet (missing/corrupt progress row) — skip this run; the
		// scheduler retries on the next tick rather than scanning with a null id.
		if ( null === $next_id ) {
			return false;
		}

		if ( true === $this->tailwatch_stop_integrity_execution() ) {
			return false;
		}

		return $this->generate_comparison_snapshot( $path_to_monitor, $next_id );
	}

	/**
	 * Runs one batch of the comparison and accumulates the result into the scan's
	 * tw_filemon_scans row + sidecar.
	 *
	 * @param string $path_to_monitor Path to monitor.
	 * @param int    $next_id         Comparison ID.
	 * @return bool True if more batches remain, false when the comparison is complete,
	 *              paused/cancelled, empty, or errored.
	 */
	public function generate_comparison_snapshot( $path_to_monitor, $next_id ) {
		try {
			$normalized_path_to_monitor = rtrim( $path_to_monitor, DIRECTORY_SEPARATOR );

			$file_mon          = new FileMonModel();
			$progress_file     = $this->comparison_progress_file;
			$progress          = (array) $this->load_progress( $progress_file );
			$last_scanned_file = $progress['last_scanned_file'] ?? null;

			// Snapshot metadata comes straight from the baseline table.
			$initial_snapshot = array(
				'path'             => $path_to_monitor,
				'folder_name'      => basename( $normalized_path_to_monitor ),
				'total_scan_files' => $file_mon->count_baseline( $path_to_monitor ),
				'folder_files'     => array(),
			);

			// The comparison accumulates across batches in tw_filemon_scans + its
			// sidecar; $prior_row is this comparison's running total so far.
			$prior_row     = $file_mon->get_scan( $next_id );
			$prior_scanned = ( null !== $prior_row ) ? (int) $prior_row['new_scan_file'] : 0;

			// Discovery decouple: walk-once queue with byte-offset resume; legacy
			// re-walk as fallback (see generate_initial_snapshot).
			$queued = $this->tailwatch_scan_batch_from_queue( $path_to_monitor, $progress_file, $this->batch_size );
			if ( null === $queued ) {
				$current_files_batch = $this->scan_files( $path_to_monitor, $last_scanned_file, $this->batch_size );
				$queue_offset        = null;
			} else {
				$current_files_batch = $queued['files'];
				$queue_offset        = $queued['next_offset'];
			}
			// Fetch this batch's baseline rows BEFORE hashing so Fast mode can gate the hash
			// on size+mtime (reuse the stored hash for unchanged files). Reused for the
			// comparison below — one lookup, not two. Keyed by path_key (md5 of the
			// normalized path), the same key compare_files matches on. Built from the raw
			// batch paths (not the post-build map) because the gate needs it pre-hash.
			$batch_hashes          = array_map( array( FileMonModel::class, 'path_key' ), $current_files_batch );
			$initial_files_for_map = $this->tailwatch_db_baseline_map( $file_mon, $path_to_monitor, $batch_hashes );

			$current_files       = $this->build_tree_structure( $current_files_batch, $path_to_monitor, $initial_files_for_map, $this->tailwatch_integrity_scan_mode() );
			$current_files_map   = $this->map_files_by_path( $current_files );

			// An empty batch after files were already scanned (total an exact multiple
			// of the batch size) is the comparison's FINAL tick: do NOT bail — deletions
			// must still be detected and the run completed (otherwise it hangs forever
			// with is_completed=false and the malware handoff never fires). Only a truly
			// empty first scan returns here.
			$finalizing_empty = ( empty( $current_files_map ) && $prior_scanned > 0 );
			if ( empty( $current_files_map ) && ! $finalizing_empty ) {
				return false; // Truly empty first scan — nothing to compare; done.
			}

			if ( ! $finalizing_empty ) {
				$last_scanned_file = end( $current_files_batch );
				$this->save_progress( $progress_file, $last_scanned_file, null, $queue_offset );
			}

			$new_scan_files   = count( $current_files_batch );
			$start_file_index = $prior_scanned;

			$end_file_index = $new_scan_files + $start_file_index;
			$message_start  = "Scanning files: $start_file_index-$end_file_index";
			$this->update_integrity_logs_records( $message_start, 'INFO' );

			$comparison_files = $this->compare_files( $initial_files_for_map, $path_to_monitor, $current_files_map, $next_id, $file_mon );
			$is_completed     = ( count( $current_files_batch ) < $this->batch_size );
			if ( $is_completed ) {
				$deleted_files    = $this->tailwatch_detect_deleted_files( $path_to_monitor, $next_id, $file_mon );
				$comparison_files = array_merge( $comparison_files, $deleted_files );
			}
			list( $total_captured_file, $added_count, $modified_count, $deleted_count ) = $this->count_files_by_status( $comparison_files );

			if ( 0 === $total_captured_file ) {
				$message_part = "Scanned $start_file_index-$end_file_index: no changes";
			} else {
				$message_part = "Scanned $start_file_index-$end_file_index: $total_captured_file " . ( 1 === $total_captured_file ? 'change' : 'changes' );
			}
			$this->update_integrity_logs_records( $message_part, 'OK' );

			$progress_data = $this->get_files_integrity_progress();
			$pid_meta      = isset( $progress_data['process_id'] ) ? $progress_data['process_id'] : ( $this->current_process_id ?? null );
			if ( $pid_meta ) {
				$this->process_manager->update_metadata(
					$pid_meta,
					array(
						'new_scan_file'       => $new_scan_files,
						'total_captured_file' => $total_captured_file,
						'is_completed'        => $is_completed,
						'range'               => array( $start_file_index, $end_file_index ),
					)
				);
			}

			// Whether to trigger an AI malware scan on the changed files. Opt-in extension point:
			// the tailwatch_integrity_malware_scan filter. Free ships no listener, so it returns the
			// default (enabled=false) -> a clean no-op; extensions hook it to run the scan and set
			// the initial status shown in comparison logs.
			$malware = apply_filters(
				'tailwatch_integrity_malware_scan',
				array(
					'enabled'             => false,
					'status'              => 'Inactive',
					'suspicious_handling' => 'No',
				),
				array(
					'comparison_id'       => $next_id,
					'new_scan_files'      => $new_scan_files,
					'total_captured_file' => $total_captured_file,
				)
			);
			$malware_status_initial = isset( $malware['status'] ) ? (string) $malware['status'] : 'Inactive';
			$suspicious_handling_initial = isset( $malware['suspicious_handling'] ) ? (string) $malware['suspicious_handling'] : 'No';

			// Accumulate this batch into the comparison's row + sidecar (the table is
			// the only store now): counts add up across batches and the change-tree
			// merges. Insert on the first batch, update on later ones (handled inside
			// tailwatch_write_scan_status). Malware fields + scan_time are set on the first
			// batch and preserved thereafter.
			$prior_tree  = ( null !== $prior_row ) ? $file_mon->read_sidecar( $next_id ) : array();
			$merged_tree = $this->merge_folder_files( $prior_tree, $comparison_files );

			$this->tailwatch_write_scan_status(
				$file_mon,
				array(
					'id'                   => $next_id,
					'malware_status'       => ( null !== $prior_row ) ? $prior_row['malware_status'] : $malware_status_initial,
					'suspicious_handling'  => ( null !== $prior_row && isset( $prior_row['suspicious_handling'] ) ) ? $prior_row['suspicious_handling'] : $suspicious_handling_initial,
					'path'                 => $initial_snapshot['path'],
					'folder_name'          => $initial_snapshot['folder_name'],
					'scan_time'            => ( null !== $prior_row ) ? $prior_row['scan_time'] : current_time( 'mysql' ),
					'is_completed'         => $is_completed,
					'old_total_scan_files' => $initial_snapshot['total_scan_files'],
					'new_scan_file'        => $prior_scanned + $new_scan_files,
					'total_captured_file'  => ( null !== $prior_row ? (int) $prior_row['total_captured_file'] : 0 ) + $total_captured_file,
					'added_count'          => ( null !== $prior_row ? (int) $prior_row['added_count'] : 0 ) + $added_count,
					'modified_count'       => ( null !== $prior_row ? (int) $prior_row['modified_count'] : 0 ) + $modified_count,
					'deleted_count'        => ( null !== $prior_row ? (int) $prior_row['deleted_count'] : 0 ) + $deleted_count,
					'folder_files'         => $merged_tree,
				)
			);

			$stop_execution = $this->tailwatch_stop_integrity_execution();

			if ( true === $stop_execution ) {
				return false; // Paused/cancelled — stop the tick loop.
			}
			if ( $is_completed ) {
				$livelogs = new LiveLogsController();
				$livelogs->tailwatch_live_logs_completed( $is_completed, $this->get_live_logs );
				$this->update_integrity_logs_records( 'Scan completed successfully', 'INFO' );

				// Branch the completion log on whether file changes were detected.
				// $total_captured_file is already in scope from the comparison build
				// above. Same action key + same notification gate; only severity
				// and copy differ so the mobile app can render appropriately.
				if ( 0 === (int) $total_captured_file ) {
					Log::info(
						'Good news — no file changes were detected. Your website core files remain intact and secure.',
						array(
							'feature'   => 'files_integrity',
							'action'    => 'files_integrity_completed',
							'title'     => 'No file changes detected',
							'meta_data' => array(
								'feature'        => 'File Integrity Watch',
								'event'          => 'Completed',
								'Files changed'  => 0,
								'threat_level'   => 'None',
								'record_id'      => $next_id,
							),
						)
					);
				} else {
					$changed_count = (int) $total_captured_file;
					$changed_noun  = ( 1 === $changed_count ) ? 'file change' : 'file changes';
					Log::warning(
						"We detected {$changed_count} {$changed_noun} on your website. This may include updates, plugin changes, or unauthorized modifications. Review the report or contact support for help.",
						array(
							'feature'             => 'files_integrity',
							'action'              => 'files_integrity_completed',
							'title'               => "{$changed_count} {$changed_noun} detected",
							'total_captured_file' => $changed_count,
							'meta_data'           => array(
								'feature'        => 'File Integrity Watch',
								'event'          => 'Completed',
								'Files changed'  => $changed_count,
								'threat_level'   => 'Warning',
								'record_id'      => $next_id,
							),
						)
					);
				}

				$result_message = 'Changes found: ' . $total_captured_file;
				$this->update_integrity_logs_records( $result_message, 'RESULT' );

				$progress_data               = $this->get_files_integrity_progress();
				$progress_data['scan_state'] = 'completed';
				$this->update_files_integrity_cancel_pause( $progress_data );

				$process_id = isset( $progress_data['process_id'] ) ? $progress_data['process_id'] : ( $this->current_process_id ?? null );
				if ( $process_id ) {
					$this->process_manager->mark_completed( $process_id );
					$this->current_process_id = null;
				}

				$integrity_data = array(
					'integrity_id'         => $next_id,
					'skip_detection_check' => false,
				);

				do_action( 'tailwatch_after_integrity_check_completed', wp_json_encode( $integrity_data ) );

				// Optional: schedule cleanup event.
				return false; // Comparison complete — no more work.
			} elseif ( count( $current_files_batch ) === $this->batch_size ) {
				// The tick loop owns rescheduling; only self-reschedule when invoked outside it.
				if ( ! $this->tick_loop_active ) {
					wp_schedule_single_event( time() + 5, 'tailwatch_files_integrity_scan' );
				}
				$process_id = isset( $progress_data['process_id'] ) ? $progress_data['process_id'] : ( $this->current_process_id ?? null );
				if ( $process_id ) {
					$this->process_manager->heart_beat( $process_id );
				}

				return true; // More batches remain.
			}

			return false; // Last partial batch already finalized above — done.
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in generate_comparison_snapshot',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_complete_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);

			// Flag the error so the tick loop counts it toward the retry/cap (instead of
			// treating this false as a clean "done"). Return false so partial work this
			// batch is not mistaken for "more work".
			$this->last_tick_errored = true;
			return false;
		}
	}

	/**
	 * Deletes integrity logs and progress data.
	 */
	public function tailwatch_delete_integrity_logs() {
		try {
			$this->tailwatch_delete_files_after_complete();

			$feature_controller = new DBModel();
			$tailwatch_key           = 'default_files_integrity_check';
			$option             = 'files_integrity_progress';
			$feature_controller->delete_recent_row( $tailwatch_key, $option );
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_delete_integrity_logs',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_delete_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
		}
	}

	/**
	 * Deletes temporary files after scan complete.
	 */
	public function tailwatch_delete_files_after_complete() {
		$this->tailwatch_delete_scan_queue( $this->comparison_progress_file );

		if ( file_exists( $this->comparison_progress_file ) ) {
			wp_delete_file( $this->comparison_progress_file );
		}

		if ( file_exists( $this->get_live_logs ) ) {
			wp_delete_file( $this->get_live_logs );
		}
	}

	/**
	 * Merges new folder files into existing folder structure.
	 *
	 * @param array $existing_files Existing structure.
	 * @param array $new_files      New structure.
	 * @return array
	 */
	private function merge_folder_files( $existing_files, $new_files ) {
		foreach ( $new_files as $new_file ) {
			$found = false;

			foreach ( $existing_files as &$existing_file ) {
				if ( isset( $existing_file['folder_name'] ) && isset( $new_file['folder_name'] ) && $new_file['folder_name'] === $existing_file['folder_name'] ) {
					if ( isset( $new_file['sub_folder_files'] ) ) {
						$existing_file['sub_folder_files'] = $this->merge_folder_files( $existing_file['sub_folder_files'], $new_file['sub_folder_files'] );
					}

					if ( isset( $new_file['file_name'] ) ) {
						$existing_file['sub_folder_files'][] = $new_file;
					}
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$existing_files[] = $new_file;
			}
		}

		return $existing_files;
	}

	/**
	 * Compares initial and current file maps and returns comparison list.
	 *
	 * @param array  $initial_files_for_map Initial map.
	 * @param string $current_directory     Current directory.
	 * @param array  $current_files_map     Current map.
	 * @return array
	 */
	private function compare_files( $initial_files_for_map, $current_directory, $current_files_map, $scan_id = 0, $file_mon = null ) {
		$comparison_files = array();
		$files_to_delete  = array();
		$log_messages     = array();

		foreach ( $current_files_map as $file_path => $current_file ) {

			if ( isset( $initial_files_for_map[ $file_path ] ) ) {
				$initial_file = $initial_files_for_map[ $file_path ];
				if ( $current_file['file_hash'] !== $initial_file['file_hash'] ) {
					$comparison_files[] = array(
						'file_name'           => $initial_file['file_name'],
						'file_path'           => $file_path,
						'status'              => 'modified',
						'previous_size'       => $initial_file['size'],
						'current_size'        => $current_file['size'],
						'previous_modified'   => gmdate( 'd-M-Y H-i-s', $initial_file['modified'] ),
						'current_modified'    => gmdate( 'd-M-Y H-i-s', $current_file['modified'] ),
						'previous_permission' => $initial_file['file_permission'],
						'current_permission'  => $current_file['file_permission'],
					);

					$prev_size      = round( $initial_file['size'] / 1024, 1 );
					$curr_size      = round( $current_file['size'] / 1024, 1 );
					$log_messages[] = 'Modified: ' . basename( $file_path ) . ' (' . $prev_size . 'KB → ' . $curr_size . 'KB)';
				}
				$files_to_delete[] = $file_path;
			} else {
				$comparison_files[] = array(
					'file_name'           => $current_file['file_name'],
					'file_path'           => $file_path,
					'status'              => 'new',
					'previous_size'       => '-',
					'current_size'        => $current_file['size'],
					'previous_modified'   => '-',
					'current_modified'    => gmdate( 'd-M-Y H-i-s', $current_file['modified'] ),
					'previous_permission' => '-',
					'current_permission'  => $current_file['file_permission'],
				);

				$file_size      = round( $current_file['size'] / 1024, 1 );
				$log_messages[] = 'New: ' . basename( $file_path ) . ' (' . $file_size . 'KB)';
			}
		}

		// One file read+write for all of this batch's change lines (not one per file).
		$this->update_integrity_logs_records_batch( $log_messages, 'INFO' );

		// Mark matched baseline files as seen this scan; unseen rows become the
		// deleted set (get_deleted).
		if ( $file_mon instanceof FileMonModel ) {
			$file_mon->mark_seen( $scan_id, array_map( array( FileMonModel::class, 'path_key' ), $files_to_delete ) );
		}

		return $this->build_tree( $comparison_files, $current_directory );
	}

	/**
	 * Build the path-keyed baseline map the comparison needs, for ONLY the given
	 * batch paths, from tw_filemon_baseline. Each row is shaped like the legacy
	 * flatten (modified as a unix timestamp for gmdate) so compare_files stays
	 * storage-agnostic.
	 *
	 * @param FileMonModel $file_mon       Repository.
	 * @param string       $monitored_path Monitored root.
	 * @param array        $batch_hashes   md5(file_path) values for this batch.
	 * @return array<string,array>
	 */
	private function tailwatch_db_baseline_map( $file_mon, $monitored_path, array $batch_hashes ) {
		$map = array();
		foreach ( $file_mon->lookup_by_hashes( $monitored_path, $batch_hashes ) as $row ) {
			$map[ $row['file_path'] ] = array(
				'file_name'       => $row['file_name'],
				'file_path'       => $row['file_path'],
				'file_hash'       => $row['file_hash'],
				'size'            => (int) $row['file_size'],
				'modified'        => (int) strtotime( $row['file_modified'] . ' UTC' ),
				'file_permission' => $row['file_permission'],
			);
		}
		return $map;
	}

	/**
	 * Detects files deleted since initial snapshot.
	 *
	 * @param string $current_directory Current directory path.
	 * @return array
	 */
	private function tailwatch_detect_deleted_files( $current_directory, $scan_id = 0, $file_mon = null ) {
		$deleted_files = array();

		if ( $file_mon instanceof FileMonModel ) {
			// Deleted = baseline rows for this root never seen during the scan.
			$skip_large    = $this->tailwatch_skip_large_files();
			$log_messages  = array();
			$deleted_total = 0;
			foreach ( $file_mon->get_deleted( $current_directory, $scan_id ) as $row ) {
				// Skip mode: large files are dormant (not scanned), so never report
				// them as deleted. Check BOTH the baseline size and the current
				// on-disk size, so a file that crossed the 100 MB line after the
				// baseline (still present on disk) is not mistaken for a deletion.
				if ( $skip_large ) {
					$row_path = isset( $row['file_path'] ) ? $row['file_path'] : '';
					$now_big  = '' !== $row_path && file_exists( $row_path ) && (int) filesize( $row_path ) > self::LARGE_FILE_BYTES;
					if ( (int) $row['file_size'] > self::LARGE_FILE_BYTES || $now_big ) {
						continue;
					}
				}
				// Every deleted file is recorded so the detail screen lists them all.
				$deleted_files[] = array(
					'file_name'           => $row['file_name'],
					'file_path'           => $row['file_path'],
					'status'              => 'deleted',
					'previous_size'       => $row['file_size'],
					'current_size'        => '-',
					'previous_modified'   => gmdate( 'd-M-Y H-i-s', (int) strtotime( $row['file_modified'] . ' UTC' ) ),
					'current_modified'    => '-',
					'previous_permission' => $row['file_permission'],
					'current_permission'  => '-',
				);
				++$deleted_total;

				// Only the first N go to the live progress feed, to keep it light.
				if ( $deleted_total <= self::LIVE_LOG_DELETE_CAP ) {
					$file_size      = round( $row['file_size'] / 1024, 1 );
					$log_messages[] = 'Deleted: ' . basename( $row['file_path'] ) . ' (' . $file_size . 'KB)';
				}
			}
			// One file read+write for all deletion lines (not one per file).
			$this->update_integrity_logs_records_batch( $log_messages, 'INFO' );

			// When capped, note the remainder once (detail screen still has them all).
			if ( $deleted_total > self::LIVE_LOG_DELETE_CAP ) {
				$this->update_integrity_logs_records(
					sprintf(
						'+ %d more deleted files (live log limited to %d for performance; full list in the report)',
						$deleted_total - self::LIVE_LOG_DELETE_CAP,
						self::LIVE_LOG_DELETE_CAP
					),
					'WARNING'
				);
			}
		}

		return $this->build_tree( $deleted_files, $current_directory );
	}

	/**
	 * Builds a nested folder tree from a flat list of comparison file entries.
	 *
	 * @param array  $files     Flat list of file arrays with 'file_path' key.
	 * @param string $base_path Base directory path.
	 * @return array
	 */
	private function build_tree( $files, $base_path ) {
		$tree        = array();
		$base_length = strlen( $base_path );

		foreach ( $files as $file ) {
			$relative_path = substr( $file['file_path'], $base_length );
			$path_parts    = explode( DIRECTORY_SEPARATOR, $relative_path );

			$current = &$tree;
			foreach ( $path_parts as $part ) {
				if ( ! isset( $current[ $part ] ) ) {
					$current[ $part ] = array();
				}
				$current = &$current[ $part ];
			}

			$current = $file;
		}

		return $this->format_tree_to_array( $tree, $base_path );
	}

	/**
	 * Converts a nested file tree into a flat map keyed by file path.
	 *
	 * @param array $files Nested file or folder structure.
	 * @return array
	 */
	private function map_files_by_path( $files ) {
		$map = array();
		foreach ( $files as $file ) {
			if ( isset( $file['file_name'] ) ) {
				$map[ $file['file_path'] ] = $file;
			} elseif ( isset( $file['folder_name'] ) ) {
				$sub_files = $this->map_files_by_path( $file['sub_folder_files'] );
				$map       = array_merge( $map, $sub_files );
			}
		}
		return $map;
	}

	/**
	 * Recursively formats an associative tree into an indexed array for JSON output.
	 *
	 * @param array  $tree        Associative tree keyed by file or folder name.
	 * @param string $parent_path Parent directory path.
	 * @return array
	 */
	private function format_tree_to_array( $tree, $parent_path ) {
		$result = array();

		foreach ( $tree as $key => $value ) {
			$current_path = $parent_path . DIRECTORY_SEPARATOR . $key;
			if ( isset( $value['file_name'] ) ) {
				$result[] = $value;
			} else {
				$result[] = array(
					'folder_name'      => basename( $key ),
					'path'             => $current_path,
					'sub_folder_files' => $this->format_tree_to_array( $value, $current_path ),
				);
			}
		}

		return $result;
	}

	/**
	 * Gets file logs data with pagination.
	 *
	 * @param string $post_data JSON with limit, page.
	 * @return array
	 */
	public function tailwatch_get_file_logs_data( $post_data ) {
		try {

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			// Set default pagination parameters.
			$limit = isset( $data['limit'] ) && is_numeric( $data['limit'] ) && $data['limit'] > 0 ? (int) $data['limit'] : 10;
			$page  = isset( $data['page'] ) && is_numeric( $data['page'] ) && $data['page'] > 0 ? (int) $data['page'] : 1;

			// Serve the completed-comparison list from tw_filemon_scans, paginated in SQL.
			$file_mon  = new FileMonModel();
			$offset    = ( $page - 1 ) * $limit;
			$result    = $file_mon->list_scans( array( 'completed_only' => true, 'limit' => $limit, 'offset' => $offset ) );
			$probe     = $file_mon->list_scans( array( 'completed_only' => true, 'limit' => 1, 'offset' => 0 ) );
			$latest_id = isset( $probe['rows'][0]['scan_id'] ) ? (int) $probe['rows'][0]['scan_id'] : null;

			$paginated_logs = array();
			foreach ( $result['rows'] as $i => $row ) {
				$paginated_logs[] = array(
					'id'                  => (int) $row['scan_id'],
					'path'                => $row['monitored_path'],
					'folder_name'         => $row['folder_name'],
					'new_scan_file'       => (int) $row['new_scan_file'],
					'total_captured_file' => (int) $row['total_captured_file'],
					'malware_status'      => $row['malware_status'],
					'suspicious_handling' => isset( $row['suspicious_handling'] ) ? $row['suspicious_handling'] : 'No',
					'scan_time'           => $row['scan_time'],
					'sr'                  => $offset + $i + 1,
					'can_clean'           => ( null !== $latest_id && (int) $row['scan_id'] === $latest_id && 'pending' === $row['malware_status'] ),
				);
			}

			return array(
				'code'       => 200,
				'message'    => __( 'File logs data retrieved successfully.', 'tailwatch' ),
				'data'       => $paginated_logs,
				'pagination' => array(
					'total'       => (int) $result['total'],
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => $result['total'] > 0 ? (int) ceil( $result['total'] / $limit ) : 0,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_get_file_logs_data',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_entries_retrieval_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'code'       => 500,
				'message'    => __( 'Error retrieving file logs data.', 'tailwatch' ),
				'data'       => array(),
				'pagination' => null,
			);
		}
	}

	/**
	 * Gets file log entry by ID with optional file status filter.
	 *
	 * @param string|null $post_data JSON post data.
	 * @param int|null    $pid       Comparison ID.
	 * @return array
	 */
	public function tailwatch_get_files_log_by_id( $post_data = null, $pid = null ) {
		try {

			if ( null !== $post_data ) {
				$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
				$data      = json_decode( $json_data, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					Log::error(
						'Invalid JSON data',
						array(
							'feature' => 'files_integrity',
							'action'  => 'files_integrity_entry_details_by_id_failed',
							'error'   => 'Invalid JSON data provided.',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
						'code'    => 400,
					);
				}
			} elseif ( null !== $pid ) {
				$data = array( 'id' => $pid );
			} else {
				Log::error(
					'Missing required field',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_entry_details_by_id_failed',
						'error'   => 'Missing required field.',
					)
				);
				return array(
					'data'       => array(),
					'data_found' => false,
					'message'    => __( 'Missing required field.', 'tailwatch' ),
					'code'       => 400,
				);
			}

			$id          = isset( $data['id'] ) ? $data['id'] : null;
			$file_status = isset( $data['file_status'] ) ? $data['file_status'] : 'all'; // new, modified, deleted, all.
			$file_status = strtolower( $file_status );
			if ( ! in_array( $file_status, array( 'new', 'modified', 'deleted', 'all' ), true ) ) {
				$file_status = 'all';
			}

			if ( null === $id ) {
				Log::error(
					'ID is required',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_entry_details_by_id_failed',
						'error'   => 'ID is required.',
					)
				);
				return array(
					'data'        => null,
					'file_status' => $file_status,
					'message'     => __( 'ID is required.', 'tailwatch' ),
					'code'        => 400,
				);
			}

			$limit = isset( $data['limit'] ) && is_numeric( $data['limit'] ) && $data['limit'] > 0 ? (int) $data['limit'] : 10;
			$page  = isset( $data['page'] ) && is_numeric( $data['page'] ) && $data['page'] > 0 ? (int) $data['page'] : 1;

			// Resolve the comparison from tw_filemon_scans (+ its sidecar for folder_files).
			$file_mon = new FileMonModel();
			$row      = $file_mon->get_scan( (int) $id );

			$comparison_status = ( null !== $row )
				? $this->tailwatch_scan_status_from_row( $row, $file_mon->read_sidecar( (int) $id ) )
				: null;

			// Case 1: return single comparison_status record by ID.
			if ( null !== $pid ) {
				if ( null !== $comparison_status ) {
					return array(
						'code'        => 200,
						'file_status' => $file_status,
						'message'     => __( 'Comparison status retrieved successfully.', 'tailwatch' ),
						'data'        => $comparison_status,
					);
				}
				return array(
					'code'        => 404,
					'file_status' => $file_status,
					'message'     => __( 'Comparison status not found.', 'tailwatch' ),
					'data'        => null,
				);
			}

			// Case 2: paginate folder_files and sub_folder_files.
			if ( null === $comparison_status ) {
				Log::error(
					'Comparison status not found',
					array(
						'feature' => 'files_integrity',
						'action'  => 'files_integrity_entry_details_by_id_failed',
						'error'   => 'Comparison status not found.',
					)
				);
				return array(
					'code'        => 404,
					'file_status' => $file_status,
					'message'     => __( 'Comparison status not found.', 'tailwatch' ),
					'data'        => null,
				);
			}

			$all_files = $this->flatten_files( $comparison_status['folder_files'] );

			if ( 'all' !== $file_status ) {
				$all_files = array_values(
					array_filter(
						$all_files,
						function ( $item ) use ( $file_status ) {
							return isset( $item['status'] ) && strtolower( $item['status'] ) === $file_status;
						}
					)
				);
			}

			$total = count( $all_files );
			if ( 0 === $total ) {
				return array(
					'code'        => 200,
					'file_status' => $file_status,
					'message'     => __( 'No file changes detected in this comparison.', 'tailwatch' ),
					'data'        => array(),
					'pagination'  => array(
						'total'       => 0,
						'page'        => $page,
						'limit'       => $limit,
						'total_pages' => 0,
					),
				);
			}

			$offset         = ( $page - 1 ) * $limit;
			$total_pages    = ceil( $total / $limit );
			$paginated_data = array_slice( $all_files, $offset, $limit );

			return array(
				'code'        => 200,
				'file_status' => $file_status,
				'message'     => __( 'Files retrieved successfully.', 'tailwatch' ),
				'data'        => $paginated_data,
				'pagination'  => array(
					'total'       => $total,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => $total_pages,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_get_files_log_by_id',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_entry_details_by_id_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'code'        => 500,
				'file_status' => null,
				'message'     => __( 'Error retrieving files by id.', 'tailwatch' ),
				'data'        => null,
			);
		}
	}

	/**
	 * Gets current file integrity feature status and next cron run.
	 *
	 * @return array
	 */
	public function tailwatch_get_file_integrity_status() {
		try {

			$comparison_data = $this->tailwatch_get_integrity_last_run();
			$started_time    = $comparison_data['data']['start_time'];
			$file_exist      = $comparison_data['data']['file_exist'];

			$next_scheduled          = wp_next_scheduled( 'tailwatch_files_integrity_schedule_run' );
			$current_time            = time();
			$next_schedule_formatted = $next_scheduled ? gmdate( 'Y-m-d H:i:s', $next_scheduled ) : 'Not Scheduled';

			$next_run = TimeService::format_time_remaining( $next_scheduled, $current_time, __( 'Running now', 'tailwatch' ) );

			$return_data = array(
				'started_time'  => $started_time,
				'file_exist'    => $file_exist,
				'next_schedule' => $next_schedule_formatted,
				'next_run'      => $next_run,
			);

			return array(
				'code'    => 200,
				'data'    => $return_data,
				'message' => __( 'Data Retrieved Successfully', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_get_file_integrity_status',
				array(
					'feature' => 'files_integrity',
					'action'  => 'files_integrity_status_failed',
					'error'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Error retrieving file integrity status.', 'tailwatch' ),
				'data'    => array(),
			);
		}
	}

	/**
	 * Gets last integrity run time and status.
	 *
	 * @return array
	 */
	public function tailwatch_get_integrity_last_run() {
		// Latest scan time via SQL MAX over all comparisons (completed or not).
		$file_mon = new FileMonModel();
		if ( $file_mon->list_scans( array( 'limit' => 1 ) )['total'] > 0 ) {
			$start = $file_mon->max_scan_time( false );
			return array(
				'code'    => 200,
				'message' => __( 'Latest comparison status retrieved successfully.', 'tailwatch' ),
				'data'    => array(
					'file_exist' => true,
					'start_time' => $start ? $start : 'N/A',
				),
			);
		}

		return array(
			'code'    => 500,
			'message' => __( 'No comparison snapshot exists.', 'tailwatch' ),
			'data'    => array(
				'file_exist' => false,
				'start_time' => 'N/A',
			),
		);
	}

	/**
	 * Updates the malware status field on the latest comparison entry.
	 *
	 * @param string $malware_status Malware status string (e.g. 'Cleaned', 'Malware Found').
	 * @return array
	 */
	public function tailwatch_update_malware_scan_status( $malware_status ) {
		// Mirror the malware fields onto the latest COMPLETED comparison row.
		$file_mon = new FileMonModel();
		$res      = $file_mon->list_scans( array( 'completed_only' => true, 'limit' => 1, 'offset' => 0 ) );

		if ( empty( $res['rows'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No completed integrity check found.', 'tailwatch' ),
			);
		}

		$file_mon->update_scan(
			(int) $res['rows'][0]['scan_id'],
			array(
				'malware_status' => (string) $malware_status,
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Malware scan status updated successfully.', 'tailwatch' ),
		);
	}

	/**
	 * Flattens nested folder_files structure into a single list.
	 *
	 * @param array  $files  Nested file/folder structure.
	 * @param string $prefix Path prefix.
	 * @return array
	 */
	private function flatten_files( $files, $prefix = '' ) {
		$result = array();
		foreach ( $files as $item ) {
			if ( isset( $item['file_name'] ) ) {
				$result[] = array_merge( $item, array( 'path_prefix' => $prefix ) );
			}
			if ( isset( $item['folder_name'] ) && isset( $item['sub_folder_files'] ) ) {
				$new_prefix = $prefix ? $prefix . '/' . $item['folder_name'] : $item['folder_name'];
				$result     = array_merge( $result, $this->flatten_files( $item['sub_folder_files'], $new_prefix ) );
			}
		}
		return $result;
	}
}
