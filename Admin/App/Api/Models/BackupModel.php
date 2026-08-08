<?php
namespace Tailwatch\Admin\App\Api\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BackupModel
 *
 * Database model for backup-related queries including backup records,
 * database table operations for SQL export, and backup folder management.
 *
 */
class BackupModel {

	/**
	 * Get all backup records by key and option.
	 *
	 * @param string $key    The key to filter by.
	 * @param string $option The option to filter by.
	 * @return array Array of backup records.
	 */
	public function get_all_backups( $key, $option ) {
		global $wpdb;
		$db_table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$query = $wpdb->prepare(
			'SELECT id, value FROM %i WHERE `key` = %s AND `option` = %s ORDER BY id ASC',
			$db_table_name,
			$key,
			$option
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; $query built via $wpdb->prepare() above.
		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get paginated backup folders with decoded JSON data.
	 *
	 * @param string $tailwatch_key The key to filter by.
	 * @param string $option   The option to filter by.
	 * @param int    $limit    Number of records to retrieve.
	 * @param int    $offset   Offset for pagination.
	 * @return array Array of backup folder data.
	 */
	public function get_all_backup_folders( $tailwatch_key, $option, $limit = 10, $offset = 0, $exclude_process_runs = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// NOT LIKE fragment for $exclude_process_runs. `process_run` lives
		// inside the JSON `value` column; match the compact JSON shape
		// `"process_run":"<value>"` portably (no JSON_EXTRACT). Each clause
		// is its own $wpdb->prepare() so the fragment is already escaped
		// and the outer prepare() has a fixed placeholder count.
		$exclude_clauses_sql = '';
		if ( is_array( $exclude_process_runs ) && ! empty( $exclude_process_runs ) ) {
			foreach ( $exclude_process_runs as $process_run ) {
				if ( ! is_string( $process_run ) || '' === $process_run ) {
					continue;
				}
				$pattern = '%' . $wpdb->esc_like( '"process_run":"' . $process_run . '"' ) . '%';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each clause is its own $wpdb->prepare() call; the result is concatenated as an already-escaped SQL fragment into the main query below.
				$exclude_clauses_sql .= ' AND ' . $wpdb->prepare( '`value` NOT LIKE %s', $pattern );
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Reason: Custom table; $exclude_clauses_sql is a pre-prepared fragment
		// (each clause built via $wpdb->prepare() above), safe to concatenate.
		$results = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, value FROM %i WHERE `option` = %s AND `key` = %s' . $exclude_clauses_sql . ' ORDER BY `date_created` DESC LIMIT %d OFFSET %d',
			$table_name,
			$option,
			$tailwatch_key,
			$limit,
			$offset
		), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$folders_data = array();

		if ( ! empty( $results ) ) {
			foreach ( $results as $result ) {
				$folder_data = json_decode( $result['value'], true );
				if ( ! empty( $folder_data ) && is_array( $folder_data ) ) {
					$folder_data['id'] = $result['id'];
					$folders_data[]    = $folder_data;
				}
			}
		}

		return $folders_data;
	}

	/**
	 * Get total count of backup folders.
	 *
	 * @param string $tailwatch_key The key to filter by.
	 * @param string $option   The option to filter by.
	 * @return int Total count of backup folders.
	 */
	public function get_all_backup_folders_count( $tailwatch_key, $option, $exclude_process_runs = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// Same exclude semantics as get_all_backup_folders — `total` must match
		// the number of rows the page query actually returns.
		$exclude_clauses_sql = '';
		if ( is_array( $exclude_process_runs ) && ! empty( $exclude_process_runs ) ) {
			foreach ( $exclude_process_runs as $process_run ) {
				if ( ! is_string( $process_run ) || '' === $process_run ) {
					continue;
				}
				$pattern = '%' . $wpdb->esc_like( '"process_run":"' . $process_run . '"' ) . '%';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each clause is its own $wpdb->prepare() call; the result is concatenated as an already-escaped SQL fragment into the main query below.
				$exclude_clauses_sql .= ' AND ' . $wpdb->prepare( '`value` NOT LIKE %s', $pattern );
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Reason: Custom table; $exclude_clauses_sql is a pre-prepared fragment
		// (each clause built via $wpdb->prepare() above), safe to concatenate.
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE `option` = %s AND `key` = %s' . $exclude_clauses_sql,
			$table_name,
			$option,
			$tailwatch_key
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $count;
	}

	/**
	 * Delete a backup entry by ID.
	 *
	 * @param int    $backup_id The ID of the backup to delete.
	 * @param string $table     Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function delete_backup_by_id( $backup_id, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$db_table_name = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from custom table. Caching not applicable for delete operations.
		return $wpdb->delete( $db_table_name, array( 'id' => $backup_id ) );
	}

	/**
	 * Get list of all database tables.
	 *
	 * @return array List of table names.
	 */
	public function get_database_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup requires real-time table list.
		$results = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

		return array_column( $results, 0 );
	}

	/**
	 * Get row count for a specific table.
	 *
	 * @param string $table_name       The table name to count rows from.
	 * @param bool   $exclude_transients Whether to exclude transients (for options table).
	 * @return int The number of rows in the table.
	 */
	public function get_table_row_count( $table_name, $exclude_transients = false ) {
		global $wpdb;

		if ( $exclude_transients && $table_name === $wpdb->options ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; count non-transient options.
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s',
				$table_name,
				'_transient_%',
				'_site_transient_%'
			) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; row count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
	}

	/**
	 * Get table data in chunks for backup.
	 *
	 * @param string $table_name         The table name to get data from.
	 * @param int    $offset             The offset for pagination.
	 * @param int    $chunk_size         The number of rows to retrieve.
	 * @param bool   $exclude_transients Whether to exclude transients (for options table).
	 * @return array The table data rows.
	 */
	public function get_table_data_chunk( $table_name, $offset, $chunk_size, $exclude_transients = false, $order_by_cols = array() ) {
		global $wpdb;
		$safe_offset     = absint( $offset );
		$safe_chunk_size = absint( $chunk_size );
		list( $order_by_sql, $order_by_args ) = $this->tailwatch_build_order_by( $order_by_cols );

		// $order_by_sql is a code-literal ORDER BY clause whose column identifiers are %i
		// placeholders, bound below from schema-derived DESCRIBE column names (never user
		// input); every value is bound through prepare(). The assembled clause trips the
		// static analyzers regardless of which line they attribute it to, so disable/enable
		// wraps the whole query section.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $exclude_transients && $table_name === $wpdb->options ) {
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s ' . $order_by_sql . ' LIMIT %d, %d',
				array_merge(
					array( $table_name, '_transient_%', '_site_transient_%' ),
					$order_by_args,
					array( $safe_offset, $safe_chunk_size )
				)
			), ARRAY_A );
		}

		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i ' . $order_by_sql . ' LIMIT %d, %d',
			array_merge(
				array( $table_name ),
				$order_by_args,
				array( $safe_offset, $safe_chunk_size )
			)
		), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Build a deterministic ORDER BY clause from a column list. Empty → ORDER BY 1
	 * (best-effort stable order for keyless tables). Each column is emitted as a %i
	 * identifier placeholder; the columns to bind are returned alongside the clause. They
	 * come from the table's own primary-key definition, never user input.
	 *
	 * @param array $cols Column names.
	 * @return array{0: string, 1: array} ORDER BY clause with %i placeholders, and the columns to bind.
	 */
	private function tailwatch_build_order_by( $cols ) {
		if ( empty( $cols ) || ! is_array( $cols ) ) {
			return array( 'ORDER BY 1', array() );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $cols ), '%i ASC' ) );
		return array( 'ORDER BY ' . $placeholders, array_values( $cols ) );
	}

	/**
	 * Average row length (bytes) from the optimizer stats — used to pick a safe per-table
	 * backup chunk size (fewer rows for big-BLOB/LONGTEXT tables). Approximate for InnoDB and
	 * 0 when unknown/empty; callers default to the normal chunk size in that case.
	 *
	 * @param string $table_name Table.
	 * @return int Average row length in bytes, or 0 if unavailable.
	 */
	public function get_table_avg_row_length( $table_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema-stats lookup for backup chunk sizing; information_schema is a fixed table, values bound via prepare.
		$avg = $wpdb->get_var( $wpdb->prepare(
			'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$table_name
		) );
		return null === $avg ? 0 : (int) $avg;
	}

	/**
	 * Decide a table's backup pagination strategy from its primary key (one DESCRIBE,
	 * reusing get_table_structure):
	 *  - single non-binary PK → { mode:'keyset', col, int } (cursor paging, skip/dup-safe + O(N))
	 *  - composite PK         → { mode:'offset', cols }      (offset + ORDER BY the PK cols)
	 *  - no / binary PK       → { mode:'offset', cols:[] }   (offset + ORDER BY 1)
	 * Result is cached by the caller in the run state so this runs once per table.
	 *
	 * @param string $table_name Table.
	 * @return array Pagination spec.
	 */
	public function get_table_primary_key( $table_name ) {
		$structure = $this->get_table_structure( $table_name );
		$pri     = array();
		$type_of = array();
		foreach ( (array) $structure as $s ) {
			if ( ! isset( $s->Field ) ) {
				continue;
			}
			$type_of[ $s->Field ] = strtolower( isset( $s->Type ) ? $s->Type : '' );
			if ( isset( $s->Key ) && 'PRI' === $s->Key ) {
				$pri[] = $s->Field;
			}
		}
		if ( empty( $pri ) ) {
			return array( 'mode' => 'offset', 'cols' => array() );
		}
		if ( count( $pri ) > 1 ) {
			return array( 'mode' => 'offset', 'cols' => $pri );
		}
		$col  = $pri[0];
		$type = isset( $type_of[ $col ] ) ? $type_of[ $col ] : '';
		// A binary/blob PK can't be safely string-compared for keyset — fall back to offset.
		if ( preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob)/', $type ) ) {
			return array( 'mode' => 'offset', 'cols' => array( $col ) );
		}
		return array(
			'mode' => 'keyset',
			'col'  => $col,
			'int'  => (bool) preg_match( '/^(tiny|small|medium|big)?int/', $type ),
		);
	}

	/**
	 * Fetch one keyset page: the rows whose PK is greater than $cursor, ordered ascending
	 * by the PK, LIMIT $chunk_size. $cursor null = first page. Skip/dup-safe under
	 * concurrent writes and O(N) (uses the PK index) — unlike LIMIT-offset paging. Returns
	 * the SAME ARRAY_A row shape as get_table_data_chunk, so generate_sql is unaffected.
	 *
	 * @param string $table_name         Table.
	 * @param string $pk_col             Single PK column.
	 * @param bool   $pk_is_int          Integer PK? (chooses %d vs %s for the comparison value).
	 * @param mixed  $cursor             Last PK value seen, or null for the first page.
	 * @param int    $chunk_size         Rows per page.
	 * @param bool   $exclude_transients Exclude transients (options table).
	 * @return array Rows (ARRAY_A).
	 */
	public function get_table_data_keyset( $table_name, $pk_col, $pk_is_int, $cursor, $chunk_size, $exclude_transients = false ) {
		global $wpdb;
		$safe_chunk = absint( $chunk_size );
		$is_options = $exclude_transients && $table_name === $wpdb->options;
		$first      = ( null === $cursor );

		// $pk_col is bound as an identifier via the %i placeholder; the cursor comparison
		// uses a literal %d (int PK) or %s (string PK) so the query is fully prepared (no
		// interpolation). Output is byte-identical to backtick-escaping the column.
		if ( $is_options ) {
			if ( $first ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset options first page.
				return $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY %i ASC LIMIT %d',
					$table_name, '_transient_%', '_site_transient_%', $pk_col, $safe_chunk
				), ARRAY_A );
			}
			if ( $pk_is_int ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset options page (int PK).
				return $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND %i > %d ORDER BY %i ASC LIMIT %d',
					$table_name, '_transient_%', '_site_transient_%', $pk_col, $cursor, $pk_col, $safe_chunk
				), ARRAY_A );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset options page (string PK).
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND %i > %s ORDER BY %i ASC LIMIT %d',
				$table_name, '_transient_%', '_site_transient_%', $pk_col, $cursor, $pk_col, $safe_chunk
			), ARRAY_A );
		}

		if ( $first ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset first page.
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i ORDER BY %i ASC LIMIT %d',
				$table_name, $pk_col, $safe_chunk
			), ARRAY_A );
		}
		if ( $pk_is_int ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset page (int PK).
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE %i > %d ORDER BY %i ASC LIMIT %d',
				$table_name, $pk_col, $cursor, $pk_col, $safe_chunk
			), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset page (string PK).
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d',
			$table_name, $pk_col, $cursor, $pk_col, $safe_chunk
		), ARRAY_A );
	}

	/**
	 * Get CREATE TABLE statement for a table.
	 *
	 * @param string $table_name The table name to get schema for.
	 * @return string|null The CREATE TABLE statement or null on failure.
	 */
	public function get_table_schema( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Backup reads schema (no changes).
		$table_schema = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table_name ), ARRAY_N );

		if ( $table_schema && isset( $table_schema[1] ) ) {
			return $table_schema[1];
		}

		return null;
	}

	/**
	 * Get table structure (column definitions) for a table.
	 *
	 * @param string $table_name The table name to get structure for.
	 * @return array The table column definitions.
	 */
	public function get_table_structure( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup reads column structure.
		return $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table_name ) );
	}

	/**
	 * Get the last database error.
	 *
	 * @return string The last error message.
	 */
	public function get_last_error() {
		global $wpdb;

		return $wpdb->last_error;
	}

	/**
	 * Check if a table is the options table.
	 *
	 * @param string $table_name The table name to check.
	 * @return bool True if it's the options table.
	 */
	public function is_options_table( $table_name ) {
		global $wpdb;

		return $table_name === $wpdb->options;
	}
}
