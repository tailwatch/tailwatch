<?php
/**
 * Time formatting service.
 *
 * Stateless, human-readable, translatable time/duration helpers shared by
 * controllers, models, and cron jobs across both plugins. Lives in the Services
 * layer so models can use it without depending on a controller (the previous home
 * was GetCronJobDetailsController).
 *
 * @package Tailwatch\Admin\App\Api\Services\Time
 */

namespace Tailwatch\Admin\App\Api\Services\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TimeService
 */
class TimeService {

	/**
	 * Human-readable, translatable countdown between two unix timestamps,
	 * e.g. "1 day, 3 hours". Seconds are only shown when under an hour remains.
	 *
	 * @param int    $next_run_time Target unix timestamp.
	 * @param int    $current_time  Current unix timestamp.
	 * @param string $elapsed_label Label when the target has passed. Defaults to a
	 *                              translated "Now"; pass e.g. __( 'Running now', … )
	 *                              for a "next run" context.
	 * @return string
	 */
	public static function format_time_remaining( $next_run_time, $current_time, $elapsed_label = '' ) {
		$time_remaining = $next_run_time - $current_time;

		if ( $time_remaining <= 0 ) {
			return '' !== $elapsed_label ? $elapsed_label : __( 'Now', 'tailwatch' );
		}

		$days    = floor( $time_remaining / ( 60 * 60 * 24 ) );
		$hours   = floor( ( $time_remaining % ( 60 * 60 * 24 ) ) / ( 60 * 60 ) );
		$minutes = floor( ( $time_remaining % ( 60 * 60 ) ) / 60 );
		$seconds = $time_remaining % 60;

		$time_parts = array();
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			$time_parts[] = sprintf( _n( '%d day', '%d days', $days, 'tailwatch' ), $days );
		}
		if ( $hours > 0 ) {
			/* translators: %d: number of hours */
			$time_parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'tailwatch' ), $hours );
		}
		if ( $minutes > 0 ) {
			/* translators: %d: number of minutes */
			$time_parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'tailwatch' ), $minutes );
		}
		if ( $seconds > 0 && $days == 0 && $hours == 0 ) {
			/* translators: %d: number of seconds */
			$time_parts[] = sprintf( _n( '%d second', '%d seconds', $seconds, 'tailwatch' ), $seconds );
		}

		return implode( ', ', $time_parts );
	}

	/**
	 * Human-readable "time left until unblock" for a block row. Returns a
	 * translated 'Permanent' for permanent locks, '' when there is no active timed
	 * block, else a readable countdown (reuses format_time_remaining()). Uses the
	 * site-local time basis so it is correct on non-UTC sites.
	 *
	 * @param string $lock_type   'temporary' | 'permanent' | 'none'.
	 * @param string $start_mysql Lock/block start time (mysql format, site-local).
	 * @param int    $duration    Block duration in seconds.
	 * @return string
	 */
	public static function format_block_time_remaining( $lock_type, $start_mysql, $duration ) {
		if ( 'permanent' === $lock_type ) {
			return __( 'Permanent', 'tailwatch' );
		}
		if ( 'temporary' !== $lock_type || empty( $start_mysql ) || (int) $duration <= 0 ) {
			return '';
		}
		$unblock = strtotime( $start_mysql ) + (int) $duration;
		return self::format_time_remaining( $unblock, strtotime( current_time( 'mysql' ) ) );
	}

	/**
	 * Human-readable, translatable schedule interval, e.g. "1 day" or
	 * "1 hour, 30 minutes". Returns a translated 'Non-repeating' for an interval of
	 * 0 (one-off events). Unlike format_time_remaining(), seconds are always shown
	 * when non-zero.
	 *
	 * @param int $seconds Interval length in seconds.
	 * @return string
	 */
	public static function format_interval( $seconds ) {
		if ( $seconds == 0 ) {
			return __( 'Non-repeating', 'tailwatch' );
		}

		$days    = floor( $seconds / ( 60 * 60 * 24 ) );
		$hours   = floor( ( $seconds % ( 60 * 60 * 24 ) ) / ( 60 * 60 ) );
		$minutes = floor( ( $seconds % ( 60 * 60 ) ) / 60 );
		$secs    = $seconds % 60;

		$parts = array();
		if ( $days > 0 ) {
			/* translators: %d: number of days */
			$parts[] = sprintf( _n( '%d day', '%d days', $days, 'tailwatch' ), $days );
		}
		if ( $hours > 0 ) {
			/* translators: %d: number of hours */
			$parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'tailwatch' ), $hours );
		}
		if ( $minutes > 0 ) {
			/* translators: %d: number of minutes */
			$parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'tailwatch' ), $minutes );
		}
		if ( $secs > 0 ) {
			/* translators: %d: number of seconds */
			$parts[] = sprintf( _n( '%d second', '%d seconds', $secs, 'tailwatch' ), $secs );
		}

		return implode( ', ', $parts );
	}
}
