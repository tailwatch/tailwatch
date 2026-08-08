<?php
namespace Tailwatch\Admin\App\Api\Controllers\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\BackupModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

class DeleteLogs {



	/**
	 * Delete logs based on provided criteria.
	 *
	 * Can delete all logs for a key/option combination or specific log entries by ID.
	 *
	 * @param string $post_data JSON encoded data containing deletion criteria.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $data    Response data array.
	 *     @type string $message Response message.
	 *     @type int    $code    HTTP response code.
	 * }
	 */
	public function tailwatch_delete_logs( $post_data ) {
		$key           = null;
		$option        = null;
		$is_delete_all = false;
		$log_ids       = null;
		$deleted_count = 0;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			// Validate JSON decode.
			if ( null === $data ) {
				Log::warning(
					'Log deletion failed: Invalid JSON format',
					array(
						'feature'   => 'logs',
						'action'    => 'log_delete_failed',
						'error'     => 'Invalid JSON format',
						'meta_data' => array(
							'feature' => 'Activity Logs',
							'event'   => 'Deletion failed',
							'reason' => 'Invalid request data',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON format.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Validate required parameters.
			if ( ! isset( $data['key'] ) || ! isset( $data['option'] ) ) {
				Log::warning(
					'Log deletion failed: Missing required parameters',
					array(
						'feature'   => 'logs',
						'action'    => 'log_delete_failed',
						'error'     => 'Key and option parameters are required',
						'meta_data' => array(
							'feature' => 'Activity Logs',
							'event'   => 'Deletion failed',
							'reason' => 'Missing required information',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Key and option parameters are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$key      = sanitize_text_field( $data['key'] );
			$option   = sanitize_text_field( $data['option'] );
			$db_model = new DBModel();

			// Delete all logs for key/option combination.
			if ( isset( $data['is_delete'] ) && true === $data['is_delete'] ) {
				$is_delete_all = true;
				$where         = array(
					'option' => $option,
					'key'    => $key,
				);

				$result = $db_model->delete_table_rows( $where, TAILWATCH_DB_LOGS_TABLE_NAME );

				if ( $result ) {
					// Log successful deletion of all logs.
					Log::info(
						"All logs deleted successfully: {$key}/{$option}",
						array(
							'feature'     => 'logs',
							'action'      => 'log_delete_completed',
							'key'         => $key,
							'option'      => $option,
							'delete_type' => 'all',
							'title'       => 'Logs Deleted',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'All logs for the specified key and option have been successfully deleted.', 'tailwatch' ),
						'code'    => 200,
					);
				} else {
					Log::error(
						'Log deletion failed: Database deletion failed or no logs found',
						array(
							'feature'     => 'logs',
							'action'      => 'log_delete_failed',
							'key'         => $key,
							'option'      => $option,
							'delete_type' => 'all',
							'error'       => 'Database deletion returned false or no logs found',
							'meta_data'   => array(
								'feature' => 'Activity Logs',
								'event'   => 'Deletion failed',
								'delete_type' => 'All',
								'reason'      => 'Database error',
							),
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'Failed to delete logs or no logs found for the specified key and option.', 'tailwatch' ),
						'code'    => 500,
					);
				}
			} elseif ( isset( $data['ids'] ) && is_array( $data['ids'] ) && ! empty( $data['ids'] ) ) {
				// Delete specific log entries by ID.
				$ids             = array_map( 'absint', $data['ids'] );
				$log_ids         = $ids;
				$success_count   = 0;
				$failed_ids      = array();
				$failed_messages = array();

				foreach ( $ids as $log_id ) {
					// Skip invalid IDs.
					if ( 0 === $log_id ) {
						$failed_ids[]      = $log_id;
						$failed_messages[] = 'Invalid log ID: ' . $log_id;
						continue;
					}

					$backup_model = new BackupModel();
					// Delete the log entry directly.
					if ( $backup_model->delete_backup_by_id( $log_id, TAILWATCH_DB_LOGS_TABLE_NAME ) ) {
						++$success_count;
					} else {
						$failed_ids[]      = $log_id;
						$failed_messages[] = 'Failed to delete log entry with ID: ' . $log_id;
					}
				}

				$deleted_count = $success_count;
				// Prepare response.
				$total_ids = count( $ids );

				if ( $success_count === $total_ids ) {
					// Log successful deletion of all specified logs.
					Log::info(
						"Log entries deleted successfully: {$success_count} of {$total_ids}",
						array(
							'feature'       => 'logs',
							'action'        => 'log_delete_completed',
							'key'           => $key,
							'option'        => $option,
							'delete_type'   => 'specific',
							'deleted_count' => $success_count,
							'total_ids'     => $total_ids,
							'title'         => 'Logs Deleted',
						)
					);
					return array(
						'data'    => array(
							'success_count' => $success_count,
							'total'         => $total_ids,
						),
						'message' => __( 'All specified log entries were deleted successfully.', 'tailwatch' ),
						'code'    => 200,
					);
				} elseif ( $success_count > 0 ) {
					// Log partial deletion.
					Log::warning(
						"Log deletion partial success: {$success_count} of {$total_ids} deleted",
						array(
							'feature'       => 'logs',
							'action'        => 'log_delete_completed',
							'key'           => $key,
							'option'        => $option,
							'delete_type'   => 'specific',
							'deleted_count' => $success_count,
							'failed_count'  => count( $failed_ids ),
							'failed_ids'    => $failed_ids,
							'total_ids'     => $total_ids,
						)
					);
					return array(
						'data'    => array(
							'success_count'   => $success_count,
							'failed_count'    => count( $failed_ids ),
							'failed_ids'      => $failed_ids,
							'failed_messages' => $failed_messages,
							'total'           => $total_ids,
						),
						'message' => __( 'Deleted ', 'tailwatch' ) . $success_count . ' out of ' . $total_ids . ' log entries.',
						'code'    => 207, // Multi-Status.
					);
				} else {
					Log::error(
						'Log deletion failed: All deletions failed',
						array(
							'feature'         => 'logs',
							'action'          => 'log_delete_failed',
							'key'             => $key,
							'option'          => $option,
							'delete_type'     => 'specific',
							'failed_ids'      => $failed_ids,
							'failed_messages' => $failed_messages,
							'total_ids'       => $total_ids,
							'error'           => 'All log deletion attempts failed',
							'meta_data'       => array(
								'feature' => 'Activity Logs',
								'event'   => 'Deletion failed',
								'delete_type'  => 'Specific',
								'total_count'  => (int) $total_ids,
								'failed_count' => is_array( $failed_ids ) ? count( $failed_ids ) : 0,
								'reason'       => 'Delete failed',
							),
						)
					);
					return array(
						'data'    => array(
							'failed_ids'      => $failed_ids,
							'failed_messages' => $failed_messages,
							'total'           => $total_ids,
						),
						'message' => __( 'Failed to delete any log entries.', 'tailwatch' ),
						'code'    => 500,
					);
				}
			} else {
				Log::warning(
					'Log deletion failed: Invalid deletion parameters',
					array(
						'feature'   => 'logs',
						'action'    => 'log_delete_failed',
						'key'       => $key,
						'option'    => $option,
						'error'     => 'Either provide ids array for specific logs or set is_delete:true to delete all logs',
						'meta_data' => array(
							'feature' => 'Activity Logs',
							'event'   => 'Deletion failed',
							'reason' => 'Invalid request data',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Either provide ids array for specific logs or set is_delete:true to delete all logs.', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Exception $e ) {
			Log::error(
				'Log deletion failed: Exception occurred',
				array(
					'feature'       => 'logs',
					'action'        => 'log_delete_failed',
					'key'           => $key,
					'option'        => $option,
					'delete_type'   => $is_delete_all ? 'all' : 'specific',
					'deleted_count' => $deleted_count,
					'log_ids'       => $log_ids,
					'error'         => $e->getMessage(),
					'exception'     => $e,
					'meta_data'     => array(
						'feature' => 'Activity Logs',
						'event'   => 'Deletion failed',
						'delete_type'   => $is_delete_all ? 'all' : 'specific',
						'deleted_count' => (int) $deleted_count,
						'reason'        => 'Unexpected error',
					),
				)
			);

			return array(
				'data'    => array(),
				'message' => __( 'An unexpected error occurred during logs deletion.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_delete_entries_and_logs( $post_data ) {
		try {
			if ( empty( $post_data ) || ! is_string( $post_data ) ) {
				Log::error(
					'Invalid or empty input data received for entries and logs deletion',
					array(
						'feature'  => 'logs_deletion',
						'action'   => 'redirect_broken_link_deletion_failed',
						'detail'   => 'Invalid or empty input data received for entries and logs deletion',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid or empty input data.', 'tailwatch' ),
				);
			}

			// Decode JSON data
			$data = json_decode( wp_unslash( $post_data ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Invalid JSON format received: ' . json_last_error_msg(),
					array(
						'feature'  => 'logs_deletion',
						'action'   => 'redirect_broken_link_deletion_failed',
						'detail'   => 'Invalid JSON format received: ' . json_last_error_msg(),
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON format: ', 'tailwatch' ) . json_last_error_msg(),
				);
			}

			$is_delete = isset( $data['is_delete'] ) ? filter_var( $data['is_delete'], FILTER_VALIDATE_BOOLEAN ) : false;

			// Validate ids if is_delete is false
			if ( ! $is_delete ) {
				if ( ! isset( $data['ids'] ) || ! is_array( $data['ids'] ) || empty( $data['ids'] ) ) {
					Log::error(
						'Missing or empty required field: ids',
						array(
							'feature'  => 'logs_deletion',
							'action'   => 'redirect_broken_link_deletion_failed',
							'detail'   => 'Missing or empty required field: ids',
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
					return array(
						'code'    => 400,
						'message' => __( 'Missing or empty required field: ids', 'tailwatch' ),
					);
				}
				// Validate each ID
				$ids = array_map( 'absint', $data['ids'] );
				foreach ( $ids as $id ) {
					if ( $id <= 0 ) {
						Log::error(
							'Invalid ID provided: ' . $id,
							array(
								'feature'  => 'logs_deletion',
								'action'   => 'redirect_broken_link_deletion_failed',
								'detail'   => 'Invalid ID provided: ' . $id,
								'origin'   => 'system',
								'severity' => 'medium',
							)
						);
						return array(
							'code'    => 400,
							'message' => __( 'Invalid ID: ', 'tailwatch' ) . $id,
						);
					}
				}
			}

			if ( ! isset( $data['key'] ) || ! in_array( $data['key'], array( 'default_redirection_rules', 'default_redirection_logs', 'default_broken_link_logs' ), true ) ) {
				Log::error(
					'Invalid key provided: ' . ( isset( $data['key'] ) ? $data['key'] : 'null' ),
					array(
						'feature'  => 'logs_deletion',
						'action'   => 'redirect_broken_link_deletion_failed',
						'detail'   => 'Invalid key provided',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid key provided.', 'tailwatch' ),
				);
			}

			// Key-aware artifact gate: only the broken-link logs key has a
			// running consumer (broken_link_checker writes into the logs table
			// for that key while scanning). Redirection rules and redirection
			// logs have no running process touching them, so deletes for those
			// keys proceed unconditionally.
			if ( 'default_broken_link_logs' === $data['key'] ) {
				$blocked = ( new ProcessGuard() )->ensure_can_modify_artifacts(
					array( 'broken_link_checker' )
				);
				if ( null !== $blocked ) {
					return $blocked;
				}
			}

			$success            = true;
			$feature_controller = new DBModel();

			if ( $is_delete ) {

				$where = array(
					'key' => $data['key'],
				);

				// Delete all rules
				if ( in_array( $data['key'], array( 'default_redirection_logs', 'default_broken_link_logs' ) ) ) {
					$result = $feature_controller->delete_table_rows( $where, TAILWATCH_DB_LOGS_TABLE_NAME );
				} else {
					$result = $feature_controller->delete_table_rows( $where );
				}

				if ( $result === false ) {
					$success = false;
				}
			} else {
				// Delete specific rules
				foreach ( $ids as $id ) {
					$where = array(
						'id' => $id,
					);

					if ( in_array( $data['key'], array( 'default_redirection_logs', 'default_broken_link_logs' ) ) ) {
						$result = $feature_controller->delete_table_rows( $where, TAILWATCH_DB_LOGS_TABLE_NAME );
					} else {
						$result = $feature_controller->delete_table_rows( $where );
					}

					if ( $result === false ) {
						$success = false;
					}
				}
			}

			if ( $success ) {
				Log::info(
					'Successfully deleted redirect broken link for key: ' . $data['key'],
					array(
						'feature' => 'logs_deletion',
						'action'  => 'redirect_broken_link_deletion_success',
						'origin'  => 'system',
					)
				);
				return array(
					'code'    => 200,
					'message' => __( 'Redirect broken link deleted successfully.', 'tailwatch' ),
				);
			} else {
				Log::error(
					'Failed to delete one or more redirect broken link for key: ' . $data['key'],
					array(
						'feature'  => 'logs_deletion',
						'action'   => 'redirect_broken_link_deletion_failed',
						'detail'   => 'Failed to delete one or more redirect broken link for key: ' . $data['key'],
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to delete one or more entries.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception occurred during redirect broken link deletion: ' . $e->getMessage(),
				array(
					'feature'  => 'logs_deletion',
					'action'   => 'redirect_broken_link_deletion_failed',
					'detail'   => 'Exception occurred during redirect broken link deletion: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An unexpected error occurred during entries and logs deletion.', 'tailwatch' ),
			);
		}
	}
}
