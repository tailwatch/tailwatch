<?php
/**
 * Tailwatch - Restore Controller
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Restore
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Controllers\Restore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;
use Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreDatabase;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreValidator;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;
use Tailwatch\Admin\App\Api\Services\Cron\CronKick;
use Tailwatch\Admin\App\Api\Services\Cron\CronHealthService;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\ProcessStartClaim;
use Tailwatch\Admin\App\Api\Services\ProcessStatusService;
use Tailwatch\Admin\App\Api\Services\TickBudget;
use Tailwatch\Admin\App\Api\Services\Restore\MaintenanceMode;
use Tailwatch\Admin\App\Api\Services\Restore\PostRestoreGuard;
use Tailwatch\Admin\App\Api\Services\Restore\RestoreOrchestrator;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Traits\ContextAuthorizationTrait;
use Tailwatch\Admin\App\Api\Traits\FeatureEnableTrait;

/**
 * Drives a restore: owns the scheduling and the persistence the orchestrator does not.
 *
 * RestoreOrchestrator is deliberately state-in/state-out with no knowledge of cron or the
 * database. Everything that makes a restore a running job rather than a pure function
 * lives here — the worker lock, the state row, the cron chain, and the entry points a
 * user or the dashboard actually calls.
 *
 * This is what creates ProcessGuard rows of type `restore`, and those rows are what make
 * the mutual exclusion against backup, migration and the malware scanner work.
 */
class RestoreController {

	use ContextAuthorizationTrait;
	use FeatureEnableTrait;

	/**
	 * Cron hook that advances a running restore.
	 */
	const CRON_HOOK = 'tailwatch_restore_process';

	/**
	 * Where the running commentary for a restore is written.
	 *
	 * A restore takes minutes, closes the site while it runs, and replaces everything.
	 * A progress bar answers "how far"; it does not answer "what is it doing" or "what
	 * did it decide" — and those are the questions an operator watching their site being
	 * overwritten actually has. Every other long-running feature here has a live console
	 * for exactly this reason, and restore is the most destructive of them.
	 *
	 * One file per restore, named for the backup, so a later run never reads an earlier
	 * one's tail.
	 */
	const LOG_DIRECTORY = TAILWATCH_LOGS_DIRECTORY . '/restore-logs';

	/**
	 * The feature row that decides whether this site may be overwritten at all.
	 */
	const FEATURE_KEY    = 'default_feature_settings';
	const FEATURE_OPTION = 'default_site_migration';

	/**
	 * Methods that keep working when restoring is switched off.
	 *
	 * The switch governs whether a restore may START, not whether one already running
	 * can be watched or finished. Gating these too would mean turning the feature off
	 * mid-restore hid a job that was still overwriting the site — turning a safety
	 * switch into a way to lose sight of it.
	 *
	 * @var string[]
	 */
	protected $tailwatch_feature_check_exemptions = array(
		'tailwatch_restore_status',
		'tailwatch_restore_logs',
		'tailwatch_resume_restore_if_stalled',
	);

	/**
	 * The running commentary, from wherever the caller had got to.
	 *
	 * Its own reader rather than the backup's: that one expects the backup's progress
	 * shape, and bending a restore into it to reuse forty lines would leave two features
	 * sharing a contract that fits neither.
	 *
	 * @param string|array $post_data Payload; expects `lastLogIndex`.
	 * @return array
	 */
	public function tailwatch_restore_logs( $post_data ) {
		if ( ! $this->is_authorized_request() ) {
			return $this->unauthorized_response();
		}

		$payload = is_string( $post_data ) ? json_decode( $post_data, true ) : (array) $post_data;
		$from    = isset( $payload['lastLogIndex'] ) ? max( 0, (int) $payload['lastLogIndex'] ) : 0;

		$state  = $this->read_state();
		$phase  = isset( $state['phase'] ) ? (string) $state['phase'] : 'idle';
		$folder = isset( $state['folder_date'] ) ? (string) $state['folder_date'] : '';

		$path = self::log_path( $folder );
		$all  = array();

		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- the plugin's own log file; checked immediately.
			$raw = @file_get_contents( $path );
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_array( $decoded['message'] ) ) {
					$all = $decoded['message'];
				}
			}
		}

		// Only what the caller has not seen. A restore replaces the site under the
		// browser, so a poll that missed a tick must not replay the whole log.
		$new = $from < count( $all ) ? array_slice( $all, $from ) : array();

		return array(
			'code'           => 200,
			'new_logs'       => array_values( array_map( 'strval', $new ) ),
			'last_log_index' => count( $all ),
			'phase'          => $phase,
			'is_completed'   => in_array( $phase, array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ),
		);
	}

	/**
	 * The commentary file for one restore.
	 *
	 * @param string $folder_date Backup folder.
	 * @return string
	 */
	public static function log_path( $folder_date ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $folder_date );

		return self::LOG_DIRECTORY . '/restore_' . ( '' === $safe ? 'current' : $safe ) . '.json';
	}

	/**
	 * Begin a fresh record for a new restore of this backup.
	 *
	 * The log is named for the BACKUP, not for the run, so restoring the same backup
	 * twice — which is exactly what happens after putting the previous database back and
	 * trying again — would append to the first run's file. The reader starts at index zero,
	 * so the panel would replay the whole previous restore, ending on "Restore complete. The
	 * site is open again." over a restore that has only just started checking the backup.
	 *
	 * @param string $folder_date Backup folder being restored.
	 * @return void
	 */
	private static function reset_log( $folder_date ) {
		if ( ! is_dir( self::LOG_DIRECTORY ) ) {
			wp_mkdir_p( self::LOG_DIRECTORY );
			SecureDirectoryService::ensure( self::LOG_DIRECTORY, true );
		}

		$fs = FilesystemService::get_filesystem();
		if ( $fs ) {
			$fs->put_contents(
				self::log_path( $folder_date ),
				wp_json_encode( array( 'message' => array() ) ),
				FS_CHMOD_FILE
			);
		}
	}

	/**
	 * Re-take the worker lock after a rename replaced the table it lives in.
	 *
	 * Set rather than cleared, because the caller is the worker itself mid-tick: dropping
	 * the lock outright during the cutover would let a second scheduled tick in at the
	 * one moment the site can least afford it. The shutdown release registered when this
	 * worker first acquired the lock still applies, so it is let go at the end of the
	 * request as usual.
	 *
	 * @return void
	 */
	public static function reassert_worker_lock() {
		delete_transient( self::LOCK_TRANSIENT );
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
	}

	/**
	 * Say what is happening, for the operator watching.
	 *
	 * Deliberately separate from Log::, which records events for later diagnosis. This is
	 * a running narration read live and thrown away with the restore.
	 *
	 * @param array  $state   Restore state (for the folder name).
	 * @param string $message What is happening.
	 * @param string $level   INFO, WARNING or ERROR.
	 * @return void
	 */
	public static function narrate( array $state, $message, $level = 'INFO' ) {
		$folder = isset( $state['folder_date'] ) ? (string) $state['folder_date'] : '';
		$path   = self::log_path( $folder );

		if ( ! is_dir( self::LOG_DIRECTORY ) ) {
			wp_mkdir_p( self::LOG_DIRECTORY );
			SecureDirectoryService::ensure( self::LOG_DIRECTORY, true );
		}

		( new LiveLogsController() )->update_live_logs_records( $message, $path, $level );
	}

	/**
	 * Whether this site may be restored onto.
	 *
	 * @return array{parent_enable:bool,feature_enable:bool}
	 */
	protected function tailwatch_get_feature_status() {
		$options = ( new OptionsController() )->get_features_options( self::FEATURE_KEY, self::FEATURE_OPTION, true );

		if ( empty( $options ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => true === ( $options['field_1']['options']['option']['selected'] ?? false ),
		);
	}

	/**
	 * Where restore state lives, mirroring the backup process's row layout so the
	 * recovery monitor can read it the same way.
	 */
	const STATE_KEY    = 'default_restore';
	const STATE_OPTION = 'restore_state';

	/**
	 * Single-worker lock, and how long a crashed worker holds it.
	 */
	const LOCK_TRANSIENT = 'tailwatch_restore_worker_lock';
	const LOCK_TTL       = 600;

	/**
	 * Preferred seconds of work per tick, before the host's limit is applied.
	 */
	const TICK_BUDGET = 25;

	/**
	 * @var ProcessManager
	 */
	private $process_manager;

	public function __construct() {
		$this->process_manager = new ProcessManager();
		$this->register_process_monitoring();

		$hooks = new HookControllers();
		$hooks->add_action_hook( self::CRON_HOOK, array( $this, 'tailwatch_run_restore_cron' ) );

		// Without this listener, a restore that exhausts its retries would leave the state
		// row frozen mid-phase and the site behind the maintenance gate until the generated
		// file's own mtime self-heal expired.
		$hooks->add_action_hook( 'tailwatch_recovery_process_failed', array( $this, 'tailwatch_on_recovery_failed' ) );
	}

	/**
	 * Declare the process so ProcessGuard and the recovery monitor can see it.
	 *
	 * @return void
	 */
	private function register_process_monitoring() {
		ProcessManager::register_process(
			array(
				'process_type'       => 'restore',
				'cron_hooks'         => array( self::CRON_HOOK ),
				'data_source'        => 'wp_tw_settings',
				'data_key'           => self::STATE_KEY,
				'data_option'        => self::STATE_OPTION,
				// Deliberately matching ProcessRecoveryController's own registration for
				// `restore`. register_process() merges with later keys winning, and that
				// controller is constructed after this one, so declaring anything else
				// here would be dead code that reads as if it were in force.
				'stuck_threshold'    => 600,
				'max_retries'        => 3,
				// Restore rewrites the same files and tables these produce or consume.
				// Without this list the exclusion is ONE-WAY: other processes refuse to
				// start while a restore runs, but a restore would happily start in the
				// middle of a backup, malware scan or search-replace and overwrite what
				// they were writing.
				'cannot_start_while' => array(
					'backup',
					'backup_download',
					'db_optimize',
					'files_integrity',
					'baseline_update',
					'search_replace',
					'malware_scan',
					'malware_restore',
					'migration',
					'settings_import',
					'reset_all',
				),
				// Its own feature included. A restore that had its "Allow Restoring" switched
				// off mid-run would be finishing an operation the site no longer permits, and
				// the switch is read again on the next tick. A stuck restore stops blocking
				// once it passes its stuck_threshold, so this cannot lock anyone out.
				'locks_features'     => array( 'default_site_migration', 'default_backup_enable', 'default_database_optimizer' ),
			)
		);
	}

	/**
	 * Begin a restore.
	 *
	 * @param array $post_data Request payload; expects `folderDate`.
	 * @return array Response with code and message.
	 */
	public function tailwatch_start_restore( $post_data ) {
		// A restore overwrites files under ABSPATH and renames tables across the whole
		// install, so on multisite it is a network-level act — `manage_options` is held
		// by every sub-site administrator. Matches the guard already used by the less
		// destructive SearchReplaceController.
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return $this->unauthorized_response();
		}

		$payload     = $this->decode_payload( $post_data );
		$folder_date = isset( $payload['folderDate'] ) ? sanitize_text_field( wp_unslash( $payload['folderDate'] ) ) : '';
		if ( '' === $folder_date ) {
			return array(
				'code'    => 400,
				'message' => __( 'Select a backup to restore from.', 'tailwatch' ),
			);
		}

		// Absent or unrecognised means the whole backup. A scope that arrives mangled must
		// never quietly narrow what an operator asked to have put back.
		$scope = isset( $payload['scope'] ) ? sanitize_key( wp_unslash( $payload['scope'] ) ) : RestoreOrchestrator::SCOPE_FULL;
		if ( ! in_array( $scope, RestoreOrchestrator::scopes(), true ) ) {
			$scope = RestoreOrchestrator::SCOPE_FULL;
		}

		return $this->begin_restore( $folder_date, $scope );
	}

	/**
	 * Whether this site offers any scope narrower than the whole backup, so the screen
	 * knows whether there is a choice to present.
	 *
	 * @return bool
	 */
	private function offers_partial_restore() {
		return count( RestoreOrchestrator::scopes() ) > 1;
	}

	/**
	 * Start a restore for a backup that is already on this site.
	 *
	 * Split out from the route so a migration can finish the job it started. A migration
	 * is one act — move this site onto that one — and should end in a working site in a
	 * single run rather than landing an archive and waiting to be told to apply it. The
	 * transfer half writes only into the backup tree; this is the half that replaces the site.
	 *
	 * Deliberately WITHOUT a capability check. The two callers arrive differently: the
	 * route checks the capability of the person pressing the button, while the handoff
	 * runs on cron with no current user, on the authority of the migration that person
	 * already confirmed. Anything reaching this method has been authorised by its caller.
	 *
	 * @param string $folder_date Backup folder to restore from.
	 * @param string $scope       How much of the backup to put back.
	 * @return array Response with code and message.
	 */
	public function begin_restore( $folder_date, $scope = RestoreOrchestrator::SCOPE_FULL ) {
		$folder_date = sanitize_text_field( (string) $folder_date );
		if ( '' === $folder_date ) {
			return array(
				'code'    => 400,
				'message' => __( 'Select a backup to restore from.', 'tailwatch' ),
			);
		}

		// Mutual exclusion first: a restore that starts while a backup is writing the
		// same tree would have each overwriting the other's work.
		$blocked = ( new ProcessGuard() )->ensure_can_start_process( 'restore' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Validate before creating any state, so a refusal leaves nothing behind and the
		// caller gets the reason immediately rather than by polling. Chain-aware, so an
		// increment whose history is incomplete is refused here with the real reason rather
		// than accepted and then failed by the verify phase.
		$report = RestoreValidator::validate_chain( $folder_date );

		// A narrower scope may leave the files or the database out, so these are checked at
		// verify instead, once that is known.
		if ( ! empty( $report['ok'] ) && RestoreOrchestrator::SCOPE_FULL === $scope ) {
			$report['errors'] = array_merge(
				RestoreOrchestrator::layout_errors( $report, $report['archives'] ),
				RestoreOrchestrator::prefix_errors( $report )
			);
			$report['ok']     = empty( $report['errors'] );
		}

		if ( empty( $report['ok'] ) ) {
			// Where it looked, not just what it did not find. A refusal naming only the
			// file leaves the other half of the question open, and that is the question
			// when a set is present to one process and absent to another — which is what a
			// path resolving differently between the web server and the command line looks
			// like from the outside. Logged rather than shown: an absolute server path
			// belongs in a log, not in a message on screen.
			Log::warning(
				'A restore was refused.',
				array(
					'feature'   => 'restore',
					'action'    => 'restore_refused',
					'title'     => 'Restore refused',
					'meta_data' => array(
						'feature'   => 'Restore & Migration',
						'event'     => 'Backup refused',
						'backup'    => $folder_date,
						'directory' => TAILWATCH_BACKUP_DIR . '/files/' . $folder_date,
						'reasons'   => $report['errors'],
					),
				)
			);

			return array(
				'code'     => 422,
				'message'  => __( 'This backup cannot be restored.', 'tailwatch' ),
				'errors'   => $report['errors'],
				'warnings' => $report['warnings'],
			);
		}

		// Asked again under the claim: reading the archives above can take seconds, and a
		// backup or migration started meanwhile by another request has its process running by now.
		$start_claim = ( new ProcessGuard() )->claim_start( 'restore' );
		if ( is_array( $start_claim ) ) {
			return $start_claim;
		}

		try {
			// A new run is a new record. Anything above this belongs to a restore of this same
			// backup that is already over.
			self::reset_log( $folder_date );

			$state = RestoreOrchestrator::begin( $folder_date, $scope );
			$this->write_state( $state );

			$process_id = $this->process_manager->get_or_create_process(
				'restore',
				self::CRON_HOOK,
				array( 'folder_date' => $folder_date )
			);
			$state['process_id'] = $process_id;
			$this->write_state( $state );
		} finally {
			ProcessStartClaim::release( $start_claim );
		}

		Log::info(
			'Site restore started.',
			array(
				'feature'   => 'restore',
				'action'    => 'restore_started',
				'title'     => 'Restore started',
				'meta_data' => array(
					'feature' => 'Backup Vault',
					'event'   => 'Restore started',
					'backup'  => $folder_date,
				),
			)
		);

		wp_schedule_single_event( time(), self::CRON_HOOK );

		// CronKick sends nothing until a loopback has been shown to work, and that result
		// lasts an hour; established here, this kick and every tick's can actually fire.
		( new CronHealthService() )->check();
		CronKick::accelerate();

		return array(
			'code'     => 200,
			'message'  => __( 'Restore started.', 'tailwatch' ),
			'warnings' => $report['warnings'],
		);
	}

	/**
	 * Current restore progress.
	 *
	 * @return array
	 */
	public function tailwatch_restore_status() {
		if ( ! $this->is_authorized_request() ) {
			return $this->unauthorized_response();
		}

		// Reported rather than left to be discovered by pressing the button: a screen that
		// answers "not enabled" only after the operator has chosen a backup and confirmed
		// an overwrite has told them nothing about where to go and sounds like a fault.
		//
		// Named as every other module names it. The dispatcher already returns this pair
		// when it REFUSES a call; sending it here as well means the screen can say so
		// before anyone presses anything, without inventing a second vocabulary for it.
		$feature = $this->tailwatch_get_feature_status();

		$state = $this->read_state();
		if ( empty( $state ) ) {
			// A site that has never restored is offered the choice too, so it is said here as well.
			return array(
				'code'            => 200,
				'phase'           => 'idle',
				'feature_enable'  => $feature['feature_enable'],
				'parent_enable'   => $feature['parent_enable'],
				'partial_allowed' => $this->offers_partial_restore(),
			);
		}

		return array(
			'code'           => 200,
			'feature_enable' => $feature['feature_enable'],
			'parent_enable'  => $feature['parent_enable'],
			'phase'          => isset( $state['phase'] ) ? $state['phase'] : 'idle',
			'folder_date' => isset( $state['folder_date'] ) ? $state['folder_date'] : '',
			'destructive' => ! empty( $state['destructive'] ),
			'errors'      => isset( $state['errors'] ) ? $state['errors'] : array(),
			'warnings'    => isset( $state['warnings'] ) ? $state['warnings'] : array(),
			// What this restore was asked to put back, so a panel watching one can say so
			// rather than implying the whole backup went on.
			'scope'          => isset( $state['scope'] ) ? $state['scope'] : RestoreOrchestrator::SCOPE_FULL,
			// Reported on every poll, the way feature_enable is, so the screen can offer the
			// choice or not rather than letting someone pick it and be refused afterwards.
			'partial_allowed' => $this->offers_partial_restore(),
			// One entry per tick: when it started, how long it waited and ran, and why it ended.
			'ticks'           => isset( $state['ticks'] ) ? array_values( (array) $state['ticks'] ) : array(),
		);
	}

	/**
	 * Close out a restore that recovery has permanently given up on.
	 *
	 * Fired by RecoveryService when a process exhausts its retries. Without it a wedged
	 * restore would stay 'in progress' forever: the progress panel spinning, the site
	 * closed to visitors, and nothing saying why.
	 *
	 * A restore that dies after the cutover is NOT rolled back here. The files are the
	 * new ones and the database is the new one; putting the database back would turn a
	 * site that is merely unfinished into one that is genuinely mismatched. Reversing it
	 * is the operator's decision, and the retained generation is still there for it.
	 *
	 * @param array $process_data The failed process row (must carry process_type).
	 * @return void
	 */
	public function tailwatch_on_recovery_failed( $process_data ) {
		if ( empty( $process_data['process_type'] ) || 'restore' !== $process_data['process_type'] ) {
			return;
		}

		$state = $this->read_state();
		$phase = isset( $state['phase'] ) ? $state['phase'] : '';

		// Never override a run that already reached an outcome of its own.
		if ( '' === $phase
			|| in_array( $phase, array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ) ) {
			// Even so: a stale gate outlives the restore that engaged it, and this is the
			// last thing that will run on this site's behalf.
			MaintenanceMode::lift();
			return;
		}

		// Before the cutover the site's own files are still aside, so a file switch that stopped
		// part way goes back and prepared files are cleared. After it the database is the
		// backup's, and putting files back would leave the two mismatched.
		$before_cutover = array( RestoreOrchestrator::PHASE_VERIFY, RestoreOrchestrator::PHASE_ENGAGE, RestoreOrchestrator::PHASE_IMPORT, RestoreOrchestrator::PHASE_REWRITE, RestoreOrchestrator::PHASE_EXTRACT, RestoreOrchestrator::PHASE_PRUNE );
		if ( in_array( $phase, $before_cutover, true ) ) {
			list( $state, $undo ) = RestoreOrchestrator::undo_switch( $state );
			$state['errors']      = array_merge( isset( $state['errors'] ) ? (array) $state['errors'] : array(), $undo );
		}

		// Nothing was written to the site, so the staging copies are just litter.
		if ( empty( $state['destructive'] ) && ! empty( $state['tables'] ) ) {
			RestoreDatabase::discard_staging( $state['tables'] );
		}

		$state['phase']    = RestoreOrchestrator::PHASE_FAILED;
		$state['errors'][] = empty( $state['destructive'] )
			? __( 'The restore stopped responding and was given up on. Nothing on your site was changed.', 'tailwatch' )
			: __( 'The restore stopped responding after it had begun replacing files, and was given up on. Your site may be part-way between the two versions.', 'tailwatch' );

		$this->write_state( $state );

		PostRestoreGuard::remove_mu_guard();
		MaintenanceMode::lift();

		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		$this->release_lock();

		Log::error(
			'Restore abandoned after recovery exhausted its retries.',
			array(
				'feature'   => 'restore',
				'action'    => 'restore_recovery_gave_up',
				'title'     => 'Restore failed',
				'meta_data' => array(
					'feature' => 'Backup Vault',
					'event'   => 'Restore failed',
					'backup'  => isset( $state['folder_date'] ) ? $state['folder_date'] : '',
					'phase'   => $phase,
				),
			)
		);
	}

	/**
	 * Seconds a due tick may sit unfired before the site's cron is assumed broken.
	 *
	 * Comfortably longer than the slack a busy site adds to a due tick, so an
	 * ordinary late tick is never mistaken for a dead one.
	 */
	const STALL_GRACE = 120;

	/**
	 * Restart a restore whose worker is gone, at the request of the progress poller.
	 *
	 * Restore is the feature that needs this most: a restore that stops mid-flight leaves
	 * the site behind a maintenance page, so the failure is not merely invisible, it is
	 * user-facing.
	 *
	 * Nothing here trusts a flag the worker writes. The tick chain is a single scheduled
	 * event and the worker lock is a transient with a 600s TTL, and BOTH survive a kill
	 * that skips shutdown handlers — which is exactly the failure being recovered from.
	 * What cannot survive it is the heartbeat, so that is what decides whether a worker
	 * is alive; ProcessStatusService already reads it and already treats a stuck row as
	 * not running.
	 *
	 * The two dead cases are answered differently, because they have different causes:
	 *
	 * - No event scheduled at all: the chain was broken by a kill between two links.
	 *   Schedule the next tick.
	 * - An event scheduled and long overdue: the chain is intact and WP-Cron is not
	 *   firing. A second event would sit beside the first and change nothing, so nudge
	 *   cron instead. CronKick declines by itself on the setups where a nudge is wrong.
	 *
	 * @return array Response array.
	 */
	public function tailwatch_resume_restore_if_stalled() {
		// The same capability starting a restore needs. Driving a stalled one forward
		// continues that same install-wide overwrite, so asking for less here would make
		// the weaker check the one that counts.
		if ( ! $this->is_authorized_request( $this->network_capability() ) ) {
			return $this->unauthorized_response();
		}

		$state = $this->read_state();
		$phase = isset( $state['phase'] ) ? $state['phase'] : '';

		if ( '' === $phase
			|| in_array( $phase, array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ) ) {
			return array(
				'code'    => 200,
				'action'  => 'none',
				'message' => __( 'There is no restore to resume.', 'tailwatch' ),
			);
		}

		if ( ( new ProcessStatusService() )->is_running( 'restore' ) && ! $this->never_ticked( $state ) ) {
			return array(
				'code'    => 200,
				'action'  => 'none',
				'message' => __( 'The restore is still running.', 'tailwatch' ),
			);
		}

		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $scheduled && $scheduled > ( time() - self::STALL_GRACE ) ) {
			// Due soon, or only just overdue. The tick chain is intact; leave it alone.
			return array(
				'code'    => 200,
				'action'  => 'none',
				'message' => __( 'The next step of the restore is already scheduled.', 'tailwatch' ),
			);
		}

		if ( false !== $scheduled ) {
			// Scheduled, long overdue, nothing running it. Duplicating the event would not
			// help; getting WP-Cron to run the one that exists would.
			$kicked = CronKick::accelerate();

			Log::info(
				'Restore tick overdue; nudged WP-Cron',
				array(
					'feature' => 'restore',
					'action'  => 'restore_stalled_kick',
					'phase'   => $phase,
					'kicked'  => $kicked,
				)
			);

			return array(
				'code'    => 200,
				'action'  => $kicked ? 'kicked' : 'none',
				'message' => __( 'The restore is waiting on the site\'s scheduled tasks.', 'tailwatch' ),
			);
		}

		// No successor and no worker: the chain broke. Rebuild it.
		$this->release_lock();
		wp_schedule_single_event( time(), self::CRON_HOOK );
		CronKick::accelerate();

		Log::info(
			'Restore worker was gone; rescheduled the next tick',
			array(
				'feature' => 'restore',
				'action'  => 'restore_stalled_resume',
				'phase'   => $phase,
			)
		);

		return array(
			'code'    => 200,
			'action'  => 'resumed',
			'message' => __( 'The restore was restarted from where it stopped.', 'tailwatch' ),
		);
	}

	/**
	 * Advance the restore by one tick.
	 *
	 * @return void
	 */
	public function tailwatch_run_restore_cron() {
		if ( ! $this->acquire_lock() ) {
			return; // another worker is mid-tick.
		}

		// Opened BEFORE any work, and closed only on a clean return. A tick killed by the
		// host never reaches close(), and the next open() reads how far it got — which is
		// the only way to learn a limit that ini_get() does not know about.
		$tick_was_killed = TickBudget::open( 'restore' );

		try {
			( new PerformanceOptimizerController() )->tailwatch_boost_for_scanning();

			// Both of these belong on EVERY tick, not just the first.
			//
			// The long phases that actually run out of memory — import, extract, swap —
			// each run in their own cron request, and arming only once would leave them
			// with nothing armed. The re-arm is what schedules a successor after an
			// uncatchable kill; without it a dead tick waits out the stuck threshold with
			// the site behind a maintenance page. Arming is idempotent, so repeating it per
			// tick costs nothing.
			MaintenanceMode::arm_fatal_guard();
			// With its own liveness test. The shared re-arm defaults to reading the
			// BACKUP's state row, and a restore cannot start while a backup is running —
			// so the default would never say yes here, and the successor this exists to
			// schedule would never be scheduled.
			BackupController::tailwatch_arm_fatal_rearm(
				self::CRON_HOOK,
				static function () {
					$live = ( new self() )->read_state();

					return is_array( $live )
						&& ! empty( $live['phase'] )
						&& ! in_array(
							$live['phase'],
							array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ),
							true
						);
				}
			);

			$state = $this->read_state();

			// A killed tick persisted nothing, so the offset on disk is from BEFORE the
			// statements that tick already ran. Resuming there replays them, and replayed
			// CREATEs and INSERTs fail on "already exists" and "duplicate entry" — which
			// the importer counts as errors and turns into an abandoned restore. The
			// operator sees a restore that failed for no reason they can act on.
			//
			// Handled here rather than by making every statement idempotent: only the
			// import phase is affected, it writes nothing outside staging, and starting it
			// again is merely slower. Correctness first, and the cost is paid only after a
			// host actually killed a tick.
			if ( $tick_was_killed && is_array( $state ) && isset( $state['phase'] ) && RestoreOrchestrator::PHASE_IMPORT === $state['phase'] ) {
				$state = RestoreOrchestrator::restart_import( $state );
				$this->write_state( $state );
			}

			if ( empty( $state ) || ! isset( $state['phase'] ) ) {
				TickBudget::close( 'restore' );
				return;
			}
			if ( in_array( $state['phase'], array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ) ) {
				TickBudget::close( 'restore' );
				return;
			}

			$budget   = BackupController::tailwatch_tick_budget( self::TICK_BUDGET, 'restore' );
			$deadline = time() + $budget;

			$tick_started = microtime( true );
			$tick_from    = $state['phase'];

			do {
				$previous = $state['phase'];

				$state = RestoreOrchestrator::tick( $state, $deadline );
				$this->write_state( $state );

				if ( isset( $state['process_id'] ) && ! empty( $state['process_id'] ) ) {
					$this->process_manager->heart_beat( $state['process_id'] );
					// A row is created 'pending', and `pending` is an adoptable state: left
					// there, a running restore reports "Pending" for its whole life and the
					// NEXT restore adopts this row instead of opening its own, inheriting a
					// start time and a heartbeat from the run before. Backup and the malware
					// scanner both beat and set the state together; this is the same pair.
					$this->process_manager->update_state( $state['process_id'], 'in_progress' );
				}

				if ( $previous !== $state['phase'] ) {
					$this->update_logs( $state['phase'] );
				}
			} while ( self::continues_in_this_tick( $previous, $state['phase'], $deadline ) );

			$state = self::note_tick( $state, $tick_started, $tick_from, $previous, $deadline );
			$this->write_state( $state );

			switch ( $state['phase'] ) {
				case RestoreOrchestrator::PHASE_DONE:
					$this->finish( $state, true );
					TickBudget::close( 'restore' );
					return;

				case RestoreOrchestrator::PHASE_FAILED:
					$this->finish( $state, false );
					TickBudget::close( 'restore' );
					return;
			}

			wp_schedule_single_event( time(), self::CRON_HOOK );
			TickBudget::close( 'restore' );

			// Strictly after the event above. The restore continues either way; this only
			// stops a low-traffic site from advancing one tick per visitor.
			CronKick::accelerate();
		} catch ( \Throwable $e ) {
			// Without this, a throw from any phase leaves the state row mid-restore with
			// no successor scheduled and, worse, never reaches the orchestrator's own
			// fail() — so maintenance mode is never lifted and the site stays behind a
			// 503 until the watchdog. Record the failure, which lifts, and stop.
			$state = $this->read_state();
			if ( is_array( $state ) && ! empty( $state ) ) {
				$state['phase']    = RestoreOrchestrator::PHASE_FAILED;
				$state['errors'][] = __( 'The restore stopped unexpectedly.', 'tailwatch' );
				$this->write_state( $state );
				$this->finish( $state, false );
			}
			MaintenanceMode::lift();
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Whether a tick that just ran a phase goes straight on to the next one.
	 *
	 * Only when the phase changed and budget remains, and never past the swap: finalize
	 * removes the recovery guard, which is only honest once a fresh request has booted
	 * the swapped database and the restored files.
	 *
	 * @param string $previous Phase the tick ran.
	 * @param string $phase    Phase it left the restore in.
	 * @param int    $deadline Tick deadline.
	 * @return bool
	 */
	private static function continues_in_this_tick( $previous, $phase, $deadline ) {
		if ( $previous === $phase || RestoreOrchestrator::PHASE_SWAP === $previous ) {
			return false;
		}

		if ( in_array( $phase, array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ) ) {
			return false;
		}

		return ! TickBudget::spent( $deadline );
	}

	/**
	 * Ticks kept in the restore state.
	 */
	const TICK_HISTORY = 40;

	/**
	 * Record when this tick started, how long it waited for that and ran, and why it ended.
	 *
	 * The restore log speaks only when a part or phase changes, so a gap in it cannot tell
	 * slow work from a tick that started late. This can.
	 *
	 * @param array  $state    State after the tick's work.
	 * @param float  $started  When the tick started working.
	 * @param string $from     Phase the tick started in.
	 * @param string $previous Phase of the tick's last step.
	 * @param int    $deadline Tick deadline.
	 * @return array
	 */
	private static function note_tick( array $state, $started, $from, $previous, $deadline ) {
		$ticks = ( isset( $state['ticks'] ) && is_array( $state['ticks'] ) ) ? array_values( $state['ticks'] ) : array();
		$last  = empty( $ticks ) ? null : $ticks[ count( $ticks ) - 1 ];
		$since = is_array( $last )
			? (float) $last['at'] + (float) $last['ran']
			: (float) ( isset( $state['started'] ) ? $state['started'] : $started );

		if ( in_array( $state['phase'], array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ) ) {
			$stop = $state['phase'];
		} elseif ( RestoreOrchestrator::PHASE_SWAP === $previous && RestoreOrchestrator::PHASE_SWAP !== $state['phase'] ) {
			$stop = 'switched';
		} elseif ( TickBudget::memory_exceeded() ) {
			$stop = 'memory';
		} elseif ( TickBudget::spent( $deadline ) ) {
			$stop = 'time';
		} else {
			$stop = 'yield';
		}

		$ticks[] = array(
			'at'     => round( (float) $started, 1 ),
			'waited' => round( max( 0, (float) $started - $since ), 1 ),
			'ran'    => round( microtime( true ) - (float) $started, 1 ),
			'from'   => (string) $from,
			'to'     => (string) $state['phase'],
			'part'   => isset( $state['archive_index'] ) ? (int) $state['archive_index'] : 0,
			'entry'  => isset( $state['entry_offset'] ) ? (int) $state['entry_offset'] : 0,
			'stop'   => $stop,
		);

		$state['ticks'] = array_slice( $ticks, -self::TICK_HISTORY );

		return $state;
	}

	/**
	 * Whether a restore started a while ago has still not run a single tick.
	 *
	 * Its process row is fresh from creation, so the running check alone would refuse
	 * to help it for the whole stuck threshold. A held worker lock means a first tick
	 * is under way, and that is left alone.
	 *
	 * @param array $state Restore state.
	 * @return bool
	 */
	private function never_ticked( array $state ) {
		if ( empty( $state['process_id'] ) || get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}

		$row = $this->process_manager->get_process( $state['process_id'] );
		if ( ! is_array( $row ) || ! isset( $row['state'], $row['started_at'] ) || 'pending' !== $row['state'] ) {
			return false;
		}

		$started = strtotime( (string) $row['started_at'] );

		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- started_at is stored as local wall-clock time.
		return false !== $started && ( current_time( 'timestamp' ) - $started ) >= self::STALL_GRACE;
	}

	/**
	 * Close out a finished restore.
	 *
	 * @param array $state   Final state.
	 * @param bool  $success Whether it completed.
	 * @return void
	 */
	private function finish( array $state, $success ) {
		$process_id = isset( $state['process_id'] ) ? $state['process_id'] : '';

		if ( $success ) {
			if ( '' !== $process_id ) {
				$this->process_manager->mark_completed( $process_id );
			}
			Log::info(
				'Your site has been restored from backup.',
				array(
					'feature'   => 'restore',
					'action'    => 'restore_complete',
					'title'     => 'Restore complete',
					'meta_data' => array(
						'feature' => 'Backup Vault',
						'event'   => 'Completed',
						'impact'  => 'A restore cannot be undone. To go back, restore a backup taken before it.',
						'backup'  => isset( $state['folder_date'] ) ? $state['folder_date'] : '',
					),
				)
			);
			return;
		}

		if ( '' !== $process_id ) {
			$this->process_manager->mark_failed( $process_id, 'restore_failed' );
		}

		// Say plainly whether the site was modified. "Restore failed" means very
		// different things before and after the destructive phase, and the operator
		// needs to know which one happened.
		$touched = ! empty( $state['destructive'] );
		Log::error(
			$touched
				? 'The restore failed after it had begun replacing files. Your database was not changed.'
				: 'The restore was stopped before anything on your site was changed.',
			array(
				'feature'   => 'restore',
				'action'    => 'restore_failed',
				'title'     => 'Restore failed',
				'meta_data' => array(
					'feature'      => 'Backup Vault',
					'event'        => 'Failed',
					'site_changed' => $touched,
					'reason'       => isset( $state['errors'][0] ) ? $state['errors'][0] : 'unknown',
				),
			)
		);
	}

	/**
	 * Record phase transitions in the live log.
	 *
	 * @param string $phase New phase.
	 * @return void
	 */
	private function update_logs( $phase ) {
		$messages = array(
			RestoreOrchestrator::PHASE_ENGAGE  => 'Closing the site to visitors',
			RestoreOrchestrator::PHASE_IMPORT  => 'Loading the database into a staging copy',
			RestoreOrchestrator::PHASE_REWRITE => 'Updating the site address in the restored database',
			RestoreOrchestrator::PHASE_EXTRACT => 'Restoring files',
			RestoreOrchestrator::PHASE_PRUNE   => 'Removing files the backup no longer contains',
			RestoreOrchestrator::PHASE_SWAP    => 'Switching the database over',
			RestoreOrchestrator::PHASE_DONE    => 'Restore complete',
		);
		if ( isset( $messages[ $phase ] ) ) {
			Log::info(
				$messages[ $phase ],
				array(
					'feature' => 'restore',
					'action'  => 'restore_progress',
					'origin'  => 'system',
				)
			);
		}
	}

	/**
	 * Normalise a dispatched payload into an array.
	 *
	 * ActionDispatcher hands controllers the RE-ENCODED JSON string, not an array —
	 * sanitize_request_payload() decodes, sanitizes and re-encodes, so every route
	 * reached from the browser receives a string. Reading it with array syntax
	 * silently yields nothing (a string offset by a non-numeric key is not `isset`),
	 * so every click would be refused as "no backup selected" while direct calls in
	 * tests still pass.
	 *
	 * Arrays are still accepted so REST/dispatch callers and tests that pass one
	 * directly keep working.
	 *
	 * @param string|array|null $post_data Raw dispatched payload.
	 * @return array
	 */
	private function decode_payload( $post_data ) {
		if ( is_array( $post_data ) ) {
			return $post_data;
		}
		if ( ! is_string( $post_data ) || '' === $post_data ) {
			return array();
		}
		$decoded = json_decode( wp_unslash( $post_data ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
	/**
	 * Read the restore state row.
	 *
	 * @return array
	 */
	private function read_state() {
		$data = ( new DBModel() )->get_recent_data( self::STATE_OPTION, self::STATE_KEY );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * The stored restore state, or an empty array when there has never been one.
	 *
	 * Static for the same reason as current_phase().
	 *
	 * @return array
	 */
	public static function stored_state() {
		$data = ( new DBModel() )->get_recent_data( self::STATE_OPTION, self::STATE_KEY );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Keep the backup a restore is reading out of retention's reach.
	 *
	 * Retention runs on its own schedule, and a restore that brings back a database can bring
	 * back a longer list of backups than the site keeps, so the one being read could be the one
	 * retention picks. Static, and registered at bootstrap, because retention runs in cron
	 * requests where this controller is never constructed.
	 *
	 * @param array $folders Folder name => true.
	 * @return array
	 */
	public static function protect_restoring_folder( $folders ) {
		$folders = is_array( $folders ) ? $folders : array();

		$state = self::stored_state();
		$phase = isset( $state['phase'] ) && is_string( $state['phase'] ) ? $state['phase'] : '';
		if ( ! empty( $state['folder_date'] ) && '' !== $phase && ! in_array( $phase, array( RestoreOrchestrator::PHASE_DONE, RestoreOrchestrator::PHASE_FAILED ), true ) ) {
			$folders[ (string) $state['folder_date'] ] = true;
		}

		return $folders;
	}

	/**
	 * The phase of the stored restore, or '' when there has never been one.
	 *
	 * Static, and deliberately not routed through read_state(): ProcessStatusService
	 * asks this on any gate check, and constructing a RestoreController to answer
	 * would register the cron listener and the process config as a side effect of a
	 * read-only question.
	 *
	 * @return string One of RestoreOrchestrator's PHASE_* values, or ''.
	 */
	public static function current_phase() {
		$data = ( new DBModel() )->get_recent_data( self::STATE_OPTION, self::STATE_KEY );
		return ( is_array( $data ) && isset( $data['phase'] ) && is_string( $data['phase'] ) ) ? $data['phase'] : '';
	}

	/**
	 * Write the restore state row, creating it the first time.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function write_state( array $state ) {
		// Stamped on every write because a state row is the ONLY evidence a restore
		// leaves when its ProcessManager row is missing — swept by the 7-day cleanup, or
		// never created because the start died between the two writes. ProcessStatusService
		// looks for exactly this field name when it has to age an unbacked claim, and
		// without it 'is a restore running?' answers yes forever, locking every process
		// that excludes one.
		$state['last_activity'] = time();

		// Captured, used and finished with inside the one request that swaps the tables:
		// capture_own_state() runs just before the rename and reinstate_own_state() just
		// after, and nothing reads it again. Persisted, it would be a copy of every backup,
		// process and restore row riding in this row for ever, most of its size, read back
		// by every status poll and every process-guard check. Dropped here, the one place
		// restore state is written, so a row written by an older version that still carries
		// one is cleaned the next time it is saved.
		unset( $state['own_state'] );

		// Likewise the credentials the swap carries across, once no phase can read them.
		$state = RestoreOrchestrator::without_spent_auth_carry( $state );

		$db_model = new DBModel();
		$encoded  = wp_json_encode( $state );
		if ( false === $encoded ) {
			return;
		}

		$updated = $db_model->update_recent_row(
			array( 'value' => $encoded ),
			self::STATE_KEY,
			self::STATE_OPTION
		);

		if ( ! $updated ) {
			// update_recent_row() returns false when no row exists yet, so the first
			// write of a restore creates it. Column set mirrors the backup process row.
			$db_model->insert_row(
				array(
					'user_id'       => '1',
					'child_of'      => '0',
					'key'           => self::STATE_KEY,
					'option'        => self::STATE_OPTION,
					'value'         => $encoded,
					'type'          => isset( $state['folder_date'] ) ? $state['folder_date'] : '',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => true,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * Take the single-worker lock.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );
		// A tick killed by an OOM or the time limit bypasses the finally, so release on
		// shutdown too; the TTL is the last resort for a hard kill.
		register_shutdown_function( array( $this, 'release_lock' ) );
		return true;
	}

	/**
	 * Release the single-worker lock. Idempotent.
	 *
	 * @return void
	 */
	public function release_lock() {
		delete_transient( self::LOCK_TRANSIENT );
	}
}
