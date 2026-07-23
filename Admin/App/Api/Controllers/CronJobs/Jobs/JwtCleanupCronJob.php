<?php
/**
 * JWT Token Cleanup Cron Job
 *
 * Runs daily to delete expired JWT token tracking rows from wp_options.
 *
 * Each mobile/desktop login creates a `wptw_token_jti_<jti>` option row with
 * an `expires` timestamp. Without cleanup these rows accumulate indefinitely
 * and bloat the options table. This job scans all such rows and removes any
 * where `expires < current time`.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerificationKeysController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class JwtCleanupCronJob
 *
 * Daily cron job that purges expired JWT token tracking rows.
 *
 */
class JwtCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_cleanup_jti';
		$this->schedule_name    = 'wptw_jwt_cleanup_schedule';
		$this->default_interval = 'Daily';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * JWT cleanup has no user-facing feature toggle — it is a lightweight
	 * maintenance job that should always run to prevent options table bloat.
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
	 * `wptw_token_jti_*` rows in the options table.
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
		return esc_html__( 'JWT Token Cleanup', 'tailwatch' );
	}

	/**
	 * Execute the JWT cleanup task.
	 *
	 * Delegates to VerificationKeysController where the domain logic lives.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$controller = new VerificationKeysController();
			$controller->cleanup_expired_jti_tokens();
		} catch ( \Throwable $e ) {
			Log::error(
				'JWT cleanup cron job failed',
				array(
					'feature'   => 'jwt_cleanup_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}