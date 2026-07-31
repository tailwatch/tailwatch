<?php
/**
 * Integrity Entry Maintenance Cron Job
 *
 * Runs every 30 minutes (only when the Files Integrity Watch feature is
 * enabled) to trim the comparison log based on the user-configured
 * retention setting (default 6 hours). Keeps the comparison history JSON
 * file from growing indefinitely.
 *
 * Distinct from IntegrityWatchCronJob which runs the recurring integrity
 * scans themselves — this job only does log maintenance.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class IntegrityEntryMaintenanceCronJob
 *
 * Every-30-minutes cron job that trims the integrity comparison log.
 * Gated by the Files Integrity Watch feature toggle.
 *
 */
class IntegrityEntryMaintenanceCronJob extends AbstractCronJob {

	/**
	 * IntegrityWatchController instance (lazy loaded).
	 *
	 * @var IntegrityWatchController|null
	 */
	private $integrity_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'wptw_integrity_old_entry_cleanup';
		$this->schedule_name    = 'wptw_integrity_entry_maintenance_schedule';
		$this->default_interval = 'Every 30 Minutes';

		parent::__construct();
	}

	/**
	 * Get IntegrityWatchController instance (lazy loading).
	 *
	 * @return IntegrityWatchController
	 */
	private function get_integrity_controller() {
		if ( null === $this->integrity_controller ) {
			$this->integrity_controller = new IntegrityWatchController();
		}
		return $this->integrity_controller;
	}

	/**
	 * Get feature settings from the Files Integrity Watch feature.
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_integrity_controller()->get_features_options();
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return 'Integrity Entry Maintenance';
	}

	/**
	 * Execute the integrity entry maintenance task.
	 *
	 * Delegates to the controller's existing wptw_maintain_integrity_entry()
	 * method which trims the comparison log based on the retention setting.
	 *
	 * @return void
	 */
	public function execute() {
		try {
			$this->get_integrity_controller()->wptw_maintain_integrity_entry();
		} catch ( \Throwable $e ) {
			Log::error(
				'Integrity entry maintenance cron job failed',
				array(
					'feature'   => 'integrity_entry_maintenance_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}