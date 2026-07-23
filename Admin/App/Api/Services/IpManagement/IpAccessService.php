<?php
/**
 * IP access check service — login-defense lockout decisions plus the shared
 * IP-range matching helper used across IP Management.
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Services\IpManagement;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;
use Tailwatch\Admin\App\Api\Models\LoginDefender\IpActivityModel;
use Tailwatch\Admin\App\Api\Models\IpManagement\WhitelistModel;
use Tailwatch\Admin\App\Api\Models\IpManagement\RuleModel;
// IpProtectionController import removed — wptw_login_defender_settings() now uses $this->settings.
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IpAccessService
 */
class IpAccessService {

	public $ip_activity;
	private $whitelist;
	private $rules;
	private $geoip;
	private $settings;
	private $notifications;
	private $throttling;
	private $block_reason;
	private static $access_cache = array();

	public function __construct(
		IpActivityModel $ip_activity,
		WhitelistModel $whitelist,
		RuleModel $rules,
		GeoIPService $geoip,
		$settings
	) {
		$this->ip_activity     = $ip_activity;
		$this->whitelist       = $whitelist;
		$this->rules           = $rules;
		$this->geoip           = $geoip;
		$this->settings        = $settings;
		$this->notifications   = new NotificationService();
		$this->throttling      = new ThrottlingService( $settings );
		$this->block_reason    = '';
	}

	private function wptw_ip_managment_settings() {
		// Cache per request: check_access() can run several times per request and
		// across several IpAccessService instances. Building IpManagementController
		// each time is wasteful (it also re-registers its template_redirect hook),
		// so resolve the enabled-flags once. Settings are constant within a request.
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$controller = new IpManagementController();
		$cached     = $controller->wptw_ips_managment_is_enabled();
		return $cached;
	}

	private function wptw_login_defender_settings() {
		// Use the settings already injected via the constructor.
		// Previously this created a new IpProtectionController which itself
		// instantiated another IpAccessService — a wasteful circular chain.
		return $this->settings;
	}

	public function check_access( $ip ) {
		$login_defender_settings  = $this->wptw_login_defender_settings();
		$ip_managment_settings = $this->wptw_ip_managment_settings();

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			Log::error(
				'Invalid IP address',
				array(
					'feature' => 'ip_management',
					'action'  => 'check_access',
					'ip'      => $ip,
				)
			);
			$this->block_reason = 'Invalid IP address';
			return array(
				'allowed'      => false,
				'method'       => 'none',
				'block_reason' => $this->block_reason,
			);
		}

		$country = $this->geoip->get_country( $ip );

		// Whitelist = fully trusted. A whitelisted IP (or country) bypasses EVERY
		// check — IP blacklist, country block, and Login Defender brute-force. Resolve
		// it FIRST so a trusted entry is never blocked by a later check (matches the
		// order in evaluate_entire_site_block). There is a single exemption type
		// (login_defender), so presence in the whitelist alone means "trusted".
		$whitelist_check = false;
		if ( ! empty( $ip_managment_settings['whitelist_feature'] ) ) {
			$whitelist_check = $this->whitelist->is_ip_whitelisted( $ip );
			$is_whitelisted  = (bool) $whitelist_check;
			if ( ! $is_whitelisted && $country && 'Unknown' !== $country ) {
				$is_whitelisted = (bool) $this->whitelist->is_country_whitelisted( $country );
			}
			if ( $is_whitelisted ) {
				return array(
					'allowed'      => true,
					'method'       => 'none',
					'block_reason' => '',
				);
			}
		}

		// Check IP rules if IP management is enabled
		if ( $ip_managment_settings['blacklist_feature'] ) {
			$ip_rules = $this->rules->get_active_rules( 'ip' );
			foreach ( $ip_rules as $rule ) {
				$data = json_decode( $rule->value, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					Log::error(
						'Invalid JSON for IP rule',
						array(
							'feature' => 'ip_management',
							'action'  => 'check_access',
							'option'  => $rule->option,
						)
					);
					continue;
				}
				if ( $this->is_ip_in_range( $ip, $data['ip_range'] ) ) {
					$current_timestamp = strtotime( current_time( 'mysql' ) );
					if ( $data['block_type'] === 'temporary' && strtotime( $data['block_start_time'] ) + $data['block_duration'] < $current_timestamp ) {
						Log::info(
							'Clearing expired IP rule for range',
							array(
								'feature'  => 'ip_management',
								'action'   => 'check_access',
								'ip_range' => $data['ip_range'],
							)
						);
						$this->rules->unblock_ip_range( $data['ip_range'], $this->geoip );
						continue;
					}
					$this->block_reason = $data['reason'] ? $data['reason'] : $this->settings['error_messages']['ip_blocked'];
					Log::info(
						'IP blocked due to IP rule',
						array(
							'feature'      => 'ip_management',
							'action'       => 'check_access',
							'ip'           => $ip,
							'ip_range'     => $data['ip_range'],
							'block_reason' => $this->block_reason,
						)
					);
					$this->notifications->log_notification(
						'ip_rule_block',
						array(
							'ip'    => $ip,
							'range' => $data['ip_range'],
						)
					);
					return array(
						'allowed'      => false,
						'method'       => 'ip_management',
						'block_reason' => $this->block_reason,
					);
				}
			}
		}

		// HOOK: Allow premium plugin to handle country-based rules checking
		$country_rules_check = apply_filters( 'wptw_login_defender_country_rules_check', false, $ip, $country, $ip_managment_settings, $this->settings, $this->notifications );
		if ( $country_rules_check !== false && is_array( $country_rules_check ) ) {
			return $country_rules_check;
		}

		// Throttling (login defender). A whitelisted IP already returned "allowed"
		// at the top, so no per-IP whitelist re-check is needed here.
		if ( $login_defender_settings['ip_protection_enabled'] ?? false ) {
			if ( $this->throttling->is_throttled( $ip ) ) {
				$this->block_reason = str_replace( '[minutes]', ceil( $this->throttling->get_remaining_time( $ip ) / 60 ), $this->settings['error_messages']['rate_limit'] );
				Log::info(
					'IP throttled',
					array(
						'feature' => 'ip_management',
						'action'  => 'check_access',
						'ip'      => $ip,
					)
				);
				$this->notifications->log_notification( 'throttle', array( 'ip' => $ip ) );
				return array(
					'allowed'      => false,
					'method'       => 'ip_activity',
					'block_reason' => $this->block_reason,
				);
			}
		}

		// Check IP activity if login defender is enabled. A whitelisted IP already
		// returned "allowed" at the top, so $whitelist_check is always falsey here.
		if ( $login_defender_settings['ip_protection_enabled'] ?? false ) {
			if ( ! $whitelist_check ) {
				$activity = $this->ip_activity->get_activity( $ip );
				if ( $activity ) {
					$data = json_decode( $activity->value, true );
					if ( json_last_error() !== JSON_ERROR_NONE ) {
						Log::error(
							'Invalid JSON for IP activity',
							array(
								'feature' => 'ip_management',
								'action'  => 'check_access',
								'ip'      => $ip,
							)
						);
						return array(
							'allowed'      => true,
							'method'       => 'ip_activity',
							'block_reason' => '',
						);
					}
					if ( isset( $data['lock_type'] ) && $data['lock_type'] !== 'none' ) {
						$current_timestamp = strtotime( current_time( 'mysql' ) );
						if ( $data['lock_type'] === 'temporary' && strtotime( $data['lock_time'] ) + $data['lock_duration'] < $current_timestamp ) {
							return array(
								'allowed'      => true,
								'method'       => 'ip_activity',
								'block_reason' => '',
							);
						}
						$retry_time         = $data['lock_type'] === 'temporary' ? gmdate( 'Y-m-d H:i:s', strtotime( $data['lock_time'] ) + $data['lock_duration'] ) : 'permanently';
						$this->block_reason = str_replace( '[time]', $retry_time, $this->settings['error_messages']['ip_blocked'] );
						Log::info(
							'IP blocked due to lock_type',
							array(
								'feature'   => 'ip_management',
								'action'    => 'check_access',
								'ip'        => $ip,
								'lock_type' => $data['lock_type'],
							)
						);
						$this->notifications->log_notification(
							'ip_block',
							array(
								'ip'        => $ip,
								'lock_type' => $data['lock_type'],
							)
						);
						return array(
							'allowed'      => false,
							'method'       => 'ip_activity',
							'block_reason' => $this->block_reason,
						);
					}
					return array(
						'allowed'      => true,
						'method'       => 'ip_activity',
						'block_reason' => '',
					);
				}
				return array(
					'allowed'      => true,
					'method'       => 'ip_activity',
					'block_reason' => '',
				);
			}
		}

		return array(
			'allowed'      => true,
			'method'       => 'none',
			'block_reason' => '',
		);
	}

	public function log_failed_attempt( $ip, $attempt_type, $username = '' ) {
		$login_defender_settings = $this->wptw_login_defender_settings();
		if ( ! ( $login_defender_settings['ip_protection_enabled'] ?? false ) ) {
			return false;
		}

		// Whitelisted IP = trusted: do not count failed attempts against it.
		if ( $this->whitelist->is_ip_whitelisted( $ip ) ) {
			return false;
		}

		unset( self::$access_cache[ $ip ] );
		$result = $this->ip_activity->log_attempt( $ip, $attempt_type, $username );
		if ( $result && $result['is_blocked'] ) {
			$this->throttling->increment_throttle( $ip );
			Log::info(
				'Logged failed attempt, blocked',
				array(
					'feature'      => 'ip_management',
					'action'       => 'log_failed_attempt',
					'ip'           => $ip,
					'attempt_type' => $attempt_type,
				)
			);
			$this->notifications->log_notification(
				'failed_attempt',
				array(
					'ip'   => $ip,
					'type' => $attempt_type,
				)
			);
		} elseif ( $result ) {
			Log::info(
				'Logged failed attempt',
				array(
					'feature'            => 'ip_management',
					'action'             => 'log_failed_attempt',
					'ip'                 => $ip,
					'attempt_type'       => $attempt_type,
					'remaining_attempts' => $result['remaining_attempts'],
				)
			);
			$this->notifications->log_notification(
				'failed_attempt',
				array(
					'ip'                 => $ip,
					'type'               => $attempt_type,
					'remaining_attempts' => $result['remaining_attempts'],
				)
			);
		}
		return $result;
	}

	/**
	 * Check if an IP is within a given range (supports CIDR notation for IPv4 and IPv6)
	 * Static method - can be called without instantiating the class
	 *
	 * @param string $ip IP address to check
	 * @param string $range IP address or CIDR range (e.g., 192.168.1.0/24)
	 * @return bool True if IP is in range, false otherwise
	 */
	public static function is_ip_in_range( $ip, $range ) {
		// Normalize the candidate up front so an IPv4-mapped IPv6 form (::ffff:a.b.c.d)
		// is collapsed to plain IPv4 — the CIDR branches below then see the v4 address
		// and an IPv4 rule matches it. wptw_get_client_ip() already normalizes, so this
		// is defense-in-depth for any direct caller.
		$ip = GetIpServices::wptw_normalize_ip( $ip );

		// Exact single-IP match. Compare the canonical form of the rule too so the
		// same IPv6 address written differently (compressed/expanded, mixed-case hex)
		// still matches. normalize() returns CIDR strings untouched, so range rules
		// fall through to the subnet branches below unchanged.
		if ( $ip === $range || $ip === GetIpServices::wptw_normalize_ip( $range ) ) {
			return true;
		}

		if ( strpos( $range, '/' ) !== false && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			list($subnet, $mask) = explode( '/', $range );
			if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || $mask < 0 || $mask > 32 ) {
				return false;
			}
			$ip_long     = ip2long( $ip );
			$subnet_long = ip2long( $subnet );
			$mask_long   = ~( ( 1 << ( 32 - $mask ) ) - 1 );
			return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
		}

		if ( strpos( $range, '/' ) !== false && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			list($subnet, $mask) = explode( '/', $range );
			if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) || $mask < 0 || $mask > 128 ) {
				return false;
			}
			$ip_bin     = inet_pton( $ip );
			$subnet_bin = inet_pton( $subnet );
			$mask_bin   = str_repeat( "\xFF", $mask >> 3 );
			if ( $mask % 8 !== 0 ) {
				$mask_bin .= chr( 0xFF << ( 8 - ( $mask % 8 ) ) );
				$mask_bin  = str_pad( $mask_bin, 16, "\x00" );
			} else {
				$mask_bin = str_pad( $mask_bin, 16, "\x00" );
			}
			return ( $ip_bin & $mask_bin ) === ( $subnet_bin & $mask_bin );
		}

		return false;
	}
}
