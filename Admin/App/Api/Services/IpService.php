<?php
/**
 * IP Service
 *
 * Canonical helper for resolving the real client IP behind reverse proxies
 * (Cloudflare / NGINX / AWS ALB / etc.). Single source of truth — replaces
 * the three duplicated `get_client_ip()` implementations that previously
 * lived in MonitoringLogController, RedirectionRules, and AuthController.
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

/**
 * Class IpService
 *
 */
class IpService {

	/**
	 * Resolve the real client IP, unwrapping common reverse-proxy headers.
	 *
	 * Lookup order:
	 *   1. `HTTP_CLIENT_IP`         — set by some proxies / WAFs.
	 *   2. `HTTP_X_FORWARDED_FOR`   — proxy chain; take the first hop
	 *                                 (closest to the original client).
	 *                                 Cloudflare sets this, so its clients
	 *                                 are covered without a CF-specific
	 *                                 check.
	 *   3. `REMOTE_ADDR` (fallback) — direct connection only.
	 *
	 * The final value goes through `filter_var(..., FILTER_VALIDATE_IP)`
	 * so a forged or malformed header never lands in a log row, a rate-
	 * limit key, or a redirection rule match.
	 *
	 * Returns `'0.0.0.0'` when nothing validates — covers CLI invocations,
	 * cron without a request context, and edge cases where a downstream
	 * header is empty or garbage. Callers should treat `'0.0.0.0'` as a
	 * "no real client IP available" sentinel rather than a real address.
	 *
	 * Why not also check Cloudflare-specific `HTTP_CF_CONNECTING_IP`?
	 * Because Cloudflare also sets `X-Forwarded-For` with the same value,
	 * and the second branch above picks it up. Adding a CF-specific check
	 * would help only on sites that strip X-Forwarded-For — an unusual
	 * configuration. If that becomes a real customer scenario, prepend
	 * a `HTTP_CF_CONNECTING_IP` check ahead of `HTTP_CLIENT_IP` here.
	 *
	 * @return string Validated IP, or '0.0.0.0' when nothing usable was found.
	 */
	public static function get_client_ip() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below via FILTER_VALIDATE_IP.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below.
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated below.
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = trim( $parts[0] );
		}

		$validated = filter_var( $ip, FILTER_VALIDATE_IP );
		return ( false !== $validated && null !== $validated ) ? $validated : '0.0.0.0';
	}
}