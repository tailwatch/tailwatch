<?php
namespace Tailwatch\Admin\App\Api\Models;

use Tailwatch\Admin\App\Api\Services\Backup\RestoreDatabase;

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
		$skip       = $this->tailwatch_rows_of_runs( $table_name, $tailwatch_key, $option, $exclude_process_runs );

		if ( empty( $skip ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the list is read fresh on each request.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, value FROM %i WHERE `option` = %s AND `key` = %s ORDER BY `date_created` DESC LIMIT %d OFFSET %d',
					$table_name,
					$option,
					$tailwatch_key,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the list is read fresh on each request.
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, value FROM %i WHERE `option` = %s AND `key` = %s AND id NOT IN (' . implode( ', ', array_fill( 0, count( $skip ), '%d' ) ) . ') ORDER BY `date_created` DESC LIMIT %d OFFSET %d',
					array_merge( array( $table_name, $option, $tailwatch_key ), $skip, array( $limit, $offset ) )
				),
				ARRAY_A
			);
		}

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
	public function get_all_backup_folders_count( $tailwatch_key, $option, $exclude_process_runs = array(), $only_types = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// The caller may restrict the count to the folders it can actually see on disk.
		// Without this the total is a raw row count while the LIST is reconciled against
		// the filesystem, so a row whose folder is gone — after a migration, a manual
		// delete, a run that never finished — would add a page that renders empty.
		$names = null;
		if ( is_array( $only_types ) ) {
			$names = array();
			foreach ( $only_types as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$names[] = $name;
				}
			}

			if ( empty( $names ) ) {
				// An empty allow-list means nothing on disk, which is a real answer.
				return 0;
			}
		}

		// Same exclusions as get_all_backup_folders — `total` must match the number of rows
		// the page query actually returns.
		$skip = $this->tailwatch_rows_of_runs( $table_name, $tailwatch_key, $option, $exclude_process_runs );

		if ( null === $names && empty( $skip ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the count is read fresh on each request.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `option` = %s AND `key` = %s',
					$table_name,
					$option,
					$tailwatch_key
				)
			);
		} elseif ( null === $names ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the count is read fresh on each request.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `option` = %s AND `key` = %s AND id NOT IN (' . implode( ', ', array_fill( 0, count( $skip ), '%d' ) ) . ')',
					array_merge( array( $table_name, $option, $tailwatch_key ), $skip )
				)
			);
		} elseif ( empty( $skip ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the count is read fresh on each request.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `option` = %s AND `key` = %s AND `type` IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')',
					array_merge( array( $table_name, $option, $tailwatch_key ), $names )
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the count is read fresh on each request.
			$count = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE `option` = %s AND `key` = %s AND `type` IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ') AND id NOT IN (' . implode( ', ', array_fill( 0, count( $skip ), '%d' ) ) . ')',
					array_merge( array( $table_name, $option, $tailwatch_key ), $names, $skip )
				)
			);
		}

		return (int) $count;
	}

	/**
	 * Ids of the rows these runs left, which the backup list does not show.
	 *
	 * `process_run` lives inside the JSON `value`; its compact JSON shape
	 * `"process_run":"<value>"` is matched portably (no JSON_EXTRACT). A row without a value
	 * has no run to read, so any exclusion leaves it out as well.
	 *
	 * @param string $table_name    Table name.
	 * @param string $tailwatch_key The key to filter by.
	 * @param string $option        The option to filter by.
	 * @param array  $process_runs  Runs whose rows are left out.
	 * @return int[]
	 */
	private function tailwatch_rows_of_runs( $table_name, $tailwatch_key, $option, $process_runs ) {
		global $wpdb;

		$ids = array();
		foreach ( (array) $process_runs as $process_run ) {
			if ( ! is_string( $process_run ) || '' === $process_run ) {
				continue;
			}
			$pattern = '%' . $wpdb->esc_like( '"process_run":"' . $process_run . '"' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read fresh for the query that follows.
			$found = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE `option` = %s AND `key` = %s AND ( `value` LIKE %s OR `value` IS NULL )',
					$table_name,
					$option,
					$tailwatch_key,
					$pattern
				)
			);
			foreach ( (array) $found as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		return array_keys( $ids );
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

		return array_values( array_filter( array_column( $results, 0 ), array( $this, 'is_backable_table' ) ) );
	}

	/**
	 * Whether a table is the site's data rather than the restore's own workings.
	 *
	 * A restore leaves the database it replaced behind as `<table>_twold` so it can be put
	 * back, and builds the incoming copy under `twstg_` before the cutover. Both are this
	 * plugin's machinery, not content — and a plain SHOW TABLES would sweep them into the
	 * next backup as though they were.
	 *
	 * That makes the following restore fail outright. With `wp_users` and `wp_users_twold`
	 * both in the dump, the cutover's single RENAME ends up with two moves competing for
	 * the same destination name — `wp_users` -> `wp_users_twold` from one pair, and
	 * `twstg_wp_users_twold` -> `wp_users_twold` from the other. MySQL refuses the whole
	 * statement, which is reported as "the database cutover failed" AFTER the files have
	 * already been replaced: the worst moment to stop, and the site is left inconsistent.
	 *
	 * The retained generation also gets dropped on the way past, so the undo point is gone
	 * as well. Filtering here covers every consumer at once, and it also stops a backup
	 * carrying a second full copy of the database for no reason.
	 *
	 * @param string $table Table name from SHOW TABLES.
	 * @return bool
	 */
	private function is_backable_table( $table ) {
		$table = (string) $table;

		if ( 0 === strpos( $table, RestoreDatabase::STAGING_PREFIX ) ) {
			return false;
		}

		$suffix = RestoreDatabase::OLD_SUFFIX;

		return substr( $table, -strlen( $suffix ) ) !== $suffix;
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
			list( $transient, $site_transient )        = $this->transient_like_patterns();
			list( $secret_key, $header_key, $pairing ) = $this->api_secret_rows();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; count non-transient options.
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ( %s, %s ) AND option_name NOT LIKE %s',
				$table_name,
				$transient,
				$site_transient,
				$secret_key,
				$header_key,
				$pairing
			) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; row count.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
	}

	/**
	 * LIKE patterns for the transient rows a backup leaves out of the options table.
	 *
	 * Escaped: `_` is a single-character wildcard in LIKE, so an unescaped pattern would
	 * also match ordinary options such as `xtransientxname`.
	 *
	 * @return array{0: string, 1: string} Transient pattern, site-transient pattern.
	 */
	private function transient_like_patterns() {
		global $wpdb;

		return array(
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
		);
	}

	/**
	 * The option rows that sign and authenticate the Connect API, which no dump carries.
	 *
	 * Anyone holding a dump could mint working API tokens with them. A restore does not
	 * need them from the dump: it carries the live values across.
	 *
	 * @return array{0: string, 1: string, 2: string} App secret, auth header key, pairing-secret LIKE pattern.
	 */
	private function api_secret_rows() {
		global $wpdb;

		return array(
			'tailwatch_app_secret_key',
			'tailwatch_auth_header_key',
			$wpdb->esc_like( 'tailwatch_cta_secret_' ) . '%',
		);
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
			list( $transient, $site_transient )        = $this->transient_like_patterns();
			list( $secret_key, $header_key, $pairing ) = $this->api_secret_rows();
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ( %s, %s ) AND option_name NOT LIKE %s ' . $order_by_sql . ' LIMIT %d, %d',
				array_merge(
					array( $table_name, $transient, $site_transient, $secret_key, $header_key, $pairing ),
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
			list( $transient, $site_transient )        = $this->transient_like_patterns();
			list( $secret_key, $header_key, $pairing ) = $this->api_secret_rows();
			if ( $first ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset options first page.
				return $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ( %s, %s ) AND option_name NOT LIKE %s ORDER BY %i ASC LIMIT %d',
					$table_name,
					$transient,
					$site_transient,
					$secret_key,
					$header_key,
					$pairing,
					$pk_col,
					$safe_chunk
				), ARRAY_A );
			}
			if ( $pk_is_int ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset options page (int PK).
				return $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ( %s, %s ) AND option_name NOT LIKE %s AND %i > %d ORDER BY %i ASC LIMIT %d',
					$table_name,
					$transient,
					$site_transient,
					$secret_key,
					$header_key,
					$pairing,
					$pk_col,
					$cursor,
					$pk_col,
					$safe_chunk
				), ARRAY_A );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database backup; keyset options page (string PK).
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN ( %s, %s ) AND option_name NOT LIKE %s AND %i > %s ORDER BY %i ASC LIMIT %d',
				$table_name,
				$transient,
				$site_transient,
				$secret_key,
				$header_key,
				$pairing,
				$pk_col,
				$cursor,
				$pk_col,
				$safe_chunk
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
