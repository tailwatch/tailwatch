<?php
/**
 * Hardening Audit Maintenance Cron Job
 *
 * Runs every 30 minutes (only when the Hardening Audit feature is enabled)
 * to prune completed audit reports older than the configured retention
 * window. Distinct from HardeningAuditCronJob which fires the audits
 * themselves — this job only does report cleanup, mirroring the
 * IntegrityEntryMaintenanceCronJob / IntegrityWatchCronJob split.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class HardeningAuditMaintenanceCronJob
 *
 */
class HardeningAuditMaintenanceCronJob extends AbstractCronJob {

	/**
	 * @var HardeningAuditController|null
	 */
	private $audit_controller = null;

	public function __construct() {
		$this->cron_hook_name   = 'wptw_hardening_audit_report_cleanup';
		$this->schedule_name    = 'wptw_hardening_audit_maintenance_schedule';
		$this->default_interval = 'Every 30 Minutes';

		parent::__construct();
	}

	/**
	 * @return HardeningAuditController
	 */
	private function get_audit_controller() {
		if ( null === $this->audit_controller ) {
			$this->audit_controller = new HardeningAuditController();
		}
		return $this->audit_controller;
	}

	protected function get_feature_settings() {
		return $this->get_audit_controller()->get_features_options();
	}

	protected function get_schedule_display_name() {
		return esc_html__( 'Hardening Audit Report Cleanup', 'tailwatch' );
	}

	public function execute() {
		try {
			$this->get_audit_controller()->wptw_prune_hardening_audit_reports();
		} catch ( \Throwable $e ) {
			Log::error(
				'Hardening audit report cleanup cron job failed',
				array(
					'feature'   => 'hardening_audit_report_cleanup_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}