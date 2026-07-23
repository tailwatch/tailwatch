<?php
namespace Tailwatch\Admin\App\Api\Services;

use Tailwatch\Admin\App\Api\Models\ProcessMonitorModel;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProcessManager {

	/**
	 * Allowed process states. Used by update_state() to reject typos / invalid
	 * transitions early so the recovery monitor never has to skip rows due to
	 * unrecognized state strings.
	 *
	 * @var array<string>
	 */
	private static $valid_states = array(
		'pending',
		'in_progress',
		'pause',
		'stuck',
		'completed',
		'failed',
	);

	private $model;

	private static $registered_processes = array();

	public function __construct() {
		$this->model = new ProcessMonitorModel();
	}

	public static function register_process( $config ) {
		$process_type = $config['process_type'];

		// Merge with any existing registration for this type rather than
		// replacing it. Several types are registered from two sources — the
		// owning controller (richer config, e.g. locks_features) and
		// ProcessRecoveryController::register_all_processes (the central
		// fallback that ensures every type has stuck_threshold/max_retries
		// even if its controller didn't load). With plain replacement the
		// fallback wipes out the controller's keys whenever it runs second.
		// Merging preserves keys that either side contributed; later keys
		// still win when both sides set the same key.
		if ( isset( self::$registered_processes[ $process_type ] ) && is_array( self::$registered_processes[ $process_type ] ) ) {
			$config = array_merge( self::$registered_processes[ $process_type ], $config );
		}

		self::$registered_processes[ $process_type ] = $config;

		return true;
	}

	public static function get_process_config( $process_type ) {
		return self::$registered_processes[ $process_type ] ?? null;
	}

	/**
	 * Get the full registry of process configurations.
	 *
	 * Used by ProcessGuard (and any other consumer that needs to inspect
	 * declarative bindings like `locks_features` across all registered
	 * processes) to do registry-driven lookups without hardcoded maps.
	 *
	 * @return array<string,array> Map of process_type => config array.
	 */
	public static function get_all_registered_processes() {
		return self::$registered_processes;
	}

	/**
	 * Find an active process of the given type, or create a new one.
	 *
	 * Wrapped in a short-lived transient lock keyed by process_type so two
	 * parallel cron / AJAX requests cannot both pass the "no existing process"
	 * check and both INSERT a duplicate row. The lock TTL (10s) is generous
	 * enough to cover the SELECT + INSERT round trip on slow hosts but short
	 * enough that a crashed PHP process doesn't permanently block the type.
	 *
	 * If the transient lock cannot be acquired (already held by another
	 * request), we fall through to the original behavior — the worst case is
	 * the rare original race condition, which is no worse than before this
	 * fix. The fast path (no contention) is unaffected.
	 *
	 * @param string $process_type Process type (e.g. 'backup').
	 * @param string $cron_hook    Hook name that initiated the process (used by recovery).
	 * @param array  $metadata     Optional metadata to attach to the process row.
	 * @return string Process ID (existing or newly created).
	 */
	public function get_or_create_process( $process_type, $cron_hook, $metadata = array() ) {
		$lock_key      = 'wptw_pm_lock_' . sanitize_key( $process_type );
		$lock_acquired = false;

		// Best-effort concurrency lock: only one process of a given type can be
		// created at a time. Existing processes are still resolved without waiting.
		if ( false === get_transient( $lock_key ) ) {
			set_transient( $lock_key, time(), 10 );
			$lock_acquired = true;
		}

		try {
			$active_processes = $this->model->get_all_active_processes();

			foreach ( $active_processes as $process ) {
				if ( $process['process_type'] === $process_type && in_array( $process['state'], array( 'pending', 'in_progress', 'pause' ), true ) ) {
					return $process['process_id'];
				}
			}

			// No adoptable (pending/in_progress/pause) row exists, so we are starting fresh.
			// Supersede any leftover 'stuck' row of the same type from a previously crashed run —
			// stuck rows keep is_active=1 and are never reaped by cleanup (which only deletes
			// completed/failed), so without this the Active Tasks list shows two of the same
			// process and the dead row lingers indefinitely.
			foreach ( $active_processes as $process ) {
				if ( isset( $process['process_type'], $process['state'], $process['process_id'] )
					&& $process['process_type'] === $process_type
					&& 'stuck' === $process['state'] ) {
					$this->model->mark_failed( $process['process_id'], 'Superseded by a new ' . $process_type . ' run' );
				}
			}

			$user_id    = get_current_user_id();
			$process_id = $process_type . '_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );

			$process_data = array(
				'process_id'     => $process_id,
				'process_type'   => $process_type,
				'user_id'        => $user_id,
				'state'          => 'pending',
				'current_cron'   => $cron_hook,
				'last_heartbeat' => current_time( 'mysql' ),
				'retry_count'    => 0,
				'started_at'     => current_time( 'mysql' ),
				'metadata'       => $metadata,
			);

			$config = self::get_process_config( $process_type );
			if ( $config ) {
				$process_data['config'] = array(
					'data_source'     => $config['data_source'] ?? 'wp_tw_settings',
					'data_key'        => $config['data_key'] ?? null,
					'data_option'     => $config['data_option'] ?? null,
					'stuck_threshold' => $config['stuck_threshold'] ?? 300,
					'max_retries'     => $config['max_retries'] ?? 3,
				);
			}

			$this->model->create_process( $process_data );

			Log::info(
				"Created process: {$process_id} (type: {$process_type})",
				array(
					'feature' => 'process_monitor',
					'action'  => 'process_created',
				)
			);

			return $process_id;
		} finally {
			if ( $lock_acquired ) {
				delete_transient( $lock_key );
			}
		}
	}

	public function heart_beat( $process_id ) {
		return $this->model->update_heart_beat( $process_id );
	}

	public function update_state( $process_id, $state ) {
		// Reject invalid state strings up front so typos cannot put a process
		// row into a state the recovery monitor or queries cannot recognize.
		if ( ! in_array( $state, self::$valid_states, true ) ) {
			Log::warning(
				"Refused to update process {$process_id} to invalid state '{$state}'",
				array(
					'feature'       => 'process_monitor',
					'action'        => 'invalid_state_rejected',
					'process_id'    => $process_id,
					'invalid_state' => $state,
					'valid_states'  => self::$valid_states,
				)
			);
			return false;
		}

		$process_data = $this->model->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		$process_data['state']          = $state;
		$process_data['last_heartbeat'] = current_time( 'mysql' );

		$success = $this->model->update_process( $process_id, $process_data );


		return $success;
	}

	public function update_metadata( $process_id, $metadata ) {
		$process_data = $this->model->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		if ( ! isset( $process_data['metadata'] ) ) {
			$process_data['metadata'] = array();
		}

		$process_data['metadata']       = array_merge( $process_data['metadata'], $metadata );
		$process_data['last_heartbeat'] = current_time( 'mysql' );

		return $this->model->update_process( $process_id, $process_data );
	}

	public function update_process( $process_id, $process_data ) {
		if ( ! $process_id || empty( $process_data ) ) {
			return false;
		}

		// Always update heartbeat on any process update.
		$process_data['last_heartbeat'] = current_time( 'mysql' );

		return $this->model->update_process( $process_id, $process_data );
	}

	public function get_process( $process_id ) {
		return $this->model->get_process( $process_id );
	}

	public function mark_completed( $process_id ) {
		$success = $this->model->mark_completed( $process_id );

		if ( $success ) {
			Log::info(
				"Process {$process_id} completed successfully",
				array(
					'feature' => 'process_monitor',
					'action'  => 'process_completed',
				)
			);
		}

		return $success;
	}

	public function mark_failed( $process_id, $error_message = '' ) {
		$success = $this->model->mark_failed( $process_id, $error_message );

		if ( $success ) {
			Log::error(
				"Process {$process_id} failed: {$error_message}",
				array(
					'feature' => 'process_monitor',
					'action'  => 'process_failed',
				)
			);
		}

		return $success;
	}

	public function increment_retry_count( $process_id ) {
		return $this->model->increment_retry_count( $process_id );
	}

	public function can_retry( $process_id ) {
		$process_data = $this->model->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		$retry_count = $process_data['retry_count'] ?? 0;
		$max_retries = $process_data['config']['max_retries'] ?? 3;

		return $retry_count < $max_retries;
	}

	public function get_stuck_processes( $threshold_seconds = 300 ) {
		return $this->model->get_stuck_processes( $threshold_seconds );
	}

	public function get_all_active_processes() {
		return $this->model->get_all_active_processes();
	}

	public function cleanup( $days = 7 ) {
		return $this->model->cleanup_old_processes( $days );
	}

	public function is_process_stuck( $process_id ) {
		$process_data = $this->model->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		if ( ! in_array( $process_data['state'], array( 'in_progress', 'stuck', 'pending' ), true ) ) {
			return false;
		}

		$last_heartbeat  = strtotime( $process_data['last_heartbeat'] );
		// Use current_time('timestamp') so comparison uses the same timezone as
		// current_time('mysql') which stores the heartbeat. Using time() (UTC)
		// against a local-time heartbeat produces a negative diff on UTC+ servers.
		$now             = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local wallclock seconds for scheduling comparisons.
		$stuck_threshold = $process_data['config']['stuck_threshold'] ?? 300;

		return ( $now - $last_heartbeat ) >= $stuck_threshold;
	}
}
