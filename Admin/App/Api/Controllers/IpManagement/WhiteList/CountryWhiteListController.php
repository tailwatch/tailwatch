<?php
/**
 * Country whitelist controller (IP Management).
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Controllers\IpManagement\WhiteList;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\IpManagement\WhitelistModel;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;
use Tailwatch\Admin\App\Api\Controllers\Routes\FieldsValidationController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CountryWhiteListController
 */
class CountryWhiteListController extends BaseController {

	protected $tailwatch_feature_check_exemptions = array();

	protected function tailwatch_get_feature_status() {
		$controller = new IpManagementController();
		$status     = $controller->tailwatch_ips_managment_is_enabled();
		return array(
			'feature_enable' => $status['whitelist_feature'],
			'parent_enable'  => $status['parent_enable'],
		);
	}

	private $whitelist_model;
	private $geoip;

	public function __construct() {
		$this->geoip = new GeoIPService();

		$this->whitelist_model = new WhitelistModel();
	}

	public function tailwatch_handle_add_country_whitelist( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'country_codes', 'exemption' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'Country whitelist addition validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_country_whitelist',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}
			$data          = $validation['data'];
			$country_codes = array_map( 'sanitize_text_field', (array) $data['country_codes'] );
			$exemption     = sanitize_text_field( $data['exemption'] );
			if ( ! in_array( $exemption, array( 'login_defender' ), true ) ) {
				Log::error(
					'Invalid exemption type for country whitelist',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_add_country_whitelist',
						'title'  => 'IP Management',
						'exemption' => $exemption,
					)
				);
				return array(
					'message' => __( 'Invalid exemption type. Must be login_defender', 'tailwatch' ),
					'code'    => 400,
				);
			}
			if ( empty( $country_codes ) ) {
				Log::error(
					'No country codes provided for whitelist',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_country_whitelist',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'At least one country code is required', 'tailwatch' ),
					'code'    => 400,
				);
			}
			$invalid_codes = array_filter(
				$country_codes,
				function ( $code ) {
					return ! preg_match( '/^[A-Z]{2}$/', $code );
				}
			);
			if ( ! empty( $invalid_codes ) ) {
				Log::error(
					'Invalid country codes for whitelist',
					array(
						'feature'       => 'ip_management',
						'action'        => 'tailwatch_handle_add_country_whitelist',
						'title'  => 'IP Management',
						'invalid_codes' => implode( ', ', $invalid_codes ),
					)
				);
				return array(
					'message' => __( 'Invalid country codes: ', 'tailwatch' ) . implode( ', ', $invalid_codes ),
					'code'    => 400,
				);
			}
			$result = $this->whitelist_model->add_country_whitelist( $country_codes, $exemption );
			if ( ! $result['success'] && ! empty( $result['failed'] ) && empty( $result['added'] ) ) {
				$message = 'Failed to add some whiltelist country codes: ' . implode(
					', ',
					array_map(
						function ( $code, $reason ) {
							return "$code ($reason)";
						},
						array_keys( $result['failed'] ),
						$result['failed']
					)
				);
				Log::error(
					'Country whitelist addition failed',
					array(
						'feature'       => 'ip_management',
						'action'        => 'tailwatch_handle_add_country_whitelist',
						'title'  => 'IP Management',
						'country_codes' => implode( ', ', $country_codes ),
					)
				);
				return array(
					'message' => $message,
					'data'    => $result,
					'code'    => 400,
				);
			}
			Log::info(
				'Country whitelist created',
				array(
					'feature'       => 'ip_management',
					'action'        => 'tailwatch_handle_add_country_whitelist',
					'title'  => 'IP Management',
					'country_codes' => implode( ', ', $country_codes ),
					'exemption'     => $exemption,
				)
			);
			return array(
				'message' => __( 'Country whitelist added successfully', 'tailwatch' ),
				'data'    => $result,
				'code'    => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Country whitelist addition exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_add_country_whitelist',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while adding country whitelist', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_update_country_whitelist( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'country_codes', 'exemption' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'Country whitelist update validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_country_whitelist',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data          = $validation['data'];
			$country_codes = array_map( 'sanitize_text_field', (array) $data['country_codes'] );
			$exemption     = sanitize_text_field( $data['exemption'] );

			if ( ! in_array( $exemption, array( 'login_defender' ), true ) ) {
				Log::error(
					'Invalid exemption type for country whitelist update',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_update_country_whitelist',
						'title'  => 'IP Management',
						'exemption' => $exemption,
					)
				);
				return array(
					'message' => __( 'Invalid exemption type. Must be login_defender', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( empty( $country_codes ) ) {
				Log::error(
					'No country codes provided for whitelist update',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_country_whitelist',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'At least one country code is required', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$invalid_codes = array_filter(
				$country_codes,
				function ( $code ) {
					return ! preg_match( '/^[A-Z]{2}$/', $code );
				}
			);
			if ( ! empty( $invalid_codes ) ) {
				Log::error(
					'Invalid country codes for whitelist update',
					array(
						'feature'       => 'ip_management',
						'action'        => 'tailwatch_handle_update_country_whitelist',
						'title'  => 'IP Management',
						'invalid_codes' => implode( ', ', $invalid_codes ),
					)
				);
				return array(
					'message' => __( 'Invalid country codes: ', 'tailwatch' ) . implode( ', ', $invalid_codes ),
					'code'    => 400,
				);
			}

			$result = $this->whitelist_model->update_country_whitelist( $country_codes, $exemption );
			if ( ! $result ) {
				Log::error(
					'Country whitelist update failed',
					array(
						'feature'       => 'ip_management',
						'action'        => 'tailwatch_handle_update_country_whitelist',
						'title'  => 'IP Management',
						'country_codes' => implode( ', ', $country_codes ),
					)
				);
				return array(
					'message' => __( 'Failed to update country whitelist, some codes may not exist', 'tailwatch' ),
					'code'    => 400,
				);
			}

			Log::info(
				'Country whitelist updated',
				array(
					'feature'       => 'ip_management',
					'action'        => 'tailwatch_handle_update_country_whitelist',
					'title'  => 'IP Management',
					'country_codes' => implode( ', ', $country_codes ),
					'exemption'     => $exemption,
				)
			);

			return array(
				'message' => __( 'Country whitelist updated successfully', 'tailwatch' ),
				'code'    => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Country whitelist update exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_update_country_whitelist',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while updating country whitelist', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_delete_country_whitelist( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'is_delete' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'Country whitelist deletion validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_delete_country_whitelist',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data          = $validation['data'];
			$is_delete     = (bool) $data['is_delete'];
			$country_codes = isset( $data['country_codes'] ) ? array_map( 'sanitize_text_field', (array) $data['country_codes'] ) : array();

			if ( ! $is_delete ) {
				if ( empty( $country_codes ) ) {
					Log::error(
						'No country codes provided for whitelist deletion',
						array(
							'feature' => 'ip_management',
							'action'  => 'tailwatch_handle_delete_country_whitelist',
							'title'  => 'IP Management',
						)
					);
					return array(
						'message' => __( 'At least one country code is required when is_delete is false', 'tailwatch' ),
						'code'    => 400,
					);
				}

				$invalid_codes = array_filter(
					$country_codes,
					function ( $code ) {
						return ! preg_match( '/^[A-Z]{2}$/', $code );
					}
				);
				if ( ! empty( $invalid_codes ) ) {
					Log::error(
						'Invalid country codes for whitelist deletion',
						array(
							'feature'       => 'ip_management',
							'action'        => 'tailwatch_handle_delete_country_whitelist',
							'title'  => 'IP Management',
							'invalid_codes' => implode( ', ', $invalid_codes ),
						)
					);
					return array(
						'message' => __( 'Invalid country codes: ', 'tailwatch' ) . implode( ', ', $invalid_codes ),
						'code'    => 400,
					);
				}
			}

			$result = $this->whitelist_model->delete_country_whitelist( $country_codes, $is_delete );
			if ( ! $result ) {
				Log::error(
					'Country whitelist deletion failed',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_delete_country_whitelist',
						'title'  => 'IP Management',
						'is_delete' => $is_delete,
					)
				);
				return array(
					'message' => $is_delete ? 'Failed to delete all country whitelists' : 'Failed to delete country whitelists, some codes may not exist',
					'code'    => 400,
				);
			}

			Log::info(
				'Country whitelist deleted',
				array(
					'feature'       => 'ip_management',
					'action'        => 'tailwatch_handle_delete_country_whitelist',
					'title'  => 'IP Management',
					'is_delete'     => $is_delete,
					'country_codes' => $is_delete ? 'all' : implode( ', ', $country_codes ),
				)
			);
			return array(
				'message' => __( 'Country whitelists deleted successfully', 'tailwatch' ),
				'code'    => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Country whitelist deletion exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_delete_country_whitelist',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while deleting country whitelist', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_get_country_whitelists( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array() );
			if ( ! $validation['success'] ) {
				Log::error(
					'Country whitelist retrieval validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_get_country_whitelists',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data  = $validation['data'];
			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : null;
			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : null;

			$whitelists = $this->whitelist_model->get_country_whitelists( $limit, $page );

			Log::info(
				'Country whitelist retrieved',
				array(
					'feature' => 'ip_management',
					'action'  => 'tailwatch_handle_get_country_whitelists',
					'title'  => 'IP Management',
					'limit'   => $limit ?? 'all',
					'page'    => $page ?? 1,
				)
			);
			return array(
				'message'    => __( 'Country whitelists retrieved successfully', 'tailwatch' ),
				'data'       => $whitelists['data'],
				'pagination' => array(
					'total'       => $whitelists['total'],
					'page'        => $whitelists['page'],
					'limit'       => $whitelists['limit'],
					'total_pages' => $whitelists['total_pages'],
				),
				'code'       => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Country whitelist retrieval exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_get_country_whitelists',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while retrieving country whitelists', 'tailwatch' ),
			);
		}
	}
}
