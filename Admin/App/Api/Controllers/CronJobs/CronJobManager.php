<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * Cron Job Manager
 *
 * Central management class for all plugin cron jobs.
 * Handles registration, initialization, and cleanup of cron jobs.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\SmartSslCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\LicenseVerifyCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\BackupCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\DatabaseOptimizerCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\IntegrityWatchCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\BrokenLinkCheckerCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\ProcessMonitorCleanupCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\BackupCleanupCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\IntegrityEntryMaintenanceCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\HardeningAuditCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\HardeningAuditMaintenanceCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\LoginDefenderExpiredBlocksCronJob;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs\LoginDefenderLogsCleanupCronJob;

/**
 * Class CronJobManager
 *
 * Singleton class that manages all cron jobs in the plugin.
 *
 * Features:
 * - Centralizes cron job registration
 * - Provides easy access to individual cron jobs
 * - Handles plugin activation/deactivation cleanup
 * - Extensible architecture for adding new cron jobs
 *
 */
class CronJobManager {

	/**
	 * Singleton instance.
	 *
	 * @var CronJobManager|null
	 */
	private static $instance = null;

	/**
	 * Registered cron job instances.
	 *
	 * @var array<string, AbstractCronJob>
	 */
	private $cron_jobs = array();

	/**
	 * Get singleton instance.
	 *
	 * @return CronJobManager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation.
	 *
	 */
	private function __construct() {
		$this->register_cron_jobs();
	}

	/**
	 * Register all cron jobs.
	 *
	 * @return void
	 */
	private function register_cron_jobs() {
		// Smart SSL Cron Job.
		$this->cron_jobs['smart_ssl'] = new SmartSslCronJob();

		// Backup Cron Job (recurring scheduled backups).
		$this->cron_jobs['backup'] = new BackupCronJob();

		// Database Optimizer Cron Job (recurring scheduled optimization).
		$this->cron_jobs['database_optimizer'] = new DatabaseOptimizerCronJob();

		// Files Integrity Cron Job (recurring scheduled scans).
		$this->cron_jobs['files_integrity'] = new IntegrityWatchCronJob();

		// Broken Link Checker Cron Job (recurring scheduled scans).
		$this->cron_jobs['broken_link_checker'] = new BrokenLinkCheckerCronJob();

		// License Verification Cron Job.
		$this->cron_jobs['license_verify'] = new LicenseVerifyCronJob();

		// Process Monitor Cleanup Cron Job (weekly — delete process records older than 7 days).
		$this->cron_jobs['process_monitor_cleanup'] = new ProcessMonitorCleanupCronJob();

		// Backup Cleanup Cron Job (every 30 minutes — purge incomplete / stuck backups).
		$this->cron_jobs['backup_cleanup'] = new BackupCleanupCronJob();

		// Integrity Entry Maintenance Cron Job (every 30 minutes — trim comparison log, feature-gated).
		$this->cron_jobs['integrity_entry_maintenance'] = new IntegrityEntryMaintenanceCronJob();

		// Hardening Audit Cron Job (recurring scheduled audits).
		$this->cron_jobs['hardening_audit'] = new HardeningAuditCronJob();

		// Hardening Audit Maintenance Cron Job (every 30 minutes — prune old audit reports per retention).
		$this->cron_jobs['hardening_audit_maintenance'] = new HardeningAuditMaintenanceCronJob();

		// Login Defender — expire elapsed temporary IP/user blocks (daily maintenance).
		$this->cron_jobs['login_defender_expired_blocks'] = new LoginDefenderExpiredBlocksCronJob();

		// Login Defender — purge old login-attempt activity logs (cleanup maintenance).
		$this->cron_jobs['login_defender_logs_cleanup'] = new LoginDefenderLogsCleanupCronJob();

		/**
		 * Filter to add custom cron jobs.
		 *
		 * Allows developers to add their own cron jobs to the plugin.
	     *
		 * @param array $cron_jobs Array of registered cron jobs.
		 */
		$this->cron_jobs = apply_filters( 'wptw_register_cron_jobs', $this->cron_jobs );
	}

	/**
	 * Use singleton instance instead.
	 *
	 * @return void
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'tailwatch' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @return void
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'tailwatch' ), '1.0.0' );
	}

	/**
	 * Get a specific cron job by key.
	 *
	 * @param string $key Cron job key (e.g., 'smart_ssl').
	 *
	 * @return AbstractCronJob|null Cron job instance or null if not found.
	 */
	public function get_cron_job( $key ) {
		return isset( $this->cron_jobs[ $key ] ) ? $this->cron_jobs[ $key ] : null;
	}

	/**
	 * Get all registered cron jobs.
	 *
	 * @return array<string, AbstractCronJob>
	 */
	public function get_all_cron_jobs() {
		return $this->cron_jobs;
	}

	/**
	 * Get status of all cron jobs.
	 *
	 * @return array Array of cron job statuses.
	 */
	public function get_all_cron_status() {
		$status = array();

		foreach ( $this->cron_jobs as $key => $cron_job ) {
			$next_run = $cron_job->get_next_run();

			$status[ $key ] = array(
				'scheduled'    => $cron_job->is_scheduled(),
				'next_run'     => $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null,
				'next_run_raw' => $next_run,
			);
		}

		return $status;
	}

	/**
	 * Schedule all enabled cron jobs.
	 *
	 * Called on plugin activation. Each job is isolated so that one that throws
	 * while reading its (possibly not-yet-initialized) settings cannot abort
	 * activation or prevent the remaining jobs from being scheduled.
	 *
	 * @param bool $force Passed through to each job's maybe_schedule() so that
	 *                    scheduling runs even outside an admin/cron request.
	 * @return void
	 */
	public function schedule_all( $force = false ) {
		foreach ( $this->cron_jobs as $cron_job ) {
			try {
				$cron_job->maybe_schedule( $force );
			} catch ( \Throwable $e ) {
				// A single misbehaving job must never abort activation or block the
				// remaining jobs from scheduling; skip it and continue with the rest.
				continue;
			}
		}

		/**
		 * Fires after all cron jobs have been scheduled.
		 *
		 */
		do_action( 'wptw_all_crons_scheduled' );
	}

	/**
	 * Unschedule all cron jobs.
	 *
	 * Typically called on plugin deactivation.
	 *
	 * @return void
	 */
	public function unschedule_all() {
		foreach ( $this->cron_jobs as $cron_job ) {
			$cron_job->unschedule();
		}

		/**
		 * Fires after all cron jobs have been unscheduled.
		 *
		 */
		do_action( 'wptw_all_crons_unscheduled' );
	}

	/**
	 * Run a specific cron job immediately.
	 *
	 * @param string $key Cron job key.
	 *
	 * @return mixed|false Result of cron execution or false if not found.
	 */
	public function run_now( $key ) {
		$cron_job = $this->get_cron_job( $key );

		if ( null === $cron_job ) {
			return false;
		}

		return $cron_job->run_now();
	}

	/**
	 * Sync a specific cron job's schedule.
	 *
	 * Call this when a feature is enabled/disabled or interval changes.
	 *
	 * @param string $key Cron job key (e.g., 'smart_ssl').
	 *
	 * @return bool True if sync was successful, false otherwise.
	 */
	public function sync_cron_job( $key ) {
		$cron_job = $this->get_cron_job( $key );

		if ( null === $cron_job ) {
			return false;
		}

		return $cron_job->sync_schedule();
	}

	/**
	 * Sync all cron job schedules.
	 *
	 * Call this when multiple features may have changed.
	 *
	 * @return array Array of sync results keyed by cron job key.
	 */
	public function sync_all() {
		$results = array();

		foreach ( $this->cron_jobs as $key => $cron_job ) {
			$results[ $key ] = $cron_job->sync_schedule();
		}

		/**
		 * Fires after all cron jobs have been synced.
	 *
		 * @param array $results Sync results for each cron job.
		 */
		do_action( 'wptw_all_crons_synced', $results );

		return $results;
	}

	/**
	 * Get all registered cron hook names.
	 *
	 * Useful for cleanup during plugin uninstallation. Pro / extension plugins
	 * can append their own cron hook names via the 'wptw_register_cron_hooks'
	 * filter so their scheduled events get swept from wp_options on uninstall
	 * without needing to edit this file.
	 *
	 * @return array Array of cron hook names.
	 */
	public static function get_all_cron_hooks() {
		$hooks = array(
			// Smart SSL.
			'wptw_verify_ssl',
			'wptw_trigger_ssl_expiry_notice',

			// Database Optimizer (recurring + chained steps).
			'wptw_start_database_optimization',
			'wptw_auto_db_optimize',

			// Visit Features.
			'wptw_activate_recommended_features',
			'wptw_inserting_rows_into_database',

			// Backup (recurring schedule + chained single events).
			'wptw_backup_schedule_run',
			'wptw_backup_daily_scan',

			// Files Integrity (recurring schedule + chained single events).
			'wptw_files_integrity_schedule_run',
			'wptw_files_integrity_scan',
			'wptw_integrity_old_entry_cleanup',
			'file_monitoring_cron_hook', // Legacy hook name.

			// Hardening Audit (recurring schedule + chained single events + retention cleanup).
			'wptw_hardening_audit_schedule_run',
			'wptw_hardening_audit_scan',
			'wptw_hardening_audit_report_cleanup',

			// Broken Link Checker (recurring schedule + chained single events).
			'wptw_broken_link_checker_schedule',
			'wptw_broken_link_checker',

			// License Verification.
			'wptw_license_verify_cron',

			// Process Monitor Cleanup (weekly maintenance).
			'wptw_process_monitor_cleanup',

			// Backup Cleanup (every 30 minutes maintenance).
			'wptw_backup_cleanup_cron',

			// Process Recovery Watchdog (3-minute watchdog, scheduled outside the framework
			// by ProcessRecoveryController — see its class docblock for rationale).
			'wptw_process_recovery_monitor',

			// Login Defender maintenance.
			'wptw_login_defender_cleanup_expired',
			'wptw_login_defender_cleanup',

			// Legacy unprefixed names — keep in the uninstall sweep for one release cycle.
			'login_defender_cleanup_expired',
			'login_defender_cleanup',
		);

		/**
		 * Filter the list of cron hook names swept on plugin uninstall.
		 *
		 * Pro / extension plugins should append their own cron hook names via
		 * this filter so their scheduled events are cleaned from wp_options
		 * when the free plugin is uninstalled. Mirrors the 'wptw_register_cron_jobs'
		 * pattern used to extend the job registry.
	 	 *
		 * @param array $hooks Cron hook names to clear on uninstall.
		 */
		return apply_filters( 'wptw_register_cron_hooks', $hooks );
	}

	/**
	 * Clear all plugin cron jobs from WordPress.
	 *
	 * Used during plugin uninstallation to ensure clean removal.
	 *
	 * @return int Number of cron events cleared.
	 */
	public static function clear_all_cron_events() {
		$hooks_cleared = 0;
		$hooks         = self::get_all_cron_hooks();

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				++$hooks_cleared;
				$timestamp = wp_next_scheduled( $hook );
			}
		}

		/**
		 * Fires after all cron events have been cleared.
	     *
		 * @param int $hooks_cleared Number of cron events that were cleared.
		 */
		do_action( 'wptw_all_cron_events_cleared', $hooks_cleared );

		return $hooks_cleared;
	}
}
