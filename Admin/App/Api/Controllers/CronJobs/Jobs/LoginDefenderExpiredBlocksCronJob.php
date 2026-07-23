<?php
/**
 * Login Defender Expired Blocks Cron Job
 *
 * Runs daily to sweep expired temporary IP blocks and stale escalation windows
 * from the active IP activity state. Also clears expired IP range / country
 * block rules. Resets attempts for temporary locks whose duration has elapsed.
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
 * Class LoginDefenderExpiredBlocksCronJob
 *
 * Daily cron job that releases expired login defender blocks.
 *
 * @since 1.0.0
 */
class LoginDefenderExpiredBlocksCronJob extends AbstractCronJob {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_login_defender_cleanup_expired';
		$this->schedule_name    = 'wptw_login_defender_expired_blocks_schedule';
		$this->default_interval = 'Daily';

		parent::__construct();
	}

	/**
	 * Get feature settings.
	 *
	 * Expired block cleanup has no user-facing feature toggle — it is a
	 * maintenance job that must run so users are never permanently locked
	 * out after a temporary block duration has elapsed.
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
	 * Always enabled so temporary locks always expire on schedule.
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
		return esc_html__( 'Login Defender Expired Blocks Cleanup', 'tailwatch' );
	}

	/**
	 * Execute the expired blocks cleanup task.
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
			$controller->cleanup_expired_blocks();
		} catch ( \Throwable $e ) {
			Log::error(
				'Login Defender expired blocks cron job failed',
				array(
					'feature'   => 'login_defender_expired_blocks_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}