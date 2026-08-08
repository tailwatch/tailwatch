<?php
namespace Tailwatch\Admin\App\Api\Controllers\SearchReplace;

use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\SearchReplaceModel;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Replace Controller
 *
 * Handles search-replace process, cron, cancel/pause, and live logs.
 *
 */
class SearchReplaceController extends BaseController {

	/**
	 * Batch size for search-replace queries.
	 *
	 * @var int
	 */
	private $batch_size = 500;

	/**
	 * Logs directory path for search-replace.
	 *
	 * @var string
	 */
	private $logs_directory = TAILWATCH_LOGS_DIRECTORY . '/search-replace/';

	/**
	 * Live logs JSON file path.
	 *
	 * @var string
	 */
	private $get_live_logs = TAILWATCH_LOGS_DIRECTORY . '/search-replace/search_replace_logs.json';

	/**
	 * Search-replace tables count log file path.
	 *
	 * @var string
	 */
	private $search_replace_count = TAILWATCH_LOGS_DIRECTORY . '/search-replace/search_replace_tables_count.json';

	/**
	 * Process manager instance for monitoring.
	 *
	 * @var \Tailwatch\Admin\App\Api\Services\ProcessManager
	 */
	private $process_manager;

	/**
	 * Constructor. Registers process and cron hooks for search-replace.
	 *
	 */
	public function __construct() {
		$this->process_manager = new ProcessManager();

		ProcessManager::register_process(
			array(
				'process_type'    => 'search_replace',
				'cron_hooks'      => array( 'tailwatch_search_replace_cron' ),
				'data_source'     => 'wp_tw_settings',
				'data_key'        => 'default_search_replace',
				'data_option'     => 'tailwatch_search_replace',
				'stuck_threshold' => 300,
				'max_retries'     => 3,
				'description'     => 'Search & Replace Process',
				'locks_features'  => array( 'default_search_replace' ),
				// Process types that, when running, prevent a user from starting
				// a manual search & replace. SR writes site-wide DB rows so it
				// cannot run alongside any other heavy DB-touching process.
				'cannot_start_while' => array(
					'backup',
					'malware_scan',
					'malware_restore',
					'files_integrity',
					'migration',
					'restore',
					// System-level settings operations rewrite feature config
					// site-wide; search_replace must wait for them to finish.
					'settings_import',
					'reset_all',
				),
			)
		);

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'tailwatch_search_replace_cron', array( $this, 'tailwatch_search_and_replace_with' ) );
		$hook_controller->add_action_hook( 'tailwatch_update_table_status_on_cancel_cron', array( $this, 'tailwatch_update_table_status_on_cancel' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'tailwatch_search_replace_feature' ) );
	}

	/**
	 * Gets feature options for search-replace (wrapper for OptionsController).
	 *
	 * @return array|false Feature options or false.
	 */
	private function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_search_replace';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Gets the current search-replace state from the database.
	 *
	 * @return array Search-replace data.
	 */
	public function tailwatch_get_search_replace_data() {
		$feature_controller = new DBModel();
		$tailwatch_key           = 'default_search_replace';
		$option             = 'tailwatch_search_replace';
		return $feature_controller->get_recent_data( $option, $tailwatch_key );
	}

	/**
	 * Whether push notification is enabled for search-replace feature.
	 *
	 * @return bool
	 */
	public function search_replace_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_search_replace';
		$field_name        = 'field_1';
		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Gets selected search-replace options (search, replace, tables, flags) from stored data.
	 *
	 * @return array Associative array with search, replace, dry_run, guid, case_insensitive, all_tables.
	 */
	public function tailwatch_get_selected_options() {
		$existing_data = $this->tailwatch_get_search_replace_data();

		$all_tables       = isset( $existing_data['all_tables'] ) ? $existing_data['all_tables'] : array();
		$search           = isset( $existing_data['search'] ) ? $existing_data['search'] : '';
		$replace          = isset( $existing_data['replace'] ) ? $existing_data['replace'] : '';
		$dry_run          = isset( $existing_data['dry_run'] ) ? (bool) $existing_data['dry_run'] : false;
		$guid             = isset( $existing_data['guid'] ) ? (bool) $existing_data['guid'] : false;
		$case_insensitive = isset( $existing_data['case_insensitive'] ) ? (bool) $existing_data['case_insensitive'] : false;

		$table_names = array_keys( $all_tables );

		return array(
			'search'           => $search,
			'replace'          => $replace,
			'dry_run'          => $dry_run,
			'guid'             => $guid,
			'case_insensitive' => $case_insensitive,
			'all_tables'       => $table_names,
		);
	}

	/**
	 * Gets cancel/pause state for the current search-replace run.
	 *
	 * @return array Cancel/pause data from options.
	 */
	public function search_replace_cancel_pause_data() {
		$feature_controller = new DBModel();
		$tailwatch_key           = 'default_search_replace';
		$option             = 'search_replace_pause_cancel';
		return $feature_controller->get_recent_data( $option, $tailwatch_key );
	}

	/**
	 * Whether the search-replace feature is enabled (parent and option).
	 *
	 * @return array With keys parent_enable, feature_enable (booleans).
	 */
	public function tailwatch_search_replace_feature_enable() {
		$feature_enable = $this->get_features_options();

		if ( empty( $feature_enable ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = isset( $feature_enable['field_1']['options']['option']['selected'] ) ? $feature_enable['field_1']['options']['option']['selected'] : false;

		if ( true === $selected ) {
			return array(
				'parent_enable'  => true,
				'feature_enable' => true,
			);
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => false,
		);
	}

	/**
	 * Gets feature status (wrapper for tailwatch_search_replace_feature_enable).
	 *
	 * @return array Parent and feature enable flags.
	 */
	protected function tailwatch_get_feature_status() {
		return $this->tailwatch_search_replace_feature_enable();
	}

	/**
	 * Unschedule search-replace cron if feature is disabled.
	 *
	 */
	public function tailwatch_search_replace_feature() {
		$get_feature = $this->get_features_options();
		if ( ! isset( $get_feature['field_1']['options']['option']['selected'] ) || ! $get_feature['field_1']['options']['option']['selected'] ) {
			$next_scheduled = wp_next_scheduled( 'tailwatch_search_replace_cron' );
			if ( $next_scheduled ) {
				wp_unschedule_event( $next_scheduled, 'tailwatch_search_replace_cron' );
			}
		}
	}

	/**
	 * Gets all database table names for the current site.
	 *
	 * @return array With keys all_tables, message, code.
	 */
	public function tailwatch_get_all_table_names() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW TABLES has no user input.
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( is_array( $tables ) && ! empty( $tables ) ) {
			return array(
				'all_tables' => $tables,
				'message'    => __( 'All tables successfully received', 'tailwatch' ),
				'code'       => 200,
			);
		} else {
			return array(
				'all_tables' => array(),
				'message'    => __( 'No tables found or error retrieving tables', 'tailwatch' ),
				'code'       => 404,
			);
		}
	}

	/**
	 * Starts search-replace: validates input, inserts data, schedules cron.
	 *
	 * @param string $post_data JSON-encoded POST data (search, replace, all_tables, etc.).
	 * @return array Response with code, message, data.
	 */
	public function tailwatch_start_search_replace( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			// Refuse to start if a conflicting process is currently running —
			// but ONLY for user-initiated runs. This same method is also
			// invoked by migration, restore, and malware_restore as a sub-step
			// of their own flows; those internal calls pass process_run set
			// to 'migrator', 'restore', or 'malware', and must bypass the
			// cross-process gate (otherwise the parent flow's own search_replace
			// step would deadlock against the parent's running state).
			$internal_callers   = array( 'migrator', 'restore', 'malware' );
			$incoming_run       = is_array( $data ) && isset( $data['process_run'] ) ? $data['process_run'] : '';
			$is_user_initiated  = ! in_array( $incoming_run, $internal_callers, true );

			if ( $is_user_initiated ) {
				$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'search_replace' );
				if ( null !== $blocked ) {
					return $blocked;
				}
			}

			$cancel_pause_data = $this->search_replace_cancel_pause_data();

			if ( ! empty( $cancel_pause_data ) ) {
				if ( 'in-progress' === $cancel_pause_data['scan_state'] ) {
					Log::error(
						'Attempt to start a new search and replace while one is in progress',
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_start_failed',
						)
					);
					return array(
						'code'       => 200,
						'is_started' => true,
						'data'       => array(),
						'message'    => __( 'Search and replace Already scheduled.', 'tailwatch' ),
					);
				}
			}
			$this->tailwatch_remove_search_replace_entries();

			$search           = isset( $data['search'] ) ? sanitize_text_field( $data['search'] ) : '';
			$replace          = isset( $data['replace'] ) ? sanitize_text_field( $data['replace'] ) : '';
			$process_run      = isset( $data['process_run'] ) ? sanitize_text_field( $data['process_run'] ) : 'search_replace';
			$dry_run          = isset( $data['dry_run'] ) ? (bool) $data['dry_run'] : false;
			$guid             = isset( $data['guid'] ) ? (bool) $data['guid'] : false;
			$case_insensitive = isset( $data['case_insensitive'] ) ? (bool) $data['case_insensitive'] : false;
			$all_tables_raw  = is_array( $data['all_tables'] ) ? $data['all_tables'] : array();
			$search_model    = new SearchReplaceModel();
			$filtered_tables = $search_model->filter_valid_tables( $all_tables_raw );
			$all_tables      = $filtered_tables['valid'];

			if ( ! empty( $filtered_tables['invalid'] ) ) {
				Log::error(
					'Search & replace rejected ' . count( $filtered_tables['invalid'] ) . ' invalid table name(s): ' . implode( ', ', $filtered_tables['invalid'] ),
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_start_failed',
					)
				);
			}

			if ( empty( $search ) || empty( $all_tables ) || ( ! $dry_run && empty( $replace ) ) ) {
				Log::error(
					'Search, tables, or replace term missing',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_start_failed',
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'Required fields missing: please provide \'Search\' term, \'Table( s)\', and \'Replace\' term.', 'tailwatch' ),
				);
			}

			if ( $search === $replace && 'search_replace' === $process_run ) {
				Log::error(
					'Search and replace terms are identical',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_start_failed',
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'Search & Replace must be different.', 'tailwatch' ),
				);
			}

			$cron_status = apply_filters(
				'tailwatch_test_http_cron_access_search_replace',
				( new CronHealthService() )->test( 'search_replace' )
			);
			if ( ! is_array( $cron_status ) || empty( $cron_status['success'] ) ) {
				$cron_status = array(
					'success' => false,
					'message' => __( 'Cron check failed.', 'tailwatch' ),
				);
			}
			if ( ! $cron_status['success'] ) {
				Log::error(
					$cron_status['message'],
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_start_failed',
					)
				);
				return array(
					'message' => __( 'Failed to run the search and replace due to an issue with the cron.', 'tailwatch' ),
					'error'   => $cron_status['message'],
					'code'    => 400,
				);
			}

			$selected_options = array(
				'cron_running'     => false,
				'scan_state'       => 'in-progress',
				'is_process'       => 'normal',
				'process_run'      => $process_run,
				'search'           => $search,
				'replace'          => $replace,
				'dry_run'          => $dry_run,
				'guid'             => $guid,
				'case_insensitive' => $case_insensitive,
				'all_tables'       => array(),
			);

			foreach ( $all_tables as $table ) {
				$selected_options['all_tables'][ $table ] = array(
					'is_completed'   => false,
					'rows_processed' => 0,
				);
			}

			$cancel_pause = array(
				'scan_state'   => 'in-progress',
				'cron_running' => false,
				'started_time' => time(),
			);

			$db_data_is = array(
				array(
					'user_id'       => '1',
					'child_of'      => '0',
					'key'           => 'default_search_replace',
					'option'        => 'tailwatch_search_replace',
					'value'         => wp_json_encode( $selected_options ),
					'type'          => 'JSON',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
				array(
					'user_id'       => '1',
					'child_of'      => '0',
					'key'           => 'default_search_replace',
					'option'        => 'search_replace_pause_cancel',
					'value'         => wp_json_encode( $cancel_pause ),
					'type'          => 'JSON',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
			);

			$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

			$db_model = new DBModel();

			$data_insert = $this->tailwatch_insert_data_in_db( $db_model, $db_data_is, $db_data_format );

			if ( false === $data_insert ) {
				Log::error(
					'Failed to insert search and replace data',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_start_failed',
					)
				);
				return array(
					'code'       => 500,
					'is_started' => false,
					'data'       => array(),
					'message'    => __( 'Error: Database insertion failed.', 'tailwatch' ),
				);
			}

			if ( ! wp_next_scheduled( 'tailwatch_search_replace_cron' ) ) {
				$cron_scheduled = wp_schedule_single_event( time(), 'tailwatch_search_replace_cron' );

				if ( $cron_scheduled ) {
					// Create/Get process for monitoring.
					$process_id = $this->process_manager->get_or_create_process(
						'search_replace',
						'tailwatch_search_replace_cron',
						array(
							'search'       => $search,
							'replace'      => $replace,
							'dry_run'      => $dry_run,
							'total_tables' => count( $all_tables ),
							'tables'       => $all_tables,
							'process_run'  => $process_run,
						)
					);

					// Store process_id in cancel_pause data for reference.
					$cancel_pause['process_id'] = $process_id;
					$this->update_search_replace_cancel_pause( $cancel_pause );

					$message   = $dry_run
						? "Starting scan for: \"{$search}\""
						: "Starting replacement: \"{$search}\" → \"{$replace}\"";
					$live_logs = new LiveLogsController();
					$live_logs->insert_live_logs_records( $message, $this->logs_directory, $this->get_live_logs );

					$get_data = $this->tailwatch_get_search_replace_data();

					if ( empty( $get_data ) ) {
						$data_insert = $this->tailwatch_insert_data_in_db( $db_model, $db_data_is, $db_data_format );
						if ( false === $data_insert ) {
							Log::error(
								'Failed to retry inserting search and replace data',
								array(
									'feature' => 'search_replace',
									'action'  => 'search_replace_start_failed',
								)
							);
							return array(
								'code'       => 500,
								'is_started' => false,
								'data'       => array(),
								'message'    => __( 'Error: Database insertion failed.', 'tailwatch' ),
							);
						}
					}

					Log::info( "Search='{$search}', Replace='{$replace}', Tables=" . implode( // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- Complex multi-line call format.
						', ', $all_tables ) . ' - Search and replace scheduled successfully.', // phpcs:ignore PEAR.Functions.FunctionCallSignature.Indent, PEAR.Functions.FunctionCallSignature.MultipleArguments, PEAR.Functions.FunctionCallSignature.CloseBracketLine -- Complex multi-line call format.
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_started',
						)
					);

					return array(
						'code'       => 200,
						'is_started' => true,
						'datais'     => $get_data,
						'data'       => array(),
						'message'    => __( 'Search and replace scheduled successfully.', 'tailwatch' ),
					);
				} else {
					Log::error(
						'Failed to schedule tailwatch_search_replace_cron',
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_start_failed',
						)
					);
					return array(
						'code'       => 500,
						'is_started' => false,
						'data'       => array(),
						'message'    => __( 'Error: Cron job could not be scheduled. Please try again.', 'tailwatch' ),
					);
				}
			} else {
				Log::info(
					'Search and replace scheduled successfully. Already scheduled.',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_started',
					)
				);
				return array(
					'code'       => 200,
					'is_started' => true,
					'data'       => array(),
					'message'    => __( 'Search and replace scheduled successfully. Already scheduled.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_start_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while starting search and replace.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Inserts multiple rows into the options table (search-replace and cancel/pause).
	 *
	 * @param \Tailwatch\Admin\App\Api\Models\DBModel $db_model       DB model instance.
	 * @param array                                     $db_data_is     Array of row data.
	 * @param array                                     $db_data_format Format array for prepare.
	 * @return bool True if all inserts succeeded.
	 */
	public function tailwatch_insert_data_in_db( $db_model, $db_data_is, $db_data_format ) {
		foreach ( $db_data_is as $db_data ) {
			if ( ! $db_model->insert_row( $db_data, $db_data_format ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Cron callback: runs search-replace across tables (or reverts), updates state and logs.
	 *
	 * @throws \Throwable On process failure.
	 */
	public function tailwatch_search_and_replace_with() {
		try {
			$get_data = $this->tailwatch_get_search_replace_data();

			if ( empty( $get_data ) ) {
				Log::error(
					'No data found for key: default_search_replace',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_process_failed',
					)
				);
				return;
			}

			$cancel_pause_data = $this->search_replace_cancel_pause_data();
			$scan_state        = $cancel_pause_data['scan_state'];
			$cron_running      = $cancel_pause_data['cron_running'];

			// Check if paused/cancelled before doing anything.
			// This prevents recovery from rescheduling paused processes.
			if ( 'pause' === $scan_state || 'cancel' === $scan_state ) {
				// Don't update heartbeat or state - let it stay stuck.
				// User will resume manually when ready.
				return;
			}

			// Get process_id from cancel_pause data.
			$process_id = $cancel_pause_data['process_id'] ?? null;

			// If no process_id, create one (recovery scenario).
			if ( ! $process_id ) {
				$process_id                      = $this->process_manager->get_or_create_process(
					'search_replace',
					'tailwatch_search_replace_cron',
					array(
						'search'   => $get_data['search'] ?? '',
						'replace'  => $get_data['replace'] ?? '',
						'recovery' => true,
					)
				);
				$cancel_pause_data['process_id'] = $process_id;
			}

			// Update state to in_progress and send heartbeat.
			$this->process_manager->update_state( $process_id, 'in_progress' );
			$this->process_manager->heart_beat( $process_id );

			if ( false === $cron_running ) {
				$cancel_pause_data['cron_running'] = true;
				$this->update_search_replace_cancel_pause( $cancel_pause_data );
			}

			$cancel_pause_data['function_completed'] = false;
			$cancel_pause_data['function_started']   = true;
			$this->update_search_replace_cancel_pause( $cancel_pause_data );

			$options = $get_data;
			$tables  = $options['all_tables'];

			if ( 'reverse' === $options['is_process'] ) {
				$replace = $options['search'];
				$search  = $options['replace'];
			} else {
				$search  = $options['search'];
				$replace = $options['replace'];
			}

			$dry_run          = $options['dry_run'];
			$guid             = $options['guid'];
			$case_insensitive = $options['case_insensitive'];
			$all_completed    = true;

			$stop_execution = $this->tailwatch_stop_search_replace_execution();
			if ( true === $stop_execution ) {
				// Defensive: scan_state may change mid-run via a concurrent request/cron,
				// so re-schedule the cancel cron if we land here in cancel state.
				if ( false === $dry_run && 'cancel' === $scan_state && false === $cron_running ) {
					wp_schedule_single_event( time() + 5, 'tailwatch_update_table_status_on_cancel_cron' );
				}
				$this->tailwatch_search_replace_function_completed();
				return;
			}

			$log_file = $this->search_replace_count;

			foreach ( $tables as $table => $status ) {
				if ( isset( $status['is_skip'] ) && true === $status['is_skip'] ) {
					continue;
				}

				if ( ! $status['is_completed'] ) {
					try {
						// Initialize total_count if it isn't set yet.
						if ( ! isset( $options['all_tables'][ $table ]['total_count'] ) || ( 0 === $options['all_tables'][ $table ]['total_count'] && 'reverse' === $options['is_process'] ) ) {
							$options['all_tables'][ $table ]['total_count'] = $this->is_table_complete( $table, $search, $guid, $case_insensitive );
							$this->update_search_replace_data( $options );
						}

						if ( ! isset( $options['all_tables'][ $table ]['rows_processed'] ) ) {
							$options['all_tables'][ $table ]['rows_processed'] = 0;
						}

						$start_row = $options['all_tables'][ $table ]['rows_processed'];
						$end_row   = min( $start_row + $this->batch_size, $options['all_tables'][ $table ]['total_count'] );

						if ( $start_row === 0 && $end_row === 0 ) {
							if ( 'reverse' === $options['is_process'] ) {
								$this->update_search_replace_logs_records( "Reverting table: $table", 'INFO' );
							} else {
								$this->update_search_replace_logs_records( "Processing table: $table", 'INFO' );
							}
						} elseif ( 'reverse' === $options['is_process'] ) {
							$this->update_search_replace_logs_records( "Reverting table: $table, rows: $start_row - $end_row", 'INFO' );
						} else {
							$this->update_search_replace_logs_records( "Processing table: $table, rows: $start_row - $end_row", 'INFO' );
						}

						$this->process_table_batch( $table, $search, $replace, $dry_run, $guid, $case_insensitive );

						$options['all_tables'][ $table ]['rows_processed'] += $this->batch_size;

						if ( $options['all_tables'][ $table ]['total_count'] <= $options['all_tables'][ $table ]['rows_processed'] ) {
							$fs           = FilesystemService::get_filesystem();
							$log_contents = $fs ? $fs->get_contents( $log_file ) : false;
							$log_data     = ( false === $log_contents ) ? null : json_decode( $log_contents, true );
							foreach ( $log_data[ $table ]['all_columns'] as $column => $matches ) {
								if ( 0 < $matches ) {
									$this->update_search_replace_logs_records( "$column: $matches match" . ( $matches > 1 ? 'es' : '' ), 'INFO' );
								}
							}

							if ( 'reverse' === $options['is_process'] ) {
								if ( 0 === $log_data[ $table ]['total_entries'] ) {
									$this->update_search_replace_logs_records( "No entries found ($table)", 'OK' );
								} else {
									$this->update_search_replace_logs_records( 'Reverted ' . $log_data[ $table ]['total_entries'] . " entries in $table", 'OK' );
								}
							} else {
								if ( 0 === $log_data[ $table ]['total_entries'] ) {
									$this->update_search_replace_logs_records( "No entries found ($table)", 'OK' );
								} else {
									$this->update_search_replace_logs_records( 'Processed ' . $log_data[ $table ]['total_entries'] . " entries in $table", 'OK' );
								}
							}

							$options['all_tables'][ $table ]['is_completed'] = true;
						} else {
							$all_completed = false;
						}

						$this->update_search_replace_data( $options );

						// Send heartbeat and update progress metadata.
						$this->process_manager->heart_beat( $process_id );

						// Calculate progress.
						$completed_tables = 0;
						$total_tables     = count( $options['all_tables'] );
						foreach ( $options['all_tables'] as $tbl_status ) {
							if ( isset( $tbl_status['is_completed'] ) && $tbl_status['is_completed'] ) {
								++$completed_tables;
							}
						}

						// Update process metadata with progress.
						$this->process_manager->update_metadata(
							$process_id,
							array(
								'current_table'       => $table,
								'rows_processed'      => $options['all_tables'][ $table ]['rows_processed'] ?? 0,
								'total_rows'          => $options['all_tables'][ $table ]['total_count'] ?? 0,
								'completed_tables'    => $completed_tables,
								'total_tables'        => $total_tables,
								'progress_percentage' => $total_tables > 0 ? round( ( $completed_tables / $total_tables ) * 100, 2 ) : 0,
							)
						);

						$stop_execution = $this->tailwatch_stop_search_replace_execution();
						if ( true === $stop_execution ) {
							if ( false === $dry_run && 'cancel' === $scan_state && false === $cron_running ) {
								wp_schedule_single_event( time() + 5, 'tailwatch_update_table_status_on_cancel_cron' );
							}
							// Intentional: return after scheduling.
						} else {
							wp_schedule_single_event( time() + 5, 'tailwatch_search_replace_cron' );
							// Intentional: return after scheduling.
						}

						$this->tailwatch_search_replace_function_completed();
						return;
					} catch ( \Throwable $e ) {
						Log::error(
							$e->getMessage(),
							array(
								'feature'   => 'search_replace',
								'action'    => 'search_replace_process_failed',
								'exception' => $e,
							)
						);
						$options['all_tables'][ $table ]['is_completed'] = false;
						$all_completed                                   = false;
						continue;
					}
				}
			}

			if ( $all_completed ) {
				try {
					$fs           = FilesystemService::get_filesystem();
					$log_contents = $fs ? $fs->get_contents( $log_file ) : false;
					$get_data     = ( false === $log_contents ) ? null : json_decode( $log_contents, true );

					if ( null === $get_data ) {
						Log::error(
							'Invalid JSON in file: ' . $log_file,
							array(
								'feature' => 'search_replace',
								'action'  => 'search_replace_process_failed',
							)
						);
						throw new \Exception( "Failed to parse JSON from log file: {$log_file}" );
					}

					$total_counts = isset( $get_data['overall_total_entries'] ) ? $get_data['overall_total_entries'] : 0;

					$cancel_pause_data = $this->search_replace_cancel_pause_data();
					if ( in_array( $cancel_pause_data['scan_state'], array( 'in-progress', 'reverting_changes' ) ) ) {
						$cancel_pause_data['scan_state'] = 'completed';
						$this->update_search_replace_cancel_pause( $cancel_pause_data );
					}

					// Mark process as completed.
					if ( isset( $process_id ) && $process_id ) {
						$this->process_manager->mark_completed( $process_id );
					}

					$this->tailwatch_search_replace_function_completed();

					if ( false === $dry_run ) {
						if ( 'reverse' === $options['is_process'] ) {
							$this->update_search_replace_logs_records( 'Reversion completed successfully', 'INFO' );
							$message_is = "Your Search & Replace reversion finished. {$total_counts} entries were restored to their previous values.";
						} else {
							$this->update_search_replace_logs_records( 'Replacement completed successfully', 'INFO' );
							$message_is = "Your Search & Replace finished. {$total_counts} entries were updated across your database.";
						}
					} else {
						$this->update_search_replace_logs_records( 'Scan completed successfully', 'INFO' );
						$message_is = "Your Search & Replace preview finished. We found {$total_counts} matches that would be replaced if you run it live.";
					}

					$this->update_search_replace_logs_records( $message_is, 'RESULT' );

					// Optional cleanup cron (left disabled; enable if desired).
					$live_logs = new LiveLogsController();
					$live_logs->tailwatch_live_logs_completed( $all_completed, $this->get_live_logs );

					Log::info(
						$message_is,
						array(
							'feature'   => 'search_replace',
							'action'    => 'search_replace_completed',
							'title'     => 'Search & Replace Completed',
							'meta_data' => array(
								'feature'       => 'Search & Replace',
								'event'         => 'Completed',
								'Search for'    => (string) $search,
								'Replace with'  => (string) $replace,
								'Dry run'       => $dry_run ? 'Yes' : 'No',
								'Matches found' => (int) $total_counts,
							),
						)
					);

					// Pro's malware-restore R6 waits on this hook to advance.
					// Gated so standalone SR runs don't fire it. 2nd arg = success.
					if ( in_array( $options['process_run'], array( 'malware' ), true ) ) {
						do_action( 'tailwatch_search_replace_completed', $options['process_run'], true );
					}
				} catch ( \Throwable $e ) {
					Log::error(
						$e->getMessage(),
						array(
							'feature'   => 'search_replace',
							'action'    => 'search_replace_process_failed',
							'exception' => $e,
						)
					);
				}
			}

			Log::info(
				'Feature status resolved',
				array(
					'feature' => 'search_replace',
					'action'  => 'update_errors_feature_status',
				)
			);
		} catch ( \Throwable $e ) {
			// Mark process as failed on exception.
			$cancel_pause_data = $this->search_replace_cancel_pause_data();
			$process_id        = $cancel_pause_data['process_id'] ?? null;
			if ( $process_id ) {
				$this->process_manager->mark_failed( $process_id, 'Exception: ' . $e->getMessage() );
			}

				Log::error(
					$e->getMessage(),
					array(
						'feature'   => 'search_replace',
						'action'    => 'search_replace_process_failed',
						'exception' => $e,
					)
				);

				// Signal abnormal termination so pro's R6 doesn't poll forever.
				// $options may be undefined if the exception fired before its
				// assignment; coalesce defensively.
				$sr_process_run = isset( $options['process_run'] ) ? $options['process_run'] : '';
				if ( in_array( $sr_process_run, array( 'malware' ), true ) ) {
					do_action( 'tailwatch_search_replace_completed', $sr_process_run, false );
				}
		}
	}

	/**
	 * Marks the current cron run as function-completed and updates cancel/pause state.
	 *
	 */
	public function tailwatch_search_replace_function_completed() {
		$cancel_pause_data                         = $this->search_replace_cancel_pause_data();
		$cancel_pause_data['function_completed']   = true;
		$cancel_pause_data['function_started']     = false;
		$cancel_pause_data['completion_timestamp'] = time();
		$this->update_search_replace_cancel_pause( $cancel_pause_data );
	}

	/**
	 * Processes one batch of rows for a table (search-replace or dry run).
	 *
	 * @param string $table            Table name.
	 * @param string $search           Search string.
	 * @param string $replace          Replace string.
	 * @param bool   $dry_run          Whether to only scan (no DB updates).
	 * @param bool   $guid            Whether to include guid column in wp_posts.
	 * @param bool   $case_insensitive Case-insensitive match.
	 */
	private function process_table_batch( $table, $search, $replace, $dry_run, $guid, $case_insensitive ) {
		global $wpdb;

		$model       = new SearchReplaceModel();
		$columns     = $this->get_searchable_columns( $table, $guid );
		$primary_key = $model->get_primary_key( $table );

		if ( ! $primary_key ) {
			Log::error(
				"No primary key found for table: $table",
				array(
					'feature' => 'search_replace',
					'action'  => 'search_replace_process_failed',
				)
			);
			return;
		}

		foreach ( $columns as $column ) {
			// Skip the guid column in wp_posts when $guid is false.
			if ( $table === $wpdb->prefix . 'posts' && ! $guid && 'guid' === $column ) {
				continue;
			}

			$results     = $model->get_matching_rows( $table, $column, $search, $this->batch_size, $case_insensitive );
			$match_count = 0;

			if ( $results ) {
				foreach ( $results as $row ) {
					// Use serialization-aware replacement instead of str_replace().
					$updated_value = $this->safe_replace_in_serialized_data( $row->$column, $search, $replace, $case_insensitive );

					if ( $row->$column !== $updated_value ) {
						if ( ! $dry_run && $wpdb->prefix . TAILWATCH_DB_TABLE_NAME !== $table ) {
							$model->update_row( $table, $column, $primary_key, $row->$primary_key, $updated_value );
						}
						++$match_count;
					}
				}
			}

			$this->update_search_replace_logs( $table, $column, $match_count );
		}
	}

	/**
	 * Counts rows in table that contain the search string (for progress).
	 *
	 * @param string $table            Table name.
	 * @param string $search           Search string.
	 * @param bool   $guid            Whether to include guid in wp_posts.
	 * @param bool   $case_insensitive Case-insensitive match.
	 * @return int Matching row count.
	 */
	private function is_table_complete( $table, $search, $guid, $case_insensitive ) {
		$model   = new SearchReplaceModel();
		$columns = $this->get_searchable_columns( $table, $guid );
		return $model->count_matching_rows( $table, $columns, $search, $case_insensitive );
	}

	/**
	 * Gets list of searchable column names for a table (excludes guid when not wanted).
	 *
	 * @param string $table Table name.
	 * @param bool   $guid  Whether to include guid column in wp_posts.
	 * @return array Column names.
	 */
	private function get_searchable_columns( $table, $guid ) {
		global $wpdb;

		$model   = new SearchReplaceModel();
		$columns = $model->get_columns( $table );

		$searchable_columns = array();
		foreach ( $columns as $column ) {
			if ( $table === $wpdb->prefix . 'posts' && ! $guid && 'guid' === $column ) {
				continue;
			}
			$searchable_columns[] = $column;
		}

		return $searchable_columns;
	}

	/**
	 * Safe replacement in serialized and plain text data. Handles all WordPress data types.
	 *
	 * @param mixed  $data              Data (string, array, or serialized).
	 * @param string $search           Search string.
	 * @param string $replace          Replace string.
	 * @param bool   $case_insensitive Case-insensitive match. Default false.
	 * @return mixed Processed data.
	 */
	private function safe_replace_in_serialized_data( $data, $search, $replace, $case_insensitive = false ) {
		return $this->recursive_unserialize_replace( $search, $replace, $data, false, $case_insensitive );
	}


	/**
	 * Recursively replaces in serialized data (strings, arrays, objects).
	 *
	 * @param string $from             Search term.
	 * @param string $to               Replacement term.
	 * @param mixed  $data             Data to process (string, array, or object).
	 * @param bool   $serialised       Whether to serialize the result. Default false.
	 * @param bool   $case_insensitive Case-insensitive search. Default false.
	 * @return mixed Processed data.
	 */
	private function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false, $case_insensitive = false ) {
		try {
			// Early exit if string does not contain search term (optimization).
			if ( is_string( $data ) ) {
				$has_match = $case_insensitive ? false !== stripos( $data, $from ) : false !== strpos( $data, $from );
				if ( ! $has_match ) {
					return $data;
				}
			}

			$unserialized = ( is_string( $data ) && ! $this->is_serialized_string( $data ) ) ? $this->safe_unserialize( $data ) : false;
			// Handle serialized strings (but not serialized strings themselves).
			if ( false !== $unserialized ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true, $case_insensitive );
			} elseif ( is_array( $data ) ) {
				// Handle arrays.
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false, $case_insensitive );
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( 'object' === gettype( $data ) ) {
				// Handle objects (use gettype like Better Search Replace).
				if ( $this->is_object_cloneable( $data ) ) {
					$_tmp  = clone $data;
					$props = get_object_vars( $data );
					foreach ( $props as $key => $value ) {
						// Skip integer properties (they cause issues).
						if ( is_int( $key ) ) {
							continue;
						}
						// Skip protected properties (they cannot be modified).
						if ( is_string( $key ) && 1 === preg_match( '/^(\\\\0).+/im', preg_quote( $key, '/' ) ) ) {
							continue;
						}
						$_tmp->$key = $this->recursive_unserialize_replace( $from, $to, $value, false, $case_insensitive );
					}
					$data = $_tmp;
					unset( $_tmp );
				}
			} elseif ( $this->is_serialized_string( $data ) ) {
				// Handle serialized strings specifically.
				$unserialized = $this->safe_unserialize( $data );
				if ( false !== $unserialized ) {
					$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true, $case_insensitive );
				}
			} else {
				// Handle regular strings.
				if ( is_string( $data ) ) {
					$data = $case_insensitive ? str_ireplace( $from, $to, $data ) : str_replace( $from, $to, $data );
				}
			}

			// Re-serialize if needed (required for serialized DB values in search-replace).
			if ( $serialised ) {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Required for serialized data replacement.
				return serialize( $data );
			}
		} catch ( \Exception $error ) {
			// On any error, return original data to prevent corruption.
			Log::error( 'SearchReplace: Exception in recursive_unserialize_replace: ' . $error->getMessage( // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- Complex multi-line call format.
				), // phpcs:ignore PEAR.Functions.FunctionCallSignature.Indent -- Complex multi-line call format.
				array(
					'feature' => 'search_replace',
					'action'  => 'recursive_unserialize_replace',
				)
			);
		}

		return $data;
	}

	/**
	 * Safe unserialize with allowed_classes false. Uses WordPress is_serialized().
	 *
	 * Always passes ['allowed_classes' => false] so no class instantiation can
	 * occur during unserialization — closes PHP object-injection attack vectors
	 * on tainted serialized data found inside post_meta / options / etc. The
	 * option has been available since PHP 7.0, and this plugin requires PHP 7.4+
	 * (see Requires PHP header in tailwatch.php), so no version branch is needed.
	 *
	 * @param string $serialized_string Serialized string.
	 * @return mixed Unserialized data or false.
	 */
	private function safe_unserialize( $serialized_string ) {
		if ( ! is_serialized( $serialized_string ) ) {
			return false;
		}

		$serialized_string = trim( $serialized_string );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Required for serialized search-replace; errors handled, no class instantiation via allowed_classes=false.
		return @unserialize( $serialized_string, array( 'allowed_classes' => false ) );
	}

	/**
	 * Checks if data is a serialized string (not array or object).
	 *
	 * @param mixed $data Data to check.
	 * @return bool True if serialized string.
	 */
	private function is_serialized_string( $data ) {
		if ( ! is_serialized( $data ) ) {
			return false;
		}

		// Check if it's a serialized string by looking at the first character.
		// s: = serialized string, a: = serialized array, O: = serialized object.
		return substr( trim( $data ), 0, 2 ) === 's:';
	}

	/**
	 * Checks if a given object can be cloned safely.
	 *
	 * @param object $obj Instance to check (param name avoids reserved keyword).
	 * @return bool True if cloneable.
	 */
	private function is_object_cloneable( $obj ) {
		if ( ! is_object( $obj ) ) {
			return false;
		}

		try {
			return ( new \ReflectionClass( get_class( $obj ) ) )->isCloneable();
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Updates the search-replace count log file for a table/column.
	 *
	 * @param string $table         Table name.
	 * @param string $column        Column name.
	 * @param int    $matches_found Number of matches.
	 * @param bool   $is_completed  Whether table is completed. Default false.
	 */
	private function update_search_replace_logs( $table, $column, $matches_found, $is_completed = false ) {
		$log_file = $this->search_replace_count;
		$fs       = FilesystemService::get_filesystem();

		if ( $fs && file_exists( $log_file ) ) {
			$log_contents = $fs->get_contents( $log_file );
			$log_data     = ( false === $log_contents ) ? array() : (array) json_decode( $log_contents, true );
		} else {
			$log_data = array();
		}

		if ( ! isset( $log_data[ $table ] ) ) {
			$log_data[ $table ] = array(
				'all_columns'   => array(),
				'total_entries' => 0,
				'is_completed'  => false,
			);
		}

		if ( ! isset( $log_data[ $table ]['all_columns'][ $column ] ) ) {
			$log_data[ $table ]['all_columns'][ $column ] = 0;
		}

		$log_data[ $table ]['all_columns'][ $column ] += $matches_found;
		$log_data[ $table ]['total_entries']          += $matches_found;

		if ( ! isset( $log_data['overall_total_entries'] ) ) {
			$log_data['overall_total_entries'] = 0;
		}
		$log_data['overall_total_entries'] += $matches_found;

		if ( $is_completed ) {
			$log_data[ $table ]['is_completed'] = true;
		}

		if ( $fs ) {
			$fs->put_contents( $log_file, wp_json_encode( $log_data, JSON_PRETTY_PRINT ), FS_CHMOD_FILE );
		}
	}

	/**
	 * Persists search-replace options (all_tables, progress) to the database.
	 *
	 * @param array $options Full options array to store.
	 */
	private function update_search_replace_data( $options ) {
		$db_model = new DBModel();
		$tailwatch_key = 'default_search_replace';
		$option   = 'tailwatch_search_replace';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $tailwatch_key, $option );
	}

	/**
	 * Updates cancel/pause state in the database.
	 *
	 * @param array $options Cancel/pause data (scan_state, process_id, etc.).
	 */
	public function update_search_replace_cancel_pause( $options ) {
		$db_model = new DBModel();
		$tailwatch_key = 'default_search_replace';
		$option   = 'search_replace_pause_cancel';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $tailwatch_key, $option );
	}

	/**
	 * Returns live logs for the current search-replace run.
	 *
	 * @param string $post_data JSON POST data (e.g. pagination).
	 * @return array Response with data, message, code.
	 */
	public function tailwatch_live_search_replace_logs( $post_data ) {
		try {
			$get_data = $this->tailwatch_get_search_replace_data();

			if ( empty( $get_data ) ) {
				Log::error(
					'No search and replace data exists',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_live_logs_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Data Not exist. Please try again.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			$get_search_data = $this->search_replace_cancel_pause_data();
			$feature_type    = 'search_replace';

			$livelogs = new LiveLogsController();
			return $livelogs->tailwatch_import_live_logs( $post_data, $this->get_live_logs, $get_search_data, $feature_type );
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_live_logs_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error retrieving live logs.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Appends a message to live logs and optionally migrator/malware logs.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (e.g. 'INFO', 'OK', 'RESULT'). Default 'INFO'.
	 */
	public function update_search_replace_logs_records( $message, $level = 'INFO' ) {
		$live_logs = new LiveLogsController();
		$live_logs->update_live_logs_records( $message, $this->get_live_logs, $level );

		$search_replace_data = $this->tailwatch_get_search_replace_data();
		if ( is_array( $search_replace_data ) && isset( $search_replace_data['process_run'] )
			&& 'malware' === $search_replace_data['process_run'] ) {
			do_action( 'tailwatch_backup_malware_scan_logs', $message, $level );
		}
	}

	/**
	 * Resumes a paused search-replace: reschedules cron and updates state.
	 *
	 * @return array Response with data, message, code.
	 */
	public function tailwatch_resume_search_replace() {
		try {
			$existing_data = $this->search_replace_cancel_pause_data();

			if ( ! empty( $existing_data ) && ! empty( $existing_data['scan_state'] ) && 'pause' === $existing_data['scan_state'] ) {
				wp_schedule_single_event( time() + 5, 'tailwatch_search_replace_cron' );

				$existing_data['scan_state'] = 'in-progress';
				$this->update_search_replace_cancel_pause( $existing_data );

				// Update process state to in_progress when resumed.
				$process_id = $existing_data['process_id'] ?? null;
				if ( $process_id ) {
					$this->process_manager->update_state( $process_id, 'in_progress' );
					$this->process_manager->heart_beat( $process_id );
				}

				Log::info(
					'The search and replace process was resumed.',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_resume',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'Search Replace Resume Successfully', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				Log::error(
					'No paused search and replace process to resume',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_resume_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Already Search Replace Schedule', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_resume_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error resuming search and replace.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Pauses or cancels the current search-replace (updates state, unschedules cron).
	 *
	 * @param string $post_data JSON with scan_state (pause|cancel).
	 * @return array Response with code and message.
	 */
	public function tailwatch_cancel_pause_search_replace( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error( 'Invalid JSON data: ' . json_last_error_msg( // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- Complex multi-line call format.
					), // phpcs:ignore PEAR.Functions.FunctionCallSignature.Indent -- Complex multi-line call format.
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_cancel_pause_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$existing_data       = $this->search_replace_cancel_pause_data();
			$search_replace_data = $this->tailwatch_get_search_replace_data();

			if ( ! empty( $data['scan_state'] ) && ( 'pause' === $data['scan_state'] || 'cancel' === $data['scan_state'] ) ) {

				$existing_data['scan_state'] = $data['scan_state'];

				$this->update_search_replace_cancel_pause( $existing_data );

				$timestamp = wp_next_scheduled( 'tailwatch_search_replace_cron' );
				wp_unschedule_event( $timestamp, 'tailwatch_search_replace_cron' );

				$process_id = $existing_data['process_id'] ?? null;

				if ( 'cancel' === $data['scan_state'] ) {
					if ( true === $search_replace_data['dry_run'] ) {
						// Optional: schedule cleanup (commented to avoid auto-delete).
						if ( $process_id ) {
							$this->process_manager->mark_failed( $process_id, 'Search Replace cancelled by user' );
						}
					} else {
						// Table status is updated via tailwatch_update_table_status_on_cancel_cron.
						if ( $process_id ) {
							$this->process_manager->update_state( $process_id, 'in_progress' );
						}
						wp_schedule_single_event( time() + 10, 'tailwatch_update_table_status_on_cancel_cron' );
					}

					$message = 'Search Replace cancel successfully.';
					Log::info(
						$message,
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_cancel',
						)
					);
				} elseif ( 'pause' === $data['scan_state'] ) {
					if ( $process_id ) {
						$this->process_manager->update_state( $process_id, 'pause' );
					}

					$message = 'Search Replace paused successfully. You can resume it later.';
					Log::info(
						$message,
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_pause',
						)
					);
					$existing_data['cron_running'] = false;
					$this->update_search_replace_cancel_pause( $existing_data );
				} else {
					Log::error(
						'Scan state must be pause or cancel. Received: ' . $data['scan_state'],
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_cancel_pause_failed',
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'Invalid stop type provided.', 'tailwatch' ),
						'code'    => 400,
					);
				}

				return array(
					'data'    => array(),
					'dry_run' => $search_replace_data['dry_run'],
					'message' => $message,
					'code'    => 200,
				);
			} else {
				Log::error(
					'Stop type is missing in input data',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_cancel_pause_failed',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Stop type is missing.', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_cancel_pause_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error pausing or cancelling search and replace.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Cron callback: reverts table progress and reschedules search-replace to run reverse.
	 *
	 */
	public function tailwatch_update_table_status_on_cancel() {
		$options           = $this->tailwatch_get_search_replace_data();
		$cancel_pause_data = $this->search_replace_cancel_pause_data();

		if ( empty( $options ) || empty( $options['all_tables'] ) ) {
			return;
		}

		foreach ( $options['all_tables'] as $table => &$table_data ) {

			if ( 0 === $table_data['rows_processed'] && ( ! isset( $table_data['total_count'] ) || 0 === $table_data['total_count'] ) && ( ! isset( $table_data['revert_change'] ) || false === $table_data['revert_change'] ) ) {
				$table_data['is_skip']       = true;
				$table_data['revert_change'] = false;
				$table_data['is_completed']  = true;
			} else {
				$table_data['rows_processed'] = 0;
				$table_data['total_count']    = 0;

				$table_data['revert_change'] = true;
				$table_data['is_skip']       = false;
				$table_data['is_completed']  = false;
			}
		}

		$options['is_process']             = 'reverse';
		$cancel_pause_data['scan_state']   = 'reverting_changes';
		$cancel_pause_data['cron_running'] = false;
		$this->update_search_replace_data( $options );
		$this->update_search_replace_cancel_pause( $cancel_pause_data );

		if ( file_exists( $this->search_replace_count ) ) {
			wp_delete_file( $this->search_replace_count );
		}

		$this->update_search_replace_logs_records( 'Reverting changes', 'INFO' );

		wp_schedule_single_event( time() + 10, 'tailwatch_search_replace_cron' );
	}

	/**
	 * Returns current search-replace status (pause, in-progress, completed, etc.).
	 *
	 * @return array With is_completed, scan_state, progress, options, message, code.
	 */
	public function tailwatch_verify_search_replace_status() {
		try {
			$existing_data = $this->search_replace_cancel_pause_data();
			$get_options   = $this->tailwatch_get_selected_options();

			if ( ! empty( $existing_data ) && isset( $existing_data['scan_state'] ) ) {

				if ( 'pause' === $existing_data['scan_state'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'pause',
						'progress'     => $existing_data['progress'],
						'options'      => $get_options,
						'message'      => __( 'Search Replace was paused', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( 'in-progress' === $existing_data['scan_state'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'in-progress',
						'progress'     => $existing_data['progress'],
						'options'      => $get_options,
						'message'      => __( 'Search Replace is in progress', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( 'reverting_changes' === $existing_data['scan_state'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'reverting_changes',
						'progress'     => $existing_data['progress'],
						'options'      => $get_options,
						'message'      => __( 'Search Replace are reverting the changes', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( 'cancel' === $existing_data['scan_state'] && false === $get_options['dry_run'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'cancel',
						'progress'     => $existing_data['progress'],
						'options'      => $get_options,
						'message'      => __( 'Search Replace are reverting the changes', 'tailwatch' ),
						'code'         => 200,
					);
				} else {
					return array(
						'is_completed' => true,
						'message'      => __( 'Currently no process is in the running.', 'tailwatch' ),
						'code'         => 200,
					);
				}
			} else {
				return array(
					'is_completed' => true,
					'message'      => __( 'No data exists', 'tailwatch' ),
					'code'         => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_verify_status_failed',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error verifying search and replace status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Checks if search-replace is paused/cancelled and unschedules cron if so.
	 *
	 * @return bool True if execution should stop (pause/cancel).
	 */
	public function tailwatch_stop_search_replace_execution() {
		$existing_data = $this->search_replace_cancel_pause_data();

		if ( ! empty( $existing_data['scan_state'] ) && ( 'pause' === $existing_data['scan_state'] || 'cancel' === $existing_data['scan_state'] ) ) {

			$timestamp = wp_next_scheduled( 'tailwatch_search_replace_cron' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'tailwatch_search_replace_cron' );
			}

			if ( true === $existing_data['cron_running'] ) {
				$existing_data['cron_running'] = false;
				$this->update_search_replace_cancel_pause( $existing_data );
			}

			return true;
		}

		return false;
	}

	/**
	 * Reschedules search-replace cron if it was not running and not scheduled (recovery).
	 *
	 * @return array Response with data, message, code.
	 */
	public function tailwatch_search_replace_cron_if_failed() {
		try {
			$existing_data = $this->search_replace_cancel_pause_data();
			if ( false === $existing_data['cron_running'] ) {
				if ( ! wp_next_scheduled( 'tailwatch_search_replace_cron' ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, 'tailwatch_search_replace_cron' );

					if ( $cron_scheduled ) {
						Log::info(
							'Successfully scheduled a new Search & Replace cron job.',
							array(
								'feature' => 'search_replace',
								'action'  => 'search_replace_if_cron_failed',
							)
						);
						return array(
							'data'    => '',
							'message' => __( 'Again attempt to run the cron', 'tailwatch' ),
							'code'    => 200,
						);
					} else {
						Log::error(
							'Failed to schedule tailwatch_search_replace_cron',
							array(
								'feature' => 'search_replace',
								'action'  => 'search_replace_cron_if_failed_on_attempt',
							)
						);
						return array(
							'code'    => 500,
							'data'    => array(),
							'message' => __( 'Error: Cron job could not be scheduled. Please try again.', 'tailwatch' ),
						);
					}
				} else {
					Log::info(
						'Cron job is already scheduled for Search & Replace.',
						array(
							'feature' => 'search_replace',
							'action'  => 'search_replace_if_cron_failed',
						)
					);
					return array(
						'data'    => '',
						'message' => __( 'Cron job is already scheduled.', 'tailwatch' ),
						'code'    => 200,
					);
				}
			} else {
				Log::info(
					'Search & Replace cron job is currently running.',
					array(
						'feature' => 'search_replace',
						'action'  => 'search_replace_if_cron_failed',
					)
				);
				return array(
					'data'    => '',
					'message' => __( 'Cron job is already running.', 'tailwatch' ),
					'code'    => 200,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_cron_if_failed_on_attempt',
					'exception' => $e,
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'Error rescheduling cron job.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Removes search-replace and cancel/pause DB entries and deletes log files.
	 *
	 * @return bool True on success.
	 */
	public function tailwatch_remove_search_replace_entries() {
		try {
			$key                = 'default_search_replace';
			$options            = array( 'tailwatch_search_replace', 'search_replace_pause_cancel' );
			$feature_controller = new DBModel();

			foreach ( $options as $option ) {
				$get_data = $feature_controller->get_value( $option, $key );

				if ( ! empty( $get_data ) ) {
					$where = array(
						'option' => $option,
						'key'    => $key,
					);

					if ( ! $feature_controller->delete_rows( $where ) ) {
						Log::error(
							'Failed to delete rows for option: ' . $option,
							array(
								'feature' => 'search_replace',
								'action'  => 'search_replace_remove_entries_failed',
							)
						);
						return false;
					}
				}
			}

			$this->tailwatch_delete_files_after_complete();

			Log::info(
				'All search and replace entries have been removed.',
				array(
					'feature' => 'search_replace',
					'action'  => 'search_replace_remove_entries',
				)
			);

			return true;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'search_replace',
					'action'    => 'search_replace_remove_entries_failed',
					'exception' => $e,
				)
			);
			return false;
		}
	}

	/**
	 * Deletes live logs and search-replace count JSON files.
	 *
	 */
	public function tailwatch_delete_files_after_complete() {
		if ( file_exists( $this->get_live_logs ) ) {
			wp_delete_file( $this->get_live_logs );
		}

		if ( file_exists( $this->search_replace_count ) ) {
			wp_delete_file( $this->search_replace_count );
		}
	}
}
