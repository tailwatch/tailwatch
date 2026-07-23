<?php
namespace Tailwatch\Admin\App\Api\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence layer for hardening-audit report snapshots.
 *
 * The shared key-value table (WPTW_DB_TABLE_NAME) holds rows for many
 * features; this model is the opinionated view filtered to STATE_KEY +
 * REPORT_OPTION rows — completed audit snapshots stored as JSON in `value`,
 * one per `child_of` (the report id).
 *
 * Per-id deletes are routed through DBModel::delete_table_rows() instead of
 * this model — that helper is already generic and reused across features.
 *
 * Methods call `global $wpdb;` directly rather than caching it as a
 * property; the WPCS PreparedSQL.NotPrepared sniff only recognises the
 * direct form and flags everything inside `$this->wpdb->prepare()` as
 * unprepared.
 */
class HardeningAuditModel {

	/**
	 * Row filter — mirrors HardeningAuditController::STATE_KEY.
	 * Keep both in sync; drift silently returns 0 rows.
	 */
	const STATE_KEY = 'default_hardening_audit_scan';

	/** Row filter — mirrors HardeningAuditController::REPORT_OPTION. */
	const REPORT_OPTION = 'audit_report';

	/**
	 * Prefixed table name. Set once in the constructor.
	 *
	 * @var string
	 */
	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . WPTW_DB_TABLE_NAME;
	}

	/**
	 * @return int Total stored reports.
	 */
	public function count_reports() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no transient cache.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `option` = %s',
				$this->table,
				self::STATE_KEY,
				self::REPORT_OPTION
			)
		);
	}

	/**
	 * One page of report rows, newest first.
	 * Caller json_decode's the `value` column and shapes the response.
	 *
	 * @return array<int, array<string, mixed>> Empty array if no rows.
	 */
	public function get_reports_page( $limit, $offset ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no transient cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, child_of, value, date_created FROM %i WHERE `key` = %s AND `option` = %s ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d',
				$this->table,
				self::STATE_KEY,
				self::REPORT_OPTION,
				(int) $limit,
				(int) $offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Raw JSON `value` for the most-recent row matching $report_id (child_of).
	 *
	 * @return string|null Null when not found.
	 */
	public function get_report_value_by_id( $report_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no transient cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT value FROM %i WHERE `key` = %s AND `option` = %s AND child_of = %d ORDER BY id DESC LIMIT 1',
				$this->table,
				self::STATE_KEY,
				self::REPORT_OPTION,
				(int) $report_id
			),
			ARRAY_A
		);

		if ( empty( $row['value'] ) ) {
			return null;
		}

		return (string) $row['value'];
	}

	/**
	 * Delete every stored report. Counts BEFORE deleting so the caller can
	 * report a meaningful success_count (post-delete count would be 0).
	 *
	 * @return int Rows that existed (and were deleted).
	 */
	public function delete_all_reports() {
		global $wpdb;
		$count = $this->count_reports();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE `key` = %s AND `option` = %s',
				$this->table,
				self::STATE_KEY,
				self::REPORT_OPTION
			)
		);

		return $count;
	}

	/**
	 * Retention sweep. Caller must honour the "Keep All Data" sentinel
	 * before invoking — we never short-circuit the whole table here.
	 *
	 * @param string $cutoff_gmt GMT cutoff, 'Y-m-d H:i:s'.
	 * @return int Rows deleted (false from wpdb is coerced to 0).
	 */
	public function delete_reports_older_than( $cutoff_gmt ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE `key` = %s AND `option` = %s AND date_created < %s',
				$this->table,
				self::STATE_KEY,
				self::REPORT_OPTION,
				(string) $cutoff_gmt
			)
		);

		return (int) $deleted;
	}
}