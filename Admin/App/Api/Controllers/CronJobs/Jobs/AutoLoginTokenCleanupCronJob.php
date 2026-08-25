<?php
/**
 * Auto-Login Token Cleanup Cron Job
 *
 * Runs daily to delete expired auto-login token records. Auto-login tokens are
 * single-use and short-lived (<=1h) and are normally removed on use; this job
 * prunes any that expired unused so the options table does not accumulate them.
 * Always-on maintenance — a no-op when there are no tokens.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Services\Login\AutoLogin;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class AutoLoginTokenCleanupCronJob
 *
 * @since 1.0.0
 */
class AutoLoginTokenCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->cron_hook_name   = 'tailwatch_auto_login_cleanup';
		$this->schedule_name    = 'tailwatch_auto_login_cleanup_schedule';
		$this->default_interval = 'Daily';

		parent::__construct();
	}

	/**
	 * Get feature settings. Maintenance job — no user-facing toggle.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty array.
	 */
	protected function get_feature_settings() {
		return array();
	}

	/**
	 * Always enabled — the cleanup is a no-op when no tokens exist.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force_refresh Unused.
	 *
	 * @return bool Always true.
	 */
	protected function is_enabled( $force_refresh = false ) {
		return true;
	}

	/**
	 * Human-readable schedule display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_schedule_display_name() {
		return 'Auto-Login Token Cleanup';
	}

	/**
	 * Execute the cleanup task.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function execute() {
		try {
			( new AutoLogin() )->cleanup_expired_tokens();
		} catch ( \Throwable $e ) {
			Log::error(
				'Auto-login token cleanup cron job failed',
				array(
					'feature'   => 'auto_login_cleanup_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}
