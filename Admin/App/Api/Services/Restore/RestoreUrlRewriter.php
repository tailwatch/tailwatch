<?php
/**
 * Tailwatch - Restore URL Rewriter
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Restore
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Restore;

use Tailwatch\Admin\App\Api\Services\Backup\BackupReconciler;
use Tailwatch\Admin\App\Api\Services\Backup\RestoreDatabase;
use Tailwatch\Admin\App\Api\Services\SearchReplace\SerializedReplacer;
use Tailwatch\Admin\App\Api\Services\TickBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes the backup's own address to this site's, inside the staging copy of the database.
 *
 * A backup restored onto a different address carries every absolute URL the source site
 * stored. Doing the rewrite here — on the staging tables, after the import and before the
 * swap — keeps it off the live site entirely, reopens the site with its addresses already
 * right, and leaves nothing that can cancel it: the Search & Replace feature's own switch,
 * process guard and state rows are not involved.
 *
 * Rows are visited in primary-key order from a saved cursor, so a rewrite spread over many
 * ticks, or resumed after a killed one, neither skips a row nor visits one twice.
 */
class RestoreUrlRewriter {

	/**
	 * Rows read per query.
	 */
	const BATCH = 200;

	/**
	 * Where progress is saved between ticks, and between writes when that is needed.
	 */
	const CURSOR_OPTION = 'tailwatch_restore_rewrite_cursor';

	/**
	 * Decide what to rewrite for this restore.
	 *
	 * @param array $state Restore state, after the import.
	 * @return array Plan; `done` is already true when there is nothing to rewrite.
	 */
	public static function plan( array $state ) {
		delete_option( self::CURSOR_OPTION );

		$plan = array(
			'run'         => md5( (string) ( $state['folder_date'] ?? '' ) . '|' . (string) ( $state['started'] ?? '' ) ),
			'origin'      => '',
			'live'        => '',
			'pairs'       => array(),
			'needles'     => array(),
			'guarded'     => false,
			'tables'      => array_values( (array) ( $state['tables'] ?? array() ) ),
			'table_index' => 0,
			'cursor'      => null,
			'columns'     => null,
			'keys'        => null,
			'rows'        => 0,
			'changed'     => array(),
			'skipped'     => array(),
			'failed'      => array(),
			'multisite'   => false,
			'done'        => true,
		);

		if ( empty( $plan['tables'] ) ) {
			return $plan;
		}

		$origin = self::origin_addresses( $state );
		$live   = array(
			'siteurl' => (string) ( $state['live_addresses']['siteurl'] ?? ( $state['live_site_url'] ?? '' ) ),
			'home'    => (string) ( $state['live_addresses']['home'] ?? ( $state['live_site_url'] ?? '' ) ),
		);

		// The site address first and the home address last, so that where the two map the
		// same old URL to different new ones, the address visitors use is the one kept.
		$map = array();
		foreach ( array( 'siteurl', 'home' ) as $which ) {
			foreach ( self::url_pairs( $origin[ $which ], $live[ $which ] ) as $pair ) {
				$map[ $pair[0] ] = $pair[1];
			}
		}

		if ( empty( $map ) ) {
			return $plan;
		}

		$plan['origin'] = untrailingslashit( '' !== $origin['home'] ? $origin['home'] : $origin['siteurl'] );
		$plan['live']   = untrailingslashit( '' !== $live['home'] ? $live['home'] : $live['siteurl'] );

		// A network keeps each site's domain in its own tables, in forms these pairs do not
		// describe. Half a rewrite there is worse than an honest none.
		if ( is_multisite() ) {
			$plan['multisite'] = true;
			return $plan;
		}

		$plan['pairs'] = self::order_pairs( $map );

		foreach ( array( $origin['siteurl'], $origin['home'] ) as $address ) {
			$host = wp_parse_url( (string) $address, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$plan['needles'][] = strtolower( $host );
			}
		}
		$plan['needles'] = array_values( array_unique( $plan['needles'] ) );

		if ( empty( $plan['needles'] ) ) {
			return $plan;
		}

		foreach ( $plan['pairs'] as $pair ) {
			if ( false !== strpos( $pair[1], $pair[0] ) ) {
				$plan['guarded'] = true;
				break;
			}
		}

		$plan['done'] = false;

		return $plan;
	}

	/**
	 * Every stored form of one address change, longest needle first.
	 *
	 * http, https and protocol-relative come from SerializedReplacer; each of those is also
	 * stored JSON-escaped (page builders keep their layouts as JSON, so a link reads
	 * `https:\/\/old.site`) and URL-encoded (redirect targets and query strings).
	 *
	 * @param string $from Address the backup was taken at.
	 * @param string $to   Address this site is served from.
	 * @return array<array{0:string,1:string}>
	 */
	public static function url_pairs( $from, $to ) {
		$from = untrailingslashit( trim( (string) $from ) );
		$to   = untrailingslashit( trim( (string) $to ) );

		if ( '' === $from || '' === $to || $from === $to ) {
			return array();
		}

		$map = array();
		foreach ( SerializedReplacer::expand_url_pairs( $from, $to ) as $pair ) {
			$map[ $pair[0] ]                             = $pair[1];
			$map[ str_replace( '/', '\/', $pair[0] ) ]   = str_replace( '/', '\/', $pair[1] );
			$map[ self::url_encoded( $pair[0] ) ]        = self::url_encoded( $pair[1] );
		}

		return self::order_pairs( $map );
	}

	/**
	 * Carry the rewrite forward until it finishes or the tick runs out.
	 *
	 * @param array $plan     Plan from plan(), or as returned by an earlier run().
	 * @param int   $deadline Tick deadline (0 = none).
	 * @param int   $batch    Rows per query.
	 * @return array Plan; `done` once every table has been visited.
	 */
	public static function run( array $plan, $deadline = 0, $batch = self::BATCH ) {
		if ( ! empty( $plan['done'] ) ) {
			return $plan;
		}

		global $wpdb;

		$plan   = self::resume( $plan );
		$batch  = max( 1, (int) $batch );
		$tables = (array) $plan['tables'];

		while ( $plan['table_index'] < count( $tables ) ) {
			$table   = (string) $tables[ $plan['table_index'] ];
			$staging = RestoreDatabase::staging_name( $table );

			if ( null === $plan['columns'] ) {
				$shape           = self::shape( $table, $staging );
				$plan['columns'] = $shape['columns'];
				$plan['keys']    = $shape['keys'];

				// Without a key a row cannot be written back to the one it was read from. Said only
				// when the table actually holds the old address: a keyless table with nothing to
				// change is not something the operator needs to hear about.
				if ( ! empty( $plan['columns'] ) && empty( $plan['keys'] ) && self::mentions_origin( $staging, $plan ) ) {
					$plan['skipped'][] = $table;
				}
			}

			if ( empty( $plan['columns'] ) || empty( $plan['keys'] ) ) {
				$plan = self::next_table( $plan );
				continue;
			}

			$rows = self::batch( $staging, $plan, $batch );
			if ( null === $rows ) {
				$plan['failed'][] = $table;
				$plan             = self::next_table( $plan );
				continue;
			}

			foreach ( $rows as $row ) {
				$changes = array();
				foreach ( $plan['columns'] as $column ) {
					$value = isset( $row[ $column ] ) ? $row[ $column ] : null;
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}
					$rewritten = self::rewrite_value( $value, $plan['pairs'] );
					if ( $rewritten !== $value ) {
						$changes[ $column ] = $rewritten;
					}
				}

				$where = array();
				foreach ( $plan['keys'] as $key ) {
					$where[ $key ] = $row[ $key ];
				}
				$plan['cursor'] = array_values( $where );

				if ( empty( $changes ) ) {
					continue;
				}

				// When the new address contains the old one, running a row twice nests it
				// (`/staging/staging`). Its cursor is saved before the write, so a tick
				// killed between the two misses this row rather than repeating it.
				if ( $plan['guarded'] ) {
					update_option( self::CURSOR_OPTION, $plan, false );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
				if ( false === $wpdb->update( $staging, $changes, $where ) ) {
					if ( ! in_array( $table, $plan['failed'], true ) ) {
						$plan['failed'][] = $table;
					}
					continue;
				}

				++$plan['rows'];
				if ( ! in_array( $table, $plan['changed'], true ) ) {
					$plan['changed'][] = $table;
				}
			}

			if ( count( $rows ) < $batch ) {
				$plan = self::next_table( $plan );
			}

			// Saved after the move to the next table, never before it: resume() prefers what was
			// saved, so a table finished on a spent tick would otherwise be finished again, forever.
			update_option( self::CURSOR_OPTION, $plan, false );
			TickBudget::alive( 'restore' );

			if ( TickBudget::spent( $deadline ) ) {
				return $plan;
			}
		}

		$plan['done'] = true;
		delete_option( self::CURSOR_OPTION );

		return $plan;
	}

	/**
	 * Apply every pair to one value in a single logical pass.
	 *
	 * Applied one after another, a later needle can match what an earlier one wrote: moving
	 * `https://site.test` to `https://site.test/staging` turns the https pair's result into a
	 * match for the protocol-relative pair, and the path goes in twice. So each needle is first
	 * swapped for a marker no needle can match, and only then are the markers swapped for their
	 * replacements. Both steps go through SerializedReplacer, so serialized lengths stay right.
	 *
	 * @param string                         $value Stored value.
	 * @param array<array{0:string,1:string}> $pairs Pairs, longest needle first.
	 * @return string
	 */
	public static function rewrite_value( $value, array $pairs ) {
		$value = (string) $value;
		$mark  = chr( 26 ) . 'tw' . substr( md5( $value ), 0, 8 ) . '-';

		$marked = $value;
		foreach ( array_values( $pairs ) as $i => $pair ) {
			$marked = SerializedReplacer::replace( $marked, $pair[0], $mark . $i . chr( 26 ) );
		}

		if ( $marked === $value ) {
			return $value;
		}

		foreach ( array_values( $pairs ) as $i => $pair ) {
			$marked = SerializedReplacer::replace( $marked, $mark . $i . chr( 26 ), $pair[1] );
		}

		return $marked;
	}

	/**
	 * The source site's own addresses, as its database recorded them.
	 *
	 * Read from the staging options table rather than the backup's marker, which records
	 * only one of the two — and a site served from a subdirectory has them differ.
	 *
	 * @param array $state Restore state.
	 * @return array{siteurl:string,home:string}
	 */
	private static function origin_addresses( array $state ) {
		global $wpdb;

		$found   = array(
			'siteurl' => '',
			'home'    => '',
		);
		$staging = RestoreDatabase::staging_name( $wpdb->prefix . 'options' );

		if ( RestoreDatabase::table_exists( $staging ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name IN ( %s, %s )', $staging, 'siteurl', 'home' ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$found[ (string) $row['option_name'] ] = (string) $row['option_value'];
			}
		}

		if ( '' === $found['siteurl'] || '' === $found['home'] ) {
			$marker   = BackupReconciler::tailwatch_read_completion_marker( (string) ( $state['folder_date'] ?? '' ) );
			$recorded = ( is_array( $marker ) && ! empty( $marker['site_url'] ) ) ? (string) $marker['site_url'] : '';
			foreach ( array( 'siteurl', 'home' ) as $which ) {
				if ( '' === $found[ $which ] ) {
					$found[ $which ] = $recorded;
				}
			}
		}

		return $found;
	}

	/**
	 * The columns worth reading in one table, and the key that identifies its rows.
	 *
	 * @param string $table   Live table name.
	 * @param string $staging Staging table name.
	 * @return array{columns:array<string>,keys:array<string>}
	 */
	private static function shape( $table, $staging ) {
		global $wpdb;

		$shape = array(
			'columns' => array(),
			'keys'    => array(),
		);

		if ( self::is_skipped_table( $table ) || ! RestoreDatabase::table_exists( $staging ) ) {
			return $shape;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema of a staging table this restore created.
		$fields = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $staging ), ARRAY_A );

		// Names come from the backup and $wpdb->update() quotes them without escaping, so
		// only plain ones are written; a key that is not plain cannot address its rows.
		$plain_keys = true;

		foreach ( (array) $fields as $field ) {
			$name  = (string) $field['Field'];
			$plain = 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $name );
			if ( 'PRI' === (string) $field['Key'] ) {
				$shape['keys'][] = $name;
				$plain_keys      = $plain_keys && $plain;
			}
			// Text only. Blobs are left alone: bytes that happen to spell a host are not an address.
			if ( ! $plain || ! preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/i', (string) $field['Type'] ) ) {
				continue;
			}
			// A GUID is a permanent identifier, not a link.
			if ( 'guid' === $name && $table === $wpdb->prefix . 'posts' ) {
				continue;
			}
			$shape['columns'][] = $name;
		}

		if ( ! $plain_keys ) {
			$shape['keys'] = array();
		}

		return $shape;
	}

	/**
	 * The next rows after the cursor that mention an origin host in any text column.
	 *
	 * @param string $staging Staging table.
	 * @param array  $plan    Plan.
	 * @param int    $limit   Rows to read.
	 * @return array|null Rows, or null when the query failed.
	 */
	private static function batch( $staging, array $plan, $limit ) {
		global $wpdb;

		$keys    = (array) $plan['keys'];
		$key_ids = implode( ', ', array_fill( 0, count( $keys ), '%i' ) );
		$args    = array( $staging );
		$after   = '';

		if ( is_array( $plan['cursor'] ) && count( $plan['cursor'] ) === count( $keys ) ) {
			$after = '( ' . $key_ids . ' ) > ( ' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ' ) AND ';
			$args  = array_merge( $args, $keys, array_values( $plan['cursor'] ) );
		}

		list( $clause, $like_args ) = self::mention_clause( $plan );

		$args = array_merge( $args, $like_args, $keys, array( (int) $limit ) );
		$sql  = 'SELECT * FROM %i WHERE ' . $after . $clause . ' ORDER BY ' . $key_ids . ' LIMIT %d';

		// Placeholder counts depend on the table's key and column lists, which a static check
		// cannot follow; every identifier binds through %i and every value through %s/%d.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether any text column of a table holds an origin host.
	 *
	 * @param string $staging Staging table.
	 * @param array  $plan    Plan.
	 * @return bool
	 */
	private static function mentions_origin( $staging, array $plan ) {
		global $wpdb;

		list( $clause, $like_args ) = self::mention_clause( $plan );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE ' . $clause . ' LIMIT 1', array_merge( array( $staging ), $like_args ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return null !== $found;
	}

	/**
	 * `( col LIKE %host% OR ... )` across every text column and origin host.
	 *
	 * @param array $plan Plan.
	 * @return array{0:string,1:array} Clause, and its values in placeholder order.
	 */
	private static function mention_clause( array $plan ) {
		global $wpdb;

		$likes = array();
		$args  = array();
		foreach ( (array) $plan['columns'] as $column ) {
			foreach ( (array) $plan['needles'] as $needle ) {
				$likes[] = '%i LIKE %s';
				$args[]  = $column;
				$args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
			}
		}

		return array( '( ' . implode( ' OR ', $likes ) . ' )', $args );
	}

	/**
	 * Tables whose contents are this plugin's history rather than site data.
	 *
	 * The settings table is rewritten like any other: in staging it holds no running job's
	 * own state, and a migrated site's feature settings may carry the old address.
	 *
	 * @param string $table Live table name.
	 * @return bool
	 */
	private static function is_skipped_table( $table ) {
		global $wpdb;

		foreach ( array( 'TAILWATCH_DB_LOGS_TABLE_NAME', 'TAILWATCH_DB_FILEMON_BASELINE_TABLE', 'TAILWATCH_DB_FILEMON_SCANS_TABLE' ) as $constant ) {
			if ( defined( $constant ) && $wpdb->prefix . constant( $constant ) === $table ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Move on to the next table, forgetting the one just finished.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private static function next_table( array $plan ) {
		++$plan['table_index'];
		$plan['cursor']  = null;
		$plan['columns'] = null;
		$plan['keys']    = null;

		return $plan;
	}

	/**
	 * Pick up progress a killed tick saved but never returned.
	 *
	 * @param array $plan Plan from the restore state.
	 * @return array
	 */
	private static function resume( array $plan ) {
		$saved = get_option( self::CURSOR_OPTION );

		if ( is_array( $saved ) && isset( $saved['run'] ) && $saved['run'] === $plan['run'] && empty( $saved['done'] ) ) {
			return $saved;
		}

		return $plan;
	}

	/**
	 * Replacement pairs, longest needle first, as a list.
	 *
	 * @param array<string,string> $map Needle => replacement.
	 * @return array<array{0:string,1:string}>
	 */
	private static function order_pairs( array $map ) {
		$pairs = array();
		foreach ( $map as $from => $to ) {
			$pairs[] = array( (string) $from, (string) $to );
		}

		usort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b[0] ) - strlen( $a[0] );
			}
		);

		return $pairs;
	}

	/**
	 * An address with its scheme separator and slashes percent-encoded.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function url_encoded( $url ) {
		return str_replace( array( ':', '/' ), array( '%3A', '%2F' ), (string) $url );
	}
}
