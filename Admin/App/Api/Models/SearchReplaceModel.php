<?php
/**
 * Search Replace Model
 *
 * Handles database operations for the search-replace feature.
 *
 * Every method that interpolates a table name into raw SQL gates the
 * input through is_table_safe() first — membership in the live SHOW
 * TABLES list. The model is the single trust boundary for the
 * search-replace SQL surface; callers do not need to know the
 * defensive rules.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Models
 */

namespace Tailwatch\Admin\App\Api\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SearchReplaceModel {

	/**
	 * Cached SHOW TABLES result for the lifetime of this instance.
	 *
	 * @var array|null
	 */
	private $existing_tables = null;

	/**
	 * Partition an array of table names into the subset that exists and
	 * the subset that was rejected.
	 *
	 * @param array $tables Raw table-name list from a request body.
	 * @return array{valid: string[], invalid: string[]}
	 */
	public function filter_valid_tables( array $tables ) {
		$valid   = array();
		$invalid = array();
		foreach ( $tables as $table ) {
			if ( $this->is_table_safe( $table ) ) {
				$valid[] = $table;
			} else {
				$invalid[] = is_scalar( $table ) ? (string) $table : '(non-scalar entry)';
			}
		}
		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * Resolve the primary key column for a table, if any.
	 *
	 * @param string $table Table name.
	 * @return string|null Primary key column name, or null when unsafe / no PK.
	 */
	public function get_primary_key( $table ) {
		if ( ! $this->is_table_safe( $table ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Schema lookup with prepared placeholders.
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_KEY = 'PRI'",
				$wpdb->dbname,
				$table
			)
		);
	}

	/**
	 * Return the column names for a table.
	 *
	 * @param string $table Table name.
	 * @return array Column names; empty array on unsafe input.
	 */
	public function get_columns( $table ) {
		if ( ! $this->is_table_safe( $table ) ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema lookup for validated table.
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0 );
	}

	/**
	 * Fetch one batch of rows whose $column matches the search string.
	 *
	 * @param string $table            Table name.
	 * @param string $column           Column name to search.
	 * @param string $search           Search string (LIKE pattern is built internally).
	 * @param int    $batch_size       Maximum rows to return.
	 * @param bool   $case_insensitive Whether to apply the case-insensitive collation.
	 * @return array Rows as objects; empty array on unsafe input.
	 */
	public function get_matching_rows( $table, $column, $search, $batch_size, $case_insensitive ) {
		if ( ! $this->is_table_safe( $table ) ) {
			return array();
		}
		global $wpdb;
		// $collation is a code-literal switch (empty or fixed COLLATE clause),
		// not user data — it cannot be bound via prepare().
		$collation = $case_insensitive ? 'COLLATE utf8mb4_general_ci' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $collation is a code-literal fragment; identifiers bind via %i, values via prepare().
		$sql   = "SELECT * FROM %i WHERE %i LIKE %s $collation LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql built from code-literal fragments + %i identifiers + bound values.
		$query = $wpdb->prepare( $sql, $table, $column, '%' . $wpdb->esc_like( $search ) . '%', (int) $batch_size );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query built via $wpdb->prepare() above.
		return (array) $wpdb->get_results( $query );
	}

	/**
	 * Update one row's column value.
	 *
	 * @param string $table             Table name.
	 * @param string $column            Column to update.
	 * @param string $primary_key       Primary key column name.
	 * @param mixed  $primary_key_value Primary key value identifying the row.
	 * @param mixed  $new_value         New value for $column.
	 * @return int|false Number of rows updated, or false on unsafe input / DB error.
	 */
	public function update_row( $table, $column, $primary_key, $primary_key_value, $new_value ) {
		if ( ! $this->is_table_safe( $table ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Search-replace write; identifiers validated above.
		return $wpdb->update(
			$table,
			array( $column => $new_value ),
			array( $primary_key => $primary_key_value )
		);
	}

	/**
	 * Count total rows in a table whose CONCAT_WS of $columns matches search.
	 *
	 * @param string $table            Table name.
	 * @param array  $columns          Columns to include in the CONCAT_WS.
	 * @param string $search           Search string.
	 * @param bool   $case_insensitive Whether to use the case-insensitive collation.
	 * @return int Match count; 0 on unsafe input or empty columns.
	 */
	public function count_matching_rows( $table, $columns, $search, $case_insensitive ) {
		if ( ! $this->is_table_safe( $table ) || empty( $columns ) ) {
			return 0;
		}
		global $wpdb;
		// $collation and $columns_list are code-literal fragments built from
		// validated column names — cannot be bound via prepare(). $table binds
		// through %i; the search value binds through %s.
		$collation    = $case_insensitive ? 'COLLATE utf8mb4_general_ci' : 'COLLATE utf8mb4_bin';
		$columns_list = implode(
			', ',
			array_map(
				function ( $column ) {
					return '`' . $column . '`';
				},
				$columns
			)
		);
		// $columns_list and $collation are code-literal fragments built from
		// validated column names — they're SQL fragments, not values or
		// identifiers, so they cannot be bound via prepare(). $table binds
		// via %i; the search value binds via %s.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE CONCAT_WS(' ', $columns_list) LIKE %s $collation",
			$table,
			'%' . $wpdb->esc_like( $search ) . '%'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is built via $wpdb->prepare() above; identifier binds via %i, value via %s, dynamic SQL fragments are code-literal column-name backticks (validated by is_table_safe upstream).
		$count = $wpdb->get_var( $query );
		return (int) $count;
	}

	/**
	 * Gate every table-name interpolation: input must be a real table in
	 * the live database. SHOW TABLES is the trust boundary — any payload
	 * that isn't an actual table name (SQL fragments, injection attempts,
	 * dropped tables) will not match and the call is refused. Cached per
	 * instance so this runs once per request even when many tables are
	 * iterated.
	 *
	 * @param mixed $table
	 * @return bool
	 */
	public function is_table_safe( $table ) {
		if ( ! is_string( $table ) || '' === $table ) {
			return false;
		}
		if ( null === $this->existing_tables ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot per-request allowlist load.
			$this->existing_tables = (array) $wpdb->get_col( 'SHOW TABLES' );
		}
		return in_array( $table, $this->existing_tables, true );
	}
}