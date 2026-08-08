<?php

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\CronHeal;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Logging\Log;

class CronHealer {

	public function __construct() {
	}

	public function tailwatch_cron_healer() {
		try {
			// Prevent infinite loops caused by lack of wp-cron.php.
			if ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				Log::error(
					'WP Cron is disabled via DISABLE_WP_CRON constant',
					array(
						'feature'  => 'cron',
						'action'   => 'cron_healer_execution_cron_failed',
						'detail'   => 'WP Cron is disabled via DISABLE_WP_CRON constant',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'      => 200,
					'processed' => 0,
					'message'   => __( 'WP Cron is disabled - no jobs processed', 'tailwatch' ),
				);
			}

			$post_data = null;
			// Parse and validate input data
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );


			$cron_name = isset( $data['cron_name'] ) ? sanitize_text_field( $data['cron_name'] ) : '';

			// Get our plugin's registered cron hooks
			$our_cron_hooks = array_fill_keys( CronJobManager::get_all_cron_hooks(), true );
			if ( empty( $our_cron_hooks ) ) {
				Log::error(
					'CronJobManager returned empty array of cron hooks',
					array(
						'feature'  => 'cron',
						'action'   => 'cron_healer_execution_cron_failed',
						'detail'   => 'CronJobManager returned empty array of cron hooks',
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'      => 500,
					'processed' => 0,
					'message'   => __( 'No plugin cron hooks registered', 'tailwatch' ),
				);
			}

			// Get ready cron jobs
			$ready_crons = wp_get_ready_cron_jobs();
			if ( empty( $ready_crons ) ) {
				return array(
					'code'      => 200,
					'processed' => 0,
					'message'   => __( 'No cron jobs ready for processing', 'tailwatch' ),
				);
			}

			$gmt_time = microtime( true );
			$keys     = array_keys( $ready_crons );
			if ( isset( $keys[0] ) && $keys[0] > $gmt_time ) {
				return array(
					'code'      => 200,
					'processed' => 0,
					'message'   => __( 'No cron jobs ready for current time', 'tailwatch' ),
				);
			}

			// Filter ready crons to only include our plugin's crons
			$executable_crons = $this->filter_our_ready_crons( $ready_crons, $our_cron_hooks, $cron_name );

			if ( empty( $executable_crons ) ) {
				$message = $cron_name ?
					"Specified cron '{$cron_name}' not found in ready jobs" :
					'No plugin cron jobs ready for processing';

				return array(
					'code'      => 200,
					'processed' => 0,
					'message'   => $message,
				);
			}

			$results        = array();
			$schedules      = wp_get_schedules();
			$executed_hooks = array();

			foreach ( $executable_crons as $timestamp => $cronhooks ) {
				if ( $timestamp > $gmt_time ) {
					break;
				}

				foreach ( (array) $cronhooks as $hook => $args ) {
					if ( isset( $schedules[ $hook ]['callback'] ) &&
						! call_user_func( $schedules[ $hook ]['callback'] ) ) {
						continue;
					}

					// Execute this specific cron - pass the ready crons to avoid duplicate call
					$result           = $this->spawn_cron( $gmt_time, $hook, $ready_crons );
					$results[]        = $result;
					$executed_hooks[] = $hook;

					if ( ! $result ) {
						Log::error(
							"Failed to execute cron hook: {$hook} at timestamp: {$timestamp}",
							array(
								'feature'  => 'cron',
								'action'   => 'cron_healer_execution_cron_failed',
								'detail'   => "Failed to execute cron hook: {$hook} at timestamp: {$timestamp}",
								'origin'   => 'system',
								'severity' => 'high',
							)
						);
					}

					// If specific cron requested, only run that one
					if ( $cron_name && $hook === $cron_name ) {
						break 2;
					}

					// Otherwise, run first available and break
					break 2;
				}
			}

			if ( in_array( false, $results, true ) ) {
				Log::error(
					'One or more cron jobs failed to spawn. Executed hooks: ' . implode( ', ', $executed_hooks ),
					array(
						'feature'  => 'cron',
						'action'   => 'cron_healer_execution_cron_failed',
						'detail'   => 'One or more cron jobs failed to spawn. Executed hooks: ' . implode( ', ', $executed_hooks ),
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
				return array(
					'code'      => 500,
					'processed' => 0,
					'message'   => __( 'Failed to spawn cron jobs', 'tailwatch' ),
				);
			}

			$processed_count = count( $results );

			return array(
				'code'      => 200,
				'processed' => $processed_count,
				'message'   => $processed_count > 0 ?
					sprintf(
						/* translators: 1: number of cron jobs processed, 2: list of executed hooks, 3: optional specific cron name */
						__( 'Successfully processed %1$d cron job(s). Hooks executed: %2$s%3$s', 'tailwatch' ),
						$processed_count,
						implode( ', ', $executed_hooks ),
						// translators: %s is the cron hook name.
						$cron_name ? sprintf( __( ' (specific cron: %s)', 'tailwatch' ), $cron_name ) : ''
					) :
					__( 'No cron jobs processed', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Exception during cron healing: ' . $e->getMessage(),
				array(
					'feature'  => 'cron',
					'action'   => 'cron_healer_execution_cron_failed',
					'detail'   => 'Exception occurred during cron healing process: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'      => 500,
				'processed' => 0,
				'message'   => __( 'An error occurred during cron healing process', 'tailwatch' ),
			);
		}
	}

	/**
	 * Filter ready crons to only include our plugin's crons
	 */
	private function filter_our_ready_crons( $ready_crons, $our_cron_hooks, $specific_cron_name = '' ) {
		$filtered_crons = array();
		$our_hook_names = array_keys( $our_cron_hooks );

		foreach ( $ready_crons as $timestamp => $cronhooks ) {
			foreach ( $cronhooks as $hook => $args ) {
				// Check if this hook belongs to our plugin
				if ( in_array( $hook, $our_hook_names ) ) {
					// If specific cron requested, only include that one
					if ( $specific_cron_name ) {
						if ( $hook === $specific_cron_name ) {
							$filtered_crons[ $timestamp ][ $hook ] = $args;
						}
					} else {
						// Include all our plugin's ready crons
						$filtered_crons[ $timestamp ][ $hook ] = $args;
					}
				}
			}
		}

		// If specific cron requested but not found in ready jobs,
		// check if we can run any of our plugin's ready crons as fallback
		if ( $specific_cron_name && empty( $filtered_crons ) ) {

			foreach ( $ready_crons as $timestamp => $cronhooks ) {
				foreach ( $cronhooks as $hook => $args ) {
					if ( in_array( $hook, $our_hook_names ) ) {
						$filtered_crons[ $timestamp ][ $hook ] = $args;
						break 2; // Only get first available as fallback
					}
				}
			}
		}

		return $filtered_crons;
	}

	public function spawn_cron( $gmt_time = 0, $specific_hook = '', $ready_crons = null ) {

		if ( ! $gmt_time ) {
			$gmt_time = microtime( true );
		}

		// Use passed ready crons or get them if not provided
		if ( $ready_crons === null ) {
			$crons = wp_get_ready_cron_jobs();
		} else {
			$crons = $ready_crons;
		}

		if ( empty( $crons ) ) {
			return false;
		}

		// If specific hook provided, verify it's still in ready jobs
		if ( $specific_hook ) {
			$hook_found = false;
			foreach ( $crons as $timestamp => $cronhooks ) {
				if ( isset( $cronhooks[ $specific_hook ] ) ) {
					$hook_found = true;
					break;
				}
			}

			if ( ! $hook_found ) {
				return false;
			}
		}

		$keys = array_keys( $crons );

		if ( isset( $keys[0] ) && $keys[0] > $gmt_time ) {
			return false;
		}

		$doing_wp_cron = sprintf( '%.22F', $gmt_time );
		set_transient( 'doing_cron', $doing_wp_cron );

		$redirect = add_query_arg( 'doing_wp_cron', $doing_wp_cron, site_url( 'wp-cron.php' ) );

		// Open an output buffer to capture the wp_safe_redirect() headers + the
		// single-space body below. This is the standard "decouple cron from the
		// HTTP response" pattern used by wp-cron.php itself: we send a 302
		// redirect + minimal body to the client, flush everything, and only
		// then require wp-cron.php so the actual cron work runs after the
		// client connection is closed (where the host supports FastCGI/PHP-FPM
		// early-flush semantics).
		ob_start();
		wp_safe_redirect( $redirect );
		echo ' ';

		// Close OUR buffer explicitly first; wp_ob_end_flush_all() then closes any OTHER buffers
		// WordPress core or other plugins may have opened, so the entire
		// response stack drains to the client before wp-cron.php runs.
		if ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		wp_ob_end_flush_all();
		flush();

		require_once ABSPATH . 'wp-cron.php';

		return true;
	}
}
