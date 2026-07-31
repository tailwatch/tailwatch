<?php

namespace Tailwatch\Admin\App\Api\Controllers\Features;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Features\VerifyingFeaturesController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\ProcessManager;

use Tailwatch\Admin\App\Api\Models\DBModel;

/**
 * Security Features Verify Controller.
 *
 * Computes the 100-point security score by summing per-feature weights
 * in chunked passes via a self-rescheduling single-event cron
 * (`wptw_verify_features_in_multi_cron`) so it never blocks page loads.
 *
 * ## Free vs. extension features
 *
 * `$security_features` lists every feature the dashboard renders a
 * security-score row for. Some entries (`default_malware_scan`,
 * `default_two_step_authenticate`) ship NO scoring implementation in
 * the free plugin — they are extension surfaces. When an add-on hooks
 * `wptw_calculate_security_feature_score`, its return value is used
 * as the row's score. When no listener responds, the row is rendered
 * as an `is_upgrade_feature` card that does not contribute to the
 * security total — the frontend draws the same upgrade affordance
 * used by other extension-only surfaces in the plugin.
 *
 * Extensions may also augment disabled-feature responses via
 * `wptw_security_feature_disabled_response` to attach contextual
 * call-to-action metadata.
 *
 */
class SecurityFeaturesVerifyController {
	private $processManager;
	private $currentProcessId;

	/**
	 * Every security feature the dashboard renders a row for.
	 *
	 * Free-scored entries have a `wptw_calculate_*` method in this
	 * class. Extension-only entries (`default_malware_scan`,
	 * `default_two_step_authenticate`) have no method — they flow
	 * through the extension-hook fallback in
	 * {@see self::wptw_calculate_security_level()} so add-ons can
	 * provide the score or the frontend can render an upgrade card.
	 *
	 * @var string[]
	 */
	public static $security_features = array(
		'default_malware_scan',
		'default_files_and_permission',
		'default_file_integrity_check',
		'default_two_step_authenticate',
		'default_log_activity',
		'default_monitoring_logs',
		'default_verify_ssl',
		'default_backup_enable',
		'default_database_optimizer',
		'default_hardening_audit',
		'default_login_defender_management',
	);

	/**
	 * Display labels for extension-only features.
	 *
	 * Used by the upgrade-card fallback when no add-on hooks the
	 * scoring filter — the dashboard still needs a human label to
	 * render the row.
	 *
	 * @var array<string,string>
	 */
	private static $extension_feature_labels = array(
		'default_malware_scan'          => 'Malware Guard',
		'default_two_step_authenticate' => 'Role-based 2FA',
	);

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_verify_features_in_multi_cron', array( $this, 'wptw_verify_features_with_cron' ) );
		$this->processManager = new ProcessManager();
		$this->register_process_monitoring();
	}

	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'    => 'security_verify',
				'cron_hooks'      => array( 'wptw_verify_features_in_multi_cron' ),
				'stuck_threshold' => 300,
				'max_retries'     => 3,
			)
		);
	}

	public function wptw_verify_features_with_cron() {
		$get_features = $this->wptw_get_features_value();
		$process_id   = isset( $get_features['process_id'] ) ? $get_features['process_id'] : ( $this->currentProcessId ?? null );
		if ( ! $process_id ) {
			$total = 0;
			if ( isset( $get_features['all_options']['active_features'] ) ) {
				$total = count( $get_features['all_options']['active_features'] );
			}
			$process_id                 = $this->processManager->get_or_create_process(
				'security_verify',
				'wptw_verify_features_in_multi_cron',
				array(
					'total' => $total,
				)
			);
			$this->currentProcessId     = $process_id;
			$get_features['process_id'] = $process_id;
			$this->update_features_value( $get_features );
		}
		$this->processManager->heart_beat( $process_id );
		$this->processManager->update_state( $process_id, 'in_progress' );

		$db_model = new DBModel();

		if ( $get_features['cron_running'] === false ) {
			$get_features['cron_running'] = true;
			$this->update_features_value( $get_features );
		}

		foreach ( $get_features['all_options']['active_features'] as $name => $feature ) {
			if ( $feature['is_completed'] === true ) {
				continue;
			}

			$feature_value = $db_model->get_data_by_id( $feature['id'] );

			$this->wptw_calculate_security_level( $feature_value );

			$get_features['all_options']['active_features'][ $name ]['is_completed'] = true;

			++$get_features['attempt_count'];
			$this->update_features_value( $get_features );

			if ( $get_features['attempt_count'] >= 5 && $get_features['process_run'] === 'automatically' ) {
				$get_features['attempt_count'] = 0;
				$this->update_features_value( $get_features );
				wp_schedule_single_event( time() + 4, 'wptw_verify_features_in_multi_cron' );
				return;
			}
		}

		$is_completed = true;

		foreach ( $get_features['all_options']['active_features'] as $completed ) {
			if ( ! empty( $completed['is_completed'] ) && $completed['is_completed'] === false ) {
				$is_completed = false;
				break;
			}
		}

		if ( $is_completed ) {
			$get_features['is_completed'] = true;
			$this->update_features_value( $get_features );
			$this->processManager->mark_completed( $process_id );
			$this->currentProcessId = null;
		}
	}

	public function wptw_get_calculate_features() {
		$db_model = new DBModel();
		$wptw_key           = 'default_dashboard_data';
		$option             = 'calculate_active_features';
		return $db_model->get_recent_data( $option, $wptw_key );
	}

	public function update_calculate_features_data( array $options ) {
		$db_model  = new DBModel();
		$wptw_key = 'default_dashboard_data';
		$option   = 'calculate_active_features';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	/**
	 * Read the cached security score and the per-feature breakdown.
	 *
	 * The breakdown returned in `all_disabled_features` is what the
	 * dashboard's security panel and the bulk-activation modal render.
	 * Three row shapes appear in that list:
	 *
	 * 1. Disabled-free-feature rows — emitted when a free feature is
	 *    inactive and the user can fix it locally (carries a
	 *    `message` and/or a `disabled_features` array).
	 * 2. Extension-only rows — emitted for features the free plugin
	 *    has no scorer for (e.g. Malware Guard, Role-based 2FA).
	 *    Carries `is_upgrade_feature => true` so the frontend can
	 *    swap the "Activate" button for an "Upgrade Now" affordance.
	 * 3. Both — an extension feature whose listener returns a real
	 *    score will overwrite the upgrade-card payload via the
	 *    `wptw_calculate_security_feature_score` filter.
	 *
	 * Fully active free features are intentionally excluded from the
	 * list — they contribute to `total_score` but have nothing for
	 * the user to act on.
	 *
	 * @return array {
	 *     code:                  int,
	 *     message:               string,
	 *     total_score:           int (0-100; free plugin caps at 77),
	 *     all_disabled_features: array<int, array{
	 *         feature_key:        string,
	 *         feature_name:       string,
	 *         message:            string,
	 *         disabled_features:  array,
	 *         is_upgrade_feature?: bool
	 *     }>
	 * }
	 */
	public function wptw_features_calculate_score() {
		try {

			$feature_data = $this->wptw_get_features_value();
			if ( ! empty( $feature_data ) && $feature_data['process_run'] === 'manually' && $feature_data['is_completed'] === false ) {
				return array(
					'code'                  => 200,
					'message'               => '',
					'cron_running'          => $feature_data['cron_running'],
					'process_running'       => true,
					'total_score'           => 0,
					'all_disabled_features' => array(),
				);
			}

			$existing_data = $this->wptw_get_calculate_features();

			if ( ! empty( $existing_data ) ) {
				$total_score           = $existing_data['total_score'] ?? 0;
				$all_disabled_features = array();

				if ( isset( $existing_data['all_features'] ) ) {
					foreach ( $existing_data['all_features'] as $key => $feature ) {
						// Skip stale entries for features that have since been removed
						// from the security verification set (e.g. security_headers,
						// google_recaptcha, login_defender_management).
						if ( ! in_array( $key, self::$security_features, true ) ) {
							continue;
						}

						$feat_message       = $feature['message'] ?? '';
						$feat_disabled      = isset( $feature['disabled_features'] ) && is_array( $feature['disabled_features'] ) ? $feature['disabled_features'] : array();
						$is_upgrade_feature = ! empty( $feature['is_upgrade_feature'] );

						// Surface a row when:
						// - the feature is disabled and the user can do something about it
						//   (has a `message` or `disabled_features`), OR
						// - the feature is extension-only — the row carries
						//   `is_upgrade_feature` so the dashboard / bulk activation UI
						//   can render an "Upgrade Now" card alongside the live rows.
						if ( ! empty( $feat_message ) || ! empty( $feat_disabled ) || $is_upgrade_feature ) {
							$row = array(
								'feature_key'       => $key,
								'feature_name'      => $feature['feature_name'] ?? ucfirst( str_replace( '_', ' ', $key ) ),
								'message'           => $feat_message,
								'disabled_features' => $feat_disabled,
							);
							if ( $is_upgrade_feature ) {
								$row['is_upgrade_feature'] = true;
							}

							/**
							 * Extension point — lets a separately-shipped add-on
							 * augment the per-row payload with extra metadata
							 * (for example, the plan tier required to unlock a
							 * feature, or a lock-state flag the dashboard can
							 * use to pick between "upgrade", "connect license",
							 * and "activate feature" CTAs).
							 *
							 * Free intentionally outputs only the minimal shape
							 * above. Any add-on that needs additional row
							 * fields hooks this filter and returns the same
							 * array with extra keys merged. With no listener
							 * attached, the filter is a no-op and the output
							 * is unchanged.
							 *
							 * @since 1.0.1
							 *
							 * @param array  $row     Row currently being added.
							 * @param string $key     Feature option key (e.g. `default_malware_scan`).
							 * @param array  $feature Full score row built by the per-feature scorer / filter listener.
							 */
							$row = apply_filters( 'wptw_security_disabled_feature_row', $row, $key, $feature );
							$all_disabled_features[] = $row;
						}
					}
				}

				return array(
					'code'                  => 200,
					'message'               => __( 'Data found successfully', 'tailwatch' ),
					'total_score'           => $total_score,
					'all_disabled_features' => $all_disabled_features,
				);
			} else {

				return array(
					'code'                  => 200,
					'message'               => __( 'No data found', 'tailwatch' ),
					'total_score'           => 0,
					'all_disabled_features' => array(),
				);
			}
		} catch ( \Throwable $e ) {
			$msg = 'Exception occurred while calculating features score: ' . $e->getMessage();
			Log::error(
				$msg,
				array(
					'feature'  => 'dashboard',
					'action'   => 'Score calculation failed',
					'detail'   => $msg,
					'origin'   => 'system',
					'severity' => 'high',
				)
			);

			return array(
				'code'                  => 500,
				'message'               => __( 'Error calculating features score', 'tailwatch' ),
				'total_score'           => 0,
				'all_disabled_features' => array(),
			);
		}
	}

	public function wptw_get_features_value() {
		$db_model = new DBModel();
		$key                = 'default_verify_features';
		$option             = 'verify_features_with_cron';
		return $db_model->get_recent_data( $option, $key );
	}

	public function update_features_value( array $options ) {
		$db_model = new DBModel();
		$key     = 'default_verify_features';
		$option  = 'verify_features_with_cron';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $key, $option );
	}

	public function wptw_delete_verify_features_entries( $db_model, $option, $key ) {
		$where = array(
			'option' => $option,
			'key'    => $key,
		);

		$db_model->delete_rows( $where );
	}

	public function wptw_get_all_features_with_cron( $process_run = 'automatically' ) {
		$option             = 'verify_features_with_cron';
		$key                = 'default_verify_features';
		$db_model = new DBModel();
		$this->wptw_delete_verify_features_entries( $db_model, $option, $key );

		$get_features = $db_model->get_features( false );

		$all_options = array();
		$recommended = self::$security_features;

		foreach ( $get_features as $feature ) {
			if ( in_array( $feature['option'], $recommended ) ) {
				$all_options['active_features'][ $feature['option'] ] = array(
					'id'           => $feature['id'],
					'is_completed' => false,
				);
			}
		}

		$feature_data = array(
			'all_options'   => $all_options,
			'is_completed'  => false,
			'cron_running'  => false,
			'process_run'   => $process_run,
			'attempt_count' => 0,
		);

		$total                      = isset( $all_options['active_features'] ) ? count( $all_options['active_features'] ) : 0;
		$process_id                 = $this->processManager->get_or_create_process(
			'security_verify',
			'wptw_verify_features_in_multi_cron',
			array(
				'total' => $total,
			)
		);
		$this->currentProcessId     = $process_id;
		$feature_data['process_id'] = $process_id;

		$db_data = array(
			'user_id'       => get_current_user_id(),
			'child_of'      => 0,
			'key'           => $key,
			'option'        => $option,
			'value'         => wp_json_encode( $feature_data ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		$db_model->insert_row( $db_data, $db_data_format );

		wp_schedule_single_event( time() + 5, 'wptw_verify_features_in_multi_cron' );
	}

	public function wptw_start_security_features_process() {
		try {
			// Fix typo and clear hooks
			wp_clear_scheduled_hook( 'wptw_verify_features_with_crone' );
			wp_clear_scheduled_hook( 'wptw_verify_features_in_multi_cron' );

			Log::info(
				'Manual security features verification process initiated',
				array(
					'feature' => 'security_verification',
					'action'  => 'Security-Feature-Verification-Started',
					'origin'  => 'user',
				)
			);

			$this->wptw_get_all_features_with_cron( 'manually' );

			return array(
				'code'    => 200,
				'message' => __( 'Security features verification process started successfully.', 'tailwatch' ),
				'data'    => array(
					'process_type' => 'manual',
					'initiated_at' => current_time( 'mysql' ),
					'user_id'      => get_current_user_id(),
				),
			);

		} catch ( \Throwable $e ) {
			$msg = 'Failed to start security verification: ' . $e->getMessage();
			Log::error(
				$msg,
				array(
					'feature'  => 'security_verification',
					'action'   => 'Start process failed',
					'detail'   => $msg,
					'origin'   => 'system',
					'severity' => 'high',
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'Failed to start security verification process.', 'tailwatch' ),
				'error'   => $e->getMessage(),
			);
		}
	}

	public function wptw_execute_security_features_cron_if_failed() {
		try {
			// Check if cron is already scheduled
			if ( wp_next_scheduled( 'wptw_verify_features_in_multi_cron' ) ) {
				return array(
					'code'    => 200,
					'message' => __( 'Security features cron is already scheduled.', 'tailwatch' ),
					'data'    => array(
						'next_scheduled' => gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'wptw_verify_features_in_multi_cron' ) ),
					),
				);
			}

			Log::info(
				'Manual restart of failed security features cron job',
				array(
					'feature' => 'security_verification',
					'action'  => 'Security-Cron-Restarted',
					'origin'  => 'user',
				)
			);

			// Schedule the cron
			wp_schedule_single_event( time() + 4, 'wptw_verify_features_in_multi_cron' );

			return array(
				'code'    => 200,
				'message' => __( 'Security features verification cron has been scheduled successfully.', 'tailwatch' ),
				'data'    => array(
					'scheduled_at' => current_time( 'mysql' ),
					'next_run'     => gmdate( 'Y-m-d H:i:s', time() + 4 ),
					'user_id'      => get_current_user_id(),
				),
			);

		} catch ( \Throwable $e ) {
			$msg = 'Failed to restart security verification cron: ' . $e->getMessage();
			Log::error(
				$msg,
				array(
					'feature'  => 'security_verification',
					'action'   => 'Cron restart failed',
					'detail'   => $msg,
					'origin'   => 'system',
					'severity' => 'high',
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'Failed to schedule security features verification cron.', 'tailwatch' ),
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Build the status payload for a feature that is currently disabled.
	 *
	 * Returns the minimal shape consumed by the security-score frontend
	 * (feature_name, total_score, message, disabled_features). Extensions
	 * can augment the response via the wptw_security_feature_disabled_response
	 * filter — for example, to attach promotional metadata for features
	 * distributed as a separate add-on.
	 *
	 * @param string $feature_name      Human-readable feature label.
	 * @param string $calculate_status  Feature option key (e.g. 'default_backup_enable').
	 * @return array Status payload.
	 */
	public function wptw_return_if_feature_disable( $feature_name, $calculate_status = '' ) {
		$response = array(
			'feature_name'      => $feature_name,
			'total_score'       => 0,
			'message'           => __( 'This feature is disabled. Please activate the feature to secure your website.', 'tailwatch' ),
			'disabled_features' => array(),
		);

		return apply_filters( 'wptw_security_feature_disabled_response', $response, $feature_name, $calculate_status );
	}

	public function wptw_calculate_security_level( $default_data ) {
		$existing_data = $this->wptw_get_calculate_features();

		$calculate_status = $default_data['option'];
		$decode_options   = json_decode( $default_data['value'], true );
		$feature_active   = $default_data['is_active'] == true && $default_data['type_state'] == 'active';

		// Only calculate for features in the security_features array
		if ( ! in_array( $calculate_status, self::$security_features ) ) {
			return;
		}

		// Score weights add up to 100 across the union of free-scored and
		// extension-scored features. The free plugin alone tops out at 77
		// (the remaining 23 — Malware Guard 14 + Role-based 2FA 9 — are
		// extension-only rows that contribute zero until an add-on hooks
		// `wptw_calculate_security_feature_score`).
		//
		// Free weights: files_and_permission 9, file_integrity_check 12,
		// log_activity 8, monitoring_logs 8, verify_ssl 8,
		// backup_enable 8, database_optimizer 8, hardening_audit 8,
		// login_defender_management 8 — sum 77.
		// Extension weights (Pro, via the dispatcher filter below):
		// malware_scan 14, two_step_authenticate 9 — sum 23. Grand total 100.
		// Extension weights (handled by the dispatcher branch below):
		// malware_scan 14, two_step_authenticate 9 — sum 23.
		// login_defender_management (8) was re-added; its 8 points were
		// rebalanced out of files_and_permission (11->9), file_integrity_check
		// (14->12), log_activity (8->6), and hardening_audit (8->6) so the free
		// total stays 77 and the overall total stays 100. Prior rebalance had
		// dropped security_headers (7) and google_recaptcha (12).
		if ( $calculate_status === 'default_files_and_permission' ) {
			$feature_name = 'File Permissions';
			if ( $feature_active ) {
				$file_permission_score = $this->wptw_calculate_file_permissions( $decode_options['options'], 9, $feature_name, $calculate_status );
			} else {
				$file_permission_score = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_files_and_permission'] = $file_permission_score;
		} elseif ( isset( self::$extension_feature_labels[ $calculate_status ] ) ) {
			// Extension-only feature row (e.g. Malware Guard, Role-based 2FA).
			// Free ships no scorer — delegate to the extension filter, fall back
			// to an upgrade card when no add-on responds.
			$existing_data['all_features'][ $calculate_status ] = $this->wptw_resolve_extension_feature_score(
				$calculate_status,
				$decode_options,
				$feature_active
			);
		} elseif ( $calculate_status === 'default_file_integrity_check' ) {
			$feature_name = 'File Integrity Watch';
			if ( $feature_active ) {
				$file_integrity = $this->wptw_calculate_file_integrity( $decode_options['options'], 12, $feature_name, $calculate_status );
			} else {
				$file_integrity = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_file_integrity_check'] = $file_integrity;
		} elseif ( $calculate_status === 'default_log_activity' ) {
			$feature_name = 'Activity Logs';
			if ( $feature_active ) {
				$user_activity_logs = $this->wptw_calculate_user_activity_logs( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$user_activity_logs = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_log_activity'] = $user_activity_logs;
		} elseif ( $calculate_status === 'default_monitoring_logs' ) {
			$feature_name = 'Error Logs';
			if ( $feature_active ) {
				$error_logs = $this->wptw_calculate_error_logs( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$error_logs = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_monitoring_logs'] = $error_logs;
		} elseif ( $calculate_status === 'default_verify_ssl' ) {
			$feature_name = 'Smart SSL';
			if ( $feature_active ) {
				$smart_ssl = $this->wptw_calculate_smart_ssl( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$smart_ssl = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_verify_ssl'] = $smart_ssl;
		} elseif ( $calculate_status === 'default_backup_enable' ) {
			$feature_name = 'Backup Vault';
			if ( $feature_active ) {
				$backup = $this->wptw_calculate_backup( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$backup = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_backup_enable'] = $backup;
		} elseif ( $calculate_status === 'default_database_optimizer' ) {
			$feature_name = 'Database Optimizer';
			if ( $feature_active ) {
				$db_optimizer = $this->wptw_calculate_database_optimizer( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$db_optimizer = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_database_optimizer'] = $db_optimizer;
		} elseif ( $calculate_status === 'default_hardening_audit' ) {
			$feature_name = 'Hardening Audit';
			if ( $feature_active ) {
				$hardening_audit = $this->wptw_calculate_hardening_audit( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$hardening_audit = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_hardening_audit'] = $hardening_audit;
		} elseif ( $calculate_status === 'default_login_defender_management' ) {
			$feature_name = 'Login Defender';
			if ( $feature_active ) {
				$login_defender = $this->wptw_calculate_login_defender( $decode_options['options'], 8, $feature_name, $calculate_status );
			} else {
				$login_defender = $this->wptw_return_if_feature_disable( $feature_name, $calculate_status );
			}
			$existing_data['all_features']['default_login_defender_management'] = $login_defender;
		}

		$total_score = 0;

		// Drop entries for features that are no longer in $security_features (e.g.
		// previously stored scores for security_headers / google_recaptcha /
		// login_defender_management). Without this cleanup, stale rows in the cached
		// `all_features` blob would keep contributing to the total and the score
		// could exceed 100 again — defeating the rebalance.
		if ( isset( $existing_data['all_features'] ) ) {
			foreach ( $existing_data['all_features'] as $key => $feature ) {
				if ( ! in_array( $key, self::$security_features, true ) ) {
					unset( $existing_data['all_features'][ $key ] );
					continue;
				}
				if ( isset( $feature['total_score'] ) ) {
					$total_score += $feature['total_score'];
				}
			}
		}

		// Safeguard: Handle cases where total score exceeds 100
		if ( $total_score > 100 ) {
			$existing_data['total_score']  = 100;
			$existing_data['score_status'] = 'above_100';
			$existing_data['actual_score'] = $total_score;
		} else {
			$existing_data['total_score']  = $total_score;
			$existing_data['score_status'] = 'normal';
			$existing_data['actual_score'] = $total_score;
		}

		$this->update_calculate_features_data( $existing_data );
	}

	public function wptw_calculate_file_permissions( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'disable_file_editor_in_plugins',
			'disable_file_editor_in_themes',
			'disable_access_to_xmlrpc',
			'restrict_author_archive_access',
			'remove_headers',
			'remove_meta_tags',
			'remove_html_comments',
			'remove_wp_version_information',
			'disable_emoji',
			'disable_oembed',
			'disable_feeds',
		);

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		$total_permissions  = count( $expected_features );
		$active_permissions = 0;
		$disabled_features  = array();

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_permissions;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$permission_score = ( $active_permissions / $total_permissions ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $permission_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	/**
	 * Build a security-score row for an extension-only feature.
	 *
	 * Asks the `wptw_calculate_security_feature_score` filter for the
	 * row payload. An add-on that ships the actual feature returns a
	 * standard score entry (`feature_name`, `total_score`, `message`,
	 * `disabled_features`). When no listener responds, the row falls
	 * back to an `is_upgrade_feature` card that contributes zero to
	 * the security total — the dashboard renders it as an extension
	 * promo without distorting the score.
	 *
	 * @param string $calculate_status Feature option key (e.g. `default_malware_scan`).
	 * @param array  $decode_options   Decoded feature blob from the DB.
	 * @param bool   $feature_active   Whether the parent feature row is currently active.
	 * @return array Standard score entry, possibly carrying `is_upgrade_feature => true`.
	 */
	private function wptw_resolve_extension_feature_score( $calculate_status, $decode_options, $feature_active ) {
		$feature_name = self::$extension_feature_labels[ $calculate_status ]
			?? ucfirst( str_replace( array( 'default_', '_' ), array( '', ' ' ), $calculate_status ) );

		$extension_score = apply_filters(
			'wptw_calculate_security_feature_score',
			null,
			$calculate_status,
			$feature_name,
			$decode_options,
			$feature_active
		);

		if ( is_array( $extension_score ) ) {
			return $extension_score;
		}

		return array(
			'feature_name'       => $feature_name,
			'total_score'        => 0,
			'is_upgrade_feature' => true,
			'message'            => __( 'This feature requires the Tailwatch Pro Plugin.', 'tailwatch' ),
			'disabled_features'  => array(),
		);
	}

	/**
	 * Score the Login Defender feature from its two core brute-force
	 * protections — username-based and IP-based attempt limiting. Each
	 * enabled protection contributes an equal share of $percentage.
	 *
	 * @param array  $feature_options  Decoded `options` of default_login_defender_management.
	 * @param int    $percentage       Point weight for this feature.
	 * @param string $feature_name     Display label.
	 * @param string $calculate_status Feature option key.
	 * @return array Standard score row.
	 */
	public function wptw_calculate_login_defender( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'ip_based_protection',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	public function wptw_calculate_file_integrity( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'enable_integrity_check',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	public function wptw_calculate_user_activity_logs( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		// Key Activity Logs log options
		$expected_features = array(
			'Login',
			'Logout',
			'Login-failed',
			'Non-existing-user',
			'User-Register',
			'Password-Reset',
			'Password-Reset-Non-Existing-user',
			'Password-Reset-Successful',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	public function wptw_calculate_error_logs( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		// Key error log options
		$expected_features = array(
			'log_503_service_unavailable',
			'log_500_internal_server_error',
			'log_404_not_found',
			'log_502_bad_gateway',
			'log_504_gateway_timeout',
			'log_403_forbidden',
			'log_401_unauthorized',
			'log_429_too_many_requests',
			'log_413_payload_too_large',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	public function wptw_calculate_smart_ssl( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'verify_ssl',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	public function wptw_calculate_backup( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'enable_backup',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	public function wptw_calculate_database_optimizer( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'enable_database_optimizer',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}

	/**
	 * Score the Hardening Audit feature.
	 *
	 * Mirrors `wptw_calculate_database_optimizer` and `wptw_calculate_backup`
	 * — the feature has a single master toggle (`enable_hardening_audit`),
	 * so the score is binary: full weight if enabled, zero if not.
	 *
	 * @param array  $feature_options  The feature's sub-options blob (field_1
	 *                                  and sub_options children).
	 * @param int    $percentage       Weight contribution to the 100-point total.
	 * @param string $feature_name     Human label used in the response.
	 * @param string $calculate_status Feature option key, used for plan-tier lookup.
	 * @return array Standard score entry: feature_name, total_score, message, disabled_features.
	 */
	public function wptw_calculate_hardening_audit( $feature_options, $percentage, $feature_name, $calculate_status = '' ) {
		$expected_features = array(
			'enable_hardening_audit',
		);

		$total_features    = count( $expected_features );
		$active_features   = 0;
		$disabled_features = array();

		$verify_feature = new VerifyingFeaturesController();
		$all_options    = $verify_feature->wptw_verify_options_status( $feature_options );
		$label_map      = $verify_feature->wptw_get_option_labels( $feature_options );

		foreach ( $expected_features as $feature ) {
			if ( in_array( $feature, $all_options ) ) {
				++$active_features;
			} else {
				$disabled_features[] = $label_map[ $feature ] ?? $feature;
			}
		}

		$total_score   = ( $active_features / $total_features ) * $percentage;

		return array(
			'feature_name'      => $feature_name,
			'total_score'       => $total_score,
			'message'           => ! empty( $disabled_features ) ? __( 'Please enable these settings to improve your security score.', 'tailwatch' ) : '',
			'disabled_features' => $disabled_features,
		);
	}
}
