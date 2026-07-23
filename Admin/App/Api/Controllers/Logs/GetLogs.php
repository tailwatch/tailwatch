<?php
namespace Tailwatch\Admin\App\Api\Controllers\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Logs\LogActivityController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\Logs\AjaxLogController;
use Tailwatch\Admin\App\Api\Controllers\Email\EmailLogController;

/**
 * Class GetLogs
 *
 * Provides methods to retrieve and filter log entries with feature validation.
 *
 */
class GetLogs {

	/**
	 * Retrieve logs based on provided criteria.
	 *
	 * Fetches log entries with pagination support and feature validation.
	 *
	 * @param string $post_data JSON encoded data containing retrieval criteria.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $data       Response data array or empty on error.
	 *     @type string $message    Response message.
	 *     @type int    $code       HTTP response code.
	 *     @type array  $pagination Pagination info (only on success).
	 * }
	 */
	public function wptw_logs_feature( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON input.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( ! isset( $data['key'] ) || ! isset( $data['option'] ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Key and Option are not provided', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$key    = sanitize_text_field( $data['key'] );
			$option = sanitize_text_field( $data['option'] );
			$type   = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : null;

			// Validate option is in allowed list.
			if ( in_array( $option, $this->wptw_allowed_log_options(), true ) ) {
				$feature_status = $this->wptw_logs_feature_status_by_type( $option, $type );

				if ( ! $feature_status['parent_enable'] || ! $feature_status['feature_enable'] ) {
					$formatted_type = ucwords( str_replace( '_', ' ', str_replace( 'default_', '', $option ) ) );
					return array(
						'data'           => array(),
						'feature_enable' => $feature_status['feature_enable'],
						'parent_enable'  => $feature_status['parent_enable'],
						'message'        => $formatted_type . ' feature is not enabled. Please enable it first.',
						'code'           => 400,
					);
				}
			} else {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid option provided', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : 10;
			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : 1;

			// Ensure minimum values.
			$limit = max( 1, $limit );
			$page  = max( 1, $page );

			// Optional multi-value facet filters for the dynamic filter bar.
			$filters = array();
			foreach ( array( 'type', 'type_state', 'username', 'ip_address', 'action', 'facet_1', 'facet_2' ) as $fcol ) {
				if ( isset( $data[ $fcol ] ) && is_array( $data[ $fcol ] ) ) {
					$filters[ $fcol ] = array_map( 'sanitize_text_field', $data[ $fcol ] );
				}
			}
			if ( null !== $type && empty( $filters['type'] ) ) {
				$filters['type'] = array( $type );
			}
			if ( isset( $data['date_from'] ) ) {
				$filters['date_from'] = sanitize_text_field( $data['date_from'] );
			}
			if ( isset( $data['date_to'] ) ) {
				$filters['date_to'] = sanitize_text_field( $data['date_to'] );
			}

			$db_model = new DBModel();
			$result   = $db_model->get_logs_filtered( $key, $option, $filters, WPTW_DB_LOGS_TABLE_NAME, $limit, $page );

			if ( false === $result ) {
				return array(
					'data'    => array(),
					'message' => __( 'Failed to retrieve logs from database.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			// Handle the result.
			if ( $result['data'] ) {
				$response = array(
					'data'       => $result['data'],
					'pagination' => array(
						'total'       => absint( $result['total'] ),
						'page'        => $page,
						'limit'       => $limit,
						'total_pages' => absint( $result['total_pages'] ),
					),
					'message'    => __( 'Successfully retrieved logs.', 'tailwatch' ),
					'code'       => 200,
				);
			} else {
				// Handle the case where no data is found.
				$response = array(
					'data'    => array(),
					'message' => __( 'No data found for the given key and option.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			return $response;

		} catch ( \Exception $e ) {

			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred during logs retrieval.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Retrieve a single log entry by its row id.
	 *
	 * Powers the "view detail" deep-link a push notification carries in
	 * `record_id`. The lookup is scoped to the notification's `option` so an id
	 * can only resolve within its own log category. The full payload
	 * (title / body / user_data / …) is stored JSON-encoded in the `value`
	 * column and is decoded before it is returned.
	 *
	 * @param string $post_data JSON encoded data containing `id` and `option`.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $data       The log row (with `value` decoded) or empty on error.
	 *     @type bool   $data_found Whether a matching row was found.
	 *     @type string $message    Response message.
	 *     @type int    $code       HTTP response code.
	 * }
	 */
	public function wptw_get_log_by_id( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! is_array( $data ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON input.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$id     = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
			$option = isset( $data['option'] ) ? sanitize_text_field( $data['option'] ) : '';

			if ( $id < 1 || '' === $option ) {
				return array(
					'data'    => array(),
					'message' => __( 'Id and Option are not provided', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( ! in_array( $option, $this->wptw_allowed_log_options(), true ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid option provided', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$db_model = new DBModel();
			$row      = $db_model->get_data_by_id( $id, WPTW_DB_LOGS_TABLE_NAME, $option );

			if ( empty( $row ) ) {
				return array(
					'data'       => array(),
					'data_found' => false,
					'message'    => __( 'No log entry found for the given id.', 'tailwatch' ),
					'code'       => 404,
				);
			}

			// The notification payload is stored JSON-encoded in `value`; decode it
			// so the detail view receives the same shape the list endpoint returns.
			if ( isset( $row['value'] ) && is_string( $row['value'] ) ) {
				$decoded = json_decode( $row['value'], true );
				if ( is_array( $decoded ) ) {
					$row['value'] = $decoded;
				}
			}

			return array(
				'data'       => $row,
				'data_found' => true,
				'message'    => __( 'Successfully retrieved log entry.', 'tailwatch' ),
				'code'       => 200,
			);
		} catch ( \Throwable $e ) {
			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred during log retrieval.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get feature status by log type.
	 *
	 * Determines if a specific log feature is enabled based on option and type.
	 *
	 * @param string      $option Log option type.
	 * @param string|null $type   Optional specific log type.
	 *
	 * @return array {
	 *     Feature status array.
	 *
	 *     @type bool $parent_enable  Whether parent feature is enabled.
	 *     @type bool $feature_enable Whether specific feature is enabled.
	 * }
	 */
	/**
	 * Dynamic filter options — distinct facet values present for a log view, so the
	 * UI only offers filters that exist in the data.
	 *
	 * @param string $post_data JSON with `key` and `option`.
	 * @return array data => map of facet => distinct values.
	 */
	public function wptw_logs_filter_options( $post_data ) {
		try {
			$data = json_decode( isset( $post_data ) ? wp_unslash( $post_data ) : '', true );
			if ( ! is_array( $data ) || ! isset( $data['key'], $data['option'] ) ) {
				return array( 'data' => array(), 'message' => __( 'Key and Option are not provided', 'tailwatch' ), 'code' => 400 );
			}
			$key    = sanitize_text_field( $data['key'] );
			$option = sanitize_text_field( $data['option'] );

			// option => [ column => UI label ]. facet_1 / facet_2 are generic slots whose
			// meaning is defined here per log type, so a NEW module adds one line and needs
			// no schema change. Universal columns (ip_address / username / action) are reused
			// as-is. facet_1 is indexed (high-cardinality facets); facet_2 is the spare slot.
			$facet_map = array(
				'default_logs_activity'       => array( 'type' => 'Action', 'username' => 'User' ),
				'default_monitoring_logs'     => array( 'type' => 'Status Code', 'type_state' => 'URL', 'ip_address' => 'IP Address' ),
				'default_ajax_logs'           => array( 'type' => 'Request Type', 'action' => 'Action', 'facet_2' => 'Method', 'ip_address' => 'IP Address' ),
				'default_email_logs'          => array( 'type' => 'Status', 'facet_1' => 'Sent To' ),
				'wptw_capturing_all_activity' => array( 'type' => 'Request Type', 'action' => 'Action', 'facet_2' => 'Method', 'ip_address' => 'IP Address', 'username' => 'User' ),
			);
			if ( ! isset( $facet_map[ $option ] ) ) {
				return array( 'data' => array(), 'message' => __( 'Invalid option provided', 'tailwatch' ), 'code' => 400 );
			}

			$db_model = new DBModel();
			$distinct = $db_model->get_logs_distinct_facets( $key, $option, array_keys( $facet_map[ $option ] ), WPTW_DB_LOGS_TABLE_NAME );

			$facets = array();
			foreach ( $facet_map[ $option ] as $col => $label ) {
				$facets[ $col ] = array(
					'label'  => $label,
					'values' => isset( $distinct[ $col ] ) ? $distinct[ $col ] : array(),
				);
			}

			return array( 'data' => $facets, 'message' => __( 'Successfully retrieved filter options.', 'tailwatch' ), 'code' => 200 );
		} catch ( \Throwable $e ) {
			return array( 'data' => array(), 'message' => __( 'Failed to retrieve filter options.', 'tailwatch' ), 'code' => 500 );
		}
	}

	public function wptw_logs_feature_status_by_type( $option, $type = null ) {
		switch ( $option ) {
			case 'default_logs_activity':
				$logs_activity = new LogActivityController();
				return $logs_activity->wptw_log_activity_is_enabled();

			case 'default_monitoring_logs':
				$field_id        = ( '404' === $type ) ? 'field_4' : null;
				$monitoring_logs = new MonitoringLogController();
				return $monitoring_logs->wptw_monitoring_is_enabled( $field_id );

			case 'default_ajax_logs':
				$ajax_logs = new AjaxLogController();
				return $ajax_logs->wptw_ajax_log_is_enabled();

			case 'default_email_logs':
				$email_logs = new EmailLogController();
				return $email_logs->wptw_email_log_is_enabled();

			case 'wptw_capturing_all_activity':
				return array(
					'parent_enable'  => true,
					'feature_enable' => true,
				);

			default:
				return array(
					'parent_enable'  => false,
					'feature_enable' => false,
				);
		}
	}

	/**
	 * Log `option` values that may be read through the logs endpoints. Single
	 * source for wptw_logs_feature() and wptw_get_log_by_id() so the two lists
	 * never drift.
	 *
	 * @return string[] Allowed log `option` values.
	 */
	private function wptw_allowed_log_options() {
		return array(
			'default_logs_activity',
			'default_monitoring_logs',
			'default_ajax_logs',
			'default_email_logs',
			'wptw_capturing_all_activity',
		);
	}
}
