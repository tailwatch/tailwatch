<?php
// phpcs:ignoreFile WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Cron-hash signatures mirror WP core's own md5(timestamp . hook . serialize(args)) convention; every serialize() in this file is for that keying purpose.

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Time\TimeService;

class GetCronJobDetailsController {

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
	 * Per-request memoization of the plugin-system hook list. Filled lazily
	 * by get_plugin_system_hooks() on first call.
	 *
	 * @var array<string>|null
	 */
	private static $plugin_system_hooks_cache = null;

	/**
	 * Canonical list of cron hooks owned by this plugin (and by Pro / other
	 * extensions via the `tailwatch_register_cron_hooks` filter). Sourced from
	 * CronJobManager::get_all_cron_hooks() so the list stays in sync
	 * automatically when a new feature ships a new cron — no manual list to
	 * maintain in two places.
	 *
	 * Cached per-request because the listing endpoint walks every cron event
	 * once per page render, and the modify actions in the sibling controller
	 * call this repeatedly inside bulk-action loops.
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

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'init', array( $this, 'tailwatch_register_custom_schedules' ) );
		$hook_controller->add_action_hook( 'init', array( $this, 'tailwatch_prevent_paused_job_execution' ) );
	}

	public function tailwatch_register_custom_schedules() {
		$hook_controller = new HookControllers();
		$hook_controller->add_filter_hook(
			'cron_schedules',
			function ( $schedules ) {
				$custom_schedules = get_option( 'tailwatch_custom_schedules', array() );
				foreach ( $custom_schedules as $slug => $schedule ) {
					$schedules[ $slug ] = array(
						'interval' => $schedule['interval'],
						'display'  => $schedule['display'],
					);
				}
				return $schedules;
			}
		);
	}

	/**
	 * Prevent WordPress or other plugins from rescheduling a paused cron event.
	 *
	 * Matches by hook name + serialized args (NOT timestamp) so the filter
	 * catches rescheduling attempts regardless of the new timestamp.
	 *
	 */
	public function tailwatch_prevent_paused_job_execution() {
		$hook_controller = new HookControllers();
		$hook_controller->add_filter_hook(
			'pre_schedule_event',
			function ( $pre, $event ) {
				$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );

				if ( empty( $paused_jobs ) || ! is_array( $paused_jobs ) ) {
					return $pre;
				}

				$event_args_serialized = serialize( $event->args );

				foreach ( $paused_jobs as $job ) {
					if ( $job['hook'] === $event->hook && serialize( $job['args'] ) === $event_args_serialized ) {
						return false;
					}
				}

				return $pre;
			},
			10,
			2
		);
	}

	private function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_cron_job_list';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	public function tailwatch_cron_job_feature_enable() {
		$feature_enable = $this->get_features_options();

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

	// 3rd version
	public function tailwatch_get_cron_jobs_with_source( $post_data ) {
		try {
			$is_enabled = $this->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = is_string( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = is_string( $json_data ) ? json_decode( $json_data, true ) : array();
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON format: ' . json_last_error_msg(),
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_jobs_list_retrieved_failed',
						'detail'   => 'Invalid JSON format: ' . json_last_error_msg(),
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the JSON error message.
					'message' => sprintf( __( 'Invalid JSON format: %s', 'tailwatch' ), json_last_error_msg() ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$search = isset( $data['search'] ) ? sanitize_text_field( $data['search'] ) : '';
			$filter = isset( $data['filter'] ) ? sanitize_text_field( $data['filter'] ) : '__all';
			$limit  = isset( $data['limit'] ) ? absint( $data['limit'] ) : 10;
			$page   = isset( $data['page'] ) ? absint( $data['page'] ) : 1;

			if ( $limit < 1 ) {
				$limit = 10;
			}
			if ( $limit > 100 ) {
				$limit = 100;
			}
			if ( $page < 1 ) {
				$page = 1;
			}

			global $wp_filter;
			$cron_jobs = _get_cron_array();
			if ( empty( $cron_jobs ) ) {
				$cron_jobs = array();
			}
			$paused_jobs = get_option( 'tailwatch_paused_cron_jobs', array() );

			$created_sigs = get_option( 'tailwatch_created_cron_jobs', array() );
			if ( ! is_array( $created_sigs ) ) {
				$created_sigs = array();
			}

			$custom_schedules          = get_option( 'tailwatch_custom_schedules', array() );
			$schedules                 = wp_get_schedules();
			$schedules['__single_run'] = array( 'display' => 'Non-repeating' );

			foreach ( $custom_schedules as $slug => $schedule ) {
				$schedules[ $slug ] = array(
					'display'  => $schedule['display'],
					'interval' => $schedule['interval'],
				);
			}

			$current_time = time();
			$offset       = ( $page - 1 ) * $limit;
			$total_jobs   = 0;

			// Step 1: Collect all jobs (active and paused) into a single array
			$all_jobs   = array();
			$job_hashes = array(); // Track hashes to prevent duplicates

			// Process active jobs
			foreach ( $cron_jobs as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $key => $event ) {
						$hash     = md5( $timestamp . $hook . serialize( $event['args'] ) );
						$schedule = ! empty( $event['schedule'] ) ? $event['schedule'] : '__single_run';
						if ( $this->should_include_job( $hook, $schedule, $search, $filter, $hash, $paused_jobs, false ) && ! in_array( $hash, $job_hashes ) ) {
							++$total_jobs;
							$job_hashes[] = $hash;
							$all_jobs[]   = array(
								'hash'      => $hash,
								'hook'      => $hook,
								'timestamp' => $timestamp,
								'schedule'  => $schedule,
								'args'      => $event['args'],
								'interval'  => $event['interval'] ?? 0,
								'key'       => $key,
								'is_paused' => false,
							);
						}
					}
				}
			}

			// Process paused jobs
			foreach ( $paused_jobs as $hash => $job ) {
				if ( $this->should_include_job( $job['hook'], $job['schedule'], $search, $filter, $hash, $paused_jobs, true ) && ! in_array( $hash, $job_hashes ) ) {
					++$total_jobs;
					$job_hashes[] = $hash;
					$all_jobs[]   = array(
						'hash'      => $hash,
						'hook'      => $job['hook'],
						'timestamp' => $job['timestamp'],
						'schedule'  => $job['schedule'],
						'args'      => $job['args'],
						'interval'  => $job['interval'],
						'key'       => $job['key'],
						'is_paused' => true,
					);
				}
			}

			// Check if page is out of range
			$total_pages = ceil( $total_jobs / $limit );
			if ( $page > $total_pages && $total_jobs > 0 ) {
				return array(
					'success'    => false,
					'message'    => __( 'No cron jobs found on this page.', 'tailwatch' ),
					'code'       => 404,
					'data'       => array(),
					'pagination' => array(
						'total_jobs'  => $total_jobs,
						'page'        => $page,
						'limit'       => $limit,
						'total_pages' => $total_pages,
					),
				);
			}

			// Step 2: Sort all jobs by timestamp
			usort( $all_jobs, fn( $a, $b ) => $a['timestamp'] <=> $b['timestamp'] );

			// Step 3: Apply pagination and build response
			$cron_job_list = array();
			$jobs_added    = 0;

			foreach ( $all_jobs as $job ) {
				// Skip jobs before the current page
				if ( $jobs_added < $offset ) {
					++$jobs_added;
					continue;
				}

				// Stop if we've collected enough jobs
				if ( $jobs_added >= $offset + $limit ) {
					break;
				}

				$next_run_human_readable = TimeService::format_time_remaining( $job['timestamp'], $current_time );
				$action_details          = $this->get_action_details( $job['hook'] );
				$schedule_display        = isset( $schedules[ $job['schedule'] ] ) ? $schedules[ $job['schedule'] ]['display'] : 'Non-repeating';
				$is_protected            = in_array( $job['hook'], $this->protected_events, true );
				$is_plugin_system        = $this->is_plugin_system_hook( $job['hook'] );
				$signature               = md5( $job['hook'] . '|' . $job['schedule'] . '|' . serialize( $job['args'] ) );

				$cron_job_list[] = array(
					'hash'               => $job['hash'],
					'hook'               => $job['hook'],
					'timestamp'          => $job['timestamp'],
					'next_run'           => $next_run_human_readable,
					'next_run_formatted' => gmdate( 'Y-m-d H:i:s', $job['timestamp'] ),
					'actions'            => implode( ', ', $action_details ),
					'schedule'           => $job['schedule'],
					'schedule_display'   => $schedule_display,
					'args'               => ! empty( $job['args'] ) ? wp_json_encode( $job['args'] ) : '',
					'interval'           => $job['interval'],
					'key'                => $job['key'],
					'is_paused'          => $job['is_paused'],
					'is_protected'       => $is_protected,
					'is_plugin_system'   => $is_plugin_system,
					'created_by_plugin'  => isset( $created_sigs[ $signature ] ),
				);
				++$jobs_added;
			}

			// Prepare response
			return array(
				'success'    => true,
				'message'    => empty( $cron_job_list ) ? __( 'No cron jobs found.', 'tailwatch' ) : __( 'Cron jobs retrieved successfully.', 'tailwatch' ),
				'code'       => 200,
				'data'       => $cron_job_list,
				'pagination' => array(
					'total_jobs'  => $total_jobs,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => $total_pages,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_jobs_list_retrieved_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while retrieving cron jobs list.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	public function tailwatch_get_schedules( $post_data ) {
		try {

			$is_enabled = $this->tailwatch_cron_job_feature_enable();
			if ( ! $is_enabled['feature_enable'] ) {
				return array(
					'data'           => array(),
					'feature_enable' => $is_enabled['feature_enable'],
					'parent_enable'  => $is_enabled['parent_enable'],
					'message'        => __( 'Cron Job feature is not enabled. Please enable it first.', 'tailwatch' ),
					'code'           => 400,
				);
			}

			$json_data = is_string( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = is_string( $json_data ) ? json_decode( $json_data, true ) : array();
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON format: ' . json_last_error_msg(),
					array(
						'feature'  => 'cron_jobs',
						'action'   => 'cron_schedules_list_failed',
						'detail'   => 'Invalid JSON format: ' . json_last_error_msg(),
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'success' => false,
					// translators: %s is the JSON error message.
					'message' => sprintf( __( 'Invalid JSON format: %s', 'tailwatch' ), json_last_error_msg() ),
					'code'    => 400,
					'data'    => array(),
				);
			}

			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : 10;
			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : 1;

			if ( $limit < 1 ) {
				$limit = 10;
			}
			if ( $limit > 100 ) {
				$limit = 100;
			}
			if ( $page < 1 ) {
				$page = 1;
			}

			$schedules                 = wp_get_schedules();
			$custom_schedules          = get_option( 'tailwatch_custom_schedules', array() );
			$schedules['__single_run'] = array(
				'display'  => 'Non-repeating',
				'interval' => 0,
			);

			foreach ( $custom_schedules as $slug => $schedule ) {
				$schedules[ $slug ] = array(
					'display'    => $schedule['display'],
					'interval'   => $schedule['interval'],
					'created_at' => $schedule['created_at'] ?? 0,
				);
			}

			$schedule_list = array();
			foreach ( $schedules as $key => $schedule ) {
				$schedule_list[] = array(
					'slug'         => $key,
					'display'      => $schedule['display'],
					'interval'     => isset( $schedule['interval'] ) ? TimeService::format_interval( $schedule['interval'] ) : 'N/A',
					'is_protected' => ! isset( $custom_schedules[ $key ] ),
					'created_at'   => $schedule['created_at'] ?? 0,
				);
			}

			usort(
				$schedule_list,
				function ( $a, $b ) {
					// Pin Non-repeating (__single_run) to the top.
					if ( '__single_run' === $a['slug'] ) {
						return -1;
					}
					if ( '__single_run' === $b['slug'] ) {
						return 1;
					}

					$a_is_custom = ! $a['is_protected'];
					$b_is_custom = ! $b['is_protected'];

					// Both custom: sort by created_at (newest first)
					if ( $a_is_custom && $b_is_custom ) {
						return $b['created_at'] <=> $a['created_at'];
					}
					// Custom before built-in
					if ( $a_is_custom && ! $b_is_custom ) {
						return -1;
					}
					if ( ! $a_is_custom && $b_is_custom ) {
						return 1;
					}
					// Both built-in: sort by display name
					return strcmp( $a['display'], $b['display'] );
				}
			);

			// Calculate pagination metadata
			$total_schedules = count( $schedule_list );
			$total_pages     = ceil( $total_schedules / $limit );
			$offset          = ( $page - 1 ) * $limit;

			if ( $page > $total_pages && $total_schedules > 0 ) {
				return array(
					'success'    => false,
					'message'    => __( 'No schedules found on this page.', 'tailwatch' ),
					'code'       => 404,
					'data'       => array(),
					'pagination' => array(
						'total_schedules' => $total_schedules,
						'page'            => $page,
						'limit'           => $limit,
						'total_pages'     => $total_pages,
					),
				);
			}

			// Apply pagination
			$paginated_schedules = array_slice( $schedule_list, $offset, $limit );

			$response = array(
				'success'    => true,
				'message'    => empty( $paginated_schedules ) ? __( 'No schedules found.', 'tailwatch' ) : __( 'Schedules retrieved successfully.', 'tailwatch' ),
				'code'       => 200,
				'data'       => $paginated_schedules,
				'pagination' => array(
					'total_schedules' => $total_schedules,
					'page'            => $page,
					'limit'           => $limit,
					'total_pages'     => $total_pages,
				),
			);

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'  => 'cron_jobs',
					'action'   => 'cron_schedules_list_retrieved_failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'success' => false,
				'message' => __( 'Exception occurred while retrieving cron schedules list.', 'tailwatch' ),
				'code'    => 500,
				'data'    => array(),
			);
		}
	}

	private function should_include_job( $hook, $schedule, $search, $filter, $hash, $paused_jobs, $is_paused = false ) {
		if ( $search && stripos( $hook, $search ) === false && stripos( $this->get_action_details( $hook )[0], $search ) === false ) {
			return false;
		}
		if ( $filter !== '__all' && $schedule !== $filter ) {
			return false;
		}
		// Skip active jobs that are also paused (to avoid duplicates), but allow paused jobs
		if ( ! $is_paused && isset( $paused_jobs[ $hash ] ) && $paused_jobs[ $hash ]['hook'] === $hook && $paused_jobs[ $hash ]['schedule'] === $schedule ) {
			return false;
		}
		return true;
	}

	public function get_action_details( $hook ) {
		global $wp_filter;

		$actions = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
		$details = array();

		foreach ( $actions as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_string( $callback['function'] ) ) {
					$details[] = $callback['function'] . '()';
				} elseif ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) ) {
					if ( is_object( $callback['function'][0] ) ) {
						$class     = get_class( $callback['function'][0] );
						$method    = $callback['function'][1];
						$details[] = "$class::$method()";
					} elseif ( is_string( $callback['function'][0] ) ) {
						$details[] = $callback['function'][0] . '::' . $callback['function'][1] . '()';
					}
				}
			}
		}

		return empty( $details ) ? array( 'Orphan (No Action)' ) : $details;
	}
}
