<?php
/**
 * Process Status Service
 *
 * Layered "is this process running?" lookup that combines ProcessManager's
 * heartbeat-tracked registry with each module's own verify_*_status method
 * as a ground-truth fallback.
 *
 * Use this from any caller that needs to gate behavior on whether another
 * module is currently busy (e.g. backup deciding whether to start a new
 * optimization or adopt one already running). Avoids each call site having
 * to know which controller class and which verify method correspond to
 * which process_type.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services
 */

namespace Tailwatch\Admin\App\Api\Services;

use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkStatus;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProcessStatusService
 *
 */
class ProcessStatusService {

	/**
	 * ProcessManager states that count as "currently running" for the purpose
	 * of Layer 1. 'pause' is intentionally excluded — a paused process is not
	 * doing work and should not block callers.
	 *
	 * @var array<string>
	 */
	const ACTIVE_STATES = array( 'in_progress', 'pending' );

	/**
	 * @var ProcessManager
	 */
	private $process_manager;

	public function __construct() {
		$this->process_manager = new ProcessManager();
	}

	/**
	 * Returns true when the given process_type is currently running.
	 *
	 * Checks two layers in order, returning early on the first positive match:
	 *
	 *   1. ProcessManager registry — fast (single query), uses heartbeat-aware
	 *      stuck detection so a crashed process row is not treated as alive.
	 *   2. Module's own verify_*_status method — slower (one DB read for the
	 *      module's progress row) but ground truth, since the module owns
	 *      its own state representation.
	 *
	 * Process types without a registered Layer-2 fallback short-circuit at
	 * Layer 1 — the result is still useful, just less robust against stale
	 * ProcessManager rows.
	 *
	 * @param string $process_type One of the ProcessManager-registered types
	 *                             (backup, db_optimize, files_integrity,
	 *                             malware_scan, …).
	 * @return bool
	 */
	public function is_running( $process_type ) {
		if ( ! is_string( $process_type ) || '' === $process_type ) {
			return false;
		}

		list( $active_rows, $stuck_rows ) = $this->classify_active_processes();

		if ( isset( $active_rows[ $process_type ] ) ) {
			// Layer 1 says active. Cross-check with the module's positive
			// completion signal: if the module asserts it has finished, the
			// DB row is orphaned (state file says completed but mark_completed
			// never fired). Self-heal so the gate stops blocking immediately
			// instead of waiting 5 min for stuck-detection to kick in.
			if ( $this->is_completed_via_module_fallback( $process_type ) ) {
				$process_id = isset( $active_rows[ $process_type ]['process_id'] ) ? $active_rows[ $process_type ]['process_id'] : '';
				$this->reap_orphan_active_row( $process_type, $process_id );
				return false;
			}
			return true;
		}

		// Layer 1 saw a row for this type but classified it stuck — treat as
		// not-running and DO NOT fall through to Layer 2. The module's own
		// scan_state field will likely still say 'in-progress' after a hard
		// crash (no code ran to update it), so trusting Layer 2 here would
		// block users from recovering until the cleanup cron runs.
		if ( isset( $stuck_rows[ $process_type ] ) ) {
			return false;
		}

		return $this->is_running_via_module_fallback( $process_type );
	}

	/**
	 * Find the first process_type from the given list that is currently running,
	 * or null if none. Optimized for callers that need to check several types at
	 * once (typical of ProcessGuard) — Layer 1 (ProcessManager registry) is
	 * batched into a single DB read instead of one per type.
	 *
	 * Layer 2 (module-specific verify) still runs per-type, but only when
	 * Layer 1 didn't classify the type as either active or stuck — so the
	 * common case where a process IS running short-circuits at the cheap
	 * layer, and stuck rows are uniformly treated as not-running across both
	 * layers.
	 *
	 * Order of return is the order types were passed in. The function returns
	 * on the first match, so callers control priority.
	 *
	 * @param array<string> $process_types Process type names to check.
	 * @return string|null  The first running process_type found, or null.
	 */
	public function find_first_running( array $process_types ) {
		if ( empty( $process_types ) ) {
			return null;
		}

		list( $active_rows, $stuck_rows ) = $this->classify_active_processes();

		// Layer 1 — return the first type with a healthy active row.
		foreach ( $process_types as $type ) {
			if ( ! is_string( $type ) || '' === $type ) {
				continue;
			}
			if ( ! isset( $active_rows[ $type ] ) ) {
				continue;
			}
			// Cross-check Layer 1 active against the module's positive
			// completion signal. If the module asserts it has finished while
			// the DB row still says active, the row is orphaned (state file
			// says completed but mark_completed never fired). Self-heal and
			// continue to the next type instead of blocking.
			if ( $this->is_completed_via_module_fallback( $type ) ) {
				$process_id = isset( $active_rows[ $type ]['process_id'] ) ? $active_rows[ $type ]['process_id'] : '';
				$this->reap_orphan_active_row( $type, $process_id );
				continue;
			}
			return $type;
		}

		// Layer 2 — per-type module fallback, but skip types Layer 1 already
		// classified as stuck. Without this skip, a hard-crashed process would
		// have its module-level scan_state still say 'in-progress' and Layer 2
		// would falsely report it running.
		foreach ( $process_types as $type ) {
			if ( ! is_string( $type ) || '' === $type ) {
				continue;
			}
			if ( isset( $stuck_rows[ $type ] ) ) {
				continue;
			}
			// Skip types already resolved at Layer 1 (returned or self-healed
			// via reap). Re-asking the running fallback for a reaped row would
			// just re-confirm the completion signal we already trusted.
			if ( isset( $active_rows[ $type ] ) ) {
				continue;
			}
			if ( $this->is_running_via_module_fallback( $type ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Read the ProcessManager registry once and split rows into:
	 *   - $active_rows: type => row, healthy active rows (heartbeat fresh)
	 *   - $stuck_rows:  type => row, active rows with stale heartbeat
	 *
	 * Both decisions are computed from the row data already returned by
	 * get_all_active_processes — no extra DB query per row. The data shape
	 * (state, last_heartbeat, config.stuck_threshold) is the same one used
	 * by ProcessMonitorModel::get_stuck_processes, so the stuck definition
	 * here matches the rest of the system.
	 *
	 * Values are the full row arrays (not booleans) so callers can read
	 * process_id off the active row when self-healing an orphan — saves a
	 * second DB read for the same data.
	 *
	 * @return array{0: array<string,array>, 1: array<string,array>}
	 */
	private function classify_active_processes() {
		$active_rows = array();
		$stuck_rows  = array();

		$rows = $this->process_manager->get_all_active_processes();
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array( $active_rows, $stuck_rows );
		}

		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local wallclock seconds for scheduling comparisons.

		foreach ( $rows as $proc ) {
			if ( ! is_array( $proc ) || ! isset( $proc['process_type'], $proc['state'] ) ) {
				continue;
			}
			if ( ! in_array( $proc['state'], self::ACTIVE_STATES, true ) ) {
				continue;
			}

			$last_heartbeat  = isset( $proc['last_heartbeat'] ) ? strtotime( $proc['last_heartbeat'] ) : 0;
			$stuck_threshold = isset( $proc['config']['stuck_threshold'] ) ? (int) $proc['config']['stuck_threshold'] : 300;
			$is_stuck        = $last_heartbeat > 0 && ( $now - $last_heartbeat ) >= $stuck_threshold;

			if ( $is_stuck ) {
				$stuck_rows[ $proc['process_type'] ] = $proc;
			} else {
				$active_rows[ $proc['process_type'] ] = $proc;
			}
		}

		return array( $active_rows, $stuck_rows );
	}

	/**
	 * Layer 2 — module-specific verify fallback.
	 *
	 * Each module owns its predicate via the tailwatch_process_status_fallbacks
	 * filter. This service ships built-in entries for free-plugin types
	 * (db_optimize, files_integrity); the pro plugin hooks the filter to add
	 * its own (malware_scan, migration, restore, …).
	 *
	 * A throwing fallback is contained — the caller sees "not running" rather
	 * than the exception propagating, since Layer 1 has already returned false
	 * for this type and the caller's behavior is then identical to having no
	 * fallback registered.
	 *
	 * @param string $process_type
	 * @return bool
	 */
	private function is_running_via_module_fallback( $process_type ) {
		$fallbacks = $this->get_fallbacks();

		if ( ! isset( $fallbacks[ $process_type ] ) || ! is_callable( $fallbacks[ $process_type ] ) ) {
			return false;
		}

		try {
			return (bool) call_user_func( $fallbacks[ $process_type ] );
		} catch ( \Throwable $e ) {
			Log::error(
				'Process status fallback threw: ' . $e->getMessage(),
				array(
					'feature'      => 'process_status',
					'action'       => 'fallback_failed',
					'process_type' => $process_type,
					'exception'    => $e,
				)
			);
			return false;
		}
	}

	/**
	 * Built-in Layer-2 fallbacks for free-plugin process types, plus the
	 * tailwatch_process_status_fallbacks filter for pro-plugin and other extensions.
	 *
	 * @return array<string,callable>
	 */
	private function get_fallbacks() {
		$fallbacks = array(
			'db_optimize'         => array( $this, 'fallback_db_optimize' ),
			'files_integrity'     => array( $this, 'fallback_files_integrity' ),
			'backup'              => array( $this, 'fallback_backup' ),
			'search_replace'      => array( $this, 'fallback_search_replace' ),
			'broken_link_checker' => array( $this, 'fallback_broken_link_checker' ),
			'hardening_audit'     => array( $this, 'fallback_hardening_audit' ),
		);

		/**
		 * Filter the per-process_type fallback predicate map.
		 *
		 * Each entry maps `process_type => callable`. The callable takes no
		 * arguments and must return a truthy value when the module considers
		 * itself actively running (typically `scan_state === 'in-progress'`
		 * inside the module's existing verify_*_status response).
		 *
		 * Pro-plugin example:
		 *
		 *     add_filter( 'tailwatch_process_status_fallbacks', function ( $f ) {
		 *         $f['malware_scan'] = function () {
		 *             $s = ( new MalwareScannerController() )->tailwatch_malware_scanner_verify_status();
		 *             return isset( $s['scan_state'] ) && 'in-progress' === $s['scan_state'];
		 *         };
		 *         return $f;
		 *     } );
	     *
		 * @param array<string,callable> $fallbacks Built-in fallback map.
		 */
		return apply_filters( 'tailwatch_process_status_fallbacks', $fallbacks );
	}

	/**
	 * Built-in fallback for db_optimize.
	 *
	 * @return bool
	 */
	private function fallback_db_optimize() {
		$controller = new DatabaseOptimizerController();
		$status     = $controller->tailwatch_verify_db_optimize_status();

		return isset( $status['scan_state'] ) && 'in-progress' === $status['scan_state'];
	}

	/**
	 * Built-in fallback for files_integrity.
	 *
	 * Handles the edge case where IntegrityWatchController returns
	 * `is_request=true` without a scan_state field during the pre-comparison
	 * setup phase — that is still "running" from the caller's perspective.
	 *
	 * @return bool
	 */
	private function fallback_files_integrity() {
		$controller = new IntegrityWatchController();
		$status     = $controller->tailwatch_verify_integrity_current_status();

		if ( isset( $status['scan_state'] ) && 'in-progress' === $status['scan_state'] ) {
			return true;
		}

		return ! empty( $status['is_request'] ) && empty( $status['scan_state'] );
	}

	/**
	 * Built-in fallback for backup.
	 *
	 * @return bool
	 */
	private function fallback_backup() {
		$controller = new BackupController();
		$status     = $controller->tailwatch_verify_backup_status();

		return isset( $status['scan_state'] ) && 'in-progress' === $status['scan_state'];
	}

	/**
	 * Built-in fallback for search_replace.
	 *
	 * Includes three states as "running":
	 *   - 'in-progress'        — main search/replace pass
	 *   - 'reverting_changes'  — dry-run rollback phase
	 *   - 'cancel'             — live-mode cancel that is actively reverting
	 *                            (verify method only returns 'cancel' when
	 *                            dry_run=false, so this is exclusively the
	 *                            still-working case)
	 *
	 * @return bool
	 */
	private function fallback_search_replace() {
		$controller = new SearchReplaceController();
		$status     = $controller->tailwatch_verify_search_replace_status();

		if ( ! isset( $status['scan_state'] ) ) {
			return false;
		}
		return in_array(
			$status['scan_state'],
			array( 'in-progress', 'reverting_changes', 'cancel' ),
			true
		);
	}

	/**
	 * Built-in fallback for broken_link_checker.
	 *
	 * @return bool
	 */
	private function fallback_broken_link_checker() {
		$controller = new BrokenLinkStatus();
		$status     = $controller->tailwatch_verify_broken_link_status();

		return isset( $status['scan_state'] ) && 'in-progress' === $status['scan_state'];
	}

	/**
	 * Built-in fallback for hardening_audit.
	 *
	 * Mirrors the scan_state pattern used by db_optimize / backup / etc.
	 * The hardening audit returns scan_state only when a cancel_pause row
	 * exists (i.e., a run is active or just finished). Idle / never-run
	 * returns no scan_state field, which correctly maps to "not running".
	 *
	 * @return bool
	 */
	private function fallback_hardening_audit() {
		$controller = new HardeningAuditController();
		$status     = $controller->tailwatch_verify_hardening_audit_status();

		return isset( $status['scan_state'] ) && 'in-progress' === $status['scan_state'];
	}

	/**
	 * Module-specific "have I positively completed?" check.
	 *
	 * Distinct from is_running_via_module_fallback (the existing Layer-2
	 * predicate) which conflates "completed" with "state file missing" and
	 * "transient setup state" — all return false. Self-heal needs a positive
	 * completion signal to safely reap an orphaned active row, which the
	 * boolean predicate cannot give. The completion check is a separate map
	 * so each module returns true ONLY when its own state file explicitly
	 * says completed (not when the file is absent or the state is unknown).
	 *
	 * Throwing fallbacks are contained — a thrown error is treated as
	 * "cannot assert completion" (return false), the safer side because
	 * the reap path triggers only on an explicit true.
	 *
	 * @param string $process_type
	 * @return bool True iff the module asserts it has finished.
	 */
	private function is_completed_via_module_fallback( $process_type ) {
		$fallbacks = $this->get_completion_fallbacks();

		if ( ! isset( $fallbacks[ $process_type ] ) || ! is_callable( $fallbacks[ $process_type ] ) ) {
			return false;
		}

		try {
			return (bool) call_user_func( $fallbacks[ $process_type ] );
		} catch ( \Throwable $e ) {
			Log::error(
				'Process completion fallback threw: ' . $e->getMessage(),
				array(
					'feature'      => 'process_status',
					'action'       => 'completion_fallback_failed',
					'process_type' => $process_type,
					'exception'    => $e,
				)
			);
			return false;
		}
	}

	/**
	 * Built-in completion checkers for free-plugin process types, plus the
	 * tailwatch_process_completion_fallbacks filter for pro-plugin extensions.
	 *
	 * Each callable returns true ONLY when the module's own state file
	 * explicitly declares the work finished — not on missing state, not on
	 * transient/setup state. This is the positive signal that distinguishes
	 * "really completed but mark_completed() never fired" from "process is
	 * registered but hasn't started writing state yet".
	 *
	 * @return array<string,callable>
	 */
	private function get_completion_fallbacks() {
		$fallbacks = array(
			'db_optimize'         => array( $this, 'completion_db_optimize' ),
			'files_integrity'     => array( $this, 'completion_files_integrity' ),
			'backup'              => array( $this, 'completion_backup' ),
			'search_replace'      => array( $this, 'completion_search_replace' ),
			'broken_link_checker' => array( $this, 'completion_broken_link_checker' ),
			'hardening_audit'     => array( $this, 'completion_hardening_audit' ),
		);

		/**
		 * Filter the per-process_type completion-checker map.
		 *
		 * Each entry maps `process_type => callable`. The callable takes no
		 * arguments and must return true ONLY when the module asserts (via
		 * its own state file or equivalent) that work has finished. Returning
		 * true triggers a self-heal of the corresponding active DB row.
		 *
		 * Pro-plugin example:
		 *
		 *     add_filter( 'tailwatch_process_completion_fallbacks', function ( $f ) {
		 *         $f['malware_scan'] = function () {
		 *             $s = ( new MalwareScannerController() )->tailwatch_malware_scanner_verify_status();
		 *             return isset( $s['scan_state'] ) && 'completed' === $s['scan_state'];
		 *         };
		 *         return $f;
		 *     } );
	     *
		 * @param array<string,callable> $fallbacks Built-in completion fallback map.
		 */
		return apply_filters( 'tailwatch_process_completion_fallbacks', $fallbacks );
	}

	/**
	 * Built-in completion check for db_optimize.
	 *
	 * @return bool
	 */
	private function completion_db_optimize() {
		$controller = new DatabaseOptimizerController();
		$status     = $controller->tailwatch_verify_db_optimize_status();

		return isset( $status['scan_state'] ) && 'completed' === $status['scan_state'];
	}

	/**
	 * Built-in completion check for files_integrity.
	 *
	 * @return bool
	 */
	private function completion_files_integrity() {
		$controller = new IntegrityWatchController();
		$status     = $controller->tailwatch_verify_integrity_current_status();

		return isset( $status['scan_state'] ) && 'completed' === $status['scan_state'];
	}

	/**
	 * Built-in completion check for backup.
	 *
	 * @return bool
	 */
	private function completion_backup() {
		$controller = new BackupController();
		$status     = $controller->tailwatch_verify_backup_status();

		return isset( $status['scan_state'] ) && 'completed' === $status['scan_state'];
	}

	/**
	 * Built-in completion check for search_replace.
	 *
	 * @return bool
	 */
	private function completion_search_replace() {
		$controller = new SearchReplaceController();
		$status     = $controller->tailwatch_verify_search_replace_status();

		return isset( $status['scan_state'] ) && 'completed' === $status['scan_state'];
	}

	/**
	 * Built-in completion check for broken_link_checker.
	 *
	 * @return bool
	 */
	private function completion_broken_link_checker() {
		$controller = new BrokenLinkStatus();
		$status     = $controller->tailwatch_verify_broken_link_status();

		return isset( $status['scan_state'] ) && 'completed' === $status['scan_state'];
	}

	/**
	 * Built-in completion check for hardening_audit.
	 *
	 * Returns true ONLY when scan_state is explicitly 'completed' (set by
	 * the controller after a successful run finishes). The idle / never-run
	 * branch of tailwatch_verify_hardening_audit_status returns an array WITHOUT
	 * a scan_state field — the isset() guard correctly returns false there,
	 * preventing a false-heal on a process that just registered but hasn't
	 * yet written its first scan_state value.
	 *
	 * @return bool
	 */
	private function completion_hardening_audit() {
		$controller = new HardeningAuditController();
		$status     = $controller->tailwatch_verify_hardening_audit_status();

		return isset( $status['scan_state'] ) && 'completed' === $status['scan_state'];
	}

	/**
	 * Self-heal an orphan active row.
	 *
	 * Called when Layer 1 says a type is active (DB row exists with
	 * is_active=1 and fresh heartbeat) but the module's own completion
	 * check says it has finished. Cause: an exception or timeout struck
	 * between the module writing its "completed" state file and calling
	 * mark_completed() on the DB row, leaving the gate stuck blocking
	 * new starts. Marking completed here flips is_active=0 immediately
	 * so the user can start a new run without waiting for the 5-minute
	 * stuck-detection threshold.
	 *
	 * Logged at WARNING because frequent reaps for the same type point to
	 * a missing mark_completed() call site that should be fixed at the
	 * source — grep for "Reaping orphan active row" to find them.
	 *
	 * @param string $process_type The orphan row's process_type.
	 * @param string $process_id   The orphan row's process_id.
	 * @return void
	 */
	private function reap_orphan_active_row( $process_type, $process_id ) {
		if ( ! is_string( $process_id ) || '' === $process_id ) {
			return;
		}

		Log::warning(
			"Reaping orphan active row for {$process_type} / {$process_id} — module reports completed but mark_completed() never fired",
			array(
				'feature'      => 'process_status',
				'action'       => 'orphan_row_reaped',
				'process_type' => $process_type,
				'process_id'   => $process_id,
			)
		);

		$this->process_manager->mark_completed( $process_id );
	}
}