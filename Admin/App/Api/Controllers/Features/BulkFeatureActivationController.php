<?php

namespace Tailwatch\Admin\App\Api\Controllers\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetByFeatureOptionController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Bulk Feature Activation Controller.
 *
 * Activates multiple feature options in a single request by delegating
 * each one to ResetByFeatureOptionController with remain_active=true.
 * Add-ons can register additional activatable feature keys via the
 * `wptw_bulk_activate_available_features` filter; unknown keys are
 * recorded as `status: 'skipped'` rather than `'failed'` so the
 * response cleanly distinguishes "not handled by this build" from
 * "tried and errored".
 *
 */
class BulkFeatureActivationController {

	public static $format_feature_name = array(
		'default_files_and_permission'     => 'File Permissions',
		'default_log_activity'             => 'Activity Logs',
		'default_monitoring_logs'          => 'Error Logs',
		'default_email_configure'    	   => 'Email/SMTP Logs',
		'default_verify_ssl'               => 'Smart SSL',
		'default_backup_enable'            => 'Backup Vault',
		'default_malware_scan'             => 'Malware Guard',
		'default_database_optimizer'       => 'Database Optimizer',
		'default_file_integrity_check'     => 'Integrity Watch',
		'default_hardening_audit'          => 'Hardening Audit',
		'default_search_replace'           => 'Search & Replace',
		'advanced_user_access_control'     => 'User Management',
		'default_redirection_manager'      => '301 Redirection',
		'broken_link_checker_settings'     => 'Broken Links',
		'default_disable_keys'             => 'Content Restrictions',
		'default_two_step_authenticate'    => 'Role-based 2FA',
		'default_cron_job_list'            => 'Cron Job Scheduler',
		'default_ips_managment'            => 'Geo-blocking',
		'default_login_defender_management' => 'Login Defender',
	);

	/**
	 * Activate a batch of feature options in a single request.
	 *
	 * @param string $post_data JSON string: `{ "feature_options": string[] }`.
	 * @return array {
	 *     code:    int,
	 *     message: string,
	 *     results: array<string, array>,
	 *     summary: array{ successful:int, failed:int, skipped:int, total:int }
	 * }
	 */
	public function wptw_activate_features_bulk( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$feature_options = isset( $data['feature_options'] ) ? $data['feature_options'] : null;

			if ( empty( $feature_options ) || ! is_array( $feature_options ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'feature_options must be a non-empty array.', 'tailwatch' ),
				);
			}

			// Build the list of feature keys this endpoint will activate. Extensions
			// (e.g. add-on plugins) may hook the filter to register additional
			// activatable feature keys; the default list is whatever the free
			// plugin ships and knows how to enable on its own.
			$available_features = (array) apply_filters( 'wptw_bulk_activate_available_features', self::get_free_features() );

			$reset_controller = new ResetByFeatureOptionController();

			$results    = array();
			$successful = 0;
			$failed     = 0;
			$skipped    = 0;

			foreach ( $feature_options as $feature_option ) {
				$feature_display_name = self::$format_feature_name[ $feature_option ] ?? $feature_option;

				if ( ! in_array( $feature_option, $available_features, true ) ) {
					// Two reasons a key can land here:
					//
					// 1. The feature ships only in an add-on (Tailwatch Pro) —
					//    the key is known to the display-name map but no free
					//    activation path exists. Return a 402 "Payment Required"
					//    with the `is_upgrade_feature` marker so the dashboard
					//    can render the same "Upgrade Now" affordance used by
					//    the security-score panel.
					//
					// 2. The key is genuinely unknown (typo, stale frontend) —
					//    return 403 with the original "not available" wording.
					$is_known_pro_feature = isset( self::$format_feature_name[ $feature_option ] );

					if ( $is_known_pro_feature ) {
						$results[ $feature_option ] = array(
							'code'               => 402,
							'feature_name'       => $feature_display_name,
							'message'            => __( 'This feature requires the Tailwatch Pro Plugin.', 'tailwatch' ),
							'status'             => 'upgrade_required',
							'is_upgrade_feature' => true,
						);
					} else {
						$results[ $feature_option ] = array(
							'code'         => 403,
							'feature_name' => $feature_display_name,
							'message'      => sprintf(
								/* translators: %s: feature display name */
								__( "Feature '%s' is not available for bulk activation.", 'tailwatch' ),
								$feature_display_name
							),
							'status'       => 'skipped',
						);
					}
					++$skipped;
					continue;
				}

				// Activate the feature
				$result = $reset_controller->wptw_reset_feature_by_option(
					wp_json_encode(
						array(
							'feature_option' => $feature_option,
							'remain_active'  => true,
						)
					)
				);

				if ( $result['code'] === 200 ) {
					++$successful;
					$results[ $feature_option ] = array_merge(
						$result,
						array(
							'feature_name' => $feature_display_name,
							'message'      => __( 'Feature activated successfully.', 'tailwatch' ),
							'status'       => 'activated',
						)
					);
				} else {
					++$failed;
					$results[ $feature_option ] = array_merge(
						$result,
						array(
							'feature_name' => $feature_display_name,
							'status'       => 'failed',
						)
					);
				}
			}

			Log::info(
				"Activated: {$successful}, Failed: {$failed}, Skipped: {$skipped}",
				array(
					'feature' => 'dashboard',
					'action'  => 'bulk_feature_activation',
					'origin'  => 'system',
				)
			);

			return array(
				'code'    => 200,
				// translators: 1: number of activated features, 2: number failed, 3: number skipped.
				'message' => sprintf( __( 'Activated %1$d features. Failed: %2$d. Skipped: %3$d.', 'tailwatch' ), $successful, $failed, $skipped ),
				'results' => $results,
				'summary' => array(
					'successful' => $successful,
					'failed'     => $failed,
					'skipped'    => $skipped,
					'total'      => count( $feature_options ),
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Bulk activation failed: ' . $e->getMessage(),
				array(
					'feature'  => 'dashboard',
					'action'   => 'bulk_activation_failed',
					'detail'   => $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'Bulk activation failed.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Feature option keys the bulk activator recognises by default.
	 * Extensions can add to this list via wptw_bulk_activate_available_features.
	 *
	 * @return string[]
	 */
	private static function get_free_features() {
		return array(
			'default_files_and_permission',
			'default_log_activity',
			'default_monitoring_logs',
			'default_verify_ssl',
			'default_email_configure',
			'default_disable_keys',
			'default_backup_enable',
			'default_database_optimizer',
			'default_cron_job_list',
			'default_search_replace',
			'default_redirection_manager',
			'default_file_integrity_check',
			'default_hardening_audit',
			'broken_link_checker_settings',
			'default_ips_managment',
			'default_login_defender_management',
		);
	}
}
