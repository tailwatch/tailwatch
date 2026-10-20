<?php
namespace Tailwatch\Admin\App\Api\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process Start Claim
 *
 * One start at a time for the jobs that take over the site's files or database: backup,
 * restore and migration. Each checks for conflicting processes and then creates its own process
 * row, seconds apart when the start first estimates sizes or reads archives. Two starts in that
 * gap (two tabs, the app and a click, the schedule and a click) would both pass the check. A
 * start holds this claim while it checks again and creates its row, so the other start then
 * finds it running. See ProcessGuard::claim_start().
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services
 */
class ProcessStartClaim {

	/**
	 * Option row that holds the claim, as "<time>:<process type>:<random>".
	 */
	const OPTION = 'tailwatch_process_start_lock';

	/**
	 * Seconds after which a claim counts as left behind by a start that died.
	 */
	const TTL = 60;

	/**
	 * Claim the right to start.
	 *
	 * INSERT IGNORE lets one start in; add_option() would let both through, since it updates a
	 * row that is already there.
	 *
	 * @param string $process_type The process_type being started.
	 * @return string|false The claim, to release; '' when the claim could not be recorded (the
	 *                      start goes ahead rather than being blocked by the lock); false while
	 *                      another start holds it.
	 */
	public static function claim( $process_type ) {
		global $wpdb;
		$value = time() . ':' . sanitize_key( (string) $process_type ) . ':' . wp_generate_password( 16, false, false );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert-if-absent, which the options API does not offer; the cache is corrected below.
			$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, %s )', $wpdb->options, self::OPTION, $value, 'no' ) );
			self::forget_cache();
			if ( false === $inserted ) {
				return '';
			}
			if ( 1 === (int) $inserted ) {
				return $value;
			}

			$held  = self::held();
			$since = (int) strtok( $held, ':' );
			if ( $since > 0 && time() - $since < self::TTL ) {
				return false;
			}

			// Left behind by a start that died. Removed by its exact value, so of two starts that
			// both find it stale only one removes it, and the other's next insert finds the new claim.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete of one option row.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `option_name` = %s AND `option_value` = %s', $wpdb->options, self::OPTION, $held ) );
		}
		return false;
	}

	/**
	 * The process_type of the start holding the claim, or '' when none does.
	 *
	 * @return string
	 */
	public static function holder() {
		$parts = explode( ':', self::held(), 3 );
		return isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
	}

	/**
	 * Release a claim, only if it is still this start's.
	 *
	 * @param mixed $value What claim() returned.
	 * @return void
	 */
	public static function release( $value ) {
		global $wpdb;
		if ( ! is_string( $value ) || '' === $value ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete of one option row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE `option_name` = %s AND `option_value` = %s', $wpdb->options, self::OPTION, $value ) );
		self::forget_cache();
	}

	/**
	 * The claim as stored, or '' when there is none.
	 *
	 * @return string
	 */
	private static function held() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read past the options cache, which another request's claim does not update.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT `option_value` FROM %i WHERE `option_name` = %s LIMIT 1', $wpdb->options, self::OPTION ) );
	}

	/**
	 * Drop the options cache for the claim after it was written past the options API.
	 *
	 * @return void
	 */
	private static function forget_cache() {
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
			unset( $notoptions[ self::OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		wp_cache_delete( self::OPTION, 'options' );
	}
}
