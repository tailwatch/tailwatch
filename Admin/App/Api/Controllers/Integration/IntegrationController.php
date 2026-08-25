<?php
/**
 * Integration Controller
 *
 * Stores and manages third-party integration settings (the MaxMind GeoLite2
 * integration). Saving a MaxMind license key triggers a single, user-initiated
 * database download; there is no background/scheduled download. Reached only via the
 * authenticated dispatcher (wp-admin nonce + manage_options, or Connect JWT).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Integration
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

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

	/**
	 * Get integration data, enriched with the live database-file status.
	 *
	 * @param string|null $section Optional section (e.g. 'maxmind').
	 * @return array
	 */
	public function tailwatch_get_integration_data( $section = null ) {
		try {
			if ( null !== $section && ! isset( $this->allowed_schema[ $section ] ) ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => __( 'Unknown integration section', 'tailwatch' ),
				);
			}

			$key    = 'default_integration';
			$option = 'site_integration_settings';

			$retrieved_data = $this->feature_controller->get_value( $option, $key );
			$response_data  = array();
			if ( null !== $section ) {
				if ( isset( $retrieved_data[ $section ] ) ) {
					$response_data = $retrieved_data[ $section ];
				} else {
					$schema_section = $this->allowed_schema[ $section ];
					$defaults       = $schema_section['defaults'] ?? array();
					$allowed_fields = array_merge( $schema_section['required'] ?? array(), $schema_section['optional'] ?? array() );
					foreach ( $allowed_fields as $field ) {
						$response_data[ $field ] = $defaults[ $field ] ?? $this->get_default_for_type( $schema_section['types'][ $field ] ?? 'string' );
					}
					$response_data = array_merge( $defaults, $response_data );
				}
			} elseif ( ! empty( $retrieved_data ) ) {
				$response_data = $retrieved_data;
			} else {
				foreach ( $this->allowed_schema as $sec_key => $schema_section ) {
					$section_defaults          = $schema_section['defaults'] ?? array();
					$allowed_fields            = array_merge( $schema_section['required'] ?? array(), $schema_section['optional'] ?? array() );
					$response_data[ $sec_key ] = $section_defaults;
					foreach ( $allowed_fields as $field ) {
						if ( ! isset( $response_data[ $sec_key ][ $field ] ) ) {
							$response_data[ $sec_key ][ $field ] = $this->get_default_for_type( $schema_section['types'][ $field ] ?? 'string' );
						}
					}
				}
			}

			// Enrich the maxmind section with live database-file status (no cron state:
			// updates are user-initiated, so there is nothing scheduled to report).
			if ( 'maxmind' === $section ) {
				$response_data = $this->tailwatch_enrich_maxmind_status( $response_data );
			} elseif ( null === $section && isset( $response_data['maxmind'] ) ) {
				$response_data['maxmind'] = $this->tailwatch_enrich_maxmind_status( $response_data['maxmind'] );
			}

			return array(
				'code'             => 200,
				'integration_data' => $response_data,
				'message'          => ! empty( $retrieved_data ) ? __( 'Data retrieved successfully', 'tailwatch' ) : __( 'Data retrieved successfully (using schema defaults)', 'tailwatch' ),
			);
		} catch ( \Throwable $th ) {
			return array(
				'code'             => 500,
				'integration_data' => array(),
				'message'          => __( 'Server error', 'tailwatch' ),
			);
		}
	}

	/**
	 * Add live database-file status fields to a maxmind data array.
	 *
	 * @param array $maxmind Maxmind section data.
	 * @return array
	 */
	private function tailwatch_enrich_maxmind_status( $maxmind ) {
		$db_path                        = GeoLiteTwoController::tailwatch_geo_lite_db_file_path();
		$db_file_exists                 = GeoLiteTwoController::tailwatch_is_geo_lite_db_file_exist();
		$maxmind['db_file_exists']       = $db_file_exists;
		$maxmind['db_file_last_updated'] = $db_file_exists ? gmdate( 'Y-m-d H:i:s', filemtime( $db_path ) ) : null;
		$maxmind['db_file_size']         = $db_file_exists ? filesize( $db_path ) : null;
		return $maxmind;
	}

	/**
	 * Save integration data. When a MaxMind license key is provided, this triggers a
	 * single user-initiated download of the GeoLite2 database.
	 *
	 * @param string $post_data JSON payload with integration_data.
	 * @return array
	 */
	public function tailwatch_update_integration_data( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data['integration_data'] ) ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => __( 'Integration data not found', 'tailwatch' ),
				);
			}

			$options = $data['integration_data'];

			$incoming_validation = $this->tailwatch_validate_integration_data( $options, true );
			if ( ! $incoming_validation['valid'] ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => wp_json_encode( $incoming_validation['errors'] ),
				);
			}

			$key    = 'default_integration';
			$option = 'site_integration_settings';

			$existing_raw = $this->feature_controller->get_value( $option, $key );
			$existing     = array();
			if ( $existing_raw ) {
				if ( is_string( $existing_raw ) ) {
					$decoded  = json_decode( $existing_raw, true );
					$existing = is_array( $decoded ) ? $decoded : array();
				} elseif ( is_array( $existing_raw ) ) {
					$existing = $existing_raw;
				}
			}

			$merge_result = $this->tailwatch_merge_and_validate( $options, $existing );
			if ( ! $merge_result['valid'] ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => wp_json_encode( $merge_result['errors'] ),
				);
			}

			$final = $merge_result['merged'];

			if ( $existing_raw ) {
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

			$this->tailwatch_execute_integration_function( $options );

			return array(
				'code'             => 200,
				'integration_data' => $final,
				'message'          => __( 'Data saved successfully', 'tailwatch' ),
			);
		} catch ( \Throwable $th ) {
			return array(
				'code'             => 500,
				'integration_data' => array(),
				'message'          => __( 'Data not found', 'tailwatch' ),
			);
		}
	}

	/**
	 * Run the per-section side effect after a save (user-initiated database download).
	 *
	 * @param array $options Saved sections.
	 * @return void
	 */
	public function tailwatch_execute_integration_function( $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'maxmind':
					if ( ! empty( $value['license_key'] ) ) {
						$geo_lite_two = new GeoLiteTwoController();
						$geo_lite_two->tailwatch_download_geo_lite_two_database();
					}
					break;
			}
		}
	}

	/**
	 * Delete an integration section and its side effects (remove the database).
	 *
	 * @param string $post_data JSON payload with section.
	 * @return array
	 */
	public function tailwatch_delete_integration_data( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );
			$section   = isset( $data['section'] ) ? sanitize_text_field( $data['section'] ) : null;

			if ( empty( $section ) || ! isset( $this->allowed_schema[ $section ] ) ) {
				return array(
					'code'             => 400,
					'integration_data' => array(),
					'message'          => __( 'Unknown integration section', 'tailwatch' ),
				);
			}

			$key    = 'default_integration';
			$option = 'site_integration_settings';

			$existing_raw = $this->feature_controller->get_value( $option, $key );
			$existing     = array();
			if ( $existing_raw ) {
				if ( is_string( $existing_raw ) ) {
					$decoded  = json_decode( $existing_raw, true );
					$existing = is_array( $decoded ) ? $decoded : array();
				} elseif ( is_array( $existing_raw ) ) {
					$existing = $existing_raw;
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

			$this->tailwatch_execute_integration_delete_function( $section );

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

	/**
	 * Run the per-section side effect after a delete (remove the database file).
	 *
	 * @param string $section Section key.
	 * @return void
	 */
	public function tailwatch_execute_integration_delete_function( $section ) {
		switch ( $section ) {
			case 'maxmind':
				$geo_lite_two = new GeoLiteTwoController();
				$geo_lite_two->tailwatch_remove_geo_lite_database();
				break;
		}
	}
}
