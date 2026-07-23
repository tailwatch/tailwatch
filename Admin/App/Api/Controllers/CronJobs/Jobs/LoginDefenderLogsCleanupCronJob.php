<?php
/**
 * Login Defender Logs Cleanup Cron Job
 *
 * Runs daily to purge old IP activity log rows from the WP Tailwatch settings
 * table. Retention is configurable via login defender settings ('log_retention_days',
 * default 30). Deletes rows where `key` IN ('ip_activity', 'ip_activity_history')
 * and `date_created` is older than the retention window.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 * @since      1.0.0
 *
 * Note: IDE may show false positives for undefined WordPress functions.
 * Functions like esc_html__() are WordPress core globals available at runtime.
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections\IpProtectionController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class LoginDefenderLogsCleanupCronJob
 *
 * Daily cron job that deletes expired IP activity log rows.
 *
 * @since 1.0.0
 */
class LoginDefenderLogsCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_login_defender_cleanup';
		$this->schedule_name    = 'wptw_login_defender_logs_cleanup_schedule';
		$this->default_interval = 'Daily';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * Log cleanup has no user-facing feature toggle — it is a lightweight
	 * maintenance job that should always run to prevent log table bloat.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty array.
	 */
	protected function get_feature_settings() {
		return array();
	}

	/**
	 * Check whether the cleanup cron should run.
	 *
	 * Always enabled. Retention cutoff itself is read from login defender
	 * settings inside the controller, so the behavior naturally adapts if
	 * the feature is reconfigured.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'Login Defender Logs Cleanup', 'tailwatch' );
	}

	/**
	 * Execute the login defender logs cleanup task.
	 *
	 * Delegates to IpProtectionController where the domain logic lives.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$controller = new IpProtectionController();
			$controller->cleanup_old_logs();
		} catch ( \Throwable $e ) {
			Log::error(
				'Login Defender logs cleanup cron job failed',
				array(
					'feature'   => 'login_defender_logs_cleanup_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}