<?php
namespace Tailwatch\Admin\App\Api\Controllers\HardeningAudit;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\HardeningAuditModel;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\ProcessManager;

/**
 * Hardening Audit lifecycle controller — schedules, chunked worker,
 * reports CRUD, retention sweep.
 */
class HardeningAuditController {

	const PROCESS_TYPE = 'hardening_audit';
	const CRON_HOOK    = 'wptw_hardening_audit_scan';

	const FEATURE_OPTION_KEY = 'default_feature_settings';
	const FEATURE_OPTION     = 'default_hardening_audit';

	const STATE_KEY           = 'default_hardening_audit_scan';
	const PROGRESS_OPTION     = 'audit_progress';
	const CANCEL_PAUSE_OPTION = 'audit_cancel_pause';
	const REPORT_OPTION       = 'audit_report';

	/**
	 * @var ProcessManager
	 */
	private $process_manager;

	/**
	 * @var HardeningChecksController
	 */
	private $checks;

	/**
	 * Live-log directory and file-base. Each run gets its own log file at
	 * `<live_log_base>_<run_id>.json` so the frontend polling
	 * `wptw_get_hardening_audit_live_logs` only ever streams the current
	 * audit's lines. Mirrors DatabaseOptimizerController's
	 * $log_directory / $get_live_logs split.
	 *
	 * @var string
	 */
	private $log_directory = WPTW_LOGS_DIRECTORY . '/hardening-audit-logs';

	/**
	 * @var string
	 */
	private $live_log_base = WPTW_LOGS_DIRECTORY . '/hardening-audit-logs/hardening-audit';

	public function __construct() {
		$this->process_manager = new ProcessManager();
		$this->checks          = new HardeningChecksController();

		$this->register_process_monitoring();

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( self::CRON_HOOK, array( $this, 'wptw_run_hardening_audit_with_monitoring' ) );

		// Layer-2 fallback so ProcessStatusService can verify our state from
		// our own scan_state field even when the ProcessManager row is stale
		// or absent. Mirrors how db_optimize and files_integrity register
		// their built-in fallbacks — for new modules we hook the public filter.
		add_filter( 'wptw_process_status_fallbacks', array( $this, 'register_status_fallback' ) );
	}

	/**
	 * Register this module with ProcessManager so ProcessGuard and the
	 * recovery monitor can reason about it like every other long-running scan.
	 *
	 * @return void
	 */
	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'        => self::PROCESS_TYPE,
				'cron_hooks'          => array(
					self::CRON_HOOK,
					'wptw_hardening_audit_schedule_run',
				),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => self::STATE_KEY,
				'data_option'         => self::PROGRESS_OPTION,
				'cancel_pause_key'    => self::STATE_KEY,
				'cancel_pause_option' => self::CANCEL_PAUSE_OPTION,
				'stuck_threshold'     => 300,
				'max_retries'         => 3,
				'locks_features'      => array( self::FEATURE_OPTION ),
				'cannot_start_while'  => array(
					'backup',
					'restore',
					'migration',
					'malware_scan',
					'malware_restore',
					'reset_all',
					'settings_import',
				),
			)
		);
	}

	/**
	 * Layer-2 fallback registered via wptw_process_status_fallbacks. Returns
	 * true when our own scan_state says the audit is mid-run, so ProcessGuard
	 * can detect activity even if the ProcessManager row is stale.
	 *
	 * @param array<string,callable> $fallbacks
	 * @return array<string,callable>
	 */
	public function register_status_fallback( $fallbacks ) {
		$fallbacks[ self::PROCESS_TYPE ] = function () {
			$state = $this->get_cancel_pause_data();
			return is_array( $state )
				&& isset( $state['scan_state'] )
				&& 'in-progress' === $state['scan_state'];
		};
		return $fallbacks;
	}

	// Options & feature accessors.

	/**
	 * Pull the hardening-audit feature definition from OptionsModel. Mirrors
	 * the convention used by every other feature controller so the cron job
	 * and ProcessGuard both find the canonical settings shape.
	 *
	 * @return array
	 */
	public function get_features_options() {
		$options_controller = new OptionsController();
		return $options_controller->get_features_options(
			self::FEATURE_OPTION_KEY,
			self::FEATURE_OPTION,
			true
		);
	}

		public function wptw_hardening_audit_feature_enable() {
		$options = $this->get_features_options();
		if ( empty( $options ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = isset( $options['field_1']['options']['option']['selected'] )
			? (bool) $options['field_1']['options']['option']['selected']
			: false;

		return array(
			'parent_enable'  => true,
			'feature_enable' => $selected,
		);
	}

	/**
	 * Used by NotificationActions for dynamic notification gating. Returns
	 * whether push notifications for hardening audit completion should fire,
	 * reading the per-feature push_notification flag on field_1 (same pattern
	 * IntegrityWatchController / BrokenLinkChecker / Backup use).
	 *
	 * @return bool True if notifications should be sent for hardening audit completion.
	 */
	public function hardening_audit_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = self::FEATURE_OPTION_KEY;
		$option            = self::FEATURE_OPTION;
		$field_name        = 'field_1';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Resolve the configured retention window (e.g. "7 Days") to seconds,
	 * used by the maintenance cron to prune old audit reports.
	 *
	 * Returns 0 for "Keep All Data" — sentinel meaning "never prune". The
	 * caller (`wptw_prune_hardening_audit_reports`) treats 0 as a
	 * skip-prune signal so an arbitrarily-large bound isn't compared
	 * against `date_created` (and so we never accidentally delete
	 * everything if the value parses unexpectedly).
	 *
	 * @return int Seconds, or 0 for "Keep All Data".
	 */
	public function get_report_retention_seconds() {
		$options  = $this->get_features_options();
		$selected = '24 Hours';

		if ( ! empty( $options['field_1']['sub_options']['field_3']['options'] ) && is_array( $options['field_1']['sub_options']['field_3']['options'] ) ) {
			foreach ( $options['field_1']['sub_options']['field_3']['options'] as $option ) {
				if ( ! empty( $option['selected'] ) && isset( $option['value'] ) ) {
					$selected = (string) $option['value'];
					break;
				}
			}
		}

		switch ( $selected ) {
			case '30 Hours':
				return 30 * HOUR_IN_SECONDS;
			case '24 Hours':
				return 24 * HOUR_IN_SECONDS;
			default:
				// Unknown retention values fall through to the filter so
				// extensions can supply seconds for retention buckets they add.
				// Defaults to 24 hours when no listener is registered.
				return (int) apply_filters( 'wptw_hardening_audit_retention_seconds', 24 * HOUR_IN_SECONDS, $selected );
		}
	}

	// Route-layer payload helpers.

	/**
	 * Normalize the route layer's `$post_data` argument.
	 *
	 * AjaxRequestController dispatches with the raw `$_POST['data']` string
	 * (JSON-encoded by the frontend); MobileAppController dispatches with the
	 * already-sanitized array form. Each public AJAX entry needs to read the
	 * same logical fields out of either shape, so funnel both through one
	 * helper instead of repeating the dance per method.
	 *
	 * @param mixed $post_data
	 * @return array
	 */
	private function parse_post_data( $post_data ) {
		if ( is_array( $post_data ) ) {
			return $post_data;
		}
		if ( is_string( $post_data ) && '' !== $post_data ) {
			$decoded = json_decode( wp_unslash( $post_data ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	// DB row helpers (audit_progress / audit_cancel_pause / audit_report).

	public function get_progress_data() {
		$db_model = new DBModel();
		return $db_model->get_recent_data( self::PROGRESS_OPTION, self::STATE_KEY );
	}

	public function update_progress_data( array $data ) {
		$db_model = new DBModel();
		$db_model->update_recent_row(
			array( 'value' => wp_json_encode( $data ) ),
			self::STATE_KEY,
			self::PROGRESS_OPTION
		);
	}

	public function get_cancel_pause_data() {
		$db_model = new DBModel();
		return $db_model->get_recent_data( self::CANCEL_PAUSE_OPTION, self::STATE_KEY );
	}

	public function update_cancel_pause_data( array $data ) {
		$db_model = new DBModel();
		$db_model->update_recent_row(
			array( 'value' => wp_json_encode( $data ) ),
			self::STATE_KEY,
			self::CANCEL_PAUSE_OPTION
		);
	}

	/**
	 * Insert the two state rows (progress + cancel_pause) for a fresh run.
	 * Each run gets its own pair of rows tagged with `child_of=$run_id` so
	 * later list / delete operations target a specific audit cleanly.
	 *
	 * @param array $progress
	 * @param array $cancel_pause
	 * @param int   $run_id
	 * @return bool
	 */
	private function insert_state_rows( array $progress, array $cancel_pause, $run_id ) {
		$now    = current_time( 'mysql' );
		$format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		$rows = array(
			array(
				'user_id'       => (string) get_current_user_id(),
				'child_of'      => $run_id,
				'key'           => self::STATE_KEY,
				'option'        => self::PROGRESS_OPTION,
				'value'         => wp_json_encode( $progress ),
				'type'          => 'JSON',
				'type_state'    => 'active',
				'date_created'  => $now,
				'date_modified' => $now,
				'is_active'     => 1,
			),
			array(
				'user_id'       => (string) get_current_user_id(),
				'child_of'      => $run_id,
				'key'           => self::STATE_KEY,
				'option'        => self::CANCEL_PAUSE_OPTION,
				'value'         => wp_json_encode( $cancel_pause ),
				'type'          => 'JSON',
				'type_state'    => 'active',
				'date_created'  => $now,
				'date_modified' => $now,
				'is_active'     => 1,
			),
		);

		$ok       = true;
		$db_model = new DBModel();
		foreach ( $rows as $row ) {
			$result = $db_model->insert_row( $row, $format );
			if ( ! $result ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Persist a completed audit as its own row. One row per report keeps
	 * deletion + retention pruning trivial — no JSON-array surgery on a
	 * single monolithic row, and listing reports is just an ORDER BY.
	 *
	 * @param array $report
	 * @return bool
	 */
	private function insert_report_row( array $report ) {
		$now      = current_time( 'mysql' );
		$run_id   = isset( $report['id'] ) ? (int) $report['id'] : time();
		$db_model = new DBModel();
		$format   = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		$row = array(
			'user_id'       => (string) get_current_user_id(),
			'child_of'      => $run_id,
			'key'           => self::STATE_KEY,
			'option'        => self::REPORT_OPTION,
			'value'         => wp_json_encode( $report ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => $now,
			'date_modified' => $now,
			'is_active'     => 1,
		);

		return (bool) $db_model->insert_row( $row, $format );
	}

	// Live-logs path helper.

	/**
	 * Build the absolute path to the current run's live-log file. Returns
	 * an empty string when there is no active run — callers must check.
	 *
	 * The file name is keyed on `run_id` (which is the unix timestamp of the
	 * start) so paused→resumed runs continue appending to the same file and
	 * a new run never collides with stragglers from a prior one.
	 *
	 * @return string Absolute path or empty string.
	 */
	public function wptw_get_log_file_path() {
		$progress = $this->get_progress_data();
		if ( empty( $progress ) || empty( $progress['run_id'] ) ) {
			return '';
		}
		return $this->live_log_base . '_' . (int) $progress['run_id'] . '.json';
	}

	// Public lifecycle entrypoints.

	/**
	 * AJAX/Mobile entry for a user-triggered audit start.
	 *
	 * Mirrors the safety gating that DatabaseOptimizerController::wptw_database_optimize
	 * and IntegrityWatchController::wptw_instant_files_integrity_check apply
	 * before kicking off a manual run:
	 *
	 *   1. ProcessGuard refusal if a conflicting process is mid-flight.
	 *   2. Feature-enabled gate.
	 *   3. `instant_scan: true` flag — refuse with 400 when missing/false.
	 *      This is a deliberate seatbelt: the route is a sensitive trigger,
	 *      and requiring an explicit boolean prevents a stray POST (e.g. an
	 *      automation that calls every `wptw_start_*` endpoint with empty
	 *      bodies during onboarding) from silently launching real audits.
	 *   4. HTTP cron-access self-test via CronHealthService — refuse if
	 *      WP-Cron itself is broken (DISABLE_WP_CRON / ALTERNATE_WP_CRON /
	 *      unreachable wp-cron.php). Without this check the audit would
	 *      "start" but the chunked worker would never fire, which surfaces
	 *      to the user as a stuck progress bar.
	 *   5. Unschedule the recurring HardeningAuditCronJob so the manual run
	 *      and the next auto run can't race / double-seed state rows.
	 *
	 * Always delegates with `scan_type = 'on-demand'` — the cron path is the
	 * only producer of `'automatically'`, and the AJAX/Mobile path is the
	 * only producer of `'on-demand'`, so the report's `scan_type` field is
	 * unambiguous.
	 *
	 * @param mixed $post_data Route-layer payload — JSON string (AJAX) or
	 *                         already-decoded array (mobile).
	 * @return array Standardised response { code, data, message }.
	 */
	public function wptw_start_hardening_audit( $post_data = null ) {
		try {
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( self::PROCESS_TYPE );
			if ( null !== $blocked ) {
				return $blocked;
			}

			$is_enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $is_enabled['feature_enable'] ) ) {
				Log::error(
					'Hardening Audit feature is not enabled',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_start_failed',
					)
				);
				return array(
					'code'           => 400,
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $is_enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit feature is not enabled. Please enable it first.', 'tailwatch' ),
				);
			}

			$data = $this->parse_post_data( $post_data );
			if ( empty( $data ) ) {
				Log::error(
					'Invalid input data for Hardening Audit instant scan',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_start_failed',
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
				);
			}

			if ( empty( $data['instant_scan'] ) || true !== $data['instant_scan'] ) {
				Log::warning(
					'instant_scan false',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_start_failed',
						'error'   => 'Failed to run Hardening Audit: instant_scan is false.',
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'Failed to run Hardening Audit: instant_scan is false.', 'tailwatch' ),
				);
			}

			$cron_status = ( new CronHealthService() )->test( 'hardening_audit' );
			if ( empty( $cron_status['success'] ) ) {
				$cron_message = isset( $cron_status['message'] ) ? (string) $cron_status['message'] : __( 'Cron access check failed.', 'tailwatch' );
				Log::error(
					'Cron access failure',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_start_failed',
						'error'   => $cron_message,
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'error'   => $cron_message,
					'message' => __( 'Failed to run the Hardening Audit due to an issue with the cron.', 'tailwatch' ),
				);
			}

			// Unschedule the recurring auto-run while the manual run owns
			// the worker. HardeningAuditCronJob::maybe_schedule will
			// reattach the recurring schedule on the next admin_init after
			// the manual run completes, exactly like DatabaseOptimizerCronJob.
			$audit_job = CronJobManager::get_instance()->get_cron_job( 'hardening_audit' );
			if ( null !== $audit_job ) {
				$audit_job->unschedule();
			}

			Log::info(
				'Hardening Audit on-demand scan started',
				array(
					'feature' => 'hardening_audit',
					'action'  => 'hardening_audit_started',
					'detail'  => 'Hardening Audit instant scan started.',
				)
			);

			return $this->wptw_run_hardening_audit( 'on-demand' );
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in wptw_start_hardening_audit: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_start_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to start the hardening audit.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Typed internal start.
	 *
	 * Two callers:
	 *   - {@see wptw_start_hardening_audit} — the AJAX/Mobile entry, after
	 *     it validates `instant_scan`, tests cron access, and unschedules
	 *     the recurring job. Always passes `'on-demand'`.
	 *   - {@see HardeningAuditCronJob::execute} — the recurring schedule.
	 *     Passes `'automatically'`.
	 *
	 * Re-runs ProcessGuard and the feature-enabled check defensively so
	 * direct calls from inside the codebase (cron, debug shells, future
	 * sub-processes) cannot bypass the same gates the AJAX entry runs.
	 *
	 * @param string $scan_type 'on-demand' or 'automatically'.
	 * @return array Standardised response { code, data, message }.
	 */
	public function wptw_run_hardening_audit( $scan_type = 'on-demand' ) {
		// Pre-declared so the catch block can mark_failed even when the
		// exception fires after `get_or_create_process` succeeded — without
		// this, $process_id would be undefined in the catch and PHP would
		// warn while silently leaving a stuck 'pending' row.
		$process_id = null;

		try {
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( self::PROCESS_TYPE );
			if ( null !== $blocked ) {
				return $blocked;
			}

			// Normalize to the same two-value taxonomy the rest of the
			// codebase uses ('on-demand' | 'automatically'). Anything else
			// (older clients, typos in test harnesses) collapses to
			// 'on-demand' so the report metadata stays meaningful.
			if ( ! in_array( $scan_type, array( 'on-demand', 'automatically' ), true ) ) {
				$scan_type = 'on-demand';
			}

			$enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $enabled['feature_enable'] ) ) {
				return array(
					'code'           => 400,
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit is not enabled. Please enable it first.', 'tailwatch' ),
				);
			}

			// If a previous run was paused, resume from where it stopped
			// rather than starting over — paused state is a user choice we
			// must respect.
			$existing = $this->get_cancel_pause_data();
			if ( is_array( $existing ) && isset( $existing['scan_state'] ) && 'pause' === $existing['scan_state'] ) {
				return $this->wptw_resume_hardening_audit();
			}

			// Anything else (in-progress, completed, or empty) — sweep stale
			// rows and start fresh. Treating a leftover 'in-progress' row as
			// a "real run" would leave a no-op audit forever stuck after a
			// hard crash; the cleanup cron normally handles that, but a
			// manual start should reset cleanly even before the cron fires.
			$this->wptw_remove_garbage_entries_audit();

			$run_id     = time();
			$process_id = $this->process_manager->get_or_create_process(
				self::PROCESS_TYPE,
				self::CRON_HOOK,
				array(
					'scan_type' => $scan_type,
					'run_id'    => $run_id,
				)
			);

			$progress = array(
				'run_id'           => $run_id,
				'started_time'     => $run_id,
				'scan_type'        => $scan_type,
				'process_id'       => $process_id,
				'remaining_checks' => array_keys( HardeningChecksController::get_check_pipeline() ),
				'completed_checks' => array(),
				'summary'          => $this->empty_summary(),
				'is_completed'     => false,
			);

			$cancel_pause = array(
				'scan_state'   => 'in-progress',
				'cron_running' => false,
				'progress'     => 0,
				'scan_type'    => $scan_type,
				'started_time' => $run_id,
				'process_id'   => $process_id,
			);

			$inserted = $this->insert_state_rows( $progress, $cancel_pause, $run_id );
			if ( ! $inserted ) {
				$this->process_manager->mark_failed( $process_id, 'Failed to insert hardening audit state rows' );
				return array(
					'code'    => 500,
					'data'    => array(),
					'message' => __( 'Failed to start the hardening audit (database error).', 'tailwatch' ),
				);
			}

			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time(), self::CRON_HOOK );
			}

			// Seed the live-log file the moment we have a run_id on disk.
			// LiveLogsController::insert_live_logs_records mkdirs the parent
			// folder for us and only writes when the file does not already
			// exist, so a duplicate start that fell through earlier guards
			// cannot clobber a sibling run's log.
			if ( ! file_exists( WPTW_LOGS_DIRECTORY ) ) {
				wp_mkdir_p( WPTW_LOGS_DIRECTORY );
			}
			$live_logs = new LiveLogsController();
			$live_logs->insert_live_logs_records(
				sprintf( 'Starting hardening audit (%s)', $scan_type ),
				$this->log_directory,
				$this->wptw_get_log_file_path()
			);

			$this->process_manager->heart_beat( $process_id );
			$this->process_manager->update_state( $process_id, 'in_progress' );

			Log::info(
				'Hardening audit started',
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_started',
					'scan_type' => $scan_type,
					'run_id'    => $run_id,
				)
			);

			return array(
				'code'    => 200,
				'data'    => array(
					'run_id'     => $run_id,
					'process_id' => $process_id,
				),
				'message' => __( 'Hardening audit started.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Hardening audit start failed: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_start_failed',
					'exception' => $e,
				)
			);

			// Clean up the ProcessManager row so we don't leak a stuck
			// 'pending' process when the exception fires AFTER
			// `get_or_create_process` succeeded. Mirrors the behaviour of
			// DatabaseOptimizerController::wptw_database_optimize_start's
			// catch block. $process_id stays null when the exception fires
			// before that line, in which case we have nothing to clean up.
			if ( ! empty( $process_id ) ) {
				$this->process_manager->mark_failed( $process_id, $e->getMessage() );
			}

			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to start the hardening audit.', 'tailwatch' ),
			);
		}
	}

	/**
	 * UI status probe. Returns the canonical scan_state plus a derived
	 * progress percentage. Shape mirrors `wptw_verify_db_optimize_status` so
	 * the same status-watching JS can render either feature.
	 *
	 * Accepts (and ignores) `$post_data` so the route layer's blanket
	 * `$controller->$method($post_data)` dispatch is safe to call.
	 *
	 * @param mixed $post_data Unused — accepted for route-layer compatibility.
	 * @return array
	 */
	public function wptw_verify_hardening_audit_status( $post_data = null ) {
		unset( $post_data );

		try {
			$is_enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $is_enabled['feature_enable'] ) ) {
				Log::error(
					'Hardening Audit feature is not enabled',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_verify_status_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $is_enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$progress     = $this->get_progress_data();
			$cancel_pause = $this->get_cancel_pause_data();

			// Explicit per-state branches mirror DB Optimizer's
			// `wptw_verify_db_optimize_status` exactly: each scan_state has
			// its own response shape and message so frontend code that
			// switches on `message` (or that conditionally renders extras
			// only when the scan is mid-run) behaves identically across
			// modules.
			if ( ! empty( $cancel_pause ) ) {
				$total = count( HardeningChecksController::get_check_pipeline() );
				$done  = ( ! empty( $progress ) && isset( $progress['completed_checks'] ) )
					? count( (array) $progress['completed_checks'] )
					: 0;
				$pct   = $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 0;

				$scan_state = isset( $cancel_pause['scan_state'] ) ? $cancel_pause['scan_state'] : '';

				if ( 'pause' === $scan_state ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'pause',
						'progress'     => $pct,
						'scan_type'    => isset( $cancel_pause['scan_type'] ) ? $cancel_pause['scan_type'] : '',
						'run_id'       => isset( $progress['run_id'] ) ? (int) $progress['run_id'] : 0,
						'started_time' => isset( $progress['started_time'] ) ? (int) $progress['started_time'] : 0,
						'summary'      => isset( $progress['summary'] ) ? $progress['summary'] : $this->empty_summary(),
						'message'      => __( 'Hardening Audit was paused.', 'tailwatch' ),
						'code'         => 200,
					);
				}

				if ( 'in-progress' === $scan_state ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'in-progress',
						'progress'     => $pct,
						'scan_type'    => isset( $cancel_pause['scan_type'] ) ? $cancel_pause['scan_type'] : '',
						'run_id'       => isset( $progress['run_id'] ) ? (int) $progress['run_id'] : 0,
						'started_time' => isset( $progress['started_time'] ) ? (int) $progress['started_time'] : 0,
						'summary'      => isset( $progress['summary'] ) ? $progress['summary'] : $this->empty_summary(),
						'message'      => __( 'Hardening Audit is in progress.', 'tailwatch' ),
						'code'         => 200,
					);
				}

				if ( 'completed' === $scan_state ) {
					return array(
						'is_completed' => true,
						'scan_state'   => 'completed',
						'message'      => __( 'Hardening audit completed successfully.', 'tailwatch' ),
						'code'         => 200,
					);
				}
			}

			// Fallback: no cancel_pause row, or a state we don't recognize
			// (e.g. legacy 'cancel' rows lingering before cleanup). Treat as
			// "no work to do" so the start button surfaces.
			return array(
				'is_completed' => true,
				'message'      => __( 'Currently no process is in the running.', 'tailwatch' ),
				'code'         => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_verify_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to verify hardening audit status: ', 'tailwatch' ) . $e->getMessage(),
				'code'    => 500,
			);
		}
	}

	/**
	 * Unified user pause/cancel entry. Mirrors Integrity's
	 * `wptw_cancel_pause_integrity` exactly: one route, one method, the
	 * caller distinguishes pause from cancel via `scan_state` in the
	 * payload. Adds the DB-Optimizer-style feature_enable gate at the top
	 * so a disabled feature returns 400 with `feature_enable=false` rather
	 * than silently flipping state on a feature the user has turned off.
	 *
	 * Accepts: `{ scan_state: 'pause' | 'cancel' }`.
	 *
	 * @param mixed $post_data
	 * @return array
	 */
	public function wptw_cancel_pause_hardening_audit( $post_data = null ) {
		try {
			$is_enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $is_enabled['feature_enable'] ) ) {
				Log::error(
					'Hardening Audit feature is not enabled',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_cancel_pause_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $is_enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$data       = $this->parse_post_data( $post_data );
			$scan_state = isset( $data['scan_state'] ) ? sanitize_text_field( (string) $data['scan_state'] ) : '';

			if ( ! in_array( $scan_state, array( 'pause', 'cancel' ), true ) ) {
				Log::error(
					'Missing scan state',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_cancel_pause_failed',
						'error'   => 'Stop type is missing in input data',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Stop type is missing.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			return $this->wptw_pause_or_cancel( $scan_state );
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in wptw_cancel_pause_hardening_audit: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_cancel_pause_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error pausing or cancelling hardening audit.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Shared pause/cancel executor. Stays private now that the public
	 * surface is the unified `wptw_cancel_pause_hardening_audit`. Two paths
	 * through one method because the unschedule logic is identical — only
	 * the terminal state and the ProcessManager call differ.
	 *
	 * @param string $target_state 'pause' or 'cancel'.
	 * @return array
	 */
	private function wptw_pause_or_cancel( $target_state ) {
		try {
			if ( ! in_array( $target_state, array( 'pause', 'cancel' ), true ) ) {
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'Invalid state.', 'tailwatch' ),
				);
			}

			$cancel_pause = $this->get_cancel_pause_data();
			if ( empty( $cancel_pause ) || empty( $cancel_pause['scan_state'] ) ) {
				return array(
					'code'    => 404,
					'data'    => array(),
					'message' => __( 'No hardening audit is currently running.', 'tailwatch' ),
				);
			}

			if ( 'completed' === $cancel_pause['scan_state'] ) {
				return array(
					'code'    => 200,
					'data'    => array(),
					'message' => __( 'The hardening audit has already finished.', 'tailwatch' ),
				);
			}

			$cancel_pause['scan_state']   = $target_state;
			$cancel_pause['cron_running'] = false;
			$this->update_cancel_pause_data( $cancel_pause );

			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}

			$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : null;
			if ( $process_id ) {
				if ( 'cancel' === $target_state ) {
					$this->process_manager->mark_failed( $process_id, 'Hardening audit cancelled by user' );
				} else {
					$this->process_manager->update_state( $process_id, 'pause' );
				}
			}

			// Tell the live-log stream what just happened so the frontend
			// can show it without polling status separately. Only write if
			// the run actually has a log file (i.e. it got past start).
			$log_file = $this->wptw_get_log_file_path();
			if ( '' !== $log_file && file_exists( $log_file ) ) {
				$live_logs = new LiveLogsController();
				$live_logs->update_live_logs_records(
					'cancel' === $target_state
						? 'Hardening audit cancelled by user'
						: 'Hardening audit paused by user',
					$log_file,
					'INFO'
				);
				if ( 'cancel' === $target_state ) {
					// Mark the live-log stream complete on cancel so the
					// frontend stops polling — pause leaves it open because
					// resume will resume the same stream.
					$live_logs->wptw_live_logs_completed( true, $log_file );
				}
			}

			Log::info(
				'cancel' === $target_state
					? 'Hardening audit cancelled by user'
					: 'Hardening audit paused by user',
				array(
					'feature' => 'hardening_audit',
					'action'  => 'hardening_audit_' . $target_state,
				)
			);

			return array(
				'code'    => 200,
				'data'    => array(),
				'message' => __( 'cancel', 'tailwatch' ) === $target_state
					? __( 'Hardening audit cancelled successfully.', 'tailwatch' )
					: __( 'Hardening audit paused successfully. You can resume it later.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Hardening audit pause/cancel failed: ' . $e->getMessage(),
				array(
					'feature'      => 'hardening_audit',
					'action'       => 'hardening_audit_pause_cancel_failed',
					'target_state' => $target_state,
					'exception'    => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to update the hardening audit state.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Resume a previously paused audit. Picks up at the same point in
	 * `remaining_checks` — no work is repeated.
	 *
	 * @param mixed $post_data Unused — accepted for route-layer compatibility.
	 * @return array
	 */
	public function wptw_resume_hardening_audit( $post_data = null ) {
		unset( $post_data );

		try {
			$is_enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $is_enabled['feature_enable'] ) ) {
				Log::error(
					'Hardening Audit feature is not enabled',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_resume_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $is_enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$cancel_pause = $this->get_cancel_pause_data();

			// Single-branch validation matching `wptw_resume_db_optimize` and
			// `wptw_resume_files_integrity` exactly: empty state AND
			// not-paused both fall into the same 400 "already scheduled"
			// rejection rather than splitting them into 404 + 400. Frontends
			// that switch on this code path will behave identically across
			// the three modules.
			if ( ! empty( $cancel_pause ) && ! empty( $cancel_pause['scan_state'] ) && 'pause' === $cancel_pause['scan_state'] ) {
				$cancel_pause['scan_state']   = 'in-progress';
				$cancel_pause['cron_running'] = false;
				$this->update_cancel_pause_data( $cancel_pause );

				$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : null;
				if ( $process_id ) {
					$this->process_manager->update_state( $process_id, 'in_progress' );
					$this->process_manager->heart_beat( $process_id );
				}

				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_single_event( time(), self::CRON_HOOK );
				}

				$log_file = $this->wptw_get_log_file_path();
				if ( '' !== $log_file && file_exists( $log_file ) ) {
					$live_logs = new LiveLogsController();
					$live_logs->update_live_logs_records(
						'Hardening audit resumed',
						$log_file,
						'INFO'
					);
				}

				Log::info(
					'The hardening audit process was resumed.',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_resumed',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Hardening Audit Resume Successfully', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				Log::error(
					'Invalid state for resume.',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_resume_failed',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Already Hardening Audit Schedule', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Hardening audit resume failed: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_resume_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to resume the hardening audit.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Recovery endpoint: reschedule the chunked worker when it appears to
	 * have stalled. Mirrors `wptw_db_optimization_cron_if_failed` and
	 * `wptw_files_integrity_cron_if_failed` — same shape, same return codes
	 * — so the same frontend "is the scan stuck? click to retry" affordance
	 * works for all three modules.
	 *
	 * Decision tree:
	 *   - feature disabled                 → 400 (don't retry a feature
	 *                                        the user has turned off).
	 *   - cron_running = true              → 200 "already running" (the
	 *                                        worker is mid-tick; nothing
	 *                                        to do).
	 *   - cron_running = false, scheduled  → 200 "already scheduled"
	 *                                        (next tick is queued; nothing
	 *                                        to do).
	 *   - cron_running = false, not queued → wp_schedule_single_event;
	 *                                        200 on success, 500 on
	 *                                        scheduling failure.
	 *
	 * Returning 200 in the "already running / already scheduled" cases is
	 * deliberate — from the frontend's perspective those are non-errors.
	 *
	 * @param mixed $post_data Unused — accepted for route-layer compatibility.
	 * @return array
	 */
	public function wptw_hardening_audit_cron_if_failed( $post_data = null ) {
		unset( $post_data );

		try {
			$is_enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $is_enabled['feature_enable'] ) ) {
				Log::error(
					'Hardening Audit feature is not enabled',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_cron_if_failed_on_attempt',
					)
				);
				return array(
					'code'           => 400,
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $is_enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit feature is not enabled. Please enable it first.', 'tailwatch' ),
				);
			}

			$cancel_pause = $this->get_cancel_pause_data();
			if ( empty( $cancel_pause ) ) {
				// No active run at all — nothing for the recovery endpoint
				// to do. Returning 404 keeps the contract honest: this
				// endpoint nudges an existing run, it does not start one.
				return array(
					'code'    => 404,
					'data'    => array(),
					'message' => __( 'No hardening audit run found.', 'tailwatch' ),
				);
			}

			$cron_running = ! empty( $cancel_pause['cron_running'] );

			if ( false === $cron_running ) {
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, self::CRON_HOOK );

					if ( $cron_scheduled ) {
						Log::info(
							'Successfully scheduled a new Hardening Audit cron job.',
							array(
								'feature' => 'hardening_audit',
								'action'  => 'hardening_audit_cron_if_failed',
							)
						);
						return array(
							'code'    => 200,
							'data'    => '',
							'message' => __( 'Again attempt to run the cron', 'tailwatch' ),
						);
					}

					Log::error(
						'Hardening Audit Cron scheduling failed.',
						array(
							'feature' => 'hardening_audit',
							'action'  => 'hardening_audit_cron_if_failed_on_attempt',
						)
					);
					return array(
						'code'    => 500,
						'data'    => array(),
						'message' => __( 'Error: Cron job could not be scheduled. Please try again.', 'tailwatch' ),
					);
				}

				Log::info(
					'Cron job is already scheduled for Hardening Audit.',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_cron_if_failed',
					)
				);
				return array(
					'code'    => 200,
					'data'    => '',
					'message' => __( 'Cron job is already scheduled.', 'tailwatch' ),
				);
			}

			Log::info(
				'Hardening Audit cron job is currently running.',
				array(
					'feature' => 'hardening_audit',
					'action'  => 'hardening_audit_cron_if_failed',
				)
			);
			return array(
				'code'    => 200,
				'data'    => '',
				'message' => __( 'Cron job is already running.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'hardening_audit',
					'action'  => 'hardening_audit_cron_if_failed_on_attempt',
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Error checking cron status.', 'tailwatch' ),
			);
		}
	}

	// Chunked worker.

	/**
	 * Cron callback. Wraps the worker in heartbeat/state updates and a
	 * try/catch so a single check that throws cannot leave the process row
	 * stuck — the failure is recorded and the cron is unscheduled cleanly.
	 *
	 * @return void
	 */
	public function wptw_run_hardening_audit_with_monitoring() {
		$cancel_pause = $this->get_cancel_pause_data();
		if ( empty( $cancel_pause ) || empty( $cancel_pause['scan_state'] ) ) {
			return;
		}
		// Bail on any non-running state. 'completed' is included so a stray
		// re-firing of the chunked-worker cron hook after finalize_audit
		// cannot fall through to run_next_check_chunk → finalize_audit a
		// second time and insert a duplicate audit_report row.
		if ( in_array( $cancel_pause['scan_state'], array( 'pause', 'cancel', 'completed' ), true ) ) {
			return;
		}

		// Boost PHP limits before the chunked worker runs — mirrors Backup
		// and File Integrity so a large site's audit doesn't hit the host's
		// default memory_limit / max_execution_time. Idempotent; only raises
		// values when they're lower than recommended.
		$performance_controller = new PerformanceOptimizerController();
		$performance_controller->wptw_boost_for_scanning();

		$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : null;
		if ( $process_id ) {
			$this->process_manager->heart_beat( $process_id );
			$this->process_manager->update_state( $process_id, 'in_progress' );
		}

		try {
			$this->run_next_check_chunk();
			if ( $process_id ) {
				$this->process_manager->heart_beat( $process_id );
			}
		} catch ( \Throwable $e ) {
			if ( $process_id ) {
				$this->process_manager->mark_failed( $process_id, $e->getMessage() );
			}
			Log::error(
				'Hardening audit worker exception: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_worker_exception',
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * Run exactly one check, persist its result, and either reschedule for
	 * the next chunk or finalize. Bailing out at the top of the function on
	 * a pause/cancel flag means a user click takes effect on the very next
	 * tick — no need to wait for the in-flight chunk to finish.
	 *
	 * @return void
	 */
	private function run_next_check_chunk() {
		$progress = $this->get_progress_data();
		if ( empty( $progress ) ) {
			return;
		}

		// Re-check pause/cancel/completed after reading progress — there is
		// a small window between the cron callback's check and this one
		// where the user could have clicked pause, or a sibling cron tick
		// could have raced ahead and finalized.
		$cancel_pause = $this->get_cancel_pause_data();
		if ( ! empty( $cancel_pause['scan_state'] ) && in_array( $cancel_pause['scan_state'], array( 'pause', 'cancel', 'completed' ), true ) ) {
			return;
		}

		$cancel_pause['cron_running'] = true;
		$this->update_cancel_pause_data( $cancel_pause );

		$pipeline  = HardeningChecksController::get_check_pipeline();
		$remaining = isset( $progress['remaining_checks'] ) && is_array( $progress['remaining_checks'] )
			? $progress['remaining_checks']
			: array();

		// No checks left — finalize. This can happen if the controller is
		// re-entered after the last check ran but before finalize logged
		// completion (e.g. PHP timeout between the two operations).
		if ( empty( $remaining ) ) {
			$this->finalize_audit( $progress );
			return;
		}

		$check_key = array_shift( $remaining );
		$method    = isset( $pipeline[ $check_key ] ) ? $pipeline[ $check_key ] : null;

		// An unknown key in the queue means the pipeline list changed
		// between plugin versions. Skip it and continue with the rest of
		// the queue rather than aborting the whole audit.
		if ( null === $method || ! method_exists( $this->checks, $method ) ) {
			$progress['remaining_checks'] = $remaining;
			$this->update_progress_data( $progress );
			$this->reschedule_next_chunk();
			return;
		}

		// Resolve the human-readable label for both log lines from the
		// shared static map. Falls back to the raw key for any pipeline
		// entry without an explicit label (defensive only — shouldn't
		// happen since the maps are kept in lockstep).
		$labels = HardeningChecksController::get_check_labels();
		$label  = isset( $labels[ $check_key ] ) ? (string) $labels[ $check_key ] : $check_key;

		// Pre-action live-log line, mirroring the convention DB Optimizer
		// uses ("Processing spam comments (rows N-M)") and Integrity uses
		// ("Scanning files: N-M"): announce what we're doing in present
		// tense BEFORE the work happens, with a human-readable name.
		$log_file       = $this->wptw_get_log_file_path();
		$live_logs      = new LiveLogsController();
		$has_log_target = ( '' !== $log_file && file_exists( $log_file ) );

		if ( $has_log_target ) {
			$live_logs->update_live_logs_records(
				sprintf( 'Verifying: %s', $label ),
				$log_file,
				'INFO'
			);
		}

		try {
			$result = $this->checks->{$method}();
		} catch ( \Throwable $e ) {
			$result = $this->checks->build_error_result( $check_key, $label, $e );
		}

		if ( ! isset( $progress['completed_checks'] ) || ! is_array( $progress['completed_checks'] ) ) {
			$progress['completed_checks'] = array();
		}
		$progress['completed_checks'][ $check_key ] = $result;

		if ( ! isset( $progress['summary'] ) || ! is_array( $progress['summary'] ) ) {
			$progress['summary'] = $this->empty_summary();
		}
		if ( isset( $result['status'], $progress['summary'][ $result['status'] ] ) ) {
			++$progress['summary'][ $result['status'] ];
		}

		$progress['remaining_checks'] = $remaining;
		$this->update_progress_data( $progress );

		// Mirror the same percentage onto cancel_pause so wptw_import_live_logs
		// and any other consumer reading cancel_pause['progress'] see the
		// real progress, not the stale 0 we seeded at start. Independently
		// useful: the verify-status endpoint pulls from progress, but
		// LiveLogsController pulls from cancel_pause.
		$total = count( $pipeline );
		$done  = count( $progress['completed_checks'] );
		$pct   = $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 0;

		$cancel_pause            = $this->get_cancel_pause_data();
		if ( is_array( $cancel_pause ) ) {
			$cancel_pause['progress'] = $pct;
			$this->update_cancel_pause_data( $cancel_pause );
		}

		// Post-action live-log line, mirroring DB Optimizer's "Spam comments
		// cleanup completed" (OK level) — same shape, status-driven level.
		// Uses the human-readable label and a capitalized status word so the
		// console reads naturally for end-users.
		if ( $has_log_target ) {
			$status        = isset( $result['status'] ) ? (string) $result['status'] : '';
			$result_label  = isset( $result['feature_label'] ) ? (string) $result['feature_label'] : $label;
			$level         = $this->live_log_level_for_status( $status );
			$status_human  = '' !== $status ? ucfirst( $status ) : 'Unknown';
			$check_message = isset( $result['message'] ) ? (string) $result['message'] : '';

			$message = '' !== $check_message
				? sprintf( '%1$s: %2$s — %3$s', $result_label, $status_human, $check_message )
				: sprintf( '%1$s: %2$s', $result_label, $status_human );

			$live_logs->update_live_logs_records( $message, $log_file, $level );
		}

		if ( empty( $remaining ) ) {
			$this->finalize_audit( $progress );
			return;
		}

		$this->reschedule_next_chunk();
	}

	/**
	 * Map a check's status taxonomy to a LiveLogs level string.
	 *
	 * Levels picked to match the conventions the existing modules already
	 * use:
	 *   - SECURE   → OK        (DB Optimizer logs sub-task success at OK,
	 *                          e.g. "Spam comments cleanup completed")
	 *   - WARNING  → WARN
	 *   - CRITICAL → CRITICAL
	 *   - ERROR    → ERROR
	 *   - NOTICE   → INFO      (informational; the check has nothing
	 *                          actionable to report)
	 *
	 * Frontends that colour-code log lines by level get the right colour
	 * automatically without parsing the message text.
	 *
	 * @param string $status
	 * @return string
	 */
	private function live_log_level_for_status( $status ) {
		switch ( $status ) {
			case HardeningChecksController::STATUS_SECURE:
				return 'OK';
			case HardeningChecksController::STATUS_CRITICAL:
				return 'CRITICAL';
			case HardeningChecksController::STATUS_WARNING:
				return 'WARN';
			case HardeningChecksController::STATUS_ERROR:
				return 'ERROR';
			case HardeningChecksController::STATUS_NOTICE:
			default:
				return 'INFO';
		}
	}

	/**
	 * Schedule the next chunk 5 seconds out — same cadence DB Optimizer uses
	 * between chunks. Skips if WP-Cron already has the event queued so we
	 * never double-schedule.
	 *
	 * @return void
	 */
	private function reschedule_next_chunk() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * All checks have run — write the report row, flip scan_state, and
	 * tell ProcessManager the run is done.
	 *
	 * @param array $progress
	 * @return void
	 */
	private function finalize_audit( array $progress ) {
		$completed_at = time();
		$progress['is_completed']    = true;
		$progress['completion_time'] = $completed_at;
		$this->update_progress_data( $progress );

		$report = array(
			'id'              => isset( $progress['run_id'] ) ? (int) $progress['run_id'] : $completed_at,
			'started_time'    => isset( $progress['started_time'] ) ? (int) $progress['started_time'] : $completed_at,
			'completion_time' => $completed_at,
			'scan_time'       => current_time( 'mysql' ),
			'scan_type'       => isset( $progress['scan_type'] ) ? $progress['scan_type'] : 'manually',
			'is_completed'    => true,
			'summary'         => isset( $progress['summary'] ) ? $progress['summary'] : $this->empty_summary(),
			'checks'          => isset( $progress['completed_checks'] ) ? $progress['completed_checks'] : array(),
		);

		$this->insert_report_row( $report );

		$cancel_pause = $this->get_cancel_pause_data();
		if ( is_array( $cancel_pause ) ) {
			$cancel_pause['scan_state']   = 'completed';
			$cancel_pause['cron_running'] = false;
			$cancel_pause['progress']     = 100;
			$this->update_cancel_pause_data( $cancel_pause );

			$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : null;
			if ( $process_id ) {
				$this->process_manager->mark_completed( $process_id );
			}
		}

		// Final live-log lines + completion flag. Mirrors Integrity's
		// two-line completion: INFO "Scan completed successfully" then
		// RESULT "Changes found: N". wptw_live_logs_completed flips
		// is_completed=true on the JSON file so the frontend's polling
		// loop tears itself down after one last fetch.
		$log_file = $this->wptw_get_log_file_path();
		if ( '' !== $log_file && file_exists( $log_file ) ) {
			$live_logs = new LiveLogsController();

			$live_logs->update_live_logs_records(
				'Hardening audit completed successfully',
				$log_file,
				'INFO'
			);

			$live_logs->update_live_logs_records(
				sprintf(
					/* translators: 1: secure count, 2: notice count, 3: warning count, 4: critical count, 5: error count */
					__( 'Result: %1$d secure, %2$d notice, %3$d warning, %4$d critical, %5$d error', 'tailwatch' ),
					(int) ( $report['summary'][ HardeningChecksController::STATUS_SECURE ] ?? 0 ),
					(int) ( $report['summary'][ HardeningChecksController::STATUS_NOTICE ] ?? 0 ),
					(int) ( $report['summary'][ HardeningChecksController::STATUS_WARNING ] ?? 0 ),
					(int) ( $report['summary'][ HardeningChecksController::STATUS_CRITICAL ] ?? 0 ),
					(int) ( $report['summary'][ HardeningChecksController::STATUS_ERROR ] ?? 0 )
				),
				$log_file,
				'RESULT'
			);

			$live_logs->wptw_live_logs_completed( true, $log_file );
		}

		$summary        = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$critical_count = (int) ( $summary[ HardeningChecksController::STATUS_CRITICAL ] ?? 0 );
		$warning_count  = (int) ( $summary[ HardeningChecksController::STATUS_WARNING ] ?? 0 );
		$error_count    = (int) ( $summary[ HardeningChecksController::STATUS_ERROR ] ?? 0 );
		$total_issues   = $critical_count + $warning_count + $error_count;

		if ( 0 === $total_issues ) {
			Log::info(
				"Good news — your latest hardening audit didn't find any issues. Your site's configuration looks secure as of this scan.",
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_completed',
					'title'     => 'Hardening audit passed',
					'run_id'    => $report['id'],
					'summary'   => $report['summary'],
					'meta_data' => array(
						'feature'         => 'Hardening Audit',
						'event'           => 'Completed',
						'Critical issues' => 0,
						'Warnings'        => 0,
						'Errors'          => 0,
						'threat_level'    => 'None',
						'record_id'       => $report['id'],
					),
				)
			);
		} else {
			$issue_noun = ( 1 === $total_issues ) ? 'hardening issue' : 'hardening issues';
			Log::warning(
				"We found {$total_issues} {$issue_noun} on your site. Review the report to harden the flagged areas before they become a problem.",
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_completed',
					'title'     => "{$total_issues} {$issue_noun} found",
					'run_id'    => $report['id'],
					'summary'   => $report['summary'],
					'meta_data' => array(
						'feature'         => 'Hardening Audit',
						'event'           => 'Completed',
						'Critical issues' => $critical_count,
						'Warnings'        => $warning_count,
						'Errors'          => $error_count,
						'threat_level'    => $critical_count > 0 ? 'Critical' : 'Warning',
						'record_id'       => $report['id'],
					),
				)
			);
		}

		do_action( 'wptw_after_hardening_audit_completed', $report );
	}

	private function empty_summary() {
		return array(
			HardeningChecksController::STATUS_SECURE   => 0,
			HardeningChecksController::STATUS_NOTICE   => 0,
			HardeningChecksController::STATUS_WARNING  => 0,
			HardeningChecksController::STATUS_CRITICAL => 0,
			HardeningChecksController::STATUS_ERROR    => 0,
		);
	}

	// Garbage / report management.

	/**
	 * Sweep the in-progress state rows so the next start is clean. Called
	 * by the recurring cron right before a new automatic run, by the manual
	 * start path when there is no paused run to resume, and exposed as a
	 * route so the frontend can force-clear stuck state if needed. Reports
	 * are NOT touched — those persist until retention pruning.
	 *
	 * Returns the standard `{ code, data, message }` envelope so the AJAX
	 * layer's `code === 200` success-detection works (a bare `bool` would
	 * be misread as an error and trigger `wp_send_json_error`).
	 *
	 * @param mixed $post_data Unused — accepted for route-layer compatibility.
	 * @return array
	 */
	public function wptw_remove_garbage_entries_audit( $post_data = null ) {
		unset( $post_data );

		try {
			$db_model = new DBModel();
			$db_model->delete_table_rows(
				array(
					'key'    => self::STATE_KEY,
					'option' => self::PROGRESS_OPTION,
				)
			);
			$db_model->delete_table_rows(
				array(
					'key'    => self::STATE_KEY,
					'option' => self::CANCEL_PAUSE_OPTION,
				)
			);

			return array(
				'code'    => 200,
				'data'    => array(),
				'message' => __( 'Hardening audit garbage entries removed.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Hardening audit garbage cleanup failed: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_garbage_cleanup_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to remove hardening audit garbage entries.', 'tailwatch' ),
			);
		}
	}

	/**
	 * AJAX/Mobile fetch for the current run's live-log stream. Frontend
	 * polls this with `{ last_log_index: N }` and gets back any new lines
	 * plus the current scan_state, progress, ETA, and is_completed flag —
	 * mirroring `wptw_get_optimize_live_logs` exactly so the same status
	 * UI component can render either feature.
	 *
	 * Returns 404 when there is no active or paused run (no log file on
	 * disk yet); the caller should treat that as "show the start button"
	 * rather than "error".
	 *
	 * @param mixed $post_data
	 * @return array
	 */
	public function wptw_get_hardening_audit_live_logs( $post_data = null ) {
		try {
			$is_enabled = $this->wptw_hardening_audit_feature_enable();
			if ( empty( $is_enabled['feature_enable'] ) ) {
				Log::error(
					'Hardening Audit feature is not enabled',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_live_logs_failed',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => false,
					'parent_enable'  => ! empty( $is_enabled['parent_enable'] ),
					'message'        => __( 'Hardening Audit feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$log_file = $this->wptw_get_log_file_path();
			if ( '' === $log_file ) {
				return array(
					'code'    => 404,
					'data'    => array(),
					'message' => __( 'No hardening audit in progress.', 'tailwatch' ),
				);
			}

			$cancel_pause = $this->get_cancel_pause_data();
			if ( empty( $cancel_pause ) ) {
				$cancel_pause = array();
			}

			$live_logs = new LiveLogsController();
			return $live_logs->wptw_import_live_logs(
				$post_data,
				$log_file,
				$cancel_pause,
				'hardening_audit'
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Hardening audit live logs fetch failed: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_live_logs_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to fetch hardening audit live logs.', 'tailwatch' ),
			);
		}
	}

	/**
	 * List completed audit reports, newest first.
	 *
	 * Pagination contract matches Integrity's `wptw_get_file_logs_data`:
	 * the caller passes `{ page, limit }` (1-indexed page), and the
	 * response carries the items as `data` directly plus a separate
	 * `pagination: { total, page, limit, total_pages }` block. Defaults
	 * are `page=1`, `limit=10` — same defaults the reference module uses.
	 *
	 * The list view returns lightweight summaries only; the caller asks
	 * for `wptw_get_hardening_audit_report_by_id` when it wants the full
	 * per-check details, so we don't ship 17 check payloads through every
	 * list refresh.
	 *
	 * @param mixed $post_data
	 * @return array
	 */
	public function wptw_list_hardening_audit_reports( $post_data = null ) {
		try {
			$data  = $this->parse_post_data( $post_data );
			$limit = isset( $data['limit'] ) && is_numeric( $data['limit'] ) && (int) $data['limit'] > 0 ? (int) $data['limit'] : 10;
			$page  = isset( $data['page'] ) && is_numeric( $data['page'] ) && (int) $data['page'] > 0 ? (int) $data['page'] : 1;

			$model = new HardeningAuditModel();
			$total = $model->count_reports();

			$offset      = ( $page - 1 ) * $limit;
			$total_pages = $limit > 0 ? (int) ceil( $total / $limit ) : 0;

			$rows = $model->get_reports_page( $limit, $offset );

			$out = array();
			if ( ! empty( $rows ) ) {
				foreach ( $rows as $index => $row ) {
					$decoded = isset( $row['value'] ) ? json_decode( $row['value'], true ) : null;
					if ( ! is_array( $decoded ) ) {
						continue;
					}
					$out[] = array(
						'sr'              => $offset + $index + 1,
						'id'              => isset( $decoded['id'] ) ? (int) $decoded['id'] : (int) $row['child_of'],
						'started_time'    => isset( $decoded['started_time'] ) ? (int) $decoded['started_time'] : 0,
						'completion_time' => isset( $decoded['completion_time'] ) ? (int) $decoded['completion_time'] : 0,
						'scan_time'       => isset( $decoded['scan_time'] ) ? $decoded['scan_time'] : $row['date_created'],
						'scan_type'       => isset( $decoded['scan_type'] ) ? $decoded['scan_type'] : 'on-demand',
						'summary'         => isset( $decoded['summary'] ) ? $decoded['summary'] : $this->empty_summary(),
						'is_completed'    => ! empty( $decoded['is_completed'] ),
					);
				}
			}

			return array(
				'code'       => 200,
				'message'    => __( 'Hardening audit reports retrieved successfully.', 'tailwatch' ),
				'data'       => $out,
				'pagination' => array(
					'total'       => $total,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => $total_pages,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in wptw_list_hardening_audit_reports: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_reports_list_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'       => 500,
				'message'    => __( 'Error retrieving hardening audit reports.', 'tailwatch' ),
				'data'       => array(),
				'pagination' => null,
			);
		}
	}

	/**
	 * Fetch a single audit report by its run_id. Mirrors the
	 * `wptw_get_files_log_by_id` shape: report fields land on `data`
	 * directly (not wrapped under `data.report`) so the frontend renders
	 * with the same template path used for the integrity-detail view.
	 *
	 * @param mixed $post_data
	 * @return array
	 */
	public function wptw_get_hardening_audit_report_by_id( $post_data = null ) {
		try {
			$data      = $this->parse_post_data( $post_data );
			$report_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
			if ( $report_id <= 0 ) {
				Log::error(
					'Missing or invalid report id',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_report_details_by_id_failed',
					)
				);
				return array(
					'code'    => 400,
					'data'    => null,
					'message' => __( 'Missing or invalid report id.', 'tailwatch' ),
				);
			}

			$model      = new HardeningAuditModel();
			$report_raw = $model->get_report_value_by_id( $report_id );

			if ( null === $report_raw ) {
				return array(
					'code'    => 404,
					'data'    => null,
					'message' => __( 'Hardening audit report not found.', 'tailwatch' ),
				);
			}

			$decoded = json_decode( $report_raw, true );
			if ( ! is_array( $decoded ) ) {
				Log::error(
					'Failed to decode hardening audit report',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_report_details_by_id_failed',
					)
				);
				return array(
					'code'    => 500,
					'data'    => null,
					'message' => __( 'Hardening audit report could not be decoded.', 'tailwatch' ),
				);
			}

			return array(
				'code'    => 200,
				'message' => __( 'Hardening audit report retrieved successfully.', 'tailwatch' ),
				'data'    => $decoded,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in wptw_get_hardening_audit_report_by_id: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_report_details_by_id_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => null,
				'message' => __( 'Error retrieving hardening audit report.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Delete one, several, or all audit reports.
	 *
	 * Mirrors `wptw_delete_comparison_by_id` exactly:
	 *   - `{ ids: [1, 2, 3] }`   → delete those specific report ids
	 *   - `{ is_delete: true }`  → delete every report
	 *   - neither / both empty   → 400 with the same wording the reference
	 *                              module uses ("Either is_delete must be
	 *                              true or a non-empty ids array must be
	 *                              provided.")
	 *
	 * Returns the standard `{ data: { success_count, failed_ids }, ... }`
	 * shape, with HTTP code:
	 *   - 200 on full success or "delete-all but already empty"
	 *   - 207 on partial success (some ids deleted, some failed)
	 *   - 400 when no provided id mapped to a real report
	 *   - 500 on exception
	 *
	 * Gates on `ProcessGuard::ensure_can_modify_artifacts(['hardening_audit'])`
	 * because the report rows we're about to delete could be the very rows
	 * the worker is mid-write on during finalize. Internal callers (cron,
	 * recovery) bypass this gate by calling DBModel directly.
	 *
	 * @param mixed $post_data
	 * @return array
	 */
	public function wptw_delete_hardening_audit_report_by_id( $post_data = null ) {
		try {
			$blocked = ( new ProcessGuard() )->ensure_can_modify_artifacts(
				array( self::PROCESS_TYPE )
			);
			if ( null !== $blocked ) {
				return $blocked;
			}

			$data = $this->parse_post_data( $post_data );

			$has_is_delete = isset( $data['is_delete'] );
			$has_ids       = isset( $data['ids'] ) && is_array( $data['ids'] ) && ! empty( $data['ids'] );

			if ( ! $has_is_delete && ! $has_ids ) {
				Log::error(
					'Missing delete parameters',
					array(
						'feature' => 'hardening_audit',
						'action'  => 'hardening_audit_delete_failed',
						'error'   => 'Either is_delete must be true or a non-empty ids array must be provided.',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Either is_delete must be true or a non-empty ids array must be provided.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$delete_all = $has_is_delete && true === $data['is_delete'];
			$ids        = $delete_all ? array() : array_map( 'intval', $data['ids'] );

			$result = $this->delete_hardening_audit_reports_internal( $ids, $delete_all );

			$success_count = (int) $result['success_count'];
			$failed_ids    = (array) $result['failed_ids'];

			Log::info(
				'Hardening audit reports deleted',
				array(
					'feature'       => 'hardening_audit',
					'action'        => 'hardening_audit_deleted',
					'success_count' => $success_count,
					'failed_count'  => count( $failed_ids ),
				)
			);

			$response = array(
				'data'    => array(
					'success_count' => $success_count,
					'failed_ids'    => $failed_ids,
				),
				'message' => __( 'Hardening audit reports deleted successfully.', 'tailwatch' ),
				'code'    => 200,
			);

			if ( ! empty( $failed_ids ) && $success_count > 0 ) {
				$response['code']    = 207;
				$response['message'] = __( 'Some hardening audit reports were deleted successfully, but some failed.', 'tailwatch' );
			} elseif ( ! empty( $failed_ids ) && 0 === $success_count ) {
				$response['code']    = 400;
				$response['message'] = __( 'No valid hardening audit reports found for the provided ids.', 'tailwatch' );
			} elseif ( $delete_all && 0 === $success_count ) {
				$response['code']    = 200;
				$response['message'] = __( 'No hardening audit reports found to delete.', 'tailwatch' );
			}

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in wptw_delete_hardening_audit_report_by_id: ' . $e->getMessage(),
				array(
					'feature'   => 'hardening_audit',
					'action'    => 'hardening_audit_delete_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error deleting hardening audit reports.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Internal bulk-delete primitive used by both the public AJAX entry and
	 * any future internal callers (e.g. a "clear all reports on uninstall"
	 * sweeper). Returns `{ success_count, failed_ids }` in the same shape
	 * `wptw_delete_comparison_entry` uses.
	 *
	 * Delete-all bypasses the per-id loop with a single SQL DELETE so it
	 * stays O(1) regardless of report count.
	 *
	 * @param array<int> $ids
	 * @param bool       $delete_all
	 * @return array{ success_count:int, failed_ids:array<int> }
	 */
	private function delete_hardening_audit_reports_internal( array $ids = array(), $delete_all = false ) {
		if ( $delete_all ) {
			$model = new HardeningAuditModel();
			return array(
				'success_count' => $model->delete_all_reports(),
				'failed_ids'    => array(),
			);
		}

		$success_count = 0;
		$failed_ids    = array();
		$db_model      = new DBModel();

		foreach ( $ids as $report_id ) {
			$report_id = (int) $report_id;
			if ( $report_id <= 0 ) {
				$failed_ids[] = $report_id;
				continue;
			}

			$deleted = $db_model->delete_table_rows(
				array(
					'key'      => self::STATE_KEY,
					'option'   => self::REPORT_OPTION,
					'child_of' => $report_id,
				)
			);

			if ( $deleted ) {
				++$success_count;
			} else {
				$failed_ids[] = $report_id;
			}
		}

		return array(
			'success_count' => $success_count,
			'failed_ids'    => $failed_ids,
		);
	}

	/**
	 * Drop reports older than the configured retention window. Driven by
	 * HardeningAuditMaintenanceCronJob (every 30 minutes), not the worker.
	 *
	 * Honours the "Keep All Data" sentinel that
	 * `get_report_retention_seconds` returns as 0 — same pattern Integrity
	 * uses for its `wptw_maintain_integrity_entry`. Skipping rather than
	 * passing a giant cutoff value avoids any chance of accidentally
	 * deleting everything if the retention value were ever parsed wrong.
	 *
	 * @return int Rows deleted (best-effort), or 0 when Keep All Data is set.
	 */
	public function wptw_prune_hardening_audit_reports() {
		$retention_seconds = $this->get_report_retention_seconds();

		if ( 0 === $retention_seconds ) {
			// "Keep All Data" — caller wants the report history preserved
			// indefinitely; do nothing.
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_seconds );
		$model  = new HardeningAuditModel();

		return $model->delete_reports_older_than( $cutoff );
	}
}