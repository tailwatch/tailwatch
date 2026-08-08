<?php
/**
 * IP Service
 *
 * Canonical helper for resolving the real client IP behind reverse proxies
 * (Cloudflare / NGINX / AWS ALB / etc.). Single source of truth — replaces
 * the three duplicated `get_client_ip()` implementations that previously
 * lived in MonitoringLogController and RedirectionRules.
 *
 * Static API by design: the helper is pure (no state, no side effects),
 * needs no constructor wiring, and the call sites are short:
 *
 *     $ip = IpService::get_client_ip();
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services
 */

namespace Tailwatch\Admin\App\Api\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\IpManagement\GetIpServices;

/**
 * Class IpService
 *
 */
class IpService {

	/**
	 * Resolve the client IP for logging and rule matching.
	 *
	 * Delegates to the hardened resolver GetIpServices::tailwatch_get_client_ip(),
	 * which returns REMOTE_ADDR by default and only consults X-Forwarded-For
	 * when the site has explicitly enabled the `trust_proxy_headers` setting.
	 * In that case it walks the header right-to-left to the first public
	 * address — the value the trusted edge proxy actually observed — so a
	 * client-supplied X-Forwarded-For or Client-IP header is never taken at
	 * face value.
	 *
	 * The result is validated with FILTER_VALIDATE_IP, which guarantees a
	 * well-formed IP. Validation confirms form, not authenticity: only the
	 * `trust_proxy_headers` gate decides whether a forwarded header is
	 * honoured at all.
	 *
	 * Returns `'0.0.0.0'` when no usable address is available (CLI, cron
	 * without a request context, or malformed input). Callers should treat
	 * `'0.0.0.0'` as a "no real client IP available" sentinel rather than a
	 * real address.
	 *
	 * @return string Validated client IP, or '0.0.0.0' when none is available.
	 */
	public static function get_client_ip() {
		$ip = GetIpServices::tailwatch_get_client_ip();
		return '' === $ip ? '0.0.0.0' : $ip;
	}
}