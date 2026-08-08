<?php
/**
 * IP whitelist controller (IP Management).
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
 * Class IpWhiteListController
 */
class IpWhiteListController extends BaseController {

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

	public function tailwatch_handle_add_ip_whitelist( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'ip_ranges', 'exemption' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP whitelist addition validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_whitelist',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data      = $validation['data'];
			$ip_ranges = array_map( 'sanitize_text_field', (array) $data['ip_ranges'] );
			$exemption = sanitize_text_field( $data['exemption'] );

			if ( ! in_array( $exemption, array( 'login_defender' ), true ) ) {
				Log::error(
					'Invalid exemption type for IP whitelist',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_add_ip_whitelist',
						'title'  => 'IP Management',
						'exemption' => $exemption,
					)
				);
				return array(
					'message' => __( 'Invalid exemption type. Must be login_defender', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( empty( $ip_ranges ) ) {
				Log::error(
					'No IP ranges provided for whitelist',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_whitelist',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'At least one IP range is required', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$invalid_ranges = array_filter(
				$ip_ranges,
				function ( $range ) {
					return ! $this->whitelist_model->is_valid_ip_or_range( $range );
				}
			);
			if ( ! empty( $invalid_ranges ) ) {
				Log::error(
					'Invalid IP ranges for whitelist',
					array(
						'feature'        => 'ip_management',
						'action'         => 'tailwatch_handle_add_ip_whitelist',
						'title'  => 'IP Management',
						'invalid_ranges' => implode( ', ', $invalid_ranges ),
					)
				);
				return array(
					'message' => __( 'Invalid IP ranges: ', 'tailwatch' ) . implode( ', ', $invalid_ranges ),
					'code'    => 400,
				);
			}

			$result = $this->whitelist_model->add_ip_whitelist( $ip_ranges, $exemption, $this->geoip );
			if ( ! $result['success'] && ! empty( $result['failed'] ) && empty( $result['added'] ) ) {
				$message = 'Failed to add some whitelist IP rules: ' . implode(
					', ',
					array_map(
						function ( $range, $reason ) {
							return $range . ' (' . $reason . ')';
						},
						array_keys( $result['failed'] ),
						$result['failed']
					)
				);
				Log::error(
					'IP whitelist addition failed',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_add_ip_whitelist',
						'title'  => 'IP Management',
						'ip_ranges' => implode( ', ', $ip_ranges ),
					)
				);
				return array(
					'message' => $message,
					'data'    => $result,
					'code'    => 400,
				);
			}

			Log::info(
				'IP whitelist created',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_add_ip_whitelist',
					'title'  => 'IP Management',
					'ip_ranges' => implode( ', ', $ip_ranges ),
					'exemption' => $exemption,
				)
			);

			return array(
				'message' => __( 'IP whitelist added successfully', 'tailwatch' ),
				'data'    => $result,
				'code'    => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP whitelist addition exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_add_ip_whitelist',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while adding IP whitelist', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_update_ip_whitelist( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'ip_ranges', 'exemption' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP whitelist update validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_whitelist',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}
			$data      = $validation['data'];
			$ip_ranges = array_map( 'sanitize_text_field', (array) $data['ip_ranges'] );
			$exemption = sanitize_text_field( $data['exemption'] );
			if ( ! in_array( $exemption, array( 'login_defender' ), true ) ) {
				Log::error(
					'Invalid exemption type for IP whitelist update',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_update_ip_whitelist',
						'title'  => 'IP Management',
						'exemption' => $exemption,
					)
				);
				return array(
					'message' => __( 'Invalid exemption type. Must be login_defender', 'tailwatch' ),
					'code'    => 400,
				);
			}
			if ( empty( $ip_ranges ) ) {
				Log::error(
					'No IP ranges provided for whitelist update',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_whitelist',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'At least one IP range is required', 'tailwatch' ),
					'code'    => 400,
				);
			}
			$invalid_ranges = array_filter(
				$ip_ranges,
				function ( $range ) {
					return ! $this->whitelist_model->is_valid_ip_or_range( $range );
				}
			);
			if ( ! empty( $invalid_ranges ) ) {
				Log::error(
					'Invalid IP ranges for whitelist update',
					array(
						'feature'        => 'ip_management',
						'action'         => 'tailwatch_handle_update_ip_whitelist',
						'title'  => 'IP Management',
						'invalid_ranges' => implode( ', ', $invalid_ranges ),
					)
				);
				return array(
					'message' => __( 'Invalid IP ranges: ', 'tailwatch' ) . implode( ', ', $invalid_ranges ),
					'code'    => 400,
				);
			}
			$result = $this->whitelist_model->update_ip_whitelist( $ip_ranges, $exemption, $this->geoip );
			if ( ! $result ) {
				Log::error(
					'IP whitelist update failed',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_update_ip_whitelist',
						'title'  => 'IP Management',
						'ip_ranges' => implode( ', ', $ip_ranges ),
					)
				);
				return array(
					'message' => __( 'Failed to update IP whitelist, some ranges may not exist', 'tailwatch' ),
					'code'    => 400,
				);
			}
			Log::info(
				'IP whitelist updated',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_update_ip_whitelist',
					'title'  => 'IP Management',
					'ip_ranges' => implode( ', ', $ip_ranges ),
					'exemption' => $exemption,
				)
			);
			return array(
				'message' => __( 'IP whitelist updated successfully', 'tailwatch' ),
				'code'    => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP whitelist update exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_update_ip_whitelist',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while updating IP whitelist', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_delete_ip_whitelist( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'is_delete' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP whitelist deletion validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_delete_ip_whitelist',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data      = $validation['data'];
			$is_delete = (bool) $data['is_delete'];
			$ip_ranges = isset( $data['ip_ranges'] ) ? array_map( 'sanitize_text_field', (array) $data['ip_ranges'] ) : array();

			if ( ! $is_delete ) {
				if ( empty( $ip_ranges ) ) {
					Log::error(
						'No IP ranges provided for whitelist deletion',
						array(
							'feature' => 'ip_management',
							'action'  => 'tailwatch_handle_delete_ip_whitelist',
							'title'  => 'IP Management',
						)
					);
					return array(
						'message' => __( 'At least one IP range is required when is_delete is false', 'tailwatch' ),
						'code'    => 400,
					);
				}

				$invalid_ranges = array_filter(
					$ip_ranges,
					function ( $range ) {
						return ! $this->whitelist_model->is_valid_ip_or_range( $range );
					}
				);
				if ( ! empty( $invalid_ranges ) ) {
					Log::error(
						'Invalid IP ranges for whitelist deletion',
						array(
							'feature'        => 'ip_management',
							'action'         => 'tailwatch_handle_delete_ip_whitelist',
							'title'  => 'IP Management',
							'invalid_ranges' => implode( ', ', $invalid_ranges ),
						)
					);
					return array(
						'message' => __( 'Invalid IP ranges: ', 'tailwatch' ) . implode( ', ', $invalid_ranges ),
						'code'    => 400,
					);
				}
			}

			$result = $this->whitelist_model->delete_ip_whitelist( $ip_ranges, $is_delete );
			if ( ! $result ) {
				Log::error(
					'IP whitelist deletion failed',
					array(
						'feature'   => 'ip_management',
						'action'    => 'tailwatch_handle_delete_ip_whitelist',
						'title'  => 'IP Management',
						'is_delete' => $is_delete,
						'ip_ranges' => $is_delete ? 'all' : implode( ', ', $ip_ranges ),
					)
				);
				return array(
					'message' => $is_delete ? 'Failed to delete all IP whitelists' : 'Failed to delete IP whitelists, some ranges may not exist',
					'code'    => 400,
				);
			}

			Log::info(
				'IP whitelist deleted',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_delete_ip_whitelist',
					'title'  => 'IP Management',
					'is_delete' => $is_delete,
					'ip_ranges' => $is_delete ? 'all' : implode( ', ', $ip_ranges ),
				)
			);
			return array(
				'message' => __( 'IP whitelists deleted successfully', 'tailwatch' ),
				'code'    => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP whitelist deletion exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_delete_ip_whitelist',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while deleting IP whitelist', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_get_ip_whitelists( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array() );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP whitelist retrieval validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_get_ip_whitelists',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data  = $validation['data'];
			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : null;
			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : null;

			$whitelists = $this->whitelist_model->get_ip_whitelists( $limit, $page, $this->geoip );

			Log::info(
				'IP whitelist retrieved',
				array(
					'feature' => 'ip_management',
					'action'  => 'tailwatch_handle_get_ip_whitelists',
					'title'  => 'IP Management',
					'limit'   => $limit ?? 'all',
					'page'    => $page ?? 1,
				)
			);
			return array(
				'message'    => __( 'IP whitelists retrieved successfully', 'tailwatch' ),
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
				'IP whitelist retrieval exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_get_ip_whitelists',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while retrieving IP whitelists', 'tailwatch' ),
			);
		}
	}
}
