<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Smart SSL Cron Job
 *
 * Handles scheduled SSL certificate verification and expiry notifications.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Ssl\SslVerificationController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class SmartSslCronJob
 *
 * Cron job for Smart SSL feature:
 * - Verifies SSL certificate status
 * - Checks certificate expiry dates
 * - Sends notifications for expiring certificates
 *
 * Controller is lazy-loaded only when needed.
 *
 */
class SmartSslCronJob extends AbstractCronJob {

	/**
	 * SSL Verification Controller instance (lazy loaded).
	 *
	 * @var SslVerificationController|null
	 */
	private $ssl_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_verify_ssl';
		$this->schedule_name    = 'wptw_ssl_verification';
		$this->default_interval = '1 Day';

		parent::__construct();
	}

	/**
	 * Get SSL controller instance (lazy loading).
	 *
	 * Only creates controller when actually needed.
	 *
	 * @return SslVerificationController
	 */
	private function get_ssl_controller() {
		if ( null === $this->ssl_controller ) {
			$this->ssl_controller = new SslVerificationController();
		}
		return $this->ssl_controller;
	}

	/**
	 * Get feature settings.
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_ssl_controller()->get_features_options();
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'Smart SSL Verification', 'tailwatch' );
	}

	/**
	 * Execute the SSL verification cron job.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			/**
			 * Fires before SSL verification cron executes.
			 *
			 */
			do_action( 'wptw_before_ssl_cron_execute' );

			// Perform SSL verification (controller is lazy loaded here).
			$result = $this->get_ssl_controller()->wptw_verify_ssl_connection();

			/**
			 * Fires after SSL verification cron executes.
	 		 *
			 * @param mixed $result The result of SSL verification.
			 */
			do_action( 'wptw_after_ssl_cron_execute', $result );
		} catch ( \Throwable $e ) {
			// Log the error but don't re-throw to prevent cron from failing silently.
			Log::error(
				'SSL verification cron job failed',
				array(
					'feature'   => 'ssl_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}
