<?php

namespace Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;

class BrokenLinkChecker extends BaseController {

	private $feature = 'broken_link_checker';
	private $log_directory;
	private $get_live_logs;
	private $broken_link_status;
	private $live_logs;
	private $processManager;
	private $currentProcessId;

	// Cache for URL check results to avoid duplicate checks
	private static $url_check_cache = array();

	const BATCH_SIZE         = 500;
	const LOGS_TABLE_NAME    = 'tw_logs';
	const CRAWL_LIMIT        = 50; // Items per batch
	const MAX_EXECUTION_TIME = 25; // Seconds

	public function __construct() {
		$hook_controller = new HookControllers();
		$this->log_directory = rtrim( TAILWATCH_LOGS_DIRECTORY, '/\\' ) . DIRECTORY_SEPARATOR . 'broken-links';
		$this->get_live_logs = $this->log_directory . DIRECTORY_SEPARATOR . 'broken-links-logs.json';

		$this->broken_link_status = new BrokenLinkStatus();
		$this->live_logs          = new LiveLogsController();
		$this->processManager     = new ProcessManager();

		$this->register_process_monitoring();
		$hook_controller->add_action_hook( 'tailwatch_broken_link_checker', array( $this, 'tailwatch_broken_link_checker_with_monitoring' ) );
	}

	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'        => 'broken_link_checker',
				'cron_hooks'          => array( 'tailwatch_broken_link_checker', 'tailwatch_start_broken_link_checker' ),
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_broken_link_checker',
				'data_option'         => 'broken_link_cancel_pause',
				'cancel_pause_key'    => 'default_broken_link_checker',
				'cancel_pause_option' => 'broken_link_cancel_pause',
				'stuck_threshold'     => 300,
				'max_retries'         => 3,
				'locks_features'      => array( 'broken_link_checker_settings' ),
				// Broken-link checker is a passive HTTP crawler that mostly
				// reads content; it doesn't conflict with heavier scanning
				// processes. The only exception is system-level settings
				// operations (settings_import / reset_all) which rewrite
				// feature config site-wide — broken_link must wait for those
				// since its own enable/config could change mid-scan.
				'cannot_start_while'  => array(
					'settings_import',
					'reset_all',
				),
			)
		);
	}

	public function tailwatch_broken_link_settings() {
		$key                = 'default_feature_settings';
		$option             = 'broken_link_checker_settings';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	public function tailwatch_broken_link_feature_enable() {
		$feature_enable = $this->tailwatch_broken_link_settings();

		if ( empty( $feature_enable ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = $feature_enable['field_1']['options']['option']['selected'] ?? false;

		if ( $selected === true ) {
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

	protected function tailwatch_get_feature_status() {
		return $this->tailwatch_broken_link_feature_enable();
	}

	private function runtime_passed_limit() {
		static $start_time = null;
		if ( $start_time === null ) {
			$start_time = microtime( true );
		}
		return ( microtime( true ) - $start_time ) > self::MAX_EXECUTION_TIME;
	}

	public function tailwatch_get_broken_link_data() {
		$DBModel = new DBModel();
		return $DBModel->get_log_data( 'start_broken_link_checker', 'tailwatch_broken_link_checker' );
	}

	private function update_broken_link_data( array $options ) {
		$DBModel  = new DBModel();
		$existing = $DBModel->get_log_data( 'start_broken_link_checker', 'tailwatch_broken_link_checker' );

		$cancel_pause = array(
			'scan_state'   => 'in-progress',
			'cron_running' => false,
			'started_time' => time(),
		);

		$db_data = array(
			'user_id'       => absint( get_current_user_id() ),
			'child_of'      => 0,
			'key'           => 'start_broken_link_checker',
			'option'        => 'tailwatch_broken_link_checker',
			'value'         => wp_json_encode( $options ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => $existing ? $existing->date_created : current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( $existing ) {
			$update_data = array(
				'value'         => wp_json_encode( $options ),
				'date_modified' => current_time( 'mysql' ),
			);
			$where       = array(
				'key'    => 'start_broken_link_checker',
				'option' => 'tailwatch_broken_link_checker',
			);
			$result      = $DBModel->update_rows( $update_data, $where, self::LOGS_TABLE_NAME );
		} else {
			$result = $DBModel->insert_row( $db_data, $db_data_format, self::LOGS_TABLE_NAME );
		}
	}

	public function insert_cancel_pause_data( $file_key, $scan_type, $process_id = null ) {
		$cancel_pause = array(
			'scan_state'   => 'in-progress',
			'cron_running' => false,
			'file_key'     => $file_key,
			'scan_type'    => $scan_type,
			'process_id'   => $process_id,
			'started_time' => time(),
		);

		$db_data = array(
			'user_id'       => absint( get_current_user_id() ),
			'child_of'      => 0,
			'key'           => 'default_broken_link_checker',
			'option'        => 'broken_link_cancel_pause',
			'value'         => wp_json_encode( $cancel_pause ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => true,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
		$DBModel        = new DBModel();
		$result         = $DBModel->insert_row( $db_data, $db_data_format, self::LOGS_TABLE_NAME );
	}

	public function tailwatch_insert_broken_links( $url, $status_code, $error_message, $parent_type, $parent_id, $anchor_text ) {
		$DBModel  = new DBModel();
		$existing = $this->is_link_in_db( $url, $parent_id );

		$data = array(
			'url'           => $url,
			'status_code'   => $status_code,
			'error_message' => $error_message,
			'parent_type'   => $parent_type,
			'parent_id'     => $parent_id,
			'anchor_text'   => $anchor_text,
			'last_checked'  => current_time( 'mysql' ),
			'dismissed'     => 0,
		);

		$db_data = array(
			'user_id'       => absint( get_current_user_id() ),
			'child_of'      => $parent_id,
			'key'           => 'default_broken_link_logs',
			'option'        => $url,
			'value'         => wp_json_encode( $data ),
			'type'          => (string) $status_code,
			'type_state'    => 'active',
			'date_created'  => $existing ? $existing->date_created : current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( $existing ) {
			$update_data = array(
				'value'         => wp_json_encode( $data ),
				'type'          => (string) $status_code,
				'date_modified' => current_time( 'mysql' ),
			);
			$where       = array(
				'key'      => 'default_broken_link_logs',
				'option'   => $url,
				'child_of' => $parent_id,
			);
			$result      = $DBModel->update_rows( $update_data, $where, self::LOGS_TABLE_NAME );
		} else {
			$result = $DBModel->insert_row( $db_data, $db_data_format, self::LOGS_TABLE_NAME );
		}
	}

	public function tailwatch_start_broken_link_checker( $post_data ) {
		$user_context  = 'system';
		$action_failed = 'broken_link_check_start_failed';
		try {
			// Refuse to start if a conflicting process is currently running.
			// Broken-link's own cannot_start_while is empty (lightweight),
			// so this gate is a no-op today, but the call site is in place
			// for future conflicts.
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'broken_link_checker' );
			if ( null !== $blocked ) {
				return $blocked;
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! $data ) {
				Log::error(
					'Invalid input data for Broken Links',
					array(
						'feature'  => $this->feature,
						'action'   => $action_failed,
						'title'  => 'Broken Links',
						'detail'   => 'Invalid input data for Broken Links',
						'origin'   => $user_context,
						'severity' => 'high',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid input data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( isset( $data['instant_scan'] ) && $data['instant_scan'] === true ) {
				$cron_status = ( new CronHealthService() )->test( 'broken_link_checker' );
				if ( ! $cron_status['success'] ) {
					Log::error(
						$cron_status['message'],
						array(
							'feature'  => $this->feature,
							'action'   => $action_failed,
							'title'  => 'Broken Links',
							'detail'   => $cron_status['message'],
							'origin'   => $user_context,
							'severity' => 'high',
						)
					);
					return array(
						'message' => __( 'Failed to run the Broken Links due to an issue with the cron.', 'tailwatch' ),
						'error'   => $cron_status['message'],
						'code'    => 400,
					);
				}

				$next_scheduled = wp_next_scheduled( 'tailwatch_broken_link_checker_schedule' );
				if ( $next_scheduled ) {
					wp_unschedule_event( $next_scheduled, 'tailwatch_broken_link_checker_schedule' );
				}
				$scan_type = 'on-demand';
				return $this->tailwatch_insert_broken_link_data( $scan_type );
			} else {
				return array(
					'data'    => array(),
					'message' => __( 'Failed to run Broken Links: instant_scan is false.', 'tailwatch' ),
					'code'    => 400,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_start_broken_link_checker: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => $action_failed,
					'title'  => 'Broken Links',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => $user_context,
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while starting Broken Links.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_insert_broken_link_data( $scan_type = 'on-demand' ) {
		try {
			// Ensure log directory exists
			if ( ! is_dir( $this->log_directory ) ) {
				wp_mkdir_p( $this->log_directory );
			}

			if ( file_exists( $this->get_live_logs ) ) {
				$fs = FilesystemService::get_filesystem();
				if ( $fs ) {
					$fs->put_contents( $this->get_live_logs, '', FS_CHMOD_FILE );
				}
			}

			$file_key = time();

			// Create process using ProcessManager
			$process_id = $this->processManager->get_or_create_process( 'broken_link_checker', 'tailwatch_broken_link_checker' );

			$this->currentProcessId = $process_id;

			$this->processManager->heart_beat( $process_id );

			$this->processManager->update_state( $process_id, 'in_progress' );

			$checker_data = array(
				'scan_type'             => $scan_type,
				'batch_offset'          => 0,
				'scanned_urls'          => array(),
				'total_urls'            => 0,
				'status'                => 'running',
				'crawl_offset_posts'    => 0,
				'crawl_offset_comments' => 0,
				'crawl_offset_postmeta' => 0,
				'crawl_offset_usermeta' => 0,
				'crawl_offset_termmeta' => 0,
				'crawl_offset_options'  => 0,
				'current_table'         => 'posts',
				'urls'                  => array(),  // Initialize urls array to prevent null/undefined issues
				'initial_message'       => true,  // Set to true since we're creating it immediately
				'file_key'              => $file_key,
			);

			$this->update_broken_link_data( $checker_data );
			$this->insert_cancel_pause_data( $file_key, $scan_type, $process_id );

			// Create initial log message IMMEDIATELY before scheduling cron
			$message = 'Initializing broken link scanner';
			$this->live_logs->insert_live_logs_records( $message, $this->log_directory, $this->get_live_logs );

			// Add immediate status update
			$this->broken_link_status->update_broken_link_logs_records( 'Starting scan' );

			if ( ! wp_next_scheduled( 'tailwatch_broken_link_checker' ) ) {
				$cron_scheduled = wp_schedule_single_event( time() + 4, 'tailwatch_broken_link_checker' );
				if ( $cron_scheduled ) {
					Log::info(
						'Broken Links process started.',
						array(
							'feature' => $this->feature,
							'action'  => 'broken_link_check_started',
							'title'  => 'Broken Links',
							'origin'  => 'system',
						)
					);
					return array(
						'code'    => 200,
						'data'    => array(),
						'message' => __( 'Broken Links scheduled successfully.', 'tailwatch' ),
					);
				} else {
					Log::error(
						'Cron scheduling failed.',
						array(
							'feature'  => $this->feature,
							'action'   => 'broken_link_check_start_failed',
							'title'  => 'Broken Links Start Failed',
							'detail'   => 'Cron scheduling failed.',
							'origin'   => 'system',
							'severity' => 'high',
						)
					);
					return array(
						'code'    => 500,
						'data'    => array(),
						'message' => __( 'Error: Cron job could not be scheduled.', 'tailwatch' ),
					);
				}
			} else {
				Log::info(
					'Broken Links cron job is already scheduled.',
					array(
						'feature' => $this->feature,
						'action'  => 'broken_link_check_started',
						'title'  => 'Broken Links',
						'origin'  => 'system',
					)
				);
				return array(
					'code'    => 200,
					'data'    => array(),
					'message' => __( 'Cron job already scheduled.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_insert_broken_link_data: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => 'broken_link_check_start_failed',
					'title'  => 'Broken Links Start Failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while starting Broken Links.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_broken_link_checker_with_monitoring() {
		$stop_execution = $this->broken_link_status->tailwatch_stop_broken_link_cron();
		if ( $stop_execution ) {
			return;
		}

		$cancel_pause_data = $this->broken_link_status->broken_link_cancel_pause_data();
		$process_id        = isset( $cancel_pause_data['process_id'] ) ? $cancel_pause_data['process_id'] : ( $this->currentProcessId ?? null );
		if ( ! $process_id ) {
			$process_id                      = $this->processManager->get_or_create_process( 'broken_link_checker', 'tailwatch_broken_link_checker' );
			$this->currentProcessId          = $process_id;
			$cancel_pause_data['process_id'] = $process_id;
			$this->broken_link_status->update_broken_link_cancel_pause( $cancel_pause_data );
		}
		$this->processManager->heart_beat( $process_id );
		$this->processManager->update_state( $process_id, 'in_progress' );

		try {
			$this->tailwatch_broken_link_checker();

			$this->processManager->heart_beat( $process_id );
		} catch ( \Throwable $e ) {
			// Log error and mark process as failed
			$this->processManager->mark_failed( $process_id, $e->getMessage() );

			Log::error(
				'Broken Links cron failed: ' . $e->getMessage(),
				array(
					'feature'  => 'broken_link_checker',
					'action'   => 'broken_link_check_complete_failed',
					'title'  => 'Broken Links Scan Failed',
					'detail'   => $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	public function tailwatch_broken_link_checker() {
		try {

			$scan_data = $this->tailwatch_get_broken_link_data();
			if ( ! $scan_data || empty( $scan_data->value ) ) {
				$this->broken_link_status->update_broken_link_logs_records( 'No scan data found', 'WARN' );
				return;
			}

			$config = json_decode( $scan_data->value, true );

			// Skip initial message creation since it's already created in tailwatch_insert_broken_link_data

			$cancel_pause_data = $this->broken_link_status->broken_link_cancel_pause_data();
			$scan_state        = $cancel_pause_data['scan_state'];

			$cron_running = $cancel_pause_data['cron_running'];
			if ( $cron_running === false ) {
				$cancel_pause_data['cron_running'] = true;
				$this->broken_link_status->update_broken_link_cancel_pause( $cancel_pause_data );
			}

			$cancel_pause_data['function_completed'] = false;
			$cancel_pause_data['function_started']   = true;
			$this->broken_link_status->update_broken_link_cancel_pause( $cancel_pause_data );

			$stop_execution = $this->broken_link_status->tailwatch_stop_broken_link_cron();
			if ( $stop_execution === true ) {
				$this->broken_link_status->update_broken_link_logs_records( 'Scan stopped by user', 'WARN' );
				$this->tailwatch_broken_link_function_completed();
				return;
			}

			// Ensure all required fields are present
			$config = array_merge(
				array(
					'batch_offset'          => 0,
					'scanned_urls'          => array(),
					'total_urls'            => 0,
					'status'                => 'running',
					'urls'                  => array(),
					'current_table'         => 'posts',
					'crawl_offset_posts'    => 0,
					'crawl_offset_comments' => 0,
					'crawl_offset_postmeta' => 0,
					'crawl_offset_usermeta' => 0,
					'crawl_offset_termmeta' => 0,
					'crawl_offset_options'  => 0,
				),
				$config
			);

			$batch_offset     = (int) $config['batch_offset'];
			$scanned_urls     = isset( $config['scanned_urls'] ) ? $config['scanned_urls'] : array();
			$total_urls       = (int) $config['total_urls'];
			$current_table    = $config['current_table'];
			$urls             = $config['urls'];
			$process_complete = false;

			// Crawl content if this is the first batch for the current table
			if ( $batch_offset === 0 ) {
				$this->broken_link_status->update_broken_link_logs_records( "Crawling $current_table" );
				$urls                 = $this->crawl_content( $config );
				$total_urls           = count( $urls );
				$config['urls']       = $urls;
				$config['total_urls'] = $total_urls;
				$config['status']     = 'running';
				$this->update_broken_link_data( $config );

				$this->broken_link_status->update_broken_link_logs_records( "Found $total_urls URLs in $current_table", $total_urls > 0 ? 'OK' : 'INFO' );
			}

			// Process batch
			$batch_urls      = array_slice( $urls, $batch_offset, self::BATCH_SIZE );
			$processed_count = 0;

			foreach ( $batch_urls as $url_data ) {
				if ( $this->runtime_passed_limit() ) {
					$this->broken_link_status->update_broken_link_logs_records( 'Execution time limit reached, pausing batch processing', 'WARN' );
					break;
				}

				$this->broken_link_status->update_broken_link_logs_records( "Checking {$url_data['url']}" );

				try {
					$result = $this->check_url( $url_data['url'] );
					$status = $result['status'];
				} catch ( \Throwable $e ) {
					// If URL checking fails, log it and continue with next URL
					$this->broken_link_status->update_broken_link_logs_records( "Check failed: {$url_data['url']}", 'ERROR' );
					$result = array(
						'status' => 0,
						'error'  => 'URL check exception: ' . $e->getMessage(),
					);
					$status = 0;
				}
				if ( $status < 200 || $status >= 400 ) {
					$this->tailwatch_insert_broken_links(
						$url_data['url'],
						$result['status'],
						isset( $result['error'] ) ? $result['error'] : '',
						$url_data['parent_type'],
						$url_data['parent_id'],
						$url_data['anchor_text']
					);
					$this->broken_link_status->update_broken_link_logs_records( "Broken link detected ($status): {$url_data['url']}", 'ERROR' );
				} else {
					$existing = $this->is_link_in_db( $url_data['url'], $url_data['parent_id'] );
					if ( $existing ) {
						$where   = array(
							'key'      => 'default_broken_link_logs',
							'option'   => $url_data['url'],
							'child_of' => $url_data['parent_id'],
						);
						$DBModel = new DBModel();
						$result  = $DBModel->delete_table_rows( $where, self::LOGS_TABLE_NAME );

						$log_message = $result ? "Removed fixed link: {$url_data['url']}" : "Failed to remove fixed link: {$url_data['url']}";
						$this->broken_link_status->update_broken_link_logs_records( $log_message, $result ? 'OK' : 'ERROR' );
					}
				}
				$scanned_urls[] = $url_data['url'];
				++$processed_count;
			}

			// Update scan progress
			$batch_offset          += $processed_count;
			$config['batch_offset'] = $batch_offset;
			$config['scanned_urls'] = $scanned_urls;

			if ( $batch_offset < $total_urls ) {

				$stop_execution = $this->broken_link_status->tailwatch_stop_broken_link_cron();
				if ( ! $stop_execution ) {
					// Schedule next batch
					wp_schedule_single_event( time() + 4, 'tailwatch_broken_link_checker' );
					$config['status'] = 'running';
					$this->update_broken_link_data( $config );
					$this->broken_link_status->update_broken_link_logs_records( "Processed $batch_offset/$total_urls in $current_table" );
				}
				$this->tailwatch_broken_link_function_completed();
				return;
			} else {
				// Move to next table or complete
				$tables        = array( 'posts', 'comments', 'postmeta', 'usermeta', 'termmeta', 'options' );
				$current_index = array_search( $current_table, $tables );
				if ( $current_index !== false && $current_index < count( $tables ) - 1 ) {
					$next_table              = $tables[ $current_index + 1 ];
					$config['current_table'] = $next_table;
					$config['batch_offset']  = 0;
					$config['urls']          = array();
					$config['total_urls']    = 0;
					// $config['scanned_urls'] = [];
					wp_schedule_single_event( time() + 4, 'tailwatch_broken_link_checker' );
					$config['status'] = 'running';
					$this->update_broken_link_data( $config );

					$this->broken_link_status->update_broken_link_logs_records( "Completed $current_table, moving to $next_table" );
					$this->tailwatch_broken_link_function_completed();
				} else {
					// Scan complete, clean up fixed links
					if ( ! empty( $scanned_urls ) ) {
						$scanned_urls = array_unique( $scanned_urls );
						$this->broken_link_status->update_broken_link_logs_records( 'Cleaning up ' . count( $scanned_urls ) . ' scanned URLs' );
						$this->cleanup_fixed_links( $scanned_urls );
					} else {
						$this->broken_link_status->update_broken_link_logs_records( 'No scanned URLs found for cleanup', 'WARN' );
					}

					$config['status']                = 'completed';
					$config['batch_offset']          = 0;
					$config['urls']                  = array();
					$config['current_table']         = 'posts';
					$config['crawl_offset_posts']    = 0;
					$config['crawl_offset_comments'] = 0;
					$config['crawl_offset_postmeta'] = 0;
					$config['crawl_offset_usermeta'] = 0;
					$config['crawl_offset_termmeta'] = 0;
					$config['crawl_offset_options']  = 0;
					$this->update_broken_link_data( $config );

					$process_complete = true;
				}
			}

			if ( $config['status'] === 'completed' && $process_complete ) {
				$cancel_pause_data = $this->broken_link_status->broken_link_cancel_pause_data();
				if ( $cancel_pause_data['scan_state'] === 'in-progress' ) {
					$cancel_pause_data['scan_state'] = 'completed';
					$this->broken_link_status->update_broken_link_cancel_pause( $cancel_pause_data );
				}

				$this->broken_link_status->update_broken_link_logs_records( 'Broken link scan complete', 'SUCCESS' );

				$this->tailwatch_broken_link_function_completed();

				$this->live_logs->tailwatch_live_logs_completed( true, $this->get_live_logs );

				// Branch the completion log on whether broken links were found.
				// Logger auto-fires the push notification via NotificationActions —
				// title comes from $context['title'], body from $message, severity
				// from the log level (info = clean scan, warning = needs attention).
				$broken_count = count( $this->get_broken_links() );
				if ( 0 === $broken_count ) {
					Log::info(
						"Good news — your latest scan didn't detect any broken links.",
						array(
							'feature'   => $this->feature,
							'action'    => 'broken_link_check_completed',
							'title'     => 'No broken links found',
							'meta_data' => array(
								'feature'      => 'Broken Links',
								'event'        => 'Completed',
								'broken_count' => 0,
							),
						)
					);
				} else {
					$broken_noun = ( 1 === $broken_count ) ? 'broken link' : 'broken links';
					Log::warning(
						"We found {$broken_count} {$broken_noun} on your website that may hurt SEO or frustrate your visitors. This may be caused by missing pages, redirects, typos, or URL changes, etc. View the details or open a support ticket for quick help.",
						array(
							'feature'      => $this->feature,
							'action'       => 'broken_link_check_completed',
							'title'        => "{$broken_count} {$broken_noun} found",
							'broken_count' => $broken_count,
							'meta_data'    => array(
								'feature'      => 'Broken Links',
								'event'        => 'Completed',
								'broken_count' => (int) $broken_count,
							),
						)
					);
				}

				if ( ! empty( $cancel_pause_data['process_id'] ) ) {
					$this->processManager->mark_completed( $cancel_pause_data['process_id'] );
				}
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_broken_link_checker: ' . $e->getMessage(),
				array(
					'feature'  => $this->feature,
					'action'   => 'broken_link_check_complete_failed',
					'title'  => 'Broken Links Scan Failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Broken Links scan failed.', 'tailwatch' ),
			);
		}
	}

	public function tailwatch_broken_link_function_completed() {
		$cancel_pause_data                         = $this->broken_link_status->broken_link_cancel_pause_data();
		$cancel_pause_data['function_completed']   = true;
		$cancel_pause_data['function_started']     = false;
		$cancel_pause_data['completion_timestamp'] = time();
		$this->broken_link_status->update_broken_link_cancel_pause( $cancel_pause_data );
	}

	private function crawl_content( array &$config ) {
		$urls          = array();
		$current_table = $config['current_table'];
		$offset_key    = "crawl_offset_$current_table";
		$offset        = isset( $config[ $offset_key ] ) ? (int) $config[ $offset_key ] : 0;

		if ( $this->runtime_passed_limit() ) {
			return $urls;
		}

		// Track URL extraction statistics
		$stats_start_time = microtime( true );

		$DBModel = new DBModel();
		switch ( $current_table ) {
			case 'posts':
				// Cache revision IDs to avoid expensive query on every call
				static $revision_ids = null;
				if ( $revision_ids === null ) {

					$revision_ids = get_posts(
						array(
							'post_type'      => 'revision',
							'fields'         => 'ids',
							'posts_per_page' => -1,
						)
					);
				}

				$args = array(
					'post_type'      => 'any',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => self::CRAWL_LIMIT,
					'offset'         => $offset,
					'post__not_in'   => $revision_ids, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Revision IDs are bounded by parent post; small set.
				);

				$posts = new \WP_Query( $args );

				$processed_posts = 0;
				while ( $posts->have_posts() ) {
					// Check runtime limit inside loop to prevent timeout
					if ( $this->runtime_passed_limit() ) {
						$this->broken_link_status->update_broken_link_logs_records(
							"Runtime limit reached, processed $processed_posts posts",
							'WARN'
						);
						break;
					}

					$posts->the_post();
					$post_id   = get_the_ID();
					$post_type = get_post_type();

					try {
						$content = get_the_content();

						// Skip shortcode parsing to avoid hangs from external API calls;
						// most shortcodes don't contain URLs anyway.
						$post_urls = $this->extract_urls( $content );

						foreach ( $post_urls as $url_data ) {
							$urls[] = array(
								'url'         => $url_data['url'],
								'parent_type' => $post_type,
								'parent_id'   => $post_id,
								'anchor_text' => $url_data['anchor_text'],
							);
						}

						++$processed_posts;
					} catch ( \Throwable $e ) {
						// Log error but continue processing other posts
					}
				}
				wp_reset_postdata();

				break;

			case 'comments':
				$comments = get_comments(
					array(
						'status' => 'approve',
						'number' => self::CRAWL_LIMIT,
						'offset' => $offset,
					)
				);

				$processed_comments = 0;
				foreach ( $comments as $comment ) {
					// Check runtime limit
					if ( $this->runtime_passed_limit() ) {
						break;
					}

					try {
						// Skip shortcode parsing to avoid hangs
						$content      = $comment->comment_content;
						$comment_urls = $this->extract_urls( $content );

						foreach ( $comment_urls as $url_data ) {
							$urls[] = array(
								'url'         => $url_data['url'],
								'parent_type' => 'comment',
								'parent_id'   => $comment->comment_ID,
								'anchor_text' => $url_data['anchor_text'],
							);
						}
						++$processed_comments;
					} catch ( \Throwable $e ) {
						// Skip malformed row — best-effort URL extraction.
					}
				}

				break;

			case 'postmeta':
				$meta_rows = $DBModel->get_rows(
					'postmeta',
					array( 'meta_id', 'meta_value' ),
					array( 'meta_value' => array( 'LIKE', '%http%' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Broken-link scan over post meta; admin-triggered.
					self::CRAWL_LIMIT,
					$offset
				);

				$processed_meta = 0;
				foreach ( $meta_rows as $meta ) {
					// Check runtime limit
					if ( $this->runtime_passed_limit() ) {
						break;
					}

					try {
						$content = $meta->meta_value;
						// Check if meta_value is JSON-encoded
						if ( is_string( $content ) && ( strpos( $content, '[' ) === 0 || strpos( $content, '{' ) === 0 ) ) {
							$decoded = json_decode( $content, true );
							if ( json_last_error() === JSON_ERROR_NONE ) {
								$content = $this->extract_elementor_content( $decoded );
							}
						}
						// Skip shortcode parsing
						$meta_urls = $this->extract_urls( $content );

						foreach ( $meta_urls as $url_data ) {
							$urls[] = array(
								'url'         => $url_data['url'],
								'parent_type' => 'postmeta',
								'parent_id'   => $meta->meta_id,
								'anchor_text' => $url_data['anchor_text'],
							);
						}
						++$processed_meta;
					} catch ( \Throwable $e ) {
						// Skip malformed row — best-effort URL extraction.
					}
				}

				break;

			case 'usermeta':
				$user_rows = $DBModel->get_rows(
					'usermeta',
					array( 'umeta_id', 'meta_value' ),
					array( 'meta_value' => array( 'LIKE', '%http%' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Broken-link scan over post meta; admin-triggered.
					self::CRAWL_LIMIT,
					$offset
				);

				$processed_user = 0;
				foreach ( $user_rows as $user ) {
					if ( $this->runtime_passed_limit() ) {
						break;
					}

					try {
						$content   = $user->meta_value;
						$user_urls = $this->extract_urls( $content );

						foreach ( $user_urls as $url_data ) {
							$urls[] = array(
								'url'         => $url_data['url'],
								'parent_type' => 'usermeta',
								'parent_id'   => $user->umeta_id,
								'anchor_text' => $url_data['anchor_text'],
							);
						}
						++$processed_user;
					} catch ( \Throwable $e ) {
						// Skip malformed row — best-effort URL extraction.
					}
				}

				break;

			case 'termmeta':
				$term_rows = $DBModel->get_rows(
					'termmeta',
					array( 'meta_id', 'meta_value' ),
					array( 'meta_value' => array( 'LIKE', '%http%' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Broken-link scan over post meta; admin-triggered.
					self::CRAWL_LIMIT,
					$offset
				);

				$processed_term = 0;
				foreach ( $term_rows as $term ) {
					if ( $this->runtime_passed_limit() ) {
						break;
					}

					try {
						$content   = $term->meta_value;
						$term_urls = $this->extract_urls( $content );

						foreach ( $term_urls as $url_data ) {
							$urls[] = array(
								'url'         => $url_data['url'],
								'parent_type' => 'termmeta',
								'parent_id'   => $term->meta_id,
								'anchor_text' => $url_data['anchor_text'],
							);
						}
						++$processed_term;
					} catch ( \Throwable $e ) {
						// Skip malformed row — best-effort URL extraction.
					}
				}

				break;

			case 'options':
				$option_rows = $DBModel->get_rows(
					'options',
					array( 'option_id', 'option_value' ),
					array( 'option_name' => array( 'LIKE', 'widget_%' ) ),
					self::CRAWL_LIMIT,
					$offset
				);

				$processed_options = 0;
				foreach ( $option_rows as $option ) {
					if ( $this->runtime_passed_limit() ) {
						break;
					}

					try {
						$content     = $option->option_value;
						$option_urls = $this->extract_urls( $content );

						foreach ( $option_urls as $url_data ) {
							$urls[] = array(
								'url'         => $url_data['url'],
								'parent_type' => 'option',
								'parent_id'   => $option->option_id,
								'anchor_text' => $url_data['anchor_text'],
							);
						}
						++$processed_options;
					} catch ( \Throwable $e ) {
						// Skip malformed widget option — best-effort URL extraction.
					}
				}

				break;
		}

		$urls                  = array_unique( $urls, SORT_REGULAR );
		$config[ $offset_key ] = $offset + self::CRAWL_LIMIT;

		// Log summary of URL extraction (performance-friendly)
		$stats_end_time = microtime( true );
		$stats_duration = round( $stats_end_time - $stats_start_time, 2 );
		$url_count      = count( $urls );
		if ( $url_count > 0 ) {
			$this->broken_link_status->update_broken_link_logs_records(
				"Extracted $url_count URLs from $current_table in {$stats_duration}s",
				'OK'
			);
		}

		return $urls;
	}

	/**
	 * Extract content from Elementor JSON data recursively.
	 */
	private function extract_elementor_content( $data, $content = '' ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( $key === 'editor' && is_string( $value ) ) {
					$content .= $value . "\n";
				} elseif ( $key === 'settings' && is_array( $value ) && isset( $value['editor'] ) ) {
					$content .= $value['editor'] . "\n";
				} elseif ( $key === 'elements' && is_array( $value ) ) {
					foreach ( $value as $element ) {
						$content .= $this->extract_elementor_content( $element );
					}
				} elseif ( is_array( $value ) ) {
					$content .= $this->extract_elementor_content( $value );
				}
			}
		}
		return $content;
	}

	private function parse_shortcodes( $content ) {
		// Ensure content is a string to avoid type errors
		if ( ! is_string( $content ) ) {
			$content = (string) $content;
		}

		// Process all shortcodes in the content
		return do_shortcode( $content );
	}

	private function extract_urls( $content ) {
		try {
			$urls = array();
			if ( empty( $content ) || ! is_string( $content ) ) {
				return $urls;
			}

			// Track filtered URLs for debugging
			$filtered_count = 0;

			$dom = new \DOMDocument();
			// Suppress warnings and ensure UTF-8 encoding
			@$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

			// Scan <a> tags
			foreach ( $dom->getElementsByTagName( 'a' ) as $link ) {
				$url = $link->getAttribute( 'href' );
				if ( ! empty( $url ) ) {
					// Normalize URL
					$url = $this->normalize_url( $url );
					// Skip invalid URLs (normalize_url returns false for invalid URLs)
					if ( $url !== false ) {
						$urls[] = array(
							'url'         => $url,
							'anchor_text' => trim( $link->textContent ),
						);
					}
				}
			}

			// Scan <img> tags
			foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
				$url = $img->getAttribute( 'src' );
				if ( ! empty( $url ) ) {
					$url = $this->normalize_url( $url );
					// Skip invalid URLs
					if ( $url !== false ) {
						$urls[] = array(
							'url'         => $url,
							'anchor_text' => trim( $img->getAttribute( 'alt' ) ?: '' ),
						);
					}
				}
			}

			// Scan <iframe> tags
			foreach ( $dom->getElementsByTagName( 'iframe' ) as $iframe ) {
				$url = $iframe->getAttribute( 'src' );
				if ( ! empty( $url ) ) {
					$url = $this->normalize_url( $url );
					// Skip invalid URLs
					if ( $url !== false ) {
						$urls[] = array(
							'url'         => $url,
							'anchor_text' => '',
						);
					}
				}
			}

			// Scan <script> tags
			foreach ( $dom->getElementsByTagName( 'script' ) as $script ) {
				$url = $script->getAttribute( 'src' );
				if ( ! empty( $url ) ) {
					$url = $this->normalize_url( $url );
					// Skip invalid URLs
					if ( $url !== false ) {
						$urls[] = array(
							'url'         => $url,
							'anchor_text' => '',
						);
					}
				}
			}

			// Scan <link> tags
			foreach ( $dom->getElementsByTagName( 'link' ) as $link ) {
				$url = $link->getAttribute( 'href' );
				if ( ! empty( $url ) ) {
					$url = $this->normalize_url( $url );
					// Skip invalid URLs
					if ( $url !== false ) {
						$urls[] = array(
							'url'         => $url,
							'anchor_text' => '',
						);
					}
				}
			}

			// Scan <video> tags
			foreach ( $dom->getElementsByTagName( 'video' ) as $video ) {
				$url = $video->getAttribute( 'src' );
				if ( ! empty( $url ) ) {
					$url = $this->normalize_url( $url );
					// Skip invalid URLs
					if ( $url !== false ) {
						$urls[] = array(
							'url'         => $url,
							'anchor_text' => '',
						);
					}
				}
			}

			// Remove duplicates based on URL
			$unique_urls = array();
			$seen        = array();
			foreach ( $urls as $url_data ) {
				if ( ! in_array( $url_data['url'], $seen ) ) {
					$seen[]        = $url_data['url'];
					$unique_urls[] = $url_data;
				}
			}

			return $unique_urls;
		} catch ( \Throwable $th ) {
			return array();
		}
	}


	private function normalize_url( $url, $base_url = null ) {
		$url = trim( $url, " \t\n\r\0\x0B\"'" );
		$url = wp_unslash( $url );

		// Skip empty or whitespace-only URLs
		if ( empty( $url ) ) {
			return false;
		}

		// Skip anchor-only links (in-page navigation)
		// Examples: #, #section, #test123
		if ( $url[0] === '#' ) {
			return false;
		}

		// Skip non-HTTP(S) protocols that shouldn't be checked
		$invalid_protocols = array(
			'javascript:',
			'mailto:',
			'tel:',
			'sms:',
			'data:',
			'blob:',
			'about:',
			'chrome:',
			'file:',
		);

		foreach ( $invalid_protocols as $protocol ) {
			if ( stripos( $url, $protocol ) === 0 ) {
				return false;
			}
		}

		// Strip fragment identifier (anchor) before checking
		// Example: http://example.com/page#section → http://example.com/page
		// Fragments are never sent to the server in HTTP requests
		$fragment_pos = strpos( $url, '#' );
		if ( $fragment_pos !== false ) {
			$url = substr( $url, 0, $fragment_pos );
			// If URL is now empty after removing fragment, skip it
			if ( empty( $url ) ) {
				return false;
			}
		}

		// Fix double slashes in protocol
		if ( preg_match( '~^https?:/([^/])~i', $url, $matches ) ) {
			$url = preg_replace( '~^(https?:)/([^/])~i', '$1//$2', $url );
		}

		// Handle relative URLs
		if ( ! preg_match( '~^https?://~i', $url ) ) {
			$site_url = $base_url ?: home_url();

			// Absolute path: /page.html
			if ( ! empty( $url ) && $url[0] === '/' ) {
				$url = rtrim( $site_url, '/' ) . $url;
			}
			// Relative path: page.html, ./page.html, ../page.html, subfolder/page.html
			else {
				$parsed_base = wp_parse_url( $site_url );
				$base_path   = isset( $parsed_base['path'] ) ? $parsed_base['path'] : '';

				// Remove filename from path if present
				if ( ! empty( $base_path ) && substr( $base_path, -1 ) !== '/' ) {
					$base_path = dirname( $base_path );
					if ( $base_path === '\\' || $base_path === '.' ) {
						$base_path = '';
					}
				}

				// Build full URL
				$scheme = isset( $parsed_base['scheme'] ) ? $parsed_base['scheme'] : 'http';
				$host   = isset( $parsed_base['host'] ) ? $parsed_base['host'] : 'localhost';
				$port   = isset( $parsed_base['port'] ) ? ':' . $parsed_base['port'] : '';

				$url = $scheme . '://' . $host . $port . rtrim( $base_path, '/' ) . '/' . ltrim( $url, '/' );
			}
		}

		// Check for malformed URLs like "http://doc/" where domain is too short
		if ( preg_match( '~^https?://([^/]+)~i', $url, $matches ) ) {
			$domain = $matches[1];
			// Extract domain without port
			$domain_without_port = preg_replace( '/:\d+$/', '', $domain );

			// Skip URLs with obviously invalid domains (less than 3 chars, no dots for external domains)
			// Use explicit === false check for clarity
			$has_dot = ( strpos( $domain_without_port, '.' ) !== false );
			if ( strlen( $domain_without_port ) < 3 || ( $domain_without_port !== 'localhost' && ! $has_dot ) ) {
				return false; // Return false for invalid URLs
			}
		}

		// Normalize multiple slashes AFTER handling relative URLs
		// But protect the protocol double slashes
		$url = preg_replace( '~(?<!:)//+~', '/', $url );

		// Fix protocol again to ensure it's properly formatted
		$url = preg_replace( '~^(https?:)/([^/])~i', '$1//$2', $url );

		// Resolve relative path segments (. and ..)
		// Example: http://example.com/path/../page.html → http://example.com/page.html
		if ( preg_match( '~^(https?://[^/]+)(.*)$~i', $url, $matches ) ) {
			$base = $matches[1];
			$path = $matches[2];

			// Split path into segments
			$segments = explode( '/', $path );
			$resolved = array();

			foreach ( $segments as $segment ) {
				if ( $segment === '' || $segment === '.' ) {
					// Skip empty segments and current directory references
					continue;
				} elseif ( $segment === '..' ) {
					// Go up one directory (remove last segment)
					if ( count( $resolved ) > 0 ) {
						array_pop( $resolved );
					}
				} else {
					// Normal segment
					$resolved[] = $segment;
				}
			}

			// Rebuild URL
			$url = $base . '/' . implode( '/', $resolved );

		}

		// Special handling for localhost: Force HTTP instead of HTTPS
		// HTTPS on localhost often fails due to SSL certificate issues
		if ( preg_match( '~^https://localhost~i', $url ) ) {
			$url = preg_replace( '~^https://~i', 'http://', $url );
		}

		// Skip WordPress admin PHP endpoints: auth-gated, return 302/403 anonymously,
		// and a GET could trigger side effects in plugins that act on admin URLs.
		$admin_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $admin_path && preg_match( '~/wp-admin/.*\.php$~i', $admin_path ) ) {
			return false;
		}

		return $url;
	}

	private function check_url( $url ) {
		// Normalize URL using centralized function
		$normalized_url = $this->normalize_url( $url );
		// Check if normalization failed
		if ( $normalized_url === false ) {
			return array(
				'status' => 0,
				'error'  => 'Malformed URL',
			);
		}

		$url = $normalized_url;

		// Check if we've already checked this URL in this session (cache)
		if ( isset( self::$url_check_cache[ $url ] ) ) {
			return self::$url_check_cache[ $url ];
		}

		// Skip invalid URLs instead of returning error
		if ( ! preg_match( '~^https?://~i', $url ) ) {
			return array(
				'status' => 0,
				'error'  => 'Invalid URL scheme',
			);
		}

		// Additional validation: check if URL has a valid domain
		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) || ! filter_var( $parsed['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			return array(
				'status' => 0,
				'error'  => 'Invalid domain',
			);
		}

		// SSRF guard: author-role users can embed URLs in posts that this cron
		// then fetches with site-level credentials; the persisted status code
		// is a presence oracle for whatever is reachable from the server.
		if ( ! $this->host_is_safe( $parsed['host'] ) ) {
			return array(
				'status' => 0,
				'error'  => 'Blocked: target is a private or loopback host',
			);
		}

		try {
			// WordPress HTTP API. wp_remote_get does not throw on 4xx/5xx — it
			// returns a successful response object whose status code we read
			// downstream; that matches the previous `'http_errors' => false`
			// behaviour. Network-level failures (timeout, DNS, SSL) come back
			// as a WP_Error.
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 5,
					'sslverify'   => true,
					'user-agent'  => 'Tailwatch Broken Links/1.0',
					'headers'     => array(
						'Accept' => '*/*',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();

				// Normalize common transport-layer errors into short labels
				// that match the previous Guzzle-era strings so anything
				// downstream (logs, dashboard cards) keeps the same text.
				$message_lc = strtolower( (string) $error_message );
				if ( strpos( $message_lc, 'timeout' ) !== false || strpos( $message_lc, 'timed out' ) !== false ) {
					$error_message = 'Connection timeout';
				} elseif ( strpos( $message_lc, 'could not resolve host' ) !== false || strpos( $message_lc, 'name or service not known' ) !== false || strpos( $message_lc, 'name resolution' ) !== false ) {
					$error_message = 'Host not found (DNS error)';
				} elseif ( strpos( $message_lc, 'ssl' ) !== false || strpos( $message_lc, 'certificate' ) !== false ) {
					$error_message = 'SSL certificate error';
				} else {
					$error_message = 'Connection failed';
				}

				$result = array(
					'status' => 0,
					'error'  => $error_message,
				);
			} else {
				$status_code = (int) wp_remote_retrieve_response_code( $response );
				$result      = array( 'status' => $status_code );
			}
		} catch ( \Throwable $e ) {
			// Catch any other exceptions
			$result = array(
				'status' => 0,
				'error'  => 'Unexpected error: ' . $e->getMessage(),
			);
		}

		// Cache the result for this session
		self::$url_check_cache[ $url ] = $result;

		return $result;
	}

	/**
	 * SSRF guard: rejects hosts that resolve to any private / reserved IP.
	 * Unresolvable hosts return false (conservative — link is broken anyway).
	 */
	private function host_is_safe( $host ) {
		$host = strtolower( trim( (string) $host ) );
		// IPv6 literals in URLs are bracketed (`[::1]`); strip so the IP-validator branch sees a raw address.
		if ( '' !== $host && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}
		if ( '' === $host ) {
			return false;
		}

		$loopback_names = array( 'localhost', 'ip6-localhost', 'ip6-loopback', 'localhost.localdomain' );
		if ( in_array( $host, $loopback_names, true ) ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS lookup may emit warnings on hosts without resolver; result is null-checked below.
		$ipv4 = @gethostbynamel( $host );
		$ipv4 = is_array( $ipv4 ) ? $ipv4 : array();

		$ipv6 = array();
		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS lookup may emit warnings on hosts without resolver; result is null-checked below.
			$aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ipv6[] = $record['ipv6'];
					}
				}
			}
		}

		$ips = array_merge( $ipv4, $ipv6 );
		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) ) {
				return false;
			}
		}

		return true;
	}

	private function is_link_in_db( $url, $parent_id ) {
		$DBModel = new DBModel();
		return $DBModel->get_log_data( 'default_broken_link_logs', $url, array( 'child_of' => $parent_id ) );
	}

	private function cleanup_fixed_links( $scanned_urls ) {
		$DBModel         = new DBModel();
		$dismissed_links = $DBModel->get_log_data( 'default_broken_link_logs', null, array(), true );

		$this->broken_link_status->update_broken_link_logs_records( 'Cleaning ' . count( $dismissed_links ) . ' dismissed links' );

		foreach ( $dismissed_links as $link ) {

			if ( ! in_array( $link->option, $scanned_urls ) ) {
				$where  = array(
					'key'      => 'default_broken_link_logs',
					'option'   => $link->option,
					'child_of' => $link->child_of,
				);
				$result = $DBModel->delete_table_rows( $where, self::LOGS_TABLE_NAME );

				$log_message = $result ? "Removed: {$link->option}" : "Failed to remove: {$link->option}";
				$this->broken_link_status->update_broken_link_logs_records( $log_message, $result ? 'OK' : 'ERROR' );
			}
		}
	}

	public function get_broken_links() {
		$DBModel = new DBModel();
		$results = $DBModel->get_log_data( 'default_broken_link_logs', null, array(), true );

		$filtered = array();
		foreach ( $results as $result ) {
			$value = json_decode( $result->value, true );
			if ( ! isset( $value['dismissed'] ) || ! $value['dismissed'] ) {
				$result->value = $value;
				$filtered[]    = $result;
			}
		}

		return $filtered;
	}

	/**
	 * Check if push notifications are enabled for broken link checker.
	 *
	 * @return bool Whether push notifications are enabled.
	 */
	public function broken_link_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'broken_link_checker_settings';
		$field_name        = 'field_1';
		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}
}
