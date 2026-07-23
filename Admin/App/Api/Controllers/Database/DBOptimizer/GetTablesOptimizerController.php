<?php
/**
 * Get Tables Optimizer Controller
 *
 * Handles database optimization status checks and table/count retrieval.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Database\DBOptimizer
 */

namespace Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer;

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Services\Time\TimeService;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GetTablesOptimizerController
 *
 */
class GetTablesOptimizerController {

	public function wptw_check_db_optimization_status() {
		try {
			$database_clean = new DatabaseOptimizerController();
			$database_rule  = new TablesOptimizeController();
			$get_options    = $database_clean->wptw_db_optimize_options();

			$is_enabled = $database_clean->wptw_db_optimizer_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$number_interval            = null;
			$orphaned_post_counts       = 0;
			$auto_drafts_counts         = 0;
			$trashed_post_counts        = 0;
			$spam_comments_counts       = 0;
			$trashed_comments_counts    = 0;
			$trackback_pingbacks_counts = 0;
			$expired_transients_counts  = 0;
			$all_logs_activity_count    = 0;
			$all_email_logs_count       = 0;
			$all_ajax_logs_count        = 0;
			$all_monitoring_logs_count  = 0;

			if ( isset( $get_options['field_1']['sub_options']['field_3']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_3']['options']['option']['selected'] ) {
				$orphaned_post        = $database_rule->wptw_get_orphaned_post( $number_interval );
				$orphaned_post_counts = count( $orphaned_post );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_4']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_4']['options']['option']['selected'] ) {
				$auto_drafts        = $database_rule->wptw_get_drafts_post( $number_interval );
				$auto_drafts_counts = count( $auto_drafts );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_5']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_5']['options']['option']['selected'] ) {
				$trashed_post        = $database_rule->wptw_get_trash_posts( $number_interval );
				$trashed_post_counts = count( $trashed_post );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_6']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_6']['options']['option']['selected'] ) {
				$spam_comments        = $database_rule->wptw_get_spam_comments( $number_interval );
				$spam_comments_counts = count( $spam_comments );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_7']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_7']['options']['option']['selected'] ) {
				$trashed_comments        = $database_rule->wptw_get_trash_comments( $number_interval );
				$trashed_comments_counts = count( $trashed_comments );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_8']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_8']['options']['option']['selected'] ) {
				$trackback_pingbacks        = $database_rule->wptw_get_trackback_pingbacks( $number_interval );
				$trackback_pingbacks_counts = count( $trackback_pingbacks );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_9']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_9']['options']['option']['selected'] ) {
				$expired_transients        = $database_rule->wptw_get_expired_transients( $number_interval );
				$expired_transients_counts = count( $expired_transients );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_11']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_11']['options']['option']['selected'] ) {
				$all_logs_activity       = $database_rule->wptw_get_logs( 'default_logs_activity', $number_interval );
				$all_logs_activity_count = count( $all_logs_activity );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_12']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_12']['options']['option']['selected'] ) {
				$all_email_logs_data  = $database_rule->wptw_get_logs( 'default_email_logs', $number_interval );
				$all_email_logs_count = count( $all_email_logs_data );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_13']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_13']['options']['option']['selected'] ) {
				$all_ajax_logs_data  = $database_rule->wptw_get_logs( 'default_ajax_logs', $number_interval );
				$all_ajax_logs_count = count( $all_ajax_logs_data );
			}

			if ( isset( $get_options['field_1']['sub_options']['field_14']['options']['option']['selected'] ) && $get_options['field_1']['sub_options']['field_14']['options']['option']['selected'] ) {
				$all_monitoring_logs_data  = $database_rule->wptw_get_logs( 'default_monitoring_logs', $number_interval );
				$all_monitoring_logs_count = count( $all_monitoring_logs_data );
			}

			if (
				0 === $orphaned_post_counts && 0 === $auto_drafts_counts && 0 === $trashed_post_counts
				&& 0 === $spam_comments_counts && 0 === $trashed_comments_counts && 0 === $trackback_pingbacks_counts
				&& 0 === $expired_transients_counts && 0 === $all_logs_activity_count
				&& 0 === $all_email_logs_count && 0 === $all_ajax_logs_count && 0 === $all_monitoring_logs_count
			) {

				return array(
					'is_disabled' => true,
					'message'     => __( 'Database already optimized', 'tailwatch' ),
					'code'        => 200,
				);
			} else {
				return array(
					'is_disabled' => false,
					'message'     => __( 'here is data to optimize in the database', 'tailwatch' ),
					'code'        => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_optimize_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Failed to check database optimization status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function wptw_db_optimize_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_database_optimizer';
		$is_active          = true;
		$options_controller = new OptionsController();

		$db_data = $options_controller->get_features_options( $key, $option, $is_active );

		if ( $db_data ) {
			$enable_optimize = isset( $db_data['field_1']['options']['option']['selected'] ) ? $db_data['field_1']['options']['option']['selected'] : null;

			$database_interval  = 'Every 48 Hours';
			$revisions_maintain = 0;

			$orphaned_post_meta = isset( $db_data['field_1']['sub_options']['field_3']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_3']['options']['option']['selected'] : false;

			$auto_drafts = isset( $db_data['field_1']['sub_options']['field_4']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_4']['options']['option']['selected'] : false;

			$trashed_posts = isset( $db_data['field_1']['sub_options']['field_5']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_5']['options']['option']['selected'] : false;

			$spam_comments = isset( $db_data['field_1']['sub_options']['field_6']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_6']['options']['option']['selected'] : false;

			$trashed_comments = isset( $db_data['field_1']['sub_options']['field_7']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_7']['options']['option']['selected'] : false;

			$trackbacks_pingbacks = isset( $db_data['field_1']['sub_options']['field_8']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_8']['options']['option']['selected'] : false;

			$expired_transient = isset( $db_data['field_1']['sub_options']['field_9']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_9']['options']['option']['selected'] : false;


			$logs_activity = isset( $db_data['field_1']['sub_options']['field_11']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_11']['options']['option']['selected'] : false;

			$email_logs = isset( $db_data['field_1']['sub_options']['field_12']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_12']['options']['option']['selected'] : false;

			$ajax_logs = isset( $db_data['field_1']['sub_options']['field_13']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_13']['options']['option']['selected'] : false;

			$monitoring_logs = isset( $db_data['field_1']['sub_options']['field_14']['options']['option']['selected'] ) ? $db_data['field_1']['sub_options']['field_14']['options']['option']['selected'] : false;

			if ( isset( $db_data['field_1']['sub_options']['field_15']['options'] ) ) {
				foreach ( $db_data['field_1']['sub_options']['field_15']['options'] as $option ) {
					if ( isset( $option['selected'] ) && 1 === (int) $option['selected'] ) {
						$database_interval = $option['value'];
					}
				}
			}

			if ( isset( $db_data['field_1']['sub_options']['field_16']['options'] ) ) {
				foreach ( $db_data['field_1']['sub_options']['field_16']['options'] as $maintain_option ) {
					if ( isset( $maintain_option['selected'] ) && true === $maintain_option['selected'] ) {
						if ( 'Select Days' === $maintain_option['value'] && isset( $maintain_option['sub_options']['field_17']['options']['option']['value'] ) ) {
							$revisions_field    = $maintain_option['sub_options']['field_17']['options']['option']['value'];
							$revisions_maintain = $revisions_field . ' days';
						} else {
							$revisions_maintain = $maintain_option['value'];
						}
					}
				}
			}

			$response_data = array(
				'optimizerEnabled'     => $enable_optimize,
				'orphanedPostMeta'     => $orphaned_post_meta,
				'autoDrafts'           => $auto_drafts,
				'trashedPosts'         => $trashed_posts,
				'spamComments'         => $spam_comments,
				'trashedComments'      => $trashed_comments,
				'trackbacks_pingbacks' => $trackbacks_pingbacks,
				'expiredTransient'     => $expired_transient,
				'logsActivity'         => $logs_activity,
				'emailLogs'            => $email_logs,
				'ajaxLogs'             => $ajax_logs,
				'monitoringLogs'       => $monitoring_logs,
				'databaseInterval'     => $database_interval,
				'keepRevision'         => $revisions_maintain,
			);
		} else {
			$response_data = array();
		}
		return $response_data;
	}

	public function wptw_get_db_optimizer_status() {
		try {
			$database_clean = new DatabaseOptimizerController();
			$is_enabled     = $database_clean->wptw_db_optimizer_feature_enable();

			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Database Optimize feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			global $wpdb;

			$options         = $this->wptw_db_optimize_options();
			$database_rule   = new TablesOptimizeController();
			$number_interval = null;

			$step_metadata = array(
				'auto_drafts'          => array( 'table' => $wpdb->posts,                          'options_key' => 'autoDrafts' ),
				'trashed_posts'        => array( 'table' => $wpdb->posts,                          'options_key' => 'trashedPosts' ),
				'spam_comments'        => array( 'table' => $wpdb->comments,                       'options_key' => 'spamComments' ),
				'trashed_comments'     => array( 'table' => $wpdb->comments,                       'options_key' => 'trashedComments' ),
				'trackbacks_pingbacks' => array( 'table' => $wpdb->comments,                       'options_key' => 'trackbacks_pingbacks' ),
				'orphaned_post_meta'   => array( 'table' => $wpdb->postmeta,                       'options_key' => 'orphanedPostMeta' ),
				'expired_transients'   => array( 'table' => $wpdb->options,                        'options_key' => 'expiredTransient' ),
				'logs_activity'        => array( 'table' => $wpdb->prefix . WPTW_DB_TABLE_NAME,    'options_key' => 'logsActivity' ),
				'email_logs'           => array( 'table' => $wpdb->prefix . WPTW_DB_TABLE_NAME,    'options_key' => 'emailLogs' ),
				'ajax_logs'            => array( 'table' => $wpdb->prefix . WPTW_DB_TABLE_NAME,    'options_key' => 'ajaxLogs' ),
				'monitoring_logs'      => array( 'table' => $wpdb->prefix . WPTW_DB_TABLE_NAME,    'options_key' => 'monitoringLogs' ),
			);

			// Allow extensions to add or modify cleanup steps.
			$step_metadata = apply_filters( 'wptw_db_optimize_step_metadata', $step_metadata );

			$steps = array();
			foreach ( $step_metadata as $step_key => $meta ) {
				$steps[ $step_key ] = array(
					'table'         => $meta['table'],
					'selected'      => ! empty( $options[ $meta['options_key'] ] ),
					'pending_count' => $this->get_step_count( $step_key, $database_rule, $number_interval ),
				);
			}

			// Allow extensions to augment per-step metadata after the base
			// shape is built (e.g. pro can add its own UI hints).
			$steps = (array) apply_filters( 'wptw_db_optimize_steps', $steps );

			$next_scheduled = wp_next_scheduled( 'wptw_start_database_optimization' );
			$current_time   = time();

			$schedule = array(
				'interval'           => $options['databaseInterval'] ?? 'N/A',
				'keep_revisions_for' => $options['keepRevision'] ?? 'N/A',
				'next_schedule'      => $next_scheduled ? gmdate( 'Y-m-d H:i:s', $next_scheduled ) : 'Not Scheduled',
				'next_run'           => TimeService::format_time_remaining( $next_scheduled, $current_time, __( 'Running now', 'tailwatch' ) ),
			);

			return array(
				'code'              => 200,
				'message'           => __( 'Data Retrieved Successfully', 'tailwatch' ),
				'optimizer_enabled' => ! empty( $options['optimizerEnabled'] ),
				'schedule'          => $schedule,
				'steps'             => $steps,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'database_optimizer',
					'action'    => 'database_optimizer_get_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to retrieve database optimizer status.', 'tailwatch' ),
			);
		}
	}

	private function get_step_count( $step_key, $database_rule, $number_interval = null ) {
		switch ( $step_key ) {
			case 'auto_drafts':
				return count( $database_rule->wptw_get_drafts_post( $number_interval ) );
			case 'trashed_posts':
				return count( $database_rule->wptw_get_trash_posts( $number_interval ) );
			case 'spam_comments':
				return count( $database_rule->wptw_get_spam_comments( $number_interval ) );
			case 'trashed_comments':
				return count( $database_rule->wptw_get_trash_comments( $number_interval ) );
			case 'trackbacks_pingbacks':
				return count( $database_rule->wptw_get_trackback_pingbacks( $number_interval ) );
			case 'orphaned_post_meta':
				return count( $database_rule->wptw_get_orphaned_post( $number_interval ) );
			case 'expired_transients':
				return count( $database_rule->wptw_get_expired_transients( $number_interval ) );
			case 'logs_activity':
				return count( $database_rule->wptw_get_logs( 'default_logs_activity', $number_interval ) );
			case 'email_logs':
				return count( $database_rule->wptw_get_logs( 'default_email_logs', $number_interval ) );
			case 'ajax_logs':
				return count( $database_rule->wptw_get_logs( 'default_ajax_logs', $number_interval ) );
			case 'monitoring_logs':
				return count( $database_rule->wptw_get_logs( 'default_monitoring_logs', $number_interval ) );
			default:
				// Unknown step keys fall through to the filter so extensions
				// can supply counts for cleanup types they add (e.g., via the
				// wptw_db_optimize_step_metadata filter).
				return (int) apply_filters( 'wptw_db_optimize_step_count', 0, $step_key, array( 'number_interval' => $number_interval ) );
		}
	}
}