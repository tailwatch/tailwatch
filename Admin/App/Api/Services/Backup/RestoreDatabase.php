<?php
/**
 * Tailwatch - Restore Database
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Backup;

use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts a restored database into place without a window where the site has no tables.
 *
 * The import writes into staging tables; this class performs the cutover. The whole
 * cutover is ONE `RENAME TABLE` statement carrying every pair:
 *
 *     RENAME TABLE live TO live_old, staging TO live, live2 TO live2_old, ...
 *
 * MySQL and MariaDB execute a multi-pair rename atomically: a statement containing one
 * impossible pair is rejected in full (errno 1146) and every other table is left
 * untouched. That property removes the usual failure modes: dropping
 * every live table and then renaming leaves no tables AND no old data after a crash
 * between the two, and swapping table by table leaves a mixed schema of new and old after
 * a mid-run failure. Here there is no intermediate state to be interrupted in.
 *
 * The live tables are renamed to `<table>_twold` rather than dropped, because one RENAME
 * cannot move staging into place without first moving live out of the way. They are an
 * artifact of that, not a retained copy: the restore drops them with discard_old() once
 * the site is serving again.
 */
class RestoreDatabase {

	/**
	 * Prefix applied to staging tables the importer writes into.
	 */
	const STAGING_PREFIX = 'twstg_';

	/**
	 * Suffix the outgoing generation is renamed to during the cutover, and dropped from
	 * immediately afterwards.
	 */
	const OLD_SUFFIX = '_twold';

	/**
	 * What the name of a link (foreign key) starts with while repoint_outside_links() is
	 * moving it.
	 */
	const PARKED_LINK = self::STAGING_PREFIX . 'o_';

	/**
	 * What a link may do when the row it leads to is removed or changed, as the server names
	 * it. A link whose rule is not one of these is not written again.
	 */
	const LINK_RULES = array( 'CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION' );

	/**
	 * Whether a table name is one we are willing to put into a statement.
	 *
	 * Identifiers are passed through $wpdb->prepare()'s %i placeholder, which quotes them
	 * safely, so this is defence in depth rather than the only guard — but a restore
	 * builds its table list from an archive, and an archive is attacker-influenced input.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function is_valid_table_name( $table ) {
		return is_string( $table )
			&& '' !== $table
			&& strlen( $table ) <= 64
			&& (bool) preg_match( '/^[A-Za-z0-9_]+$/', $table );
	}

	/**
	 * Log the driver's error, return a sentence the operator can act on.
	 *
	 * $wpdb->last_error is a MySQL diagnostic — untranslated, and phrased in terms of
	 * schema internals. The restore UI renders these strings verbatim in the browser, where
	 * "Table 'x' doesn't exist" would confront someone deciding whether their site is
	 * broken. The detail is what a developer needs, so it goes to the log; the caller gets
	 * the sentence.
	 *
	 * @param string $message Translated, operator-facing description.
	 * @param string $action  Log action slug for the failing step.
	 * @return string $message, always.
	 */
	private static function report_db_error( $message, $action ) {
		global $wpdb;

		if ( '' !== $wpdb->last_error ) {
			Log::error(
				'Restore database step failed: ' . $wpdb->last_error,
				array(
					'feature' => 'restore',
					'action'  => 'restore_db_' . $action . '_failed',
					'origin'  => 'system',
				)
			);
		}

		return $message;
	}

	/**
	 * Staging table name for a live table.
	 *
	 * @param string $table Live table name.
	 * @return string
	 */
	public static function staging_name( $table ) {
		return self::STAGING_PREFIX . $table;
	}

	/**
	 * Retained-generation name for a live table.
	 *
	 * @param string $table Live table name.
	 * @return string
	 */
	public static function old_name( $table ) {
		return $table . self::OLD_SUFFIX;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		if ( ! self::is_valid_table_name( $table ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- schema probe on a caller-supplied table; %s is a literal here, not an identifier.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $found === (string) $table;
	}

	/**
	 * Swap staged tables into place in a single atomic statement.
	 *
	 * Every table must have a staging counterpart before anything moves. That check is
	 * not merely a nicety: a rename naming a table that does not exist fails the WHOLE
	 * statement, so an unchecked list would abort the cutover after the caller believed
	 * it had begun. Checking first turns that into a clean refusal.
	 *
	 * @param array $tables Live table names to swap.
	 * @return array{ok:bool,swapped:array,error:string}
	 */
	public static function swap( array $tables ) {
		global $wpdb;

		$out = array(
			'ok'      => false,
			'swapped' => array(),
			'error'   => '',
		);

		$pairs = array();
		$args  = array();
		// Accumulated locally and only published on success. Reporting a partial list
		// alongside a failure would tell the caller work happened when the statement
		// never ran — the opposite of what this class exists to guarantee.
		$swapped = array();

		foreach ( $tables as $table ) {
			if ( ! self::is_valid_table_name( $table ) ) {
				$out['error'] = __( 'Refusing to restore a table with an unsafe name.', 'tailwatch' );
				return $out;
			}

			$staging = self::staging_name( $table );
			if ( ! self::table_exists( $staging ) ) {
				/* translators: %s: database table name. */
				$out['error'] = sprintf( __( 'Restored data for "%s" is missing; nothing was changed.', 'tailwatch' ), $table );
				return $out;
			}

			// A live table that does not exist yet (a table the backup adds) has nothing
			// to retire — only the staging half of the pair applies.
			if ( self::table_exists( $table ) ) {
				$old = self::old_name( $table );
				if ( self::table_exists( $old ) ) {
					/* translators: %s: database table name. */
					$out['error'] = sprintf( __( 'A previous restore of "%s" has not been cleared yet.', 'tailwatch' ), $table );
					return $out;
				}
				$pairs[] = '%i TO %i';
				$args[]  = $table;
				$args[]  = $old;
			}

			$pairs[] = '%i TO %i';
			$args[]  = $staging;
			$args[]  = $table;

			$swapped[] = $table;
		}

		if ( empty( $pairs ) ) {
			$out['error'] = __( 'Nothing to restore.', 'tailwatch' );
			return $out;
		}

		// One statement, every pair — the cutover is only atomic if it is a single
		// RENAME, so this list cannot be looped.
		//
		// $pairs holds nothing but the constant string '%i TO %i' repeated once per
		// rename; no caller data reaches the query text. Every table name is bound
		// through prepare()'s %i identifier placeholder. PHPCS cannot see those
		// placeholders because the statement is assembled with implode(), which is what
		// UnfinishedPrepare is reporting.
		$sql = $wpdb->prepare( 'RENAME TABLE ' . implode( ', ', $pairs ), $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- query text is the constant '%i TO %i' repeated; all identifiers bound via %i.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- cutover; $sql built via prepare() above.
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			// The statement is atomic, so a failure means nothing moved.
			$out['error'] = self::report_db_error( __( 'The database cutover failed.', 'tailwatch' ), 'swap' );
			return $out;
		}

		$out['ok']      = true;
		$out['swapped'] = $swapped;
		return $out;
	}

	/**
	 * Drop the generation the cutover renamed aside.
	 *
	 * Separate from swap() because the timing matters: while the site is still coming back
	 * up those tables are the only thing that could put it right, so they are dropped once
	 * it is serving again rather than as part of the rename itself.
	 *
	 * @param array $tables Live table names whose retained copies should be dropped.
	 * @return array{ok:bool,dropped:array,left:array,error:string} `left` names the copies that are still there.
	 */
	public static function discard_old( array $tables ) {
		$out = array(
			'ok'      => true,
			'dropped' => array(),
			'left'    => array(),
			'error'   => '',
		);

		$retained = array();
		foreach ( $tables as $table ) {
			if ( ! self::is_valid_table_name( $table ) ) {
				continue;
			}
			$old = self::old_name( $table );
			if ( self::table_exists( $old ) ) {
				$retained[] = $old;
			}
		}

		$result         = self::drop_linked( $retained );
		$out['dropped'] = $result['dropped'];
		$out['left']    = $result['left'];

		if ( ! empty( $result['left'] ) ) {
			$out['ok']    = false;
			$out['error'] = self::report_db_error( __( 'Could not remove a retained table.', 'tailwatch' ), 'discard_old' );
		}

		return $out;
	}

	/**
	 * Drop tables of ours that may be linked to one another.
	 *
	 * A table that another one links to cannot be dropped ahead of it, and the order the
	 * names arrive in is the dump's. So the list is gone through again for as long as a
	 * pass removed something: each pass frees the tables its own removals were holding.
	 *
	 * @param array $names Names of tables that are this restore's own to remove.
	 * @return array{dropped:array,left:array}
	 */
	private static function drop_linked( array $names ) {
		global $wpdb;

		$out = array(
			'dropped' => array(),
			'left'    => array_values( $names ),
		);

		// A refusal on one pass is expected and answered by the next, so it is not shown.
		$shown = $wpdb->suppress_errors( true );

		do {
			$waiting = array();

			foreach ( $out['left'] as $name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- dropping this restore's own staging or retained copy; identifier bound via %i.
				if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name ) ) ) {
					$waiting[] = $name;
				} else {
					$out['dropped'][] = $name;
				}
			}

			$progressed  = count( $waiting ) < count( $out['left'] );
			$out['left'] = $waiting;
		} while ( $progressed && ! empty( $out['left'] ) );

		$wpdb->suppress_errors( $shown );

		return $out;
	}

	/**
	 * Remove, from the staged tables, every link to a table this restore did not stage.
	 *
	 * The importer points a staged table's links at the staging copies of the tables they
	 * lead to, so that the cutover carries each link over with its table. A table the dump
	 * does not hold has no staging copy. A link to one would lead nowhere once the staged
	 * table is live, and the server would then refuse every write to that table over it.
	 *
	 * @param array $tables Live names of the tables this restore staged.
	 * @return array{ok:bool,removed:array} `removed` names each table that lost a link, with the table it led to.
	 */
	public static function drop_unmet_links( array $tables ) {
		global $wpdb;

		$out = array(
			'ok'      => true,
			'removed' => array(),
		);

		$staged = array();
		foreach ( $tables as $table ) {
			if ( self::is_valid_table_name( $table ) ) {
				$staged[ self::staging_name( $table ) ] = $table;
			}
		}

		if ( empty( $staged ) ) {
			return $out;
		}

		$like = $wpdb->esc_like( self::STAGING_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the server's own record of the links on this restore's staging tables.
		$links = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS holder, CONSTRAINT_NAME AS link, REFERENCED_TABLE_NAME AS target FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND REFERENCED_TABLE_NAME LIKE %s', $like, $like ), ARRAY_A );

		foreach ( (array) $links as $row ) {
			$holder = isset( $row['holder'] ) ? (string) $row['holder'] : '';
			$target = isset( $row['target'] ) ? (string) $row['target'] : '';
			$link   = isset( $row['link'] ) ? (string) $row['link'] : '';

			// LIKE is collation-dependent, so both names are checked again here: only a table
			// this restore staged is altered, and only for a link to a staging copy it lacks.
			if ( '' === $link || ! isset( $staged[ $holder ] ) || 0 !== strpos( $target, self::STAGING_PREFIX ) || isset( $staged[ $target ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- a link on this restore's own staging table; identifiers bound via %i.
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP FOREIGN KEY %i', $holder, $link ) ) ) {
				$out['ok'] = false;
				self::report_db_error( '', 'drop_unmet_link' );
				continue;
			}

			$out['removed'][] = $staged[ $holder ] . ' -> ' . (string) substr( $target, strlen( self::STAGING_PREFIX ) );
		}

		return $out;
	}

	/**
	 * The name a link is staged under.
	 *
	 * The server keeps the names of links unique across the whole database, and the live
	 * table still holds the name the dump gives. So a staged link takes a name of its own,
	 * made from the run, its table and the name it stands in for, and is given the real one
	 * once the live table's copy is gone: see name_links().
	 *
	 * @param string $run   What tells one restore from another.
	 * @param string $table Live name of the table that holds the link.
	 * @param string $name  The link's name in the dump.
	 * @return string
	 */
	public static function staged_link_name( $run, $table, $name ) {
		return self::STAGING_PREFIX . 'k_' . substr( md5( $run . '|' . $table . '|' . $name ), 0, 24 );
	}

	/**
	 * The name a link carries between the two statements that move it.
	 *
	 * It holds the link's own name, so a move cut short between the two is finished by the
	 * next pass. A name too long to be held this way is stood in for, and is not found again.
	 *
	 * @param string $table Table that holds the link.
	 * @param string $name  The link's own name.
	 * @return string
	 */
	private static function parked_link_name( $table, $name ) {
		$parked = self::PARKED_LINK . $name;

		return strlen( $parked ) <= 64 ? $parked : self::staged_link_name( 'outside', $table, $name );
	}

	/**
	 * The link's own name, from the one it may be parked under.
	 *
	 * @param string $name A link's name as the server holds it.
	 * @return string
	 */
	private static function unparked_link_name( $name ) {
		$own = 0 === strpos( $name, self::PARKED_LINK ) ? (string) substr( $name, strlen( self::PARKED_LINK ) ) : '';

		return '' !== $own ? $own : $name;
	}

	/**
	 * Point links from tables outside the restore at the restored tables.
	 *
	 * The cutover renames each live table aside, and the server keeps every link on its table
	 * through a rename. A table the restore did not replace, and which links to one it did,
	 * is therefore left tied to the copy set aside: that copy can no longer be dropped, the
	 * table checks its rows against it, and the next restore is refused at its cutover.
	 *
	 * Each such link is added again, leading to the table now live under that name. One
	 * statement cannot drop a link and add one of the same name, so it takes two, the first
	 * under the name parked_link_name() gives; a link found under such a name was moved by a
	 * pass that was cut short, and is given its own back. No row is checked: the table's rows
	 * are its own, and may refer to entries the backup does not hold. A link the restored
	 * table cannot take is removed, so that the copy can still be dropped.
	 *
	 * @param array $tables Live names of the tables whose copies set aside are about to be dropped.
	 * @return array{moved:array,removed:array,left:array} Each entry names a table and the table its link leads to.
	 */
	public static function repoint_outside_links( array $tables ) {
		global $wpdb;

		$out = array(
			'moved'   => array(),
			'removed' => array(),
			'left'    => array(),
		);

		$aside = array();
		foreach ( $tables as $table ) {
			if ( self::is_valid_table_name( $table ) ) {
				$aside[ self::old_name( $table ) ] = $table;
			}
		}

		if ( empty( $aside ) ) {
			return $out;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the server's own record of the links that lead to a copy set aside, or are parked.
		$links = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS holder, CONSTRAINT_NAME AS link, REFERENCED_TABLE_NAME AS target FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE() AND ( REFERENCED_TABLE_NAME LIKE %s OR CONSTRAINT_NAME LIKE %s )', '%' . $wpdb->esc_like( self::OLD_SUFFIX ), $wpdb->esc_like( self::PARKED_LINK ) . '%' ), ARRAY_A );

		$outside = array();
		foreach ( (array) $links as $row ) {
			$holder = isset( $row['holder'] ) ? (string) $row['holder'] : '';
			$target = isset( $row['target'] ) ? (string) $row['target'] : '';
			$link   = isset( $row['link'] ) ? (string) $row['link'] : '';

			// Never from one of these copies, nor from a staging copy: those go with the
			// restore's own tables.
			if ( '' === $link || isset( $aside[ $holder ] ) || 0 === strpos( $holder, self::STAGING_PREFIX ) ) {
				continue;
			}

			if ( isset( $aside[ $target ] ) ) {
				$outside[] = array( $holder, $link, $aside[ $target ], true );
			} elseif ( 0 === strpos( $link, self::PARKED_LINK ) && in_array( $target, $aside, true ) ) {
				// Moved to the restored table already, and still to be given its own name.
				$outside[] = array( $holder, $link, $target, false );
			}
		}

		if ( empty( $outside ) ) {
			return $out;
		}

		return self::without_link_checks(
			static function () use ( $outside, $out ) {
				global $wpdb;

				foreach ( $outside as list( $holder, $link, $live, $holds_copy ) ) {
					$said       = $holder . ' -> ' . $live;
					$definition = self::link_definition( $holder, $link );
					$own        = self::unparked_link_name( $link );
					$moved      = false;

					if ( null !== $definition && self::table_exists( $live ) ) {
						if ( $own !== $link ) {
							$moved = ! self::link_name_taken( $own ) && self::relink( $holder, $link, $own, $definition, $live );
						} else {
							$parked = self::parked_link_name( $holder, $link );
							$moved  = self::relink( $holder, $link, $parked, $definition, $live );

							if ( $moved ) {
								// Refused here, the link works as it is, and the next pass asks again.
								self::relink( $holder, $parked, $link, $definition, $live );
							}
						}
					}

					if ( $moved ) {
						$out['moved'][] = $said;
						continue;
					}

					// It leads to the restored table and holds no copy in place.
					if ( ! $holds_copy ) {
						continue;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- a link that holds a discarded copy in place; identifiers bound via %i.
					if ( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP FOREIGN KEY %i', $holder, $link ) ) ) {
						$out['removed'][] = $said;
						continue;
					}

					self::report_db_error( '', 'repoint_outside_link' );
					$out['left'][] = $said;
				}

				return $out;
			}
		);
	}

	/**
	 * Give the restored tables' links the names the backup holds for them.
	 *
	 * See staged_link_name(). Called once the copies set aside are dropped, because until
	 * then they hold these names. A name that is still taken, or that the server will not
	 * take, leaves its link under the name it was staged with; the link works either way.
	 *
	 * @param array $links Each with `table`, the live table; `temp`, the name its link was
	 *                     staged under; and `name`, the one it is to carry.
	 * @return array{named:int,left:array} `left` names the tables whose link kept its staging name.
	 */
	public static function name_links( array $links ) {
		$out = array(
			'named' => 0,
			'left'  => array(),
		);

		$staged = array();
		foreach ( $links as $link ) {
			$table = isset( $link['table'] ) ? (string) $link['table'] : '';
			$temp  = isset( $link['temp'] ) ? (string) $link['temp'] : '';
			$name  = isset( $link['name'] ) ? (string) $link['name'] : '';

			if ( self::is_valid_table_name( $table ) && 0 === strpos( $temp, self::STAGING_PREFIX ) ) {
				$staged[] = array( $table, $temp, $name );
			}
		}

		if ( empty( $staged ) ) {
			return $out;
		}

		return self::without_link_checks(
			static function () use ( $staged, $out ) {
				foreach ( $staged as list( $table, $temp, $name ) ) {
					$definition = self::link_definition( $table, $temp );

					// Named already, on an earlier pass.
					if ( null === $definition ) {
						continue;
					}

					if ( '' !== $name && strlen( $name ) <= 64 && ! self::link_name_taken( $name ) && self::relink( $table, $temp, $name, $definition, $definition['target'] ) ) {
						++$out['named'];
						continue;
					}

					self::report_db_error( '', 'name_link' );
					$out['left'][] = $table;
				}

				$out['left'] = array_values( array_unique( $out['left'] ) );

				return $out;
			}
		);
	}

	/**
	 * A link as the server records it: its columns, the table it leads to and that table's
	 * columns, and its two rules.
	 *
	 * Read from the server and not from the dump, so what is written back is what the
	 * database itself holds.
	 *
	 * @param string $table Table that holds the link.
	 * @param string $link  The link's name.
	 * @return array{columns:array,target:string,target_columns:array,on_delete:string,on_update:string,local:bool}|null
	 *         Null when the table holds no link of that name. `local` is whether the table
	 *         it leads to is in this database.
	 */
	private static function link_definition( $table, $link ) {
		global $wpdb;

		// Each of the server's two tables is asked on its own, with the database and the
		// table named. Asked as one joined question, the server reads every table it can see.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the server's own record of one link's rules.
		$rules = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS holder, DELETE_RULE AS on_delete, UPDATE_RULE AS on_update FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s', $table, $link ), ARRAY_A );

		$out = null;

		foreach ( (array) $rules as $row ) {
			// The comparison above follows the server's collation; the table is matched to the letter here.
			if ( (string) $row['holder'] !== (string) $table ) {
				continue;
			}

			$out = array(
				'columns'        => array(),
				'target'         => '',
				'target_columns' => array(),
				'on_delete'      => strtoupper( (string) $row['on_delete'] ),
				'on_update'      => strtoupper( (string) $row['on_update'] ),
				'local'          => true,
			);
			break;
		}

		if ( null === $out ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the server's own record of one link's columns.
		$columns = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS holder, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS target, REFERENCED_COLUMN_NAME AS target_col, ( REFERENCED_TABLE_SCHEMA = TABLE_SCHEMA ) AS same_database FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY ORDINAL_POSITION', $table, $link ), ARRAY_A );

		foreach ( (array) $columns as $row ) {
			if ( (string) $row['holder'] !== (string) $table ) {
				continue;
			}

			$out['columns'][]        = (string) $row['col'];
			$out['target_columns'][] = (string) $row['target_col'];
			$out['target']           = (string) $row['target'];
			$out['local']            = $out['local'] && ! empty( $row['same_database'] );
		}

		return empty( $out['columns'] ) ? null : $out;
	}

	/**
	 * Whether any table in this database holds a link of this name.
	 *
	 * @param string $name Link name.
	 * @return bool
	 */
	private static function link_name_taken( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the server's own record of link names.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = %s', $name ) ) > 0;
	}

	/**
	 * The rule the server gives a link that states none, as the server records it.
	 *
	 * Servers from version 8.0 record it as NO ACTION, earlier ones as RESTRICT. The other
	 * server family records RESTRICT at every version, and is told apart by the name in its
	 * version string, as its version numbers overlap. A server leaves its own default out of
	 * a table's description and prints the other.
	 *
	 * @return string An entry of LINK_RULES.
	 */
	private static function unstated_rule() {
		global $wpdb;

		$is_mariadb = ( false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' ) );

		return ( ! $is_mariadb && version_compare( (string) $wpdb->db_version(), '8.0', '>=' ) ) ? 'NO ACTION' : 'RESTRICT';
	}

	/**
	 * Drop one link of a table and add another in its place, in a single statement.
	 *
	 * A rule the server gives unasked is left unstated, so the table's description reads as
	 * it did before.
	 *
	 * @param string $table      Table that holds the link.
	 * @param string $drop       Name of the link to drop.
	 * @param string $add        Name of the link to add; not the one being dropped.
	 * @param array  $definition From link_definition().
	 * @param string $target     Table the new link leads to, in this database.
	 * @return bool False when the link cannot be written again, or the server refused.
	 */
	private static function relink( $table, $drop, $add, array $definition, $target ) {
		global $wpdb;

		$on_delete = array_search( $definition['on_delete'], self::LINK_RULES, true );
		$on_update = array_search( $definition['on_update'], self::LINK_RULES, true );
		$count     = count( $definition['columns'] );

		if ( empty( $definition['local'] ) || false === $on_delete || false === $on_update || $count < 1 || count( $definition['target_columns'] ) !== $count ) {
			return false;
		}

		$columns  = implode( ', ', array_fill( 0, $count, '%i' ) );
		$names    = array_merge( array( $table, $drop, $add ), $definition['columns'], array( $target ), $definition['target_columns'] );
		$unstated = self::unstated_rule();
		$rules    = '';

		if ( self::LINK_RULES[ $on_delete ] !== $unstated ) {
			$rules .= ' ON DELETE ' . self::LINK_RULES[ $on_delete ];
		}

		if ( self::LINK_RULES[ $on_update ] !== $unstated ) {
			$rules .= ' ON UPDATE ' . self::LINK_RULES[ $on_update ];
		}

		// The text is made of constant pieces only: '%i' once per column, and rules that are
		// entries of LINK_RULES. Every name is bound through prepare()'s %i placeholder:
		// $names holds one for each, which PHPCS cannot count in a statement put together
		// this way.
		$sql = $wpdb->prepare( 'ALTER TABLE %i DROP FOREIGN KEY %i, ADD CONSTRAINT %i FOREIGN KEY (' . $columns . ') REFERENCES %i (' . $columns . ')' . $rules, $names ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- constant fragments only; all identifiers bound via %i, one name per placeholder.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one link of a table this restore answers for; $sql built via prepare() above.
		return false !== $wpdb->query( $sql );
	}

	/**
	 * Do a piece of work with the server's checking of links switched off, then put the
	 * setting back as it was.
	 *
	 * With it off the server adds a link without reading the table's rows, so the change is
	 * to the table's description alone, whatever the table's size. A refusal inside is
	 * answered by the caller, so it is not shown.
	 *
	 * @param callable $work The work.
	 * @return mixed What the work returned.
	 */
	private static function without_link_checks( callable $work ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- session setting, read to put it back.
		$was   = $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' );
		$shown = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- session setting for the statements below.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		try {
			return $work();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- session setting, put back.
			$wpdb->query( $wpdb->prepare( 'SET FOREIGN_KEY_CHECKS = %d', '0' === (string) $was ? 0 : 1 ) );
			$wpdb->suppress_errors( $shown );
		}
	}

	/**
	 * Drop every staging table an earlier restore left behind, named or not.
	 *
	 * discard_staging() can only drop what a run recorded, and a run records a table when
	 * the tick that created it finishes. DDL commits the moment it runs and cannot be
	 * rolled back, so a tick killed mid-import - a PHP timeout, a host cutting the request,
	 * a fatal in something else on the site - leaves staging tables that nothing afterwards
	 * can name. They then outlive every attempt to tidy up, and the next restore meets them
	 * on its first CREATE and stops with "table already exists" about a table the operator
	 * never made. Which reads as a fault in their database rather than as litter from the
	 * attempt before.
	 *
	 * Nothing but the importer writes a table under this prefix: it is scratch, emptied
	 * into the live names by the swap and dropped on the way out. The undo generation is
	 * a different thing and is never touched - a `_twold` table is the only way back from
	 * a restore that finished, and losing it would turn a reversible restore into a final
	 * one.
	 *
	 * Called once, at the start of a restore. Not per tick: the import runs across many of
	 * them and a sweep in the middle would take this restore's own staging with it.
	 * Restores are serialised by the process manager, so the only staging that can be here
	 * at that moment belongs to a run that is over.
	 *
	 * @return int Number of staging tables removed.
	 */
	public static function discard_stale_staging() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- listing our own scratch tables; the pattern is a constant escaped with esc_like().
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::STAGING_PREFIX ) . '%' ) );

		$scratch = array();

		foreach ( (array) $found as $name ) {
			$name = (string) $name;

			// LIKE is collation-dependent and the list comes back from the server, so the
			// prefix is checked again here rather than assumed from the pattern.
			if ( ! self::is_valid_table_name( $name ) || 0 !== strpos( $name, self::STAGING_PREFIX ) ) {
				continue;
			}

			// Belt and braces: an undo generation is never scratch, whatever it is called.
			if ( substr( $name, -strlen( self::OLD_SUFFIX ) ) === self::OLD_SUFFIX ) {
				continue;
			}

			$scratch[] = $name;
		}

		$result = self::drop_linked( $scratch );

		return count( $result['dropped'] );
	}

	/**
	 * Remove staging tables left behind by an abandoned restore.
	 *
	 * @param array $tables Live table names whose staging copies should be dropped.
	 * @return int Number of staging tables removed.
	 */
	public static function discard_staging( array $tables ) {
		$scratch = array();

		foreach ( $tables as $table ) {
			if ( ! self::is_valid_table_name( $table ) ) {
				continue;
			}
			$staging = self::staging_name( $table );
			if ( self::table_exists( $staging ) ) {
				$scratch[] = $staging;
			}
		}

		$result = self::drop_linked( $scratch );

		return count( $result['dropped'] );
	}
}
