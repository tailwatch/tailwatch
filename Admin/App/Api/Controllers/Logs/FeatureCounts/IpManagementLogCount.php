<?php

namespace Tailwatch\Admin\App\Api\Controllers\Logs\FeatureCounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Models\IpManagement\RuleModel;
use Tailwatch\Admin\App\Api\Models\IpManagement\WhitelistModel;

class IpManagementLogCount {

	public function tailwatch_ip_management_log_count() {
		try {
			$ip_protection   = new IpManagementController();
			$feature_status  = $ip_protection->tailwatch_ips_managment_is_enabled();
			$rule_model      = new RuleModel();
			$whitelist_model = new WhitelistModel();

			$logs_data = array();

			if ( ! empty( $feature_status['blacklist_feature'] ) ) {
				$logs_data[] = array(
					'feature_name' => 'blacklist',
					'total_count'  => (int) $rule_model->count_blocked_ip_ranges(),
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'blacklist',
					'message'      => __( 'Feature is not enabled', 'tailwatch' ),
				);
			}

			if ( ! empty( $feature_status['country_feature'] ) ) {
				$logs_data[] = array(
					'feature_name' => 'country',
					'total_count'  => (int) $rule_model->count_blocked_countries(),
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'country',
					'message'      => __( 'Feature is not enabled', 'tailwatch' ),
				);
			}

			if ( ! empty( $feature_status['whitelist_feature'] ) ) {
				$total_count = (int) $whitelist_model->count_ip_whitelists() + (int) $whitelist_model->count_country_whitelists();
				$logs_data[] = array(
					'feature_name' => 'whitelist',
					'total_count'  => $total_count,
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'whitelist',
					'message'      => __( 'Feature is not enabled', 'tailwatch' ),
				);
			}

			return array(
				'code'      => 200,
				'message'   => __( 'Data found successfully', 'tailwatch' ),
				'logs_data' => $logs_data,
			);
		} catch ( \Exception $e ) {
			return array(
				'code'      => 500,
				'message'   => __( 'An error occurred: ', 'tailwatch' ) . $e->getMessage(),
				'logs_data' => array(),
			);
		}
	}
}
