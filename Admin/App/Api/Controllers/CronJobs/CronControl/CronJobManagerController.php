<?php
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Cron-hash signatures mirror WP core's own md5(timestamp . hook . serialize(args)) convention; every serialize() in this file is for that keying purpose.

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl;

defined( 'ABSPATH' ) || exit;


use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Logging\Log;

class CronJobManagerController {

	private $protected_events = array(
		'delete_expired_transients',
		'recovery_mode_clean_expired_keys',
		'wp_https_detection',
		'wp_privacy_delete_old_export_files',
		'wp_scheduled_auto_draft_delete',
		'wp_scheduled_delete',
		'wp_site_health_scheduled_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_update_user_counts',
		'wp_version_check',
	);

	/**
	 * Per-request memoization of the plugin-system hook list.
	 *
	 * @var array<string>|null
	 */
	private static $plugin_system_hooks_cache = null;

	/**
	 * Canonical list of cron hooks owned by this plugin (and by Pro / other
	 * extensions via the `tailwatch_register_cron_hooks` filter). Sourced from
	 * CronJobManager::get_all_cron_hooks() so the list stays in sync
	 * automatically when a new feature ships a new cron — no manual list to
	 * maintain in this controller.
	 *
	 * Cached per-request because the bulk-action handler can iterate this
	 * for 50+ hashes per call.
	 *
	 * @return array<string>
	 */
	private function get_plugin_system_hooks() {
		if ( null === self::$plugin_system_hooks_cache ) {
			self::$plugin_system_hooks_cache = class_exists( CronJobManager::class )
				? CronJobManager::get_all_cron_hooks()
				: array();
		}
		return self::$plugin_system_hooks_cache;
	}

	/**
	 * Is the given hook owned by this plugin's cron registry?
	 *
	 * @param string $hook
	 * @return bool
	 */
	private function is_plugin_system_hook( $hook ) {
		return in_array( $hook, $this->get_plugin_system_hooks(), true );
	}

	private function compute_signature( $hook, $schedule, $args ) {
		return md5( $hook . '|' . $schedule . '|' . serialize( $args ) );
	}

	private function get_created_registry() {
		$created = get_option( 'tailwatch_created_cron_jobs', array() );
		if ( ! is_array( $created ) ) {
			$created = array();
		}
		return $created;
	}

	private function is_plugin_created( $signature ) {
		$created = $this->get_created_registry();
		return isset( $created[ $signature ] );
	}

	private function mark_plugin_created( $signature ) {
		$created = $this->get_created_registry();
		if ( isset( $created[ $signature ] ) ) {
			return;
		}
		$created[ $signature ] = 1;
		if ( false === get_option( 'tailwatch_created_cron_jobs', false ) ) {
			add_option( 'tailwatch_created_cron_jobs', $created, '', false );
		} else {
			update_option( 'tailwatch_created_cron_jobs', $created );
		}
	}

	private function unmark_plugin_created( $signature ) {
		$created = $this->get_created_registry();
		if ( ! isset( $created[ $signature ] ) ) {
			return;
		}
		unset( $created[ $signature ] );
		update_option( 'tailwatch_created_cron_jobs', $created );
	}

	private function get_event_by_hash( $hash ) {
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			$crons = array();
		}
		$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $key => $event ) {
					$event_hash = md5( $timestamp . $hook . serialize( $event['args'] ) );
					if ( $event_hash === $hash ) {
						return array(
							'timestamp' => $timestamp,
							'hook'      => $hook,
							'key'       => $key,
							'args'      => $event['args'],
							'schedule'  => ! empty( $event['schedule'] ) ? $event['schedule'] : '__single_run',
							'interval'  => isset( $event['interval'] ) ? $event['interval'] : 0,
							'is_paused' => false,
						);
					}
				}
			}
		}

		if ( isset( $paused_jobs[ $hash ] ) ) {
			return array(
				'timestamp' => $paused_jobs[ $hash ]['timestamp'],
				'hook'      => $paused_jobs[ $hash ]['hook'],
				'key'       => $paused_jobs[ $hash ]['key'],
				'args'      => $paused_jobs[ $hash ]['args'],
				'schedule'  => $paused_jobs[ $hash ]['schedule'],
				'interval'  => $paused_jobs[ $hash ]['interval'],
				'is_paused' => true,
			);
		}

		return false;
	}

	private function validate_args( $args_raw ) {
		$args = json_decode( wp_unslash( $args_raw ), true );
		if ( ! is_array( $args ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid arguments. Must be a JSON-encoded array.', 'tailwatch' ),
				'code'    => 400,
				'data'    => array(),
			);
		}

		foreach ( $args as &$arg ) {
			if ( is_string( $arg ) ) {
				$arg = sanitize_text_field( html_entity_decode( $arg, ENT_QUOTES, 'UTF-8' ) );
			} elseif ( ! is_scalar( $arg ) && ! is_null( $arg ) ) {
				return array(
					'success' => false,
					'message' => __( 'Arguments must be scalar values or null.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}
		}

		return array(
			'success' => true,
			'data'    => $args,
		);
	}

	private function check_duplicate_event( $hook, $schedule, $args, $exclude_hash = '' ) {
		$crons = _get_cron_array();
		if ( empty( $crons ) ) {
			$crons = array();
		}
		$args_serialized = serialize( $args );

		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $event_hook => $events ) {
				if ( $event_hook !== $hook ) {
					continue;
				}
				foreach ( $events as $key => $event ) {
					$event_schedule = ! empty( $event['schedule'] ) ? $event['schedule'] : '__single_run';
					if ( $event_schedule === $schedule && serialize( $event['args'] ) === $args_serialized ) {
						$hash = md5( $timestamp . $hook . $args_serialized );
						if ( $hash !== $exclude_hash ) {
							return array(
								'success' => false,
								'message' => __( 'An identical cron job already exists.', 'tailwatch' ),
								'code'    => 400,
								'data'    => array(),
							);
						}
					}
				}
			}
		}
		return array( 'success' => true );
	}

	public function tailwatch_run_cron_job( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'   => 'cron_jobs',
						'action'    => 'cron_job_run_failed',
						'detail'    => 'Cron Job feature is not enabled',
						'origin'    => 'system',
						'severity'  => 'medium',
						'meta_data' => array(
							'feature' => 'Cron Jobs',
							'event'   => 'Failed',
							'reason' => 'Feature is disabled',
						),
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data ) || null === $data ) {
				Log::error(
					'Invalid JSON data.',
					array(
						'feature'   => 'cron_jobs',
						'action'    => 'cron_job_run_failed',
						'detail'    => 'Invalid JSON data.',
						'origin'    => 'system',
						'severity'  => 'high',
						'meta_data' => array(
							'feature' => 'Cron Jobs',
							'event'   => 'Failed',
							'reason' => 'Invalid request data',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$hash = isset( $data['hash'] ) ? sanitize_text_field( $data['hash'] ) : '';
			if ( empty( $hash ) ) {
				Log::error(
					'Hash cannot be empty.',
					array(
						'feature'   => 'cron_jobs',
						'action'    => 'cron_job_run_failed',
						'detail'    => 'Hash cannot be empty.',
						'origin'    => 'system',
						'severity'  => 'high',
						'meta_data' => array(
							'feature' => 'Cron Jobs',
							'event'   => 'Failed',
							'reason' => 'Missing required information',
						),
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Hash cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$event = $this->get_event_by_hash( $hash );
			if ( ! $event ) {
				Log::error(
					'Cron job not found.',
					array(
						'feature'   => 'cron_jobs',
						'action'    => 'cron_job_run_failed',
						'detail'    => 'Cron job not found.',
						'origin'    => 'system',
						'severity'  => 'high',
						'meta_data' => array(
							'feature' => 'Cron Jobs',
							'event'   => 'Failed',
							'reason' => 'Cron event not registered',
						),
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cron job not found.', 'tailwatch' ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			// Plugin-system cron jobs are owned by Tailwatch — control them
			// through the feature settings (interval / enable toggle), not the
			// cron list. Running them on-demand here would bypass the
			// feature's own start/pause/cancel state machine.
			if ( $this->is_plugin_system_hook( $event['hook'] ) ) {
				Log::error(
					'This is a Tailwatch system cron job.',
					array(
						'feature'   => 'cron_jobs',
						'action'    => 'cron_job_run_failed',
						'detail'    => 'Plugin-system cron job cannot be run from the cron list.',
						'hook'      => $event['hook'],
						'origin'    => 'system',
						'severity'  => 'medium',
						'meta_data' => array(
							'feature' => 'Cron Jobs',
							'event'   => 'Failed',
							'cron_event' => isset( $event['hook'] ) ? sanitize_key( (string) $event['hook'] ) : '',
							'reason'     => 'System cron is blocked',
						),
					)
				);
				return array(
					'success' => false,
					'message' => __( 'This is a Tailwatch system cron job. Control it through its feature settings instead of the cron list.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			if ( $event['is_paused'] ) {
				Log::error(
					'Cannot run a paused cron job.',
					array(
						'feature'   => 'cron_jobs',
						'action'    => 'cron_job_run_failed',
						'detail'    => 'Cannot run a paused cron job.',
						'origin'    => 'system',
						'severity'  => 'medium',
						'meta_data' => array(
							'feature' => 'Cron Jobs',
							'event'   => 'Failed',
							'cron_event' => isset( $event['hook'] ) ? sanitize_key( (string) $event['hook'] ) : '',
							'reason'     => 'Cron is paused',
						),
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cannot run a paused cron job.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}


			delete_transient( 'doing_cron' );
			if ( ! defined( 'DOING_CRON' ) ) {
				define( 'DOING_CRON', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant.
			}

			do_action_ref_array( $event['hook'], $event['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Executing registered cron event hook.
			Log::info(
				"Cron job {$event['hook']} executed successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_run_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the cron hook name.
				'message' => sprintf( __( 'Cron job %s executed successfully.', 'tailwatch' ), $event['hook'] ),
				'code'    => 200,
				'data'    => array( 'hash' => $hash ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception in tailwatch_run_cron_job: ' . $e->getMessage(),
				array(
					'feature'   => 'cron_jobs',
					'action'    => 'cron_job_run_failed',
					'detail'    => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'    => 'system',
					'severity'  => 'high',
					'meta_data' => array(
						'feature' => 'Cron Jobs',
						'event'   => 'Failed',
						'reason' => 'Unexpected error',
					),
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while running cron job.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_pause_cron_job( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data ) || null === $data ) {
				Log::error(
					'Invalid JSON data.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Invalid JSON data.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$hash = isset( $data['hash'] ) ? sanitize_text_field( $data['hash'] ) : '';
			if ( empty( $hash ) ) {
				Log::error(
					'Hash cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Hash cannot be empty.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Hash cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$event = $this->get_event_by_hash( $hash );
			if ( ! $event ) {
				Log::error(
					'Cron job not found.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Cron job not found.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cron job not found.', 'tailwatch' ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			// Plugin-system cron jobs are owned by Tailwatch — pausing one
			// from the cron list would leave its feature thinking it's still
			// scheduled. Users disable the parent feature instead.
			if ( $this->is_plugin_system_hook( $event['hook'] ) ) {
				Log::error(
					'This is a Tailwatch system cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Plugin-system cron job cannot be paused from the cron list.',
						'hook'     => $event['hook'],
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'This is a Tailwatch system cron job. Disable the feature in its settings instead of pausing the cron.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			if ( $event['is_paused'] ) {
				Log::error(
					'Cron job is already paused.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Cron job is already paused.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cron job is already paused.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}


			wp_unschedule_event( $event['timestamp'], $event['hook'], $event['args'], true );
			$crons = _get_cron_array();
			if ( empty( $crons ) ) {
				$crons = array();
			}
			$event_still_exists = false;
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					if ( $hook === $event['hook'] && isset( $events[ $event['key'] ] ) && serialize( $events[ $event['key'] ]['args'] ) === serialize( $event['args'] ) ) {
						$event_still_exists = true;
						break 2;
					}
				}
			}
			if ( $event_still_exists ) {
				Log::error(
					'Failed to unschedule cron job',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_pause_failed',
						'detail'   => 'Failed to unschedule cron job',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Failed to pause cron job.', 'tailwatch' ),
					'code'    => 500,
					'data'    => array( 'hash' => $hash ),
				);
			}

			$paused_jobs          = get_option( 'tailwatch_paused_cron_jobs', array() );
			$paused_jobs[ $hash ] = array(
				'hook'      => $event['hook'],
				'timestamp' => $event['timestamp'],
				'schedule'  => $event['schedule'],
				'args'      => $event['args'],
				'interval'  => $event['interval'],
				'key'       => $event['key'],
			);
			update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );

			Log::info(
				"Cron job {$event['hook']} paused successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_pause_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the cron hook name.
				'message' => sprintf( __( 'Cron job %s paused successfully.', 'tailwatch' ), $event['hook'] ),
				'code'    => 200,
				'data'    => array( 'hash' => $hash ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_pause_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while pausing cron job.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_resume_cron_job( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data ) || null === $data ) {
				Log::error(
					'Invalid JSON data.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => 'Invalid JSON data.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$hash = isset( $data['hash'] ) ? sanitize_text_field( $data['hash'] ) : '';
			if ( empty( $hash ) ) {
				Log::error(
					'Hash cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => 'Hash cannot be empty.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Hash cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );
			if ( ! isset( $paused_jobs[ $hash ] ) ) {
				Log::error(
					'Paused cron job not found.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => 'Paused cron job not found.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Paused cron job not found.', 'tailwatch' ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			$job = $paused_jobs[ $hash ];
			if ( empty( $job['hook'] ) || empty( $job['schedule'] ) ) {
				unset( $paused_jobs[ $hash ] );
				update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );
				Log::error(
					'Invalid paused job data.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => 'Invalid paused job data.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid paused job data.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			// Plugin-system cron jobs are owned by Tailwatch — resuming a
			// paused plugin-system cron from the UI would let users re-enable
			// it independently of the feature's own enable toggle. Force them
			// to use the feature settings instead.
			if ( $this->is_plugin_system_hook( $job['hook'] ) ) {
				Log::error(
					'This is a Tailwatch system cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => 'Plugin-system cron job cannot be resumed from the cron list.',
						'hook'     => $job['hook'],
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'This is a Tailwatch system cron job. Re-enable it through its feature settings instead of the cron list.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			$new_time = time();
			if ( $job['schedule'] !== '__single_run' && $job['interval'] > 0 ) {
				$time_since_last  = time() - $job['timestamp'];
				$intervals_passed = floor( $time_since_last / $job['interval'] );
				$new_time         = $job['timestamp'] + ( ( $intervals_passed + 1 ) * $job['interval'] );
			}

			// Remove from paused BEFORE scheduling so the pre_schedule_event
			// filter does not block our own resume attempt.
			unset( $paused_jobs[ $hash ] );
			update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );

			if ( $job['schedule'] === '__single_run' ) {
				$result = wp_schedule_single_event( $new_time, $job['hook'], $job['args'], true );
			} else {
				$result = wp_schedule_event( $new_time, $job['schedule'], $job['hook'], $job['args'], true );
			}

			if ( is_wp_error( $result ) ) {
				// Restore the paused job — scheduling failed.
				$paused_jobs[ $hash ] = $job;
				update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );

				$error_msg = $result->get_error_message();
				Log::error(
					"Failed to reschedule cron job {$job['hook']} during resume.",
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_resume_failed',
						'detail'   => $error_msg,
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Failed to reschedule cron job during resume.', 'tailwatch' ),
					'code'    => 500,
					'data'    => array( 'hash' => $hash ),
				);
			}

			Log::info(
				"Cron job {$job['hook']} resumed successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_resume_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the cron hook name.
				'message' => sprintf( __( 'Cron job %s resumed successfully.', 'tailwatch' ), $job['hook'] ),
				'code'    => 200,
				'data'    => array( 'hash' => $hash ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_resume_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while resuming cron job.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_delete_cron_job( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! isset( $data ) || null === $data ) {
				Log::error(
					'Invalid JSON data.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_failed',
						'detail'   => 'Invalid JSON data.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid JSON data.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$hash = isset( $data['hash'] ) ? sanitize_text_field( $data['hash'] ) : '';
			if ( empty( $hash ) ) {
				Log::error(
					'Hash cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_failed',
						'detail'   => 'Hash cannot be empty.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Hash cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$event = $this->get_event_by_hash( $hash );
			if ( ! $event ) {
				Log::error(
					'Cron job not found.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_failed',
						'detail'   => 'Cron job not found.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cron job not found.', 'tailwatch' ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			// Plugin-system cron jobs are owned by Tailwatch — deleting one
			// would break the feature that depends on it. The feature's
			// enable toggle is the right way to stop it; users are blocked
			// from removing it through the cron list.
			if ( $this->is_plugin_system_hook( $event['hook'] ) ) {
				Log::error(
					'This is a Tailwatch system cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_failed',
						'detail'   => 'Plugin-system cron job cannot be deleted from the cron list.',
						'hook'     => $event['hook'],
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'This is a Tailwatch system cron job. Disable its feature in settings instead of deleting the cron.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			if ( in_array( $event['hook'], $this->protected_events, true ) ) {
				Log::error(
					'Protected cron job cannot be deleted.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_failed',
						'detail'   => 'Protected cron job cannot be deleted.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Protected cron job cannot be deleted.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			if ( $event['is_paused'] ) {
				$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );
				unset( $paused_jobs[ $hash ] );
				update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );
			} else {
				$unscheduled = wp_unschedule_event( $event['timestamp'], $event['hook'], $event['args'], true );
				if ( is_wp_error( $unscheduled ) ) {
					Log::error(
						"Failed to delete cron job {$event['hook']}.",
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_delete_failed',
							'detail'   => $unscheduled->get_error_message(),
							'origin'   => 'system',
							'severity' => 'high',
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Failed to delete cron job.', 'tailwatch' ),
						'code'    => 500,
						'data'    => array( 'hash' => $hash ),
					);
				}
			}

			$this->unmark_plugin_created( $this->compute_signature( $event['hook'], $event['schedule'], $event['args'] ) );

			Log::info(
				"Cron job {$event['hook']} deleted successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_delete_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the cron hook name.
				'message' => sprintf( __( 'Cron job %s deleted successfully.', 'tailwatch' ), $event['hook'] ),
				'code'    => 200,
				'data'    => array( 'hash' => $hash ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_delete_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while deleting cron job.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_add_cron_job( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );
			$hook      = isset( $data['hook'] ) ? sanitize_text_field( $data['hook'] ) : '';
			$schedule  = isset( $data['schedule'] ) ? sanitize_text_field( $data['schedule'] ) : '';
			$time      = isset( $data['execution_time'] ) ? sanitize_text_field( $data['execution_time'] ) : '';
			$args_raw  = isset( $data['args'] ) ? $data['args'] : '';

			if ( empty( $hook ) || ! is_string( $hook ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $hook ) ) {
				Log::error(
					'Invalid hook name. Must contain only letters, numbers, and underscores.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Invalid hook name. Must contain only letters, numbers, and underscores.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid hook name. Must contain only letters, numbers, and underscores.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			if ( empty( $time ) ) {
				Log::error(
					'Execution time is required.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Execution time is required.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Execution time is required.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$args_result = $this->validate_args( $args_raw );
			if ( ! $args_result['success'] ) {
				Log::error(
					'Invalid arguments. Must be a JSON-encoded array.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Invalid arguments. Must be a JSON-encoded array.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return $args_result;
			}
			$args                      = $args_result['data'];
			$schedules                 = wp_get_schedules();
			$schedules['__single_run'] = array( 'display' => 'Non-repeating' );
			$custom_schedules          = get_option( 'tailwatch_custom_schedules', array() );
			foreach ( $custom_schedules as $slug => $custom ) {
				$schedules[ $slug ] = $custom;
			}

			if ( ! isset( $schedules[ $schedule ] ) ) {
				Log::error(
					'Invalid schedule.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Invalid schedule.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid schedule.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$timezone_string = get_option( 'timezone_string', 'UTC' );
			if ( empty( $timezone_string ) || ! in_array( $timezone_string, timezone_identifiers_list(), true ) ) {
				$timezone_string = 'UTC';
			}
			try {
				$timezone = new \DateTimeZone( $timezone_string );
				$date     = \DateTime::createFromFormat( 'Y-m-d H:i:s', $time, $timezone );
				if ( ! $date ) {
					Log::error(
						'Invalid execution time. Use YYYY-MM-DD HH:MM:SS format.',
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_add_failed',
							'detail'   => 'Invalid execution time. Use YYYY-MM-DD HH:MM:SS format.',
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Invalid execution time. Use YYYY-MM-DD HH:MM:SS format.', 'tailwatch' ),
						'code'    => 400,
						'data'    => array(),
					);
				}
				$timestamp = $date->getTimestamp();
				if ( $timestamp <= time() ) {
					Log::error(
						'Execution time must be in the future.',
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_add_failed',
							'detail'   => 'Execution time must be in the future.',
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Execution time must be in the future.', 'tailwatch' ),
						'code'    => 400,
						'data'    => array(),
					);
				}
			} catch ( \Exception $e ) {
				Log::error(
					'Invalid execution time format or timezone.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Error parsing date: ' . $e->getMessage(),
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid execution time format or timezone.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			// Prevent users from scheduling duplicates of plugin-system hooks
			// via the cron list — those hooks are managed by Tailwatch's
			// feature controllers, and a hand-added duplicate would race
			// with the feature's own scheduled event.
			if ( $this->is_plugin_system_hook( $hook ) ) {
				Log::error(
					'Cannot add a Tailwatch system cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Plugin-system hook reserved by Tailwatch.',
						'hook'     => $hook,
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the cron hook name.
					'message' => sprintf( __( 'The hook \'%s\' is reserved by Tailwatch. Manage it through its feature settings instead.', 'tailwatch' ), $hook ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			if ( in_array( $hook, $this->protected_events, true ) ) {
				Log::error(
					'Cannot add a protected cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Cannot add a protected cron job.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cannot add a protected cron job.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			$duplicate_check = $this->check_duplicate_event( $hook, $schedule, $args );
			if ( ! $duplicate_check['success'] ) {
				Log::error(
					'An identical cron job already exists.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'An identical cron job already exists.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return $duplicate_check;
			}

			if ( $schedule === '__single_run' ) {
				$result = wp_schedule_single_event( $timestamp, $hook, $args, true );
			} else {
				$result = wp_schedule_event( $timestamp, $schedule, $hook, $args, true );
			}

			if ( is_wp_error( $result ) ) {
				Log::error(
					'Failed to schedule new cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_failed',
						'detail'   => 'Failed to schedule new cron job.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Failed to schedule new cron job.', 'tailwatch' ),
					'code'    => 500,
					'data'    => array(),
				);
			}

			$hash = md5( $timestamp . $hook . serialize( $args ) );
			$this->mark_plugin_created( $this->compute_signature( $hook, $schedule, $args ) );
			Log::info(
				"Cron job $hook scheduled successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_add_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the cron hook name.
				'message' => sprintf( __( 'Cron job %s scheduled successfully.', 'tailwatch' ), $hook ),
				'code'    => 200,
				'data'    => array(
					'hash'      => $hash,
					'timestamp' => $timestamp,
					'hook'      => $hook,
					'schedule'  => $schedule,
					'args'      => wp_json_encode( $args ),
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_add_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while adding cron job.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_edit_cron_job( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );
			$hash      = isset( $data['hash'] ) ? sanitize_text_field( $data['hash'] ) : '';
			$schedule  = isset( $data['schedule'] ) ? sanitize_text_field( $data['schedule'] ) : '';
			$time      = isset( $data['execution_time'] ) ? sanitize_text_field( $data['execution_time'] ) : '';
			$args_raw  = isset( $data['args'] ) ? $data['args'] : '';
			$event     = $this->get_event_by_hash( $hash );
			if ( ! $event ) {
				Log::error(
					'Cron job not found.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'Cron job not found.',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Cron job not found.', 'tailwatch' ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			// Plugin-system cron jobs are owned by Tailwatch — editing the
			// schedule/args from the cron list would race with the feature's
			// own scheduler. Users adjust the feature's settings (interval,
			// enable toggle) instead.
			if ( $this->is_plugin_system_hook( $event['hook'] ) ) {
				Log::error(
					'This is a Tailwatch system cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'Plugin-system cron job cannot be edited from the cron list.',
						'hook'     => $event['hook'],
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'This is a Tailwatch system cron job. Edit its schedule through its feature settings instead of the cron list.', 'tailwatch' ),
					'code'    => 403,
					'data'    => array(),
				);
			}


			$args_result = $this->validate_args( $args_raw );
			if ( ! $args_result['success'] ) {
				Log::error(
					'Invalid arguments. Must be a JSON-encoded array.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'Invalid arguments. Must be a JSON-encoded array.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return $args_result;
			}
			$new_args = $args_result['data'];

			$schedules                 = wp_get_schedules();
			$schedules['__single_run'] = array( 'display' => 'Non-repeating' );
			$custom_schedules          = get_option( 'tailwatch_custom_schedules', array() );
			foreach ( $custom_schedules as $slug => $custom ) {
				$schedules[ $slug ] = $custom;
			}

			if ( ! isset( $schedules[ $schedule ] ) ) {
				Log::error(
					'Invalid schedule.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'Invalid schedule.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid schedule.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}
			$timezone_string = get_option( 'timezone_string', 'UTC' );
			if ( empty( $timezone_string ) || ! in_array( $timezone_string, timezone_identifiers_list(), true ) ) {
				$timezone_string = 'UTC';
			}
			try {
				$timezone = new \DateTimeZone( $timezone_string );
				$date     = \DateTime::createFromFormat( 'Y-m-d H:i:s', $time, $timezone );
				if ( ! $date ) {
					Log::error(
						'Invalid execution time. Use YYYY-MM-DD HH:MM:SS format.',
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_edit_failed',
							'detail'   => 'Invalid execution time. Use YYYY-MM-DD HH:MM:SS format.',
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Invalid execution time. Use YYYY-MM-DD HH:MM:SS format.', 'tailwatch' ),
						'code'    => 400,
						'data'    => array(),
					);
				}
				$new_timestamp = $date->getTimestamp();
				if ( $new_timestamp <= time() ) {
					Log::error(
						'Execution time must be in the future.',
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_edit_failed',
							'detail'   => 'Execution time must be in the future.',
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Execution time must be in the future.', 'tailwatch' ),
						'code'    => 400,
						'data'    => array(),
					);
				}
			} catch ( \Exception $e ) {
				Log::error(
					'Invalid execution time format or timezone.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'Error parsing date: ' . $e->getMessage(),
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid execution time format or timezone.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}
			// Check for duplicates BEFORE removing the old event to prevent data loss.
			$duplicate_check = $this->check_duplicate_event( $event['hook'], $schedule, $new_args, $hash );
			if ( ! $duplicate_check['success'] ) {
				Log::error(
					'An identical cron job already exists.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => 'An identical cron job already exists.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return $duplicate_check;
			}

			// Remove old event after validation passes.
			if ( $event['is_paused'] ) {
				$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );
				unset( $paused_jobs[ $hash ] );
				update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );
			} else {
				$unscheduled = wp_unschedule_event( $event['timestamp'], $event['hook'], $event['args'], true );
				if ( is_wp_error( $unscheduled ) ) {
					Log::error(
						"Failed to unschedule old cron job {$event['hook']} during edit.",
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_edit_failed',
							'detail'   => $unscheduled->get_error_message(),
							'origin'   => 'system',
							'severity' => 'high',
						)
					);
					return array(
						'success' => false,
						'message' => __( 'Failed to remove old cron job during edit.', 'tailwatch' ),
						'code'    => 500,
						'data'    => array(),
					);
				}
			}

			if ( $schedule === '__single_run' ) {
				$result = wp_schedule_single_event( $new_timestamp, $event['hook'], $new_args, true );
			} else {
				$result = wp_schedule_event( $new_timestamp, $schedule, $event['hook'], $new_args, true );
			}

			if ( is_wp_error( $result ) ) {
				// Restore the old event to prevent data loss.
				if ( $event['is_paused'] ) {
					$paused_jobs          = get_option( 'tailwatch_paused_cron_jobs', array() );
					$paused_jobs[ $hash ] = array(
						'hook'      => $event['hook'],
						'timestamp' => $event['timestamp'],
						'schedule'  => $event['schedule'],
						'args'      => $event['args'],
						'interval'  => $event['interval'],
						'key'       => $event['key'],
					);
					update_option( 'tailwatch_paused_cron_jobs', $paused_jobs );
				} else {
					if ( $event['schedule'] === '__single_run' ) {
						wp_schedule_single_event( $event['timestamp'], $event['hook'], $event['args'], true );
					} else {
						wp_schedule_event( $event['timestamp'], $event['schedule'], $event['hook'], $event['args'], true );
					}
				}

				$error_msg = $result->get_error_message();
				Log::error(
					'Failed to schedule updated cron job.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_failed',
						'detail'   => $error_msg,
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Failed to schedule updated cron job.', 'tailwatch' ),
					'code'    => 500,
					'data'    => array(),
				);
			}

			$new_hash = md5( $new_timestamp . $event['hook'] . serialize( $new_args ) );

			$old_sig = $this->compute_signature( $event['hook'], $event['schedule'], $event['args'] );
			if ( $this->is_plugin_created( $old_sig ) ) {
				$new_sig = $this->compute_signature( $event['hook'], $schedule, $new_args );
				if ( $old_sig !== $new_sig ) {
					$this->unmark_plugin_created( $old_sig );
					$this->mark_plugin_created( $new_sig );
				}
			}

			Log::info(
				"Cron job {$event['hook']} updated successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_edit_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the cron hook name.
				'message' => sprintf( __( 'Cron job %s updated successfully.', 'tailwatch' ), $event['hook'] ),
				'code'    => 200,
				'data'    => array(
					'hash'      => $new_hash,
					'timestamp' => $new_timestamp,
					'hook'      => $event['hook'],
					'schedule'  => $schedule,
					'args'      => wp_json_encode( $new_args ),
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_edit_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while editing cron job.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_bulk_cron_action( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_bulk_action_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$hashes = isset( $data['hashes'] ) && is_array( $data['hashes'] ) ? array_map( 'sanitize_text_field', $data['hashes'] ) : array();
			$action = isset( $data['bulk_action'] ) ? sanitize_text_field( $data['bulk_action'] ) : '';

			if ( ! is_array( $hashes ) || empty( $hashes ) ) {
				Log::error(
					'No cron jobs selected.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_bulk_action_failed',
						'detail'   => 'No cron jobs selected.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'No cron jobs selected.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$valid_actions = array( 'run', 'pause', 'resume', 'delete' );
			if ( ! in_array( $action, $valid_actions, true ) ) {
				Log::error(
					'Invalid bulk action.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_bulk_action_failed',
						'detail'   => 'Invalid bulk action.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Invalid bulk action.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$results = array();
			foreach ( $hashes as $hash ) {
				$hash = sanitize_text_field( $hash );

				$post_data = array(
					'hash' => $hash,
				);

				$encode_data = wp_json_encode( $post_data );
				switch ( $action ) {
					case 'run':
						$result = $this->tailwatch_run_cron_job( $encode_data );
						break;
					case 'pause':
						$result = $this->tailwatch_pause_cron_job( $encode_data );
						break;
					case 'resume':
						$result = $this->tailwatch_resume_cron_job( $encode_data );
						break;
					case 'delete':
						$result = $this->tailwatch_delete_cron_job( $encode_data );
						break;
				}
				$results[ $hash ] = $result;
			}

			$success_count = count(
				array_filter(
					$results,
					function ( $result ) {
						return $result['success'];
					}
				)
			);
			$total         = count( $hashes );

			Log::info(
				"$success_count of $total cron jobs processed successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_bulk_action_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => $success_count === $total,
				// translators: 1: number of cron jobs processed successfully, 2: total number of cron jobs.
				'message' => sprintf( __( '%1$s of %2$s cron jobs processed successfully.', 'tailwatch' ), $success_count, $total ),
				'code'    => $success_count === $total ? 200 : 207,
				'data'    => $results,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_bulk_action_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while performing bulk cron action.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_add_schedule( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_schedule_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );
			$slug      = isset( $data['slug'] ) ? sanitize_text_field( $data['slug'] ) : '';
			$name      = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
			$interval  = isset( $data['interval'] ) ? absint( $data['interval'] ) : 0;
			$slug      = sanitize_title_with_dashes( $slug );
			$slug      = str_replace( '-', '_', $slug );
			$name      = sanitize_text_field( $name );
			$interval  = absint( $interval );
			if ( empty( $slug ) ) {
				Log::error(
					'Schedule slug cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_schedule_failed',
						'detail'   => 'Schedule slug cannot be empty.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Schedule slug cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			if ( empty( $name ) ) {
				Log::error(
					'Schedule name cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_schedule_failed',
						'detail'   => 'Schedule name cannot be empty.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Schedule name cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			if ( $interval < 1 || $interval > 31536000 ) {
				Log::error(
					'Interval must be between 1 second and 1 year.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_schedule_failed',
						'detail'   => 'Interval must be between 1 second and 1 year.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Interval must be between 1 second and 1 year.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$schedules        = wp_get_schedules();
			$custom_schedules = get_option( 'tailwatch_custom_schedules', array() );

			if ( isset( $schedules[ $slug ] ) || isset( $custom_schedules[ $slug ] ) ) {
				Log::error(
					"Schedule with slug '$slug' already exists.",
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_add_schedule_failed',
						'detail'   => "Schedule with slug '$slug' already exists.",
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the schedule slug.
					'message' => sprintf( __( 'Schedule with slug \'%s\' already exists.', 'tailwatch' ), $slug ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$custom_schedules[ $slug ] = array(
				'display'    => $name,
				'interval'   => $interval,
				'created_at' => time(),
			);
			update_option( 'tailwatch_custom_schedules', $custom_schedules );
			Log::info(
				"Schedule '$name' added successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_add_schedule_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the schedule name.
				'message' => sprintf( __( 'Schedule \'%s\' added successfully.', 'tailwatch' ), $name ),
				'code'    => 200,
				'data'    => array(
					'slug'     => $slug,
					'display'  => $name,
					'interval' => $interval,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_add_schedule_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while adding custom schedule.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_edit_schedule( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_schedule_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );
			$slug      = isset( $data['slug'] ) ? sanitize_text_field( $data['slug'] ) : '';
			$name      = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
			$interval  = isset( $data['interval'] ) ? absint( $data['interval'] ) : 0;
			$slug      = sanitize_title_with_dashes( $slug );
			$slug      = str_replace( '-', '_', $slug );
			$name      = sanitize_text_field( $name );
			$interval  = absint( $interval );
			if ( empty( $slug ) ) {
				Log::error(
					'Schedule slug cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_schedule_failed',
						'detail'   => 'Schedule slug cannot be empty.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Schedule slug cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			if ( empty( $name ) ) {
				Log::error(
					'Schedule name cannot be empty.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_schedule_failed',
						'detail'   => 'Schedule name cannot be empty.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Schedule name cannot be empty.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}
			if ( $interval < 1 || $interval > 31536000 ) {
				Log::error(
					'Interval must be between 1 second and 1 year.',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_schedule_failed',
						'detail'   => 'Interval must be between 1 second and 1 year.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					'message' => __( 'Interval must be between 1 second and 1 year.', 'tailwatch' ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$custom_schedules = get_option( 'tailwatch_custom_schedules', array() );
			if ( ! isset( $custom_schedules[ $slug ] ) ) {
				Log::error(
					"Schedule with slug '$slug' does not exist.",
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_schedule_failed',
						'detail'   => "Schedule with slug '$slug' does not exist.",
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the schedule slug.
					'message' => sprintf( __( 'Schedule with slug \'%s\' does not exist.', 'tailwatch' ), $slug ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			$schedules = wp_get_schedules();
			if ( isset( $schedules[ $slug ] ) && ! isset( $custom_schedules[ $slug ] ) ) {
				Log::error(
					"Schedule '$slug' is protected and cannot be edited.",
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_edit_schedule_failed',
						'detail'   => "Schedule '$slug' is protected and cannot be edited.",
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the schedule slug.
					'message' => sprintf( __( 'Schedule \'%s\' is protected and cannot be edited.', 'tailwatch' ), $slug ),
					'code'    => 403,
					'data'    => array(),
				);
			}

			$custom_schedules[ $slug ] = array(
				'display'    => $name,
				'interval'   => $interval,
				'created_at' => $custom_schedules[ $slug ]['created_at'] ?? time(),
			);
			update_option( 'tailwatch_custom_schedules', $custom_schedules );
			Log::info(
				"Schedule '$name' updated successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_edit_schedule_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the schedule name.
				'message' => sprintf( __( 'Schedule \'%s\' updated successfully.', 'tailwatch' ), $name ),
				'code'    => 200,
				'data'    => array(
					'slug'     => $slug,
					'display'  => $name,
					'interval' => $interval,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_edit_schedule_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while editing custom schedule.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_delete_schedule( $post_data ) {
		try {
			$cron_job_status = new GetCronJobDetailsController();
			$is_enabled      = $cron_job_status->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				Log::error(
					'Cron Job feature is not enabled',
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_schedule_failed',
						'detail'   => 'Cron Job feature is not enabled',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}
			$json_data        = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data             = json_decode( $json_data, true );
			$slug             = isset( $data['slug'] ) ? sanitize_text_field( $data['slug'] ) : '';
			$slug             = sanitize_title_with_dashes( $slug );
			$slug             = str_replace( '-', '_', $slug );
			$custom_schedules = get_option( 'tailwatch_custom_schedules', array() );
			if ( ! isset( $custom_schedules[ $slug ] ) ) {
				Log::error(
					"Schedule with slug '$slug' does not exist.",
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_job_delete_schedule_failed',
						'detail'   => "Schedule with slug '$slug' does not exist.",
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the schedule slug.
					'message' => sprintf( __( 'Schedule with slug \'%s\' does not exist.', 'tailwatch' ), $slug ),
					'code'    => 404,
					'data'    => array(),
				);
			}

			// Check active cron jobs for schedule usage.
			$crons = _get_cron_array();
			if ( empty( $crons ) ) {
				$crons = array();
			}
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $event ) {
						if ( isset( $event['schedule'] ) && $event['schedule'] === $slug ) {
							Log::error(
								"Cannot delete schedule '$slug' as it is in use by active cron jobs.",
								array(
									'feature'  => 'cron_jobs',
									'action'   => 'cron_job_delete_schedule_failed',
									'detail'   => "Cannot delete schedule '$slug' as it is in use by active cron jobs.",
									'origin'   => 'system',
									'severity' => 'medium',
								)
							);
							return array(
								'success' => false,
								// translators: %s is the schedule slug.
								'message' => sprintf( __( 'Cannot delete schedule \'%s\' as it is in use by active cron jobs.', 'tailwatch' ), $slug ),
								'code'    => 400,
								'data'    => array(),
							);
						}
					}
				}
			}

			// Check paused jobs for schedule usage — deleting the schedule
			// would make these jobs unresumable.
			$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );
			foreach ( $paused_jobs as $job ) {
				if ( isset( $job['schedule'] ) && $job['schedule'] === $slug ) {
					Log::error(
						"Cannot delete schedule '$slug' as it is in use by paused cron jobs.",
						array(
							'feature'  => 'cron_jobs',
							'action'   => 'cron_job_delete_schedule_failed',
							'detail'   => "Cannot delete schedule '$slug' as it is in use by paused cron jobs.",
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
					return array(
						'success' => false,
						// translators: %s is the schedule slug.
						'message' => sprintf( __( 'Cannot delete schedule \'%s\' as it is in use by paused cron jobs.', 'tailwatch' ), $slug ),
						'code'    => 400,
						'data'    => array(),
					);
				}
			}

			unset( $custom_schedules[ $slug ] );
			update_option( 'tailwatch_custom_schedules', $custom_schedules );
			Log::info(
				"Schedule '$slug' deleted successfully.",
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_delete_schedule_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				// translators: %s is the schedule slug.
				'message' => sprintf( __( 'Schedule \'%s\' deleted successfully.', 'tailwatch' ), $slug ),
				'code'    => 200,
				'data'    => array( 'slug' => $slug ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_delete_schedule_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while deleting custom schedule.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_cleanup_paused_jobs() {
		try {
			$paused_jobs  = get_option( 'tailwatch_paused_cron_jobs', array() );
			$cleaned_jobs = array();
			foreach ( $paused_jobs as $hash => $job ) {
				if ( ! empty( $job['hook'] ) && ! empty( $job['timestamp'] ) && ! empty( $job['schedule'] ) ) {
					$cleaned_jobs[ $hash ] = $job;
				}
			}
			if ( count( $cleaned_jobs ) !== count( $paused_jobs ) ) {
				update_option( 'tailwatch_paused_cron_jobs', $cleaned_jobs );
			}
			Log::info(
				'Paused jobs cleaned up successfully.',
				array(
					'feature' => 'cron_jobs',
					'action'  => 'cron_job_cleanup_paused_jobs_completed',
					'origin'  => 'system',
				)
			);
			return array(
				'success' => true,
				'message' => __( 'Paused jobs cleaned up successfully.', 'tailwatch' ),
				'code'    => 200,
				'data'    => array(),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_job_cleanup_paused_jobs_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while cleaning up paused jobs.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}
}
