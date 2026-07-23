<?php

namespace Tailwatch\Admin\App\Api\Controllers\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Integration\GeoIp2\GeoLiteTwoController;
use Tailwatch\Admin\App\Api\Models\DBModel;

class IntegrationController extends AbstractIntegration {

	public function __construct() {
		$this->feature_controller = new DBModel();
	}

	public function wptw_get_integration_data( $section = null ) {
		try {
			if ( $section != null && ! isset( $this->allowedSchema[ $section ] ) ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => __( 'Unknown integration section', 'tailwatch' ),
				);
			}

			$key    = 'default_integration';
			$option = 'site_integration_settings';

			$retrievedData = $this->feature_controller->get_value( $option, $key );
			$responseData  = array();
			if ( $section != null ) {
				if ( isset( $retrievedData[ $section ] ) ) {
					$responseData = $retrievedData[ $section ];
				} else {
					$schemaSection = $this->allowedSchema[ $section ];
					$defaults      = $schemaSection['defaults'] ?? array();
					$allowedFields = array_merge( $schemaSection['required'] ?? array(), $schemaSection['optional'] ?? array() );
					foreach ( $allowedFields as $field ) {
						$responseData[ $field ] = $defaults[ $field ] ?? $this->get_default_for_type( $schemaSection['types'][ $field ] ?? 'string' );
					}
					$responseData = array_merge( $defaults, $responseData ); // Override with any explicit defaults
				}
			} elseif ( ! empty( $retrievedData ) ) {
					$responseData = $retrievedData;
			} else {
				foreach ( $this->allowedSchema as $secKey => $schemaSection ) {
					$sectionDefaults         = $schemaSection['defaults'] ?? array();
					$allowedFields           = array_merge( $schemaSection['required'] ?? array(), $schemaSection['optional'] ?? array() );
					$responseData[ $secKey ] = $sectionDefaults;
					foreach ( $allowedFields as $field ) {
						if ( ! isset( $responseData[ $secKey ][ $field ] ) ) {
							$responseData[ $secKey ][ $field ] = $this->get_default_for_type( $schemaSection['types'][ $field ] ?? 'string' );
						}
					}
				}
			}

			// Enrich maxmind section with live file status and cron state for UI display
			if ( $section === 'maxmind' ) {
				$db_path                              = GeoLiteTwoController::wptw_geo_lite_db_file_path();
				$db_file_exists                       = GeoLiteTwoController::wptw_is_geo_lite_db_file_exist();
				$next_run                             = wp_next_scheduled( 'wptw_geo_lite_update_cron' );
				$responseData['db_file_exists']       = $db_file_exists;
				$responseData['db_file_last_updated'] = $db_file_exists ? gmdate( 'Y-m-d H:i:s', filemtime( $db_path ) ) : null;
				$responseData['db_file_size']         = $db_file_exists ? filesize( $db_path ) : null;
				$responseData['max_retry_reached']    = intval( $responseData['retry_attempts'] ?? 0 ) >= 3;
				$responseData['is_update_scheduled']  = (bool) $next_run;
				$responseData['next_update_check']    = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;
			} elseif ( $section === null && isset( $responseData['maxmind'] ) ) {
				$db_path                                         = GeoLiteTwoController::wptw_geo_lite_db_file_path();
				$db_file_exists                                  = GeoLiteTwoController::wptw_is_geo_lite_db_file_exist();
				$next_run                                        = wp_next_scheduled( 'wptw_geo_lite_update_cron' );
				$responseData['maxmind']['db_file_exists']       = $db_file_exists;
				$responseData['maxmind']['db_file_last_updated'] = $db_file_exists ? gmdate( 'Y-m-d H:i:s', filemtime( $db_path ) ) : null;
				$responseData['maxmind']['db_file_size']         = $db_file_exists ? filesize( $db_path ) : null;
				$responseData['maxmind']['max_retry_reached']    = intval( $responseData['maxmind']['retry_attempts'] ?? 0 ) >= 3;
				$responseData['maxmind']['is_update_scheduled']  = (bool) $next_run;
				$responseData['maxmind']['next_update_check']    = $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null;
			}

			return array(
				'code'             => 200,
				'integration_data' => $responseData,
				'message'          => ! empty( $retrievedData ) ? __( 'Data retrieved successfully', 'tailwatch' ) : __( 'Data retrieved successfully (using schema defaults)', 'tailwatch' ),
			);
		} catch ( \Throwable $th ) {
			return array(
				'code'             => 500,
				'integration_data' => array(),
				'message'          => __( 'Server error', 'tailwatch' ),
			);
		}
	}

	public function wptw_update_integration_data( $postData ) {
		try {

			$json_data = isset( $postData ) ? stripslashes( $postData ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data['integration_data'] ) ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => __( 'Integration data not found', 'tailwatch' ),
				);
			}

			$options = $data['integration_data'];

			// Partial validate incoming payload (only check allowed keys/types)
			$incomingValidation = $this->wptw_validate_integration_data( $options, true );
			if ( ! $incomingValidation['valid'] ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => wp_json_encode( $incomingValidation['errors'] ),
				);
			}

			$key    = 'default_integration';
			$option = 'site_integration_settings';

			$existingRaw = $this->feature_controller->get_value( $option, $key );
			$existing    = array();
			if ( $existingRaw ) {
				if ( is_string( $existingRaw ) ) {
					$decoded  = json_decode( $existingRaw, true );
					$existing = is_array( $decoded ) ? $decoded : array();
				} elseif ( is_array( $existingRaw ) ) {
					$existing = $existingRaw;
				}
			}

			// Merge with existing and fully validate final configuration
			$mergeResult = $this->wptw_merge_and_validate( $options, $existing );
			if ( ! $mergeResult['valid'] ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => wp_json_encode( $mergeResult['errors'] ),
				);
			}

			$final = $mergeResult['merged'];

			if ( $existingRaw ) {
				$db_data = array( 'value' => wp_json_encode( $final ) );
				$where   = array(
					'key'    => $key,
					'option' => $option,
				);
				$this->feature_controller->update_rows( $db_data, $where );
			} else {
				$db_data        = array(
					'user_id'       => get_current_user_id(),
					'child_of'      => 0,
					'key'           => $key,
					'option'        => $option,
					'value'         => wp_json_encode( $final ),
					'type'          => 'JSON',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => 1,
				);
				$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
				$this->feature_controller->insert_row( $db_data, $db_data_format );
			}

			$this->wptw_execute_integration_function( $options );

			return array(
				'code'             => 200,
				'integration_data' => $final,
				'message'          => __( 'Data retrive successfully', 'tailwatch' ),
			);

		} catch ( \Throwable $th ) {
			return array(
				'code'             => 500,
				'integration_data' => array(),
				'message'          => __( 'Data not found', 'tailwatch' ),
			);
		}
	}

	public function wptw_execute_integration_function( $options ) {
		foreach ( $options as $key => $value ) {

			switch ( $key ) {
				case 'maxmind':
					if ( ! empty( $value['license_key'] ) ) {
						$geo_lite_two = new GeoLiteTwoController();
						$geo_lite_two->wptw_download_geo_lite_two_database( true );
					}
					break;
			}
		}
	}

	public function wptw_delete_integration_data( $postData ) {
		try {
			$json_data = isset( $postData ) ? stripslashes( $postData ) : '';
			$data      = json_decode( $json_data, true );
			$section   = isset( $data['section'] ) ? $data['section'] : null;

			if ( empty( $section ) || ! isset( $this->allowedSchema[ $section ] ) ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => __( 'Unknown integration section', 'tailwatch' ),
				);
			}

			$key    = 'default_integration';
			$option = 'site_integration_settings';

			$existingRaw = $this->feature_controller->get_value( $option, $key );
			$existing    = array();
			if ( $existingRaw ) {
				if ( is_string( $existingRaw ) ) {
					$decoded  = json_decode( $existingRaw, true );
					$existing = is_array( $decoded ) ? $decoded : array();
				} elseif ( is_array( $existingRaw ) ) {
					$existing = $existingRaw;
				}
			}

			if ( isset( $existing[ $section ] ) ) {
				unset( $existing[ $section ] );

				$where = array(
					'key'    => $key,
					'option' => $option,
				);

				if ( empty( $existing ) ) {
					$this->feature_controller->delete_rows( $where );
				} else {
					$this->feature_controller->update_rows( array( 'value' => wp_json_encode( $existing ) ), $where );
				}
			}

			$this->wptw_execute_integration_delete_function( $section );

			return array(
				'code'             => 200,
				'integration_data' => $existing,
				'message'          => __( 'Integration removed successfully', 'tailwatch' ),
			);
		} catch ( \Throwable $th ) {
			return array(
				'code'             => 500,
				'integration_data' => array(),
				'message'          => __( 'Server error', 'tailwatch' ),
			);
		}
	}

	public function wptw_execute_integration_delete_function( $section ) {
		switch ( $section ) {
			case 'maxmind':
				$geo_lite_two = new GeoLiteTwoController();
				$geo_lite_two->wptw_remove_geo_lite_database();
				break;
		}
	}
}
