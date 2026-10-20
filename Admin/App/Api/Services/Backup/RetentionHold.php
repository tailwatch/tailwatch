<?php
/**
 * Tailwatch - Retention Hold
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backups made while more of them were kept are not deleted by a lower number the owner did not
 * choose.
 *
 * The number of backups kept can drop without the owner choosing it: a plan that no longer
 * includes the larger numbers, an add-on switched off, the feature switched off or its settings
 * reset. Applied as it stands, the lower number would delete the backups made before the drop,
 * which are every way back the owner had. So each backup records the number in force when it
 * finished, and this plugin remembers the highest number in force since the owner last chose one
 * themselves. While the number in force is below that, the backups recorded under a higher
 * number, or under none, take no part in retention: they stay until the owner deletes them or
 * the number rises again.
 */
final class RetentionHold {

	/**
	 * Key on a backup's record: the number of backups kept when it finished.
	 */
	const STAMP = 'retention';

	/**
	 * Option: the highest number of backups kept since the owner last chose one.
	 */
	const PEAK_OPTION = 'tailwatch_backup_retention_peak';

	/**
	 * Record on a finishing backup the number of backups kept.
	 *
	 * @param array    $run   The backup's record, by reference.
	 * @param int|null $count Number kept; null when it cannot be read.
	 * @return void
	 */
	public static function stamp( array &$run, $count ) {
		if ( is_int( $count ) && $count >= 1 ) {
			$run[ self::STAMP ] = $count;
		}
	}

	/**
	 * The highest number of backups kept since the owner last chose one; 0 before any.
	 *
	 * @return int
	 */
	public static function peak() {
		return max( 0, (int) get_option( self::PEAK_OPTION, 0 ) );
	}

	/**
	 * The number in force: the peak rises to it, and never falls by it.
	 *
	 * @param int $count Number of backups kept.
	 * @return void
	 */
	public static function observe( $count ) {
		if ( is_int( $count ) && $count > self::peak() ) {
			update_option( self::PEAK_OPTION, $count, false );
		}
	}

	/**
	 * The owner chose a number themselves: it is the peak from now on, higher or lower.
	 *
	 * @param int $count Number of backups kept.
	 * @return void
	 */
	public static function chosen( $count ) {
		if ( is_int( $count ) && $count >= 1 ) {
			update_option( self::PEAK_OPTION, $count, false );
		}
	}

	/**
	 * The backups that take part in retention at this number, and the ones it holds.
	 *
	 * @param array $pool  Backup rows, each with its `value`.
	 * @param int   $limit Number of backups kept.
	 * @return array{0:array,1:array} Taking part, held.
	 */
	public static function split( array $pool, $limit ) {
		$limit = (int) $limit;
		if ( $limit >= self::peak() ) {
			return array( $pool, array() );
		}

		$taking_part = array();
		$held        = array();
		foreach ( $pool as $row ) {
			$value = json_decode( (string) ( $row['value'] ?? '' ), true );
			$stamp = is_array( $value ) ? ( $value[ self::STAMP ] ?? null ) : null;

			if ( is_int( $stamp ) && $stamp <= $limit ) {
				$taking_part[] = $row;
			} else {
				$held[] = $row;
			}
		}

		return array( $taking_part, $held );
	}

	/**
	 * Forget the peak, with the rest of this plugin's data.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::PEAK_OPTION );
	}
}
