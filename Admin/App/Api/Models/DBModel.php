<?php

namespace Tailwatch\Admin\App\Api\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Database Model for Custom Plugin Tables.
 *
 * This class manages interactions with custom plugin tables (tw_settings, tw_logs, etc.).
 * Direct database queries are necessary as WordPress does not provide high-level APIs for custom tables.
 * Caching is intentionally not used for most operations as this data is frequently updated and needs to be real-time.
 *
 * @package    Tailwatch
 * @subpackage Models
 *
 */
class DBModel {

	/**
	 * Get features from settings table.
	 *
	 * Retrieves plugin features based on active status and option name.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param bool        $is_active Whether to filter by active status.
	 * @param string|null $option    Optional. Specific option name to retrieve.
	 *
	 * @return array|null Array of features or null if none found.
	 */
	public function get_features( $is_active = 0, $option = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		if ( $option !== null ) {
			if ( $is_active ) {
				$query = $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND `type_state` = %s AND `is_active` = %d',
					$table_name,
					'default_feature_settings',
					$option,
					'active',
					1
				);
			} else {
				$query = $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'default_feature_settings',
					$option
				);
			}
		} elseif ( $is_active ) {
				$query = $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `type_state` = %s AND `is_active` = %d',
					$table_name,
					'default_feature_settings',
					'active',
					1
				);
		} else {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s',
				$table_name,
				'default_feature_settings'
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; $query built via $wpdb->prepare() above.
		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( $rows ) {
			return $rows;
		}

		return null;
	}

	/**
	 * Drop plugin custom tables.
	 *
	 * Deletes the settings and logs tables from the database.
	 *
	 * @return void
	 */
	public function tailwatch_drop_tables() {
		global $wpdb;
		$tables = array(
			$wpdb->prefix . TAILWATCH_DB_TABLE_NAME,
			$wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME,
			$wpdb->prefix . TAILWATCH_DB_FILEMON_BASELINE_TABLE,
			$wpdb->prefix . TAILWATCH_DB_FILEMON_SCANS_TABLE,
		);
		foreach ( $tables as $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin deactivation: dropping custom tables. Schema change is intentional.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
		}

		// Invalidate the positive existence cache so any insert_row() later in this same
		// request re-probes instead of writing to a table that has just been dropped.
		self::$table_exists_cache = array();
	}

	/**
	 * Get option value from settings table.
	 *
	 * Retrieves the decoded value of a specific option.
	 *
	 * @param string $option Option name.
	 * @param string $key    Option key.
	 * @return array|null Decoded value array or null if not found.
	 */
	public function get_value( $option, $key ) {
		global $wpdb;

		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
			$table_name,
			$key,
			$option
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; $sql built via $wpdb->prepare() above.
		$result_all = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! empty( $result_all ) && isset( $result_all[0]['value'] ) ) {
			$value_array = json_decode( $result_all[0]['value'], true );
			return $value_array;
		} else {
			return null;
		}
	}

	/**
	 * Get raw log value.
	 *
	 * Retrieves all columns for a specific log entry.
	 *
	 * @param string $key    Log key.
	 * @param string $option Log option.
	 * @return array|null Array of log rows or null.
	 */
	public function get_log_value( $key, $option ) {
		global $wpdb;

		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
			$table_name,
			$key,
			$option
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; $sql built via $wpdb->prepare() above.
		$result_all = $wpdb->get_results( $sql, ARRAY_A );
		return $result_all;
	}

	/**
	 * Per-request cache of table names confirmed to exist. Positive results only:
	 * a table found missing is re-probed on the next call (so one created later in the
	 * same request is picked up), while tailwatch_drop_tables() clears the whole cache
	 * (so one dropped mid-request is re-probed). Lets bulk insert_row() loops skip the
	 * existence probe after the first row.
	 *
	 * @var array<string,bool>
	 */
	private static $table_exists_cache = array();

	/**
	 * Insert a new row into the database.
	 *
	 * @param array  $db_data        Data to insert (column => value).
	 * @param array  $db_data_format Format of the data values.
	 * @param string $table          Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return int|false The inserted ID or false on error.
	 */
	public function insert_row( $db_data, $db_data_format, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$db_table_name = $wpdb->prefix . $table;

		// Skip the write when the target table is absent (e.g. the admin declined setup,
		// or data was removed on deactivation). Without this, WordPress core runs a
		// SHOW FULL COLUMNS during $wpdb->insert() and logs a "table doesn't exist"
		// database error. esc_like() keeps the table name's underscores literal so the
		// LIKE matches only the exact table; only positive results are cached.
		if ( empty( self::$table_exists_cache[ $db_table_name ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-metadata probe for a custom table; positive result cached per-request in self::$table_exists_cache and invalidated on drop.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $db_table_name ) ) );
			if ( $found !== $db_table_name ) {
				return false;
			}
			self::$table_exists_cache[ $db_table_name ] = true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
		$result = $wpdb->insert( $db_table_name, $db_data, $db_data_format );
		return $result !== false ? $wpdb->insert_id : false;
	}

	/**
	 * Generic SELECT helper. Used by BrokenLinkChecker scans across several
	 * core tables. Callers MUST pass code-literal column names in $columns and
	 * $conditions — these are not validated here because doing so would
	 * require shipping a per-table column allowlist that has to drift with WP
	 * core schema changes. Never call this with user-controlled column names.
	 *
	 * Table identifier passes through %i. Filter values bind via prepare args.
	 *
	 * @param string $table      Bare table name (without $wpdb->prefix).
	 * @param array  $columns    Whitelisted column-name strings for the SELECT.
	 * @param array  $conditions Map of column => value or column => array('LIKE', $value).
	 * @param int    $limit
	 * @param int    $offset
	 * @return array|object|null wpdb->get_results() return.
	 */
	public function get_rows( $table, $columns, $conditions, $limit, $offset ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;

		// Column identifiers are bound as identifiers through the %i placeholder
		// (WP 6.2+) rather than concatenated into the query.
		$select_placeholders = array();
		$select_args         = array();
		foreach ( $columns as $column ) {
			$select_placeholders[] = '%i';
			$select_args[]         = $column;
		}
		$select = implode( ', ', $select_placeholders );

		$where_extras = '';
		$where_args   = array();

		foreach ( $conditions as $column => $condition ) {
			if ( is_array( $condition ) && $condition[0] === 'LIKE' ) {
				$where_extras .= ' AND %i LIKE %s';
				$where_args[]  = $column;
				$where_args[]  = $condition[1];
			} else {
				$where_extras .= ' AND %i = %s';
				$where_args[]  = $column;
				$where_args[]  = $condition;
			}
		}

		$sql = 'SELECT ' . $select . ' FROM %i WHERE 1=1' . $where_extras . ' LIMIT %d OFFSET %d';

		// Placeholder order: SELECT columns (%i), table (%i), each WHERE column (%i) + its
		// value (%s), then LIMIT/OFFSET (%d). Every identifier and value is bound by prepare().
		$args = array_merge(
			$select_args,
			array( $table_name ),
			$where_args,
			array( (int) $limit, (int) $offset )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled only from code-literal SQL fragments and %i/%s/%d placeholders; every identifier and value is bound through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
	}

	public function get_recent_row( $option, $key, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; most-recent row lookup.
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY `date_created` DESC LIMIT 1',
				$table_name,
				$key,
				$option
			),
			ARRAY_A
		);

		return empty( $result ) ? null : $result;
	}

	/**
	 * Update rows in the database.
	 *
	 * @param array  $db_data           Data to update (column => value).
	 * @param array  $where             Where clause (column => value).
	 * @param string $table             Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @param bool   $table_with_prefix Optional. Whether table name already includes prefix. Default false.
	 * @return bool True on success, false on error.
	 */
	public function update_rows( $db_data, $where, $table = TAILWATCH_DB_TABLE_NAME, $table_with_prefix = false ) {
		global $wpdb;
		$db_table_name = $table_with_prefix ? $table : $wpdb->prefix . $table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating custom table. No caching for write operations.
		$result = $wpdb->update( $db_table_name, $db_data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
		return $result !== false;
	}

	/**
	 * Update the most recent row matching criteria.
	 *
	 * Updates the latest entry for a given key and option.
	 *
	 * @param array  $db_data Data to update.
	 * @param string $key     Key to match.
	 * @param string $option  Option to match.
	 * @param string $table   Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return bool True on success, false on failure or if no row found.
	 */
	public function update_recent_row( $db_data, $key, $option, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$db_table_name = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; recent-row id lookup.
		$recent_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE `key` = %s AND `option` = %s ORDER BY id DESC LIMIT 1',
			$db_table_name,
			$key,
			$option
		) );

		if ( $recent_id ) {
			$where = array( 'id' => $recent_id );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating custom table. No caching for write operations.
			$result = $wpdb->update( $db_table_name, $db_data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
			return $result !== false;
		}

		return false;
	}

	/**
	 * Delete the most recent row matching key and option.
	 *
	 * @param string $key    Key to match.
	 * @param string $option Option to match.
	 * @param string $table  Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return bool True on success, false on failure.
	 */
	public function delete_recent_row( $key, $option, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$db_table_name = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; recent-row id lookup before delete.
		$recent_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE `key` = %s AND `option` = %s ORDER BY id DESC LIMIT 1',
			$db_table_name,
			$key,
			$option
		) );

		if ( $recent_id ) {
			$where = array( 'id' => $recent_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from custom table. Caching not applicable for delete operations.
			$result = $wpdb->delete( $db_table_name, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
			return $result !== false;
		}

		return false;
	}

	/**
	 * Delete rows based on criteria.
	 *
	 * @param array $where Where clause (column => value).
	 * @return bool True on success, false on error.
	 */
	public function delete_rows( $where ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		if ( empty( $where ) ) {
			return false;
		}

		$where_format = array_fill( 0, count( $where ), '%s' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from custom table. Caching not applicable for delete operations.
		$result = $wpdb->delete( $table_name, $where, $where_format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.

		return $result !== false;
	}

	/**
	 * Delete table rows based on criteria.
	 *
	 * Similar to delete_rows but allows specifying table name.
	 *
	 * @param array  $where Where clause (column => value).
	 * @param string $table Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return bool True on success, false on error.
	 */
	public function delete_table_rows( $where, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;

		if ( empty( $where ) ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from custom table. Caching not applicable for delete operations.
		$delete_result = $wpdb->delete( $table_name, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
		return $delete_result !== false;
	}

	/**
	 * Delete unified logs from logs table.
	 *
	 * Deletes logs that are part of the unified logging system (excludes old logs with key = 'default_feature_logs').
	 * Optionally filters by log type. Uses direct query because $wpdb->delete() doesn't support != operator or IN clause. // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
	 *
	 * @param string|null $type Optional. Log type to filter by ('error_logs', 'activity_logs', or null for both).
	 * @param string      $table Table name constant. Default TAILWATCH_DB_LOGS_TABLE_NAME.
	 *
	 * @return int|false Number of deleted rows, or false on failure.
	 */
	public function delete_unified_logs( $type = null, $table = TAILWATCH_DB_LOGS_TABLE_NAME ) {
		global $wpdb;

		// Validate constant exists.
		if ( ! defined( 'TAILWATCH_DB_LOGS_TABLE_NAME' ) && $table === TAILWATCH_DB_LOGS_TABLE_NAME ) {
			return false;
		}

		// Build query to delete unified logs only (exclude old logs with key = 'default_feature_logs').
		// Cannot use delete_table_rows because $wpdb->delete() doesn't support != operator or IN clause.
		$table_name = $wpdb->prefix . $table;

		if ( null !== $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; bulk delete by type.
			$result = $wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE `key` != %s AND `type` = %s',
				$table_name,
				'default_feature_logs',
				sanitize_text_field( $type )
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; bulk delete (error + activity).
			$result = $wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE `key` != %s AND `type` IN (%s, %s)',
				$table_name,
				'default_feature_logs',
				'error_logs',
				'activity_logs'
			) );
		}

		return false !== $result ? absint( $result ) : false;
	}

	/**
	 * Check if a log entry is a unified log (not an old log).
	 *
	 * Verifies that a log entry exists and is part of the unified logging system
	 * (excludes old logs with key = 'default_feature_logs').
	 *
	 * @param int    $log_id Log entry ID.
	 * @param string $table  Table name constant. Default TAILWATCH_DB_LOGS_TABLE_NAME.
	 *
	 * @return array|false Log entry data with 'key' and 'type', or false if not found or not a unified log.
	 */
	public function is_unified_log( $log_id, $table = TAILWATCH_DB_LOGS_TABLE_NAME ) {
		global $wpdb;

		// Validate constant exists.
		if ( ! defined( 'TAILWATCH_DB_LOGS_TABLE_NAME' ) && $table === TAILWATCH_DB_LOGS_TABLE_NAME ) {
			return false;
		}

		// Validate log ID.
		$log_id = absint( $log_id );
		if ( 0 === $log_id ) {
			return false;
		}

		// Get log entry to verify it's a unified log.
		$table_name = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; lookup by id before delete.
		$log_entry = $wpdb->get_row( $wpdb->prepare(
			'SELECT `key`, `type` FROM %i WHERE `id` = %d',
			$table_name,
			$log_id
		), ARRAY_A );

		if ( ! $log_entry ) {
			return false;
		}

		// Return false if it's an old log (key = 'default_feature_logs').
		if ( 'default_feature_logs' === $log_entry['key'] ) {
			return false;
		}

		// Return log entry data if it's a unified log.
		return $log_entry;
	}

	/**
	 * Get feature value by ID.
	 *
	 * @param int $id Row ID.
	 * @return string|null Value column content or null.
	 */
	public function get_feature_value( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; value lookup by id.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT value FROM %i WHERE id = %d',
				$table_name,
				$id
			)
		);
		return $value;
	}

	/**
	 * Get a full row by primary-key id.
	 *
	 * @param int         $id     Row ID.
	 * @param string      $table  Table-name constant; defaults to the settings table.
	 * @param string|null $option When set (logs table), also require this `option`, so an
	 *                            id can only resolve within its own log category.
	 * @return array|null Row data or null when not found.
	 */
	public function get_data_by_id( $id, $table = TAILWATCH_DB_TABLE_NAME, $option = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;
		if ( null !== $option ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; lookup by id scoped to option.
			return $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id = %d AND `option` = %s',
					$table_name,
					$id,
					$option
				),
				ARRAY_A
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; lookup by id.
		$value = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table_name,
				$id
			),
			ARRAY_A
		);
		return $value;
	}

	/**
	 * Count rows matching ( key, option, optional type_state ).
	 *
	 * @param string|null $key        Log key (required for non-zero result).
	 * @param string|null $option     Log option (required for non-zero result).
	 * @param string|null $type_state Optional state filter.
	 * @param string      $table      Table-name constant; defaults to logs table.
	 * @param bool        $distinct   COUNT(DISTINCT `option`) when true, else COUNT(*).
	 * @return int
	 */
	/**
	 * Fetch logs with dynamic facet filtering + pagination. Column names come only
	 * from the code-literal whitelist below (never request input); every value binds
	 * via prepare(). Powers the dynamic filter bar.
	 *
	 * @param string $key     Logs key.
	 * @param string $option  Log category.
	 * @param array  $filters column => array(values), plus date_from / date_to.
	 * @param string $table   Bare table name.
	 * @param int    $limit   Page size.
	 * @param int    $page    1-based page.
	 * @return array data/total/page/limit/total_pages.
	 */
	public function get_logs_filtered( $key, $option, $filters, $table = TAILWATCH_DB_LOGS_TABLE_NAME, $limit = 10, $page = 1 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;
		$limit      = max( 1, absint( $limit ) );
		$page       = max( 1, absint( $page ) );
		$offset     = ( $page - 1 ) * $limit;

		$where = array( '`key` = %s', '`option` = %s' );
		$args  = array( $key, $option );

		foreach ( array( 'type', 'type_state', 'username', 'ip_address', 'action', 'facet_1', 'facet_2' ) as $col ) {
			if ( empty( $filters[ $col ] ) || ! is_array( $filters[ $col ] ) ) {
				continue;
			}
			$vals = array_values( array_filter( array_map( 'sanitize_text_field', $filters[ $col ] ), 'strlen' ) );
			if ( empty( $vals ) ) {
				continue;
			}
			$placeholders = implode( ', ', array_fill( 0, count( $vals ), '%s' ) );
			$where[]      = '%i IN (' . $placeholders . ')';
			$args[]       = $col;
			$args         = array_merge( $args, $vals );
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'date_created >= %s';
			$args[]  = sanitize_text_field( $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'date_created <= %s';
			$args[]  = sanitize_text_field( $filters['date_to'] );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE is assembled from code-literal fragments, %i identifier placeholders (the dynamic filter column) and %s value placeholders; the dynamic IN() length requires an assembled string and every identifier/value is bound through prepare().
		$result = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( array( $table_name ), $args, array( $limit, $offset ) )
		), ARRAY_A );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE {$where_sql}",
			array_merge( array( $table_name ), $args )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'data'        => ! empty( $result ) ? $result : array(),
			'total'       => $total,
			'page'        => $page,
			'limit'       => $limit,
			'total_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 0,
		);
	}

	/**
	 * Distinct values per whitelisted facet column — drives the dynamic filter
	 * dropdowns (only values actually present in the logs).
	 *
	 * @param string $key     Logs key.
	 * @param string $option  Log category.
	 * @param array  $columns Facet columns (validated against a whitelist).
	 * @param string $table   Bare table name.
	 * @return array column => list of distinct non-empty values.
	 */
	public function get_logs_distinct_facets( $key, $option, $columns, $table = TAILWATCH_DB_LOGS_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;
		$allowed    = array( 'type', 'type_state', 'username', 'ip_address', 'action', 'facet_1', 'facet_2' );
		$out        = array();

		foreach ( (array) $columns as $col ) {
			if ( ! in_array( $col, $allowed, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; the column identifier is passed as %i (validated against the whitelist above) and every value is bound through prepare().
			$vals = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT %i FROM %i WHERE `key` = %s AND `option` = %s AND is_active = %d AND %i IS NOT NULL AND %i <> '' ORDER BY %i ASC LIMIT 500",
				$col, $table_name, $key, $option, 1, $col, $col, $col
			) );
			$out[ $col ] = ! empty( $vals ) ? $vals : array();
		}

		return $out;
	}

	public function get_logs_count_by_type( $key = null, $option = null, $type_state = null, $table = TAILWATCH_DB_LOGS_TABLE_NAME, $distinct = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;

		// The only production caller is VerifyingFeaturesController::*, which
		// always passes (key, option, optional type_state). To keep the SQL
		// statically literal for the WordPress.DB.PreparedSQL.NotPrepared sniff,
		// we dispatch by which of those three filters are non-null and use a
		// distinct literal query for each shape — instead of concatenating a
		// WHERE clause into prepare().
		if ( $key === null || $option === null ) {
			return 0;
		}

		if ( $distinct ) {
			if ( $type_state !== null ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; real-time count.
				return (int) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(DISTINCT `option`) FROM %i WHERE `key` = %s AND `option` = %s AND `type_state` = %s',
					$table_name,
					$key,
					$option,
					$type_state
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; real-time count.
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(DISTINCT `option`) FROM %i WHERE `key` = %s AND `option` = %s',
				$table_name,
				$key,
				$option
			) );
		}

		if ( $type_state !== null ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; real-time count.
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `option` = %s AND `type_state` = %s',
				$table_name,
				$key,
				$option,
				$type_state
			) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; real-time count.
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `option` = %s',
			$table_name,
			$key,
			$option
		) );
	}

	/**
	 * Get logs filtered by type and other criteria.
	 *
	 * @param string      $option     Log option.
	 * @param string      $key        Log key.
	 * @param string|null $type_state Optional. Type state.
	 * @param string      $table      Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @param int|null    $limit      Optional. Limit for pagination.
	 * @param int|null    $page       Optional. Page number for pagination.
	 * @return array|null Logs data or null if empty. Returns pagination array if limit/page provided.
	 */
	public function get_logs_only_by_type( $option, $key, $type_state = null, $table = TAILWATCH_DB_TABLE_NAME, $limit = null, $page = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;
		$paginate   = ( $limit !== null && $page !== null );
		$limit      = $paginate ? absint( $limit ) : 0;
		$page       = $paginate ? absint( $page ) : 0;
		$offset     = $paginate ? ( $page - 1 ) * $limit : 0;

		if ( $type_state !== null ) {
			if ( $paginate ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; paginated lookup.
				$result = $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND `type` = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$table_name, $key, $option, $type_state, $limit, $offset
				), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; full lookup.
				$result = $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND `type` = %s ORDER BY id DESC',
					$table_name, $key, $option, $type_state
				), ARRAY_A );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; count for pagination.
			$total = $paginate ? (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `option` = %s AND `type` = %s',
				$table_name, $key, $option, $type_state
			) ) : 0;
		} else {
			if ( $paginate ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; paginated lookup.
				$result = $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$table_name, $key, $option, $limit, $offset
				), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; full lookup.
				$result = $wpdb->get_results( $wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY id DESC',
					$table_name, $key, $option
				), ARRAY_A );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; count for pagination.
			$total = $paginate ? (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `option` = %s',
				$table_name, $key, $option
			) ) : 0;
		}

		if ( $paginate ) {
			return array(
				'data'        => ! empty( $result ) ? $result : array(),
				'total'       => $total,
				'page'        => $page,
				'limit'       => $limit,
				'total_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 0,
			);
		}

		return ! empty( $result ) ? $result : null;
	}

	/**
	 * Get logs filtered by `type_state` column (sibling of get_logs_only_by_type).
	 *
	 * Use this when rows are stored with a unique identifier in `type_state`
	 * (e.g. malware scan folder_name) rather than in the `type` column.
	 *
	 * @param string      $option     Row option name.
	 * @param string      $key        Row key name.
	 * @param string|null $type_state Optional. type_state value to filter by.
	 * @param string      $table      Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return array|null Array of rows or null when empty.
	 */
	public function get_logs_by_type_state( $option, $key, $type_state = null, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;

		if ( $type_state !== null ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; rows filtered by type_state.
			$result = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND `type_state` = %s ORDER BY id DESC',
				$table_name, $key, $option, $type_state
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; rows by key+option.
			$result = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY id DESC',
				$table_name, $key, $option
			), ARRAY_A );
		}

		return ! empty( $result ) ? $result : null;
	}

	/**
	 * Get most recent data entry.
	 *
	 * @param string $option Option name.
	 * @param string $key    Key name.
	 * @param string $table  Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return array|null Decoded value or null.
	 */
	public function get_recent_data( $option, $key, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; most-recent row.
		$result = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY `date_created` DESC LIMIT 1',
			$table_name,
			$key,
			$option
		), ARRAY_A );

		if ( ! empty( $result ) ) {
			if ( isset( $result['value'] ) ) {
				$result = json_decode( $result['value'], true );
			}

			return $result;
		} else {
			return null;
		}
	}

	/**
	 * Get most recent error log within last 24 hours.
	 *
	 * @param string      $key        Log key.
	 * @param string      $option     Log option.
	 * @param int         $type       Log type code.
	 * @param string|null $type_state Optional. Type state.
	 * @param string      $table      Optional. Table name constant. Default TAILWATCH_DB_LOGS_TABLE_NAME.
	 * @return array|null Log row or null.
	 */
	public function get_error_logs( $key, $option, $type, $type_state = null, $table = TAILWATCH_DB_LOGS_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;
		$time_limit = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		// Two literal SQL paths so prepare() always receives a static string —
		// the WordPress.DB.PreparedSQL.NotPrepared sniff rejects dynamically
		// concatenated WHERE clauses.
		if ( ! empty( $type_state ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; real-time error lookup.
			$result = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND `type` = %d AND `date_created` >= %s AND `type_state` = %s ORDER BY `date_created` DESC LIMIT 1',
				$table_name,
				$key,
				$option,
				$type,
				$time_limit,
				$type_state
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table; real-time error lookup.
			$result = $wpdb->get_row( $wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND `type` = %d AND `date_created` >= %s ORDER BY `date_created` DESC LIMIT 1',
				$table_name,
				$key,
				$option,
				$type,
				$time_limit
			), ARRAY_A );
		}

		return empty( $result ) ? null : $result;
	}

	/**
	 * Get logs with flexible filtering and pagination.
	 *
	 * Retrieves logs from the logging system table with optional filters
	 * for type, key, and option. Supports pagination.
	 *
	 * @param string|null $type   Log type filter (e.g., 'error_logs', 'activity_logs'). Null for all types.
	 * @param string|null $key    Optional key filter (e.g., 'features', 'plugins').
	 * @param string|null $option Optional option filter (e.g., 'high', 'medium', 'low').
	 * @param int         $limit  Number of records per page. Default 10.
	 * @param int         $page   Page number. Default 1.
	 * @param string      $table  Table name constant. Default TAILWATCH_DB_LOGS_TABLE_NAME.
	 *
	 * @return array|false {
	 *     Query result array or false on failure.
	 *
	 *     @type array $data        Log entries.
	 *     @type int   $total       Total count.
	 *     @type int   $total_pages Total pages.
	 * }
	 */
	public function get_logs( $type = null, $key = null, $option = null, $limit = 10, $page = 1, $table = TAILWATCH_DB_LOGS_TABLE_NAME ) {
		global $wpdb;

		if ( ! defined( 'TAILWATCH_DB_LOGS_TABLE_NAME' ) && $table === TAILWATCH_DB_LOGS_TABLE_NAME ) {
			return false;
		}

		$table_name = $wpdb->prefix . $table;

		// Always-on filter: exclude the legacy 'default_feature_logs' key —
		// this method returns only unified logs (type = error_logs or
		// activity_logs). Built from code-literal fragments + placeholders;
		// user values bound via prepare() args, never interpolated.
		$where_extras = ' AND `key` != %s';
		$extra_values = array( 'default_feature_logs' );

		if ( null !== $type ) {
			$where_extras  .= ' AND `type` = %s';
			$extra_values[] = sanitize_text_field( $type );
		} else {
			$where_extras  .= ' AND `type` IN (%s, %s)';
			$extra_values[] = 'error_logs';
			$extra_values[] = 'activity_logs';
		}

		if ( null !== $key ) {
			$where_extras  .= ' AND `key` = %s';
			$extra_values[] = sanitize_text_field( $key );
		}
		if ( null !== $option ) {
			$where_extras  .= ' AND `option` = %s';
			$extra_values[] = sanitize_text_field( $option );
		}

		$limit  = max( 1, absint( $limit ) );
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * $limit;

		$data_sql  = 'SELECT * FROM %i WHERE 1=1' . $where_extras . ' ORDER BY `date_created` DESC LIMIT %d OFFSET %d';
		$count_sql = 'SELECT COUNT(*) FROM %i WHERE 1=1' . $where_extras;

		$data_args  = array_merge( array( $table_name ), $extra_values, array( $limit, $offset ) );
		$count_args = array_merge( array( $table_name ), $extra_values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $data_sql built from code-literal fragments only; user values bound via prepare() args.
		$data_result = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_args ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $count_sql built from code-literal fragments only; user values bound via prepare() args.
		$total = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_args ) );

		if ( false === $data_result || false === $total ) {
			return false;
		}

		$total       = absint( $total );
		$total_pages = $total > 0 ? ceil( $total / $limit ) : 0;

		return array(
			'data'        => $data_result ? $data_result : array(),
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Retrieve data from tw_logs with flexible conditions and optional pagination.
	 *
	 * @param string|null $key The key to filter by (e.g., 'default_broken_link_logs').
	 * @param string|null $option The option to filter by (e.g., URL).
	 * @param array       $conditions Additional conditions (e.g., ['child_of' => 123]).
	 * @param bool        $multiple Return multiple rows (true) or a single row (false).
	 * @param int|null    $limit Number of records per page (only used when $multiple is true and pagination is needed).
	 * @param int|null    $page Page number (only used when $multiple is true and pagination is needed).
	 * @return array|object|null
	 */
	public function get_log_data( $key = null, $option = null, $conditions = array(), $multiple = false, $limit = null, $page = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;

		// $where_extras is built from code-literal column+placeholder fragments
		// only. Column names in $conditions are filtered through an allowlist
		// so callers can't smuggle SQL via array keys. User values are bound
		// via prepare() args — never interpolated into the SQL string.
		$allowed_condition_cols = array( 'child_of', 'user_id', 'type', 'type_state', 'is_active' );

		$where_extras = '';
		$args         = array( $table_name );

		if ( $key !== null ) {
			$where_extras .= ' AND `key` = %s';
			$args[]        = $key;
		}
		if ( $option !== null ) {
			$where_extras .= ' AND `option` = %s';
			$args[]        = $option;
		}
		foreach ( $conditions as $field => $value ) {
			if ( ! in_array( $field, $allowed_condition_cols, true ) ) {
				continue;
			}
			// $field is from the allowlist above — safe to use as identifier.
			$where_extras .= ' AND %i = %s';
			$args[]        = $field;
			$args[]        = $value;
		}

		// Single-row path.
		if ( ! $multiple ) {
			$sql = 'SELECT * FROM %i WHERE 1=1' . $where_extras . ' ORDER BY `date_created` DESC LIMIT 1';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql built from code-literal fragments only; user values bound via prepare() args.
			return $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) );
		}

		// Paginated multi-row path.
		if ( $limit !== null && $page !== null ) {
			$offset = ( $page - 1 ) * $limit;

			$data_sql  = 'SELECT * FROM %i WHERE 1=1' . $where_extras . ' ORDER BY `date_modified` DESC LIMIT %d OFFSET %d';
			$count_sql = 'SELECT COUNT(*) FROM %i WHERE 1=1' . $where_extras;
			$data_args = array_merge( $args, array( $limit, $offset ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $data_sql built from code-literal fragments only; user values bound via prepare() args.
			$data = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_args ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $count_sql built from code-literal fragments only; user values bound via prepare() args.
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );

			return array(
				'data'        => $data,
				'total'       => $total,
				'page'        => (int) $page,
				'limit'       => (int) $limit,
				'total_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 0,
			);
		}

		// Unpaginated multi-row path.
		$sql = 'SELECT * FROM %i WHERE 1=1' . $where_extras . ' ORDER BY `date_modified` DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql built from code-literal fragments only; user values bound via prepare() args.
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * Get all redirect rules.
	 *
	 * @param string $key        Key name.
	 * @param string $type_state Type state.
	 * @param int    $limit      Limit per page.
	 * @param int    $page       Page number.
	 * @param string $table      Optional. Table name constant. Default TAILWATCH_DB_TABLE_NAME.
	 * @return array Pagination result array.
	 */
	public function getAllRedirectRules( $key, $type_state, $limit = 10, $page = 1, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $table;
		$offset     = ( $page - 1 ) * $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; paginated redirect listing.
		$result_all = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND `type_state` = %s LIMIT %d OFFSET %d',
			$table_name, $key, $type_state, $limit, $offset
		), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; count for pagination.
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `type_state` = %s',
			$table_name, $key, $type_state
		) );

		return array(
			'data'        => $result_all,
			'total'       => $total,
			'page'        => $page,
			'limit'       => $limit,
			'total_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 0,
		);
	}

	/**
	 * Delete all Connect pairing (CTA) keys.
	 *
	 * Removes the pairing id, the auth header key and every rotating CTA secret
	 * so a disconnected site can no longer authenticate the mobile app / cloud to
	 * the Connect REST API.
	 *
	 * @return void
	 */
	public function tailwatch_delete_all_cta_keys() {
		global $wpdb;

		delete_option( 'tailwatch_cta_id' );
		delete_option( 'tailwatch_auth_header_key' );

		$like_pattern = $wpdb->esc_like( 'tailwatch_cta_secret_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; CTA-secret cleanup.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$like_pattern
			),
			ARRAY_A
		);

		if ( is_array( $options ) ) {
			foreach ( $options as $option ) {
				if ( isset( $option['option_name'] ) ) {
					delete_option( $option['option_name'] );
				}
			}
		}
	}

	/**
	 * Revoke all outstanding JWT tokens.
	 *
	 * Marks every recorded token id as revoked so existing mobile/cloud access
	 * and refresh tokens stop validating immediately. Used on license disconnect.
	 *
	 * @return void
	 */
	public function tailwatch_revoke_all_tokens() {
		global $wpdb;

		$like_pattern = $wpdb->esc_like( 'tailwatch_token_jti_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; JWT-token revocation sweep.
		$options = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$like_pattern
			),
			ARRAY_A
		);

		if ( is_array( $options ) ) {
			foreach ( $options as $option ) {
				if ( ! isset( $option['option_name'] ) ) {
					continue;
				}
				$jti = str_replace( 'tailwatch_token_jti_', '', $option['option_name'] );
				if ( ! \Tailwatch\Admin\App\Api\Services\Auth\JwtService::is_valid_jti_format( $jti ) ) {
					continue;
				}
				update_option( 'tailwatch_token_revoked_' . $jti, true, false );
			}
		}
	}

	/**
	 * Delete all plugin data on deactivation.
	 *
	 * Cleanup operation to remove all plugin options from the database.
	 *
	 * @return void
	 */
	public function tailwatch_delete_data_on_deactivate() {
		global $wpdb;

		$delete_patterns = array(
			'tailwatch_cta_secret_',
			'tailwatch_cta_user_',
			'tailwatch_token_jti_',
			'tailwatch_token_revoked_',
			'tailwatch_recovery_token_',
			'tailwatch_auto_login_token_',
		);

		foreach ( $delete_patterns as $pattern ) {
			$like_pattern = $wpdb->esc_like( $pattern ) . '%';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; deactivation cleanup.
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$like_pattern
			) );
		}

		// Single-row Connect pairing options (exact names, not prefixes).
		delete_option( 'tailwatch_cta_id' );
		delete_option( 'tailwatch_auth_header_key' );
	}

	public function get_latest_import_option_name() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; find latest import-transient row.
		$transient = $wpdb->get_var( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1',
			$wpdb->options,
			'_transient_tailwatch_import_%'
		) );
		if ( $transient ) {
			return str_replace( '_transient_', '', $transient );
		}
		return null;
	}

	public function get_latest_reset_option_name() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; find latest reset-transient row.
		$transient = $wpdb->get_var( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1',
			$wpdb->options,
			'_transient_tailwatch_reset_%'
		) );
		if ( $transient ) {
			return str_replace( '_transient_', '', $transient );
		}
		return null;
	}

	public function get_features_option_by_key() {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;

		// Both `key` and `option` are MySQL reserved words and MUST be backticked,
		// otherwise strict servers reject the query with a syntax error and the
		// caller silently receives an empty array.
		$query = $wpdb->prepare(
			'SELECT `option` FROM %i WHERE `key` = %s',
			$table_name,
			'default_feature_settings'
		);

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $query built via $wpdb->prepare() above.

		if ( $rows ) {
			return array_column( $rows, 'option' );
		}

		return array();
	}

	public function get_user_data( $user_id, $key, $option ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;
		$value      = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `user_id` = %d AND `key` = %s AND `option` = %s',
				$table_name,
				$user_id,
				$key,
				$option
			),
			ARRAY_A
		);
		return $value;
	}

	public function count_users_by_meta( $meta_key, $meta_value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core users/usermeta tables; user-by-meta count.
		$total = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(DISTINCT u.ID) FROM %i u INNER JOIN %i m ON u.ID = m.user_id WHERE m.meta_key = %s AND m.meta_value = %s',
			$wpdb->users,
			$wpdb->usermeta,
			$meta_key,
			$meta_value
		) );

		return (int) $total;
	}

	public function delete_all_transients( $transient_name ) {
		global $wpdb;
		$like_pattern = $transient_name . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; bulk transient cleanup.
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$like_pattern
		) );
	}
}
