<?php
/**
 * JWT Cleanup Cron Job
 *
 * Runs daily to prune expired JWT tracking rows created by the Connect REST API.
 * Every issued or refreshed token writes a tailwatch_token_jti_* option; this job
 * removes those whose lifetime has passed, plus any orphaned revocation flags, so
 * the options table does not grow unbounded. Always-on maintenance — it does no
 * work when there are no tokens.
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
use Tailwatch\Admin\App\Api\Controllers\Verification\VerificationKeysController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class JwtCleanupCronJob
 *
 * Daily cron job that deletes expired JWT tracking rows.
 *
 * @since 1.0.0
 */
class JwtCleanupCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->cron_hook_name   = 'tailwatch_jwt_cleanup';
		$this->schedule_name    = 'tailwatch_jwt_cleanup_schedule';
		$this->default_interval = 'Daily';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * Token cleanup has no user-facing feature toggle — it is a lightweight
	 * maintenance job that should always run to prevent option-table bloat.
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
	 * Always enabled — the cleanup is a no-op when no tokens exist.
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
		return 'JWT Token Cleanup';
	}

	/**
	 * Execute the JWT token cleanup task.
	 *
	 * Delegates to VerificationKeysController where the cleanup logic lives.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$controller = new VerificationKeysController();
			$controller->cleanup_expired_jti_tokens();
		} catch ( \Throwable $e ) {
			Log::error(
				'JWT token cleanup cron job failed',
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
