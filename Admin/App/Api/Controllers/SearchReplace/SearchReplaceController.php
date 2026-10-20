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
use Tailwatch\Admin\App\Api\Services\SearchReplace\SerializedReplacer;

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
				'process_type'        => 'search_replace',
				'cron_hooks'          => array( 'tailwatch_search_replace_cron' ),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_search_replace',
				'data_option'         => 'tailwatch_search_replace',
				// The run's timestamps are on the status row; the data row above has none.
				'cancel_pause_key'    => 'default_search_replace',
				'cancel_pause_option' => 'search_replace_pause_cancel',
				'stuck_threshold'     => 300,
				'max_retries'         => 3,
				'description'         => 'Search & Replace Process',
				'locks_features'      => array( 'default_search_replace' ),
				// Process types that, when running, prevent a user from starting
				// a manual search & replace. SR writes site-wide DB rows so it
				// cannot run alongside any other heavy DB-touching process.
				'cannot_start_while' => array(
					'backup',
					'db_optimize',
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
		$hook_controller->add_action_hook( 'tailwatch_recovery_process_failed', array( $this, 'tailwatch_on_recovery_failed' ) );
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
	public function tailwatch_start_search_replace( $post_data, $internal = false ) {
		try {
			// $internal is a METHOD PARAMETER, never anything read out of $post_data. The
			// dispatcher calls this with one argument, so a web request cannot set it; only
			// in-process code that has already established its own authority can. A flag
			// carried in the request body would be a capability bypass wearing a comment.
			//
			// The internal caller is the restore, which runs in cron with no current user at
			// all — so the check below cannot pass there and cannot be made to.
			$sr_network_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
			if ( ! $internal && ! current_user_can( $sr_network_cap ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'You are not allowed to run a search and replace on this site.', 'tailwatch' ),
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			// Refuse to start if a conflicting process is running — but only for a run the
			// user started. A restore invokes this as a step inside its own flow, and would
			// otherwise deadlock against its own running state.
			$is_user_initiated = ! $internal;

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
	 * Rewrite one URL to another across every table, as a step inside another flow.
	 *
	 * A restore replaces the database with the contents of a backup, and that backup records
	 * whatever URL the site had when it was taken. Restore a backup onto a different address
	 * — a migration, a domain change, a move to staging — and every absolute URL in the data
	 * still points at the old one. The site comes back broken in a way that looks like the
	 * backup was bad.
	 *
	 * Runs across every table, so it uses the same table list a user-initiated run would.
	 * Serialized values are handled structurally by SerializedReplacer, which never calls
	 * unserialize() — a URL inside a serialized string changes its length, and a naive
	 * replacement corrupts every such row.
	 *
	 * @param string $from URL currently in the data.
	 * @param string $to   URL the site is actually served from.
	 * @return bool Whether a rewrite was scheduled.
	 */
	public function tailwatch_rewrite_site_url( $from, $to ) {
		$from = untrailingslashit( trim( (string) $from ) );
		$to   = untrailingslashit( trim( (string) $to ) );

		// Nothing to do when the backup came from this same address, which is the ordinary
		// case: restoring a site's own backup onto itself.
		if ( '' === $from || '' === $to || $from === $to ) {
			return false;
		}

		// An ENVELOPE — { all_tables, message, code } — not a list. Passed straight to
		// array_values() it gives [ [names…], 'All tables successfully received', 200 ],
		// which validates to zero usable tables, and an envelope is never empty, not even
		// the 404 one, so the emptiness check only means something on the list inside it.
		$response   = $this->tailwatch_get_all_table_names();
		$all_tables = isset( $response['all_tables'] ) && is_array( $response['all_tables'] )
			? $response['all_tables']
			: array();

		if ( empty( $all_tables ) ) {
			return false;
		}

		$payload = wp_json_encode(
			array(
				'search'           => $from,
				'replace'          => $to,
				'process_run'      => 'restore',
				'dry_run'          => false,
				// GUIDs are permanent identifiers, not addresses. Rewriting them re-keys every
				// post for feed readers and is the one thing WordPress explicitly warns against.
				'guid'             => false,
				'case_insensitive' => false,
				'all_tables'       => array_values( $all_tables ),
			)
		);

		if ( false === $payload ) {
			return false;
		}

		$result = $this->tailwatch_start_search_replace( $payload, true );

		return is_array( $result ) && ! empty( $result['is_started'] );
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
			if ( 'pause' === $scan_state || 'cancel' === $scan_state || 'failed' === $scan_state ) {
				// Don't update heartbeat or state - let it stay stuck.
				// User will resume manually when ready.
				return;
			}

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

						$completed_tables = 0;
						$total_tables     = count( $options['all_tables'] );
						foreach ( $options['all_tables'] as $tbl_status ) {
							if ( isset( $tbl_status['is_completed'] ) && $tbl_status['is_completed'] ) {
								++$completed_tables;
							}
						}

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
						} else {
							wp_schedule_single_event( time() + 5, 'tailwatch_search_replace_cron' );
							// One batch per tick: the scheduled event continues from here.
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
					// The counts only feed the closing message, so they must never keep a run from
					// closing. A run that processed no table has no file at all - a live run cancelled
					// before its first step reverts nothing - and throwing here would leave it 'reverting'.
					$fs           = FilesystemService::get_filesystem();
					$log_contents = ( $fs && $fs->exists( $log_file ) ) ? $fs->get_contents( $log_file ) : '';
					$get_data     = ( is_string( $log_contents ) && '' !== $log_contents ) ? json_decode( $log_contents, true ) : array();

					if ( ! is_array( $get_data ) ) {
						Log::error(
							'Invalid JSON in file: ' . $log_file,
							array(
								'feature' => 'search_replace',
								'action'  => 'search_replace_process_failed',
							)
						);
						$get_data = array();
					}

					$total_counts = isset( $get_data['overall_total_entries'] ) ? $get_data['overall_total_entries'] : 0;

					$cancel_pause_data = $this->search_replace_cancel_pause_data();
					if ( in_array( $cancel_pause_data['scan_state'], array( 'in-progress', 'reverting_changes' ) ) ) {
						$cancel_pause_data['scan_state'] = 'completed';
						$this->update_search_replace_cancel_pause( $cancel_pause_data );
					}

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

					// The pro malware restore waits on this hook to advance.
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
			$cancel_pause_data = $this->search_replace_cancel_pause_data();
			$process_id        = $cancel_pause_data['process_id'] ?? null;
			if ( $process_id ) {
				$this->process_manager->mark_failed( $process_id, 'Exception: ' . $e->getMessage() );
			}
			$this->tailwatch_mark_run_failed( $process_id );

				Log::error(
					$e->getMessage(),
					array(
						'feature'   => 'search_replace',
						'action'    => 'search_replace_process_failed',
						'exception' => $e,
					)
				);

				// Signal abnormal termination so the pro malware restore doesn't poll forever.
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
		// Delegates to the structural rewriter. An unserialize / walk / re-serialize
		// round-trip is correct only when the value is itself valid serialized data, and
		// silently destructive when it is not: the fallback then is a flat str_replace,
		// which leaves every `s:N:` prefix inside the value counting the OLD byte length.
		// A serialized fragment wrapped in anything (JSON, a prefix, a concatenation) would
		// hit that path, and the row would not fail until something reads it.
		return SerializedReplacer::replace( $data, $search, $replace, $case_insensitive );
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
	 * @param array $options            Cancel/pause data (scan_state, process_id, etc.).
	 * @param bool  $allow_state_change Resume only: put a paused run back to work.
	 */
	public function update_search_replace_cancel_pause( $options, $allow_state_change = false ) {
		$options = $this->tailwatch_keep_stop_state( $options, $allow_state_change );

		$db_model = new DBModel();
		$tailwatch_key = 'default_search_replace';
		$option   = 'search_replace_pause_cancel';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		$db_model->update_recent_row( $db_data, $tailwatch_key, $option );
	}

	/**
	 * Keep a pause, cancel, revert or finish from being undone by an older copy of this row.
	 *
	 * The live-log poll writes back the copy it read at its start. A copy read before the user
	 * paused or cancelled, or before the run finished, would put 'in-progress' back: the run
	 * would carry on after Pause, or a finished run would show as running and every new run be
	 * refused as "already scheduled". A finished row is kept whole. A revert keeps its state, since the
	 * data row is already running in reverse. Only resume moves a stopped run back to work,
	 * and it says so.
	 *
	 * @param array $options            The row about to be stored.
	 * @param bool  $allow_state_change Resume only.
	 * @return array
	 */
	private function tailwatch_keep_stop_state( $options, $allow_state_change ) {
		if ( $allow_state_change || ! is_array( $options ) || ! isset( $options['scan_state'] ) || 'in-progress' !== $options['scan_state'] ) {
			return $options;
		}

		$current = $this->search_replace_cancel_pause_data();
		if ( ! is_array( $current ) || ! isset( $current['scan_state'] ) ) {
			return $options;
		}
		if ( in_array( $current['scan_state'], array( 'completed', 'failed' ), true ) ) {
			return $current;
		}
		if ( in_array( $current['scan_state'], array( 'pause', 'cancel', 'reverting_changes' ), true ) ) {
			$options['scan_state'] = $current['scan_state'];
		}

		return $options;
	}

	/**
	 * Listener for 'tailwatch_recovery_process_failed': recovery gave up on a stuck run.
	 *
	 * @param array $process_data The process recovery gave up on.
	 * @return void
	 */
	public function tailwatch_on_recovery_failed( $process_data ) {
		if ( is_array( $process_data ) && isset( $process_data['process_type'] ) && 'search_replace' === $process_data['process_type'] ) {
			$this->tailwatch_mark_run_failed( $process_data['process_id'] ?? null );
		}
	}

	/**
	 * Put a run that stopped on an error into 'failed'.
	 *
	 * Otherwise its row says 'in-progress' for good: the screen shows it running, and every new
	 * run is answered "already scheduled". Only a run still going forward or reverting is
	 * converted, and only the one the failed process belongs to, so a newer run is never touched.
	 *
	 * @param string|null $process_id The process that failed.
	 * @return void
	 */
	private function tailwatch_mark_run_failed( $process_id ) {
		$row = $this->search_replace_cancel_pause_data();
		if ( ! is_array( $row ) || ! in_array( $row['scan_state'] ?? '', array( 'in-progress', 'reverting_changes' ), true ) ) {
			return;
		}
		if ( ! empty( $process_id ) && ! empty( $row['process_id'] ) && $row['process_id'] !== $process_id ) {
			return;
		}

		$timestamp = wp_next_scheduled( 'tailwatch_search_replace_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'tailwatch_search_replace_cron' );
		}

		$row['scan_state']   = 'failed';
		$row['cron_running'] = false;
		$this->update_search_replace_cancel_pause( $row );
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
			// Search & replace rewrites rows across every table (SHOW TABLES), which on
			// multisite spans all sites; require a super-admin-level capability there. A
			// plain site admin keeps manage_options on single-site.
			$sr_network_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
			if ( ! current_user_can( $sr_network_cap ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'You are not allowed to run a search and replace on this site.', 'tailwatch' ),
				);
			}

			$existing_data = $this->search_replace_cancel_pause_data();

			if ( ! empty( $existing_data ) && ! empty( $existing_data['scan_state'] ) && 'pause' === $existing_data['scan_state'] ) {
				// Resumed only while nothing that cannot run beside it is running or paused. A run
				// that is a step of a restore or a malware clean-up is resumed beside it.
				$run_data = $this->tailwatch_get_search_replace_data();
				$run      = is_array( $run_data ) ? (string) ( $run_data['process_run'] ?? '' ) : '';
				$inside   = array(
					'restore' => array( 'restore' ),
					'malware' => array( 'malware_scan', 'malware_restore' ),
				);
				$blocked  = ( new ProcessGuard() )->ensure_can_start_process( 'search_replace', $inside[ $run ] ?? array() );
				if ( null !== $blocked ) {
					return $blocked;
				}

				wp_schedule_single_event( time() + 5, 'tailwatch_search_replace_cron' );

				$existing_data['scan_state'] = 'in-progress';
				$this->update_search_replace_cancel_pause( $existing_data, true );

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
				$current_state = ( is_array( $existing_data ) && isset( $existing_data['scan_state'] ) ) ? $existing_data['scan_state'] : '';
				// Cancelling a live run reverts it, so a click that lands after it finished would
				// undo the whole replacement, and one during a revert would stop it halfway. Pause
				// only means something to a run that is going forward.
				if ( 'reverting_changes' === $current_state ) {
					return array(
						'data'    => array(),
						'message' => __( 'The changes are being put back. This cannot be paused or cancelled until it finishes.', 'tailwatch' ),
						'code'    => 409,
					);
				}
				// Recovery has already failed this run's process, so a cancel here would start a
				// revert that holds no lock and that nothing watches. To undo a stopped live run, run
				// Search & Replace again with the two terms swapped.
				if ( 'failed' === $current_state ) {
					return array(
						'data'    => array(),
						'message' => __( 'This run has stopped, so there is nothing to pause or cancel.', 'tailwatch' ),
						'code'    => 409,
					);
				}
				if ( 'completed' === $current_state || ( 'pause' === $data['scan_state'] && 'in-progress' !== $current_state ) ) {
					return array(
						'data'    => array(),
						'message' => ( 'completed' === $current_state )
							? __( 'This run has already finished, so there is nothing to pause or cancel.', 'tailwatch' )
							: __( 'Only a run that is in progress can be paused.', 'tailwatch' ),
						'code'    => 409,
					);
				}

				$existing_data['scan_state'] = $data['scan_state'];

				$this->update_search_replace_cancel_pause( $existing_data );

				$timestamp = wp_next_scheduled( 'tailwatch_search_replace_cron' );
				wp_unschedule_event( $timestamp, 'tailwatch_search_replace_cron' );

				$process_id = $existing_data['process_id'] ?? null;

				if ( 'cancel' === $data['scan_state'] ) {
					if ( true === $search_replace_data['dry_run'] ) {
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

		// This runs seconds after the cancel. By then the row can belong to a new run, or have
		// been failed by recovery; either way there is no cancelled run left to revert.
		if ( ! is_array( $cancel_pause_data ) || 'cancel' !== ( $cancel_pause_data['scan_state'] ?? '' ) ) {
			return;
		}

		// A cancel that crossed recovery giving up: the process is already failed and inactive, so
		// the revert would run with no lock and nothing to recover it. The run stays failed.
		$process = empty( $cancel_pause_data['process_id'] ) ? null : $this->process_manager->get_process( $cancel_pause_data['process_id'] );
		if ( is_array( $process ) && ! empty( $process['failed_at'] ) ) {
			$cancel_pause_data['scan_state']   = 'failed';
			$cancel_pause_data['cron_running'] = false;
			$this->update_search_replace_cancel_pause( $cancel_pause_data );
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
						'progress'     => $existing_data['progress'] ?? 0,
						'options'      => $get_options,
						'message'      => __( 'Search Replace was paused', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( 'in-progress' === $existing_data['scan_state'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'in-progress',
						'progress'     => $existing_data['progress'] ?? 0,
						'options'      => $get_options,
						'message'      => __( 'Search Replace is in progress', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( 'reverting_changes' === $existing_data['scan_state'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'reverting_changes',
						'progress'     => $existing_data['progress'] ?? 0,
						'options'      => $get_options,
						'message'      => __( 'Search Replace are reverting the changes', 'tailwatch' ),
						'code'         => 200,
					);
				} elseif ( 'cancel' === $existing_data['scan_state'] && false === $get_options['dry_run'] ) {
					return array(
						'is_completed' => false,
						'scan_state'   => 'cancel',
						'progress'     => $existing_data['progress'] ?? 0,
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
			if ( ! is_array( $existing_data ) ) {
				return array(
					'data'    => '',
					'message' => __( 'There is no run to restart.', 'tailwatch' ),
					'code'    => 409,
				);
			}
			// A run that stopped on an error stays stopped: the screen asks for this while it
			// polls, and a tick scheduled here would run the failed step again.
			if ( in_array( $existing_data['scan_state'] ?? '', array( 'failed', 'completed' ), true ) ) {
				return array(
					'data'    => '',
					'message' => __( 'This run has stopped, so there is nothing to restart.', 'tailwatch' ),
					'code'    => 409,
				);
			}
			if ( false === ( $existing_data['cron_running'] ?? null ) ) {
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
