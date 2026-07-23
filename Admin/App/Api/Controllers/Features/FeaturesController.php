<?php
/**
 * Features Controller
 *
 * Handles feature management including enable/disable and option updates.
 * Integrates with CronJobManager to sync cron schedules when features change.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Features
 */

namespace Tailwatch\Admin\App\Api\Controllers\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\FeatureCacheController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\Crypto\EncryptionService;

/**
 * Class FeaturesController
 *
 * Manages plugin features including retrieval, status updates, and option modifications.
 *
 */
class FeaturesController {
	/**
	 * Mapping of feature options to cron job keys.
	 *
	 * When a feature is enabled/disabled, the corresponding cron job
	 * will be scheduled/unscheduled automatically.
	 *
	 * @var array<string, string>
	 */
	private static $feature_cron_map = array(
		'smart_ssl'        => 'smart_ssl',
		'config_salt_keys' => 'config_salt_keys',
		'verify_features'  => 'verify_features',
	);

	/**
	 * Invalidate feature cache.
	 *
	 * @param string $option Feature option key.
	 *
	 * @return void
	 */
	private function invalidate_feature_cache( $option ) {
		try {
			$cache = new FeatureCacheController();
			$cache->invalidate_feature_cache( 'default_feature_settings', $option );
			// Also clear OptionsController's static request cache so any subsequent
			// reads in the same request get fresh DB data (not stale pre-save data).
			OptionsController::invalidate_request_cache( 'default_feature_settings', $option );
		} catch ( \Throwable $e ) {
			// Silently fail cache invalidation.
		}
	}

	/**
	 * Sync cron job schedule for a feature.
	 *
	 * Automatically schedules or unschedules the cron job based on
	 * the feature's enabled state.
	 *
	 * @param string $option Feature option key (e.g., 'smart_ssl').
	 *
	 * @return bool True if sync was performed, false otherwise.
	 */
	private function sync_feature_cron( $option ) {
		try {
			// Check if this feature has an associated cron job.
			if ( ! isset( self::$feature_cron_map[ $option ] ) ) {
				return false;
			}

			$cron_key = self::$feature_cron_map[ $option ];
			$manager  = CronJobManager::get_instance();

			return $manager->sync_cron_job( $cron_key );
		} catch ( \Throwable $e ) {
			// Silently fail cron sync - don't break feature updates.
			return false;
		}
	}

	/**
	 * Check if cron-related settings were changed.
	 *
	 * Detects changes to:
	 * - Main toggle (field_1) - enable/disable
	 * - Interval setting (field_2) - cron frequency
	 *
	 * @param array $options Array of option updates.
	 *
	 * @return bool True if cron-related settings were changed.
	 */
	private function is_cron_setting_changed( $options ) {
		foreach ( $options as $option ) {
			$id = isset( $option['id'] ) ? $option['id'] : '';

			// Check if this is the main toggle field (field_1) - enable/disable.
			if ( strpos( $id, 'field_1' ) !== false && isset( $option['selected'] ) ) {
				return true;
			}

			// Check if this is the interval/frequency field (field_2) - schedule timing.
			if ( strpos( $id, 'field_2' ) !== false && isset( $option['value'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if the main feature toggle (field_1) was changed.
	 *
	 * @param array $options Array of option updates.
	 *
	 * @return bool True if field_1 toggle was changed.
	 *
	 * @deprecated Use is_cron_setting_changed() instead.
	 */
	private function is_main_toggle_changed( $options ) {
		return $this->is_cron_setting_changed( $options );
	}

	/**
	 * Get feature data.
	 *
	 * Retrieves feature configuration based on provided filters.
	 *
	 * @param mixed       $post_data JSON string or array of request data.
	 * @param string|null $plan_type Optional plan type filter.
	 *
	 * @return array Response array with data, message, and code.
	 */
	public function wptw_get_feature( $post_data, $plan_type = null ) {
		// License status is read from cached DB state — no outbound API call.
		// Freshness is owned by the 12h LicenseVerifyCronJob plus the manual
		// connect / disconnect / verify-button user actions. This endpoint
		// used to call wptw_verify_license() inline, which fired an outbound
		// HTTPS request on every dashboard feature fetch (5-15 per admin
		// session) — well-documented antipattern in WP licensing (EDD, Freemius,
		// plugin-update-checker all cache locally and read from cache for UI).
		$verify_ctrl    = new VerifyStatusController();
		$license_status = $verify_ctrl->get_cached_license_status();

		try {
			$option = null;

			if ( ! empty( $post_data ) ) {
				$json_data = is_string( $post_data ) ? wp_unslash( $post_data ) : $post_data;
				$data      = is_array( $json_data ) ? $json_data : json_decode( $json_data, true );
				$option    = isset( $data['option'] ) ? sanitize_text_field( $data['option'] ) : null;
			}

			$db_model = new DBModel();
			$rows     = $db_model->get_features( false, $option );

			if ( ! $rows ) {
				return array(
					'data'           => array(),
					'license_status' => $license_status,
					// translators: %s is the feature option key.
					'message'        => $option ? sprintf( __( 'No feature found for option: %s', 'tailwatch' ), $option ) : __( 'No data found.', 'tailwatch' ),
					'code'           => 404,
				);
			}

			return array(
				'data'           => $rows,
				'license_status' => $license_status,
				'message'        => __( 'Values fetched successfully.', 'tailwatch' ),
				'code'           => 200,
			);
		} catch ( \Throwable $e ) {
			return array(
				'data'           => array(),
				'license_status' => $license_status,
				'message'        => __( 'Internal server error occurred.', 'tailwatch' ),
				'code'           => 500,
			);
		}
	}

	/**
	 * Update feature status (active/inactive).
	 *
	 * Toggles a feature's active state and syncs associated cron jobs.
	 *
	 * @param string|null $post_data       JSON string of request data.
	 * @param array|null  $feature_options Array of feature options (alternative input).
	 *
	 * @return array Response array with feature_data, message, and code.
	 */
	public function wptw_update_feature_status( $post_data = null, $feature_options = null, $skip_lock_gate = false ) {
		$feature_id     = null;
		$feature_title  = null;
		$feature_option = null;

		try {
			if ( null !== $post_data ) {
				$data_value = wp_unslash( $post_data );
				$json_data  = json_decode( $data_value, true, 512, JSON_THROW_ON_ERROR );
			} elseif ( null !== $feature_options ) {
				$json_data = $feature_options;
			} else {
				Log::warning(
					'Feature status update failed: Missing required field',
					array(
						'feature' => 'features',
						'action'  => 'feature_status_update_failed',
						'error'   => 'Missing required field',
					)
				);
				return array(
					'feature_data' => array(),
					'code'         => 400,
					'message'      => __( 'Missing required field', 'tailwatch' ),
				);
			}

			if ( ! isset( $json_data['featureId'] ) ) {
				Log::warning(
					'Feature status update failed: featureId is missing',
					array(
						'feature' => 'features',
						'action'  => 'feature_status_update_failed',
						'error'   => 'featureId is missing in the request body',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'featureId is missing in the request body.', 'tailwatch' ),
					'code'         => 400,
				);
			}

			$feature_id = absint( $json_data['featureId'] );
			$is_active  = $json_data['is_active'] ?? false;
			$db_model   = new DBModel();
			$feature    = $db_model->get_data_by_id( $feature_id );

			if ( ! $feature ) {
				Log::error(
					'Feature status update failed: Feature not found',
					array(
						'feature'    => 'features',
						'action'     => 'feature_status_update_failed',
						'feature_id' => $feature_id,
						'error'      => 'Failed to fetch default feature data. No matching entry found for the given feature ID.',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'Failed to fetch default feature data. No matching entry found for the given feature ID.', 'tailwatch' ),
					'code'         => 404,
				);
			}

			$desired_data   = json_decode( $feature['value'], true, 512, JSON_THROW_ON_ERROR );
			$feature_title  = isset( $desired_data['title'] ) ? $desired_data['title'] : 'Unknown Feature';
			$feature_option = isset( $feature['option'] ) ? $feature['option'] : 'unknown';

			// Block any modification to this feature row while a process that
			// has declared `locks_features` for it is still running. Pulling
			// the rug out from a live cron leaves scan_state='in-progress'
			// rows orphaned and breaks resume/recovery. The strict rule
			// (block ALL changes, not just disable) matches the codebase
			// intent and avoids the trap of "permitted-but-actually-unsafe"
			// sub-option edits mid-run. ProcessGuard returns null when safe.
			// $skip_lock_gate is the opt-in escape hatch for the running process
			// itself: migration/restore call wptw_keep_enable_migration_restore_
			// feature mid-flight to force-enable their own parent row, which
			// would otherwise self-block on the gate they triggered.
			if ( ! $skip_lock_gate ) {
				$blocked = ( new ProcessGuard() )->ensure_can_modify_feature( $feature_option );
				if ( null !== $blocked ) {
					Log::warning(
						'Feature status update blocked: a related background process is running',
						array(
							'feature'         => 'features',
							'action'          => 'feature_status_update_blocked',
							'feature_id'      => $feature_id,
							'feature_name'    => $feature_title,
							'feature_option'  => $feature_option,
							'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
						)
					);
					return $blocked;
				}
			}

			$old_type_state = isset( $feature['type_state'] ) ? $feature['type_state'] : 'unknown';
			$new_type_state = ( true === $is_active ) ? 'active' : 'inactive';
			$new_is_active  = ( true === $is_active ) ? 1 : 0;

			$db_data = array(
				'type_state' => $new_type_state,
				'is_active'  => $new_is_active,
			);
			$where   = array(
				'id' => $feature['id'],
			);

			$updated = $db_model->update_rows( $db_data, $where );
			if ( false === $updated ) {
				Log::error(
					'Feature status update failed: Database update failed',
					array(
						'feature'        => 'features',
						'action'         => 'feature_status_update_failed',
						'feature_id'     => $feature_id,
						'feature_name'   => $feature_title,
						'feature_option' => $feature_option,
						'old_state'      => $old_type_state,
						'new_state'      => $new_type_state,
						'error'          => 'Failed to update feature data in database',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'Failed to update feature data.', 'tailwatch' ),
					'code'         => 500,
				);
			}

			// Invalidate cache after successful update.
			$this->invalidate_feature_cache( $feature['option'] );

			// Sync cron job schedule based on new feature state.
			$this->sync_feature_cron( $feature['option'] );

			// Refresh feature data.
			$feature = $db_model->get_data_by_id( $feature_id );

			// Recalculate security score for this feature immediately.
			( new SecurityFeaturesVerifyController() )->wptw_calculate_security_level( $feature );

			// Use $new_type_state instead of $feature (which is an array).
			$is_activating  = ( $new_type_state === 'active' );
			$scenario       = $is_activating ? 'enabled' : 'disabled';
			$verb_phrase    = $is_activating ? 'turned on' : 'turned off';
			$state_label    = $is_activating ? 'Active' : 'Inactive';
			$previous_label = ( $old_type_state === 'active' ) ? 'Active' : 'Inactive';
			$event_label    = $is_activating ? 'Enabled' : 'Disabled';
			$impact_text    = FeatureNotificationMessagesController::get_impact_text( $feature_option, $scenario );
			$consequence    = FeatureNotificationMessagesController::get_consequence_text( $feature_option, $scenario );
			$message_text   = $is_activating
				? "{$feature_title} is now enabled. {$impact_text}"
				: "{$feature_title} is now disabled. {$impact_text}";

			// Log successful feature status update.
			Log::info(
				$message_text,
				array(
					'feature'        => 'features',
					'action'         => 'feature_status_update',
					'feature_id'     => $feature_id,
					'feature_name'   => $feature_title,
					'feature_option' => $feature_option,
					'old_state'      => $old_type_state,
					'new_state'      => $new_type_state,
					'title'          => "{$feature_title} {$verb_phrase}",
					'meta_data'      => array(
						'event'   => $event_label,
						'feature' => $feature_title,
						'before'  => $previous_label,
						'now'     => $state_label,
						'impact'  => $consequence,
						'feature_option' => $feature_option,
					),
				)
			);

			return array(
				'feature_data' => $feature,
				'message'      => __( 'Feature data updated successfully.', 'tailwatch' ),
				'code'         => 200,
			);
		} catch ( \Throwable $e ) {
			// Log exception with full context.
			Log::error(
				'Feature status update failed: Exception occurred',
				array(
					'feature'        => 'features',
					'action'         => 'feature_status_update_failed',
					'feature_id'     => $feature_id,
					'feature_name'   => $feature_title ? $feature_title : 'Unknown',
					'feature_option' => $feature_option ? $feature_option : 'unknown',
					'error'          => $e->getMessage(),
					'exception'      => $e,
				)
			);

			return array(
				'feature_data' => array(),
				'message'      => __( 'Internal server error occurred.', 'tailwatch' ),
				'code'         => 500,
			);
		}
	}

	/**
	 * Update inner feature options.
	 *
	 * Handles updates to feature sub-options, including toggles, inputs,
	 * checkboxes, and other field types.
	 *
	 * @param string|null $post_data       JSON string of request data.
	 * @param array|null  $feature_options Array of feature options (alternative input).
	 *
	 * @return array|void Response array with feature_data, message, and code.
	 */
	public function wptw_update_inner_feature( $post_data = null, $feature_options = null, $skip_lock_gate = false ) {
		$feature_id     = null;
		$feature_title  = null;
		$feature_option = null;

		try {
			if ( null !== $post_data ) {
				$data      = wp_unslash( $post_data );
				$json_data = json_decode( $data, true );
			} elseif ( null !== $feature_options ) {
				$json_data = $feature_options;
			} else {
				Log::warning(
					'Inner feature update failed: Missing required field',
					array(
						'feature' => 'features',
						'action'  => 'feature_inner_value_update_failed',
						'error'   => 'Both post_data and feature_options are null',
					)
				);
				return array(
					'data'    => array(),
					'code'    => 400,
					'message' => __( 'Missing required field', 'tailwatch' ),
				);
			}

			if ( ! is_array( $json_data ) || ! isset( $json_data['id'] ) || ! isset( $json_data['options'] ) ) {
				Log::warning(
					'Inner feature update failed: Invalid request format',
					array(
						'feature' => 'features',
						'action'  => 'feature_inner_value_update_failed',
						'error'   => 'Expected array with id and options. Received: ' . wp_json_encode( $json_data ),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid request format. Expected an array with id and options.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$duplicate_selection = $this->wptw_check_duplicate_selection( $json_data['options'] );
			if ( ! empty( $duplicate_selection ) ) {
				Log::warning(
					'Inner feature update failed: Duplicate selection detected',
					array(
						'feature' => 'features',
						'action'  => 'feature_inner_value_update_failed',
						'error'   => $duplicate_selection,
					)
				);
				return array(
					'code'    => 400,
					'message' => $duplicate_selection,
				);
			}

			$feature_id = absint( $json_data['id'] );
			$db_model   = new DBModel();
			$feature    = $db_model->get_data_by_id( $feature_id );

			if ( ! $feature ) {
				Log::error(
					'Inner feature update failed: Feature not found',
					array(
						'feature'    => 'features',
						'action'     => 'feature_inner_value_update_failed',
						'feature_id' => $feature_id,
						'error'      => 'Default data for the feature not found',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Default data for the feature not found.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			$default_data         = json_decode( $feature['value'], true );
			$feature_title        = isset( $default_data['title'] ) ? $default_data['title'] : 'Unknown Feature';
			$feature_option       = isset( $feature['option'] ) ? $feature['option'] : 'unknown';
			$selected_data        = array();
			$report_data          = false;
			$processed_duplicates = array();
			$duplication_reports  = array();
			$deletion_reports     = array();
			$processed_reports    = array(); // Fix: Pass as argument instead of static
			$collected_value      = $this->collect_selected_values( $default_data['options'] );

			// Inner options can only be changed while the parent feature is active.
			if ( ! $skip_lock_gate && 1 !== (int) ( isset( $feature['is_active'] ) ? $feature['is_active'] : 0 ) ) {
				return array(
					'data'    => array(),
					'code'    => 400,
					'message' => __( 'Enable this feature before changing its options.', 'tailwatch' ),
				);
			}

			// Block any inner-feature edit (toggle off, toggle on, or sub-option
			// change) while a process that has declared `locks_features` for
			// this feature is still running. Same strict rule as the parent
			// toggle endpoint: mid-run config drift can break a live cron just
			// as surely as disabling can, so all changes wait until the run
			// finishes. ProcessGuard returns null when safe to proceed.
			// $skip_lock_gate is the opt-in escape hatch for the running process
			// itself: wptw_enable_restore_and_migrate is invoked mid-flight by
			// migration/restore to set their own inner option and would
			// otherwise self-block on the gate they triggered.
			if ( $skip_lock_gate ) {
				$blocked = null;
			} else {
				$blocked = ( new ProcessGuard() )->ensure_can_modify_feature( $feature_option );
			}
			if ( null !== $blocked ) {
				Log::warning(
					'Inner feature update blocked: a related background process is running',
					array(
						'feature'         => 'features',
						'action'          => 'feature_inner_value_update_blocked',
						'feature_id'      => $feature_id,
						'feature_name'    => $feature_title,
						'feature_option'  => $feature_option,
						'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
					)
				);
				return $blocked;
			}

			// Update options.
			$this->update_options(
				$default_data['options'],
				$json_data['options'],
				$selected_data,
				$report_data,
				$processed_duplicates,
				$collected_value,
				$duplication_reports,
				$deletion_reports,
				$processed_reports
			);

			$db_data = array(
				'value' => wp_json_encode( $default_data ),
			);
			$where   = array(
				'id' => $feature_id,
			);

			$updated = $db_model->update_rows( $db_data, $where );
			if ( false === $updated ) {
				Log::error(
					'Inner feature update failed: Database update failed',
					array(
						'feature'        => 'features',
						'action'         => 'feature_inner_value_update_failed',
						'feature_id'     => $feature_id,
						'feature_name'   => $feature_title,
						'feature_option' => $feature_option,
						'error'          => 'Failed to update feature options in database',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Failed to update security feature options.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			// Invalidate cache after successful update.
			$this->invalidate_feature_cache( $feature['option'] );

			// Sync cron job if the main toggle (field_1) was changed.
			if ( $this->is_main_toggle_changed( $json_data['options'] ) ) {
				$this->sync_feature_cron( $feature['option'] );
			}

			// Refresh feature data.
			$feature = $db_model->get_data_by_id( $feature_id );

			// Recalculate security score for this feature immediately.
			( new SecurityFeaturesVerifyController() )->wptw_calculate_security_level( $feature );

			// Log successful update if there were changes.
			// Note: selected_data is now an array of structured arrays, duplicates are prevented by $processed_reports.
			if ( $report_data && ! empty( $selected_data ) ) {
				$change_count   = count( $selected_data );
				$change_noun    = ( 1 === $change_count ) ? 'setting' : 'settings';
				$update_impact  = FeatureNotificationMessagesController::get_impact_text( $feature_option, 'settings_updated' );
				$update_message = "You updated {$change_count} {$change_noun} for {$feature_title}. {$update_impact}";

				Log::info(
					$update_message,
					array(
						'feature'        => 'features',
						'action'         => 'feature_inner_value_update',
						'feature_id'     => $feature_id,
						'feature_name'   => $feature_title,
						'feature_option' => $feature_option,
						'changes'        => $selected_data,
						'title'          => "{$feature_title} settings updated",
						'meta_data'      => array(
							'event'   => 'Settings updated',
							'feature' => $feature_title,
							'impact'  => $update_impact,
							'feature_option' => $feature_option,
						),
					)
				);
			}

			// Log duplication actions.
			if ( ! empty( $duplication_reports ) ) {
				$unique_duplication_reports = array_unique( $duplication_reports );
				$dup_count                  = count( $unique_duplication_reports );
				$dup_noun                   = ( 1 === $dup_count ) ? 'entry' : 'entries';
				$dup_impact                 = FeatureNotificationMessagesController::get_impact_text( $feature_option, 'entries_duplicated' );
				$dup_message                = "You added {$dup_count} new {$dup_noun} to {$feature_title}. {$dup_impact}";

				Log::info(
					$dup_message,
					array(
						'feature'        => 'features',
						'action'         => 'feature_inner_value_duplicate',
						'feature_id'     => $feature_id,
						'feature_name'   => $feature_title,
						'feature_option' => $feature_option,
						'duplications'   => $unique_duplication_reports,
						'title'          => "{$feature_title} entries added",
						'meta_data'      => array(
							'event'   => 'Entries added',
							'feature' => $feature_title,
							'impact'  => $dup_impact,
							'feature_option' => $feature_option,
						),
					)
				);
			}

			// Log deletion actions.
			if ( ! empty( $deletion_reports ) ) {
				$unique_deletion_reports = array_unique( $deletion_reports );
				$del_count               = count( $unique_deletion_reports );
				$del_noun                = ( 1 === $del_count ) ? 'entry' : 'entries';
				$del_impact              = FeatureNotificationMessagesController::get_impact_text( $feature_option, 'entries_removed' );
				$del_message             = "You removed {$del_count} {$del_noun} from {$feature_title}. {$del_impact}";

				Log::info(
					$del_message,
					array(
						'feature'        => 'features',
						'action'         => 'feature_inner_value_delete',
						'feature_id'     => $feature_id,
						'feature_name'   => $feature_title,
						'feature_option' => $feature_option,
						'deletions'      => $unique_deletion_reports,
						'title'          => "{$feature_title} entries removed",
						'meta_data'      => array(
							'event'   => 'Entries removed',
							'feature' => $feature_title,
							'impact'  => $del_impact,
							'feature_option' => $feature_option,
						),
					)
				);
			}

			return array(
				'feature_data' => $feature,
				'message'      => __( 'Security feature options updated successfully.', 'tailwatch' ),
				'code'         => 200,
			);

		} catch ( \Throwable $e ) {
			// Log exception with full context.
			Log::error(
				'Inner feature update failed: Exception occurred',
				array(
					'feature'        => 'features',
					'action'         => 'feature_inner_value_update_failed',
					'feature_id'     => $feature_id,
					'feature_name'   => $feature_title ? $feature_title : 'Unknown',
					'feature_option' => $feature_option ? $feature_option : 'unknown',
					'error'          => $e->getMessage(),
					'exception'      => $e,
				)
			);

			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while updating feature options.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	private function update_options( &$defaultOptions, $newOptions, &$selectedData, &$reportData = null, &$processedDuplicates = null, &$collectedValue = array(), &$duplicationReports = array(), &$deletionReports = array(), &$processedReports = array() ) {

		if ( $processedDuplicates === null ) {
			$processedDuplicates = array();
		}

		try {
			foreach ( $newOptions as $optionKey => $option ) {
				$option_id = $option['id'];
				$selected  = isset( $option['selected'] ) ? $option['selected'] : null;
				$value     = isset( $option['value'] ) ? $option['value'] : null;

				$is_duplicate = isset( $option['is_duplicate'] ) ? $option['is_duplicate'] : false;
				$is_delete    = isset( $option['is_delete'] ) ? $option['is_delete'] : false;
				$is_hide      = isset( $option['is_hide'] ) ? $option['is_hide'] : false;
				$handle_hide  = isset( $option['handle_hide'] ) ? $option['handle_hide'] : false;

				// Process fields in default options
				foreach ( $defaultOptions as $key_name => &$field ) {
					if ( $field['id'] === $option_id ) {
						$option_duplicate = isset( $field['is_duplicate'] ) ? $field['is_duplicate'] : false;
						$option_delete    = isset( $field['is_delete'] ) ? $field['is_delete'] : false;

						// Handle deletion
						if ( $is_delete && $option_delete ) {
							// Record deletion for notification
							$field_label       = isset( $field['label'] ) ? $field['label'] : 'Field';
							$deletionReports[] = $field_label . ' was deleted';

							unset( $defaultOptions[ $key_name ] );
							continue;
						}

						if ( $is_duplicate && $option_duplicate && ! in_array( $option_id, $processedDuplicates ) ) {
							$processedDuplicates[] = $option_id;

							$newField = json_decode( wp_json_encode( $field ), true );

							$timestamp = round( microtime( true ) * 1000 );

							$this->update_field_keys_and_ids( $newField['sub_options'], $timestamp );

							$newId                   = preg_replace( '/_\d{13}$/', '', $option_id ) . '_' . $timestamp;
							$newField['id']          = $newId;
							$newField['key']         = $newId;
							$newField['is_delete']   = true;
							$newField['last_object'] = true;
							$newField['remove_btn']  = 'show';
							if ( $newField['type'] === 'toggleSwitch' ) {
								$newField['values']['option']['selected'] = false;
							}

							$defaultOptions[ 'field_' . $timestamp ] = $newField;

							// Record duplication for notification
							$field_label          = isset( $field['label'] ) ? $field['label'] : 'Field';
							$duplicationReports[] = $field_label . ' was duplicated';

							if ( $is_hide ) {
								$is_hide = 'true';
							} else {
								$is_hide = 'false';
							}
							$defaultOptions[ $key_name ]['hide'] = $is_hide;
							$field['last_object']                = false;
						}

						if ( in_array( $field['type'], array( 'checkbox', 'input', 'toggleSwitch', 'textarea', 'password', 'email', 'number', 'button', 'file', 'color' ) ) ) {
							if ( $selected !== null ) {

								if ( $handle_hide ) {
									$field['hide'] = $is_hide;
								}

								if ( $field['values']['option']['selected'] !== $selected ) {
									$current_selected                      = $field['values']['option']['selected'];
									$field['values']['option']['selected'] = $selected;

									// Advanced Structured Logging
									$report_key = $field['id'] . '_selected';
									if ( ! isset( $processedReports[ $report_key ] ) ) {
										$old_state_text = $current_selected ? 'Enabled' : 'Disabled';
										$new_state_text = $selected ? 'Enabled' : 'Disabled';

										$selectedData[]                  = array(
											'field_id'  => $field['id'],
											'label'     => isset( $field['label'] ) ? $field['label'] : 'Unknown Field',
											'type'      => $field['type'],
											'old_value' => $current_selected,
											'new_value' => $selected,
											'readable_change' => sprintf( '%s: Changed from %s to %s', $field['label'], $old_state_text, $new_state_text ),
										);
										$processedReports[ $report_key ] = true;
										$reportData                      = true;
									}
								} else {
									$field['values']['option']['selected'] = $selected;
								}
							}

							// Only process if value actually changed AND not null
							if ( $value !== null && isset( $field['values']['option']['value'] ) && $field['values']['option']['value'] !== $value ) {
								$current_value = $field['values']['option']['value'];

								// Encrypt password-type fields before storage.
								$store_value = $value;
								if ( $field['type'] === 'password' ) {
									$store_value = EncryptionService::is_encrypted( $value ) ? $value : EncryptionService::encrypt( $value );
								}
								$field['values']['option']['value'] = $store_value;

								if ( in_array( $field['type'], array( 'input', 'textarea', 'password', 'email', 'number', 'color' ) ) ) {
									$report_key = $field['id'] . '_value_' . md5( $value );

									if ( ! isset( $processedReports[ $report_key ] ) ) {
										$display_old = $current_value;
										$display_new = $value;

										if ( $field['type'] === 'password' ) {
											$display_old = '******';
											$display_new = '******';
										} elseif ( $field['type'] === 'textarea' ) {
											$display_old = strlen( (string) $current_value ) > 50 ? substr( (string) $current_value, 0, 50 ) . '...' : $current_value;
											$display_new = strlen( (string) $value ) > 50 ? substr( (string) $value, 0, 50 ) . '...' : $value;
										}

										$selectedData[]                  = array(
											'field_id'  => $field['id'],
											'label'     => isset( $field['label'] ) ? $field['label'] : 'Unknown Field',
											'type'      => $field['type'],
											'old_value' => ( $field['type'] === 'password' ) ? '******' : $current_value,
											'new_value' => ( $field['type'] === 'password' ) ? '******' : $value,
											'readable_change' => sprintf( '%s: Value changed from "%s" to "%s"', $field['label'], $display_old, $display_new ),
										);
										$processedReports[ $report_key ] = true;
										$reportData                      = true;
									}
								}
							} elseif ( $value !== null && ! isset( $field['values']['option']['value'] ) ) {
								// Handle case where value field doesn't exist yet
								$store_value = $value;
								if ( $field['type'] === 'password' ) {
									$store_value = EncryptionService::is_encrypted( $value ) ? $value : EncryptionService::encrypt( $value );
								}
								$field['values']['option']['value'] = $store_value;

								if ( in_array( $field['type'], array( 'input', 'textarea', 'password', 'email', 'number', 'color' ) ) ) {
									$report_key = $field['id'] . '_value_' . md5( $value );

									if ( ! isset( $processedReports[ $report_key ] ) ) {
										$display_new = $value;
										if ( $field['type'] === 'password' ) {
											$display_new = '******';
										} elseif ( $field['type'] === 'textarea' && strlen( (string) $value ) > 50 ) {
											$display_new = substr( (string) $value, 0, 50 ) . '...';
										}

										$selectedData[]                  = array(
											'field_id'  => $field['id'],
											'label'     => isset( $field['label'] ) ? $field['label'] : 'Unknown Field',
											'type'      => $field['type'],
											'old_value' => null,
											'new_value' => ( $field['type'] === 'password' ) ? '******' : $value,
											'readable_change' => sprintf( '%s: Value set to "%s"', $field['label'], $display_new ),
										);
										$processedReports[ $report_key ] = true;
										$reportData                      = true;
									}
								}
							}
						} elseif ( in_array( $field['type'], array( 'radio', 'select' ) ) ) {
							if ( isset( $field['duplicate_options_allowed'] ) && $field['duplicate_options_allowed'] === false ) {
								foreach ( $field['values'] as $radioOption ) {
									if ( $radioOption['value'] === $value ) {
										if ( $radioOption['value'] === $value && in_array( $radioOption['value'], $collectedValue ) ) {
											/* translators: %s: the duplicate option value */
											wp_send_json_error( array( 'message' => sprintf( __( 'Duplicate value found: %s', 'tailwatch' ), $radioOption['value'] ) ), 400 );
											return;
										}
									}
								}
							}

							// Normalize values to indexed array if associative
							$values   = $field['values'];
							$is_assoc = array_keys( $values ) !== range( 0, count( $values ) - 1 );
							if ( $is_assoc ) {
								$values = array_values( $values );
							}

							// Track changes for reporting
							$previousSelection = null;
							$newSelection      = null;

							foreach ( $values as $i => &$radioOption ) {

								// Check previous selection
								if ( isset( $radioOption['selected'] ) && $radioOption['selected'] === true ) {
									$previousSelection = isset( $radioOption['label'] ) ? $radioOption['label'] : $radioOption['value'];
								}

								if ( $radioOption['value'] === $value ) {
									$radioOption['selected'] = true;
									$newSelection            = isset( $radioOption['label'] ) ? $radioOption['label'] : $radioOption['value'];

									// Update sub-options if they exist
									if ( isset( $radioOption['sub_options'] ) && is_array( $radioOption['sub_options'] ) ) {
										$this->update_options(
											$radioOption['sub_options'],
											$newOptions,
											$selectedData,
											$reportData,
											$processedDuplicates,
											$collectedValue,
											$duplicationReports,
											$deletionReports,
											$processedReports
										);
									}
								} else {
									$radioOption['selected'] = false;
								}
								// Write back to the original array
								if ( $is_assoc ) {
									$assocKeys                           = array_keys( $field['values'] );
									$field['values'][ $assocKeys[ $i ] ] = $radioOption;
								} else {
									$field['values'][ $i ] = $radioOption;
								}
							}
							unset( $radioOption );

							// Only add report if there was actually a change
							if ( $previousSelection !== $newSelection && $newSelection !== null ) {

								// Use field ID + new value as key to prevent duplicates
								$report_key = $field['id'] . '_radio_' . md5( $newSelection );
								if ( ! isset( $processedReports[ $report_key ] ) ) {
									$selectedData[]                  = array(
										'field_id'        => $field['id'],
										'label'           => isset( $field['label'] ) ? $field['label'] : 'Unknown Field',
										'type'            => $field['type'],
										'old_value'       => $previousSelection,
										'new_value'       => $newSelection,
										'readable_change' => sprintf( '%s: Changed from "%s" to "%s"', $field['label'], $previousSelection ? $previousSelection : 'None', $newSelection ),
									);
									$processedReports[ $report_key ] = true;
									$reportData                      = true;
								}
							}
						} elseif ( in_array( $field['type'], array( 'multiple_checkbox' ) ) ) {

							if ( isset( $option['options'] ) && is_array( $option['options'] ) ) {

								$values   = $field['values'];
								$is_assoc = array_keys( $values ) !== range( 0, count( $values ) - 1 );

								if ( $is_assoc ) {
									// Fast path: direct key lookup
									foreach ( $option['options'] as $updateOpt ) {
										if ( isset( $updateOpt['option'] ) && isset( $field['values'][ $updateOpt['option'] ] ) ) {
											$key           = $updateOpt['option'];
											$previousState = isset( $field['values'][ $key ]['selected'] ) ? $field['values'][ $key ]['selected'] : false;
											$newState      = isset( $updateOpt['selected'] ) ? $updateOpt['selected'] : false;

											if ( $previousState !== $newState ) {
												$field['values'][ $key ]['selected'] = $newState;
												$label                               = isset( $field['values'][ $key ]['label'] ) ? $field['values'][ $key ]['label'] : $field['values'][ $key ]['value'];

												$old_text = $previousState ? 'Enabled' : 'Disabled';
												$new_text = $newState ? 'Enabled' : 'Disabled';

												$report_key = $field['id'] . '_checkbox_' . $key . '_' . ( $newState ? '1' : '0' );
												if ( ! isset( $processedReports[ $report_key ] ) ) {
													$selectedData[]                  = array(
														'field_id'        => $field['id'],
														'sub_option_key'  => $key,
														'label'           => $label,
														'type'            => 'multiple_checkbox',
														'old_value'       => $previousState,
														'new_value'       => $newState,
														'readable_change' => sprintf( 'Changed "%s" from %s to %s', $label, $old_text, $new_text ),
													);
													$processedReports[ $report_key ] = true;
													$reportData                      = true;
												}
											}
										}
									}
								} else {
									// Fallback: match by value
									foreach ( $option['options'] as $updateOpt ) {
										foreach ( $field['values'] as &$checkboxOption ) {
											if (
												( isset( $checkboxOption['option'] ) && isset( $updateOpt['option'] ) && $checkboxOption['option'] === $updateOpt['option'] ) ||
												( isset( $checkboxOption['value'] ) && isset( $updateOpt['value'] ) && $checkboxOption['value'] === $updateOpt['value'] )
											) {
												$previousState = isset( $checkboxOption['selected'] ) ? $checkboxOption['selected'] : false;
												$newState      = isset( $updateOpt['selected'] ) ? $updateOpt['selected'] : false;

												if ( $previousState !== $newState ) {
													$checkboxOption['selected'] = $newState;
													$label                      = isset( $checkboxOption['label'] ) ? $checkboxOption['label'] : $checkboxOption['value'];

													$old_text = $previousState ? 'Enabled' : 'Disabled';
													$new_text = $newState ? 'Enabled' : 'Disabled';

													$identifier = isset( $checkboxOption['option'] ) ? $checkboxOption['option'] : $checkboxOption['value'];
													$report_key = $field['id'] . '_checkbox_' . md5( $identifier ) . '_' . ( $newState ? '1' : '0' );

													if ( ! isset( $processedReports[ $report_key ] ) ) {
														$selectedData[]                  = array(
															'field_id'        => $field['id'],
															'sub_option_id'   => $identifier,
															'label'           => $label,
															'type'            => 'multiple_checkbox',
															'old_value'       => $previousState,
															'new_value'       => $newState,
															'readable_change' => sprintf( 'Changed "%s" from %s to %s', $label, $old_text, $new_text ),
														);
														$processedReports[ $report_key ] = true;
														$reportData                      = true;
													}
												}
											}
										}
									}
									unset( $checkboxOption );
								}
							}
						}

						// Recursively update sub-options if they exist
						if ( isset( $option['sub_options'] ) && is_array( $option['sub_options'] ) ) {

							if ( ! isset( $field['sub_options'] ) ) {
								$field['sub_options'] = array();
							}
							$this->update_options(
								$field['sub_options'],
								$option['sub_options'],
								$selectedData,
								$reportData,
								$processedDuplicates,
								$collectedValue,
								$duplicationReports,
								$deletionReports,
								$processedReports
							);
						}
					} elseif ( isset( $field['sub_options'] ) && is_array( $field['sub_options'] ) ) {

						// Recursively check and update sub-options of current field
						if ( is_array( $field['sub_options'] ) ) {
							$this->update_options(
								$field['sub_options'],
								$newOptions,
								$selectedData,
								$reportData,
								$processedDuplicates,
								$collectedValue,
								$duplicationReports,
								$deletionReports,
								$processedReports
							);
						}

						// Process sub-options within values if they exist
						if ( isset( $field['values'] ) && is_array( $field['values'] ) ) {
							foreach ( $field['values'] as &$valueOption ) {
								if ( isset( $valueOption['sub_options'] ) && is_array( $valueOption['sub_options'] ) ) {
									$this->update_options(
										$valueOption['sub_options'],
										$newOptions,
										$selectedData,
										$reportData,
										$processedDuplicates,
										$collectedValue,
										$duplicationReports,
										$deletionReports,
										$processedReports
									);
								}
							}
						}
					} elseif ( isset( $field['values'] ) && is_array( $field['values'] ) ) {
						foreach ( $field['values'] as &$valueOption ) {
							if ( isset( $valueOption['sub_options'] ) && is_array( $valueOption['sub_options'] ) ) {
								$this->update_options(
									$valueOption['sub_options'],
									$newOptions,
									$selectedData,
									$reportData,
									$processedDuplicates,
									$collectedValue,
									$duplicationReports,
									$deletionReports,
									$processedReports
								);
							}
						}
					}
				}
			}

			// After processing all updates, set the last object
			$this->set_last_object( $defaultOptions );

		} catch ( \Exception $e ) {
			Log::error(
				'Feature options update error',
				array(
					'action' => 'update_options_recursive',
					'error'  => $e->getMessage(),
					'trace'  => $e->getTraceAsString(),
				)
			);
		}
	}

	/**
	 * Collect selected values from options.
	 *
	 * Recursively traverses options to collect selected values for
	 * fields that don't allow duplicates.
	 *
	 * @param array $options         Array of option fields.
	 * @param array $selected_values Reference to array of collected values.
	 *
	 * @return array Array of selected values keyed by field ID.
	 */
	public function collect_selected_values( $options, &$selected_values = array() ) {
		foreach ( $options as $field ) {
			if ( ! isset( $field['display'] ) ) {
				continue;
			}

			$is_select_or_radio = isset( $field['type'] ) && in_array( $field['type'], array( 'select', 'radio' ), true );
			$no_duplicates      = isset( $field['duplicate_options_allowed'] ) && false === $field['duplicate_options_allowed'];

			if ( $is_select_or_radio && $no_duplicates ) {
				foreach ( $field['values'] as $option ) {
					if ( isset( $option['selected'] ) && true === $option['selected'] ) {
						$selected_values[ $field['id'] ] = $option['value'];
						break;
					}
				}
			}

			if ( isset( $field['sub_options'] ) && is_array( $field['sub_options'] ) ) {
				$this->collect_selected_values( $field['sub_options'], $selected_values );
			}

			if ( isset( $field['values'] ) && is_array( $field['values'] ) ) {
				foreach ( $field['values'] as $value_option ) {
					if ( isset( $value_option['sub_options'] ) && is_array( $value_option['sub_options'] ) ) {
						$this->collect_selected_values( $value_option['sub_options'], $selected_values );
					}
				}
			}
		}

		return $selected_values;
	}

	/**
	 * Check for duplicate role selection in options.
	 *
	 * @param array $new_options Array of new option values.
	 *
	 * @return string|null Error message if duplicates found, null otherwise.
	 */
	public function wptw_check_duplicate_selection( $new_options ) {
		$prevent_duplicate_prefix = array( 'user_role_selection' );
		$selected_values          = array();

		// Collect values for fields matching prevent_duplicate_prefix.
		foreach ( $new_options as $option ) {
			foreach ( $prevent_duplicate_prefix as $prefix ) {
				if ( 0 === strpos( $option['id'], $prefix ) && isset( $option['value'] ) ) {
					$selected_values[ $option['id'] ] = $option['value'];
				}
			}
		}

		// Check for duplicates.
		$value_counts = array_count_values( $selected_values );
		foreach ( $value_counts as $val => $count ) {
			if ( $count > 1 ) {
				$duplicate_ids = array_keys(
					array_filter(
						$selected_values,
						function ( $v ) use ( $val ) {
							return $v === $val;
						}
					)
				);
				return sprintf(
					"Duplicate role selection detected for '%s' in fields: %s",
					$val,
					implode( ', ', $duplicate_ids )
				);
			}
		}

		return null;
	}

	/**
	 * Set last_object flag on options array.
	 *
	 * Marks the last item in the options array and updates hide flags.
	 *
	 * @param array $options Reference to options array.
	 *
	 * @return void
	 */
	public function set_last_object( &$options ) {
		$keys     = array_keys( $options );
		$last_key = end( $keys );

		foreach ( $options as $key => &$field ) {
			if ( isset( $field['last_object'] ) ) {
				if ( $key === $last_key ) {
					$field['last_object'] = true;
					$field['hide']        = 'false';
				} else {
					$field['last_object'] = false;
					$field['hide']        = 'true';
				}
			}
		}
	}

	/**
	 * Update field keys and IDs with new timestamp.
	 *
	 * Used when duplicating fields to ensure unique identifiers.
	 *
	 * @param array $fields    Reference to fields array.
	 * @param int   $timestamp Timestamp to append to IDs.
	 *
	 * @return void
	 */
	public function update_field_keys_and_ids( &$fields, $timestamp ) {
		if ( ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as &$field ) {
			if ( isset( $field['id'] ) ) {
				$field['id']  = preg_replace( '/_\d{13}$/', '', $field['id'] ) . '_' . $timestamp;
				$field['key'] = preg_replace( '/_\d{13}$/', '', $field['key'] ) . '_' . $timestamp;
			}

			// Reset values.
			if ( isset( $field['type'] ) ) {
				if ( in_array( $field['type'], array( 'input', 'textarea' ), true ) ) {
					$field['values']['option']['value'] = '';
				}

				if ( in_array( $field['type'], array( 'toggleSwitch', 'checkbox', 'radio', 'select' ), true ) ) {
					foreach ( $field['values'] as &$option ) {
						$option['selected'] = false;

						if ( ! empty( $option['sub_options'] ) ) {
							$this->update_field_keys_and_ids( $option['sub_options'], $timestamp );
						}
					}
				}
			}

			if ( ! empty( $field['sub_options'] ) ) {
				$this->update_field_keys_and_ids( $field['sub_options'], $timestamp );
			}
		}
	}

	/**
	 * Update push notification value for a feature option.
	 *
	 * @param string $post_data JSON string of request data.
	 *
	 * @return array Response array with feature_data, message, and code.
	 */
	public function wptw_update_push_notification_value( $post_data ) {
		$feature_id     = null;
		$feature_title  = null;
		$feature_option = null;
		$field_id       = null;

		try {
			$data_value = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$json_data  = json_decode( $data_value, true, 512, JSON_THROW_ON_ERROR );

			if ( ! isset( $json_data['feature_id'], $json_data['id'], $json_data['push_notification'] ) ) {
				Log::warning(
					'Push notification update failed: Invalid input data',
					array(
						'feature' => 'features',
						'action'  => 'push_notification_update_failed',
						'error'   => 'Missing required fields: feature_id, id, or push_notification',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'Invalid input data.', 'tailwatch' ),
					'code'         => 400,
				);
			}

			$feature_id              = absint( $json_data['feature_id'] );
			$field_id                = sanitize_text_field( $json_data['id'] );
			$push_notification_value = $json_data['push_notification'];

			$db_model = new DBModel();
			$value    = $db_model->get_feature_value( $feature_id );

			if ( empty( $value ) ) {
				Log::error(
					'Push notification update failed: Feature data not found',
					array(
						'feature'    => 'features',
						'action'     => 'push_notification_update_failed',
						'feature_id' => $feature_id,
						'field_id'   => $field_id,
						'error'      => 'No data found for the specified feature ID',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'No data found for the specified feature ID.', 'tailwatch' ),
					'code'         => 404,
				);
			}

			$data = json_decode( $value, true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) ) {
				Log::error(
					'Push notification update failed: Invalid data format',
					array(
						'feature'    => 'features',
						'action'     => 'push_notification_update_failed',
						'feature_id' => $feature_id,
						'field_id'   => $field_id,
						'error'      => 'Invalid data format in the database',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'Invalid data format in the database.', 'tailwatch' ),
					'code'         => 500,
				);
			}

			$feature_title  = isset( $data['title'] ) ? $data['title'] : 'Unknown Feature';
			$feature_option = isset( $data['option'] ) ? $data['option'] : 'unknown';

			$updated = $this->update_push_notification_recursive( $data['options'], $field_id, $push_notification_value );
			if ( ! $updated ) {
				Log::error(
					'Push notification update failed: Field ID not found',
					array(
						'feature'        => 'features',
						'action'         => 'push_notification_update_failed',
						'feature_id'     => $feature_id,
						'feature_name'   => $feature_title,
						'feature_option' => $feature_option,
						'field_id'       => $field_id,
						'error'          => 'ID not found in the data',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'ID not found in the data.', 'tailwatch' ),
					'code'         => 404,
				);
			}

			$db_data = array(
				'value' => wp_json_encode( $data ),
			);
			$where   = array(
				'id' => $feature_id,
			);

			$updated      = $db_model->update_rows( $db_data, $where );
			$feature_data = $db_model->get_data_by_id( $feature_id );

			if ( false === $updated ) {
				Log::error(
					'Push notification update failed: Database update failed',
					array(
						'feature'                 => 'features',
						'action'                  => 'push_notification_update_failed',
						'feature_id'              => $feature_id,
						'feature_name'            => $feature_title,
						'feature_option'          => $feature_option,
						'field_id'                => $field_id,
						'push_notification_value' => $push_notification_value,
						'error'                   => 'Failed to update push notification value in database',
					)
				);
				return array(
					'feature_data' => array(),
					'message'      => __( 'Failed to update push notification value.', 'tailwatch' ),
					'code'         => 500,
				);
			}

			// Invalidate cache after successful update.
			if ( ! empty( $feature_data['option'] ) ) {
				$this->invalidate_feature_cache( $feature_data['option'] );
			}

			// Log successful push notification update.
			Log::info(
				"Push notification value updated: {$feature_title} - Field ID: {$field_id}",
				array(
					'feature'                 => 'features',
					'action'                  => 'push_notification_update',
					'feature_id'              => $feature_id,
					'feature_name'            => $feature_title,
					'feature_option'          => $feature_option,
					'field_id'                => $field_id,
					'push_notification_value' => $push_notification_value,
					'title'                   => 'Push Notification Updated',
				)
			);

			return array(
				'feature_data' => $feature_data,
				'message'      => __( 'Push notification value updated successfully.', 'tailwatch' ),
				'code'         => 200,
			);
		} catch ( \Throwable $e ) {
			// Log exception with full context.
			Log::error(
				'Push notification update failed: Exception occurred',
				array(
					'feature'        => 'features',
					'action'         => 'push_notification_update_failed',
					'feature_id'     => $feature_id,
					'feature_name'   => $feature_title ? $feature_title : 'Unknown',
					'feature_option' => $feature_option ? $feature_option : 'unknown',
					'field_id'       => $field_id,
					'error'          => $e->getMessage(),
					'exception'      => $e,
				)
			);

			return array(
				'feature_data' => array(),
				'message'      => __( 'An error occurred while updating push notification value.', 'tailwatch' ),
				'code'         => 500,
			);
		}
	}

	/**
	 * Recursively update push notification value in data array.
	 *
	 * @param array  $data                    Reference to data array.
	 * @param string $id                      Target item ID.
	 * @param mixed  $push_notification_value New push notification value.
	 *
	 * @return bool True if item was found and updated.
	 */
	private function update_push_notification_recursive( &$data, $id, $push_notification_value ) {
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $id ) {
				$item['push_notification'] = $push_notification_value;
				return true;
			}
		}
		return false;
	}
}
