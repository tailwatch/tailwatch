<?php
namespace Tailwatch\Admin\App\Api\Controllers\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\VerifyingFeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\Logs\LogActivityController;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupMaintainController;
use Tailwatch\Admin\App\Api\Controllers\Email\EmailLogController;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Logging\Log;

class DashboardController {

	/**
	 * Get counts for all log types to display on dashboard.
	 *
	 * Aggregates counts from:
	 * - Activity Logs
	 * - Email Logs
	 * - Network Logs
	 * - Error Monitoring
	 *
	 * @return array Response array with counts and status codes.
	 */
	public function wptw_dashboard_logs_count() {
		try {
			$verifying_controller = new VerifyingFeaturesController();
			$logs_activity        = new LogActivityController();
			$monitoring_logs      = new MonitoringLogController();
			$email_logs           = new EmailLogController();

			$logs_data = array();

			// Activity Logs.
			$activity_status = $logs_activity->wptw_log_activity_is_enabled();
			if ( ! empty( $activity_status['feature_enable'] ) && true === $activity_status['feature_enable'] ) {
				$key         = 'default_feature_logs';
				$option      = 'default_logs_activity';
				$total_count = $verifying_controller->wptw_counts_logs_activity( $option, $key );
				$logs_data[] = array(
					'feature_name' => 'Activity Logs',
					'total_count'  => $total_count,
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'Activity Logs',
					'message'      => __( 'Feature is not enabled', 'tailwatch' ),
				);
			}

			// Email Logs.
			$email_status = $email_logs->wptw_email_log_is_enabled();
			if ( ! empty( $email_status['feature_enable'] ) && true === $email_status['feature_enable'] ) {
				$key         = 'default_feature_logs';
				$option      = 'default_email_logs';
				$total_count = $verifying_controller->wptw_counts_logs_activity( $option, $key );
				$logs_data[] = array(
					'feature_name' => 'Email Logs',
					'total_count'  => $total_count,
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'Email Logs',
					'message'      => __( 'Feature is not enabled', 'tailwatch' ),
				);
			}

			// Error Logs.
			$monitoring_status = $monitoring_logs->wptw_monitoring_is_enabled();
			if ( ! empty( $monitoring_status['feature_enable'] ) && true === $monitoring_status['feature_enable'] ) {
				$key         = 'default_feature_logs';
				$option      = 'default_monitoring_logs';
				$total_count = $verifying_controller->wptw_counts_logs_activity( $option, $key );
				$logs_data[] = array(
					'feature_name' => 'Error Logs',
					'total_count'  => $total_count,
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'Error Logs',
					'message'      => __( 'Feature is not enabled', 'tailwatch' ),
				);
			}

			return array(
				'code'      => 200,
				'message'   => __( 'Data found successfully', 'tailwatch' ),
				'logs_data' => $logs_data,
			);
		} catch ( \Throwable $e ) {
			// Log the error for debugging.
			Log::error(
				'Dashboard logs count failed',
				array(
					'feature'   => 'dashboard',
					'action'    => 'logs_count_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);

			return array(
				'code'      => 500,
				'message'   => __( 'Error retrieving logs count data', 'tailwatch' ),
				'logs_data' => array(),
			);
		}
	}

	public function wptw_dashboard_features() {
		$response_data = array();

		// 2FA dashboard data is supplied by the pro plugin via this filter.
		$response_data = apply_filters( 'wptw_dashboard_features_data', $response_data );

		return array(
			'code'     => 200,
			'message'  => __( 'Features data retrieved successfully', 'tailwatch' ),
			'features' => $response_data,
		);
	}

	public function wptw_scanning_feature_detail() {
		try {
			$response_data = array();

			// Backup Details
			$backup_data = new BackupMaintainController();
			$backup      = $backup_data->get_features_options();

			if ( ! empty( $backup ) ) {
				$backup_details = $backup_data->wptw_get_backup_status();
				if ( ! empty( $backup_details ) && $backup_details['code'] === 200 ) {
					$backupInfo      = $backup_details['data'];
					$response_data[] = array(
						'name'    => 'Backup',
						'details' => array(
							'Backup Type'     => $backupInfo['backup_type'] ?? 'N/A',
							'Backup Interval' => $backupInfo['time_interval'] ?? 'N/A',
							'Backup Maintain' => $backupInfo['backupMaintain'] ?? 'N/A',
							'Next Schedule'   => $backupInfo['next_schedule'] ?? 'N/A',
						),
					);

				} else {
					$response_data[] = array(
						'name'    => 'Backup',
						'details' => array(
							'Message' => 'No detail exist',
						),
					);
				}
			} else {
				$response_data[] = array(
					'name'    => 'Backup',
					'details' => array(
						'Message' => 'Backup Feature is not enabled',
					),
				);
			}

			// Integrity Watch Check
			$integrity_data = new IntegrityWatchController();
			$integrity      = $integrity_data->get_features_options();

			if ( ! empty( $integrity ) ) {
				$integrity_details = $integrity_data->wptw_get_file_integrity_status();
				if ( ! empty( $integrity_details ) && $integrity_details['code'] === 200 && ! empty( $integrity_details['data'] ) ) {
					$integrityInfo   = $integrity_details['data'];
					$response_data[] = array(
						'name'    => 'Integrity Watch',
						'details' => array(
							'Next Schedule' => $integrityInfo['next_schedule'] ?? 'N/A',
							'Next Run'      => $integrityInfo['next_run'] ?? 'N/A',
						),
					);
				} else {
					$response_data[] = array(
						'name'    => 'Integrity Watch',
						'details' => array(
							'Message' => 'No detail exist',
						),
					);
				}
			} else {
				$response_data[] = array(
					'name'    => 'Integrity Watch',
					'details' => array(
						'Message' => 'Integrity Watch Feature is not enabled',
					),
				);
			}

			return array(
				'code'     => 200,
				'message'  => __( 'Scanning Features data retrieved successfully', 'tailwatch' ),
				'features' => $response_data,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Feature scanning failed',
				array(
					'feature'  => 'dashboard',
					'action'   => 'scanning_features_retrieval_failed',
					'error'    => 'Exception occurred while retrieving scanning features: ' . $e->getMessage(),
					'type'     => 'system',
					'severity' => 'high',
				)
			);

			return array(
				'code'     => 500,
				'message'  => __( 'Error retrieving scanning features data', 'tailwatch' ),
				'features' => array(),
			);
		}
	}
}
