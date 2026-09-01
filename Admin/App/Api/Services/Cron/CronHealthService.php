<?php
/**
 * Cron Health Service
 *
 * Single source of truth for the question "can this site run Tailwatch's
 * scheduled tasks?". Built on WordPress core's own cron primitives and the
 * layered model core Site Health uses, so it stays accurate on the many valid
 * setups where WP-Cron is not self-triggered (system crontab, external runners,
 * ALTERNATE_WP_CRON).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Cron
 */

namespace Tailwatch\Admin\App\Api\Services\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layered WP-Cron health detector.
 *
 * Layer 1 — configuration short-circuits (cheap, no HTTP):
 *   - external cron runner present (Cavalcade / Cron Control) -> managed
 *   - DISABLE_WP_CRON                                          -> disabled
 *   - ALTERNATE_WP_CRON                                        -> alternate
 * Layer 2 — a single blocking loopback probe to wp-cron.php, with a
 *   positive-only short-lived cache so healthy sites are not re-probed on
 *   every request.
 *
 * check() returns: array{ state:string, can_proceed:bool, reason:string }
 * where state is one of: ok | managed | alternate | disabled | blocked.
 * can_proceed is false only for a genuine "blocked" loopback failure; the
 * config states are all valid ways to run cron and must not block the plugin.
 */
class CronHealthService {

	/** @var string Transient that caches a healthy loopback result. */
	const CACHE_KEY = 'tailwatch_cron_status_ok';

	/** @var int Cache TTL, in seconds, for a healthy result. */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/** @var int Loopback request timeout, in seconds. */
	const PROBE_TIMEOUT = 3;

	/**
	 * External cron-runner plugins that replace WordPress's own dispatcher.
	 * When one is active the loopback probe is meaningless, so we trust it.
	 *
	 * @return array<string,string> Fully-qualified class name => display name.
	 */
	private static function external_runners() {
		return array(
			'\HM\Cavalcade\Plugin\Job'         => 'Cavalcade',
			'\Automattic\WP\Cron_Control\Main' => 'Cron Control',
		);
	}

	/**
	 * Evaluate cron health.
	 *
	 * @param bool $fresh When true, ignore the cached healthy result and re-probe.
	 * @return array{state:string,can_proceed:bool,reason:string}
	 */
	public function check( $fresh = false ) {
		// Layer 1a: an external runner owns dispatch.
		foreach ( self::external_runners() as $class => $name ) {
			if ( class_exists( $class ) ) {
				return $this->result(
					'managed',
					true,
					sprintf(
						/* translators: %s: name of the external cron-runner plugin, e.g. Cavalcade. */
						__( 'Scheduled tasks on this site are handled by the %s runner rather than the built-in WP-Cron, so Tailwatch\'s tasks run through it and no action is needed.', 'tailwatch' ),
						$name
					)
				);
			}
		}

		// Layer 1b: DISABLE_WP_CRON turns off WordPress's built-in trigger. Checked before
		// ALTERNATE_WP_CRON because WordPress core (_wp_cron) honours DISABLE_WP_CRON even when
		// ALTERNATE_WP_CRON is also set, so it is the flag that decides whether WP-Cron self-runs
		// (this mirrors WP Crontrol's ordering). A system crontab calling wp-cron.php is a valid
		// setup that cannot be detected from PHP, so the plugin continues with guidance, not a failure.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return $this->result(
				'disabled',
				true,
				__( 'WordPress\'s built-in cron trigger is switched off on this site (DISABLE_WP_CRON is set to true). That is fine if your host runs a system cron that calls wp-cron.php; if it does not, Tailwatch\'s scheduled tasks will not run on time.', 'tailwatch' )
			);
		}

		// Layer 1c: ALTERNATE_WP_CRON triggers scheduled tasks on page visits.
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return $this->result(
				'alternate',
				true,
				__( 'This site uses ALTERNATE_WP_CRON, so scheduled tasks are triggered during page visits. Tailwatch\'s tasks will run this way.', 'tailwatch' )
			);
		}

		// Layer 2: a healthy loopback is cached for a while to avoid re-probing.
		if ( ! $fresh && get_transient( self::CACHE_KEY ) ) {
			return $this->result( 'ok', true, __( 'WordPress cron (WP-Cron) is responding normally on this site.', 'tailwatch' ) );
		}

		$probe = $this->probe_loopback();

		if ( $probe['ok'] ) {
			set_transient( self::CACHE_KEY, 1, self::CACHE_TTL );
			return $this->result( 'ok', true, __( 'WordPress cron (WP-Cron) is responding normally on this site.', 'tailwatch' ) );
		}

		return $this->result( 'blocked', false, $probe['reason'] );
	}

	/**
	 * Convenience wrapper: may the plugin rely on scheduled tasks running?
	 *
	 * @param bool $fresh Ignore the cached result and re-probe.
	 * @return bool
	 */
	public function can_proceed( $fresh = false ) {
		$status = $this->check( $fresh );
		return $status['can_proceed'];
	}

	/**
	 * Result in the { success, message } shape the feature pre-flight gates
	 * consume. A config state (managed/alternate/disabled) reports success=true so valid
	 * setups are not blocked; only a genuine loopback failure reports false.
	 *
	 * @param string $trigger Optional context label (kept for call-site parity).
	 * @param bool   $fresh   Ignore the cached result and re-probe.
	 * @return array{success:bool,message:string}
	 */
	public function test( $trigger = '', $fresh = false ) {
		$status = $this->check( $fresh );

		return array(
			'success' => $status['can_proceed'],
			'message' => $status['reason'],
		);
	}

	/**
	 * Drop the cached healthy result so the next check re-probes.
	 *
	 * @return void
	 */
	public function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * One blocking loopback request to wp-cron.php, mirroring core spawn_cron().
	 * Cookies are intentionally not forwarded so the probe reflects the same
	 * unauthenticated conditions under which real WP-Cron requests run.
	 *
	 * @return array{ok:bool,reason:string}
	 */
	private function probe_loopback() {
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
		$url           = add_query_arg( 'doing_wp_cron', $doing_wp_cron, site_url( 'wp-cron.php' ) );

		$result = wp_remote_post(
			$url,
			array(
				'timeout'   => self::PROBE_TIMEOUT,
				'blocking'  => true,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter for local loopback SSL verification.
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'ok'     => false,
				'reason' => sprintf(
					/* translators: %s: the underlying connection error detail. */
					__( 'wp-cron.php could not be reached: %s. Loopback HTTP requests to this site may be blocked by the host, a firewall, or HTTP authentication.', 'tailwatch' ),
					$result->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $result );

		// Only a 2xx response confirms wp-cron.php was actually reached and ran. A
		// redirect (3xx), a client/server error (4xx/5xx), or an unreadable status (0)
		// all mean the scheduled-task trigger did not complete.
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'     => false,
				'reason' => 0 === $code
					? __( 'The loopback request to wp-cron.php did not return a readable HTTP response, so WordPress cannot confirm scheduled tasks are able to run. This usually points to a host, firewall, or DNS issue that stops the site from reaching itself.', 'tailwatch' )
					: sprintf(
						/* translators: %d: the HTTP status code returned by wp-cron.php. */
						__( 'wp-cron.php replied with HTTP status %d, so WordPress cannot start scheduled tasks. A 403 usually means the host or a security rule is blocking loopback requests to wp-cron.php.', 'tailwatch' ),
						$code
					),
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
		);
	}

	/**
	 * Shape a result array.
	 *
	 * @param string $state       One of ok|managed|alternate|disabled|blocked.
	 * @param bool   $can_proceed Whether scheduled tasks can be relied upon.
	 * @param string $reason      Human-readable explanation.
	 * @return array{state:string,can_proceed:bool,reason:string}
	 */
	private function result( $state, $can_proceed, $reason ) {
		return array(
			'state'       => $state,
			'can_proceed' => (bool) $can_proceed,
			'reason'      => $reason,
		);
	}
}
