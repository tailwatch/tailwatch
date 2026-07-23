<?php
/**
 * Recommended Features Controller
 *
 * Handles the automatic implementation of recommended security features.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Visit
 */

namespace Tailwatch\Admin\App\Api\Controllers\Visit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\FeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetByFeatureOptionController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\ProcessManager;

/**
 * Recommended Features Controller
 *
 * Handles the automatic implementation of recommended security features.
 *
 */
class RecommendedFeaturesController {

	/**
	 * @var ProcessManager
	 */
	private $processManager;

	/**
	 * List of recommended features to implement
	 *
	 * @var array
	 */
	public static $recommended_features = array(
		'default_log_activity',
		'default_monitoring_logs',
		'default_config_generate_key',
		'default_verify_ssl',
	);

	/**
	 * Constructor - Register hooks
	 */
	public function __construct() {
		$this->processManager = new ProcessManager();
		$this->register_process_monitoring();
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_activate_recommended_features', array( $this, 'wptw_implement_and_verify_features' ) );
	}

	/**
	 * Register this controller's process type with the ProcessManager.
	 *
	 */
	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'    => 'recommended_features',
				'cron_hooks'      => array( 'wptw_activate_recommended_features' ),
				'stuck_threshold' => 300,
				'max_retries'     => 3,
			)
		);
	}

	/**
	 * Get feature implementation status
	 *
	 * @return array Feature implementation status data.
	 */
	public function wptw_get_feature_implement_status() {
		return $this->get_feature_data( 'start_features_implement' );
	}

	/**
	 * Update feature implementation status
	 *
	 * @param array $options Status data to update.
	 */
	public function wptw_update_feature_implement_status( $options ) {
		$this->update_feature_data( 'start_features_implement', $options );
	}

	/**
	 * Get feature data from database
	 *
	 * @param string $option   Option name.
	 * @param string $wptw_key Key identifier.
	 * @return array Feature data.
	 */
	private function get_feature_data( $option, $wptw_key = 'verify_visit_options' ) {
		$db_model = new DBModel();
		return $db_model->get_recent_data( $option, $wptw_key );
	}

	/**
	 * Update feature data in database
	 *
	 * @param string $option   Option name.
	 * @param array  $data     Data to update.
	 * @param string $wptw_key Key identifier.
	 */
	private function update_feature_data( $option, $data, $wptw_key = 'verify_visit_options' ) {
		$db_model = new DBModel();
		$db_data  = array( 'value' => wp_json_encode( $data ) );
		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	/**
	 * Start features implementation process
	 *
	 * @param string $post_data JSON encoded post data.
	 * @return array Response with status code and message.
	 */
	public function wptw_start_features_implementation( $post_data ) {
		$setup_configuration = null;

		try {
			// Verify user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				Log::warning(
					'Recommended features implementation failed: Insufficient permissions',
					array(
						'feature'   => 'recommended_features',
						'action'    => 'recommended_feature_implementation_failed',
						'error'     => 'User does not have permission to perform this action',
						'meta_data' => array(
							'feature' => 'Recommended Features',
							'event'   => 'Failed',
							'reason' => 'Permission denied',
						),
					)
				);
				return array(
					'code'    => 403,
					'message' => __( 'You do not have permission to perform this action.', 'tailwatch' ),
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( empty( $data ) || ! is_array( $data ) ) {
				Log::warning(
					'Recommended features implementation failed: Invalid data provided',
					array(
						'feature'   => 'recommended_features',
						'action'    => 'recommended_feature_implementation_failed',
						'error'     => 'Invalid data provided - empty or not an array',
						'meta_data' => array(
							'feature' => 'Recommended Features',
							'event'   => 'Failed',
							'reason' => 'Invalid request data',
						),
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid data provided.', 'tailwatch' ),
				);
			}

			$valid_configs = array( 'custom_settings', 'default_settings' );
			if ( ! isset( $data['setup_configuration'] ) || ! in_array( $data['setup_configuration'], $valid_configs, true ) ) {
				Log::warning(
					'Recommended features implementation failed: Invalid setup configuration',
					array(
						'feature'             => 'recommended_features',
						'action'              => 'recommended_feature_implementation_failed',
						'setup_configuration' => $data['setup_configuration'] ?? 'not provided',
						'error'               => 'Invalid setup configuration provided',
						'meta_data'           => array(
							'feature' => 'Recommended Features',
							'event'   => 'Failed',
							'reason' => 'Invalid setup configuration',
						),
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid setup configuration provided.', 'tailwatch' ),
				);
			}

			$setup_configuration = sanitize_text_field( $data['setup_configuration'] );

			$db_model = new DBModel();

			$data_is = array(
				'cron_running'             => false,
				'recommended_completed'    => false,
				'feature_verify_started'   => false,
				'feature_verify_completed' => false,
				'is_completed'             => false,
				'setup_configuration'      => sanitize_text_field( $data['setup_configuration'] ),
				'update_default_settings'  => false,
				'progress'                 => 0,
			);

			$db_data = array(
				'user_id'       => get_current_user_id(),
				'child_of'      => 0,
				'key'           => 'verify_visit_options',
				'option'        => 'start_features_implement',
				'value'         => wp_json_encode( $data_is ),
				'type'          => 'JSON',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			);

			$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

			$db_model->insert_row( $db_data, $db_data_format );

			$get_data = json_decode( get_option( WPTW_VISIT_DATA ), true );
			if ( isset( $get_data['recommended_features'] ) && is_array( $get_data['recommended_features'] ) ) {
				$get_data['recommended_features']['is_completed'] = true;
			}

			update_option( WPTW_VISIT_DATA, wp_json_encode( $get_data ) );

			$scheduled = wp_schedule_single_event( time() + 10, 'wptw_activate_recommended_features' );

			if ( false === $scheduled ) {
				Log::error(
					'Recommended features implementation failed: Failed to schedule cron event',
					array(
						'feature'             => 'recommended_features',
						'action'              => 'recommended_feature_implementation_failed',
						'setup_configuration' => $setup_configuration,
						'error'               => 'Failed to schedule process cron event',
						'meta_data'           => array(
							'feature' => 'Recommended Features',
							'event'   => 'Failed',
							'reason' => 'Cron schedule failed',
						),
					)
				);
				return array(
					'data'    => array(),
					'code'    => 500,
					'message' => __( 'Failed to schedule process. Please try again.', 'tailwatch' ),
				);
			}

			// Log successful start.
			Log::info(
				'Recommended features implementation started',
				array(
					'feature'             => 'recommended_features',
					'action'              => 'recommended_feature_implementation_started',
					'setup_configuration' => $setup_configuration,
					'title'               => 'Features Implementation Started',
				)
			);

			return array(
				'data'    => array(),
				'code'    => 200,
				'message' => __( 'Process started successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Recommended features implementation failed: Exception occurred',
				array(
					'feature'             => 'recommended_features',
					'action'              => 'recommended_feature_implementation_failed',
					'setup_configuration' => $setup_configuration,
					'error'               => $e->getMessage(),
					'exception'           => $e,
					'meta_data'           => array(
						'feature' => 'Recommended Features',
						'event'   => 'Failed',
						'reason' => 'Unexpected error',
					),
				)
			);

			return array(
				'data'    => array(),
				'code'    => 500,
				'message' => __( 'An error occurred while starting the process.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get recommended features process status
	 *
	 * @return array Process status information.
	 */
	public function wptw_recommended_features_process() {
		$process_status = $this->wptw_get_feature_implement_status();

		return array(
			'cron_running'         => isset( $process_status['cron_running'] ) ? $process_status['cron_running'] : false,
			'is_completed'         => isset( $process_status['is_completed'] ) ? $process_status['is_completed'] : false,
			'progress'             => isset( $process_status['progress'] ) ? $process_status['progress'] : 0,
			'function_started'     => isset( $process_status['function_started'] ) ? $process_status['function_started'] : false,
			'function_completed'   => isset( $process_status['function_completed'] ) ? $process_status['function_completed'] : false,
			'completion_timestamp' => isset( $process_status['completion_timestamp'] ) ? $process_status['completion_timestamp'] : null,
			'current_timestamp'    => time(),
			'code'                 => 200,
			'message'              => __( 'Data retrieved successfully.', 'tailwatch' ),
		);
	}

	/**
	 * Implement and verify recommended features
	 */
	public function wptw_implement_and_verify_features() {
		$process_id    = $this->processManager->get_or_create_process( 'recommended_features', 'wptw_activate_recommended_features', array() );
		$this->processManager->update_state( $process_id, 'in_progress' );

		$progress_data = $this->wptw_get_feature_implement_status();

		if ( false === $progress_data['cron_running'] ) {
			$progress_data['cron_running'] = true;
			$this->wptw_update_feature_implement_status( $progress_data );
		}

		$progress_data['function_started']   = true;
		$progress_data['function_completed'] = false;
		$this->wptw_update_feature_implement_status( $progress_data );

		if ( 'default_settings' === $progress_data['setup_configuration'] ) {
			if ( false === $progress_data['update_default_settings'] ) {
				$this->wptw_implement_recommended_features();
				$this->wptw_recommended_feature_complete();
				return;
			}
		} elseif ( 'custom_settings' === $progress_data['setup_configuration'] ) {
			if ( true !== $progress_data['recommended_completed'] ) {
				$progress_data['recommended_completed'] = true;
				$this->wptw_update_feature_implement_status( $progress_data );
			}
		}

		if ( true === $progress_data['recommended_completed'] ) {
			$progress_data['is_completed'] = true;
			$this->wptw_update_feature_implement_status( $progress_data );

			$visit_data = json_decode( get_option( WPTW_VISIT_DATA ), true );
			if ( isset( $visit_data['features_implemented'] ) && is_array( $visit_data['features_implemented'] ) ) {
				$visit_data['features_implemented']['is_completed'] = true;
			}
			update_option( WPTW_VISIT_DATA, wp_json_encode( $visit_data ) );

			$this->processManager->mark_completed( $process_id );
		} else {
			$this->processManager->heart_beat( $process_id );
			wp_schedule_single_event( time() + 5, 'wptw_activate_recommended_features' );
		}

		$this->wptw_recommended_feature_complete();
	}

	/**
	 * Mark recommended feature implementation as complete
	 */
	public function wptw_recommended_feature_complete() {
		$progress_data                         = $this->wptw_get_feature_implement_status();
		$progress_data['completion_timestamp'] = time();
		$progress_data['function_completed']   = true;
		$progress_data['function_started']     = false;
		$this->wptw_update_feature_implement_status( $progress_data );
	}

	/**
	 * Retry feature implementation cron if it failed
	 *
	 * @return array Response with status and message.
	 */
	public function wptw_run_feature_implement_cron_if_failed() {
		try {
			// Verify user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				Log::warning(
					'Feature implementation cron retry failed: Insufficient permissions',
					array(
						'feature'   => 'recommended_features',
						'action'    => 'recommended_feature_implementation_failed',
						'error'     => 'User does not have permission to perform this action',
						'meta_data' => array(
							'feature' => 'Recommended Features',
							'event'   => 'Failed',
							'reason' => 'Permission denied',
						),
					)
				);
				return array(
					'code'    => 403,
					'message' => __( 'You do not have permission to perform this action.', 'tailwatch' ),
				);
			}

			$progress_data = $this->wptw_get_feature_implement_status();

			if ( false === $progress_data['cron_running'] ) {
				if ( ! wp_next_scheduled( 'wptw_activate_recommended_features' ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, 'wptw_activate_recommended_features' );

					if ( $cron_scheduled ) {
						Log::info(
							'Feature implementation cron retry scheduled successfully',
							array(
								'feature' => 'recommended_features',
								'action'  => 'recommended_feature_implementation_started',
								'title'   => 'Cron Retry Scheduled',
							)
						);
						return array(
							'data'    => array(),
							'message' => __( 'Attempting to run the cron again.', 'tailwatch' ),
							'code'    => 200,
						);
					} else {
						Log::error(
							'Feature implementation cron retry failed: Could not schedule cron event',
							array(
								'feature'   => 'recommended_features',
								'action'    => 'recommended_feature_implementation_failed',
								'error'     => 'Cron job could not be scheduled',
								'meta_data' => array(
									'feature' => 'Recommended Features',
									'event'   => 'Failed',
									'reason' => 'Cron schedule failed',
								),
							)
						);
						return array(
							'code'    => 500,
							'data'    => array(),
							'message' => __( 'Error: Cron job could not be scheduled. Please try again.', 'tailwatch' ),
						);
					}
				} else {
					// Cron already scheduled - informational only
					return array(
						'data'    => array(),
						'message' => __( 'Cron job is already scheduled.', 'tailwatch' ),
						'code'    => 200,
					);
				}
			} else {
				// Cron already running - informational only
				return array(
					'data'    => array(),
					'message' => __( 'Cron job is already running.', 'tailwatch' ),
					'code'    => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Feature implementation cron retry failed: Exception occurred',
				array(
					'feature'   => 'recommended_features',
					'action'    => 'recommended_feature_implementation_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
					'meta_data' => array(
						'feature' => 'Recommended Features',
						'event'   => 'Failed',
						'reason' => 'Unexpected error',
					),
				)
			);

			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'An error occurred while retrying the cron.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Implement recommended features in batches
	 */
	public function wptw_implement_recommended_features() {
		$recommended_features = self::$recommended_features;
		$progress_data        = $this->wptw_get_feature_implement_status();
		$processed            = isset( $progress_data['processed_features'] ) && is_array( $progress_data['processed_features'] ) ? $progress_data['processed_features'] : array();
		$batch_size           = 5;
		$to_process           = array();
		$completed_features   = count( $processed );
		$total_features       = count( $recommended_features );

		foreach ( $recommended_features as $feature ) {
			if ( ! in_array( $feature, $processed, true ) ) {
				$to_process[] = $feature;
				if ( count( $to_process ) >= $batch_size ) {
					break;
				}
			}
		}

		if ( empty( $to_process ) ) {
			// All features processed.
			$this->wptw_update_progress_status( $total_features, $total_features );
			$progress_data['processed_features']      = $recommended_features;
			$progress_data['recommended_completed']   = true;
			$progress_data['update_default_settings'] = true;
			$this->wptw_update_feature_implement_status( $progress_data );
			return;
		}

		foreach ( $to_process as $feature ) {
			$reset_feature = new ResetByFeatureOptionController();
			$feature_data  = array(
				'feature_option' => $feature,
				'remain_active'  => false,
			);
			$reset_feature->wptw_reset_feature_by_option( wp_json_encode( $feature_data ) );
			$processed[] = $feature;
			++$completed_features;
		}

		$this->wptw_update_progress_status( $completed_features, $total_features );
		$progress_data                       = $this->wptw_get_feature_implement_status();
		$progress_data['processed_features'] = $processed;
		$this->wptw_update_feature_implement_status( $progress_data );

		if ( $completed_features < $total_features ) {
			wp_schedule_single_event( time() + 5, 'wptw_activate_recommended_features' );
		} else {
			$progress_data['recommended_completed']   = true;
			$progress_data['update_default_settings'] = true;
			$this->wptw_update_feature_implement_status( $progress_data );
			wp_schedule_single_event( time() + 5, 'wptw_activate_recommended_features' );
		}
	}

	/**
	 * Update progress status
	 *
	 * @param int $completed_features Number of completed features.
	 * @param int $total_features     Total number of features.
	 */
	public function wptw_update_progress_status( $completed_features, $total_features ) {
		$progress_percentage = ( $total_features > 0 ) ? round( ( $completed_features / $total_features ) * 50 ) : 0;
		$is_completed        = ( $completed_features === $total_features );

		$progress_data             = $this->wptw_get_feature_implement_status();
		$progress_data['progress'] = $progress_percentage;

		if ( $is_completed ) {
			$progress_data['recommended_completed'] = $is_completed;
		}
		$this->wptw_update_feature_implement_status( $progress_data );
	}

	/**
	 * Update parent feature active status
	 *
	 * @param int  $id        Feature ID.
	 * @param bool $is_active Current active status.
	 * @return bool Success status.
	 */
	public function wptw_update_parent_active_status( $id, $is_active ) {
		if ( ! $is_active ) {
			$feature_data        = array(
				'featureId' => $id,
				'is_active' => true,
			);
			$features_controller = new FeaturesController();
			$response            = $features_controller->wptw_update_feature_status( null, $feature_data );
			return ! empty( $response['feature_data'] ) && 200 === $response['code'];
		}

		return true;
	}

	/**
	 * Get feature ID by option name
	 *
	 * @param string $option Feature option name.
	 * @return array|null Feature ID and active status.
	 */
	public function wptw_get_feature_id( $option ) {
		$db_model     = new DBModel();
		$key          = 'default_feature_settings';
		$feature_data = $db_model->get_log_value( $key, $option );

		if ( ! empty( $feature_data[0] ) ) {
			return array(
				'id'        => $feature_data[0]['id'],
				'is_active' => $feature_data[0]['is_active'],
			);
		}

		return null;
	}
}
