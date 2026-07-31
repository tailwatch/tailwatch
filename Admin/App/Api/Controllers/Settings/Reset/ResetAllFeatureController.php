<?php

namespace Tailwatch\Admin\App\Api\Controllers\Settings\Reset;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\OptionsModel;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Features\SecurityFeaturesVerifyController;
use Tailwatch\Admin\App\Api\Controllers\Features\FeatureCacheController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;

class ResetAllFeatureController {

	private $processManager;
	private $currentProcessId;
	public function __construct() {
		$this->processManager = new ProcessManager();
		$this->register_process_monitoring();

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_reset_all_settings_batch_cron', array( $this, 'wptw_reset_all_settings_batch_cron' ) );
	}

	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'       => 'reset_all',
				'cron_hooks'         => array( 'wptw_reset_all_settings_batch_cron' ),
				'stuck_threshold'    => 300,
				'max_retries'        => 3,
				// Reset-all clears feature configuration site-wide; it cannot
				// run while any scanning/restoration/maintenance process is
				// active or while a settings_import is mid-flight.
				'cannot_start_while' => array(
					'backup',
					'backup_download',
					'db_optimize',
					'files_integrity',
					'baseline_update',
					'search_replace',
					'broken_link_checker',
					'migration',
					'malware_scan',
					'malware_restore',
					'restore',
					'settings_import',
					'hardening_audit',
				),
			)
		);
	}


	public function wptw_start_reset_all_settings( $post_data = null ) {
		try {
			// Refuse to start if a conflicting process is currently running.
			$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'reset_all' );
			if ( null !== $blocked ) {
				return $blocked;
			}

			// Two independent switches (at least one required):
			//   reset_settings — restore feature settings to defaults (the classic reset).
			//   reset_data     — wipe ALL user data (logs, blocks, scan history, file baseline,
			//                    backups, on-disk logs) for a fresh-setup state.
			// The license/connection (the site_settings row + auth keys) is NEVER touched by
			// either. A payload with neither key defaults to settings-only, so an older UI that
			// sends nothing keeps working.
			$data           = is_string( $post_data ) ? json_decode( wp_unslash( $post_data ), true ) : ( is_array( $post_data ) ? $post_data : array() );
			$data           = is_array( $data ) ? $data : array();
			$reset_settings = array_key_exists( 'reset_settings', $data ) ? (bool) $data['reset_settings'] : true;
			$reset_data     = ! empty( $data['reset_data'] );

			if ( ! $reset_settings && ! $reset_data ) {
				return array(
					'code'    => 400,
					'message' => __( 'Select at least one option: reset settings or delete data.', 'tailwatch' ),
				);
			}

			$DBModel = new DBModel();
			$DBModel->delete_all_transients( '_transient_wptw_reset_' );

			// Settings-phase inputs (empty when reset_settings is off). The license-bearing
			// `site_settings` row is always excluded so the connection survives the reset.
			$defaults    = array();
			$option_keys = array();
			if ( $reset_settings ) {
				$options_model = new OptionsModel();
				$defaults      = $options_model->wptw_complete_site_data();
				unset( $defaults['site_settings'] );
				$option_keys = array_keys( $defaults );
			}

			// Data-phase steps (empty when reset_data is off). Fast steps first; the two
			// directory purges are time-budgeted and may span several ticks.
			$data_steps = $reset_data ? array(
				'truncate_logs',
				'truncate_baseline',
				'truncate_scans',
				'purge_backup_dir',
				'purge_logs_dir',
				'reset_pro_data',
			) : array();

			$total_steps = count( $option_keys ) + count( $data_steps );

			$reset_id = 'wptw_reset_' . bin2hex( random_bytes( 13 ) );

			$process_id             = $this->processManager->get_or_create_process(
				'reset_all',
				'wptw_reset_all_settings_batch_cron',
				array(
					'reset_id' => $reset_id,
					'total'    => $total_steps,
				)
			);
			$this->currentProcessId = $process_id;

			$batch_state      = array(
				'reset_settings' => $reset_settings,
				'reset_data'     => $reset_data,
				'option_keys'    => $option_keys,
				'defaults'       => $defaults,
				'current_index'  => 0,
				'settings_done'  => ! $reset_settings,
				'data_steps'     => $data_steps,
				'data_index'     => 0,
				'data_done'      => ! $reset_data,
				'total_steps'    => $total_steps,
				'cron_running'   => false,
				'process_id'     => $process_id,
				'started_time'   => time(),
			);
			$transient_result = set_transient( $reset_id, $batch_state, 60 * 60 ); // 60 minutes

			if ( ! $transient_result ) {
				Log::error(
					'Failed to store reset data in transient: ' . $reset_id,
					array(
						'feature'  => 'settings',
						'action'   => 'settings_start_reset_all_failed',
						'title'  => 'Settings Reset All Failed',
						'detail'   => 'Failed to store reset data in transient: ' . $reset_id,
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to prepare reset process.', 'tailwatch' ),
				);
			}

			if ( ! wp_next_scheduled( 'wptw_reset_all_settings_batch_cron', array( $reset_id ) ) ) {
				$schedule_result = wp_schedule_single_event( time() + 5, 'wptw_reset_all_settings_batch_cron', array( $reset_id ) );
				if ( ! $schedule_result ) {
					Log::error(
						'Failed to schedule cron job for reset: ' . $reset_id,
						array(
							'feature'  => 'settings',
							'action'   => 'settings_start_reset_all_failed',
							'title'  => 'Settings Reset All Failed',
							'detail'   => 'Failed to schedule cron job for reset: ' . $reset_id,
							'origin'   => 'system',
							'severity' => 'high',
						)
					);
					return array(
						'code'    => 500,
						'message' => __( 'Failed to start reset process.', 'tailwatch' ),
					);
				}
				$this->processManager->heart_beat( $process_id );
				$this->processManager->update_state( $process_id, 'in_progress' );
			}

			Log::info(
				'Reset process started successfully with ID: ' . $reset_id . ', steps: ' . $total_steps . ' (settings=' . ( $reset_settings ? 'yes' : 'no' ) . ', data=' . ( $reset_data ? 'yes' : 'no' ) . ')',
				array(
					'feature' => 'settings',
					'action'  => 'settings_all_reset_started',
					'title'  => 'Settings Reset All',
					'origin'  => 'system',
				)
			);
			return array(
				'code'     => 200,
				'message'  => __( 'Reset process started. Processing in batches.', 'tailwatch' ),
				'reset_id' => $reset_id,
				'total'    => $total_steps,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception occurred while starting reset process: ' . $e->getMessage(),
				array(
					'feature'  => 'settings',
					'action'   => 'settings_start_reset_all_failed',
					'title'  => 'Settings Reset All Failed',
					'detail'   => 'Exception occurred while starting reset process: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			if ( ! empty( $this->currentProcessId ) ) {
				$this->processManager->mark_failed( $this->currentProcessId, $e->getMessage() );
			}

			return array(
				'code'    => 500,
				'message' => __( 'An error occurred while starting the reset process.', 'tailwatch' ),
			);
		}
	}

	public function wptw_reset_all_settings_batch_cron( $reset_id ) {
		try {
			$batch_size  = 5;
			$batch_state = get_transient( $reset_id );
			if ( ! $batch_state || ! is_array( $batch_state['option_keys'] ) ) {
				Log::error(
					'Invalid or expired batch state for reset_id: ' . $reset_id,
					array(
						'feature'  => 'settings',
						'action'   => 'settings_all_reset_batch_process_failed',
						'title'  => 'Settings Reset All Failed',
						'detail'   => 'Invalid or expired batch state for reset_id: ' . $reset_id,
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return;
			}
			$batch_state['cron_running']       = true;
			$batch_state['function_started']   = true;
			$batch_state['function_completed'] = false;

			$total_options = isset( $batch_state['total_steps'] ) ? (int) $batch_state['total_steps'] : count( $batch_state['option_keys'] );
			$process_id    = isset( $batch_state['process_id'] ) ? $batch_state['process_id'] : ( $this->currentProcessId ?? null );
			if ( ! $process_id ) {
				$process_id                = $this->processManager->get_or_create_process(
					'reset_all',
					'wptw_reset_all_settings_batch_cron',
					array(
						'reset_id' => $reset_id,
						'total'    => $total_options,
					)
				);
				$this->currentProcessId    = $process_id;
				$batch_state['process_id'] = $process_id;
			}
			$this->processManager->heart_beat( $process_id );
			$this->processManager->update_state( $process_id, 'in_progress' );

			set_transient( $reset_id, $batch_state, 60 * 60 );

			$reset_settings = ! empty( $batch_state['reset_settings'] );
			$reset_data     = ! empty( $batch_state['reset_data'] );

			// ---- Phase A: reset feature settings to defaults (chunked, 5/tick) ----
			if ( $reset_settings && empty( $batch_state['settings_done'] ) ) {
				$DBModel       = new DBModel();
				$format        = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
				$option_keys   = $batch_state['option_keys'];
				$defaults      = $batch_state['defaults'];
				$current_index = $batch_state['current_index'] ?? 0;
				$total         = count( $option_keys );
				$processed     = 0;
				for ( $i = $current_index; $i < $total && $processed < $batch_size; $i++, $processed++ ) {
					$option = $option_keys[ $i ];
					$row    = $defaults[ $option ];
					$where  = array(
						'key'    => $row['key'],
						'option' => $row['option'],
					);
					if ( ! $DBModel->update_rows( $row, $where ) ) {
						$DBModel->insert_row( $row, $format );
					}

					// Post-write hook so the pro plugin re-applies the per-row lock/unlock the
					// bare-default rewrite just wiped — this is what keeps a connected license's
					// features open after the reset. Same hook the per-feature reset path fires.
					do_action( 'wptw_apply_plan_features_to_db', $option );
				}
				$batch_state['current_index'] = $current_index + $processed;
				if ( $batch_state['current_index'] >= $total ) {
					$batch_state['settings_done'] = true;
				}
				if ( empty( $batch_state['settings_done'] ) ) {
					$this->wptw_reschedule_batch( $reset_id, $batch_state ); // more settings next tick
					return;
				}
			}

			// ---- Phase B: wipe all user data (steps; the two dir purges are time-budgeted) ----
			if ( $reset_data && empty( $batch_state['data_done'] ) ) {
				$deadline   = time() + 20;
				$data_steps = isset( $batch_state['data_steps'] ) ? $batch_state['data_steps'] : array();
				$di         = $batch_state['data_index'] ?? 0;
				while ( $di < count( $data_steps ) ) {
					$step_complete = $this->wptw_run_data_reset_step( $data_steps[ $di ], $deadline );
					if ( ! $step_complete ) {
						break; // step (e.g. a large directory purge) needs another tick
					}
					++$di;
					if ( time() >= $deadline ) {
						break;
					}
				}
				$batch_state['data_index'] = $di;
				if ( $di >= count( $data_steps ) ) {
					$batch_state['data_done'] = true;
				}
				if ( empty( $batch_state['data_done'] ) ) {
					$this->wptw_reschedule_batch( $reset_id, $batch_state ); // more data next tick
					return;
				}
			}

			// ---- Completion (both requested phases finished) ----
			$batch_state['completed']    = true;
			$batch_state['cron_running'] = false;
			if ( ! empty( $process_id ) ) {
				$this->processManager->mark_completed( $process_id );
				$this->currentProcessId = null;
			}

			try {
				$cache = new FeatureCacheController();
				$cache->invalidate_all_caches();
				// Also clear OptionsController's static request cache so the
				// SecurityFeaturesVerifyController call below reads fresh
				// post-reset feature options instead of stale in-memory values.
				OptionsController::invalidate_request_cache();
			} catch ( \Throwable $e ) {
				// Silently fail cache invalidation — the reset itself already succeeded.
			}

			Log::info(
				'Reset completed for reset_id: ' . $reset_id,
				array(
					'feature' => 'settings',
					'action'  => 'settings_all_reset_completed',
					'title'   => 'Settings Reset All',
					'origin'  => 'system',
				)
			);
			set_transient( $reset_id, $batch_state, 60 * 60 );

			// Re-establish feature state only when settings were reset (a data-only wipe leaves
			// the feature rows untouched, so there is nothing to re-verify).
			if ( $reset_settings ) {
				// Settings are back to defaults, so unschedule every plugin/feature cron and drop
				// the cron bookkeeping. Each enabled feature re-registers its own cron on the next
				// load ("schedule if missing"), so this realigns the schedule to the reset (default)
				// state and drops crons of features that are now off — fixing the orphaned-cron gap
				// the direct-row rewrite would otherwise leave. clear_all_cron_events() sweeps the
				// canonical hook list (pro's included via wptw_register_cron_hooks) and does NOT
				// touch this reset's cron or the verify cron, so it is safe to run mid-completion.
				CronJobManager::clear_all_cron_events();
				delete_option( 'wptw_created_cron_jobs' );
				delete_option( 'wptw_paused_cron_jobs' );
				delete_option( 'wptw_custom_schedules' );

				$security_controller = new SecurityFeaturesVerifyController();
				$security_controller->wptw_start_security_features_process();
			}
			delete_transient( $reset_id );
		} catch ( \Throwable $e ) {
			$process_id = isset( $batch_state['process_id'] ) ? $batch_state['process_id'] : ( $this->currentProcessId ?? null );
			if ( ! empty( $process_id ) ) {
				$this->processManager->mark_failed( $process_id, $e->getMessage() );
			}
			Log::error(
				'Exception occurred in reset batch cron for reset_id: ' . $reset_id . ' - ' . $e->getMessage(),
				array(
					'feature'  => 'settings',
					'action'   => 'settings_all_reset_batch_process_failed',
					'title'  => 'Settings Reset All Failed',
					'detail'   => 'Exception occurred in reset batch cron for reset_id: ' . $reset_id . ' - ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	/**
	 * Persist mid-run progress flags and schedule the next batch tick.
	 */
	private function wptw_reschedule_batch( $reset_id, $batch_state ) {
		$batch_state['cron_running']         = true;
		$batch_state['completion_timestamp'] = time();
		$batch_state['function_completed']   = true;
		$batch_state['function_started']     = false;
		set_transient( $reset_id, $batch_state, 60 * 60 );
		wp_schedule_single_event( time() + 5, 'wptw_reset_all_settings_batch_cron', array( $reset_id ) );
	}

	/**
	 * Run one data-wipe step. Returns true when finished, false when a time-budgeted step (a
	 * directory purge) needs another tick. NEVER touches the license row (site_settings) or the
	 * auth keys — only user DATA (activity/blocks/scan-history tables, on-disk backups + logs).
	 *
	 * @param string $step     Step name.
	 * @param int    $deadline Unix-ts wall-clock budget for this tick.
	 * @return bool True when the step is complete.
	 */
	private function wptw_run_data_reset_step( $step, $deadline ) {
		global $wpdb;
		switch ( $step ) {
			case 'truncate_logs':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reset: truncate the plugin's own data table; identifier via %i, no user input.
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME ) );
				return true;
			case 'truncate_baseline':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reset: fixed table-name constant.
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . WPTW_DB_FILEMON_BASELINE_TABLE ) );
				return true;
			case 'truncate_scans':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reset: fixed table-name constant.
				$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . WPTW_DB_FILEMON_SCANS_TABLE ) );
				return true;
			case 'purge_backup_dir':
				return $this->wptw_purge_dir_contents( WPTW_BACKUP_DIR, $deadline );
			case 'purge_logs_dir':
				return $this->wptw_purge_dir_contents( WPTW_LOGS_DIRECTORY, $deadline );
			case 'reset_pro_data':
				// Pro (if active) clears its own data — user-meta blocks, GeoIP db, country-rule
				// caches, pro logs. No-op when pro is inactive.
				do_action( 'wptw_reset_all_data' );
				return true;
			default:
				return true;
		}
	}

	/**
	 * Delete the CONTENTS of a directory (files + subdirs), keeping the directory itself, with a
	 * wall-clock budget so a huge tree (multi-GB backups) spans several ticks — WP_Filesystem's
	 * recursive delete has no time budget and would time out. Returns true when empty, false when
	 * the budget was hit (resume next tick; deletion is permanent so it always progresses). Never
	 * follows symlinks out of the tree.
	 *
	 * @param string $dir      Absolute directory path.
	 * @param int    $deadline Unix-ts budget.
	 * @return bool True when fully emptied.
	 */
	private function wptw_purge_dir_contents( $dir, $deadline ) {
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- time-budgeted purge of the plugin's own data dirs; WP_Filesystem::delete can't be interrupted for the time budget.
		$items = @scandir( $dir );
		if ( false === $items ) {
			return true;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( time() >= $deadline ) {
				return false;
			}
			$path = $dir . '/' . $item;
			if ( is_link( $path ) ) {
				@unlink( $path );
				continue;
			}
			if ( is_dir( $path ) ) {
				if ( ! $this->wptw_purge_dir_contents( $path, $deadline ) ) {
					return false;
				}
				@rmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}

	public function wptw_reset_all_settings_status( $post_data = null ) {
		try {
			$reset_id = null;
			if ( $post_data ) {
				$json_data = is_string( $post_data ) ? wp_unslash( $post_data ) : '';
				$data      = json_decode( $json_data, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					Log::error(
						'Invalid JSON data for reset status check: ' . json_last_error_msg(),
						array(
							'feature'  => 'settings',
							'action'   => 'settings_all_reset_status_verify_failed',
							'title'  => 'Settings Reset All Failed',
							'detail'   => 'Invalid JSON data for reset status check: ' . json_last_error_msg(),
							'origin'   => 'system',
							'severity' => 'high',
						)
					);
					return array(
						'code'    => 400,
						'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
					);
				}

				$reset_id = isset( $data['reset_id'] ) ? $data['reset_id'] : null;
			}
			// reset_ids we mint are `'wptw_reset_' . uniqid()`; reject
			// other shapes before the dynamic `get_transient()`.
			if ( ! empty( $reset_id ) && ! ( is_string( $reset_id ) && preg_match( '/^wptw_reset_[a-f0-9]{26}$/', $reset_id ) ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'Invalid reset_id format.', 'tailwatch' ),
				);
			}
			if ( ! empty( $reset_id ) ) {
				$batch_state = get_transient( $reset_id );
				if ( ! $batch_state ) {
					return array(
						'code'      => 200,
						'message'   => __( 'Reset process completed.', 'tailwatch' ),
						'completed' => true,
					);
				} else {
					$current_index        = ( $batch_state['current_index'] ?? 0 ) + ( $batch_state['data_index'] ?? 0 );
					$total                = isset( $batch_state['total_steps'] ) ? (int) $batch_state['total_steps'] : count( $batch_state['option_keys'] );
					$cron_running         = $batch_state['cron_running'] ?? false;
					$completed            = isset( $batch_state['completed'] ) ? $batch_state['completed'] : false;
					$function_started     = isset( $batch_state['function_started'] ) ? $batch_state['function_started'] : false;
					$function_completed   = isset( $batch_state['function_completed'] ) ? $batch_state['function_completed'] : false;
					$completion_timestamp = isset( $batch_state['completion_timestamp'] ) ? $batch_state['completion_timestamp'] : null;

					return array(
						'code'                 => 200,
						'message'              => $completed ? 'Reset process completed.' : 'Reset process in progress.',
						'completed'            => $completed,
						'cron_running'         => $cron_running,
						'current_index'        => $current_index,
						'total'                => $total,
						'reset_id'             => $reset_id,
						'function_started'     => $function_started,
						'function_completed'   => $function_completed,
						'completion_timestamp' => $completion_timestamp,
						'current_timestamp'    => time(),
					);
				}
			} else {
				$DBModel  = new DBModel();
				$reset_id = $DBModel->get_latest_reset_option_name();
				if ( ! empty( $reset_id ) ) {
					$batch_state = get_transient( $reset_id );
					if ( $batch_state && isset( $batch_state['option_keys'] ) ) {
						$current_index        = ( $batch_state['current_index'] ?? 0 ) + ( $batch_state['data_index'] ?? 0 );
						$total                = isset( $batch_state['total_steps'] ) ? (int) $batch_state['total_steps'] : count( $batch_state['option_keys'] );
						$cron_running         = $batch_state['cron_running'] ?? false;
						$completed            = isset( $batch_state['completed'] ) ? $batch_state['completed'] : ( $current_index >= $total );
						$function_started     = isset( $batch_state['function_started'] ) ? $batch_state['function_started'] : false;
						$function_completed   = isset( $batch_state['function_completed'] ) ? $batch_state['function_completed'] : false;
						$completion_timestamp = isset( $batch_state['completion_timestamp'] ) ? $batch_state['completion_timestamp'] : null;

						return array(
							'code'                 => 200,
							'message'              => $completed ? 'Reset process completed.' : 'Reset process in progress.',
							'completed'            => $completed,
							'cron_running'         => $cron_running,
							'current_index'        => $current_index,
							'total'                => $total,
							'reset_id'             => $reset_id,
							'function_started'     => $function_started,
							'function_completed'   => $function_completed,
							'completion_timestamp' => $completion_timestamp,
							'current_timestamp'    => time(),
						);
					}
				}
				return array(
					'code'      => 200,
					'message'   => __( 'No reset process running.', 'tailwatch' ),
					'completed' => true,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception occurred during reset status check: ' . $e->getMessage(),
				array(
					'feature'  => 'settings',
					'action'   => 'settings_all_reset_status_verify_failed',
					'title'  => 'Settings Reset All Failed',
					'detail'   => 'Exception occurred during reset status check: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An error occurred while checking reset status.', 'tailwatch' ),
			);
		}
	}

	public function wptw_reset_cron_if_failed( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON data for cron restart: ' . json_last_error_msg(),
					array(
						'feature'  => 'settings',
						'action'   => 'settings_all_reset_cron_retry_failed',
						'title'  => 'Settings Reset All Failed',
						'detail'   => 'Invalid JSON data for cron restart: ' . json_last_error_msg(),
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
				);
			}

			$reset_id = isset( $data['reset_id'] ) ? $data['reset_id'] : '';

			if ( ! empty( $reset_id ) && ! ( is_string( $reset_id ) && preg_match( '/^wptw_reset_[a-f0-9]{26}$/', $reset_id ) ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'Invalid reset_id format.', 'tailwatch' ),
				);
			}

			if ( empty( $reset_id ) ) {
				Log::error(
					'Reset ID is required for cron restart',
					array(
						'feature'  => 'settings',
						'action'   => 'settings_all_reset_cron_retry_failed',
						'title'  => 'Settings Reset All Failed',
						'detail'   => 'Reset ID is required for cron restart',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'No reset_id provided.', 'tailwatch' ),
				);
			}

			$batch_state = get_transient( $reset_id );
			if ( ! $batch_state || ! is_array( $batch_state['option_keys'] ) ) {
				Log::error(
					'Reset session expired or invalid for ID: ' . $reset_id,
					array(
						'feature'  => 'settings',
						'action'   => 'settings_all_reset_cron_retry_failed',
						'title'  => 'Settings Reset All Failed',
						'detail'   => 'Reset session expired or invalid for ID: ' . $reset_id,
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Reset session expired or invalid.', 'tailwatch' ),
				);
			}

			$cron_running = $batch_state['cron_running'] ?? false;

			if ( $cron_running === false ) {
				if ( ! wp_next_scheduled( 'wptw_reset_all_settings_batch_cron', array( $reset_id ) ) ) {
					$cron_scheduled = wp_schedule_single_event( time() + 5, 'wptw_reset_all_settings_batch_cron', array( $reset_id ) );
					if ( $cron_scheduled ) {
						Log::info(
							'Cron job restarted successfully for reset ID: ' . $reset_id,
							array(
								'feature' => 'settings',
								'action'  => 'settings_all_reset_cron_if_failed',
								'title'  => 'Settings Reset All Failed',
								'origin'  => 'system',
							)
						);
						return array(
							'code'    => 200,
							'message' => __( 'Again attempt to run the reset cron.', 'tailwatch' ),
						);
					} else {
						Log::error(
							'Failed to schedule cron job restart for reset ID: ' . $reset_id,
							array(
								'feature'  => 'settings',
								'action'   => 'settings_all_reset_cron_retry_failed',
								'title'  => 'Settings Reset All Failed',
								'detail'   => 'Failed to schedule cron job restart for reset ID: ' . $reset_id,
								'origin'   => 'system',
								'severity' => 'high',
							)
						);
						return array(
							'code'    => 500,
							'message' => __( 'Error: Cron job could not be scheduled. Please try again.', 'tailwatch' ),
						);
					}
				} else {
					Log::info(
						'Cron job already scheduled for reset ID: ' . $reset_id,
						array(
							'feature' => 'settings',
							'action'  => 'settings_all_reset_cron_if_failed',
							'title'  => 'Settings Reset All Failed',
							'origin'  => 'system',
						)
					);
					return array(
						'code'    => 200,
						'message' => __( 'Cron job is already scheduled.', 'tailwatch' ),
					);
				}
			} else {
				Log::info(
					'Cron job already running for reset ID: ' . $reset_id,
					array(
						'feature' => 'settings',
						'action'  => 'settings_all_reset_cron_if_failed',
						'title'  => 'Settings Reset All Failed',
						'origin'  => 'system',
					)
				);
				return array(
					'code'    => 200,
					'message' => __( 'Cron job is already running.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception occurred during cron restart: ' . $e->getMessage(),
				array(
					'feature'  => 'settings',
					'action'   => 'settings_all_reset_cron_retry_failed',
					'title'  => 'Settings Reset All Failed',
					'detail'   => 'Exception occurred during cron restart: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An error occurred while restarting the cron job.', 'tailwatch' ),
			);
		}
	}
}
