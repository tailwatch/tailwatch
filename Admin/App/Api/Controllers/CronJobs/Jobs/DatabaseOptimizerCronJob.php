<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Database Optimizer Cron Job
 *
 * Handles scheduled recurring database optimization using the same Jobs pattern as other features.
 * When the schedule fires, it starts one optimization run (automatically).
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 *
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class DatabaseOptimizerCronJob
 *
 * Cron job for Database Optimizer feature:
 * - Schedules recurring optimization based on interval (Every 12/24/48 Hours, Every Week).
 * - On execute, starts the optimizer with scan_type 'automatically'.
 *
 */
class DatabaseOptimizerCronJob extends AbstractCronJob {

	/**
	 * Database Optimizer Controller instance (lazy loaded).
	 *
	 * @var DatabaseOptimizerController|null
	 */
	private $db_optimizer_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_start_database_optimization';
		$this->schedule_name    = 'wptw_db_optimized';
		$this->default_interval = 'Every 48 Hours';

		parent::__construct();
	}

	/**
	 * Get Database Optimizer controller instance (lazy loading).
	 *
	 * @return DatabaseOptimizerController
	 */
	private function get_db_optimizer_controller() {
		if ( null === $this->db_optimizer_controller ) {
			$this->db_optimizer_controller = new DatabaseOptimizerController();
		}
		return $this->db_optimizer_controller;
	}

	/**
	 * Get feature settings (from default_database_optimizer).
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_db_optimizer_controller()->wptw_db_optimize_options();
	}

	/**
	 * Get configured interval from DB Optimizer settings (field_15 = rotation interval).
	 *
	 * @return string Interval string (e.g., 'Every 48 Hours', 'Every Week').
	 */
	protected function get_configured_interval() {
		$settings = $this->get_cached_settings();

		$interval = isset( $settings['field_1']['sub_options']['field_15']['options'] )
			? $settings['field_1']['sub_options']['field_15']['options']
			: $this->default_interval;

		if ( is_array( $interval ) ) {
			foreach ( $interval as $option ) {
				if ( isset( $option['selected'] ) && $option['selected'] ) {
					return isset( $option['value'] ) ? sanitize_text_field( $option['value'] ) : $this->default_interval;
				}
			}
			return $this->default_interval;
		}

		return is_string( $interval ) ? sanitize_text_field( $interval ) : $this->default_interval;
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return 'Database Optimizer';
	}

	/**
	 * Execute the database optimizer cron job.
	 *
	 * Starts one optimization run with scan_type 'automatically'.
	 *
	 * @return void
	 */
	public function execute() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Skip this scheduled cycle if a conflicting process is currently
		// running. WP-Cron will fire the next recurring occurrence normally.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'db_optimize' );
		if ( null !== $blocked ) {
			Log::info(
				'Scheduled database optimization skipped: a conflicting process is running',
				array(
					'feature'         => 'database_optimizer',
					'action'          => 'scheduled_db_optimize_skipped',
					'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
				)
			);
			return;
		}

		/**
		 * Fires before database optimizer cron executes.
		 *
		 */
		do_action( 'wptw_before_database_optimizer_cron_execute' );

		$this->get_db_optimizer_controller()->wptw_database_optimize_start( 'automatically' );

		/**
		 * Fires after database optimizer cron was triggered.
		 *
		 */
		do_action( 'wptw_after_database_optimizer_cron_execute' );
	}
}
