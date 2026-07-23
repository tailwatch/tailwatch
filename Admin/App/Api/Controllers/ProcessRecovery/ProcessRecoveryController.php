<?php
namespace Tailwatch\Admin\App\Api\Controllers\ProcessRecovery;

use Tailwatch\Admin\App\Api\Services\RecoveryMonitor;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process Recovery Controller — the watchdog for stuck background processes.
 *
 * Scans for backup / db_optimize / integrity / malware scan / restore processes
 * that have stopped updating their heartbeat, marks them stuck, and retries or
 * fails them. This is the last line of defence for the whole cron system.
 *
 * ------------------------------------------------------------------------
 * Why this cron is DELIBERATELY outside the CronJobs framework
 * ------------------------------------------------------------------------
 *
 * Every job under Admin/App/Api/Controllers/CronJobs/ ultimately relies on
 * WP-Cron being healthy: AbstractCronJob schedules via wp_schedule_event,
 * registers itself on admin_init via maybe_schedule(), etc. If the framework
 * itself breaks or a scheduled event gets corrupted, framework jobs silently
 * stop running.
 *
 * This controller is the recovery mechanism for exactly that scenario — it
 * must be able to run even when the framework is broken. Putting it inside
 * the framework it is supposed to rescue would create a circular dependency:
 * if the framework fails, the watchdog dies with it, and nothing can recover.
 *
 * Additional reasons it intentionally bypasses the framework:
 *
 * 1. **Eager scheduling.** Framework jobs only schedule themselves from
 *    admin_init. On a site with no admin visits, the initial schedule could
 *    be delayed for days. The watchdog must run on frontend-only sites too,
 *    so it schedules itself directly from the controller constructor.
 *
 * 2. **Tiered recovery.** The controller registers THREE independent recovery
 *    paths: the 3-minute background cron (Tier 1), an inline admin_init
 *    recovery on every admin page load (Tier 2), and an AJAX emergency
 *    trigger (Tier 3). The framework pattern assumes one scheduling path.
 *
 * 3. **Concurrency lock.** run_background_recovery() uses a transient-based
 *    lock to prevent overlap, because recovery operations write to the same
 *    tables that running processes are actively updating. Overlap would
 *    corrupt state. The framework does not provide this.
 *
 * 4. **No user-facing toggle.** Recovery monitoring must always run,
 *    regardless of which features are enabled. The framework is designed
 *    around user-toggleable features via is_enabled().
 *
 * The 'wptw_process_recovery_monitor' hook IS listed in
 * CronJobManager::get_all_cron_hooks() so its entry gets swept on plugin
 * uninstall along with the framework jobs.
 *
 */
class ProcessRecoveryController {

	/**
	 * @var RecoveryMonitor
	 */
	private $recoveryMonitor;

	public function __construct() {
		$this->recoveryMonitor = new RecoveryMonitor();

		$hook_controller = new HookControllers();

		// Register custom cron schedule for recovery monitoring (every 3 minutes).
		$hook_controller->add_filter_hook( 'cron_schedules', array( $this, 'add_recovery_cron_schedule' ) );

		// Schedule background recovery monitoring cron.
		$this->schedule_recovery_cron();

		// Hook for background recovery cron (runs every 3 minutes).
		$hook_controller->add_action_hook( 'wptw_process_recovery_monitor', array( $this, 'run_background_recovery' ) );

		// Hook into admin_init for immediate recovery monitoring (Tier 2).
		$hook_controller->add_action_hook( 'admin_init', array( $this, 'run_recovery_monitor' ) );

		// Weekly cleanup of old process records is handled by
		// ProcessMonitorCleanupCronJob in the CronJobs framework.

		// Register all known process types so per-process config (stuck_threshold, max_retries) is available.
		$this->register_all_processes();
	}

	public function run_recovery_monitor() {
		// Only run in admin area.
		if ( ! is_admin() ) {
			return;
		}

		// Only run if user can manage options (admin).
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check and recover stuck processes (throttled internally).
		$this->recoveryMonitor->check_and_recover();
	}

	public function add_recovery_cron_schedule( $schedules ) {
		// wptw_-prefixed name is the canonical one (PrefixAllGlobals). The bare
		// `every_3_minutes` key is retained as an alias for one release cycle
		// so pre-existing scheduled events continue to reschedule successfully;
		// remove the alias after that cycle.
		$schedules['wptw_every_3_minutes'] = array(
			'interval' => 180,
			'display'  => __( 'Every 3 Minutes', 'tailwatch' ),
		);
		$schedules['every_3_minutes'] = $schedules['wptw_every_3_minutes'];

		return $schedules;
	}

	private function schedule_recovery_cron() {
		// Check if WP Cron is enabled.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return; // Don't schedule if WP Cron is disabled.
		}

		// Check if already scheduled.
		if ( ! wp_next_scheduled( 'wptw_process_recovery_monitor' ) ) {
			wp_schedule_event( time(), 'wptw_every_3_minutes', 'wptw_process_recovery_monitor' );
		}
	}

	public function run_background_recovery() {
		$lock_key = 'wptw_recovery_running';
		if ( get_transient( $lock_key ) ) {
			return; // Another recovery check is already running.
		}

		set_transient( $lock_key, true, 120 );

		try {
			// Run recovery check (with per-process throttling).
			$data = $this->recoveryMonitor->check_and_recover();

			Log::info(
				'Background recovery check completed',
				array(
					'feature' => 'process_recovery',
					'action'  => 'background_recovery_completed',
					'result'  => $data,
				)
			);
		} finally {
			// Always release lock even if error occurs.
			delete_transient( $lock_key );
		}
	}

	/**
	 * Register all known process types with their per-process configs (stuck threshold, max retries).
	 * These configs are used by RecoveryMonitor::check_health() and ProcessManager::can_retry().
	 *
	 */
	private function register_all_processes() {
		// Backup: higher max_retries because it has many resumable stages.
		ProcessManager::register_process(
			array(
				'process_type'        => 'backup',
				'data_source'         => 'wp_tw_settings',
				'data_key'            => 'default_backup_scan',
				'data_option'         => 'scan_backp',
				'cancel_pause_key'    => 'default_backup_scan',
				'cancel_pause_option' => 'backup_cancel_pause',
				'stuck_threshold'     => 300,
				'max_retries'         => 10,
			)
		);
		ProcessManager::register_process(
			array(
				'process_type'    => 'db_optimize',
				'data_source'     => 'wp_tw_settings',
				'data_key'        => 'default_dboptimize_scan',
				'data_option'     => 'scan_dbtables',
				'stuck_threshold' => 300,
				'max_retries'     => 3,
			)
		);
		ProcessManager::register_process(
			array(
				'process_type'    => 'search_replace',
				'data_source'     => 'wp_tw_settings',
				'data_key'        => 'default_search_replace',
				'data_option'     => 'wptw_search_replace',
				'stuck_threshold' => 300,
				'max_retries'     => 3,
			)
		);
		ProcessManager::register_process(
			array(
				'process_type'    => 'broken_link_checker',
				'data_source'     => 'wp_tw_settings',
				'data_key'        => 'default_broken_link_checker',
				'data_option'     => 'broken_link_cancel_pause',
				'stuck_threshold' => 300,
				'max_retries'     => 3,
			)
		);
		ProcessManager::register_process(
			array(
				'process_type'    => 'files_integrity',
				'data_source'     => 'wp_tw_settings',
				'data_key'        => 'default_files_integrity_check',
				'data_option'     => 'files_integrity_progress',
				'stuck_threshold' => 300,
				'max_retries'     => 3,
			)
		);
		// Long-running processes: 10-minute stuck threshold.
		foreach ( array( 'malware_scan', 'malware_restore', 'migration', 'migration_backup', 'restore', 'baseline_update', 'migration_backup_upload' ) as $long_type ) {
			ProcessManager::register_process(
				array(
					'process_type'    => $long_type,
					'stuck_threshold' => 600,
					'max_retries'     => 3,
				)
			);
		}
		// Standard 5-minute threshold for remaining types.
		foreach ( array( 'verify_features', 'security_verify', 'settings_import', 'reset_all', 'recommended_features', 'backup_download' ) as $std_type ) {
			ProcessManager::register_process(
				array(
					'process_type'    => $std_type,
					'stuck_threshold' => 300,
					'max_retries'     => 3,
				)
			);
		}
	}
}
