<?php
/**
 * Files Integrity Cron Job
 *
 * Handles scheduled recurring file integrity scans using the same Jobs pattern as other features.
 * When the schedule fires, it removes stale garbage entries then triggers a new monitoring cycle.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class IntegrityWatchCronJob
 *
 * Cron job for Files Integrity feature:
 * - Schedules recurring scans based on the configured interval (Hourly, Every 3/6/12/24 Hours).
 * - On execute, clears stale garbage entries then starts a new monitoring run automatically.
 *
 */
class IntegrityWatchCronJob extends AbstractCronJob {

	/**
	 * Integrity Watch Controller instance (lazy loaded).
	 *
	 * @var IntegrityWatchController|null
	 */
	private $integrity_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_files_integrity_schedule_run';
		$this->schedule_name    = 'wptw_files_integrity_schedule';
		$this->default_interval = 'Every 12 Hours';

		parent::__construct();
	}

	/**
	 * Get Integrity Watch Controller instance (lazy loading).
	 *
	 * @return IntegrityWatchController
	 */
	private function get_integrity_controller() {
		if ( null === $this->integrity_controller ) {
			$this->integrity_controller = new IntegrityWatchController();
		}
		return $this->integrity_controller;
	}

	/**
	 * Get feature settings (from default_file_integrity_check).
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_integrity_controller()->get_features_options();
	}

	/**
	 * Get configured interval from integrity settings (field_3 = scan interval).
	 *
	 * @return string Interval string (e.g., 'Every 12 Hours').
	 */
	protected function get_configured_interval() {
		$settings = $this->get_cached_settings();

		$interval = isset( $settings['field_1']['sub_options']['field_3']['options'] )
			? $settings['field_1']['sub_options']['field_3']['options']
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
		return 'Files Integrity';
	}

	/**
	 * Execute the files integrity cron job.
	 *
	 * Removes stale garbage entries first, then starts a new automated monitoring run.
	 *
	 * @return void
	 */
	public function execute() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Skip this scheduled cycle if a conflicting process is currently
		// running. WP-Cron will fire the next recurring occurrence normally.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'files_integrity' );
		if ( null !== $blocked ) {
			Log::info(
				'Scheduled files-integrity scan skipped: a conflicting process is running',
				array(
					'feature'         => 'files_integrity',
					'action'          => 'scheduled_files_integrity_skipped',
					'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
				)
			);
			return;
		}

		/**
		 * Fires before files integrity cron executes.
		 *
		 */
		do_action( 'wptw_before_files_integrity_cron_execute' );

		$remove_data = $this->get_integrity_controller()->wptw_remove_garbage_entries_files();

		if ( $remove_data ) {
			$this->get_integrity_controller()->wptw_start_monitoring( 'automatically' );
		}

		/**
		 * Fires after files integrity cron was triggered.
		 *
		 */
		do_action( 'wptw_after_files_integrity_cron_execute' );
	}
}
