<?php
/**
 * Backup Cleanup Cron Job
 *
 * Runs every 30 minutes to clean up incomplete / stuck / cancelled backup
 * folders and their tracking rows. Deletes cancelled backups immediately,
 * deletes paused backups older than 12 hours, and removes stale progress
 * entries so the backup UI never shows zombie rows.
 *
 * Distinct from BackupCronJob (which runs the actual recurring scheduled
 * backups). This job is maintenance-only and always runs regardless of
 * whether the user has enabled scheduled backups.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class BackupCleanupCronJob
 *
 * Maintenance cron job that removes incomplete backup folders and stale
 * tracking rows every 30 minutes.
 *
 */
class BackupCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'tailwatch_backup_cleanup_cron';
		$this->schedule_name    = 'tailwatch_backup_cleanup_schedule';
		$this->default_interval = 'Every 30 Minutes';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * Backup cleanup has no user-facing feature toggle — it runs regardless
	 * of whether scheduled backups are enabled, because users may still
	 * create manual backups that need cleanup if cancelled/paused.
	 *
	 * @return array Empty array.
	 */
	protected function get_feature_settings() {
		return array();
	}

	/**
	 * Check whether the cleanup cron should run.
	 *
	 * Always enabled.
	 *
	 * @param bool $force_refresh Unused — always returns true.
	 *
	 * @return bool Always true.
	 */
	protected function is_enabled( $force_refresh = false ) {
		return true;
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return 'Backup Cleanup';
	}

	/**
	 * Execute the backup cleanup task.
	 *
	 * Delegates to BackupController where the domain logic lives. The
	 * controller's cleanup method respects active migration / malware scan
	 * progress and only touches backups that are safe to remove.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$controller = new BackupController();
			$controller->tailwatch_auto_cleanup_incomplete_backup();
		} catch ( \Throwable $e ) {
			Log::error(
				'Backup cleanup cron job failed',
				array(
					'feature'   => 'backup_cleanup_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}