<?php
/**
 * Process Monitor Cleanup Cron Job
 *
 * Runs weekly to delete process monitor records older than 7 days from the
 * Tailwatch settings table. Keeps the process tracking table from
 * accumulating indefinite history for completed/failed background jobs.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Models\RecoveryLogModel;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class ProcessMonitorCleanupCronJob
 *
 * Weekly cron job that purges old process monitor records.
 *
 */
class ProcessMonitorCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'tailwatch_process_monitor_cleanup';
		$this->schedule_name    = 'tailwatch_process_monitor_cleanup_schedule';
		$this->default_interval = 'Weekly';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * Process monitor cleanup has no user-facing feature toggle — it is a
	 * maintenance job that should always run regardless of which features
	 * are enabled.
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
		return 'Process Monitor Cleanup';
	}

	/**
	 * Execute the process monitor cleanup task.
	 *
	 * Delegates to ProcessManager::cleanup() which deletes monitor records
	 * older than the provided retention window (7 days).
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$process_manager = new ProcessManager();
			$deleted         = $process_manager->cleanup( 7 );

			// Also prune old recovery-log rows (30-day retention). cleanup_old_logs() otherwise
			// has no caller, so the process_recovery log rows grow unbounded.
			$logs_deleted = ( new RecoveryLogModel() )->cleanup_old_logs( 30 );

			Log::info(
				'Process monitor cleanup completed. Deleted: ' . $deleted,
				array(
					'feature'      => 'process_recovery',
					'action'       => 'process_cleanup',
					'deleted'      => $deleted,
					'logs_deleted' => $logs_deleted,
				)
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Process monitor cleanup cron job failed',
				array(
					'feature'   => 'process_monitor_cleanup_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}