<?php

namespace Tailwatch\Admin\App\Api\Controllers\Logs\FeatureCounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Models\LoginDefender\IpActivityModel;
use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;

class LoginDefenderLogCount {

	public function tailwatch_login_defender_log_count() {
		try {
			$options   = ( new OptionsController() )->get_features_options( 'default_feature_settings', 'default_login_defender_management', true );
			$logs_data = array();

			// IP protection (field_13) — count of distinct IPs tracked in ip_activity.
			if ( ! empty( $options['field_13']['options']['option']['selected'] ) ) {
				$ip_model    = new IpActivityModel( new GeoIPService() );
				$total_count = $ip_model->get_ip_activity_count();
				$logs_data[] = array(
					'feature_name' => 'ip_protection',
					'total_count'  => $total_count,
				);
			} else {
				$logs_data[] = array(
					'feature_name' => 'ip_protection',
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
