<?php
/**
 * IP blacklist / rules controller (IP Management).
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Controllers\IpManagement\BlackList;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\IpManagement\RuleModel;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;
use Tailwatch\Admin\App\Api\Controllers\Routes\FieldsValidationController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IpBlackListController
 */
class IpBlackListController extends BaseController {

	protected $tailwatch_feature_check_exemptions = array();

	protected function tailwatch_get_feature_status() {
		$controller = new IpManagementController();
		$status     = $controller->tailwatch_ips_managment_is_enabled();
		return array(
			'feature_enable' => $status['blacklist_feature'],
			'parent_enable'  => $status['parent_enable'],
		);
	}

	private $rule_model;
	private $geoip;

	public function __construct() {
		$this->geoip = new GeoIPService();

		$this->rule_model = new RuleModel();
	}


	public function tailwatch_handle_add_ip_rule( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'ip_ranges', 'block_type', 'block_duration', 'scope', 'reason' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP rule creation validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data           = $validation['data'];
			$ip_ranges      = array_map( 'sanitize_text_field', (array) $data['ip_ranges'] );
			$block_type     = sanitize_text_field( $data['block_type'] );
			$block_duration = absint( $data['block_duration'] ?? 3600 );
			$scope          = sanitize_text_field( $data['scope'] ?? 'login_forms' );
			$reason         = sanitize_text_field( $data['reason'] );

			if ( empty( $ip_ranges ) ) {
				Log::error(
					'No IP ranges provided for rule creation',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'At least one IP range is required', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( ! in_array( $block_type, array( 'temporary', 'permanent', 'unblocked' ) ) ) {
				Log::error(
					'Invalid block type for IP rule',
					array(
						'feature'    => 'ip_management',
						'action'     => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
						'block_type' => $block_type,
					)
				);
				return array(
					'message' => __( 'Invalid block_type, must be temporary, permanent, or unblocked', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( ! in_array( $scope, array( 'login_forms', 'entire_site' ) ) ) {
				Log::error(
					'Invalid scope for IP rule',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
						'scope'   => $scope,
					)
				);
				return array(
					'message' => __( 'Invalid scope, must be login_forms or entire_site', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( $block_duration < 0 || ( $block_type === 'temporary' && $block_duration > 31536000 ) ) {
				Log::error(
					'Invalid block duration for IP rule',
					array(
						'feature'        => 'ip_management',
						'action'         => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
						'block_duration' => $block_duration,
					)
				);
				return array(
					'message' => __( 'Invalid block_duration, must be non-negative and less than 1 year for temporary blocks', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( strlen( $reason ) > 255 ) {
				Log::error(
					'Reason too long for IP rule',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'Reason exceeds 255 characters', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$invalid_ranges = array_filter(
				$ip_ranges,
				function ( $range ) {
					return ! $this->rule_model->is_valid_ip_or_range( $range );
				}
			);
			if ( ! empty( $invalid_ranges ) ) {
				Log::error(
					'Invalid IP ranges for rule',
					array(
						'feature'        => 'ip_management',
						'action'         => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
						'invalid_ranges' => implode( ', ', $invalid_ranges ),
					)
				);
				return array(
					// translators: %s is a comma-separated list of invalid IP ranges.
					'message' => sprintf( __( 'Invalid IP ranges: %s', 'tailwatch' ), implode( ', ', $invalid_ranges ) ),
					'code'    => 400,
				);
			}

			// Get optional block_page parameter (post_id or custom path)
			$block_page = isset( $data['block_page'] ) ? $data['block_page'] : null;

			$result = $this->rule_model->add_ip_rule( $ip_ranges, $block_type, $block_duration, $scope, $reason, $this->geoip, $block_page );
			if ( ! $result['success'] && ! empty( $result['failed'] ) && empty( $result['added'] ) ) {
				$message = 'Failed to add some IP rules: ' . implode(
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
					'IP rule addition failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_add_ip_rule',
						'title'  => 'IP Management',
						'detail'  => $message,
					)
				);
				return array(
					'message' => $message,
					'code'    => 400,
					'data'    => $result,
				);
			}

			Log::info(
				'IP rule created',
				array(
					'feature'    => 'ip_management',
					'action'     => 'tailwatch_handle_add_ip_rule',
					'title'  => 'IP Management',
					'added'      => $result['added'],
					'block_type' => $block_type,
					'scope'      => $scope,
				)
			);

			return array(
				'message' => __( 'IP rules added successfully', 'tailwatch' ),
				'code'    => 200,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP rule addition exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_add_ip_rule',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while adding IP rules', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_update_ip_rule( $postData ) {
		try {
			// First validate with minimal required fields
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'ip_ranges', 'block_type', 'reason' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP rule update validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data       = $validation['data'];
			$block_type = sanitize_text_field( $data['block_type'] );

			if ( ! in_array( $block_type, array( 'temporary', 'permanent', 'unblocked' ) ) ) {
				Log::error(
					'Invalid block type for IP rule update',
					array(
						'feature'    => 'ip_management',
						'action'     => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
						'block_type' => $block_type,
					)
				);
				return array(
					'message' => __( 'Invalid block_type, must be temporary, permanent, or unblocked', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( $block_type !== 'unblocked' ) {
				$additional_validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'block_duration', 'scope' ) );
				if ( ! $additional_validation['success'] ) {
					Log::error(
						'IP rule update validation failed (block_duration, scope)',
						array(
							'feature' => 'ip_management',
							'action'  => 'tailwatch_handle_update_ip_rule',
							'title'  => 'IP Management',
							'detail'  => $additional_validation['message'],
						)
					);
					return $additional_validation;
				}

				$block_duration = absint( $additional_validation['data']['block_duration'] ?? 3600 );
				$scope          = sanitize_text_field( $additional_validation['data']['scope'] ?? 'login_forms' );
			} else {
				// For unblocked type, set default values
				$block_duration = 0;
				$scope          = 'login_forms';
			}

			$ip_ranges = array_map( 'sanitize_text_field', (array) $data['ip_ranges'] );
			$reason    = sanitize_text_field( $data['reason'] );

			if ( empty( $ip_ranges ) ) {
				Log::error(
					'No IP ranges provided for rule update',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'At least one IP range is required', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( $block_type !== 'unblocked' && ! in_array( $scope, array( 'login_forms', 'entire_site' ) ) ) {
				Log::error(
					'Invalid scope for IP rule update',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
						'scope'   => $scope,
					)
				);
				return array(
					'message' => __( 'Invalid scope, must be login_forms or entire_site', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( $block_type !== 'unblocked' && ( $block_duration < 0 || ( $block_type === 'temporary' && $block_duration > 31536000 ) ) ) {
				Log::error(
					'Invalid block duration for IP rule update',
					array(
						'feature'        => 'ip_management',
						'action'         => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
						'block_duration' => $block_duration,
					)
				);
				return array(
					'message' => __( 'Invalid block_duration, must be non-negative and less than 1 year for temporary blocks', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( strlen( $reason ) > 255 ) {
				Log::error(
					'Reason too long for IP rule update',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
					)
				);
				return array(
					'message' => __( 'Reason exceeds 255 characters', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$invalid_ranges = array_filter(
				$ip_ranges,
				function ( $range ) {
					return ! $this->rule_model->is_valid_ip_or_range( $range );
				}
			);
			if ( ! empty( $invalid_ranges ) ) {
				Log::error(
					'Invalid IP ranges for rule update',
					array(
						'feature'        => 'ip_management',
						'action'         => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
						'invalid_ranges' => implode( ', ', $invalid_ranges ),
					)
				);
				return array(
					// translators: %s is a comma-separated list of invalid IP ranges.
					'message' => sprintf( __( 'Invalid IP ranges: %s', 'tailwatch' ), implode( ', ', $invalid_ranges ) ),
					'code'    => 400,
				);
			}

			// Get optional block_page parameter (post_id or custom path)
			$block_page = isset( $data['block_page'] ) ? $data['block_page'] : null;

			$result = $this->rule_model->update_ip_rule( $ip_ranges, $block_type, $block_duration, $scope, $reason, $this->geoip, $block_page );
			if ( ! $result['success'] && ! empty( $result['failed'] ) ) {
				$message = 'Failed to update some IP rules: ' . implode(
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
					'IP rule update failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_update_ip_rule',
						'title'  => 'IP Management',
						'detail'  => $message,
					)
				);
				return array(
					'message' => $message,
					'code'    => 400,
					'data'    => $result,
				);
			}

			Log::info(
				'IP rule updated',
				array(
					'feature'    => 'ip_management',
					'action'     => 'tailwatch_handle_update_ip_rule',
					'title'  => 'IP Management',
					'updated'    => $result['updated'],
					'block_type' => $block_type,
					'scope'      => $scope,
				)
			);
			return array(
				'message' => __( 'IP rules updated successfully', 'tailwatch' ),
				'code'    => 200,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP rule update exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_update_ip_rule',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while updating IP rules', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_delete_ip_rule( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'is_delete' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP rule deletion validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_delete_ip_rule',
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
						'No IP ranges provided for rule deletion',
						array(
							'feature' => 'ip_management',
							'action'  => 'tailwatch_handle_delete_ip_rule',
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
						return ! $this->rule_model->is_valid_ip_or_range( $range );
					}
				);
				if ( ! empty( $invalid_ranges ) ) {
					Log::error(
						'Invalid IP ranges for rule deletion',
						array(
							'feature'        => 'ip_management',
							'action'         => 'tailwatch_handle_delete_ip_rule',
							'title'  => 'IP Management',
							'invalid_ranges' => implode( ', ', $invalid_ranges ),
						)
					);
					return array(
						// translators: %s is a comma-separated list of invalid IP ranges.
						'message' => sprintf( __( 'Invalid IP ranges: %s', 'tailwatch' ), implode( ', ', $invalid_ranges ) ),
						'code'    => 400,
					);
				}
			}

			$result = $this->rule_model->delete_ip_rule( $ip_ranges, $is_delete );
			if ( ! $result['success'] && ! empty( $result['failed'] ) ) {
				$message = $is_delete ? 'Failed to delete all IP rules' : 'Failed to delete some IP rules: ' . implode(
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
					'IP rule deletion failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_delete_ip_rule',
						'title'  => 'IP Management',
						'detail'  => $message,
					)
				);
				return array(
					'message' => $message,
					'code'    => 400,
					'data'    => $result,
				);
			}

			Log::info(
				'IP rules deleted',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_delete_ip_rule',
					'title'  => 'IP Management',
					'deleted'   => $result['deleted'],
					'is_delete' => $is_delete,
				)
			);
			return array(
				'message' => __( 'IP rules deleted successfully', 'tailwatch' ),
				'code'    => 200,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP rule deletion exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_delete_ip_rule',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while deleting IP rules', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_unblock_ip_range( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array( 'ip_range' ) );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP unblock validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_unblock_ip_range',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data     = $validation['data'];
			$ip_range = sanitize_text_field( $data['ip_range'] );

			if ( ! $this->rule_model->is_valid_ip_or_range( $ip_range ) ) {
				Log::error(
					'Invalid IP address or range for unblock',
					array(
						'feature'  => 'ip_management',
						'action'   => 'tailwatch_handle_unblock_ip_range',
						'title'  => 'IP Management',
						'ip_range' => $ip_range,
					)
				);
				return array(
					'message' => __( 'Invalid IP address or range', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$result = $this->rule_model->unblock_ip_range( $ip_range, $this->geoip );
			if ( ! $result['success'] && ! empty( $result['failed'] ) ) {
				$message = 'Failed to unblock IP range: ' . implode(
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
					'IP unblock failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_unblock_ip_range',
						'title'  => 'IP Management',
						'detail'  => $message,
					)
				);
				return array(
					'message' => $message,
					'code'    => 400,
					'data'    => $result,
				);
			}

			Log::info(
				'IP unblocked',
				array(
					'feature'  => 'ip_management',
					'action'   => 'tailwatch_handle_unblock_ip_range',
					'title'  => 'IP Management',
					'ip_range' => $ip_range,
				)
			);
			return array(
				'message' => __( 'IP range unblocked successfully', 'tailwatch' ),
				'code'    => 200,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP unblock exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_unblock_ip_range',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while unblocking IP range', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_handle_get_blocked_ip_ranges( $postData ) {
		try {
			$validation = FieldsValidationController::tailwatch_validate_json_and_fields( $postData, array() );
			if ( ! $validation['success'] ) {
				Log::error(
					'IP blocked ranges retrieval validation failed',
					array(
						'feature' => 'ip_management',
						'action'  => 'tailwatch_handle_get_blocked_ip_ranges',
						'title'  => 'IP Management',
						'detail'  => $validation['message'],
					)
				);
				return $validation;
			}

			$data  = $validation['data'];
			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : null;
			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : null;

			$blocked = $this->rule_model->get_blocked_ip_ranges( $limit, $page, $this->geoip );
			$data    = isset( $blocked['data'] ) ? $blocked['data'] : $blocked;

			return array(
				'success'    => true,
				'message'    => __( 'Blocked IP ranges retrieved successfully', 'tailwatch' ),
				'code'       => 200,
				'data'       => $data,
				'pagination' => $limit !== null && $page !== null ? array(
					'total'       => $blocked['total'],
					'page'        => $blocked['page'],
					'limit'       => $blocked['limit'],
					'total_pages' => $blocked['total_pages'],
				) : null,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP blocked ranges retrieval exception',
				array(
					'feature'   => 'ip_management',
					'action'    => 'tailwatch_handle_get_blocked_ip_ranges',
					'title'  => 'IP Management',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while retrieving blocked IP ranges', 'tailwatch' ),
			);
		}
	}
}
