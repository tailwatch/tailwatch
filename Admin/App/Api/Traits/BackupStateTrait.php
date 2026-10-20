<?php
/**
 * Tailwatch - Backup State Trait
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Traits
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Traits;

use Tailwatch\Admin\App\Api\Models\DBModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading and writing the two backup state rows: `scan_backp` and `backup_cancel_pause`.
 *
 * A trait rather than a service, deliberately. The other backup concerns are stateless
 * collaborators (BackupManifest, BackupReconciler, ArchiveReader) with closed clusters and
 * a handful of callers. This one is neither:
 *
 * - it is reached from dozens of call sites in free and, through a BackupController
 *   instance, from Pro across the class_alias boundary — a service would mean changing
 *   or wrapping every one of them
 * - `tailwatch_guard_stop_state()` is shared by BOTH state rows, so pulling out only the
 *   scan_backp half would split a guard from one of its two users
 *
 * A trait is flattened into the class at compile time, so `$this`, the private static
 * write buffer, and reflection against BackupController all behave as if the methods
 * were declared on the class itself.
 */
trait BackupStateTrait {

	/**
	 * Minimum seconds between real writes of the backup state row.
	 *
	 * The state blob carries a `batch` entry per archive part and per DB part, so it
	 * grows with the job, and every write re-encodes the whole blob and costs two
	 * SELECTs (the stop-state guard, and update_recent_row()'s id lookup). Writes
	 * coalesce to at most one a second, with a shutdown flush guaranteeing the last
	 * one lands.
	 *
	 * A static property rather than a class constant on purpose: constants in traits
	 * require PHP 8.2, and both plugins declare a floor of PHP 7.4. On 7.4-8.1 a trait
	 * constant is a COMPILE-TIME fatal, so it would take down every request on both
	 * plugins the moment this file was autoloaded — and a local lint on 8.2 would not
	 * notice.
	 *
	 * @var float
	 */
	private static $state_write_interval = 1.0;

	/**
	 * Buffered [ $options, $allow_state_change ] awaiting a write, or null.
	 *
	 * Static because the backup path constructs BackupController ad hoc in several
	 * places within one tick; they must share a single buffer.
	 *
	 * @var array|null
	 */
	private static $pending_backup_data = null;

	/**
	 * microtime(true) of the last real write. 0.0 makes the first write immediate.
	 *
	 * @var float
	 */
	private static $last_backup_data_write = 0.0;

	/**
	 * Whether the shutdown flush has been registered for this request.
	 *
	 * @var bool
	 */
	private static $flush_registered = false;

	/**
	 * The backup run this request is working for (its folderDate), or '' when it is not a
	 * backup worker.
	 *
	 * A worker keeps working from the copy it read when its tick began, for as long as the
	 * tick lasts. If its run is cancelled and another starts meanwhile, the newest rows and
	 * the cron hooks belong to the other run. With an owner set, reads and writes stay on
	 * the owner's own rows, and a run that is no longer the current one reads as 'gone'.
	 * Set by each worker as it starts and cleared when it ends: WP-Cron runs several hooks
	 * in one request.
	 *
	 * @var string
	 */
	private static $run_owner = '';

	/**
	 * Gets the current scan/backup state from the database.
	 *
	 * @return array Scan backup data.
	 */
	public function tailwatch_get_scan_backup_data() {
		// Read-your-writes. update_backup_data() buffers the payload and flushes at
		// most once a second, so a same-request reader must see the buffer: several
		// callers do read-modify-write, and reading the row directly would rebuild
		// their payload from a stale copy and silently drop everything written since
		// the last flush.
		if ( null !== self::$pending_backup_data ) {
			return self::$pending_backup_data[0];
		}

		return $this->read_scan_backup_row( self::$run_owner );
	}

	/**
	 * Read the stored backup row straight from the database, ignoring the write buffer.
	 *
	 * Only tailwatch_guard_stop_state() wants this. Its whole job is to notice a
	 * pause/cancel written by a DIFFERENT request, which by definition is not in our
	 * buffer — going through the buffer-aware getter would make it compare the
	 * payload against itself and never see the stop-state. Everything else wants
	 * tailwatch_get_scan_backup_data().
	 *
	 * @param string $folder_date The run whose row to read; '' for the newest row.
	 * @return array|null
	 */
	private function read_scan_backup_row( $folder_date = '' ) {
		return $this->read_run_row( 'scan_backp', $folder_date );
	}

	/**
	 * One of a run's two rows, decoded: the run's own when $folder_date is given, the
	 * newest otherwise.
	 *
	 * @param string $option      'scan_backp' or 'backup_cancel_pause'.
	 * @param string $folder_date The run's folder name, or ''.
	 * @return array|null
	 */
	private function read_run_row( $option, $folder_date ) {
		$feature_controller = new DBModel();

		if ( '' === (string) $folder_date ) {
			// By id, because update_recent_row() writes by id. Ordered on `date_created` this
			// can read a row the writer has never touched, which for a guard whose whole job
			// is to see another request's cancel means either missing a real one or obeying
			// a stale one.
			return $feature_controller->get_recent_data_by_id( $option, 'default_backup_scan' );
		}

		$row = $feature_controller->get_run_row( $option, 'default_backup_scan', $folder_date );
		if ( null === $row ) {
			return null;
		}
		$value = json_decode( (string) $row['value'], true );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * The folder of the run a payload belongs to: its own folderDate, else this request's
	 * owner, else '' (a write with no run to name goes to the newest row, as before).
	 *
	 * @param array $options Payload about to be written.
	 * @return string
	 */
	private function run_folder_for( array $options ) {
		if ( isset( $options['folderDate'] ) && is_scalar( $options['folderDate'] ) && '' !== (string) $options['folderDate'] ) {
			return (string) $options['folderDate'];
		}
		return self::$run_owner;
	}

	/**
	 * Name the backup run this request works for.
	 *
	 * @param string $folder_date The run's folder name.
	 * @return void
	 */
	public static function tailwatch_set_run_owner( $folder_date ) {
		self::$run_owner = is_scalar( $folder_date ) ? (string) $folder_date : '';
	}

	/**
	 * Forget the run owner once the worker that set it has finished.
	 *
	 * @return void
	 */
	public static function tailwatch_clear_run_owner() {
		self::$run_owner = '';
	}

	/**
	 * Whether a run is still the site's current one: the newest status row is its own.
	 *
	 * Asked before anything that is not the run's own row: scheduling or unscheduling the
	 * backup hooks (they carry no run), marking a process, creating a folder, finishing,
	 * pruning or notifying.
	 *
	 * @param string|null $folder_date The run; defaults to this request's owner.
	 * @return bool True outside a worker (there is no run to compare), or when it is current.
	 */
	public function tailwatch_run_is_current( $folder_date = null ) {
		$folder_date = null === $folder_date ? self::$run_owner : (string) $folder_date;
		if ( '' === $folder_date ) {
			return true;
		}
		$newest = ( new DBModel() )->get_recent_data_by_id( 'backup_cancel_pause', 'default_backup_scan' );
		return is_array( $newest ) && isset( $newest['folderDate'] ) && (string) $newest['folderDate'] === $folder_date;
	}

	/**
	 * Whether the run this request works for may arm its next step.
	 *
	 * Asked again just before a step is armed, because the step before it can take most of a
	 * tick: a run cancelled, paused, failed or replaced meanwhile gets no further step. A
	 * cancelled run's step that fires after the next run has started would work on that run,
	 * out of order and beside that run's own step.
	 *
	 * @return bool
	 */
	public function tailwatch_run_may_continue() {
		$status = $this->tailwatch_backup_cancel_pause_data();
		$state  = is_array( $status ) && isset( $status['scan_state'] ) ? (string) $status['scan_state'] : '';
		return ! in_array( $state, array( 'cancel', 'pause', 'failed', 'completed', 'gone' ), true );
	}

	/**
	 * Keep an already-set stop-state (pause/cancel/failed/completed) from being reverted by a stale write.
	 *
	 * The file + DB crons persist state repeatedly across a ~20s loop from a copy captured at tick
	 * start; a pause/cancel/failure set mid-tick by a separate request is otherwise lost when that
	 * stale copy is written back, and the run keeps going while verify reports in-progress. When
	 * the payload carries a NON-terminal scan_state but the row already holds an active
	 * stop-state, re-read and keep the stop-state. Terminal writes (pause/cancel/failed/
	 * completed) and deliberate resumes ($allow_state_change) pass through untouched.
	 *
	 * A 'completed' row is kept whole, not only its state. Every writer of a non-terminal state
	 * over it is a copy read before the finaliser ran - a new run inserts fresh rows, and resume
	 * opts in - so nothing in the copy is newer than what is stored, and the finaliser's record
	 * (completed, had_failures, files_missing) must survive it. The copy carries completed=false,
	 * which the incomplete-backup cleanup deletes after 24 hours. The live-log poll is one such
	 * writer: it reads this row, measures the backup folder and reads the log before writing its
	 * copy back.
	 *
	 * @param array  $options            Payload about to be written.
	 * @param bool   $allow_state_change Resume opt-in: write scan_state verbatim.
	 * @param string $option             'scan_backp' or 'backup_cancel_pause'.
	 * @param string $folder_date        The run the payload is written to; '' for the newest row.
	 * @return array Payload, with scan_state coerced back to the active stop-state when applicable,
	 *               or the stored row itself when that row is finished.
	 */
	private function tailwatch_guard_stop_state( array $options, $allow_state_change, $option, $folder_date = '' ) {
		if ( $allow_state_change || ! isset( $options['scan_state'] )
			|| in_array( $options['scan_state'], array( 'pause', 'cancel', 'failed', 'completed' ), true ) ) {
			return $options;
		}
		$current = $this->read_run_row( $option, $folder_date );
		if ( ! isset( $current['scan_state'] ) ) {
			return $options;
		}
		if ( 'completed' === $current['scan_state'] ) {
			return $current;
		}
		if ( in_array( $current['scan_state'], array( 'pause', 'cancel', 'failed' ), true ) ) {
			$options['scan_state'] = $current['scan_state'];
		}
		return $options;
	}

	/**
	 * Updates the stored backup scan data (e.g. progress, state).
	 *
	 * @param array $options            Data to store (will be JSON-encoded).
	 * @param bool  $allow_state_change Pass true only for a deliberate resume (see tailwatch_guard_stop_state).
	 */
	public function update_backup_data( array $options, $allow_state_change = false, $force = false ) {
		self::$pending_backup_data = array( $options, $allow_state_change );

		$elapsed = microtime( true ) - self::$last_backup_data_write;
		if ( ! $force && $elapsed < self::$state_write_interval ) {
			$this->register_state_flush();
			return;
		}

		$this->flush_backup_data();
	}

	/**
	 * Arrange for any buffered state to be written before the request ends.
	 *
	 * Registered at most once per request, and against $this rather than a fresh
	 * instance: the constructor registers action hooks, so building a new
	 * BackupController inside a shutdown handler would double-register them.
	 *
	 * Shutdown handlers still run for an OOM or max_execution_time kill (both are
	 * E_ERROR, not signals), which is what makes the throttle safe — the worst case
	 * is losing under a second of progress to a SIGKILL, and the job resumes from
	 * its cursor anyway.
	 *
	 * @return void
	 */
	private function register_state_flush() {
		if ( self::$flush_registered ) {
			return;
		}
		self::$flush_registered = true;

		register_shutdown_function(
			function () {
				$this->flush_backup_data();
			}
		);
	}

	/**
	 * Write any buffered backup state to the database.
	 *
	 * @return void
	 */
	public function flush_backup_data() {
		if ( null === self::$pending_backup_data ) {
			return;
		}

		list( $options, $allow_state_change ) = self::$pending_backup_data;

		self::$pending_backup_data    = null;
		self::$last_backup_data_write = microtime( true );

		// The stop-state guard runs here, not at buffer time, so it compares against
		// the row as it stands at the moment of writing — that is what lets a
		// pause/cancel issued by another request mid-tick survive.
		$folder_date = $this->run_folder_for( $options );
		$options     = $this->tailwatch_guard_stop_state( $options, $allow_state_change, 'scan_backp', $folder_date );
		$db_data     = array( 'value' => wp_json_encode( $options ) );
		$db_model    = new DBModel();

		// To the run's own row: a run that has been replaced since this payload was read has
		// no row any more, and its write is dropped rather than landing on the next run's.
		if ( '' !== $folder_date ) {
			$db_model->update_run_row( $db_data, 'default_backup_scan', 'scan_backp', $folder_date );
			return;
		}
		$db_model->update_recent_row( $db_data, 'default_backup_scan', 'scan_backp' );
	}

	/**
	 * Updates the backup scan state (e.g. in-progress, cancel, pause, completed).
	 *
	 * @param string $scan_state         New state value.
	 * @param bool   $allow_state_change Pass true only for a deliberate resume (see tailwatch_guard_stop_state).
	 */
	public function update_backup_scan_state( $scan_state, $allow_state_change = false ) {
		if ( 'gone' === $scan_state ) {
			return;
		}
		$backup_data = $this->tailwatch_get_scan_backup_data();
		if ( empty( $backup_data ) || ! is_array( $backup_data ) ) {
			return;
		}
		if ( ! isset( $backup_data['scan_state'] ) || $backup_data['scan_state'] !== $scan_state ) {
			$backup_data['scan_state'] = $scan_state;

			// Terminal states are what other requests poll for, so they bypass the
			// write throttle and land immediately rather than up to a second late.
			$is_terminal = in_array( $scan_state, array( 'pause', 'cancel', 'failed', 'completed' ), true );

			$this->update_backup_data( $backup_data, $allow_state_change, $is_terminal );
		}
	}

	/**
	 * Gets cancel/pause and progress data for the current backup.
	 *
	 * In a worker (an owner is set) this is the owner's own row, and while the owner is not the
	 * current run it reads `scan_state` 'gone' - so every existing stop check stops it, and
	 * nothing it does afterwards belongs to the run that replaced it.
	 *
	 * @return array Cancel/pause and progress data.
	 */
	public function tailwatch_backup_cancel_pause_data() {
		$feature_controller = new DBModel();
		$tailwatch_key      = 'default_backup_scan';
		$option             = 'backup_cancel_pause';

		// By id, to match update_backup_cancel_pause(). Read on `date_created` instead, an
		// older row can sort newest - the column is a varchar written by paths that do not
		// share a clock - and a run that finished is then reported as cancelled and its
		// folder thrown away.
		$newest = $feature_controller->get_recent_data_by_id( $option, $tailwatch_key );
		if ( '' === self::$run_owner ) {
			return $newest;
		}
		if ( ! is_array( $newest ) || (string) ( $newest['folderDate'] ?? '' ) !== self::$run_owner ) {
			return array(
				'scan_state'   => 'gone',
				'folderDate'   => self::$run_owner,
				'cron_running' => false,
			);
		}
		return $newest;
	}

	/**
	 * The live backup run, or null when the newest row is not one.
	 *
	 * `scan_backp` carries two different things under one key: every backup this site
	 * knows about, and the run that is happening now. Taking the newest row and treating
	 * it as the run is only correct while the two are the same thing, and after a
	 * migration they are not - MigrationPublisher::register_backup_row() adds a row for
	 * the folder that was carried in, so the backup list can see it. That row describes a
	 * backup made somewhere else: it has no zipId, and its site_url is the site it came
	 * from.
	 *
	 * Adopted as a run it produces a backup that can never finish. Every archive is named
	 * `{area}_part_{n}_{zipId}.zip`, so an empty zipId names them all `{area}_part_{n}_.zip`;
	 * each cycle overwrites the last, no area ever accumulates its parts, the completion
	 * check never passes and the run starts again, re-archiving into the migrated folder.
	 *
	 * So the row is asked to prove it is this site's run before anything acts on it. All
	 * three questions are answered by fields the row already carries.
	 *
	 * @return array|null
	 */
	public function tailwatch_backup_run_state() {
		$state = ( new DBModel() )->get_recent_data_by_id( 'scan_backp', 'default_backup_scan' );

		if ( empty( $state ) || ! is_array( $state ) ) {
			return null;
		}

		// A catalogue entry for a backup that arrived from somewhere else. It is a record,
		// not a job, and the migration says so in the row itself.
		if ( ! empty( $state['migrated'] ) ) {
			return null;
		}

		// A real run never has an empty zipId - it is stamped the moment a backup starts.
		// Without this the emptiness is not an error, it is a filename, and the run loops.
		if ( '' === trim( (string) ( $state['zipId'] ?? '' ) ) ) {
			return null;
		}

		// Stamped at the start of every backup with the site that made it.
		$owner = trim( (string) ( $state['site_url'] ?? '' ) );
		if ( '' !== $owner && untrailingslashit( $owner ) !== untrailingslashit( site_url() ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Updates the backup cancel/pause and progress record.
	 *
	 * @param array $options            Data to store (will be JSON-encoded).
	 * @param bool  $allow_state_change Pass true only for a deliberate resume (see tailwatch_guard_stop_state).
	 */
	public function update_backup_cancel_pause( array $options, $allow_state_change = false ) {
		if ( isset( $options['scan_state'] ) && 'gone' === $options['scan_state'] ) {
			return;
		}
		$folder_date   = $this->run_folder_for( $options );
		$options       = $this->tailwatch_guard_stop_state( $options, $allow_state_change, 'backup_cancel_pause', $folder_date );
		$db_model      = new DBModel();
		$tailwatch_key = 'default_backup_scan';
		$option        = 'backup_cancel_pause';

		$db_data = array(
			'value' => wp_json_encode( $options ),
		);

		if ( '' !== $folder_date ) {
			$db_model->update_run_row( $db_data, $tailwatch_key, $option, $folder_date );
			return;
		}
		$db_model->update_recent_row( $db_data, $tailwatch_key, $option );
	}
}
