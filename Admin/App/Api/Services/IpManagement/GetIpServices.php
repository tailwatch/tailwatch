<?php
/**
 * Client IP resolution (including proxy headers).
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Services\IpManagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GetIpServices
 */
class GetIpServices {

	public static function tailwatch_get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Trust proxy headers if configured
		global $wpdb;
		$table       = $wpdb->prefix . TAILWATCH_DB_TABLE_NAME;
		$trust_proxy = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
			$wpdb->prepare(
				'SELECT `value` FROM %i WHERE `key` = %s AND `option` = %s LIMIT 1',
				$table,
				'trust_proxy_headers',
				'login_defender_settings'
			)
		);

		if ( $trust_proxy && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$forwarded_ips    = array_map( 'trim', explode( ',', $forwarded_header ) );

			// Take the RIGHTMOST entry — the one appended by our own trusted edge
			// proxy — not the leftmost. The leftmost is client-controlled and can
			// be forged to evade a block or frame a victim IP; the rightmost is
			// the address the trusted proxy actually observed. Walk right-to-left
			// to the first public IP so an internal proxy hop (private range) is
			// skipped rather than treated as the client.
			foreach ( array_reverse( $forwarded_ips ) as $candidate ) {
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$ip = $candidate;
					break;
				}
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? self::tailwatch_normalize_ip( $ip ) : '0.0.0.0';
	}

	/**
	 * Canonicalize an IP so the same address written in different forms
	 * (compressed vs expanded IPv6, upper vs lower-case hex) yields one stable
	 * value for keying and comparison. IPv4 round-trips unchanged. The input is
	 * returned untouched when it is not a valid IP (e.g. a CIDR range), so
	 * callers can safely pass either form.
	 *
	 * @param string $ip IP address.
	 * @return string Canonical IP, or the original string when not a valid IP.
	 */
	public static function tailwatch_normalize_ip( $ip ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits a warning on malformed input; false is explicitly handled on the next line.
		$packed = @inet_pton( (string) $ip );
		if ( false === $packed ) {
			return $ip;
		}
		// Collapse an IPv4-mapped IPv6 address to plain IPv4 so the same host keys
		// and matches identically whether a dual-stack server presents it as v4 or
		// v4-mapped-v6 (::ffff:a.b.c.d). Otherwise an IPv4 block/whitelist rule would
		// not match the mapped form — a real block-evasion path. Detected on the
		// packed bytes (no text-format dependency): a mapped address is 16 bytes =
		// ten 0x00, then 0xFFFF, then the 4 IPv4 bytes. A normal IPv6 that merely
		// contains an "ffff" hextet does NOT match (its leading bytes are non-zero).
		if ( 16 === strlen( $packed ) && 0 === strncmp( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", 12 ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_ntop emits a warning on malformed input; false is explicitly handled below.
			$v4 = @inet_ntop( substr( $packed, -4 ) );
			if ( false !== $v4 ) {
				return $v4;
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_ntop emits a warning on malformed input; false is explicitly handled by the return expression.
		$normalized = @inet_ntop( $packed );
		return false === $normalized ? $ip : $normalized;
	}
}
