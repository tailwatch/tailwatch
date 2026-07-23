<?php
/**
 * Hardening Audit Cron Job
 *
 * Handles the recurring hardening / security audit using the Jobs framework
 * pattern. When the recurring schedule fires, sweeps any stale in-progress
 * state from a prior run then triggers a fresh chunked audit. The worker
 * itself (`wptw_hardening_audit_scan`) self-reschedules until every check
 * has run — this job only kicks off the cycle, it does not iterate checks.
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
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

/**
 * Class HardeningAuditCronJob
 *
 */
class HardeningAuditCronJob extends AbstractCronJob {

	/**
	 * @var HardeningAuditController|null
	 */
	private $audit_controller = null;

	public function __construct() {
		$this->cron_hook_name   = 'wptw_hardening_audit_schedule_run';
		$this->schedule_name    = 'wptw_hardening_audit_schedule';
		$this->default_interval = 'Every 24 Hours';

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

	/**
	 * Read the configured interval from `field_2` (audit_interval) on the
	 * Hardening Audit feature option. Same shape DB Optimizer / Integrity
	 * use, just a different sub-field key.
	 *
	 * @return string
	 */
	protected function get_configured_interval() {
		$settings = $this->get_cached_settings();

		$interval = isset( $settings['field_1']['sub_options']['field_2']['options'] )
			? $settings['field_1']['sub_options']['field_2']['options']
			: $this->default_interval;

		if ( is_array( $interval ) ) {
			foreach ( $interval as $option ) {
				if ( isset( $option['selected'] ) && $option['selected'] ) {
					return isset( $option['value'] ) ? sanitize_text_field( $option['value'] ) : $this->default_interval;
				}
			}
			return $this->default_interval;
		}

		return is_string( $interval ) ? sanitize_text_field( $interval ) : $this->default_interval;
	}

	protected function get_schedule_display_name() {
		return esc_html__( 'Hardening Audit', 'tailwatch' );
	}

	public function execute() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Skip this scheduled cycle if a conflicting process is mid-flight.
		// WP-Cron will fire the next recurring occurrence normally; we do
		// NOT reschedule a one-shot retry so persistent conflicts cannot
		// cause retry storms.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'hardening_audit' );
		if ( null !== $blocked ) {
			Log::info(
				'Scheduled hardening audit skipped: a conflicting process is running',
				array(
					'feature'         => 'hardening_audit',
					'action'          => 'scheduled_hardening_audit_skipped',
					'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
				)
			);
			return;
		}

		/**
		 * Fires before the hardening audit cron executes.
		 *
		 */
		do_action( 'wptw_before_hardening_audit_cron_execute' );

		// Use the typed internal start (`wptw_run_hardening_audit`) instead of
		// the AJAX-facing entry (`wptw_start_hardening_audit`) which expects a
		// route-layer payload (string/array) rather than a literal scan_type.
		// Mirrors the DatabaseOptimizerCronJob → wptw_database_optimize_start
		// split.
		$controller = $this->get_audit_controller();
		$controller->wptw_remove_garbage_entries_audit();
		$controller->wptw_run_hardening_audit( 'automatically' );

		/**
		 * Fires after the hardening audit cron has been triggered.
		 *
		 */
		do_action( 'wptw_after_hardening_audit_cron_execute' );
	}
}