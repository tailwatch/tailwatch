<?php
namespace Tailwatch\Admin\App\Api\Services;

defined( 'ABSPATH' ) || exit;

class RecoveryMonitor {

	/**
	 * @var ProcessManager
	 */
	private $processManager;

	/**
	 * @var RecoveryService
	 */
	private $recoveryService;

	/**
	 * @var string Transient key for throttling.
	 */
	private $throttle_key = 'wptw_recovery_monitor_last_run';

	/**
	 * @var int Minimum seconds between checks (default: 120 = 2 minutes).
	 */
	private $throttle_interval = 120;

	public function __construct() {
		$this->processManager  = new ProcessManager();
		$this->recoveryService = new RecoveryService();
	}

	/**
	 * Emit a recovery-system trace line to the PHP error log only when WP_DEBUG_LOG
	 * is enabled. Use this for the verbose health-check / decision tracing — major
	 * events go through the unified Log:: system instead and are queryable from
	 * the admin UI.
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

	public function should_run() {
		$last_run = get_transient( $this->throttle_key );

		if ( $last_run === false ) {
			// Never run before.
			return true;
		}

		$time_since_last_run = time() - (int) $last_run;

		return $time_since_last_run >= $this->throttle_interval;
	}

	private function mark_as_run() {
		set_transient( $this->throttle_key, time(), $this->throttle_interval + 60 );
	}

	public function check_health() {
		$active_processes = $this->processManager->get_all_active_processes();
		$stuck_processes  = array();

		// Use current_time('timestamp') — WordPress local time as Unix timestamp — so
		// it matches heartbeats stored via current_time('mysql') (also local time).
		// Using plain time() (UTC) against a local-time string causes a negative
		// time_diff on servers where the WordPress timezone is ahead of UTC.
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local wallclock seconds for scheduling comparisons.

		foreach ( $active_processes as $process ) {
			$config    = $process['config'] ?? array();
			$threshold = $config['stuck_threshold'] ?? 300; // Use per-process config, default 300s.

			if ( isset( $process['last_heartbeat'] ) ) {
				$last_heartbeat = strtotime( $process['last_heartbeat'] );
				$time_diff      = $now - $last_heartbeat;

				if ( $time_diff >= $threshold &&
					isset( $process['state'] ) &&
					in_array( $process['state'], array( 'in_progress', 'stuck', 'pending' ), true ) ) {
					$stuck_processes[] = $process;
				}
			}
		}

		$this->mark_as_run();

		return $stuck_processes;
	}

	public function check_and_recover() {
		// Respect throttle interval to avoid running on every page load.
		if ( ! $this->should_run() ) {
			return array(
				'success'     => true,
				'message'     => __( 'Throttled — too soon since last check', 'tailwatch' ),
				'stuck_count' => 0,
				'recovered'   => array(),
			);
		}

		$stuck_processes = $this->check_health();

		if ( empty( $stuck_processes ) ) {
			return array(
				'success'     => true,
				'message'     => __( 'No stuck processes found', 'tailwatch' ),
				'stuck_count' => 0,
				'recovered'   => array(),
			);
		}

		$recovery_results = array();

		foreach ( $stuck_processes as $process ) {
			$process_id   = $process['process_id'];
			$process_type = $process['process_type'];
			$stuck_secs   = isset( $process['last_heartbeat'] ) ? ( current_time( 'timestamp' ) - strtotime( $process['last_heartbeat'] ) ) : 'unknown';

			$this->debug_trace( sprintf(
				'[WPTW Recovery] Stuck process detected — ID: %s | Type: %s | State: %s | Stuck for: %ss | Retries: %d | Last heartbeat: %s',
				$process_id,
				$process_type,
				$process['state'] ?? 'unknown',
				$stuck_secs,
				$process['retry_count'] ?? 0,
				$process['last_heartbeat'] ?? 'unknown'
			) );

			// Attempt recovery.
			$result = $this->recoveryService->attempt_recovery( $process_id );

			$this->debug_trace( sprintf(
				'[WPTW Recovery] Recovery result for %s (%s) — success: %s | action: %s | message: %s',
				$process_id,
				$process_type,
				( $result['success'] ?? false ) ? 'YES' : 'NO',
				$result['action'] ?? 'n/a',
				$result['message'] ?? 'n/a'
			) );

			$recovery_results[] = array(
				'process_id'   => $process_id,
				'process_type' => $process_type,
				'result'       => $result,
			);
		}

		return array(
			'success'     => true,
			'message'     => count( $stuck_processes ) . ' stuck process(es) found',
			'stuck_count' => count( $stuck_processes ),
			'recovered'   => $recovery_results,
		);
	}

	public function get_status() {
		$last_run = get_transient( $this->throttle_key );

		$status = array(
			'last_check_time'          => $last_run ? gmdate( 'Y-m-d H:i:s', $last_run ) : 'Never',
			'seconds_since_last_check' => $last_run ? ( time() - (int) $last_run ) : null,
			'next_check_in_seconds'    => null,
			'monitoring_enabled'       => true,
			'throttle_interval'        => $this->throttle_interval,
			'active_processes'         => array(),
			'stuck_processes'          => array(),
		);

		if ( $last_run ) {
			$time_since_last_run             = time() - (int) $last_run;
			$next_check                      = $this->throttle_interval - $time_since_last_run;
			$status['next_check_in_seconds'] = max( 0, $next_check );
		}

		// Get all active processes (regardless of user).
		$status['active_processes'] = $this->processManager->get_all_active_processes();

		// Get stuck processes (without throttling for dashboard).
		$status['stuck_processes'] = $this->processManager->get_stuck_processes( 300 );

		return $status;
	}

	public function force_check() {
		$this->debug_trace( '[WPTW Recovery] force_check triggered — bypassing throttle and running immediately' );
		// Delete throttle transient to allow immediate run.
		delete_transient( $this->throttle_key );

		return $this->check_and_recover();
	}

	public function set_throttle_interval( $seconds ) {
		$this->throttle_interval = max( 60, $seconds ); // Minimum 1 minute.
	}

	public function get_throttle_interval() {
		return $this->throttle_interval;
	}
}
