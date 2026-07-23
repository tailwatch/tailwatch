<?php
/**
 * License Verification Cron Job
 *
 * Periodically verifies the connected license against the Tailwatch API
 * to keep the local license status up to date.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class LicenseVerifyCronJob
 *
 * Cron job for License Verification:
 * - Runs every 12 hours (industry standard for license checks).
 * - Only fires when a license is actively connected.
 * - Syncs plan name, devices, and dates from the remote API.
 * - Kills the local connection if the remote API revokes the license.
 *
 * @since 1.0.0
 */
class LicenseVerifyCronJob extends AbstractCronJob {

	/**
	 * VerifyStatusController instance (lazy loaded).
	 *
	 * @var VerifyStatusController|null
	 */
	private $verify_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_license_verify_cron';
		$this->schedule_name    = 'wptw_license_verification';
		$this->default_interval = 'Every 12 Hours';

		parent::__construct();
	}

	/**
	 * Get VerifyStatusController instance (lazy loading).
	 *
	 * @return VerifyStatusController
	 */
	private function get_verify_controller() {
		if ( null === $this->verify_controller ) {
			$this->verify_controller = new VerifyStatusController();
		}
		return $this->verify_controller;
	}

	/**
	 * Get feature settings.
	 *
	 * License verification is not a user-toggleable feature setting.
	 * Returns an empty array; enabled state is determined by is_enabled() below.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty array (not driven by a feature option).
	 */
	protected function get_feature_settings() {
		return array();
	}

	/**
	 * Check if the cron job should run.
	 *
	 * Enabled whenever a license is actively connected — not controlled
	 * by a feature toggle in settings.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force_refresh Unused — always performs a fresh check.
	 *
	 * @return bool True if a license is connected, false otherwise.
	 */
	protected function is_enabled( $force_refresh = false ) {
		$status = $this->get_verify_controller()->get_plugin_activation_status();
		return ! empty( $status['extended_connected'] );
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'License Verification', 'tailwatch' );
	}

	/**
	 * Execute the license verification cron job.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function execute() {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			/**
			 * Fires before the license verification cron executes.
			 *
			 * @since 1.0.0
			 */
			do_action( 'wptw_before_license_verify_cron_execute' );

			// Cron is the periodic-refresh backbone — must always hit the API
			// to keep the cached verify-result transient fresh. Passing the
			// cached path (force = false) here would just return the cache
			// it itself is responsible for populating.
			$result = $this->get_verify_controller()->wptw_verify_license( true );

			/**
			 * Fires after the license verification cron executes.
			 *
			 * @since 1.0.0
			 *
			 * @param mixed $result The result of license verification.
			 */
			do_action( 'wptw_after_license_verify_cron_execute', $result );
		} catch ( \Throwable $e ) {
			Log::error(
				'License verification cron job failed',
				array(
					'feature'   => 'license_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}
