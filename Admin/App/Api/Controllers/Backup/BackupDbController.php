<?php
namespace Tailwatch\Admin\App\Api\Controllers\Backup;

use Tailwatch\Admin\App\Api\Services\Cron\CronKick;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\BackupModel;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\ProcessManager;
use Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BackupDbController
 *
 * Handles database backup scanning and SQL export for backup feature.
 *
 */
class BackupDbController {

	private $get_live_logs = TAILWATCH_LOGS_DIRECTORY . '/backup-logs/create_backup.json';
	private $log_directory = TAILWATCH_LOGS_DIRECTORY . '/backup-logs';
	private $process_manager;
	private $backup_model;

	/**
	 * Unix-ts deadline to yield the current cron's chunk loop (set once at cron entry). 0 = unset.
	 *
	 * @var int
	 */
	private $db_cron_deadline = 0;

	/**
	 * Constructor. Registers cron hooks for DB table scan and backup creation.
	 *
	 */
	public function __construct() {
		$this->process_manager = new ProcessManager();
		$this->backup_model    = new BackupModel();
		$hook_controller       = new HookControllers();
		$hook_controller->add_action_hook( 'tailwatch_scan_db_tables_cron', array( $this, 'tailwatch_scan_db_tables' ) );
		$hook_controller->add_action_hook( 'tailwatch_create_db_backup_cron', array( $this, 'tailwatch_create_db_backup' ) );
	}

	/**
	 * Cron callback: scans DB tables and schedules create_db_backup cron.
	 *
	 */
	public function tailwatch_scan_db_tables() {
		try {
			BackupController::tailwatch_forget_cached_schedule();
			$feature_controller = new DBModel();
			$tailwatch_key           = 'default_backup_scan';
			$option             = 'scan_backp';

			$backup_controller = new BackupController();
			$cancel_pause      = $backup_controller->tailwatch_backup_cancel_pause_data();
			if ( isset( $cancel_pause['scan_state'] ) && 'failed' === $cancel_pause['scan_state'] ) {
				return; // terminal — mark_backup_failed already stopped everything; don't revive the DB phase.
			}
			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				return;
			}

			$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : $this->process_manager->get_or_create_process( 'backup', 'tailwatch_backup_daily_scan' );
			$this->process_manager->heart_beat( $process_id );
			$this->process_manager->update_state( $process_id, 'in_progress' );

			$existing_data    = $feature_controller->get_recent_data( $option, $tailwatch_key );
			$folder_date      = isset( $existing_data['folderDate'] ) ? $existing_data['folderDate'] : current_time( 'Y-m-d' );
			$sql_id           = $existing_data['zipId'];
			$backup_directory = TAILWATCH_BACKUP_DIR . '/database/';
			BackupController::tailwatch_set_run_owner( is_array( $existing_data ) ? ( $existing_data['folderDate'] ?? '' ) : '' );
			if ( ! $existing_data ) {
				$existing_data = array(
					'tables'        => array(),
					'current_table' => 0,
				);
			}

			if ( ! isset( $existing_data['tables'] ) ) {
				$is_malware_scan = isset( $existing_data['process_run'] ) && in_array( $existing_data['process_run'], array( 'malware', 'files_integrity' ));
				$tables          = $this->get_tables_list( $is_malware_scan );

				// Finishing a scan run's database part with nothing in it would send the scan
				// on as though the database had been backed up, so the run stops instead.
				if ( $is_malware_scan && empty( $tables ) ) {
					$backup_controller->update_logs_records( 'No tables were found for the scan, so its database backup stopped before copying anything and nothing will be sent. Start the scan again.', 'ERROR' );
					$backup_controller->tailwatch_mark_backup_failed( 'scan_tables_unresolved' );
					return;
				}

				foreach ( $tables as $table ) {
					$existing_data['tables']['db_tables'][ $table ] = array(
						'completed'      => false,
						'rows_processed' => 0,
						'table_insert'   => false,
					);
				}
				$existing_data['tables']['db_size_process'] = 0;
				$existing_data['tables']['completed']       = false;
				$existing_data['tables']['path']            = $this->tailwatch_dump_path( $backup_directory . $folder_date . '/db_parent_tables_' . $sql_id );

				$backup_controller->update_logs_records( 'Starting database backup' );
				$table_label = $is_malware_scan ? 'malware-targeted tables' : 'tables';
				$backup_controller->update_logs_records( 'Found ' . count( $tables ) . ' ' . $table_label . ' to back up' );

				// Through the state layer: onto this run's own row, past the stop-state guard.
				$backup_controller->update_backup_data( $existing_data, false, true );
			}

			// Listing the tables takes a moment, so the export starts in this same run.
			wp_clear_scheduled_hook( 'tailwatch_create_db_backup_cron' );
			$this->tailwatch_create_db_backup();
		} catch ( \Throwable $e ) {
			if ( ! empty( $process_id ) ) {
				$this->process_manager->mark_failed( $process_id, $e->getMessage() );
			}
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_db_scan_failed',
					'exception' => $e,
				)
			);
		} finally {
			BackupController::tailwatch_clear_run_owner();
			BackupController::tailwatch_forget_cached_schedule();
		}
	}

	public function tailwatch_create_db_backup() {
		try {
			BackupController::tailwatch_forget_cached_schedule();
			// Raise memory/time before dumping — large BLOB chunks are an OOM/timeout
			// source on small hosts. Runtime-only, idempotent, never lowers.
			( new PerformanceOptimizerController() )->tailwatch_boost_for_scanning();

			// A dump chunk killed by OOM or the time limit is an E_ERROR, so the catch
			// below never sees it and this cron would not reschedule itself. Re-arm from
			// a shutdown handler rather than waiting for the recovery watchdog.
			BackupController::tailwatch_arm_fatal_rearm( 'tailwatch_create_db_backup_cron' );

			$feature_controller = new DBModel();
			$tailwatch_key           = 'default_backup_scan';
			$option             = 'scan_backp';

			$backup_controller = new BackupController();

			$existing_data = $backup_controller->tailwatch_get_scan_backup_data();
			BackupController::tailwatch_set_run_owner( is_array( $existing_data ) ? ( $existing_data['folderDate'] ?? '' ) : '' );
			$cancel_pause = $backup_controller->tailwatch_backup_cancel_pause_data();

			// Replaced by another run since this step was queued: everything below is that run's.
			if ( isset( $cancel_pause['scan_state'] ) && 'gone' === $cancel_pause['scan_state'] ) {
				return;
			}

			// Terminal 'failed' or 'completed' run: bail BEFORE re-setting cron_running /
			// re-activating the process row below (this worker would otherwise actively undo
			// mark_backup_failed, or reopen a finished backup and schedule a files tick that
			// finalises it again).
			if ( isset( $cancel_pause['scan_state'] ) && in_array( $cancel_pause['scan_state'], array( 'failed', 'completed' ), true ) ) {
				return;
			}

			if ( false === $cancel_pause['cron_running'] ) {
				$cancel_pause['cron_running'] = true;
				$backup_controller->update_backup_cancel_pause( $cancel_pause );
			}

			$backup_controller->tailwatch_backup_function_started();

			$folder_date      = isset( $existing_data['folderDate'] ) ? $existing_data['folderDate'] : current_time( 'Y-m-d' );
			$backup_directory = TAILWATCH_BACKUP_DIR . '/';
			$this->create_backup_directories( $backup_directory, $folder_date );

			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				return;
			}

			$process_id = isset( $cancel_pause['process_id'] ) ? $cancel_pause['process_id'] : $this->process_manager->get_or_create_process( 'backup', 'tailwatch_backup_daily_scan' );

			$this->process_manager->heart_beat( $process_id );
			$this->process_manager->update_state( $process_id, 'in_progress' );

			$current_table = $this->get_first_incomplete_table( $existing_data['tables']['db_tables'] ?? array() );
			if ( ! $current_table ) {
				$backup_controller->update_logs_records( 'Database backup complete', 'SUCCESS' );

				if ( self::tailwatch_run_has_files( $existing_data ) ) {
					wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
					CronKick::accelerate();
				} elseif ( empty( $existing_data['completed'] ) ) {
					$existing_data['tables']['completed'] = true;
					$backup_controller->check_and_finalize_backup( $existing_data, null, '', '', $process_id );
				}
				$backup_controller->tailwatch_backup_function_complete();
				return;
			}

			$current_chunk   = (int) ( ( $existing_data['tables']['db_tables'][ $current_table ]['rows_processed'] ?? 0 ) / $this->tailwatch_db_chunk_size( $current_table, $existing_data ) );
			$database_backup = $backup_directory . 'database/' . $folder_date . '/';

			// Yield deadline for this cron's chunk loop (paired with the size backstop
			// below). Bounded by the host's real execution limit rather than a flat 20s —
			// see BackupController::tailwatch_tick_budget(). Read after the boost above so
			// a successfully raised limit counts.
			$this->db_cron_deadline = time() + BackupController::tailwatch_tick_budget( 20 );

			// No-progress guard: a chunk killed mid-export (huge BLOB row vs host limit) leaves the
			// cursor unadvanced (last_pk/rows_processed update only AFTER generate_sql), so it would
			// re-export forever with no error. Count consecutive same-position crons — persist BEFORE
			// the chunk so a kill still counts — and fail after 3. Never fires on a progressing run.

			// Resolved here rather than in the chunk method so it survives a tick that dies
			// before writing any state — the ratchet below needs to know the paging mode on
			// exactly that tick. The chunk method reads the cached value, so this adds no query.
			if ( ! isset( $existing_data['tables']['db_tables'][ $current_table ]['pk_info'] ) ) {
				$existing_data['tables']['db_tables'][ $current_table ]['pk_info'] = $this->backup_model->get_table_primary_key( $current_table );
			}
			$db_table_ref = &$existing_data['tables']['db_tables'][ $current_table ];
			$db_keyset    = isset( $db_table_ref['pk_info']['mode'] ) && 'keyset' === $db_table_ref['pk_info']['mode'];

			// The position is the RESUME POINT, not the chunk index. Keyset paging resumes
			// from last_pk, and the index is only ever derived as rows_processed / chunk_size
			// — so keying the guard off the index would make any change of size look like
			// progress and silently disarm it. That matters because a stuck chunk shrinks.
			$db_position = $db_keyset
				? $current_table . ':pk:' . substr( (string) ( isset( $db_table_ref['last_pk'] ) ? $db_table_ref['last_pk'] : '' ), 0, 64 )
				: $current_table . ':chunk:' . $current_chunk;
			$db_last     = isset( $existing_data['tables']['db_last_position'] ) ? $existing_data['tables']['db_last_position'] : '';
			$db_stuck    = isset( $existing_data['tables']['db_stuck_count'] ) ? (int) $existing_data['tables']['db_stuck_count'] : 0;
			$db_stuck    = ( $db_last === $db_position ) ? ( $db_stuck + 1 ) : 0;

			// Same resume point as last tick: that tick died on this chunk. Retrying it at
			// the same size would make a row set that is merely too big for this host
			// indistinguishable from one that can never be exported. Step the size down and
			// cap it there, so the three attempts are a ladder and only a chunk that fails at
			// the floor aborts the backup. Keyset only: under the offset fallback the cursor
			// IS index * size, so changing the size mid-table would move rows under it.
			if ( $db_stuck > 0 && $db_stuck < 3 && $db_keyset ) {
				$this->tailwatch_ratchet_chunk_size( $db_table_ref );
				$current_chunk = (int) ( ( $db_table_ref['rows_processed'] ?? 0 ) / $this->tailwatch_db_chunk_size( $current_table, $existing_data ) );
			}
			unset( $db_table_ref );

			if ( $db_stuck >= 3 ) {
				$backup_controller->update_logs_records( "Database backup stalled on {$current_table} (chunk {$current_chunk}) — still failing after the chunk size was stepped down, so a single row is too large to export within the host limit; aborting backup", 'ERROR' );
				$backup_controller->tailwatch_mark_backup_failed( "db_chunk_no_progress:{$current_table}:{$current_chunk}" );
				return;
			}
			$existing_data['tables']['db_last_position'] = $db_position;
			$existing_data['tables']['db_stuck_count']   = $db_stuck;
			// Forced past the write throttle: this one exists specifically so a kill
			// during the chunk loop still counts, which a buffered write would not
			// guarantee against a signal that skips shutdown handlers.
			$backup_controller->update_backup_data( $existing_data, false, true );

			// Pack chunks in a flat loop until a budget is hit or the backup finishes. Each pass
			// re-picks the chunk from the updated in-memory state and honors a mid-run
			// cancel/pause/failed + heartbeat.
			while ( true ) {
				// A chunk's SQL is bounded near a few megabytes; the margin covers the hex-doubling
				// of binary columns.
				if ( ! $backup_controller->tailwatch_disk_guard( 16 * 1048576, 'the next rows of ' . $current_table ) ) {
					return;
				}

				$continue = $this->tailwatch_backup_table_in_chunks( $current_table, $database_backup, $existing_data, $backup_controller, $current_chunk );
				if ( ! $continue ) {
					break; // a budget was hit; the chunk method already rescheduled the next cron
				}

				// true = more chunks remain; false = backup completed (or cancelled/paused).
				if ( ! $this->handle_backup_completion( $existing_data, $backup_controller, $process_id ) ) {
					break;
				}

				$current_table = $this->get_first_incomplete_table( $existing_data['tables']['db_tables'] ?? array() );
				if ( ! $current_table ) {
					break; // defensive: handle_backup_completion returns true only while work remains
				}
				$current_chunk = (int) ( ( $existing_data['tables']['db_tables'][ $current_table ]['rows_processed'] ?? 0 ) / $this->tailwatch_db_chunk_size( $current_table, $existing_data ) );

				$cancel_pause = $backup_controller->tailwatch_backup_cancel_pause_data();
				if ( isset( $cancel_pause['scan_state'] ) && in_array( $cancel_pause['scan_state'], array( 'failed', 'gone' ), true ) ) {
					return; // marked failed by another process, or replaced by another run
				}
				if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
					$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
					return;
				}
				$this->process_manager->heart_beat( $process_id );
			}

			$this->process_manager->heart_beat( $process_id );
			$backup_controller->tailwatch_backup_function_complete();
		} catch ( \Throwable $e ) {
			if ( ! empty( $process_id ) ) {
				$this->process_manager->mark_failed( $process_id, $e->getMessage() );
			}
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'backup',
					'action'    => 'backup_db_backup_failed',
					'exception' => $e,
				)
			);
		} finally {
			BackupController::tailwatch_clear_run_owner();
			BackupController::tailwatch_forget_cached_schedule();
		}
	}

	/**
	 * The size a scan-driven run's database parts are fixed at.
	 *
	 * Their dumps are uploaded to the malware scan service, so their shape is part of an
	 * external contract rather than something the Archive Part Size setting should move.
	 */
	const SCAN_DUMP_PART_BYTES = 52428800;

	/**
	 * The largest a database part may become, whatever the setting says.
	 *
	 * A destination on restricted hosting slices uploads at chunk_bytes(), which floors at
	 * 64 KB, and accepts at most MAX_CHUNKS of them - about 512 MB in total. A part above
	 * that cannot be carried by hand at all, so this stays comfortably beneath it.
	 */
	const MAX_DUMP_PART_BYTES = 471859200;

	/**
	 * Smallest and largest row counts an adapted chunk may take.
	 *
	 * The floor keeps one slow measurement from reducing a table to a crawl. The ceiling
	 * is a memory bound: rows are held in an array before being written, and the byte cap
	 * below is what usually binds first anyway.
	 */
	const CHUNK_ROWS_MIN = 50;
	const CHUNK_ROWS_MAX = 5000;

	/**
	 * Bytes of generated SQL a chunk aims to produce. Matches the AVG_ROW_LENGTH
	 * budget in tailwatch_db_chunk_size(), so both sizing paths agree.
	 */
	const CHUNK_TARGET_BYTES = 4194304;

	/**
	 * Fraction of the remaining tick a chunk may plan to consume.
	 */
	const CHUNK_TARGET_FRACTION = 0.6;

	/**
	 * Below this, a measurement is noise rather than a rate.
	 */
	const CHUNK_MIN_MEASURABLE = 0.05;

	/**
	 * Resize the next chunk from the throughput this one actually achieved.
	 *
	 * The starting size comes from AVG_ROW_LENGTH, which is a guess about the DATA and
	 * says nothing about the host: the same table dumps at wildly different rates on a
	 * contended shared box and a dedicated one. Measuring the previous chunk is the only
	 * way to find out which we are on.
	 *
	 * Three rules keep the estimate honest:
	 *
	 * 1. When the clock has already run out, the chunk SHRINKS. Running out of clock is
	 *    evidence the chunk was too big; growing it would make the next tick run out too.
	 * 2. It aims at a fraction of the remaining budget, never all of it, leaving room for
	 *    the write to land.
	 * 3. An unmeasurably fast chunk teaches nothing, so it changes nothing. Flooring its
	 *    duration instead would inflate the computed rate by up to 100x and pin the size
	 *    at maximum.
	 *
	 * Rows are also the wrong unit on their own — a rate learned on a narrow table would
	 * be applied to `wp_postmeta` longtext — so the result is additionally capped by bytes.
	 *
	 * @param array $table_ref  Per-table state, by reference.
	 * @param int   $rows_done  Rows returned by the chunk just written.
	 * @param float $elapsed    Seconds it took.
	 * @param int   $bytes      Bytes of SQL it produced.
	 * @return void
	 */
	private function tailwatch_adapt_chunk_size( &$table_ref, $rows_done, $elapsed, $bytes ) {
		$current = isset( $table_ref['chunk_size'] ) ? (int) $table_ref['chunk_size'] : self::CHUNK_ROWS_MIN;

		if ( $rows_done < 1 ) {
			return;
		}

		$remaining = ( $this->db_cron_deadline > 0 ) ? ( $this->db_cron_deadline - time() ) : 0;

		if ( $this->db_cron_deadline > 0 && $remaining <= 0 ) {
			// Out of clock. Halve rather than reach for the maximum.
			$table_ref['chunk_size'] = (int) max( self::CHUNK_ROWS_MIN, floor( $current / 2 ) );
			return;
		}

		if ( $elapsed < self::CHUNK_MIN_MEASURABLE ) {
			return;
		}

		// Without a deadline (the size-backstop-only path) assume a modest budget rather
		// than an unbounded one.
		$budget = ( $remaining > 0 ) ? (float) $remaining : 10.0;
		$target = (int) floor( ( $rows_done / $elapsed ) * $budget * self::CHUNK_TARGET_FRACTION );

		if ( $bytes > 0 ) {
			$per_row = max( 1.0, $bytes / $rows_done );
			$target  = (int) min( $target, floor( self::CHUNK_TARGET_BYTES / $per_row ) );
		}

		// A size that once killed a tick stays out of reach. Measuring the chunk that came
		// AFTER a shrink says the smaller size was affordable — it says nothing about the
		// larger one, and without this the rate calculation walks straight back up to it.
		$ceiling = isset( $table_ref['chunk_ceiling'] ) ? (int) $table_ref['chunk_ceiling'] : self::CHUNK_ROWS_MAX;

		$table_ref['chunk_size'] = (int) max( self::CHUNK_ROWS_MIN, min( $ceiling, self::CHUNK_ROWS_MAX, $target ) );
	}

	/**
	 * Step a table's chunk size down after a tick died on it, and cap it there.
	 *
	 * The mirror of what TickBudget does with time: a kill is evidence, and the only
	 * evidence a fatal ever leaves. The measured path in tailwatch_adapt_chunk_size()
	 * cannot see this at all, because it only ever runs on a chunk that SUCCEEDED.
	 *
	 * @param array $table_ref Per-table state, by reference.
	 * @return void
	 */
	private function tailwatch_ratchet_chunk_size( &$table_ref ) {
		$current = isset( $table_ref['chunk_size'] ) ? (int) $table_ref['chunk_size'] : self::CHUNK_ROWS_MAX;
		$reduced = (int) max( self::CHUNK_ROWS_MIN, floor( $current / 2 ) );

		$table_ref['chunk_size']    = $reduced;
		$table_ref['chunk_ceiling'] = isset( $table_ref['chunk_ceiling'] )
			? (int) min( (int) $table_ref['chunk_ceiling'], $reduced )
			: $reduced;
	}

	/**
	 * Rows per chunk for a table (computed once, cached). 500 by default; shrinks toward 1 for
	 * big-row tables so one chunk's read + hex-doubled SQL stays near ~4MB. Unknown/<=500-row
	 * tables stay at 500. The cursor math is size-parameterized, so a smaller
	 * chunk is just more, smaller INSERTs of the same data (restore-safe).
	 *
	 * @param string $current_table Table.
	 * @param array  $existing_data Run state (by reference; caches the result).
	 * @return int Rows per chunk (1..500).
	 */
	private function tailwatch_db_chunk_size( $current_table, &$existing_data ) {
		$ref = &$existing_data['tables']['db_tables'][ $current_table ];
		if ( isset( $ref['chunk_size'] ) ) {
			return (int) $ref['chunk_size'];
		}
		// Rows gate: only shrink when the exact count > 500. AVG_ROW_LENGTH is noisy for tiny tables
		// (InnoDB rounds to a 16KB page, so a 1-row table reports a 16KB "average" -> false shrink).
		// total_rows is shared with the chunk loop's cache -> one COUNT per table.
		if ( ! isset( $ref['total_rows'] ) ) {
			$ref['total_rows'] = $this->backup_model->get_table_row_count(
				$current_table,
				$this->backup_model->is_options_table( $current_table )
			);
		}
		if ( (int) $ref['total_rows'] <= 500 ) {
			$ref['chunk_size'] = 500;
			return 500;
		}
		$avg  = (int) $this->backup_model->get_table_avg_row_length( $current_table ); // bytes; 0 if unknown
		$size = ( $avg > 0 ) ? (int) floor( ( 4 * 1024 * 1024 ) / $avg ) : 500;        // ~4MB of row data per chunk
		$size = max( 1, min( 500, $size ) );
		$ref['chunk_size'] = $size;
		return $size;
	}

	public function tailwatch_backup_table_in_chunks( $current_table, $database_backup, &$existing_data, $backup_controller, $current_chunk = 0 ) {
		// Backstop only — the ~20s deadline below is the primary pacer. SQL is streamed to disk per
		// chunk (FILE_APPEND) so a bigger per-cron volume isn't held in memory; 50MB matches the
		// .sql part size, so a cap-bounded cron is ~one part.
		$max_size_per_cron = 50 * 1024 * 1024;

		$chunk_size = $this->tailwatch_db_chunk_size( $current_table, $existing_data );

		$is_options_table = $this->backup_model->is_options_table( $current_table );
		$table_ref        = &$existing_data['tables']['db_tables'][ $current_table ];

		// Per-table pagination plan + row count, computed ONCE and cached in the run state.
		// pk_info chooses keyset paging (single non-binary PK — skip/dup-safe + O(N)) vs the
		// offset+ORDER BY fallback.
		if ( ! isset( $table_ref['pk_info'] ) ) {
			$table_ref['pk_info'] = $this->backup_model->get_table_primary_key( $current_table );
		}
		if ( ! isset( $table_ref['total_rows'] ) ) {
			$table_ref['total_rows'] = $this->backup_model->get_table_row_count( $current_table, $is_options_table );
		}
		$pk_info      = $table_ref['pk_info'];
		$total_rows   = (int) $table_ref['total_rows'];
		$total_chunks = max( 1, (int) ceil( $total_rows / $chunk_size ) );
		$use_keyset   = ( isset( $pk_info['mode'] ) && 'keyset' === $pk_info['mode'] );

		// A table that fits in one chunk gets a single line, once it is done. A larger one is
		// announced, then reported in rows: the row total is fixed, while the chunk count
		// moves whenever the chunk size adapts and could overshoot its own total.
		if ( $total_rows > $chunk_size && empty( $table_ref['announced'] ) ) {
			$table_ref['announced'] = true;
			$backup_controller->update_logs_records( 'Backing up ' . $current_table . ' (' . BackupController::tailwatch_count( $total_rows, 'row' ) . ')' );
		}

		// Keyset resumes from the stored last PK (skip/dup-safe, O(N)); the fallback uses
		// offset + a deterministic ORDER BY. Both return the same ARRAY_A row shape, so
		// generate_sql() and the .sql output/restore contract are the same for either path.
		global $wpdb;
		$chunk_started = microtime( true );
		if ( $use_keyset ) {
			$cursor = isset( $table_ref['last_pk'] ) ? $table_ref['last_pk'] : null;
			$data   = $this->backup_model->get_table_data_keyset( $current_table, $pk_info['col'], ! empty( $pk_info['int'] ), $cursor, $chunk_size, $is_options_table );
		} else {
			$offset     = $current_chunk * $chunk_size;
			$order_cols = isset( $pk_info['cols'] ) ? $pk_info['cols'] : array();
			$data       = $this->backup_model->get_table_data_chunk( $current_table, $offset, $chunk_size, $is_options_table, $order_cols );
		}
		$read_error = $wpdb->last_error; // capture the fetch's error NOW (the table_insert write below would overwrite it)

		$rows_in_this_chunk         = is_array( $data ) ? count( $data ) : 0;
		$table_ref['rows_exported'] = ( isset( $table_ref['rows_exported'] ) ? (int) $table_ref['rows_exported'] : 0 ) + $rows_in_this_chunk;

		// 0 rows + a DB error = unreadable/crashed/dropped table (a genuinely empty one has NO
		// error). Warn and skip (short-page completion below marks it done) rather than silently
		// emit an empty table, so an incomplete backup is visible. Always on; only on a real error.
		if ( '' !== (string) $read_error ) {
			// Flag the table itself so finalize can report it. The table is still marked
			// complete below (otherwise the worker would retry it forever), so without
			// this the run would end "Database backup complete / SUCCESS" having silently
			// dropped a table.
			$table_ref['read_failed'] = true;
			$table_ref['read_error']  = (string) $read_error;

			if ( 0 === $rows_in_this_chunk ) {
				$backup_controller->update_logs_records( 'Could not read ' . $current_table . ' (possibly corrupted): ' . $read_error . ' — skipping this table', 'WARNING' );
			} else {
				// Rows came back AND the driver reported an error, so the page is short
				// and this table's dump is missing rows; it must not pass as a clean export.
				$backup_controller->update_logs_records( 'Partial read of ' . $current_table . ' (' . $rows_in_this_chunk . ' rows returned alongside an error): ' . $read_error, 'ERROR' );
			}
		}

		if ( false === $table_ref['table_insert'] ) {
			$is_table_insert        = true;
			$table_ref['table_insert'] = true;
			$backup_controller->update_backup_data( $existing_data );
		} else {
			$is_table_insert = false;
		}

		$sql_size = $this->generate_sql( $current_table, $data, $is_table_insert, $existing_data, $database_backup, $backup_controller );

		// Advance the keyset cursor to the last row's PK value (generate_sql does not mutate
		// $data). Persisted with the rest of the state below → correct cross-tick resume.
		if ( $use_keyset && $rows_in_this_chunk > 0 ) {
			$last_row = $data[ $rows_in_this_chunk - 1 ];
			if ( is_array( $last_row ) && isset( $last_row[ $pk_info['col'] ] ) ) {
				$table_ref['last_pk'] = $last_row[ $pk_info['col'] ];
			}
		}

		$existing_data['tables']['db_size_process'] = ( isset( $existing_data['tables']['db_size_process'] ) ? $existing_data['tables']['db_size_process'] : 0 ) + $sql_size;
		$table_ref['rows_processed']                = ( $current_chunk + 1 ) * $chunk_size;

		// Size the NEXT chunk from what this one actually cost. Only in keyset mode:
		// the offset fallback derives its position as chunk_index * chunk_size, so a
		// size that changes mid-table would move rows under its own cursor.
		if ( $use_keyset ) {
			$this->tailwatch_adapt_chunk_size( $table_ref, $rows_in_this_chunk, microtime( true ) - $chunk_started, (int) $sql_size );
		}

		// Completion: keyset finishes when a short page arrives (robust to live row-count
		// drift); the offset fallback uses the precomputed chunk count.
		$is_complete = $use_keyset ? ( $rows_in_this_chunk < $chunk_size ) : ( ( $current_chunk + 1 ) >= $total_chunks );
		$exported_total = isset( $table_ref['rows_exported'] ) ? (int) $table_ref['rows_exported'] : 0;
		if ( $is_complete ) {
			$table_ref['completed'] = true;
			$backup_controller->update_logs_records( 'Completed ' . $current_table . ' (' . BackupController::tailwatch_count( $exported_total, 'row' ) . ')', 'OK' );
		} elseif ( $exported_total - (int) ( $table_ref['logged_rows'] ?? 0 ) >= max( $chunk_size, (int) ceil( $total_rows / 20 ) ) ) {
			// A line per chunk, or about twenty for a table with many.
			$table_ref['logged_rows'] = $exported_total;
			$backup_controller->update_logs_records( 'Exported ' . number_format_i18n( $exported_total ) . ' of ' . BackupController::tailwatch_count( max( $total_rows, $exported_total ), 'row' ) . ' from ' . $current_table );
		}

		$backup_controller->update_backup_data( $existing_data );

		// Yield when either budget hits: the ~20s deadline (primary) or the 50MB backstop.
		// deadline 0 (unset) -> size-backstop only.
		$time_budget_hit = ( $this->db_cron_deadline > 0 && time() >= $this->db_cron_deadline );
		if ( $existing_data['tables']['db_size_process'] >= $max_size_per_cron || $time_budget_hit ) {
			$existing_data['tables']['db_size_process'] = 0;
			$backup_controller->update_backup_data( $existing_data );
			if ( ! $backup_controller->tailwatch_run_is_current() ) {
				return false;
			}
			// Stopped during these chunks: no further step. A resume arms it again.
			if ( ! $backup_controller->tailwatch_run_may_continue() ) {
				$cancel_pause = $backup_controller->tailwatch_backup_cancel_pause_data();
				if ( in_array( $cancel_pause['scan_state'] ?? '', array( 'cancel', 'pause' ), true ) ) {
					$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				}
				return false;
			}
			wp_schedule_single_event( time(), 'tailwatch_create_db_backup_cron' );
			// Only ever after the event is scheduled — see CronKick.
			CronKick::accelerate();
			return false;
		}

		return true;
	}

	public function handle_backup_completion( &$existing_data, $backup_controller, $process_id ) {
		$cancel_pause = $backup_controller->tailwatch_backup_cancel_pause_data();
		if ( isset( $cancel_pause['scan_state'] ) && 'gone' === $cancel_pause['scan_state'] ) {
			return false;
		}

		// "Any incomplete table left?" — INCLUDING the current one if it still has chunks pending.
		// Looking only at tables AFTER the current one would mark a multi-chunk LAST table complete
		// after its first chunk and silently truncate it. Mirrors the cron-entry pick.
		$has_more_tables = $this->get_first_incomplete_table( $existing_data['tables']['db_tables'] );

		if ( $has_more_tables ) {
			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				return false;
			}

			// More chunks remain → tell the caller's loop to pack the next one; the loop keeps
			// the stack flat. Yielding is owned by the chunk method, which reschedules the next
			// cron and returns false when its size or time budget is hit.
			return true;
		}

		$backup_controller->update_logs_records( 'Database backup complete', 'SUCCESS' );

		$existing_data['tables']['completed'] = true;
		$backup_controller->update_backup_data( $existing_data );

		if ( self::tailwatch_run_has_files( $existing_data ) ) {
			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				return false;
			}
			wp_schedule_single_event( time(), 'tailwatch_backup_daily_scan' );
			CronKick::accelerate();
		} else {
			// Nothing follows the dump, so the run ends here and is finalised the same way a
			// run with files is: the summary, the completion marker a restore requires, and
			// the completion notification.
			$backup_controller->check_and_finalize_backup( $existing_data, null, '', '', $process_id );
		}

		return false;
	}

	/**
	 * Whether this run archives files after its database dump.
	 *
	 * Read from the run's own state, not the current setting, which can change while a
	 * backup runs. The setting is only the fallback for a run recorded without a type.
	 *
	 * @param array $existing_data Backup state.
	 * @return bool
	 */
	public static function tailwatch_run_has_files( array $existing_data ) {
		$type = isset( $existing_data['backupType'] ) ? (string) $existing_data['backupType'] : '';
		if ( '' === $type ) {
			$settings = ( new BackupMaintainController() )->tailwatch_get_backup_settings();
			$type     = isset( $settings['backupType'] ) ? (string) $settings['backupType'] : '';
		}

		return 'Complete Backup' === $type;
	}

	public function cancel_or_pause_backup( $cancel_pause, $backup_controller ) {
		$timestamp = wp_next_scheduled( 'tailwatch_create_db_backup_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'tailwatch_create_db_backup_cron' );
		}

		if ( true === $cancel_pause['cron_running'] ) {
			$cancel_pause['cron_running'] = false;
			$backup_controller->update_backup_cancel_pause( $cancel_pause );
		}
	}

	public function get_first_incomplete_table( $tables ) {
		foreach ( $tables as $table => $data ) {
			if ( ! $data['completed'] ) {
				return $table;
			}
		}
		return null;
	}

	public function create_backup_directories( $backup_directory, $folder_date ) {
		if ( ! is_dir( $backup_directory ) ) {
			// Seal the backup root with deny files so archives are not reachable over the web.
			SecureDirectoryService::ensure_private_root( $backup_directory );
		}

		// Seal every level, not just the root — see the matching note in
		// BackupController::…backup_daily_scan(). The SQL dump contains user rows
		// and password hashes, so this directory is the most sensitive of the tree.
		$db_directory = $backup_directory . 'database/';
		if ( ! file_exists( $db_directory ) ) {
			wp_mkdir_p( $db_directory );
		}
		SecureDirectoryService::ensure( $db_directory, true );

		$database_backup = $db_directory . $folder_date . '/';
		if ( ! file_exists( $database_backup ) ) {
			wp_mkdir_p( $database_backup );
		}
		SecureDirectoryService::ensure( $database_backup, true );
	}

	/**
	 * Resolve the on-disk name for a SQL dump, given its path without an extension.
	 *
	 * The contents are plaintext SQL written with FILE_APPEND, and the name carries a
	 * `.zip` suffix anyway. That looks wrong until you try to move a backup: a great many
	 * hosts, CDNs and security layers refuse to serve or accept a bare `.sql` download,
	 * and some strip it silently rather than erroring. The suffix is what gets a database
	 * part off one site and onto another - by download, by upload, or by a site-to-site
	 * transfer - so it is a compatibility measure, not a mistake.
	 *
	 * Nothing inside this plugin is misled by it: dumps are located from the marker's
	 * recorded names rather than by extension, the importer opens them as plain files, and
	 * verification hashes a database part with hash_file() instead of trying to open an
	 * archive.
	 *
	 * Genuine compression is not an option: the restore importer resumes by seeking to a
	 * recorded BYTE OFFSET, and a gzip stream cannot be seeked that way, so compressing
	 * here would lose resumable imports on exactly the large databases that need them.
	 *
	 * BOTH names are read. An existing file always wins, in either form: a backup that was
	 * mid-flight when the plugin updated would otherwise switch names between two ticks
	 * and split one dump across two files, with the state row pointing at only the second
	 * half. A dump already written under the plain `.sql` name is continued as it is.
	 *
	 * @param string $base     Absolute path with no extension.
	 * @param string $recorded Path already recorded for this part, if any.
	 * @return string
	 */
	private function tailwatch_dump_path( $base, $recorded = '' ) {
		if ( is_string( $recorded ) && '' !== $recorded && file_exists( $recorded ) ) {
			return $recorded;
		}
		if ( file_exists( $base . '.sql.zip' ) ) {
			return $base . '.sql.zip';
		}
		// A dump started under the plain name is continued as it is rather than started
		// again under the other name.
		if ( file_exists( $base . '.sql' ) ) {
			return $base . '.sql';
		}

		return $base . '.sql.zip';
	}

	/**
	 * Get the list of tables to back up.
	 *
	 * When $malware_scan_only is true, fires the 'tailwatch_malware_scan_tables' filter
	 * so the pro plugin can supply the malware-scan-targeted table list. That list never
	 * widens: an empty or unusable answer (e.g. pro plugin inactive) is an empty list,
	 * because every table would take users, sessions and this plugin's own settings into
	 * a dump that is uploaded for scanning.
	 *
	 * @param bool $malware_scan_only When true, asks pro plugin for the targeted table list.
	 * @return array List of table names.
	 */
	public function get_tables_list( $malware_scan_only = false ) {
		if ( ! $malware_scan_only ) {
			return $this->backup_model->get_database_tables();
		}

		$tables = apply_filters( 'tailwatch_malware_scan_tables', array() );
		if ( ! is_array( $tables ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$tables,
				function ( $table ) {
					return is_string( $table ) && '' !== $table;
				}
			)
		);
	}

	/**
	 * Generate SQL for one table and append it to the parent-tables backup file.
	 *
	 * Writes the CREATE TABLE schema (when $is_table_insert is true) and any
	 * accumulated INSERT statements to an on-disk SQL file using PHP's native
	 * file_put_contents() with the FILE_APPEND flag.
	 *
	 * ## Why this method does NOT use WP_Filesystem
	 *
	 * WP_Filesystem's put_contents() has no append mode. The closest equivalent
	 * would be read-all + concatenate + write-all on every call — which means
	 * loading the entire backup SQL file into PHP memory for each schema/INSERT
	 * write. For a typical WordPress backup the parent-tables SQL file grows to
	 * tens or hundreds of megabytes, and large stores reach multiple gigabytes;
	 * the read-modify-write pattern would peak at 2× file size in RAM per write
	 * and quickly exhaust PHP memory_limit, breaking backups on real sites.
	 *
	 * Native FILE_APPEND streams the new bytes directly to disk without holding
	 * the prior content in memory. The phpcs:ignore on the file_put_contents
	 * call below documents the same rationale for static analyzers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table_name        Table to back up.
	 * @param array  $data              Accumulated row data for this table.
	 * @param bool   $is_table_insert   Whether to also emit the CREATE TABLE schema.
	 * @param array  $existing_data     Backup state (by reference).
	 * @param string $database_backup   Backup directory path.
	 * @param object $backup_controller BackupController instance.
	 * @return int   Total bytes written.
	 */
	public function generate_sql( $table_name, $data, $is_table_insert, &$existing_data, $database_backup, $backup_controller ) {
		$sql        = '';
		$total_size = 0;

		// Add table schema if requested (CREATE TABLE).
		if ( $is_table_insert ) {
			$table_schema = $this->backup_model->get_table_schema( $table_name );
			if ( $table_schema ) {
				$sql         = $table_schema . ";\n\n";
				$parent_file = $this->tailwatch_dump_path(
					$database_backup . "db_parent_tables_{$existing_data['zipId']}",
					isset( $existing_data['tables']['path'] ) ? (string) $existing_data['tables']['path'] : ''
				);
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- FILE_APPEND streaming write to incrementally-built backup SQL file; WP_Filesystem::put_contents does not support append mode and would require reading the entire file into memory each call, breaking with large databases.
				if ( false === file_put_contents( $parent_file, $sql, FILE_APPEND ) ) {
					Log::error(
						'Failed to write schema for ' . $table_name . ' to ' . $parent_file,
						array(
							'feature' => 'backup',
							'action'  => 'db_schema_write_failed',
						)
					);
				}
				$total_size += strlen( $sql );
			} else {
				Log::error(
					'Failed to get schema for ' . $table_name . ': ' . $this->backup_model->get_last_error(),
					array(
						'feature' => 'backup',
						'action'  => 'db_schema_fetch_failed',
					)
				);
			}
		}

		if ( ! empty( $data ) ) {
			// Get column definitions for type-specific handling (int/binary/string).
			$table_structure = $this->backup_model->get_table_structure( $table_name );
			$integer_fields  = array();
			$binary_fields   = array();
			foreach ( $table_structure as $struct ) {
				$field = strtolower( $struct->Field );
				if ( preg_match( '/^(tiny|small|medium|big)?int/i', $struct->Type ) ) {
					$integer_fields[ $field ] = true;
				} elseif ( preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob)/i', $struct->Type ) ) {
					$binary_fields[ $field ] = true;
				}
			}

			$columns       = array_keys( $data[0] );
			// Serializer for the .sql dump: the INSERT statements built here are written
			// to a .sql backup file (via tailwatch_save_db_in_file below) and are never
			// executed through $wpdb, so wpdb::prepare()/%i do not apply. $table_name and
			// $columns are schema-derived; identifiers are backtick-quoted, with any
			// backtick inside a name doubled.
			$escaped_table   = str_replace( '`', '``', $table_name );
			$escaped_columns = array_map(
				static function ( $column ) {
					return '`' . str_replace( '`', '``', $column ) . '`';
				},
				$columns
			);
			$insert_header = 'INSERT INTO `' . $escaped_table . '` (' . implode( ', ', $escaped_columns ) . ') VALUES ';

			$values  = array();
			$search  = array( "\x00", "\x0a", "\x0d", "\x1a" ); // Special characters.
			$replace = array( '\0', '\n', '\r', '\Z' );

			foreach ( $data as $row ) {
				$row_values = array_map(
					function ( $value, $key ) use ( $integer_fields, $binary_fields, $search, $replace ) {
						$key = strtolower( $key );
						if ( is_null( $value ) ) {
							return 'NULL';
						}
						if ( isset( $integer_fields[ $key ] ) ) {
							return ( '' === $value ) ? "''" : $value; // Empty string to '' for integers.
						}
						if ( isset( $binary_fields[ $key ] ) ) {
							if ( '' === $value ) {
								return "''";
							}
							return '0x' . bin2hex( $value ); // Binary data as hex.
						}
						// String escaping: backslashes, single quotes, then special chars.
						$value = str_replace( '\\', '\\\\', $value );
						$value = str_replace( "'", "\\'", $value );
						$value = str_replace( $search, $replace, $value );
						return "'" . $value . "'";
					},
					array_values( $row ),
					array_keys( $row )
				);
				$values[]   = '(' . implode( ', ', $row_values ) . ')';
			}

			$chunk_max_rows = 250;
			$chunk_max_size = 1048576; // 1MB.
			$current_chunk  = array();
			$chunk_buffer   = '';

			foreach ( $values as $row ) {
				$row_length = strlen( $row );

				if ( $row_length >= $chunk_max_size ) {
					// Flush current chunk if any.
					if ( ! empty( $current_chunk ) ) {
						$chunk_sql = $insert_header . implode( ",\n", $current_chunk ) . ";\n";
						$this->tailwatch_save_db_in_file( $database_backup, $table_name, $chunk_sql, $existing_data, $backup_controller );
						$total_size   += strlen( $chunk_sql );
						$current_chunk = array();
					}
					// Write oversized row separately (one row per chunk).
					$chunk_sql = $insert_header . $row . ";\n";
					$this->tailwatch_save_db_in_file( $database_backup, $table_name, $chunk_sql, $existing_data, $backup_controller );
					$total_size += strlen( $chunk_sql );
				} else {
					$current_chunk_size = strlen( $chunk_buffer . ( $chunk_buffer ? ",\n" : '' ) . $row );
					if ( count( $current_chunk ) >= $chunk_max_rows || $current_chunk_size > $chunk_max_size ) {
						$chunk_sql = $insert_header . implode( ",\n", $current_chunk ) . ";\n";
						$this->tailwatch_save_db_in_file( $database_backup, $table_name, $chunk_sql, $existing_data, $backup_controller );
						$total_size   += strlen( $chunk_sql );
						$current_chunk = array();
						$chunk_buffer  = '';
					}
					$current_chunk[] = $row;
					$chunk_buffer   .= ( $chunk_buffer ? ",\n" : '' ) . $row;
				}
			}

			// Flush remaining chunk.
			if ( ! empty( $current_chunk ) ) {
				$chunk_sql = $insert_header . implode( ",\n", $current_chunk ) . ";\n";
				$this->tailwatch_save_db_in_file( $database_backup, $table_name, $chunk_sql, $existing_data, $backup_controller );
				$total_size += strlen( $chunk_sql );
			}
		}

		return $total_size;
	}

	/**
	 * How large a database part may grow, in bytes.
	 *
	 * Pro's malware importer streams a part rather than reading it whole, so the part size
	 * does not bound its memory.
	 *
	 * A normal backup follows the run's Archive Part Size, so one setting governs how many
	 * files a migration comes to rather than the archives and the database disagreeing. A
	 * scan-driven run keeps the fixed size; its dumps go to the scan service.
	 *
	 * Bounded at both ends regardless of the setting: never smaller than the fixed size, and
	 * never above what a destination can still accept by hand.
	 *
	 * @param array $existing_data Backup state.
	 * @param bool  $scan_driven   Whether this run feeds the scan service.
	 * @return int Bytes.
	 */
	private function tailwatch_dump_part_size( array $existing_data, $scan_driven ) {
		if ( $scan_driven ) {
			return self::SCAN_DUMP_PART_BYTES;
		}

		$size = isset( $existing_data['part_size'] ) ? (int) $existing_data['part_size'] : 0;

		if ( $size < self::SCAN_DUMP_PART_BYTES ) {
			$size = self::SCAN_DUMP_PART_BYTES;
		}

		return min( $size, self::MAX_DUMP_PART_BYTES );
	}

	/**
	 * Appends SQL to a part file (creates new part when size limit reached).
	 *
	 * ## Why this method does NOT use WP_Filesystem
	 *
	 * Same rationale as generate_sql() above: this is a streaming append to an
	 * incrementally-built backup SQL part file. WP_Filesystem::put_contents
	 * has no append mode, so the only WP_Filesystem-compliant alternative
	 * would be read-all + concatenate + write-all on every call. Backup part
	 * files routinely grow to hundreds of MB before the size cap triggers a
	 * new part; doing a full read-modify-write per INSERT chunk would peak
	 * at 2× file size in PHP memory and exhaust memory_limit on real sites.
	 * Native FILE_APPEND streams to disk without holding prior content in RAM.
	 *
	 * @param string $database_backup  Database backup directory path.
	 * @param string $table_name      Table name.
	 * @param string $sql             SQL to append.
	 * @param array  $existing_data   Backup state (by reference).
	 * @param object $backup_controller BackupController instance.
	 * @return bool True on success.
	 */
	public function tailwatch_save_db_in_file( $database_backup, $table_name, $sql, &$existing_data, $backup_controller ) {

		if ( empty( $sql ) ) {
			return false;
		}

		$sql_size = strlen( $sql );

		// Parts roll over ACROSS tables rather than starting a new file for each one, so the
		// file count follows the size of the data instead of the number of tables. One file
		// per table would make the database the bulk of a migration's parts while most of
		// those files would hold only a few kilobytes.
		//
		// Correct because every CREATE TABLE is written to the parent schema file, which the
		// restore imports first: INSERTs for different tables may therefore share a part, and
		// the order the parts are applied in does not matter.
		//
		// A SCAN-DRIVEN run keeps one file per table. Its dumps are uploaded to the malware
		// scan service, which depends on the per-table dump names, and the archive packer uses
		// the per-table layout for these runs too. Normal backups roll over; scans do not.
		$scan_driven = isset( $existing_data['process_run'] )
			&& in_array( (string) $existing_data['process_run'], BackupMaintainController::SCAN_DRIVEN_PROCESS_RUNS, true );

		$max_file_size = $this->tailwatch_dump_part_size( $existing_data, $scan_driven );

		if ( $scan_driven ) {
			// One file per table, a new one only when THAT table passes the cap.
			$part = 1;
			while (
				isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] ) &&
				! empty( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size_limit_reached'] )
			) {
				++$part;
			}

			$recorded         = isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] ) ? (string) $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] : '';
			$backup_file_path = $this->tailwatch_dump_path( "{$database_backup}db_{$table_name}_part_{$part}_{$existing_data['zipId']}", $recorded );
		} else {
			$part = isset( $existing_data['tables']['rows_part'] ) ? max( 1, (int) $existing_data['tables']['rows_part'] ) : 1;

			$recorded         = isset( $existing_data['tables']['rows_paths'][ "part_$part" ] ) ? (string) $existing_data['tables']['rows_paths'][ "part_$part" ] : '';
			$backup_file_path = $this->tailwatch_dump_path( "{$database_backup}db_rows_part_{$part}_{$existing_data['zipId']}", $recorded );
		}

		// Taken from disk rather than from the row: a tick killed between the append and the
		// state write would leave a counter behind the file, and every later part would then
		// overshoot the cap by whatever was lost.
		//
		// clearstatcache() is not optional. One tick appends many times, and filesize() is
		// served from PHP's stat cache - without this the size read back is whatever it was
		// on the first call of the request, the cap is never seen to be reached, and a part
		// grows without limit.
		clearstatcache( true, $backup_file_path );
		$current_size = file_exists( $backup_file_path ) ? (int) filesize( $backup_file_path ) : 0;

		// Checked BEFORE appending a whole block, so a part boundary always falls between
		// statements and never splits one.
		if ( $current_size > 0 && ( $current_size + $sql_size ) > $max_file_size ) {
			if ( $scan_driven ) {
				$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size_limit_reached'] = true;
			}
			++$part;

			$stem             = $scan_driven ? "db_{$table_name}_part_{$part}" : "db_rows_part_{$part}";
			$recorded         = $scan_driven
				? ( isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] ) ? (string) $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] : '' )
				: ( isset( $existing_data['tables']['rows_paths'][ "part_$part" ] ) ? (string) $existing_data['tables']['rows_paths'][ "part_$part" ] : '' );
			$backup_file_path = $this->tailwatch_dump_path( "{$database_backup}{$stem}_{$existing_data['zipId']}", $recorded );
			clearstatcache( true, $backup_file_path );
			$current_size     = file_exists( $backup_file_path ) ? (int) filesize( $backup_file_path ) : 0;
		}

		if ( ! $scan_driven ) {
			$existing_data['tables']['rows_part']                  = $part;
			$existing_data['tables']['rows_paths'][ "part_$part" ] = $backup_file_path;
		}

		// The per-table map is kept, with several tables naming the same file. Pro's restore
		// walks it to find the parts holding a given table, and its importer scans a part for
		// `INSERT INTO `<table>`` rather than assuming a file belongs to one table.
		// BackupReconciler array_unique()s the marker's file list, so shared paths collapse
		// to a single entry there.
		if ( ! isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] ) ) {
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] = array(
				'path'               => $backup_file_path,
				'size'               => 0,
				'size_limit_reached' => false,
				// The status lets tailwatch_collect_failed_parts() see a failed dump, so the
				// backup cannot report success over it.
				'status'             => 'in-progress',
			);
		} else {
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] = $backup_file_path;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- FILE_APPEND streaming write to incrementally-built backup SQL file; WP_Filesystem::put_contents does not support append mode and would require reading the entire file into memory each call, breaking with large databases.
		$result = file_put_contents( $backup_file_path, $sql, FILE_APPEND );
		if ( false === $result ) {
			Log::error(
				'Failed to write to file: ' . $backup_file_path,
				array(
					'feature' => 'backup',
					'action'  => 'db_file_write_failed',
				)
			);
			// Record the failure on the part so finalize can see it. Forced past the
			// write throttle: we are about to bail out of this path entirely.
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['status'] = 'failed';
			$backup_controller->update_backup_data( $existing_data, false, true );
			return false;
		}

		// This table's own contribution to the shared part, not the part's total - the part
		// may hold other tables' rows too, and its size on disk is what the marker digests.
		$table_bytes = (int) ( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size'] ?? 0 );

		$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size'] = $table_bytes + $sql_size;
		// Drives part selection for a scan-driven run, where one file holds one table. A
		// rolled-over part is shared, so its own size is what the next append measures.
		$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size_limit_reached'] = $scan_driven
			? ( ( $current_size + $sql_size ) >= $max_file_size )
			: false;
		$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['status']             = 'completed';
		$backup_controller->update_backup_data( $existing_data );

		return true;
	}
}
