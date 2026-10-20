<?php
/**
 * Tailwatch - Cron accelerator
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Cron
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts the next scheduled step as soon as this request ends, instead of when the site
 * next gets a visitor. It never decides whether a step runs: callers schedule the step
 * first, due now, and the scheduled event is what guarantees it.
 *
 * The request calls wp-cron.php the way a server cron does, without a doing_wp_cron key,
 * so wp-cron.php takes the cron lock itself and only when no other run holds it. A request
 * that never arrives therefore leaves no lock behind to block the next attempt.
 *
 * It is sent from a shutdown function registered during shutdown, which PHP runs after
 * every handler registered before — buffered state writes and worker lock releases
 * included. Inside wp-cron.php that is also after the finished run released its lock.
 */
class CronKick {

	/**
	 * Minimum seconds between kicks, so a fast tick loop cannot hammer the site.
	 */
	const THROTTLE = 2;

	/**
	 * Transient enforcing THROTTLE.
	 */
	const THROTTLE_KEY = 'tailwatch_cron_kick_sent';

	/**
	 * Set when a loopback probe from inside cron failed, so every tick does not probe again.
	 */
	const UNAVAILABLE_KEY = 'tailwatch_cron_kick_unavailable';

	/**
	 * Seconds a failed probe is remembered.
	 */
	const UNAVAILABLE_TTL = 600;

	/**
	 * Seconds a kick sent from inside cron waits for wp-cron.php to take the request.
	 */
	const CRON_TIMEOUT = 3;

	/**
	 * Whether this request already arranged a kick.
	 *
	 * @var bool
	 */
	private static $armed = false;

	/**
	 * Arrange for due cron jobs to be started when this request ends.
	 *
	 * @return bool Whether a kick was arranged.
	 */
	public static function accelerate() {
		// The site owner runs cron from the system scheduler. Their choice; a loopback
		// would be working around a configuration they made deliberately.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return false;
		}

		// ALTERNATE_WP_CRON advances cron on ordinary page loads through a redirect, and a
		// loopback into that scheme does not help.
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return false;
		}

		// Outside cron a loopback must already be known to work: probing would hold up the
		// response someone is waiting for.
		if ( ! self::in_cron() && ! get_transient( CronHealthService::CACHE_KEY ) ) {
			return false;
		}

		if ( self::$armed ) {
			return true;
		}
		self::$armed = true;

		if ( did_action( 'shutdown' ) ) {
			self::on_shutdown();
		} else {
			add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), PHP_INT_MAX );
		}

		return true;
	}

	/**
	 * Decide at shutdown, once everything the request scheduled is in place.
	 *
	 * @return void
	 */
	public static function on_shutdown() {
		if ( ! self::should_send() ) {
			return;
		}

		set_transient( self::THROTTLE_KEY, 1, self::THROTTLE );
		register_shutdown_function( array( __CLASS__, 'send' ) );
	}

	/**
	 * Whether a request to wp-cron.php would start anything.
	 *
	 * @return bool
	 */
	public static function should_send() {
		if ( get_transient( self::THROTTLE_KEY ) ) {
			return false;
		}

		if ( ! self::loopback_works() ) {
			return false;
		}

		// A run holding the lock would turn the request away. Same reading as spawn_cron().
		$lock = (float) get_transient( 'doing_cron' );
		if ( $lock > microtime( true ) + 10 * MINUTE_IN_SECONDS ) {
			$lock = 0;
		}
		if ( $lock + WP_CRON_LOCK_TIMEOUT > microtime( true ) ) {
			return false;
		}

		$ready = wp_get_ready_cron_jobs();

		return ! empty( $ready );
	}

	/**
	 * Send the request.
	 *
	 * @return void
	 */
	public static function send() {
		wp_remote_post( site_url( 'wp-cron.php' ), self::request_args() );
	}

	/**
	 * Request arguments for the kick.
	 *
	 * Inside cron nobody waits on this process, so it waits until wp-cron.php has taken
	 * the request, which a busy host may otherwise drop. Anywhere else it must not delay
	 * the response, and core's own spawn at shutdown is already making the same attempt.
	 *
	 * @return array
	 */
	public static function request_args() {
		$in_cron = self::in_cron();

		return array(
			'timeout'   => $in_cron ? self::CRON_TIMEOUT : 0.01,
			'blocking'  => $in_cron,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Consuming WordPress core's own hook, not declaring one: core applies this exact filter in spawn_cron() for the identical request, and hardcoding false instead would stop a site with a real local certificate from verifying it.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);
	}

	/**
	 * Whether a loopback to wp-cron.php is known to work, probing once from inside cron.
	 *
	 * @return bool
	 */
	private static function loopback_works() {
		if ( get_transient( CronHealthService::CACHE_KEY ) ) {
			return true;
		}

		if ( ! self::in_cron() || get_transient( self::UNAVAILABLE_KEY ) ) {
			return false;
		}

		$status = ( new CronHealthService() )->check();
		if ( 'ok' === $status['state'] ) {
			return true;
		}

		set_transient( self::UNAVAILABLE_KEY, 1, self::UNAVAILABLE_TTL );
		return false;
	}

	/**
	 * Whether this request is a WP-Cron run.
	 *
	 * @return bool
	 */
	private static function in_cron() {
		return defined( 'DOING_CRON' ) && DOING_CRON;
	}
}
