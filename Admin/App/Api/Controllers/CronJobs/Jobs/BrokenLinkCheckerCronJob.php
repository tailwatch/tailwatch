<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Broken Link Checker Cron Job
 *
 * Handles scheduled recurring broken link checks using the Jobs pattern.
 * When the schedule fires, it starts one scan run (automatically).
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 *
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkChecker;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class BrokenLinkCheckerCronJob
 *
 * Cron job for Broken Link Checker feature:
 * - Schedules recurring scans based on configured interval (1 Day, 3 Days, Weekly, 15 Days, Monthly).
 * - On execute, starts the broken link checker with scan_type 'automatically'.
 *
 */
class BrokenLinkCheckerCronJob extends AbstractCronJob {

	/**
	 * Broken Link Checker controller instance (lazy loaded).
	 *
	 * @var BrokenLinkChecker|null
	 */
	private $broken_link_controller = null;

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cron_hook_name   = 'tailwatch_broken_link_checker_schedule';
		$this->schedule_name    = 'tailwatch_broken_link_checker_interval';
		$this->default_interval = '15 Days';

		parent::__construct();
	}

	/**
	 * Get Broken Link Checker controller instance (lazy loading).
	 *
	 * @return BrokenLinkChecker
	 */
	private function get_broken_link_controller() {
		if ( null === $this->broken_link_controller ) {
			$this->broken_link_controller = new BrokenLinkChecker();
		}
		return $this->broken_link_controller;
	}

	/**
	 * Get feature settings from broken link checker.
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_broken_link_controller()->tailwatch_broken_link_settings();
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return 'Broken Link Checker';
	}

	/**
	 * Execute the broken link checker cron job.
	 *
	 * Starts one scan run with scan_type 'automatically'.
	 *
	 * @return void
	 */
	public function execute() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Skip this scheduled cycle if a conflicting process is currently
		// running. Today broken_link_checker has an empty cannot_start_while
		// (it's a lightweight crawler), so this gate is a no-op — but the
		// call site is in place for future conflicts and consistency with
		// the other scheduled jobs.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'broken_link_checker' );
		if ( null !== $blocked ) {
			Log::info(
				'Scheduled broken-link check skipped: a conflicting process is running',
				array(
					'feature'         => 'broken_link_checker',
					'action'          => 'scheduled_broken_link_skipped',
					'running_process' => isset( $blocked['data']['running_process'] ) ? $blocked['data']['running_process'] : null,
				)
			);
			return;
		}

		/**
		 * Fires before broken link checker cron executes.
		 *
		 */
		do_action( 'tailwatch_before_broken_link_checker_cron_execute' );

		$this->get_broken_link_controller()->tailwatch_insert_broken_link_data( 'automatically' );

		/**
		 * Fires after broken link checker cron was triggered.
		 *
		 */
		do_action( 'tailwatch_after_broken_link_checker_cron_execute' );
	}
}
