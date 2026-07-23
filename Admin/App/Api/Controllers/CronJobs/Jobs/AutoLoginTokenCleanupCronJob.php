<?php
/**
 * Auto-Login Token Cleanup Cron Job
 *
 * Runs daily to delete expired auto-login token rows from wp_options.
 *
 * Each recovery-mode link / dashboard auto-login creates a
 * `wptw_auto_login_token_<token>` option row with an `expires` timestamp.
 * Without cleanup these rows accumulate indefinitely (expired tokens are
 * only purged on next access — links that are never clicked leak forever).
 * This job scans all such rows once per day and removes any where
 * `expires < current time`.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Login\AutoLogin;

/**
 * Class AutoLoginTokenCleanupCronJob
 *
 * Daily cron job that purges expired auto-login token rows.
 *
 */
class AutoLoginTokenCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_cleanup_auto_login_tokens';
		$this->schedule_name    = 'wptw_auto_login_cleanup_schedule';
		$this->default_interval = 'Daily';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * Auto-login token cleanup has no user-facing feature toggle — it is a
	 * lightweight maintenance job that should always run to prevent options
	 * table bloat from unused / expired recovery-mode tokens.
	 *
	 * @return array Empty array.
	 */
	protected function get_feature_settings() {
		return array();
	}

	/**
	 * Check whether the cleanup cron should run.
	 *
	 * Always enabled. The only cost is a once-per-day scan of
	 * `wptw_auto_login_token_*` rows in the options table.
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
		return esc_html__( 'Auto-Login Token Cleanup', 'tailwatch' );
	}

	/**
	 * Execute the auto-login token cleanup task.
	 *
	 * Delegates to AutoLogin service where the deletion logic lives.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$auto_login    = new AutoLogin();
			$cleaned_count = $auto_login->cleanup_expired_tokens();

			if ( $cleaned_count > 0 ) {
				Log::info(
					sprintf( 'Auto-login token cleanup removed %d expired tokens', (int) $cleaned_count ),
					array(
						'feature' => 'auto_login_cleanup_cron',
						'action'  => 'execute_completed',
						'count'   => (int) $cleaned_count,
					)
				);
			}
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