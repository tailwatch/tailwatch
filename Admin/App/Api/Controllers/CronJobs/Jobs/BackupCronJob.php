<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Backup Cron Job
 *
 * Handles scheduled recurring backups using the same Jobs pattern as other features.
 * When the schedule fires, it triggers one backup cycle (wptw_backup_daily_scan);
 * the backup controller then chains further single events until the run completes.
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
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupMaintainController;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class BackupCronJob
 *
 * Cron job for Backup feature:
 * - Schedules recurring backups based on backup interval (Every 12/24 Hours, Every 3 Days, Every 1 Week).
 * - On execute, triggers the main backup hook so BackupController runs the backup cycle.
 *
 */
class BackupCronJob extends AbstractCronJob {

	/**
	 * Backup Maintain Controller instance (lazy loaded).
	 *
	 * @var BackupMaintainController|null
	 */
	private $backup_maintain_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_backup_schedule_run';
		$this->schedule_name    = 'wptw_backup_schedule';
		$this->default_interval = 'Every 3 Days';

		parent::__construct();
	}

	/**
	 * Get Backup Maintain controller instance (lazy loading).
	 *
	 * @return BackupMaintainController
	 */
	private function get_backup_maintain_controller() {
		if ( null === $this->backup_maintain_controller ) {
			$this->backup_maintain_controller = new BackupMaintainController();
		}
		return $this->backup_maintain_controller;
	}

	/**
	 * Get feature settings (backup enable + interval from default_backup_enable).
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_backup_maintain_controller()->get_features_options();
	}

	/**
	 * Get configured interval from backup settings (field_6 = backup interval).
	 *
	 * @return string Interval string (e.g., 'Every 24 Hours', 'Every 1 Week').
	 */
	protected function get_configured_interval() {
		$settings = $this->get_cached_settings();

		$interval = isset( $settings['field_1']['sub_options']['field_6']['options'] )
			? $settings['field_1']['sub_options']['field_6']['options']
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
		return 'Backup';
	}

	/**
	 * Execute the backup cron job.
	 *
	 * Triggers the main backup hook so BackupController::wptw_run_backup_cron runs.
	 * That method handles the full backup cycle (and chains single events internally).
	 *
	 * @return void
	 */
	public function execute() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Skip this scheduled cycle if a conflicting process is currently
		// running. WP-Cron will fire the next recurring occurrence normally;
		// we deliberately do NOT reschedule a one-shot retry here to avoid
		// retry storms when conflicts persist.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'backup' );
		if ( null !== $blocked ) {
			Log::info(
				'Scheduled backup skipped: a conflicting process is running',
				array(
					'feature'         => 'backup',
					'action'          => 'scheduled_backup_skipped',
					'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
				)
			);
			return;
		}

		/**
		 * Fires before backup cron executes.
		 *
		 */
		do_action( 'wptw_before_backup_cron_execute' );

		// Start a fresh backup cycle. We must NOT call wptw_run_backup_cron() here:
		// that method is the worker that resumes an in-flight scan record, and it
		// silently returns when no record exists (which is the case for every fresh
		// scheduled run). wptw_start_backup_creation() is the bootstrap entry point
		// that unschedules the recurring job for the duration of the run, seeds a
		// new scan_backp record, and kicks off the backup chain.
		//
		// Resolve the optimize-DB toggle through the shared helper rather
		// than hardcoding false. Without this, scheduled backups silently
		// ignored the "Optimize Database Before Backup" setting (it worked
		// for instant backups only). The helper runs the same gate chain
		// as the user-initiated path so both honour the same configuration.
		$backup_controller = new BackupController();
		$backup_data       = $this->get_backup_maintain_controller()->wptw_get_backup_settings();
		$database_optimize = $backup_controller->should_optimize_database_for_backup( $backup_data );
		$backup_controller->wptw_start_backup_creation( $database_optimize, false, 'automatically' );

		/**
		 * Fires after backup cron was triggered.
		 *
		 */
		do_action( 'wptw_after_backup_cron_execute' );
	}
}
