<?php
/**
 * Tailwatch - Restore Prefix
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
 * Fits a backup taken under one table prefix to a site that uses another.
 *
 * A dump names its tables with the prefix of the site that wrote it, and WordPress keeps that
 * prefix inside the data too: in the name of the option holding the role list, and at the
 * start of every per-site user setting, each user's role among them. So the fit has two
 * halves. The importer stages each table under the name this site reads, and convert_keys()
 * then gives those stored names this site's prefix, in the staging copy, before anything is
 * switched.
 *
 * Only this site's prefix is ever written. The one a backup records decides which names
 * change, never which tables this site reads.
 */
class RestorePrefix {

	/**
	 * Where a user setting's name starts while it is between the two prefixes.
	 *
	 * A table prefix holds only letters, digits and underscores, so no name written for one
	 * can start with this. A name moved here is therefore never taken for one still waiting,
	 * which keeps the move repeatable when one prefix begins with the other.
	 */
	const PARKED = '~twstg~';

	/**
	 * Rows renamed per statement.
	 */
	const BATCH = 5000;

	/**
	 * Longest name the user settings table holds.
	 */
	const KEY_LENGTH = 255;

	/**
	 * convert_keys() stages: names still leaving the backup's prefix, then names arriving at
	 * this site's.
	 */
	const STAGE_PARK = 'park';
	const STAGE_LAND = 'land';

	/**
	 * The table prefix this site reads.
	 *
	 * @return string
	 */
	public static function live() {
		global $wpdb;

		return isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '';
	}

	/**
	 * Whether a prefix is made only of what a table prefix may hold, and leaves a table name
	 * room after it.
	 *
	 * @param mixed $prefix Prefix.
	 * @return bool
	 */
	public static function is_plain( $prefix ) {
		return is_string( $prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]{1,63}\z/', $prefix );
	}

	/**
	 * Whether a backup's tables are named for another prefix than this site's.
	 *
	 * A prefix that could not be read on either side is not a difference.
	 *
	 * @param string $origin Prefix the backup records.
	 * @return bool
	 */
	public static function differs( $origin ) {
		$origin = (string) $origin;
		$live   = self::live();

		return '' !== $origin && '' !== $live && $origin !== $live;
	}

	/**
	 * Why the database of a backup taken under another prefix cannot be fitted to this site.
	 *
	 * @param string $origin Prefix the backup records.
	 * @return string|null Message, or null when it fits or needs no fitting.
	 */
	public static function refusal( $origin ) {
		$origin = (string) $origin;

		if ( ! self::differs( $origin ) ) {
			return null;
		}

		$live = self::live();

		if ( ! self::is_plain( $origin ) || ! self::is_plain( $live ) ) {
			return __( 'This backup records a table prefix this site cannot work with, so it has been stopped.', 'tailwatch' );
		}

		// Each site of a network keeps its own tables and its own role list under the prefix.
		if ( is_multisite() ) {
			return sprintf(
				/* translators: 1: table prefix recorded in the backup, 2: table prefix this network uses. */
				__( 'This backup came from a site using the table prefix "%1$s", and this network uses "%2$s". Only a single site can take a backup made under another prefix, so it has been stopped.', 'tailwatch' ),
				$origin,
				$live
			);
		}

		return null;
	}

	/**
	 * What a restore carries, from verify to the swap, for a backup taken under another prefix.
	 *
	 * @param string $origin Prefix the backup records.
	 * @return array
	 */
	public static function plan( $origin ) {
		return array(
			'from'    => (string) $origin,
			'to'      => self::live(),
			// Whether the dump held any table named for the prefix it records.
			'matched' => false,
			'skipped' => array(),
			'stage'   => self::STAGE_PARK,
			'keys'    => 0,
			'error'   => '',
			'done'    => false,
		);
	}

	/**
	 * The name a table from the backup takes on this site.
	 *
	 * A table named for the backup's prefix takes this site's. One that already carries this
	 * site's prefix, and not the backup's, is left out: it was never part of the site that was
	 * backed up, and its name belongs to a table that is. Any other keeps its own name.
	 *
	 * @param string $table Table name as the backup wrote it.
	 * @param string $from  Prefix the backup was taken under.
	 * @param string $to    Prefix this site uses.
	 * @return string|null Null for a table that is left out.
	 */
	public static function renamed( $table, $from, $to ) {
		$table = (string) $table;
		$from  = (string) $from;
		$to    = (string) $to;

		if ( '' === $from || '' === $to || $from === $to ) {
			return $table;
		}

		if ( 0 === strpos( $table, $from ) ) {
			return $to . substr( $table, strlen( $from ) );
		}

		if ( 0 === strpos( $table, $to ) ) {
			return null;
		}

		return $table;
	}

	/**
	 * Give the stored names in the staging copy this site's prefix.
	 *
	 * The role list is one option, renamed in place. User settings go in two moves, a batch
	 * at a time: to a holding name first, then to this site's prefix. Before the first, any
	 * setting already sitting under a name about to be taken is removed. The site that was
	 * backed up never read it, and left in place it would answer ahead of the real one.
	 *
	 * The call returns as soon as the first move is complete, so that the caller records it
	 * before any name lands. A repeat of an interrupted call then never clears a name that
	 * has already landed.
	 *
	 * @param array $plan     Plan from plan(), or as an earlier call returned it.
	 * @param array $tables   Tables this restore staged, by the name each takes on this site.
	 * @param int   $deadline Tick deadline (0 = none).
	 * @param int   $batch    Rows per statement.
	 * @return array Plan; `done` once every name is in place, `error` set when the database refused.
	 */
	public static function convert_keys( array $plan, array $tables, $deadline = 0, $batch = self::BATCH ) {
		if ( ! empty( $plan['done'] ) ) {
			return $plan;
		}

		$from = isset( $plan['from'] ) ? $plan['from'] : '';
		$to   = isset( $plan['to'] ) ? $plan['to'] : '';

		// Nothing is put into a statement that is not a plain prefix.
		if ( ! self::is_plain( $from ) || ! self::is_plain( $to ) || $from === $to ) {
			$plan['error'] = self::unfitted();
			return $plan;
		}

		$batch    = max( 1, (int) $batch );
		$usermeta = in_array( $to . 'usermeta', $tables, true ) ? RestoreDatabase::staging_name( $to . 'usermeta' ) : '';

		if ( ! isset( $plan['stage'] ) || self::STAGE_LAND !== $plan['stage'] ) {
			if ( in_array( $to . 'options', $tables, true ) ) {
				$roles = self::move_roles( RestoreDatabase::staging_name( $to . 'options' ), $from, $to );
				if ( null === $roles ) {
					return self::failed( $plan );
				}
				$plan['keys'] = (int) ( $plan['keys'] ?? 0 ) + $roles;
			}

			if ( '' === $usermeta ) {
				$plan['done'] = true;
				return $plan;
			}

			// A name is moved only when it still fits the column at both of its stops.
			$fits = self::KEY_LENGTH + strlen( $from ) - max( strlen( $from ), strlen( self::PARKED ), strlen( $to ) );

			if ( ! self::clear_landings( $usermeta, $from, $to, $fits ) ) {
				return self::failed( $plan );
			}

			$parked = self::move( $usermeta, $from, self::PARKED, $fits, $deadline, $batch );
			if ( ! $parked['ok'] ) {
				return self::failed( $plan );
			}
			$plan['keys'] = (int) ( $plan['keys'] ?? 0 ) + $parked['rows'];

			if ( $parked['finished'] ) {
				$plan['stage'] = self::STAGE_LAND;
			}

			return $plan;
		}

		if ( '' !== $usermeta ) {
			$fits   = self::KEY_LENGTH + strlen( self::PARKED ) - max( strlen( self::PARKED ), strlen( $to ) );
			$landed = self::move( $usermeta, self::PARKED, $to, $fits, $deadline, $batch );
			if ( ! $landed['ok'] ) {
				return self::failed( $plan );
			}
			if ( ! $landed['finished'] ) {
				return $plan;
			}
		}

		$plan['done'] = true;

		return $plan;
	}

	/**
	 * Rename the option holding the role list, replacing one already under this site's name.
	 *
	 * @param string $table Staging copy of the options table.
	 * @param string $from  Prefix the backup was taken under.
	 * @param string $to    Prefix this site uses.
	 * @return int|null Options renamed, or null when the database refused.
	 */
	private static function move_roles( $table, $from, $to ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $table, $from . 'user_roles' ) );
		if ( null === $held ) {
			return null;
		}
		if ( (int) $held < 1 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
		if ( false === $wpdb->delete( $table, array( 'option_name' => $to . 'user_roles' ), array( '%s' ) ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
		$renamed = $wpdb->update( $table, array( 'option_name' => $to . 'user_roles' ), array( 'option_name' => $from . 'user_roles' ), array( '%s' ), array( '%s' ) );

		return false === $renamed ? null : 1;
	}

	/**
	 * Remove every user setting sitting under a name the backup's own settings are about to take.
	 *
	 * @param string $table Staging copy of the user settings table.
	 * @param string $from  Prefix the backup was taken under.
	 * @param string $to    Prefix this site uses.
	 * @param int    $fits  Longest name that is moved.
	 * @return bool False when the database refused.
	 */
	private static function clear_landings( $table, $from, $to, $fits ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
		$cleared = $wpdb->query(
			$wpdb->prepare(
				'DELETE taken FROM %i AS taken INNER JOIN ( SELECT DISTINCT CONCAT( %s, SUBSTRING( meta_key, %d ) ) AS landing FROM %i WHERE meta_key LIKE %s AND LEFT( meta_key, %d ) = CAST( %s AS BINARY ) AND CHAR_LENGTH( meta_key ) <= %d ) AS named ON taken.meta_key = named.landing',
				$table,
				$to,
				strlen( $from ) + 1,
				$table,
				$wpdb->esc_like( $from ) . '%',
				strlen( $from ),
				$from,
				$fits
			)
		);

		return false !== $cleared;
	}

	/**
	 * Give every user setting whose name starts with one text the other in its place.
	 *
	 * The comparison is exact to the letter's case, which the table's own collation is not:
	 * WordPress reads these names exactly, so only an exact match is the prefix.
	 *
	 * @param string $table    Staging copy of the user settings table.
	 * @param string $was      Text the names start with now.
	 * @param string $becomes  Text they start with afterwards.
	 * @param int    $fits     Longest name that is moved.
	 * @param int    $deadline Tick deadline (0 = none).
	 * @param int    $batch    Rows per statement.
	 * @return array{ok:bool,finished:bool,rows:int}
	 */
	private static function move( $table, $was, $becomes, $fits, $deadline, $batch ) {
		global $wpdb;

		$out = array(
			'ok'       => true,
			'finished' => false,
			'rows'     => 0,
		);

		$like = $wpdb->esc_like( $was ) . '%';

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a staging table this restore created; no cache models it.
			$changed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s AND LEFT( meta_key, %d ) = CAST( %s AS BINARY ) AND CHAR_LENGTH( meta_key ) <= %d LIMIT %d',
					$table,
					$becomes,
					strlen( $was ) + 1,
					$like,
					strlen( $was ),
					$was,
					$fits,
					$batch
				)
			);

			if ( false === $changed ) {
				$out['ok'] = false;
				return $out;
			}

			$out['rows'] += (int) $changed;
			TickBudget::alive( 'restore' );

			if ( (int) $changed < $batch ) {
				$out['finished'] = true;
				return $out;
			}
		} while ( ! TickBudget::spent( $deadline ) );

		return $out;
	}

	/**
	 * Record that the names could not be changed, with the driver's reason in the log.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private static function failed( array $plan ) {
		global $wpdb;

		if ( isset( $wpdb->last_error ) && '' !== $wpdb->last_error ) {
			Log::error(
				'Restore could not give a stored name this site\'s table prefix: ' . $wpdb->last_error,
				array(
					'feature' => 'restore',
					'action'  => 'restore_prefix_names_failed',
					'origin'  => 'system',
				)
			);
		}

		$plan['error'] = self::unfitted();

		return $plan;
	}

	/**
	 * What the operator is told when the stored names could not be changed.
	 *
	 * @return string
	 */
	private static function unfitted() {
		return __( 'The restored database could not be fitted to this site\'s table prefix. Nothing was changed.', 'tailwatch' );
	}
}
