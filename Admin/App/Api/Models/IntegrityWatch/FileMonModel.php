<?php
/**
 * File Integrity storage model.
 *
 * The single SQL layer for the file-integrity tables — the baseline
 * ({prefix}tw_filemon_baseline, one row per file) and the scan history
 * ({prefix}tw_filemon_scans, one row per comparison) — plus the per-scan
 * change-tree sidecar files. All file-integrity DB access (free AND pro)
 * goes through here; pro calls the public methods rather than touching the
 * tables directly. Every query is prepared; bulk writes are chunked,
 * transaction-wrapped, and fall back to per-row writes if a bulk query fails.
 *
 * @package    Tailwatch
 * @subpackage Models/IntegrityWatch
 */

namespace Tailwatch\Admin\App\Api\Models\IntegrityWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FileMonModel
 */
class FileMonModel {

	/** Rows per multi-row INSERT — kept well under max_allowed_packet for ~700-byte rows. */
	const INSERT_CHUNK = 200;

	/** Identifiers per IN() clause (lookup / mark-seen / delete). */
	const IN_CHUNK = 500;

	/**
	 * Fully-qualified, prefixed table name.
	 *
	 * @param string $which 'baseline' | 'scans'.
	 * @return string
	 */
	private function table( $which = 'baseline' ) {
		global $wpdb;
		$name = ( 'scans' === $which ) ? TAILWATCH_DB_FILEMON_SCANS_TABLE : TAILWATCH_DB_FILEMON_BASELINE_TABLE;
		return $wpdb->prefix . $name;
	}

	/**
	 * Stable lookup key (file_path_hash) for a file path. Normalizes separators via
	 * wp_normalize_path so the same file keys identically regardless of OS — Windows
	 * '\' and Linux '/' produce the same hash. No-op on Linux (paths are already
	 * forward-slash), so existing rows are unaffected. EVERY md5-of-path on both the
	 * write and read sides MUST go through here so the keys can never drift.
	 *
	 * @param string $path Absolute file path.
	 * @return string 32-char key.
	 */
	public static function path_key( $path ) {
		return md5( wp_normalize_path( (string) $path ) );
	}

	/**
	 * Canonical monitored_path for write AND read. Normalizes separators and strips
	 * any trailing slash, so the value stored matches the value queried regardless of
	 * a caller's trailing slash or OS — e.g. ABSPATH ('/var/www/html/') and a
	 * rtrim()'d '/var/www/html' both key the same root. EVERY monitored_path written
	 * to or queried against the baseline MUST go through here.
	 *
	 * @param string $path Monitored root.
	 * @return string
	 */
	public static function norm_path( $path ) {
		return untrailingslashit( wp_normalize_path( (string) $path ) );
	}

	/* ---------------------------------------------------------------- Baseline */

	/**
	 * Insert/refresh baseline rows (one per file). Idempotent via the unique
	 * file_path_hash key — existing files UPDATE, new files INSERT. Chunked +
	 * transaction-wrapped per chunk + per-row fallback on bulk failure.
	 *
	 * Each row: file_path (required), file_name, file_hash, file_size,
	 * file_modified ('Y-m-d H:i:s'), file_permission, monitored_path,
	 * comparison_status. file_path_hash = md5(file_path) is derived here.
	 *
	 * @param array $rows    List of associative file rows.
	 * @param int   $scan_id Scan id to stamp as last_seen_scan_id (0 = leave seen).
	 * @return int Number of rows written.
	 */
	public function upsert_baseline( array $rows, $scan_id = 0 ) {
		if ( empty( $rows ) ) {
			return 0;
		}
		global $wpdb;
		$table   = $this->table( 'baseline' );
		$now     = current_time( 'mysql' );
		$written = 0;

		foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional batch upsert on a custom table.
			$wpdb->query( 'START TRANSACTION' );
			$result = $this->baseline_chunk_query( $table, $chunk, (int) $scan_id, $now );

			if ( false === $result ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
				$wpdb->query( 'ROLLBACK' );
				foreach ( $chunk as $row ) {
					if ( false !== $this->baseline_chunk_query( $table, array( $row ), (int) $scan_id, $now ) ) {
						$written++;
					}
				}
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			$wpdb->query( 'COMMIT' );
			$written += count( $chunk );
		}

		return $written;
	}

	/**
	 * One multi-row INSERT ... ON DUPLICATE KEY UPDATE for a chunk of baseline rows.
	 *
	 * @param string $table   Prefixed table.
	 * @param array  $chunk   Rows.
	 * @param int    $scan_id last_seen_scan_id stamp.
	 * @param string $now     Datetime.
	 * @return int|false Rows attempted, or false on query failure.
	 */
	private function baseline_chunk_query( $table, array $chunk, $scan_id, $now ) {
		global $wpdb;
		$row_ph       = '(%s, %s, %s, %s, %d, %s, %s, %s, %d, %d, %s, %s)';
		$placeholders = array();
		$values       = array( $table );

		foreach ( $chunk as $r ) {
			$path           = (string) ( $r['file_path'] ?? '' );
			$placeholders[] = $row_ph;
			$values[]       = self::path_key( $path );
			$values[]       = $path;
			$values[]       = (string) ( $r['file_name'] ?? '' );
			$values[]       = (string) ( $r['file_hash'] ?? '' );
			$values[]       = (int) ( $r['file_size'] ?? 0 );
			$values[]       = (string) ( $r['file_modified'] ?? $now );
			$values[]       = (string) ( $r['file_permission'] ?? '' );
			$values[]       = self::norm_path( $r['monitored_path'] ?? '' );
			$values[]       = (int) $scan_id;
			$values[]       = (int) ( $r['comparison_status'] ?? 0 );
			$values[]       = $now;
			$values[]       = $now;
		}

		$sql = 'INSERT INTO %i (file_path_hash, file_path, file_name, file_hash, file_size, file_modified, file_permission, monitored_path, last_seen_scan_id, comparison_status, date_created, date_modified) VALUES '
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE file_hash = VALUES(file_hash), file_size = VALUES(file_size), file_modified = VALUES(file_modified), file_permission = VALUES(file_permission), monitored_path = VALUES(monitored_path), last_seen_scan_id = VALUES(last_seen_scan_id), comparison_status = VALUES(comparison_status), date_modified = VALUES(date_modified)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql holds only code-literal column names + %i/%s/%d placeholders; all values bound via prepare().
		return $wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Fetch baseline rows for a set of path hashes, keyed by file_path_hash.
	 * Chunked over IN_CHUNK so the IN() list never grows unbounded.
	 *
	 * @param string $monitored_path Monitored root.
	 * @param array  $hashes         md5(path) values.
	 * @return array<string,array> Map file_path_hash => row.
	 */
	public function lookup_by_hashes( $monitored_path, array $hashes ) {
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		if ( empty( $hashes ) ) {
			return array();
		}
		global $wpdb;
		$table = $this->table( 'baseline' );
		$map   = array();

		foreach ( array_chunk( $hashes, self::IN_CHUNK ) as $chunk ) {
			$ph   = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql  = "SELECT file_path_hash, file_path, file_name, file_hash, file_size, file_modified, file_permission, comparison_status FROM %i WHERE monitored_path = %s AND file_path_hash IN ($ph)";
			$args = array_merge( array( $table, self::norm_path( $monitored_path ) ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders code-literal; values bound via prepare().
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$map[ $row['file_path_hash'] ] = $row;
			}
		}

		return $map;
	}

	/**
	 * Stamp last_seen_scan_id on matched files (replaces current_status_copy.json
	 * deletion tracking). Batched over IN_CHUNK.
	 *
	 * @param int   $scan_id Current scan id.
	 * @param array $hashes  md5(path) values that were seen on disk.
	 * @return void
	 */
	public function mark_seen( $scan_id, array $hashes ) {
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		if ( empty( $hashes ) ) {
			return;
		}
		global $wpdb;
		$table = $this->table( 'baseline' );

		foreach ( array_chunk( $hashes, self::IN_CHUNK ) as $chunk ) {
			$ph   = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql  = "UPDATE %i SET last_seen_scan_id = %d WHERE file_path_hash IN ($ph)";
			$args = array_merge( array( $table, (int) $scan_id ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders code-literal; values bound via prepare().
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Files present in the baseline that were NOT seen during the given scan
	 * (i.e. deleted from disk since the baseline was taken).
	 *
	 * @param string $monitored_path Monitored root.
	 * @param int    $scan_id        Current scan id.
	 * @return array List of rows (file_path, file_name, file_size, file_modified, file_permission).
	 */
	public function get_deleted( $monitored_path, $scan_id ) {
		global $wpdb;
		$table = $this->table( 'baseline' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- values bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT file_path, file_name, file_size, file_modified, file_permission FROM %i WHERE monitored_path = %s AND last_seen_scan_id <> %d',
				$table,
				self::norm_path( $monitored_path ),
				(int) $scan_id
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/**
	 * Remove baseline rows by path hash (deleted files, after a baseline update).
	 *
	 * @param array $hashes md5(path) values.
	 * @return void
	 */
	public function delete_by_hashes( array $hashes ) {
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		if ( empty( $hashes ) ) {
			return;
		}
		global $wpdb;
		$table = $this->table( 'baseline' );

		foreach ( array_chunk( $hashes, self::IN_CHUNK ) as $chunk ) {
			$ph   = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$sql  = "DELETE FROM %i WHERE file_path_hash IN ($ph)";
			$args = array_merge( array( $table ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders code-literal; values bound via prepare().
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Count baseline rows for a monitored root.
	 *
	 * @param string $monitored_path Monitored root.
	 * @return int
	 */
	public function count_baseline( $monitored_path ) {
		global $wpdb;
		$table = $this->table( 'baseline' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- values bound via prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE monitored_path = %s', $table, self::norm_path( $monitored_path ) ) );
	}

	/**
	 * Wipe the baseline for a monitored root (re-scan / scope change).
	 *
	 * @param string $monitored_path Monitored root.
	 * @return void
	 */
	public function truncate_baseline( $monitored_path ) {
		global $wpdb;
		$table = $this->table( 'baseline' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- values bound via prepare().
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE monitored_path = %s', $table, self::norm_path( $monitored_path ) ) );
	}

	/* ---------------------------------------------------------------- Scans */

	/** Columns the scan writer accepts, with their bind formats. */
	private function scan_field_formats() {
		return array(
			'monitored_path'       => '%s',
			'folder_name'          => '%s',
			'scan_time'            => '%s',
			'is_completed'         => '%d',
			'malware_status'       => '%s',
			'suspicious_handling'  => '%s',
			'old_total_scan_files' => '%d',
			'new_scan_file'        => '%d',
			'total_captured_file'  => '%d',
			'added_count'          => '%d',
			'modified_count'       => '%d',
			'deleted_count'        => '%d',
			'folder_files_ref'     => '%s',
		);
	}

	/**
	 * Insert a scan-history row.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $data    Whitelisted scan fields (see scan_field_formats()).
	 * @return bool
	 */
	public function insert_scan( $scan_id, array $data ) {
		global $wpdb;
		$now     = current_time( 'mysql' );
		$allowed = $this->scan_field_formats();
		$insert  = array(
			'scan_id'       => (int) $scan_id,
			'date_created'  => $now,
			'date_modified' => $now,
		);
		$formats = array( '%d', '%s', '%s' );

		foreach ( $allowed as $col => $fmt ) {
			if ( array_key_exists( $col, $data ) ) {
				$insert[ $col ] = $data[ $col ];
				$formats[]      = $fmt;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row insert on a custom table.
		return false !== $wpdb->insert( $this->table( 'scans' ), $insert, $formats );
	}

	/**
	 * Update whitelisted fields on a scan row.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $fields  Whitelisted fields.
	 * @return bool
	 */
	public function update_scan( $scan_id, array $fields ) {
		global $wpdb;
		$allowed = $this->scan_field_formats();
		$data    = array();
		$formats = array();

		foreach ( $allowed as $col => $fmt ) {
			if ( array_key_exists( $col, $fields ) ) {
				$data[ $col ] = $fields[ $col ];
				$formats[]    = $fmt;
			}
		}
		if ( empty( $data ) ) {
			return false;
		}

		$data['date_modified'] = current_time( 'mysql' );
		$formats[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single-row update on a custom table.
		return false !== $wpdb->update( $this->table( 'scans' ), $data, array( 'scan_id' => (int) $scan_id ), $formats, array( '%d' ) );
	}

	/**
	 * Fetch one scan row.
	 *
	 * @param int $scan_id Scan id.
	 * @return array|null
	 */
	public function get_scan( $scan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- values bound via prepare().
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE scan_id = %d', $this->table( 'scans' ), (int) $scan_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * List scan rows (newest first), with optional completed-only filter and
	 * pagination. Returns rows + the total matching count.
	 *
	 * @param array $args { completed_only:bool, limit:int, offset:int }.
	 * @return array{rows:array,total:int}
	 */
	public function list_scans( array $args = array() ) {
		global $wpdb;
		$table          = $this->table( 'scans' );
		$completed_only = ! empty( $args['completed_only'] );
		$limit          = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 10;
		$offset         = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		// Literal WHERE branches (no interpolation) so the query is fully prepared.
		if ( $completed_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scans table count; no caching by design.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_completed = 1', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scans table page; no caching by design.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE is_completed = 1 ORDER BY scan_time DESC, scan_id DESC LIMIT %d OFFSET %d', $table, $limit, $offset ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scans table count; no caching by design.
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scans table page; no caching by design.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY scan_time DESC, scan_id DESC LIMIT %d OFFSET %d', $table, $limit, $offset ),
				ARRAY_A
			);
		}

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Most recent scan_time — across all comparisons by default, or completed-only.
	 *
	 * @param bool $completed_only Restrict to is_completed = 1.
	 * @return string|null
	 */
	public function max_scan_time( $completed_only = false ) {
		global $wpdb;
		$table = $this->table( 'scans' );
		if ( $completed_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scans table aggregate; no caching by design.
			$val = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(scan_time) FROM %i WHERE is_completed = 1', $table ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scans table aggregate; no caching by design.
			$val = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(scan_time) FROM %i', $table ) );
		}
		return $val ? $val : null;
	}

	/**
	 * One-pass dashboard aggregate over completed scans — counts are summed from
	 * the per-scan columns, so no folder_files tree is ever loaded into memory.
	 * Totals cover all completed scans; "period" covers those with
	 * scan_time >= $period_start_dt; "last" is the latest completed scan row.
	 *
	 * @param string $period_start_dt 'Y-m-d H:i:s' UTC cutoff ('' disables the period split).
	 * @return array{totals:array,period:array,last:?array}
	 */
	public function aggregate_scan_stats( $period_start_dt = '' ) {
		global $wpdb;
		$table = $this->table( 'scans' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- column names code-literal; table bound via prepare().
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS comparisons,
						COALESCE(SUM(total_captured_file),0) AS changes_total,
						COALESCE(SUM(modified_count),0) AS modified,
						COALESCE(SUM(added_count),0) AS new_count,
						COALESCE(SUM(deleted_count),0) AS deleted
				   FROM %i WHERE is_completed = 1',
				$table
			),
			ARRAY_A
		);

		$period = array(
			'comparisons'   => 0,
			'changes_total' => 0,
		);
		if ( '' !== (string) $period_start_dt ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- column names code-literal; values bound via prepare().
			$period_row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT COUNT(*) AS comparisons,
							COALESCE(SUM(total_captured_file),0) AS changes_total
					   FROM %i WHERE is_completed = 1 AND scan_time >= %s',
					$table,
					(string) $period_start_dt
				),
				ARRAY_A
			);
			if ( $period_row ) {
				$period['comparisons']   = (int) $period_row['comparisons'];
				$period['changes_total'] = (int) $period_row['changes_total'];
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table bound via prepare().
		$last = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE is_completed = 1 ORDER BY scan_time DESC, scan_id DESC LIMIT 1',
				$table
			),
			ARRAY_A
		);

		return array(
			'totals' => array(
				'comparisons'   => $totals ? (int) $totals['comparisons'] : 0,
				'changes_total' => $totals ? (int) $totals['changes_total'] : 0,
				'modified'      => $totals ? (int) $totals['modified'] : 0,
				'new'           => $totals ? (int) $totals['new_count'] : 0,
				'deleted'       => $totals ? (int) $totals['deleted'] : 0,
			),
			'period' => $period,
			'last'   => $last ? (array) $last : null,
		);
	}

	/**
	 * Delete scan rows by id (and their sidecars).
	 *
	 * @param array $scan_ids Scan ids.
	 * @return void
	 */
	public function delete_scans( array $scan_ids ) {
		$scan_ids = array_values( array_unique( array_map( 'intval', $scan_ids ) ) );
		if ( empty( $scan_ids ) ) {
			return;
		}
		global $wpdb;
		$table = $this->table( 'scans' );

		foreach ( array_chunk( $scan_ids, self::IN_CHUNK ) as $chunk ) {
			$ph   = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$sql  = "DELETE FROM %i WHERE scan_id IN ($ph)";
			$args = array_merge( array( $table ), $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- placeholders code-literal; values bound via prepare().
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}

		foreach ( $scan_ids as $id ) {
			$this->delete_sidecar( $id );
		}
	}

	/**
	 * All scan ids (any state). Used by "delete all comparisons".
	 *
	 * @return int[]
	 */
	public function all_scan_ids() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT scan_id FROM %i', $this->table( 'scans' ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete incomplete (interrupted) scans + their sidecars. Returns deleted ids.
	 *
	 * @return int[]
	 */
	public function delete_incomplete_scans() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table bound via prepare().
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT scan_id FROM %i WHERE is_completed = 0', $this->table( 'scans' ) ) ) );
		if ( ! empty( $ids ) ) {
			$this->delete_scans( $ids );
		}
		return $ids;
	}

	/**
	 * Delete completed scans older than a cutoff (retention). Returns deleted ids
	 * so the caller can act if needed; sidecars are unlinked here.
	 *
	 * @param string $cutoff_datetime 'Y-m-d H:i:s'.
	 * @return int[] Deleted scan ids.
	 */
	public function delete_scans_before( $cutoff_datetime ) {
		global $wpdb;
		$table = $this->table( 'scans' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- values bound via prepare().
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT scan_id FROM %i WHERE scan_time < %s', $table, (string) $cutoff_datetime ) );
		$ids = array_map( 'intval', (array) $ids );
		if ( ! empty( $ids ) ) {
			$this->delete_scans( $ids );
		}
		return $ids;
	}

	/* ---------------------------------------------------------------- Sidecars */

	/**
	 * Lazily-initialised WP_Filesystem instance (house file-IO pattern).
	 *
	 * @return \WP_Filesystem_Base|false
	 */
	private function fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/**
	 * Absolute sidecar directory (created on demand).
	 *
	 * @return string
	 */
	private function sidecar_dir() {
		$dir = TAILWATCH_LOGS_DIRECTORY . '/file-monitoring-log/folder_files';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Sidecar file path for a scan id.
	 *
	 * @param int $scan_id Scan id.
	 * @return string
	 */
	private function sidecar_path( $scan_id ) {
		return $this->sidecar_dir() . '/' . (int) $scan_id . '.json';
	}

	/**
	 * Write the change-tree sidecar for a scan. Returns the bare filename to
	 * store in tw_filemon_scans.folder_files_ref, or '' on failure.
	 *
	 * @param int   $scan_id Scan id.
	 * @param mixed $tree    Change-tree (array).
	 * @return string
	 */
	public function write_sidecar( $scan_id, $tree ) {
		$path = $this->sidecar_path( $scan_id );
		$json = wp_json_encode( $tree );
		if ( false === $json ) {
			return '';
		}
		$fs = $this->fs();
		if ( ! $fs ) {
			return '';
		}
		// Atomic write (temp + rename) is added in the concurrency-hardening phase.
		$ok = $fs->put_contents( $path, $json, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : false );
		return $ok ? ( (int) $scan_id . '.json' ) : '';
	}

	/**
	 * Read a scan's change-tree sidecar.
	 *
	 * @param int $scan_id Scan id.
	 * @return array
	 */
	public function read_sidecar( $scan_id ) {
		$path = $this->sidecar_path( $scan_id );
		$fs   = $this->fs();
		if ( ! $fs || ! $fs->exists( $path ) ) {
			return array();
		}
		$raw = $fs->get_contents( $path );
		$out = json_decode( (string) $raw, true );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * Delete a scan's sidecar.
	 *
	 * @param int $scan_id Scan id.
	 * @return void
	 */
	public function delete_sidecar( $scan_id ) {
		$path = $this->sidecar_path( $scan_id );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
