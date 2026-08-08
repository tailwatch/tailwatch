<?php
namespace Tailwatch\Admin\App\Api\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProcessMonitorModel {

	public function create_process( $process_data ) {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$process_id = $process_data['process_id'];

		$data = array(
			'user_id'       => $process_data['user_id'] ?? get_current_user_id(),
			'child_of'      => 0,
			'key'           => 'process_monitor',
			'option'        => $process_id,
			'value'         => wp_json_encode( $process_data ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no WP API available.
		$result = $wpdb->insert( $table, $data );

		return $result !== false ? $process_id : false;
	}

	public function get_process( $process_id ) {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; live process lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s LIMIT 1',
				$table,
				'process_monitor',
				$process_id
			),
			ARRAY_A
		);

		if ( $row && ! empty( $row['value'] ) ) {
			return json_decode( $row['value'], true );
		}

		return null;
	}

	public function update_process( $process_id, $process_data, $is_active = null ) {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$update_data = array(
			'value'         => wp_json_encode( $process_data ),
			'date_modified' => current_time( 'mysql' ),
		);

		if ( null !== $is_active ) {
			$update_data['is_active'] = $is_active ? 1 : 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP API available.
		$result = $wpdb->update(
			$table,
			$update_data,
			array(
				'key'    => 'process_monitor',
				'option' => $process_id,
			)
		);

		return $result !== false;
	}

	public function update_heart_beat( $process_id ) {
		$process_data = $this->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		$process_data['last_heartbeat'] = current_time( 'mysql' );

		return $this->update_process( $process_id, $process_data );
	}

	public function get_stuck_processes( $stuck_threshold_seconds = 300 ) {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; stuck-process scan.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND is_active = 1 ORDER BY date_created DESC LIMIT 100',
				$table,
				'process_monitor'
			),
			ARRAY_A
		);
		$stuck_processes = array();

		foreach ( $results as $row ) {
			if ( ! empty( $row['value'] ) ) {
				$process_data = json_decode( $row['value'], true );

				// Only check in_progress or stuck states.
				if ( isset( $process_data['state'] ) &&
					in_array( $process_data['state'], array( 'in_progress', 'stuck', 'pending' ), true ) ) {

					// Check last_heartbeat from JSON data.
					if ( isset( $process_data['last_heartbeat'] ) ) {

						// Heartbeats are written via current_time('mysql') (WP local time);
						// must compare against current_time('timestamp') so the diff is
						// correct on sites where WP timezone != UTC. time() (UTC) here
						// produces a negative diff and stuck detection never fires.
						$last_heartbeat = strtotime( $process_data['last_heartbeat'] );
						$now            = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Local wallclock seconds for scheduling comparisons.

						$time_diff = $now - $last_heartbeat;

							// Use per-process config threshold if registered, fall back to the passed default.
						$threshold = $process_data['config']['stuck_threshold'] ?? $stuck_threshold_seconds;
						if ( $time_diff >= $threshold ) {
							$stuck_processes[] = $process_data;
						}
					}
				}
			}
		}

		return $stuck_processes;
	}

	public function get_all_active_processes() {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; active-process listing.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND is_active = 1 ORDER BY date_created DESC LIMIT 50',
				$table,
				'process_monitor'
			),
			ARRAY_A
		);
		$processes = array();

		foreach ( $results as $row ) {
			if ( ! empty( $row['value'] ) ) {
				$process_data = json_decode( $row['value'], true );

				if ( isset( $process_data['state'] ) &&
					in_array( $process_data['state'], array( 'pending', 'in_progress', 'stuck', 'pause' ), true ) ) {
					$processes[] = $process_data;
				}
			}
		}

		return $processes;
	}

	public function mark_completed( $process_id ) {
		$process_data = $this->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		$process_data['state']          = 'completed';
		$process_data['completed_at']   = current_time( 'mysql' );
		$process_data['last_heartbeat'] = current_time( 'mysql' );
		// Clear the in-flight cron pointer so audit views and recovery never
		// see a completed row claiming an active cron hook.
		$process_data['current_cron']   = '';

		return $this->update_process( $process_id, $process_data, false );
	}

	public function mark_failed( $process_id, $error_message = '' ) {
		$process_data = $this->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		$process_data['state']          = 'failed';
		$process_data['error_message']  = $error_message;
		$process_data['failed_at']      = current_time( 'mysql' );
		$process_data['last_heartbeat'] = current_time( 'mysql' );
		// Clear the in-flight cron pointer so audit views and recovery never
		// see a failed row claiming an active cron hook.
		$process_data['current_cron']   = '';

		return $this->update_process( $process_id, $process_data, false );
	}

	public function increment_retry_count( $process_id ) {
		$process_data = $this->get_process( $process_id );

		if ( ! $process_data ) {
			return false;
		}

		$process_data['retry_count']   = isset( $process_data['retry_count'] ) ? $process_data['retry_count'] + 1 : 1;
		$process_data['last_retry_at'] = current_time( 'mysql' );

		return $this->update_process( $process_id, $process_data );
	}

	public function delete_process( $process_id ) {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP API available.
		$result = $wpdb->delete(
			$table,
			array(
				'key'    => 'process_monitor',
				'option' => $process_id,
			)
		);

		return $result !== false;
	}

	public function cleanup_old_processes( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$cutoff_date = wp_date( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );

		// Reap EVERY monitor row older than the window, not just completed/failed. No process
		// legitimately runs for days (the longest stuck_threshold is 10 min), so a row this old is
		// dead regardless of state. The old completed/failed-only filter leaked stuck/pending/
		// in_progress rows forever (is_active=1), which then blinded the LIMIT-50 active scan and
		// the recovery watchdog. Single DELETE mirrors RecoveryLogModel::cleanup_old_logs().
		//
		// Key off date_modified (last activity), NOT date_created: every heartbeat/state write
		// bumps date_modified, so a row updated within the window is possibly still a live/adopted
		// long-running process and must be kept — even if it was first created long ago. A truly
		// dead row stops being written, so its date_modified ages out and it gets reaped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table cleanup sweep.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE `key` = %s AND date_modified < %s LIMIT 1000',
				$table,
				'process_monitor',
				$cutoff_date
			)
		);

		return $deleted !== false ? (int) $deleted : 0;
	}
}
