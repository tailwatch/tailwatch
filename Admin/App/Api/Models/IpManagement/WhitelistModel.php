<?php
/**
 * IP and country whitelist model.
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Models\IpManagement;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\IpManagement\IpAccessService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WhitelistModel
 */
class WhitelistModel {

	public function is_valid_ip_or_range( $ip_range ) {
		if ( filter_var( $ip_range, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( preg_match( '/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip_range ) ) {
			list($ip, $mask) = explode( '/', $ip_range );
			return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && $mask >= 0 && $mask <= 32;
		}
		if ( preg_match( '/^([0-9a-fA-F:]+)\/\d{1,3}$/', $ip_range ) ) {
			list($ip, $mask) = explode( '/', $ip_range );
			return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && $mask >= 0 && $mask <= 128;
		}
		return false;
	}

	public function add_ip_whitelist( $ip_ranges, $exemption, $geoip ) {
		global $wpdb;
		$ip_ranges  = (array) $ip_ranges;
		$results    = array(
			'success' => true,
			'failed'  => array(),
			'added'   => array(),
		);
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$exemption  = in_array( $exemption, array( 'login_defender' ), true ) ? $exemption : 'login_defender';

		foreach ( $ip_ranges as $ip_range ) {
			if ( ! $this->is_valid_ip_or_range( $ip_range ) ) {
				Log::error(
					'Invalid IP range',
					array(
						'feature'  => 'ip_management',
						'action'   => 'add_ip_whitelist',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Invalid IP range';
				$results['success']             = false;
				continue;
			}

			// Check for existing entry
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'whitelist_ip',
					$ip_range
				)
			);

			if ( $existing ) {
				Log::info(
					'IP whitelist already exists',
					array(
						'feature'  => 'ip_management',
						'action'   => 'add_ip_whitelist',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'IP whitelist already exists';
				$results['success']             = false;
				continue;
			}

			// Country is display-only metadata (matching uses ip_range, never country),
			// so 'Unknown' is always acceptable — for single IPs as well as ranges.
			// A missing/disabled GeoIP DB or a private IP must NOT block whitelisting.
			$is_range     = strpos( $ip_range, '/' ) !== false;
			$lookup_ip    = $is_range ? explode( '/', $ip_range )[0] : $ip_range;
			$country_code = $geoip->get_country( $lookup_ip );

			$data = array(
				'ip_range'     => $ip_range,
				'country_code' => $country_code,
				'exemption'    => $exemption,
			);

			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'key'           => 'whitelist_ip',
					'option'        => $ip_range,
					'value'         => wp_json_encode( $data ),
					'type'          => 'whitelist',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => 1,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to add IP whitelist',
					array(
						'feature'  => 'ip_management',
						'action'   => 'add_ip_whitelist',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Database insertion failed';
				$results['success']             = false;
				continue;
			}
			delete_transient( 'tailwatch_whitelist_ip_all' );
			$results['added'][] = $ip_range;
		}

		return $results;
	}

	public function add_country_whitelist( $country_codes, $exemption ) {
		global $wpdb;
		$country_codes = (array) $country_codes;
		$results       = array(
			'success' => true,
			'failed'  => array(),
			'added'   => array(),
		);
		$table_name    = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$exemption     = in_array( $exemption, array( 'login_defender' ), true ) ? $exemption : 'login_defender';

		foreach ( $country_codes as $country_code ) {
			if ( ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
				Log::error(
					'Invalid country code',
					array(
						'feature'      => 'ip_management',
						'action'       => 'add_country_whitelist',
						'country_code' => $country_code,
					)
				);
				$results['failed'][ $country_code ] = 'Invalid country code';
				$results['success']                 = false;
				continue;
			}

			// Check for existing entry
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'whitelist_country',
					$country_code
				)
			);

			if ( $existing ) {
				Log::info(
					'Country whitelist already exists',
					array(
						'feature'      => 'ip_management',
						'action'       => 'add_country_whitelist',
						'country_code' => $country_code,
					)
				);
				$results['failed'][ $country_code ] = 'Country whitelist already exists';
				$results['success']                 = false;
				continue;
			}

			$data = array(
				'country_code' => $country_code,
				'exemption'    => $exemption,
			);

			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'key'           => 'whitelist_country',
					'option'        => $country_code,
					'value'         => wp_json_encode( $data ),
					'type'          => 'whitelist',
					'type_state'    => 'active',
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => 1,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to add country whitelist',
					array(
						'feature'      => 'ip_management',
						'action'       => 'add_country_whitelist',
						'country_code' => $country_code,
					)
				);
				$results['failed'][ $country_code ] = 'Database insertion failed';
				$results['success']                 = false;
				continue;
			}

			delete_transient( 'tailwatch_login_defender_whitelist_country_' . $country_code );
			$results['added'][] = $country_code;
		}

		return $results;
	}

	public function update_ip_whitelist( $ip_ranges, $exemption, $geoip ) {
		global $wpdb;
		$ip_ranges  = (array) $ip_ranges;
		$success    = true;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$exemption  = in_array( $exemption, array( 'login_defender' ), true ) ? $exemption : 'login_defender';

		foreach ( $ip_ranges as $ip_range ) {
			if ( ! $this->is_valid_ip_or_range( $ip_range ) ) {
				Log::error(
					'Invalid IP range',
					array(
						'feature'  => 'ip_management',
						'action'   => 'update_ip_whitelist',
						'ip_range' => $ip_range,
					)
				);
				$success = false;
				continue;
			}

			// Check if entry exists
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'whitelist_ip',
					$ip_range
				)
			);

			if ( ! $existing ) {
				Log::info(
					'IP whitelist does not exist',
					array(
						'feature'  => 'ip_management',
						'action'   => 'update_ip_whitelist',
						'ip_range' => $ip_range,
					)
				);
				$success = false;
				continue;
			}

			// Determine country code - check if it's a range or single IP
			$is_range = strpos( $ip_range, '/' ) !== false;

			if ( $is_range ) {
				// For CIDR ranges, extract the subnet for country lookup
				list($subnet) = explode( '/', $ip_range );
				$country_code = $geoip->get_country( $subnet );
			} else {
				// For single IPs, normal lookup
				$country_code = $geoip->get_country( $ip_range );
			}

			$data = array(
				'ip_range'     => $ip_range,
				'country_code' => $country_code,
				'exemption'    => $exemption,
			);

			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'value'         => wp_json_encode( $data ),
					'type_state'    => 'active',
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => 1,
				),
				array(
					'key'    => 'whitelist_ip',
					'option' => $ip_range,
				),
				array( '%s', '%s', '%s', '%d' ),
				array( '%s', '%s' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to update IP whitelist',
					array(
						'feature'  => 'ip_management',
						'action'   => 'update_ip_whitelist',
						'ip_range' => $ip_range,
					)
				);
				$success = false;
			}
			delete_transient( 'tailwatch_whitelist_ip_all' );
		}

		return $success;
	}

	public function update_country_whitelist( $country_codes, $exemption ) {
		global $wpdb;
		$country_codes = (array) $country_codes;
		$success       = true;
		$table_name    = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$exemption     = in_array( $exemption, array( 'login_defender' ), true ) ? $exemption : 'login_defender';

		foreach ( $country_codes as $country_code ) {
			if ( ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
				Log::error(
					'Invalid country code',
					array(
						'feature'      => 'ip_management',
						'action'       => 'update_country_whitelist',
						'country_code' => $country_code,
					)
				);
				$success = false;
				continue;
			}

			// Check if entry exists
			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'whitelist_country',
					$country_code
				)
			);

			if ( ! $existing ) {
				Log::info(
					'Country whitelist does not exist',
					array(
						'feature'      => 'ip_management',
						'action'       => 'update_country_whitelist',
						'country_code' => $country_code,
					)
				);
				$success = false;
				continue;
			}

			$data = array(
				'country_code' => $country_code,
				'exemption'    => $exemption,
			);

			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'value'         => wp_json_encode( $data ),
					'type_state'    => 'active',
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => 1,
				),
				array(
					'key'    => 'whitelist_country',
					'option' => $country_code,
				),
				array( '%s', '%s', '%s', '%d' ),
				array( '%s', '%s' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to update country whitelist',
					array(
						'feature'      => 'ip_management',
						'action'       => 'update_country_whitelist',
						'country_code' => $country_code,
					)
				);
				$success = false;
			}
			// Clear transient cache for the country code
			delete_transient( 'tailwatch_login_defender_whitelist_country_' . $country_code );
		}

		return $success;
	}

	public function delete_ip_whitelist( $ip_ranges, $is_delete = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$success    = true;

		if ( $is_delete ) {
			$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array( 'key' => 'whitelist_ip' ),
				array( '%s' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to delete all IP whitelists',
					array(
						'feature' => 'ip_management',
						'action'  => 'delete_ip_whitelist',
					)
				);
				$success = false;
			}

			delete_transient( 'tailwatch_whitelist_ip_all' );
		} else {
			$ip_ranges = (array) $ip_ranges;
			foreach ( $ip_ranges as $ip_range ) {
				if ( ! $this->is_valid_ip_or_range( $ip_range ) ) {
					Log::error(
						'Invalid IP range for deletion',
						array(
							'feature'  => 'ip_management',
							'action'   => 'delete_ip_whitelist',
							'ip_range' => $ip_range,
						)
					);
					$success = false;
					continue;
				}

				$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'key'    => 'whitelist_ip',
						'option' => $ip_range,
					),
					array( '%s', '%s' )
				);

				if ( $result === false ) {
					Log::error(
						'Failed to delete IP whitelist',
						array(
							'feature'  => 'ip_management',
							'action'   => 'delete_ip_whitelist',
							'ip_range' => $ip_range,
						)
					);
					$success = false;
				}

				delete_transient( 'tailwatch_whitelist_ip_all' );
			}
		}

		return $success;
	}

	public function delete_country_whitelist( $country_codes, $is_delete = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$success    = true;

		if ( $is_delete ) {
			$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array( 'key' => 'whitelist_country' ),
				array( '%s' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to delete all country whitelists',
					array(
						'feature' => 'ip_management',
						'action'  => 'delete_country_whitelist',
					)
				);
				$success = false;
			}

			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
					'%tailwatch_login_defender_whitelist_country_%'
				)
			);
		} else {
			$country_codes = (array) $country_codes;
			foreach ( $country_codes as $country_code ) {
				if ( ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
					Log::error(
						'Invalid country code for deletion',
						array(
							'feature'      => 'ip_management',
							'action'       => 'delete_country_whitelist',
							'country_code' => $country_code,
						)
					);
					$success = false;
					continue;
				}

				$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'key'    => 'whitelist_country',
						'option' => $country_code,
					),
					array( '%s', '%s' )
				);

				if ( $result === false ) {
					Log::error(
						'Failed to delete country whitelist',
						array(
							'feature'      => 'ip_management',
							'action'       => 'delete_country_whitelist',
							'country_code' => $country_code,
						)
					);
					$success = false;
				}

				delete_transient( 'tailwatch_login_defender_whitelist_country_' . $country_code );
			}
		}

		return $success;
	}

	public function get_ip_whitelists( $limit = null, $page = null, $geoip = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;

		$count_sql = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE `key` = %s AND is_active = %d',
			$table_name,
			'whitelist_ip',
			1
		);

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND is_active = %d',
			$table_name,
			'whitelist_ip',
			1
		);

		if ( $limit !== null && $page !== null ) {
			$limit  = absint( $limit );
			$page   = absint( $page );
			$offset = ( $page - 1 ) * $limit;
			$sql   .= ' LIMIT %d OFFSET %d';
			$sql    = $wpdb->prepare( $sql, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$whitelists = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array();
		foreach ( $whitelists as $whitelist ) {
			$data = json_decode( $whitelist->value, true );

			// Get country code - if missing, lookup with range support
			$country_code = $data['country_code'] ?? null;
			if ( ! $country_code && $geoip ) {
				$is_range = strpos( $whitelist->option, '/' ) !== false;
				if ( $is_range ) {
					list($subnet) = explode( '/', $whitelist->option );
					$country_code = $geoip->get_country( $subnet );
				} else {
					$country_code = $geoip->get_country( $whitelist->option );
				}
			}

			$result[] = array(
				'ip_range'     => $whitelist->option,
				'country_code' => $country_code ?? 'Unknown',
				'exemption'    => $data['exemption'],
			);
		}

		if ( $limit !== null && $page !== null ) {
			$total = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'data'        => $result,
				'total'       => (int) $total,
				'page'        => (int) $page,
				'limit'       => (int) $limit,
				'total_pages' => $total > 0 ? ceil( $total / $limit ) : 0,
			);
		}

		return $result;
	}

	public function get_country_whitelists( $limit = null, $page = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;

		$count_sql = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE `key` = %s AND is_active = %d',
			$table_name,
			'whitelist_country',
			1
		);

		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND is_active = %d',
			$table_name,
			'whitelist_country',
			1
		);

		if ( $limit !== null && $page !== null ) {
			$limit  = absint( $limit );
			$page   = absint( $page );
			$offset = ( $page - 1 ) * $limit;
			$sql   .= ' LIMIT %d OFFSET %d';
			$sql    = $wpdb->prepare( $sql, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$whitelists = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array();
		foreach ( $whitelists as $whitelist ) {
			$data     = json_decode( $whitelist->value, true );
			$result[] = array(
				'country_code' => $whitelist->option,
				'exemption'    => $data['exemption'],
			);
		}

		if ( $limit !== null && $page !== null ) {
			$total = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'data'        => $result,
				'total'       => (int) $total,
				'page'        => (int) $page,
				'limit'       => (int) $limit,
				'total_pages' => $total > 0 ? ceil( $total / $limit ) : 0,
			);
		}

		return $result;
	}

	public function is_ip_whitelisted( $ip ) {
		// Single-list transient avoids the per-IP key vs CIDR range invalidation mismatch
		// that made the original per-IP caching unreliable.
		$list_cache_key = 'tailwatch_whitelist_ip_all';
		$whitelists     = get_transient( $list_cache_key );

		if ( false === $whitelists ) {
			global $wpdb;
			$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
			$rows       = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT `value` FROM %i WHERE `key` = %s AND is_active = %d',
					$table_name,
					'whitelist_ip',
					1
				)
			);

			$whitelists = array();
			foreach ( $rows as $row ) {
				$data = json_decode( $row->value, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$whitelists[] = $data;
				}
			}
			set_transient( $list_cache_key, $whitelists, HOUR_IN_SECONDS );
		}

		foreach ( $whitelists as $data ) {
			if ( IpAccessService::is_ip_in_range( $ip, $data['ip_range'] ) ) {
				return array( 'exemption' => $data['exemption'] );
			}
		}

		return false;
	}

	public function is_country_whitelisted( $country_code ) {
		$cache_key = 'tailwatch_login_defender_whitelist_country_' . $country_code;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$whitelist  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND is_active = %d',
				$table_name,
				'whitelist_country',
				$country_code,
				1
			)
		);

		if ( $whitelist ) {
			$data = json_decode( $whitelist->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON for whitelist country',
					array(
						'feature' => 'ip_management',
						'action'  => 'is_country_whitelisted',
						'option'  => $whitelist->option,
					)
				);
				return false;
			}
			$result = array( 'exemption' => $data['exemption'] );
			set_transient( $cache_key, $result, HOUR_IN_SECONDS );
			return $result;
		}

		set_transient( $cache_key, array(), HOUR_IN_SECONDS );
		return false;
	}

	public function count_ip_whitelists() {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$sql        = $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `key` = 'whitelist_ip' AND is_active = 1", $table_name );
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function count_country_whitelists() {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$sql        = $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `key` = 'whitelist_country' AND is_active = 1", $table_name );
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
