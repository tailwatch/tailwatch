<?php
namespace Tailwatch\Admin\App\Api\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RecoveryLogModel {

	public function log_recovery( $process_id, $log_data ) {
		global $wpdb;
		$table = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		$data = array(
			'user_id'       => $log_data['user_id'] ?? get_current_user_id(),
			'child_of'      => 0,
			'key'           => 'process_recovery',
			'option'        => $process_id,
			'value'         => wp_json_encode( $log_data ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom logs table, no WP API available.
		$result = $wpdb->insert( $table, $data );

		return $result !== false ? $wpdb->insert_id : false;
	}

	public function get_process_recovery_logs( $process_id, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; per-process recovery log.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY date_created DESC LIMIT %d',
				$table,
				'process_recovery',
				$process_id,
				$limit
			),
			ARRAY_A
		);
		$logs    = array();

		foreach ( $results as $row ) {
			if ( ! empty( $row['value'] ) ) {
				$log_data              = json_decode( $row['value'], true );
				$log_data['log_id']    = $row['id'];
				$log_data['logged_at'] = $row['date_created'];
				$logs[]                = $log_data;
			}
		}

		return $logs;
	}

	public function get_recent_recovery_logs( $limit = 100 ) {
		global $wpdb;
		$table = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; recent recovery log.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s ORDER BY date_created DESC LIMIT %d',
				$table,
				'process_recovery',
				$limit
			),
			ARRAY_A
		);
		$logs    = array();

		foreach ( $results as $row ) {
			if ( ! empty( $row['value'] ) ) {
				$log_data               = json_decode( $row['value'], true );
				$log_data['log_id']     = $row['id'];
				$log_data['process_id'] = $row['option'];
				$log_data['logged_at']  = $row['date_created'];
				$logs[]                 = $log_data;
			}
		}

		return $logs;
	}

	public function get_failed_recovery_attempts( $process_id ) {
		$all_logs    = $this->get_process_recovery_logs( $process_id );
		$failed_logs = array();

		foreach ( $all_logs as $log ) {
			if ( isset( $log['recovery_success'] ) && $log['recovery_success'] === false ) {
				$failed_logs[] = $log;
			}
		}

		return $failed_logs;
	}

	public function get_recovery_success_rate( $process_type, $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		$cutoff_date = wp_date( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; recovery rate stats.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND date_created >= %s LIMIT 1000',
				$table,
				'process_recovery',
				$cutoff_date
			),
			ARRAY_A
		);

		$total   = 0;
		$success = 0;
		$failed  = 0;

		foreach ( $results as $row ) {
			if ( ! empty( $row['value'] ) ) {
				$log_data = json_decode( $row['value'], true );

				if ( isset( $log_data['process_type'] ) && $log_data['process_type'] === $process_type ) {
					++$total;

					if ( isset( $log_data['recovery_success'] ) && $log_data['recovery_success'] === true ) {
						++$success;
					} else {
						++$failed;
					}
				}
			}
		}

		return array(
			'total_attempts' => $total,
			'successful'     => $success,
			'failed'         => $failed,
			'success_rate'   => $total > 0 ? round( ( $success / $total ) * 100, 2 ) : 0,
		);
	}

	public function cleanup_old_logs( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		$cutoff_date = wp_date( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table cleanup.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE `key` = %s AND date_created < %s LIMIT 1000',
				$table,
				'process_recovery',
				$cutoff_date
			)
		);

		return $result !== false ? $result : 0;
	}

	public function get_recovery_summary() {
		global $wpdb;
		$table = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		// Get logs from last 7 days.
		$seven_days_ago = wp_date( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; recovery summary stats.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND date_created >= %s LIMIT 1000',
				$table,
				'process_recovery',
				$seven_days_ago
			),
			ARRAY_A
		);

		$summary = array(
			'total_recovery_attempts' => 0,
			'successful_recoveries'   => 0,
			'failed_recoveries'       => 0,
			'processes_recovered'     => array(),
			'recovery_by_type'        => array(),
		);

		foreach ( $results as $row ) {
			if ( ! empty( $row['value'] ) ) {
				$log_data = json_decode( $row['value'], true );

				++$summary['total_recovery_attempts'];

				if ( isset( $log_data['recovery_success'] ) ) {
					if ( $log_data['recovery_success'] === true ) {
						++$summary['successful_recoveries'];
					} else {
						++$summary['failed_recoveries'];
					}
				}

				if ( isset( $log_data['process_type'] ) ) {
					$type = $log_data['process_type'];

					if ( ! isset( $summary['recovery_by_type'][ $type ] ) ) {
						$summary['recovery_by_type'][ $type ] = array(
							'total'   => 0,
							'success' => 0,
							'failed'  => 0,
						);
					}

					++$summary['recovery_by_type'][ $type ]['total'];

					if ( isset( $log_data['recovery_success'] ) ) {
						if ( $log_data['recovery_success'] === true ) {
							++$summary['recovery_by_type'][ $type ]['success'];
						} else {
							++$summary['recovery_by_type'][ $type ]['failed'];
						}
					}
				}

				if ( isset( $log_data['process_id'] ) && ! in_array( $log_data['process_id'], $summary['processes_recovered'], true ) ) {
					$summary['processes_recovered'][] = $log_data['process_id'];
				}
			}
		}

		$summary['unique_processes_recovered'] = count( $summary['processes_recovered'] );
		$summary['success_rate']               = $summary['total_recovery_attempts'] > 0
			? round( ( $summary['successful_recoveries'] / $summary['total_recovery_attempts'] ) * 100, 2 )
			: 0;

		return $summary;
	}
}
