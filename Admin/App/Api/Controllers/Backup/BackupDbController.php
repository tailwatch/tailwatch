<?php
namespace Tailwatch\Admin\App\Api\Controllers\Backup;

use Tailwatch\Admin\App\Api\Controllers\Logs\LiveLogs\LiveLogsController;
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
			if ( ! $existing_data ) {
				$existing_data = array(
					'tables'        => array(),
					'current_table' => 0,
				);
			}

			if ( ! isset( $existing_data['tables'] ) ) {
				$is_malware_scan = isset( $existing_data['process_run'] ) && in_array( $existing_data['process_run'], array( 'malware', 'files_integrity' ));
				$tables          = $this->get_tables_list( $is_malware_scan );
				foreach ( $tables as $table ) {
					$existing_data['tables']['db_tables'][ $table ] = array(
						'completed'      => false,
						'rows_processed' => 0,
						'table_insert'   => false,
					);
				}
				$existing_data['tables']['db_size_process'] = 0;
				$existing_data['tables']['completed']       = false;
				$existing_data['tables']['path']            = $backup_directory . $folder_date . '/db_parent_tables_' . $sql_id . '.sql.zip';

				$backup_controller->update_logs_records( 'Starting database backup' );
				$table_label = $is_malware_scan ? 'malware-targeted tables' : 'tables';
				$backup_controller->update_logs_records( 'Found ' . count( $tables ) . ' ' . $table_label . ' to backup' );

				$db_data = array( 'value' => wp_json_encode( $existing_data ) );
				$feature_controller->update_recent_row( $db_data, $tailwatch_key, $option );
			}

			wp_schedule_single_event( time() + 5, 'tailwatch_create_db_backup_cron' );
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
		}
	}

	public function tailwatch_create_db_backup() {
		try {
			// Raise memory/time before dumping — large BLOB chunks are an OOM/timeout
			// source on small hosts. Runtime-only, idempotent, never lowers.
			( new PerformanceOptimizerController() )->tailwatch_boost_for_scanning();

			$feature_controller = new DBModel();
			$tailwatch_key           = 'default_backup_scan';
			$option             = 'scan_backp';

			$backup_controller = new BackupController();

			$existing_data = $backup_controller->tailwatch_get_scan_backup_data();
			$cancel_pause  = $backup_controller->tailwatch_backup_cancel_pause_data();

			// Terminal 'failed' run: bail BEFORE re-setting cron_running / re-activating the
			// process row below (this worker would otherwise actively undo mark_backup_failed).
			if ( isset( $cancel_pause['scan_state'] ) && 'failed' === $cancel_pause['scan_state'] ) {
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

				$get_backup_option = new BackupMaintainController();
				$backup_data       = $get_backup_option->tailwatch_get_backup_settings();
				$get_backup_type   = ! empty( $backup_data['backupType'] ) ? $backup_data['backupType'] : null;

				if ( 'Complete Backup' === $get_backup_type ) {
					wp_schedule_single_event( time() + 5, 'tailwatch_backup_daily_scan' );
				}
				$backup_controller->tailwatch_backup_function_complete();
				return;
			}

			$current_chunk   = (int) ( ( $existing_data['tables']['db_tables'][ $current_table ]['rows_processed'] ?? 0 ) / $this->tailwatch_db_chunk_size( $current_table, $existing_data ) );
			$database_backup = $backup_directory . 'database/' . $folder_date . '/';

			// ~20s yield deadline for this cron's chunk loop (paired with the size backstop below).
			$this->db_cron_deadline = time() + 20;

			// No-progress guard: a chunk killed mid-export (huge BLOB row vs host limit) leaves the
			// cursor unadvanced (last_pk/rows_processed update only AFTER generate_sql), so it would
			// re-export forever with no error. Count consecutive same-position crons — persist BEFORE
			// the chunk so a kill still counts — and fail after 3. Never fires on a progressing run.
			$db_position = $current_table . ':' . $current_chunk;
			$db_last     = isset( $existing_data['tables']['db_last_position'] ) ? $existing_data['tables']['db_last_position'] : '';
			$db_stuck    = isset( $existing_data['tables']['db_stuck_count'] ) ? (int) $existing_data['tables']['db_stuck_count'] : 0;
			$db_stuck    = ( $db_last === $db_position ) ? ( $db_stuck + 1 ) : 0;
			if ( $db_stuck >= 3 ) {
				$backup_controller->update_logs_records( "Database backup stalled on {$current_table} (chunk {$current_chunk}) — a row is likely too large to export within the host limit; aborting backup", 'ERROR' );
				$backup_controller->tailwatch_mark_backup_failed( "db_chunk_no_progress:{$current_table}:{$current_chunk}" );
				return;
			}
			$existing_data['tables']['db_last_position'] = $db_position;
			$existing_data['tables']['db_stuck_count']   = $db_stuck;
			$backup_controller->update_backup_data( $existing_data ); // persist BEFORE the chunk loop so a kill counts

			// Pack chunks in a flat loop (was per-chunk recursion — a wide table nested hundreds
			// deep) until a budget is hit or the backup finishes. Each pass re-picks the chunk from
			// the updated in-memory state and honors a mid-run cancel/pause/failed + heartbeat.
			while ( true ) {
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
				if ( isset( $cancel_pause['scan_state'] ) && 'failed' === $cancel_pause['scan_state'] ) {
					return; // another process marked the run failed — don't revive it
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
		}
	}

	/**
	 * Rows per chunk for a table (computed once, cached). 500 by default; shrinks toward 1 for
	 * big-row tables so one chunk's read + hex-doubled SQL stays near ~4MB. Unknown/<=500-row
	 * tables stay 500 -> byte-identical .sql. The cursor math is size-parameterized, so a smaller
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
		$size = max( 1, min( 500, $size ) );                                           // clamp [1, 500]
		$ref['chunk_size'] = $size;
		return $size;
	}

	public function tailwatch_backup_table_in_chunks( $current_table, $database_backup, &$existing_data, $backup_controller, $current_chunk = 0 ) {
		// Backstop only — the ~20s deadline below is the primary pacer. SQL is streamed to disk per
		// chunk (FILE_APPEND) so a bigger per-cron volume isn't held in memory; 50MB matches the
		// .sql part size, so a cap-bounded cron is ~one part.
		$max_size_per_cron = 50 * 1024 * 1024; // 50 MB backstop.

		$chunk_size = $this->tailwatch_db_chunk_size( $current_table, $existing_data );

		$is_options_table = $this->backup_model->is_options_table( $current_table );
		$table_ref        = &$existing_data['tables']['db_tables'][ $current_table ];

		// Per-table pagination plan + row count, computed ONCE and cached in the run state
		// (previously COUNT(*) ran every chunk). pk_info chooses keyset paging (single
		// non-binary PK — skip/dup-safe + O(N)) vs the offset+ORDER BY fallback.
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

		// Throttle progress for big tables: log the first part, ~every 5%, and the last (like the
		// file backup) so a many-part table makes ~20 lines, not one per 500 rows.
		$log_this_part = true;
		if ( $total_rows > $chunk_size ) {
			$part_number   = $current_chunk + 1;
			$progress_step = max( 10, (int) ceil( $total_chunks / 20 ) );
			$log_this_part = ( 1 === $part_number || $part_number >= $total_chunks || 0 === ( $part_number % $progress_step ) );
			if ( $log_this_part ) {
				$backup_controller->update_logs_records( 'Backing up ' . $current_table . ' (part ' . $part_number . '/' . $total_chunks . ')' );
			}
		} else {
			$backup_controller->update_logs_records( 'Backing up ' . $current_table );
		}

		// Keyset resumes from the stored last PK (skip/dup-safe, O(N)); the fallback uses
		// offset + a deterministic ORDER BY. Both return the same ARRAY_A row shape, so
		// generate_sql() below is identical and the .sql output/restore contract is unchanged.
		global $wpdb;
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
		if ( 0 === $rows_in_this_chunk && '' !== (string) $read_error ) {
			$backup_controller->update_logs_records( 'Could not read ' . $current_table . ' (possibly corrupted): ' . $read_error . ' — skipping this table', 'WARNING' );
		}

		if ( $log_this_part ) {
			$backup_controller->update_logs_records( 'Exported ' . $rows_in_this_chunk . ' rows from ' . $current_table );
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

		// Completion: keyset finishes when a short page arrives (robust to live row-count
		// drift); the offset fallback uses the precomputed chunk count.
		$is_complete = $use_keyset ? ( $rows_in_this_chunk < $chunk_size ) : ( ( $current_chunk + 1 ) >= $total_chunks );
		if ( $is_complete ) {
			$table_ref['completed'] = true;
			$exported_total         = isset( $table_ref['rows_exported'] ) ? (int) $table_ref['rows_exported'] : 0;
			$backup_controller->update_logs_records( 'Completed ' . $current_table . ' (' . $exported_total . ' rows)', 'OK' );
		}

		$backup_controller->update_backup_data( $existing_data );

		// Yield when either budget hits: the ~20s deadline (primary) or the 50MB backstop.
		// deadline 0 (unset) -> size-backstop only.
		$time_budget_hit = ( $this->db_cron_deadline > 0 && time() >= $this->db_cron_deadline );
		if ( $existing_data['tables']['db_size_process'] >= $max_size_per_cron || $time_budget_hit ) {
			$existing_data['tables']['db_size_process'] = 0;
			$backup_controller->update_backup_data( $existing_data );
			wp_schedule_single_event( time() + 3, 'tailwatch_create_db_backup_cron' );
			return false;
		}

		return true;
	}

	public function handle_backup_completion( &$existing_data, $backup_controller, $process_id ) {
		$cancel_pause = $backup_controller->tailwatch_backup_cancel_pause_data();

		// "Any incomplete table left?" — INCLUDING the current one if it still has chunks pending.
		// get_next_table() only looked at tables AFTER the current, so a multi-chunk LAST table was
		// marked complete after its first chunk and silently truncated. Mirror the cron-entry pick.
		$has_more_tables = $this->get_first_incomplete_table( $existing_data['tables']['db_tables'] );

		if ( $has_more_tables ) {
			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				return false;
			}

			// More chunks remain → tell the caller's loop to pack the next one. This used to
			// self-recurse (handle → tailwatch_create_db_backup, one stack frame per chunk; a wide
			// table could nest hundreds deep), which is what blocked raising the per-cron budget.
			// The loop keeps the stack flat. Yielding is owned by the chunk method, which
			// reschedules the next cron and returns false when the 10MB/20s budget is hit.
			return true;
		}

		$get_backup_option = new BackupMaintainController();
		$backup_data       = $get_backup_option->tailwatch_get_backup_settings();
		$get_backup_type   = ! empty( $backup_data['backupType'] ) ? $backup_data['backupType'] : null;

		$backup_controller->update_logs_records( 'Database backup complete', 'SUCCESS' );

		$existing_data['tables']['completed'] = true;
		$backup_controller->update_backup_data( $existing_data );

		if ( 'Complete Backup' === $get_backup_type ) {
			if ( isset( $cancel_pause['scan_state'] ) && ( 'cancel' === $cancel_pause['scan_state'] || 'pause' === $cancel_pause['scan_state'] ) ) {
				$this->cancel_or_pause_backup( $cancel_pause, $backup_controller );
				return false;
			}
			wp_schedule_single_event( time() + 5, 'tailwatch_backup_daily_scan' );
		} else {
			$existing_data['completed']  = true;
			$existing_data['scan_state'] = 'completed';
			$backup_controller->update_backup_data( $existing_data );

			$this->process_manager->mark_completed( $process_id );

			if ( 'in-progress' === $cancel_pause['scan_state'] ) {
				$cancel_pause['scan_state'] = 'completed';
				$backup_controller->update_backup_cancel_pause( $cancel_pause );
			}

			$live_logs = new LiveLogsController();
			$live_logs->tailwatch_live_logs_completed( true, $backup_controller->tailwatch_get_log_file_path() );
		}

		return false;
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

		$db_directory = $backup_directory . 'database/';
		if ( ! file_exists( $db_directory ) ) {
			wp_mkdir_p( $db_directory );
		}

		$database_backup = $db_directory . $folder_date . '/';
		if ( ! file_exists( $database_backup ) ) {
			wp_mkdir_p( $database_backup );
		}
	}

	/**
	 * Get the list of tables to back up.
	 *
	 * When $malware_scan_only is true, fires the 'tailwatch_malware_scan_tables' filter
	 * so the pro plugin can supply the malware-scan-targeted table list. Falls back
	 * to all tables if the filter returns an empty array (e.g. pro plugin inactive).
	 *
	 * @param bool $malware_scan_only When true, asks pro plugin for the targeted table list.
	 * @return array List of table names.
	 */
	public function get_tables_list( $malware_scan_only = false ) {
		if ( $malware_scan_only ) {
			$tables = apply_filters( 'tailwatch_malware_scan_tables', array() );
			if ( ! empty( $tables ) ) {
				return $tables;
			}
		}
		return $this->backup_model->get_database_tables();
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
				$parent_file = $database_backup . "db_parent_tables_{$existing_data['zipId']}.sql.zip";
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
			// Mysqldump-style serializer: the INSERT statements built here are written
			// to a .sql backup file (via tailwatch_save_db_in_file below) and are never
			// executed through $wpdb, so wpdb::prepare()/%i do not apply. $table_name and
			// $columns are schema-derived; identifiers are backtick-escaped the same way
			// mysqldump and phpMyAdmin escape them.
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
		$max_file_size = 50 * 1024 * 1024; // 50 MB.

		// Skip if $sql is null or empty.
		if ( empty( $sql ) ) {
			return false;
		}

		$part = 1;

		// Find the next available part.
		while (
			isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] ) &&
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size_limit_reached']
		) {
			++$part;
		}

		$backup_file_path = "{$database_backup}db_{$table_name}_part_{$part}_{$existing_data['zipId']}.sql.zip";

		if ( ! isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] ) ) {
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] = array(
				'path'               => $backup_file_path,
				'size'               => 0,
				'size_limit_reached' => false,
			);
		} else {
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] = $backup_file_path;
		}

		$current_size = $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size'];
		$sql_size     = strlen( $sql );

		if ( ( $current_size + $sql_size ) > $max_file_size ) {
			$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size_limit_reached'] = true;
			$backup_controller->update_backup_data( $existing_data );

			++$part;
			$backup_file_path = "{$database_backup}db_{$table_name}_part_{$part}_{$existing_data['zipId']}.sql.zip";

			if ( ! isset( $existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] ) ) {
				$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ] = array(
					'path'               => $backup_file_path,
					'size'               => 0,
					'size_limit_reached' => false,
				);
			} else {
				$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['path'] = $backup_file_path;
			}
			$current_size = 0;
			$backup_controller->update_backup_data( $existing_data );
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
			return false;
		}

		$new_size = $current_size + $sql_size;
		$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size'] = $new_size;

		$existing_data['tables']['db_tables'][ $table_name ]['batch'][ "part_$part" ]['size_limit_reached'] = ( $new_size >= $max_file_size );
		$backup_controller->update_backup_data( $existing_data );

		return true;
	}
}
