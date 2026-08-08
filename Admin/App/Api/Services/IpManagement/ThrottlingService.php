<?php
/**
 * Throttling / rate limiting for IPs.
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Services\IpManagement;

use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ThrottlingService
 */
class ThrottlingService {

	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function is_throttled( $ip ) {
		$cache_key     = 'tailwatch_login_defender_throttle_' . md5( $ip );
		$throttle_data = get_transient( $cache_key );
		if ( $throttle_data === false || ! is_array( $throttle_data ) || ! isset( $throttle_data['expires'] ) ) {
			return false;
		}

		$current_timestamp = strtotime( current_time( 'mysql' ) );
		if ( $throttle_data['expires'] < $current_timestamp ) {
			delete_transient( $cache_key );
			return false;
		}

		return true;
	}

	public function increment_throttle( $ip ) {
		$cache_key         = 'tailwatch_login_defender_throttle_' . md5( $ip );
		$throttle_data     = get_transient( $cache_key );
		$current_timestamp = strtotime( current_time( 'mysql' ) );

		if ( $throttle_data === false ) {
			$throttle_data = array(
				'attempts' => 0,
				'expires'  => $current_timestamp,
			);
		}

		++$throttle_data['attempts'];
		$delays                   = $this->settings['throttling_delays'] ?? array( 5, 10, 20 );
		$delay_index              = min( $throttle_data['attempts'] - 1, count( $delays ) - 1 );
		$delay                    = $delays[ $delay_index ] ?? end( $delays );
		$throttle_data['expires'] = $current_timestamp + $delay;

		set_transient( $cache_key, $throttle_data, $delay );
		Log::info(
			'Incremented throttle',
			array(
				'feature'  => 'ip_management',
				'action'   => 'increment_throttle',
				'ip'       => $ip,
				'attempts' => $throttle_data['attempts'],
				'delay'    => $delay,
			)
		);
	}

	public function get_remaining_time( $ip ) {
		$cache_key     = 'tailwatch_login_defender_throttle_' . md5( $ip );
		$throttle_data = get_transient( $cache_key );
		if ( $throttle_data === false || ! is_array( $throttle_data ) || ! isset( $throttle_data['expires'] ) ) {
			return 0;
		}

		$current_timestamp = strtotime( current_time( 'mysql' ) );
		$remaining         = max( 0, $throttle_data['expires'] - $current_timestamp );
		return $remaining;
	}
}
