<?php
namespace Tailwatch\Admin\App\Api\Controllers\Visit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\OptionsModel;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;

class VisitController {

	/**
	 * Insert initial visit data structure
	 */
	public function tailwatch_insert_visit_data() {
		$data = array(
			'check_php_version'    => array(
				'required'       => true,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
			'check_wp_version'     => array(
				'required'       => true,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
			'tailwatch_table'           => array(
				'required'       => true,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
			'check_cron_status'    => array(
				'required'       => true,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
			'db_initialize'        => array(
				'required'       => true,
				'cron_running'   => false,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
			'recommended_features' => array(
				'required'       => true,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
			'features_implemented' => array(
				'required'       => true,
				'is_completed'   => false,
				'failed_attempt' => 0,
			),
		);

		if ( false === get_option( TAILWATCH_VISIT_DATA ) ) {
			add_option( TAILWATCH_VISIT_DATA, wp_json_encode( $data ), '', false );
		}
	}

	/**
	 * Verify visit progress and return current step
	 *
	 * @return array Visit progress status with current step information.
	 */
	public function tailwatch_verify_visit_progress() {
		try {
			$this->tailwatch_insert_visit_data();

			$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

			// Ensure all required fields exist with defaults.
			$defaults = array(
				'check_php_version'    => array(
					'required'       => true,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
				'check_wp_version'     => array(
					'required'       => true,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
				'tailwatch_table'           => array(
					'required'       => true,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
				'check_cron_status'    => array(
					'required'       => false,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
				'db_initialize'        => array(
					'required'       => true,
					'cron_running'   => false,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
				'recommended_features' => array(
					'required'       => true,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
				'features_implemented' => array(
					'required'       => true,
					'is_completed'   => false,
					'failed_attempt' => 0,
				),
			);

			$get_data = array_merge( $defaults, $get_data );

			$visit_data = array(
				'check_php_version'    => $get_data['check_php_version']['is_completed'],
				'check_wp_version'     => $get_data['check_wp_version']['is_completed'],
				'tailwatch_table'           => $get_data['tailwatch_table']['is_completed'],
				'check_cron_status'    => $get_data['check_cron_status']['is_completed'],
				'db_initialize'        => $get_data['db_initialize']['is_completed'],
				'recommended_features' => $get_data['recommended_features']['is_completed'],
				'features_implemented' => $get_data['features_implemented']['is_completed'],
			);

			$steps = array(
				'check_php_version'    => __( 'Version check is pending.', 'tailwatch' ),
				'check_wp_version'     => __( 'Version check is pending.', 'tailwatch' ),
				'tailwatch_table'           => __( 'Table creation check is pending.', 'tailwatch' ),
				'check_cron_status'    => __( 'Verify WP Cron check is pending.', 'tailwatch' ),
				'db_initialize'        => __( 'Database initialization check is pending.', 'tailwatch' ),
				'recommended_features' => __( 'Recommended Features check is pending.', 'tailwatch' ),
				'features_implemented' => __( 'Features Implemented check is pending.', 'tailwatch' ),
			);

			// Collect failed non-required steps.
			$failed_non_required = array();
			foreach ( $get_data as $step => $data ) {
				if ( ! $data['required'] && ! $data['is_completed'] && $data['failed_attempt'] > 0 ) {
					$failed_non_required[ $step ] = array(
						'message'         => isset( $data['failed_message'] ) ? $data['failed_message'] : $steps[ $step ],
						'failed_attempts' => $data['failed_attempt'],
					);
				}
			}

			// Check completion status.
			$all_required_completed = true;
			$non_required_attempted = true;

			foreach ( $get_data as $step => $data ) {
				if ( $data['required'] && ! $data['is_completed'] ) {
					$all_required_completed = false;
				}
				if ( ! $data['required'] && ! $data['is_completed'] && 0 === $data['failed_attempt'] ) {
					$non_required_attempted = false;
				}
			}

			if ( $all_required_completed && $non_required_attempted ) {
				return array(
					'code'                => 200,
					'is_completed'        => true,
					'message'             => __( 'Visit completed.', 'tailwatch' ),
					'failed_non_required' => $failed_non_required,
				);
			}

			// Find next pending step.
			foreach ( $steps as $step => $message ) {
				// Skip failed non-required steps.
				if ( ! $get_data[ $step ]['required'] && $get_data[ $step ]['failed_attempt'] > 0 ) {
					continue;
				}

				// Special handling for db_initialize.
				if ( 'db_initialize' === $step ) {
					if ( ! $get_data['db_initialize']['is_completed'] && ! $get_data['db_initialize']['cron_running'] ) {
						return array(
							'code'                => 200,
							'visit_step'          => $step,
							'visit_data'          => $visit_data,
							'is_completed'        => false,
							'message'             => $message,
							'failed_non_required' => $failed_non_required,
						);
					}
				} elseif ( ! $get_data[ $step ]['is_completed'] ) {
					if ( $get_data[ $step ]['required'] || 0 === $get_data[ $step ]['failed_attempt'] ) {
						return array(
							'code'                => 200,
							'visit_step'          => $step,
							'visit_data'          => $visit_data,
							'is_completed'        => false,
							'message'             => $message,
							'failed_non_required' => $failed_non_required,
						);
					}
				}
			}

			return array(
				'code'                => 200,
				'is_completed'        => true,
				'message'             => __( 'Visit completed.', 'tailwatch' ),
				'failed_non_required' => $failed_non_required,
			);

		} catch ( \Throwable $e ) {
			return array(
				'code'         => 500,
				'is_completed' => false,
				'message'      => __( 'An error occurred while verifying visit progress: ', 'tailwatch' ) . $e->getMessage(),
			);
		}
	}

	/**
	 * Check PHP version compatibility
	 *
	 * @param bool $update_data Whether to update visit data.
	 * @return array Compatibility check result.
	 */
	public static function tailwatch_check_php_version( $update_data = true ) {
		$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

		$result_is = false === $update_data && isset( $get_data['check_php_version']['is_completed'] ) && false === $get_data['check_php_version']['is_completed'];

		if ( version_compare( PHP_VERSION, TAILWATCH_PHP_VERSION, '<' ) || $result_is ) {
			if ( is_array( $get_data ) ) {
				if ( true === $get_data['check_php_version']['is_completed'] ) {
					$get_data['check_php_version']['is_completed']    = false;
					$get_data['check_php_version']['failed_attempt'] += 1;
					update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
				}
			}

			return array(
				'required_php_version' => TAILWATCH_PHP_VERSION,
				'current_php_version'  => PHP_VERSION,
				'message'              => sprintf(
					/* translators: 1: Required PHP version, 2: Current PHP version */
					__(
						'<h3><u>WP Tail Watch:</u></h3>This plugin cannot run because it requires <strong>PHP %1$s</strong> or <strong>Higher</strong>. Your current <strong>PHP Version</strong> is <strong>%2$s</strong>.<br/><br/>Please contact your hosting provider to upgrade your PHP version for assistance.',
						'tailwatch'
					),
					esc_html( TAILWATCH_PHP_VERSION ),
					esc_html( PHP_VERSION )
				),
				'code'                 => 400,
			);
		} else {
			if ( is_array( $get_data ) ) {
				$get_data['check_php_version']['is_completed'] = true;
				update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
			}

			return array(
				'required_php_version' => TAILWATCH_PHP_VERSION,
				'current_php_version'  => PHP_VERSION,
				'message'              => __( 'Version Successfully Compatible.', 'tailwatch' ),
				'code'                 => 200,
			);
		}
	}

	/**
	 * Check WordPress version compatibility
	 *
	 * @param bool $update_data Whether to update visit data.
	 * @return array Compatibility check result.
	 */
	public function tailwatch_check_wordpress_version( $update_data = true ) {
		$current_wp_version = get_bloginfo( 'version' );
		$get_data           = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

		$result_is = false === $update_data && isset( $get_data['check_wp_version']['is_completed'] ) && false === $get_data['check_wp_version']['is_completed'];

		if ( version_compare( $current_wp_version, TAILWATCH_WP_VERSION, '<' ) || $result_is ) {
			if ( is_array( $get_data ) ) {
				if ( true === $get_data['check_wp_version']['is_completed'] ) {
					$get_data['check_wp_version']['is_completed']    = false;
					$get_data['check_wp_version']['failed_attempt'] += 1;
					update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
				}
			}

			return array(
				'required_wp_version' => TAILWATCH_WP_VERSION,
				'current_wp_version'  => $current_wp_version,
				'message'             => sprintf(
					/* translators: 1: Required WordPress version, 2: Current WordPress version */
					__(
						'<h3><u>WP Tail Watch:</u></h3>This plugin cannot run because it requires <strong>WordPress %1$s</strong> or <strong>Higher</strong>. Your current <strong>WordPress Version</strong> is <strong>%2$s</strong>.<br/><br/>Please contact your developer or hosting provider to upgrade your WordPress version.',
						'tailwatch'
					),
					esc_html( TAILWATCH_WP_VERSION ),
					esc_html( $current_wp_version )
				),
				'code'                => 400,
			);
		} else {
			if ( is_array( $get_data ) ) {
				$get_data['check_wp_version']['is_completed'] = true;
				update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
			}

			return array(
				'required_wp_version' => TAILWATCH_WP_VERSION,
				'current_wp_version'  => $current_wp_version,
				'message'             => __( 'Version is Compatible.', 'tailwatch' ),
				'code'                => 200,
			);
		}
	}

	/**
	 * Check if required database table exists
	 *
	 * @param bool $update_data Whether to update visit data.
	 * @return array Table existence check result.
	 */
	public function check_tailwatch_table( $update_data = true ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// Cached fast path ($update_data = false): if a prior run already confirmed
		// the table exists, trust the cached state instead of re-running SHOW TABLES.
		// The AJAX entry point uses the default $update_data = true and re-verifies
		// on demand, so an externally dropped table is still caught.
		$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

		if ( false === $update_data && is_array( $get_data ) && ! empty( $get_data['tailwatch_table']['is_completed'] ) ) {
			return array(
				'tailwatch_table'   => $table_name,
				'table_exists' => true,
				'message'      => __( 'Required plugin tables are created.', 'tailwatch' ),
				'code'         => 200,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES requires direct query. Table existence check during plugin setup.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$result_is = false === $update_data && isset( $get_data['tailwatch_table']['is_completed'] ) && false === $get_data['tailwatch_table']['is_completed'];

		if ( $table_exists !== $table_name || $result_is ) {
			if ( is_array( $get_data ) ) {
				if ( true === $get_data['tailwatch_table']['is_completed'] ) {
					$get_data['tailwatch_table']['is_completed']    = false;
					$get_data['tailwatch_table']['failed_attempt'] += 1;
					update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
				}
			}

			return array(
				'tailwatch_table'   => $table_name,
				'table_exists' => false,
				'message'      => __(
					'<h3><u>WP Tail Watch:</u></h3>This plugin requires the necessary database table for <strong>"WP Tail Watch"</strong> to exist. Please ensure that the required table has been created successfully.<br/><br/>If you believe this is a bug or the table is missing, please contact support for assistance.',
					'tailwatch'
				),
				'code'         => 400,
			);
		} else {
			if ( is_array( $get_data ) ) {
				$get_data['tailwatch_table']['is_completed'] = true;
				update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
			}

			return array(
				'tailwatch_table'   => $table_name,
				'table_exists' => true,
				'message'      => __( 'Required plugin tables are created.', 'tailwatch' ),
				'code'         => 200,
			);
		}
	}

	/**
	 * Check WordPress cron status
	 *
	 * @param bool $update_data Whether to update visit data.
	 * @return array Cron status check result.
	 */
	public function tailwatch_check_cron_status( $update_data = true ) {
		$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

		// Passive re-check (the admin-notice check that runs on every admin page)
		// must not fire a loopback request each load - reuse the stored result.
		if ( false === $update_data ) {
			$completed = isset( $get_data['check_cron_status']['is_completed'] ) && true === $get_data['check_cron_status']['is_completed'];

			return array(
				'cron_status' => $completed ? 'enabled' : 'disabled',
				'message'     => $completed
					? __( 'WordPress cron jobs are enabled and functioning correctly.', 'tailwatch' )
					: ( isset( $get_data['check_cron_status']['failed_message'] ) ? $get_data['check_cron_status']['failed_message'] : __( 'WP-Cron is not working on this site.', 'tailwatch' ) ),
				'code'        => $completed ? 200 : 400,
			);
		}

		// Single source of truth: the layered cron-health check (external runners,
		// ALTERNATE_WP_CRON, DISABLE_WP_CRON, then a cached loopback probe).
		$health = ( new CronHealthService() )->check();

		if ( $health['can_proceed'] ) {
			if ( is_array( $get_data ) ) {
				$get_data['check_cron_status']['is_completed'] = true;
				unset( $get_data['check_cron_status']['failed_message'] );
				update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
			}

			return array(
				'cron_status' => 'enabled',
				'message'     => $health['reason'],
				'code'        => 200,
			);
		}

		$message = sprintf(
			/* translators: %s: the specific reason cron is not running (an HTTP status or connection error). */
			__( 'WordPress cron (WP-Cron) does not appear to be running on this site. %s Tailwatch depends on WP-Cron to run its scheduled security tasks such as scans, monitoring, backups and cleanups, so setup cannot continue until this is resolved. Please confirm DISABLE_WP_CRON is not set to true in wp-config.php and that your host or security setup permits loopback requests to wp-cron.php, then try again.', 'tailwatch' ),
			$health['reason']
		);

		if ( is_array( $get_data ) ) {
			$get_data['check_cron_status']['is_completed']   = false;
			$get_data['check_cron_status']['failed_attempt'] = ( isset( $get_data['check_cron_status']['failed_attempt'] ) ? (int) $get_data['check_cron_status']['failed_attempt'] : 0 ) + 1;
			$get_data['check_cron_status']['failed_message'] = $message;
			update_option( TAILWATCH_VISIT_DATA, wp_json_encode( $get_data ) );
		}

		return array(
			'cron_status' => 'disabled',
			'message'     => $message,
			'code'        => 400,
		);
	}

	/**
	 * Verify database initialization completion
	 *
	 * @return array Initialization status.
	 */
	public function tailwatch_verify_initialize_completed() {
		$get_data = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

		$cron_running         = $get_data['db_initialize']['cron_running'] ?? false;
		$initialize_completed = ! empty( $get_data['db_initialize']['is_completed'] );

		if ( false === $cron_running ) {
			wp_schedule_single_event( time() + 5, 'tailwatch_inserting_rows_into_database' );

			return array(
				'code'                 => 200,
				'progress_percentage'  => 0,
				'initialize_completed' => false,
				'cron_running'         => false,
				'message'              => __( 'Cron scheduling for database initialization.', 'tailwatch' ),
			);
		}

		// Watchdog: cron_running is true but the WP-Cron event may have been
		// lost (PHP timeout / fatal in the worker → no self-reschedule). When
		// the polled status arrives and initialization isn't complete yet, make
		// sure the worker is queued so progress can resume — otherwise the
		// dashboard would show a frozen progress bar forever.
		if ( ! $initialize_completed && ! wp_next_scheduled( 'tailwatch_inserting_rows_into_database' ) ) {
			wp_schedule_single_event( time() + 5, 'tailwatch_inserting_rows_into_database' );
		}

		return $this->tailwatch_get_db_initialize_progress();
	}

	/**
	 * Get database initialization progress
	 *
	 * @return array Progress information.
	 */
	public function tailwatch_get_db_initialize_progress() {
		$option_model = new OptionsModel();
		$get_data     = $option_model->tailwatch_get_initialization_data();
		$visit_data   = json_decode( get_option( TAILWATCH_VISIT_DATA ), true );

		if ( ! empty( $get_data ) && ! empty( $get_data['progress_percentage'] ) ) {
			return array(
				'code'                 => 200,
				'progress_percentage'  => $get_data['progress_percentage'],
				'initialize_completed' => $get_data['initialize_completed'],
				'cron_running'         => isset( $visit_data['db_initialize']['cron_running'] ) ? $visit_data['db_initialize']['cron_running'] : false,
				'function_started'     => isset( $visit_data['db_initialize']['function_started'] ) ? $visit_data['db_initialize']['function_started'] : false,
				'function_completed'   => isset( $visit_data['db_initialize']['function_completed'] ) ? $visit_data['db_initialize']['function_completed'] : false,
				'completion_timestamp' => isset( $visit_data['db_initialize']['completion_timestamp'] ) ? $visit_data['db_initialize']['completion_timestamp'] : null,
				'current_timestamp'    => time(),
				'message'              => $get_data['progress_message'],
			);
		} else {
			return array(
				'code'                 => 200,
				'progress_percentage'  => 0,
				'initialize_completed' => false,
				'cron_running'         => isset( $visit_data['db_initialize']['cron_running'] ) ? $visit_data['db_initialize']['cron_running'] : false,
				'function_started'     => isset( $visit_data['db_initialize']['function_started'] ) ? $visit_data['db_initialize']['function_started'] : false,
				'function_completed'   => isset( $visit_data['db_initialize']['function_completed'] ) ? $visit_data['db_initialize']['function_completed'] : false,
				'completion_timestamp' => isset( $visit_data['db_initialize']['completion_timestamp'] ) ? $visit_data['db_initialize']['completion_timestamp'] : null,
				'current_timestamp'    => time(),
				'message'              => '',
			);
		}
	}

}