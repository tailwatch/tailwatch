<?php
/**
 * Tailwatch - Restore SQL Importer
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
use Tailwatch\Admin\App\Api\Services\TickBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams a SQL dump into staging tables, resumably.
 *
 * Three properties:
 *
 * **It streams.** The file is read through a handle and never held in memory, so a large
 * table costs a read buffer rather than its whole size in parsing.
 *
 * **It resumes by byte offset.** A tick consumes a byte budget, records ftell(), and
 * returns; the next tick seeks straight back, so a dump larger than one PHP lifetime
 * still imports, even on a short-timeout host.
 *
 * **It never reports success over a failed statement.** Every $wpdb->query() return value
 * is checked, so a failed INSERT can never leave a run ending "complete".
 *
 * No transaction is used, and that is deliberate rather than an omission: DDL forces an
 * implicit commit in MySQL, so a transaction spanning CREATE TABLE would be silently
 * broken anyway. The atomicity guarantee lives one level up — everything here writes to
 * staging tables that RestoreDatabase swaps in a single statement, so an interrupted
 * import leaves an incomplete staging set that is simply never swapped in.
 *
 * ## Statement splitting
 *
 * Statements are split on ";\n", which is exact for the INSERT half of a dump this plugin
 * wrote. BackupDbController::generate_sql() escapes every control character in a value —
 * `"\x0a"` becomes the two-character sequence `\n` — so a raw newline cannot occur inside
 * a quoted string, and the terminator is unambiguous without tokenising SQL. That is a
 * real coupling between the two classes: if the dumper's escaping ever changes, this must
 * change with it.
 *
 * The schema half rests on a SECOND, weaker assumption worth stating plainly. CREATE TABLE
 * statements are `SHOW CREATE TABLE` output written verbatim, and that output contains raw
 * newlines by construction — one per column. Splitting it on ";\n" is safe only because
 * MySQL escapes control characters inside COMMENT and DEFAULT string literals, so a `;`
 * immediately followed by a real newline cannot appear inside a quoted clause. That is a
 * property of the server rather than of this codebase, and it is the one part of the
 * splitting contract this project cannot prove from its own source.
 */
class RestoreSqlImporter {

	/**
	 * Bytes of dump consumed per tick before yielding.
	 */
	const BYTE_BUDGET = 5242880;

	/**
	 * Read size when filling the statement buffer.
	 */
	const READ_CHUNK = 262144;

	/**
	 * Longest single statement accepted.
	 *
	 * A dump statement larger than this is refused rather than executed, because the
	 * likely causes — a truncated file, or a terminator that never arrives — are both
	 * cases where guessing is worse than stopping.
	 */
	const MAX_STATEMENT = 33554432;

	/**
	 * rewrite_target() outcomes.
	 *
	 * UNRECOGNISED — the statement targets no table we handle; skipped by design.
	 * REFUSED      — it targets a table we will not stage; counted as a failure so the
	 *                restore can never report success having dropped it.
	 * LEFT_OUT     — it targets a table that has no place on this site; reported by name
	 *                and not staged.
	 */
	const TARGET_OK           = 'ok';
	const TARGET_UNRECOGNISED = 'unrecognised';
	const TARGET_REFUSED      = 'refused';
	const TARGET_LEFT_OUT     = 'left_out';

	/**
	 * Import the next slice of a dump.
	 *
	 * @param string $sql_file    Dump to read.
	 * @param int    $offset      Byte offset to resume from.
	 * @param string $staging_pfx Prefix to rewrite table names to.
	 * @param int    $deadline    Unix timestamp to stop at (0 = no limit).
	 * @param array  $rename      For a dump taken under another table prefix: `from`, the prefix
	 *                            it was taken under, and `to`, the one this site uses. Empty
	 *                            when every table keeps the name the dump gives it.
	 * @param string $run         What tells this restore from another; it goes into the names
	 *                            the staged links are given.
	 * @return array{next_offset:int,statements:int,failed:int,tables:array,converted:bool,left_out:array,links:array,eof:bool,errors:array,said:bool}
	 */
	public static function import_chunk( $sql_file, $offset = 0, $staging_pfx = RestoreDatabase::STAGING_PREFIX, $deadline = 0, array $rename = array(), $run = '' ) {
		global $wpdb;

		$out = array(
			'next_offset' => (int) $offset,
			'statements'  => 0,
			'failed'      => 0,
			// By the name each takes on this site.
			'tables'      => array(),
			// Whether any table was given this site's prefix in place of the dump's.
			'converted'   => false,
			'left_out'    => array(),
			// Links between tables, each staged under a name of its own: the table that
			// holds it, that name, and the name it is to carry once the restore is over.
			'links'       => array(),
			'eof'         => false,
			'errors'      => array(),
			// Whether the driver's own account of the first failure has been recorded.
			// One per chunk: every statement after the first tends to fail for the same
			// reason, and a hundred copies of it buries the table names underneath.
			'said'        => false,
		);

		if ( '' === (string) $sql_file || ! is_file( $sql_file ) ) {
			$out['errors'][] = __( 'The database file is missing.', 'tailwatch' );
			return $out;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- streamed read of a large dump; WP_Filesystem cannot seek. Checked immediately.
		$handle = @fopen( $sql_file, 'rb' );
		if ( false === $handle ) {
			$out['errors'][] = __( 'The database file could not be opened.', 'tailwatch' );
			return $out;
		}
		if ( $offset > 0 && 0 !== fseek( $handle, (int) $offset ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
			fclose( $handle );
			$out['errors'][] = __( 'Could not resume the database file at its saved position.', 'tailwatch' );
			return $out;
		}

		// Referential integrity is restored by the swap, not during the load: rows arrive
		// table by table, so a constraint would fire on data that is merely not there yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- session setting for the import.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		$buffer   = '';
		$consumed = 0;
		$position = (int) $offset;

		while ( true ) {
			$terminator = strpos( $buffer, ";\n" );

			if ( false === $terminator ) {
				if ( strlen( $buffer ) > self::MAX_STATEMENT ) {
					$out['errors'][] = __( 'A statement in the database file is unreasonably long; the file may be truncated.', 'tailwatch' );
					break;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming read; see fopen above.
				$read = fread( $handle, self::READ_CHUNK );
				if ( false === $read || '' === $read ) {
					// End of file: whatever remains is the last statement, if any.
					$tail = trim( $buffer );
					if ( '' !== $tail ) {
						self::run( $tail, $staging_pfx, $out, $rename, $run );
						$position += strlen( $buffer );
					}
					$out['eof'] = true;
					break;
				}
				$buffer .= $read;
				continue;
			}

			$statement = substr( $buffer, 0, $terminator + 1 );
			$length    = $terminator + 2; // Includes the ";\n" terminator.
			$buffer    = substr( $buffer, $length );

			$position += $length;
			$consumed += $length;

			$trimmed = trim( $statement );
			if ( '' !== $trimmed ) {
				self::run( $trimmed, $staging_pfx, $out, $rename, $run );
			}

			// Yield on a statement boundary only, so a resumed tick never has to reason
			// about half a statement. The byte budget bounds what this tick READS; it says
			// nothing about what a single wide INSERT costs to buffer and run, which is the
			// allocation that actually ends a dump import.
			if ( $consumed >= self::BYTE_BUDGET || TickBudget::spent( $deadline ) ) {
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
		fclose( $handle );

		$out['next_offset'] = $position;
		$out['tables']      = array_values( array_unique( $out['tables'] ) );
		$out['left_out']    = array_values( array_unique( $out['left_out'] ) );

		return $out;
	}

	/**
	 * Execute one statement, rewritten to the staging prefix.
	 *
	 * @param string $statement   Complete SQL statement, with or without its terminator.
	 * @param string $staging_pfx Staging prefix.
	 * @param array  $out         Result, by reference.
	 * @param array  $rename      The two table prefixes; see import_chunk().
	 * @param string $run         What tells this restore from another; see import_chunk().
	 * @return void
	 */
	private static function run( $statement, $staging_pfx, array &$out, array $rename = array(), $run = '' ) {
		global $wpdb;

		$target = self::rewrite_target( $statement, $staging_pfx, $rename, $run );

		if ( self::TARGET_UNRECOGNISED === $target['status'] ) {
			// Not a statement we recognise as targeting a table. Skipping is the safe
			// choice: executing an unrecognised statement from a dump would run it
			// against the LIVE schema, outside the staging tables the swap protects.
			return;
		}

		if ( self::TARGET_LEFT_OUT === $target['status'] ) {
			$out['left_out'][] = $target['table'];
			return;
		}

		if ( self::TARGET_REFUSED === $target['status'] ) {
			// Recognised as targeting a table, but the name cannot be staged. This MUST
			// be counted: skipping it silently would drop the table from the restore
			// while the run still reported success — the exact silent-loss failure this
			// module exists to remove. The common cause is length: a live name of 59+
			// characters exceeds MySQL's 64-char limit once the staging prefix is added,
			// and the dumper applies no such cap, so the table backs up fine.
			++$out['failed'];
			if ( count( $out['errors'] ) < 10 ) {
				/* translators: 1: database table name, 2: reason the table was refused. */
				$out['errors'][] = sprintf( __( 'Cannot restore "%1$s": %2$s', 'tailwatch' ), $target['table'], $target['reason'] );
			}
			return;
		}

		$rewritten  = $target['statement'];
		$live_table = $target['table'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a complete statement from a dump this plugin wrote; the table identifier is rewritten and validated above, and values were escaped at dump time.
		$result = $wpdb->query( $rewritten );

		if ( false === $result ) {
			++$out['failed'];

			// Said before the table name, so it reads first. Without it the operator is
			// handed a list of tables and no reason, which is the same as no report: the
			// causes are all server-side and all invisible from here - a packet limit
			// below the 1MB statements the dumper writes, a strict sql_mode refusing dates
			// the schema itself allows, a connection dropped mid-load. Naming which one
			// turns a dead end into one setting to change.
			if ( ! $out['said'] && '' !== $wpdb->last_error ) {
				$out['said']     = true;
				$out['errors'][] = sprintf(
					/* translators: %s: the database driver's own error message. */
					__( 'The database refused the first statement this restore tried. MySQL said: %s', 'tailwatch' ),
					self::driver_message( $wpdb->last_error )
				);
			}
			// The driver's message is a schema-level diagnostic and is not translated;
			// the restore UI shows these strings to the operator verbatim, so it goes to
			// the log and they get the table name they can act on.
			if ( '' !== $wpdb->last_error ) {
				Log::error(
					'Restore statement failed on "' . $live_table . '": ' . self::driver_message( $wpdb->last_error ),
					array(
						'feature' => 'restore',
						'action'  => 'restore_statement_failed',
						'origin'  => 'system',
					)
				);
			}
			if ( count( $out['errors'] ) < 10 ) {
				/* translators: %s: database table name. */
				$out['errors'][] = sprintf( __( 'Failed to restore into "%s".', 'tailwatch' ), $live_table );
			}
			return;
		}

		++$out['statements'];
		$out['tables'][] = $live_table;

		if ( $target['converted'] ) {
			$out['converted'] = true;
		}

		foreach ( $target['links'] as $link ) {
			$out['links'][] = $link;
		}
	}

	/**
	 * A driver error, fit to show an operator and safe to paste into a support thread.
	 *
	 * MySQL quotes the offending SQL back at you - "...near 'INSERT INTO `wp_users` VALUES
	 * ('someone@example.com'..." - and this message goes into a log with a Copy button on
	 * it. The fragment is the one part that carries row data, so it is cut at `near` and
	 * the rest kept: what is left is the diagnosis, which is all that was wanted from it.
	 *
	 * @param string $error Raw message from the driver.
	 * @return string
	 */
	private static function driver_message( $error ) {
		$error = (string) $error;

		// Cut before trimming, and on the word rather than on a leading space, so a
		// message that is nothing BUT the quoted fragment comes back empty instead of
		// coming back whole.
		if ( preg_match( '/\s+near\s+\'/i', $error, $m, PREG_OFFSET_CAPTURE ) ) {
			$error = substr( $error, 0, (int) $m[0][1] );
		}

		// Others quote the row value itself: "Duplicate entry 'someone@example.com' for key",
		// "Incorrect integer value: '...' for column". The value goes, the diagnosis stays.
		$error = (string) preg_replace( '/\b(entry|value:?)\s+\'[^\']*\'/i', '$1 \'[value]\'', $error );

		$error = trim( $error, " \t\n\r\0\x0B;," );

		if ( strlen( $error ) > 300 ) {
			$error = rtrim( substr( $error, 0, 300 ) ) . '...';
		}

		return '' === $error ? __( 'no reason given', 'tailwatch' ) : $error;
	}

	/**
	 * Whether a table is the restore's own machinery rather than the site's data.
	 *
	 * The same two markers the dumper filters on, applied again on the way in so a
	 * backup written without that filter can still be restored.
	 *
	 * @param string $table Live table name from the dump.
	 * @return bool
	 */
	private static function is_internal_table( $table ) {
		$table = (string) $table;

		if ( 0 === strpos( $table, RestoreDatabase::STAGING_PREFIX ) ) {
			return true;
		}

		$suffix = RestoreDatabase::OLD_SUFFIX;

		return substr( $table, -strlen( $suffix ) ) === $suffix;
	}

	/**
	 * Rewrite a statement's target table to the staging prefix.
	 *
	 * Only the identifier immediately following CREATE TABLE / INSERT INTO / DROP TABLE
	 * is touched — matched at the START of the statement — so nothing inside row data can
	 * be altered by this.
	 *
	 * A refusal is reported distinctly from "this statement targets no table", because
	 * the two mean opposite things: the second is a statement we deliberately skip, the
	 * first is a table the caller asked for and will not get.
	 *
	 * For a dump taken under another table prefix the table is staged under the name it
	 * takes on this site, and that is the name reported back, so the swap and everything
	 * after it works with this site's names alone.
	 *
	 * @param string $statement   Statement.
	 * @param string $staging_pfx Staging prefix.
	 * @param array  $rename      The two table prefixes; see import_chunk().
	 * @param string $run         What tells this restore from another; see import_chunk().
	 * @return array{status:string,statement:string,table:string,reason:string,converted:bool,links:array}
	 */
	private static function rewrite_target( $statement, $staging_pfx, array $rename = array(), $run = '' ) {
		$patterns = array(
			'create' => '/^(\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`([^`]+)`/i',
			'insert' => '/^(\s*INSERT\s+(?:IGNORE\s+)?INTO\s+)`([^`]+)`/i',
			'drop'   => '/^(\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?)`([^`]+)`/i',
		);

		$unrecognised = array(
			'status'    => self::TARGET_UNRECOGNISED,
			'statement' => '',
			'table'     => '',
			'reason'    => '',
			'converted' => false,
			'links'     => array(),
		);

		$from = isset( $rename['from'] ) ? (string) $rename['from'] : '';
		$to   = isset( $rename['to'] ) ? (string) $rename['to'] : '';

		foreach ( $patterns as $kind => $pattern ) {
			if ( ! preg_match( $pattern, $statement, $m ) ) {
				continue;
			}

			$live = $m[2];
			if ( ! RestoreDatabase::is_valid_table_name( $live ) ) {
				return array(
					'status'    => self::TARGET_REFUSED,
					'statement' => '',
					'table'     => $live,
					'reason'    => 'the table name is not one this plugin will put into a statement',
					'converted' => false,
					'links'     => array(),
				);
			}

			// A backup taken while a previous restore's undo point was still on disk
			// can contain the restore's own workings: the retained `<table>_twold`
			// generation, and any `twstg_` staging a cancelled run left behind. The
			// dumper filters them out, but that cannot reach a set already written
			// without the filter.
			//
			// Restoring them breaks the cutover outright. With `wp_users` and
			// `wp_users_twold` both present, its single RENAME gets two moves competing
			// for the same destination name, MySQL refuses the whole statement, and it
			// fails AFTER the files are replaced — leaving the site inconsistent and the
			// undo point destroyed on the way past.
			//
			// Skipped by design rather than refused: their absence is correct, not a
			// fault, and counting it as a failure would abort a restore over rows that
			// should never have been backed up.
			if ( self::is_internal_table( $live ) ) {
				return $unrecognised;
			}

			$named = RestorePrefix::renamed( $live, $from, $to );
			if ( null === $named ) {
				return array(
					'status'    => self::TARGET_LEFT_OUT,
					'statement' => '',
					'table'     => $live,
					'reason'    => '',
					'converted' => false,
					'links'     => array(),
				);
			}

			if ( ! self::has_plain_shape( $kind, (string) substr( $statement, strlen( $m[0] ) ) ) ) {
				return array(
					'status'    => self::TARGET_REFUSED,
					'statement' => '',
					'table'     => $live,
					'reason'    => 'the statement does more than write this one table',
					'converted' => false,
					'links'     => array(),
				);
			}

			$staged = $staging_pfx . $named;
			if ( ! RestoreDatabase::is_valid_table_name( $staged ) ) {
				return array(
					'status'    => self::TARGET_REFUSED,
					'statement' => '',
					'table'     => $live,
					'reason'    => 'the name is too long to stage (over 64 characters once prefixed)',
					'converted' => false,
					'links'     => array(),
				);
			}

			// preg_replace with a count of 1 and an anchored pattern: only the leading
			// identifier is replaced, never an occurrence inside the values.
			$rewritten = preg_replace(
				$pattern,
				'${1}`' . $staged . '`',
				$statement,
				1
			);

			$links = array();
			if ( is_string( $rewritten ) && 'create' === $kind && false !== stripos( $rewritten, 'REFERENCES' ) ) {
				$rewritten = self::retarget_references( $rewritten, $staging_pfx, $from, $to, $named, (string) $run, $links );
			}

			if ( ! is_string( $rewritten ) || '' === $rewritten ) {
				return array(
					'status'    => self::TARGET_REFUSED,
					'statement' => '',
					'table'     => $live,
					'reason'    => 'the statement could not be retargeted to staging',
					'converted' => false,
					'links'     => array(),
				);
			}

			return array(
				'status'    => self::TARGET_OK,
				'statement' => $rewritten,
				'table'     => $named,
				'reason'    => '',
				'converted' => $named !== $live,
				'links'     => $links,
			);
		}

		return $unrecognised;
	}

	/**
	 * Point a table definition's links to other tables at the staging copies of those tables.
	 *
	 * A link names the table it leads to, and the server keeps a link on its table through a
	 * rename. Staged against the live name, a link would follow the live table when the
	 * cutover moves it aside, and the restored table would be left tied to the generation
	 * being discarded. Staged against the staging copy, it follows that copy into place.
	 * RestoreDatabase::drop_unmet_links() answers for a link to a table the dump does not hold.
	 *
	 * The link's own name is changed as well. The server keeps those names unique across the
	 * database and the live table still holds this one, so the link is staged under a name
	 * of its own and reported in $links, for RestoreDatabase::name_links() to give it the
	 * name it is to carry: the dump's, with this site's prefix where it began with the
	 * dump's, as a name the server gave follows its table's.
	 *
	 * Only a name that directly follows REFERENCES, or stands between CONSTRAINT and FOREIGN
	 * KEY, is changed. Quoted text and every other name are stepped over whole, so a comment
	 * or default that happens to read the same is left as written, a link into another
	 * database keeps the name that database uses, and no other kind of constraint is renamed.
	 *
	 * @param string $statement   A table definition, already retargeted to staging.
	 * @param string $staging_pfx Staging prefix.
	 * @param string $from        Prefix the dump was taken under; empty when it is this site's.
	 * @param string $to          Prefix this site uses.
	 * @param string $table       Name the table takes on this site.
	 * @param string $run         What tells this restore from another.
	 * @param array  $links       Filled with one entry per link: `table`, `temp` and `name`.
	 * @return string|null Null when the statement could not be read.
	 */
	private static function retarget_references( $statement, $staging_pfx, $from, $to, $table = '', $run = '', array &$links = array() ) {
		return preg_replace_callback(
			'/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|\bCONSTRAINT\s+`(?<link>[^`]+)`(?=\s+FOREIGN\s+KEY\b)|\bREFERENCES\s+`(?<parent>[^`]+)`(?!\s*\.)|`(?:[^`]|``)*`/is',
			static function ( $m ) use ( $staging_pfx, $from, $to, $table, $run, &$links ) {
				if ( isset( $m['link'] ) && '' !== $m['link'] ) {
					$wanted = RestorePrefix::renamed( $m['link'], $from, $to );
					$wanted = null === $wanted ? $m['link'] : $wanted;
					$temp   = RestoreDatabase::staged_link_name( $run, $table, $m['link'] );

					$links[] = array(
						'table' => $table,
						'temp'  => $temp,
						// A name too long to be one stays with the name the link was staged under.
						'name'  => strlen( $wanted ) <= 64 ? $wanted : '',
					);

					return substr( $m[0], 0, -( strlen( $m['link'] ) + 2 ) ) . '`' . $temp . '`';
				}

				if ( ! isset( $m['parent'] ) || '' === $m['parent'] ) {
					return $m[0];
				}

				$parent = $m['parent'];
				if ( ! RestoreDatabase::is_valid_table_name( $parent ) || self::is_internal_table( $parent ) ) {
					return $m[0];
				}

				// A table that is left out is not staged, so a link to it keeps its name.
				$named = RestorePrefix::renamed( $parent, $from, $to );
				if ( null === $named || ! RestoreDatabase::is_valid_table_name( $staging_pfx . $named ) ) {
					return $m[0];
				}

				return substr( $m[0], 0, -( strlen( $parent ) + 2 ) ) . '`' . $staging_pfx . $named . '`';
			},
			$statement
		);
	}

	/**
	 * Whether the rest of a statement, after its target table, is only what a table dump holds.
	 *
	 * The target pattern decides which table a statement writes; this decides that it
	 * reaches nothing else. A second table in a DROP, a query or file read feeding an
	 * INSERT or a CREATE, or a table defined over other tables or another server would
	 * otherwise reach past the staging prefix to live tables and other databases.
	 *
	 * @param string $kind 'create', 'insert' or 'drop'.
	 * @param string $rest The statement after its target table.
	 * @return bool
	 */
	private static function has_plain_shape( $kind, $rest ) {
		$rest = rtrim( $rest );
		if ( ';' === substr( $rest, -1 ) ) {
			$rest = rtrim( substr( $rest, 0, -1 ) );
		}

		if ( 'drop' === $kind ) {
			return '' === trim( $rest );
		}

		$code = self::code_of( $rest );
		if ( null === $code || 0 !== preg_match( '/\b(?:SELECT|TABLE|LOAD_FILE|UNION|CONNECTION|DIRECTORY)\b/i', $code ) ) {
			return false;
		}

		if ( 'insert' === $kind ) {
			return 1 === preg_match( '/^\s*(?:\(|VALUES\b)/i', $rest )
				&& 1 === preg_match( '/^\s*(?:\([^()]*\)\s*)?VALUES\s*\(/i', $code );
		}

		return 1 === preg_match( '/^\s*\(/', $rest )
			&& 0 === preg_match( '/^\s*\(\s*(?:WITH|VALUES|LIKE)\b/i', $code );
	}

	/**
	 * The part of some SQL that MySQL reads as code.
	 *
	 * Quoted strings and identifiers become spaces, and versioned comments are opened up,
	 * since MySQL runs their contents on a new enough server.
	 *
	 * @param string $sql SQL text.
	 * @return string|null Null for anything that MySQL and this reading could quote
	 *                     differently: an unclosed quote, a double quote, an ordinary
	 *                     comment, or a versioned comment whose quotes do not close
	 *                     before it does.
	 */
	private static function code_of( $sql ) {
		$code   = '';
		$quote  = '';
		$length = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $char && "'" === $quote ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( "'" === $char || '`' === $char ) {
				$quote = $char;
				$code .= ' ';
				continue;
			}

			$pair = substr( $sql, $i, 2 );

			if ( '"' === $char || '#' === $char || '--' === $pair ) {
				return null;
			}

			if ( '/*' === $pair ) {
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end || ! preg_match( '/\G\/\*M?!\d*/', $sql, $marker, 0, $i ) ) {
					return null;
				}

				$start = $i + strlen( $marker[0] );
				$inner = self::code_of( (string) substr( $sql, $start, $end - $start ) );
				if ( null === $inner ) {
					return null;
				}

				$code .= ' ' . $inner . ' ';
				$i     = $end + 1;
				continue;
			}

			$code .= $char;
		}

		return '' === $quote ? $code : null;
	}
}
