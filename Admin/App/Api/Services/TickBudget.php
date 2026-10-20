<?php
/**
 * Tailwatch - Learned tick budget
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Learns how long a request actually survives on this host, and sizes ticks from that.
 *
 * `ini_get( 'max_execution_time' )` is what a tick budget is usually derived from, and on
 * a large fraction of real hosts it is not the number that ends the request. An nginx
 * `fastcgi_read_timeout`, an Apache `Timeout`, a PHP-FPM `request_terminate_timeout` or
 * the OOM killer all stop the process without consulting it — and on Linux the ini value
 * counts CPU time, so a tick that is mostly file I/O can blow a wall-clock proxy limit
 * while PHP still believes it has seconds in hand. Planning a 25-second tick on a box
 * that dies at 10 means the cursor barely advances, every tick is killed, and the
 * no-progress guard eventually aborts a backup that was never actually broken.
 *
 * So the ceiling is measured rather than asked for. The measurement has to happen DURING
 * a tick — a process that gets killed cannot report how long it lasted — so each tick
 * keeps a high-water mark of how far it got, and the next tick reads it.
 *
 * Four rules keep the measurement honest:
 *
 * - the ceiling is kept PER PROCESS TYPE, not site-wide, so a files backup cannot teach
 *   a database job its timings;
 * - it is raised only when a tick genuinely pushed against it, so a job that finishes
 *   early can never inflate it;
 * - it is lowered only on evidence of an actual kill, as a one-way ratchet;
 * - and `alive()` writes only when the mark really moves, rather than on every check-in.
 */
class TickBudget {

	/**
	 * Where the learned ceilings live: process_type => seconds.
	 *
	 * Non-autoloaded. It is read on the cron path, not on every front-end request.
	 */
	const OPTION = 'tailwatch_tick_ceilings';

	/**
	 * In-flight marks: process_type => [ started, high_water ].
	 */
	const INFLIGHT_OPTION = 'tailwatch_tick_inflight';

	/**
	 * Floor and cap for a learned ceiling, in seconds.
	 *
	 * The floor keeps one pathological sample from grinding a job to a halt; the cap
	 * stops a CLI run (where nothing ever kills the process) teaching the ceiling a
	 * number no web request will ever reach.
	 */
	const CEILING_MIN = 10;
	const CEILING_MAX = 300;

	/**
	 * Fraction of the learned ceiling a tick is allowed to plan for.
	 *
	 * Leaving 40% unused looks wasteful and is not: the cost of overshooting is a killed
	 * process that loses the work of the whole tick, and the cost of undershooting is one
	 * extra tick.
	 */
	const TARGET_FRACTION = 0.6;

	/**
	 * Starting assumption before anything has been learned.
	 */
	const DEFAULT_CEILING = 30;

	/**
	 * A tick is only credited with pushing against the ceiling within this margin of it.
	 */
	const RAISE_MARGIN = 5;

	/**
	 * Fraction of the memory limit at which a tick should stop and come back.
	 *
	 * Below the ceiling by enough to finish the current unit of work, close a zip, flush
	 * state and schedule the next tick — all of which allocate. Yielding at the ceiling
	 * itself would fatal on the way out.
	 */
	const MEMORY_FRACTION = 0.9;

	/**
	 * Whether this request is close enough to the memory ceiling that it should yield.
	 *
	 * Time is the limit hosts enforce most visibly, but it is not the only one, and running
	 * out of memory behaves very differently from running out of time: the tick dies, the
	 * fatal handler reschedules, and the next tick attempts *the same work at the same size*
	 * — so a run paced only by the clock retries identically until the no-progress guard
	 * gives up.
	 *
	 * Checking between units of work turns that into an ordinary yield: the tick stops, the
	 * state it already wrote is intact, and the next one continues. It cannot catch a single
	 * allocation that overshoots on its own — nothing in PHP can — but it does prevent the
	 * steady climb that accounts for most of them.
	 *
	 * An unreadable or unlimited limit means "let the host decide" rather than "yield now".
	 * Treating unknown as exhausted would stall every run on a host that does not report a
	 * limit at all.
	 *
	 * @return bool
	 */
	public static function memory_exceeded() {
		$limit = self::memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false;
		}

		return memory_get_usage( true ) >= ( $limit * self::MEMORY_FRACTION );
	}

	/**
	 * The PHP memory limit in bytes, or 0 when it is unlimited or unreadable.
	 *
	 * @return int
	 */
	public static function memory_limit_bytes() {
		$raw = ini_get( 'memory_limit' );
		if ( false === $raw || '' === $raw ) {
			return 0;
		}

		$raw = trim( (string) $raw );
		if ( '-1' === $raw ) {
			return 0; // unlimited.
		}

		$value = (int) $raw;
		switch ( strtolower( substr( $raw, -1 ) ) ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return max( 0, $value );
	}

	/**
	 * Whether this tick should stop — out of time, or close to the memory ceiling.
	 *
	 * @param int $deadline Unix timestamp the tick must finish by (0 = no limit).
	 * @return bool
	 */
	public static function spent( $deadline ) {
		if ( $deadline > 0 && time() >= $deadline ) {
			return true;
		}

		return self::memory_exceeded();
	}

	/**
	 * Open a tick.
	 *
	 * Reads the previous tick's fate first: an in-flight mark that was never closed means
	 * that tick died, and how far it got is the best evidence available of where this host
	 * actually cuts a request off.
	 *
	 * @param string $process_type Registered process type.
	 * @return bool True when the PREVIOUS tick was killed before it could close.
	 */
	public static function open( $process_type ) {
		$process_type = (string) $process_type;
		if ( '' === $process_type ) {
			return false;
		}

		$inflight = self::inflight();
		$killed   = false;

		if ( isset( $inflight[ $process_type ]['high_water'] ) ) {
			// The previous tick never closed. It was killed at roughly this point, so the
			// ceiling can be no higher than that. Ratchets DOWN only — a kill is evidence
			// of a limit, a clean finish is not evidence of its absence.
			//
			// Reported as well as recorded: a caller resuming work that is not idempotent
			// needs to know its last tick may have half-run, and this mark is the only
			// evidence there is. Nothing else survives a kill to say so.
			$killed  = true;
			$died_at = (float) $inflight[ $process_type ]['high_water'];
			if ( $died_at > 0 ) {
				self::lower_ceiling( $process_type, $died_at );
			}
		}

		$inflight[ $process_type ] = array(
			'started'    => microtime( true ),
			'high_water' => 0.0,
		);
		self::save_inflight( $inflight );

		return $killed;
	}

	/**
	 * Record that the tick is still running.
	 *
	 * Called from inside the work loops. Writes only when the mark moves by a whole
	 * second, because the alternative is one option write per processed file.
	 *
	 * @param string $process_type Registered process type.
	 * @return void
	 */
	public static function alive( $process_type ) {
		$process_type = (string) $process_type;
		$inflight     = self::inflight();

		if ( ! isset( $inflight[ $process_type ]['started'] ) ) {
			return;
		}

		$elapsed = microtime( true ) - (float) $inflight[ $process_type ]['started'];
		$current = (float) ( $inflight[ $process_type ]['high_water'] ?? 0.0 );

		if ( $elapsed < $current + 1.0 ) {
			return;
		}

		$inflight[ $process_type ]['high_water'] = $elapsed;
		self::save_inflight( $inflight );
	}

	/**
	 * Close a tick that finished under its own control.
	 *
	 * A clean finish that ran close to the ceiling is the one case where the ceiling may
	 * be raised: the host allowed it. Finishing early proves nothing and changes nothing.
	 *
	 * @param string $process_type Registered process type.
	 * @return void
	 */
	public static function close( $process_type ) {
		$process_type = (string) $process_type;
		$inflight     = self::inflight();

		if ( ! isset( $inflight[ $process_type ]['started'] ) ) {
			return;
		}

		$elapsed = microtime( true ) - (float) $inflight[ $process_type ]['started'];
		unset( $inflight[ $process_type ] );
		self::save_inflight( $inflight );

		$ceiling = self::ceiling( $process_type );
		if ( $elapsed + self::RAISE_MARGIN > $ceiling ) {
			self::store_ceiling( $process_type, (int) ceil( $elapsed + self::RAISE_MARGIN ) );
		}
	}

	/**
	 * Seconds of work to plan for in one tick.
	 *
	 * Takes the smallest of what the caller wants, what PHP admits to, and what this host
	 * has actually been observed to allow. Any of the three can be the real constraint.
	 *
	 * @param string $process_type Registered process type.
	 * @param int    $preferred    Budget when nothing else binds.
	 * @return int Seconds, at least 1 unless $preferred is lower.
	 */
	public static function budget_for( $process_type, $preferred ) {
		$preferred = (int) $preferred;
		$candidate = $preferred;

		$ini = (int) ini_get( 'max_execution_time' );
		if ( $ini > 0 ) {
			$candidate = min( $candidate, (int) floor( $ini * self::TARGET_FRACTION ) );
		}

		// Only a MEASURED ceiling may bind. DEFAULT_CEILING is an assumption, and applying
		// an assumption as a cap would silently shorten every tick on every site that has
		// not yet been killed once — punishing the healthy hosts to protect the sick ones.
		if ( self::has_learned( $process_type ) ) {
			$learned   = self::ceiling( $process_type );
			$candidate = min( $candidate, (int) floor( $learned * self::TARGET_FRACTION ) );
		}

		// A floor of 1 rather than something derived from CEILING_MIN: callers apply their
		// own minimum (BackupController::TICK_BUDGET_MIN), and a second, higher floor here
		// would quietly override it.
		return (int) min( $preferred, max( 1, $candidate ) );
	}

	/**
	 * The learned ceiling for a type, or the default when nothing is known yet.
	 *
	 * @param string $process_type Registered process type.
	 * @return int Seconds.
	 */
	public static function ceiling( $process_type ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $process_type ] ) ) {
			return self::DEFAULT_CEILING;
		}

		return (int) max( self::CEILING_MIN, min( self::CEILING_MAX, (int) $all[ $process_type ] ) );
	}

	/**
	 * Whether this host has actually taught us anything about this type.
	 *
	 * The distinction matters: ceiling() always answers, falling back to an assumption,
	 * but only a real measurement is allowed to constrain a tick.
	 *
	 * @param string $process_type Registered process type.
	 * @return bool
	 */
	public static function has_learned( $process_type ) {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $process_type ] );
	}

	/**
	 * Forget everything learned, for tests and for a support reset.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
		delete_option( self::INFLIGHT_OPTION );
	}

	/**
	 * Lower the ceiling toward an observed kill point.
	 *
	 * Deliberately not a jump straight to the observed value: one anomalous sample — a
	 * host hiccup, a slow neighbour on shared infrastructure — should move the estimate,
	 * not define it. Three quarters of the distance converges quickly and survives noise.
	 *
	 * @param string $process_type Registered process type.
	 * @param float  $died_at      Seconds the killed tick had survived.
	 * @return void
	 */
	private static function lower_ceiling( $process_type, $died_at ) {
		$current = self::ceiling( $process_type );
		$target  = (int) floor( $died_at );

		if ( $target >= $current ) {
			// It ran at least as long as we allowed for; nothing to learn from that.
			return;
		}

		$next = (int) round( $current - ( ( $current - $target ) * 0.75 ) );
		self::store_ceiling( $process_type, $next );
	}

	/**
	 * Persist a ceiling, clamped, writing only on a real change.
	 *
	 * @param string $process_type Registered process type.
	 * @param int    $seconds      Proposed ceiling.
	 * @return void
	 */
	private static function store_ceiling( $process_type, $seconds ) {
		$seconds = (int) max( self::CEILING_MIN, min( self::CEILING_MAX, (int) $seconds ) );

		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( isset( $all[ $process_type ] ) && (int) $all[ $process_type ] === $seconds ) {
			return;
		}

		$all[ $process_type ] = $seconds;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Current in-flight marks.
	 *
	 * @return array
	 */
	private static function inflight() {
		$all = get_option( self::INFLIGHT_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Persist in-flight marks.
	 *
	 * @param array $inflight Marks.
	 * @return void
	 */
	private static function save_inflight( array $inflight ) {
		update_option( self::INFLIGHT_OPTION, $inflight, false );
	}
}
