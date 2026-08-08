<?php
/**
 * IP rule model (block/unblock IP ranges).
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Models\IpManagement;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Time\TimeService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RuleModel
 */
class RuleModel {

	public function add_ip_rule( $ip_ranges, $block_type, $block_duration, $scope, $reason, $geoip, $block_page = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$results    = array(
			'success' => true,
			'failed'  => array(),
			'added'   => array(),
		);

		if ( ! is_array( $ip_ranges ) ) {
			$ip_ranges = array( $ip_ranges );
		}

		if ( ! in_array( $block_type, array( 'temporary', 'permanent', 'unblocked' ) ) ) {
			Log::error(
				'Invalid block_type',
				array(
					'feature'    => 'ip_management',
					'action'     => 'add_ip_rule',
					'block_type' => $block_type,
				)
			);
			return array(
				'success' => false,
				'failed'  => array( 'block_type' => 'Invalid block_type' ),
			);
		}

		if ( $block_duration < 0 || ( $block_type === 'temporary' && $block_duration > 31536000 ) ) {
			Log::error(
				'Invalid block_duration',
				array(
					'feature'        => 'ip_management',
					'action'         => 'add_ip_rule',
					'block_duration' => $block_duration,
				)
			);
			return array(
				'success' => false,
				'failed'  => array( 'block_duration' => 'Invalid block_duration' ),
			);
		}

		if ( strlen( $reason ) > 255 ) {
			Log::error(
				'Reason too long',
				array(
					'feature' => 'ip_management',
					'action'  => 'add_ip_rule',
				)
			);
			return array(
				'success' => false,
				'failed'  => array( 'reason' => 'Reason exceeds 255 characters' ),
			);
		}

		$scope      = in_array( $scope, array( 'login_forms', 'entire_site' ) ) ? $scope : 'login_forms';
		$reason     = sanitize_text_field( $reason );
		$is_active  = $block_type === 'unblocked' ? 0 : 1;
		$type_state = $block_type === 'unblocked' ? 'unblocked' : 'active';

		// Sanitize block_page (can be post_id as integer or custom path as string)
		if ( ! empty( $block_page ) ) {
			if ( is_numeric( $block_page ) ) {
				$block_page = absint( $block_page ); // Post ID
			} else {
				$block_page = sanitize_text_field( $block_page ); // Custom path
			}
		} else {
			$block_page = null;
		}

		// $wpdb->query('START TRANSACTION');
		foreach ( $ip_ranges as $ip_range ) {
			if ( ! $this->is_valid_ip_or_range( $ip_range ) ) {
				Log::error(
					'Invalid IP range',
					array(
						'feature'  => 'ip_management',
						'action'   => 'add_ip_rule',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Invalid IP range';
				$results['success']             = false;
				continue;
			}

			$existing_rule = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'ip_range_block',
					$ip_range
				)
			);

			if ( $existing_rule ) {
				Log::info(
					'IP rule already exists',
					array(
						'feature'  => 'ip_management',
						'action'   => 'add_ip_rule',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Already exists';
				$results['success']             = false;
				continue;
			}

			// Country is display-only metadata (matching uses ip_range, never country),
			// so 'Unknown' is always acceptable — for single IPs as well as ranges.
			// A missing/disabled GeoIP DB or a private IP must NOT block adding a rule.
			$is_range     = strpos( $ip_range, '/' ) !== false;
			$lookup_ip    = $is_range ? explode( '/', $ip_range )[0] : $ip_range;
			$country_code = $geoip->get_country( $lookup_ip );

			$data = array(
				'ip_range'         => $ip_range,
				'country_code'     => $country_code,
				'block_type'       => $block_type,
				'block_duration'   => absint( $block_duration ),
				'block_start_time' => current_time( 'mysql' ),
				'scope'            => $scope,
				'reason'           => $reason,
				'block_page'       => $block_page,
			);

			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'key'           => 'ip_range_block',
					'option'        => $ip_range,
					'value'         => wp_json_encode( $data ),
					'type'          => 'rule',
					'type_state'    => $type_state,
					'date_created'  => current_time( 'mysql' ),
					'date_modified' => current_time( 'mysql' ),
					'is_active'     => $is_active,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to add IP rule',
					array(
						'feature'  => 'ip_management',
						'action'   => 'add_ip_rule',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Database error';
				$results['success']             = false;
				continue;
			}

			$results['added'][] = $ip_range;
		}

		if ( $results['success'] && ! empty( $results['failed'] ) ) {
			$results['success'] = false; // Partial failure
		}

		// Refresh the rule cache even on a partial batch — rows are written
		// immediately (no wrapping transaction), so a new rule must enforce at once
		// rather than after the 1h TTL. Clear the _all variant too (the admin list).
		delete_transient( 'tailwatch_login_defender_ip_rules' );
		delete_transient( 'tailwatch_login_defender_ip_rules_all' );

		return $results;
	}

	public function update_ip_rule( $ip_ranges, $block_type, $block_duration, $scope, $reason, $geoip, $block_page = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$results    = array(
			'success' => true,
			'failed'  => array(),
			'updated' => array(),
		);

		if ( ! is_array( $ip_ranges ) ) {
			$ip_ranges = array( $ip_ranges );
		}

		if ( ! in_array( $block_type, array( 'temporary', 'permanent', 'unblocked' ) ) ) {
			Log::error(
				'Invalid block_type',
				array(
					'feature'    => 'ip_management',
					'action'     => 'update_ip_rule',
					'block_type' => $block_type,
				)
			);
			return array(
				'success' => false,
				'failed'  => array( 'block_type' => 'Invalid block_type' ),
			);
		}

		if ( $block_duration < 0 || ( $block_type === 'temporary' && $block_duration > 31536000 ) ) {
			Log::error(
				'Invalid block_duration',
				array(
					'feature'        => 'ip_management',
					'action'         => 'update_ip_rule',
					'block_duration' => $block_duration,
				)
			);
			return array(
				'success' => false,
				'failed'  => array( 'block_duration' => 'Invalid block_duration' ),
			);
		}

		if ( strlen( $reason ) > 255 ) {
			Log::error(
				'Reason too long',
				array(
					'feature' => 'ip_management',
					'action'  => 'update_ip_rule',
				)
			);
			return array(
				'success' => false,
				'failed'  => array( 'reason' => 'Reason exceeds 255 characters' ),
			);
		}

		$scope      = in_array( $scope, array( 'login_forms', 'entire_site' ) ) ? $scope : 'login_forms';
		$reason     = sanitize_text_field( $reason );
		$is_active  = $block_type === 'unblocked' ? 0 : 1;
		$type_state = $block_type === 'unblocked' ? 'unblocked' : 'active';

		// Sanitize block_page (can be post_id as integer or custom path as string)
		if ( ! empty( $block_page ) ) {
			if ( is_numeric( $block_page ) ) {
				$block_page = absint( $block_page ); // Post ID
			} else {
				$block_page = sanitize_text_field( $block_page ); // Custom path
			}
		} else {
			$block_page = null;
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $ip_ranges as $ip_range ) {
			if ( ! $this->is_valid_ip_or_range( $ip_range ) ) {
				Log::error(
					'Invalid IP range',
					array(
						'feature'  => 'ip_management',
						'action'   => 'update_ip_rule',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Invalid IP range';
				$results['success']             = false;
				continue;
			}

			$existing_rule = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'ip_range_block',
					$ip_range
				)
			);

			if ( ! $existing_rule ) {
				Log::info(
					'IP rule does not exist',
					array(
						'feature'  => 'ip_management',
						'action'   => 'update_ip_rule',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Does not exist';
				$results['success']             = false;
				continue;
			}

			$existing_data = json_decode( $existing_rule->value, true );

			// Determine country code
			if ( ! empty( $existing_data['country_code'] ) ) {
				// Use existing country code if available
				$country_code = $existing_data['country_code'];
			} else {
				// Need to lookup country - check if it's a range or single IP
				$is_range = strpos( $ip_range, '/' ) !== false;

				if ( $is_range ) {
					// For CIDR ranges, extract the subnet for country lookup
					list($subnet, $mask) = explode( '/', $ip_range );
					$country_code        = $geoip->get_country( $subnet );
				} else {
					// For single IPs, normal lookup
					$country_code = $geoip->get_country( $ip_range );
				}
			}

			$data = array(
				'ip_range'         => $ip_range,
				'country_code'     => $country_code,
				'block_type'       => $block_type,
				'block_duration'   => absint( $block_duration ),
				'block_start_time' => current_time( 'mysql' ),
				'scope'            => $scope,
				'reason'           => $reason,
				'block_page'       => $block_page,
			);

			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'value'         => wp_json_encode( $data ),
					'type_state'    => $type_state,
					'is_active'     => $is_active,
					'date_modified' => current_time( 'mysql' ),
				),
				array(
					'key'    => 'ip_range_block',
					'option' => $ip_range,
				),
				array( '%s', '%s', '%d', '%s' ),
				array( '%s', '%s' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to update IP rule',
					array(
						'feature'  => 'ip_management',
						'action'   => 'update_ip_rule',
						'ip_range' => $ip_range,
					)
				);
				$results['failed'][ $ip_range ] = 'Database error';
				$results['success']             = false;
				continue;
			}

			$results['updated'][] = $ip_range;
		}

		if ( $results['success'] && ! empty( $results['failed'] ) ) {
			$results['success'] = false; // Partial failure
		}

		if ( $results['success'] || empty( $results['failed'] ) ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		// Always refresh both cache variants (harmless after a ROLLBACK — the rebuild
		// just re-reads the unchanged rows; required so the _all admin-list variant
		// never goes stale).
		delete_transient( 'tailwatch_login_defender_ip_rules' );
		delete_transient( 'tailwatch_login_defender_ip_rules_all' );

		return $results;
	}

	public function get_active_rules( $type, $limit = null, $page = null, $only_active = true ) {
		global $wpdb;
		$cache_key = 'tailwatch_login_defender_' . ( $type === 'ip' ? 'ip_rules' : 'country_rules' ) . ( $only_active ? '' : '_all' );

		if ( $limit === null && $page === null ) {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$key        = $type === 'ip' ? 'ip_range_block' : 'country_block';

		// Each branch passes a static SQL literal to prepare() so WPCS can
		// statically verify the placeholder count. Ternary string-concat into
		// prepare() defeats that check.
		if ( $only_active ) {
			$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `key` = %s AND is_active = %d', $table_name, $key, 1 );
		} else {
			$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE `key` = %s', $table_name, $key );
		}

		if ( $limit !== null && $page !== null ) {
			$limit  = absint( $limit );
			$page   = absint( $page );
			$offset = ( $page - 1 ) * $limit;
			if ( $only_active ) {
				$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE `key` = %s AND is_active = %d ORDER BY id DESC LIMIT %d OFFSET %d', $table_name, $key, 1, $limit, $offset );
			} else {
				$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE `key` = %s ORDER BY id DESC LIMIT %d OFFSET %d', $table_name, $key, $limit, $offset );
			}
		} elseif ( $only_active ) {
			$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE `key` = %s AND is_active = %d ORDER BY id DESC', $table_name, $key, 1 );
		} else {
			$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE `key` = %s ORDER BY id DESC', $table_name, $key );
		}

		$rules = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $limit !== null && $page !== null ) {
			$total  = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = array(
				'data'        => ! empty( $rules ) ? $rules : array(),
				'total'       => (int) $total,
				'page'        => (int) $page,
				'limit'       => (int) $limit,
				'total_pages' => $total > 0 ? ceil( $total / $limit ) : 0,
			);
		} else {
			$result = ! empty( $rules ) ? $rules : array();
			set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		}

		return $result;
	}

	public function is_valid_ip_or_range( $ip_range ) {
		if ( filter_var( $ip_range, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( preg_match( '/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip_range ) ) {
			list($ip, $mask) = explode( '/', $ip_range );
			return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && $mask >= 0 && $mask <= 32;
		}
		// IPv6 CIDR — kept consistent with WhitelistModel and is_ip_in_range(),
		// which both support IPv6 ranges (previously blacklist rules silently
		// rejected every IPv6 range).
		if ( preg_match( '/^([0-9a-fA-F:]+)\/\d{1,3}$/', $ip_range ) ) {
			list($ip, $mask) = explode( '/', $ip_range );
			return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && $mask >= 0 && $mask <= 128;
		}
		return false;
	}

	public function unblock_ip_range( $ip_range, $geoip = null ) {
		return $this->update_ip_rule( array( $ip_range ), 'unblocked', 0, 'login_forms', 'Unblocked', $geoip );
	}

	public function delete_ip_rule( $ip_ranges, $is_delete = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$results    = array(
			'success' => true,
			'failed'  => array(),
			'deleted' => array(),
		);

		if ( $is_delete ) {
			$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array( 'key' => 'ip_range_block' ),
				array( '%s' )
			);

			if ( $result === false ) {
				Log::error(
					'Failed to delete all IP rules',
					array(
						'feature' => 'ip_management',
						'action'  => 'delete_ip_rule',
					)
				);
				$results['success']       = false;
				$results['failed']['all'] = 'Database error';
			} else {
				$results['deleted'] = array( 'all' );
			}
		} else {
			$ip_ranges = (array) $ip_ranges;
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ip_ranges as $ip_range ) {
				if ( ! $this->is_valid_ip_or_range( $ip_range ) ) {
					Log::error(
						'Invalid IP range for deletion',
						array(
							'feature'  => 'ip_management',
							'action'   => 'delete_ip_rule',
							'ip_range' => $ip_range,
						)
					);
					$results['failed'][ $ip_range ] = 'Invalid IP range';
					$results['success']             = false;
					continue;
				}

				$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'key'    => 'ip_range_block',
						'option' => $ip_range,
					),
					array( '%s', '%s' )
				);

				if ( $result === false ) {
					Log::error(
						'Failed to delete IP rule',
						array(
							'feature'  => 'ip_management',
							'action'   => 'delete_ip_rule',
							'ip_range' => $ip_range,
						)
					);
					$results['failed'][ $ip_range ] = 'Database error or does not exist';
					$results['success']             = false;
					continue;
				}

				$results['deleted'][] = $ip_range;
			}

			if ( $results['success'] || empty( $results['failed'] ) ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} else {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}

		delete_transient( 'tailwatch_login_defender_ip_rules' );
		delete_transient( 'tailwatch_login_defender_ip_rules_all' );
		return $results;
	}

	public function get_blocked_ip_ranges( $limit = null, $page = null, $geoip = null ) {
		// only_active=false: the grid shows BOTH currently-active blocks (is_active=1,
		// permanent/temporary) AND unblocked history (is_active=0), each with its
		// status. Pagination stays correct because the read below no longer skips or
		// mutates rows, so total matches what's rendered.
		$rules     = $this->get_active_rules( 'ip', $limit, $page, false );
		$ip_ranges = array();

		if ( $limit !== null && $page !== null ) {
			$rules_data = $rules['data'];
		} else {
			$rules_data = $rules;
		}

		foreach ( $rules_data as $rule ) {
			$data = json_decode( $rule->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON for IP rule',
					array(
						'feature' => 'ip_management',
						'action'  => 'get_blocked_ip_ranges',
						'option'  => $rule->option,
					)
				);
				continue;
			}
			$is_active = $rule->is_active;
			// Expired temporary block -> reset it to unblocked so the grid shows the
			// correct status. The row is NOT skipped (it stays in the list with its
			// corrected status); the old `continue` skip is what broke pagination.
			// SAFETY INVARIANT (do not change without re-testing pagination): this
			// write-on-read is safe ONLY because get_active_rules() above uses
			// only_active=false (the row stays in the result set after unblocking) and
			// ORDER BY id (immutable, so the write can't shift rows between pages).
			if ( $data['block_type'] === 'temporary' && $data['block_duration'] > 0 && strtotime( $data['block_start_time'] ) + $data['block_duration'] < strtotime( current_time( 'mysql' ) ) ) {
				$this->unblock_ip_range( $data['ip_range'], $geoip );
				$data['block_type'] = 'unblocked';
				$is_active          = 0;
			}

			// Get country code - if missing, lookup with range support
			$country_code = $data['country_code'] ?? null;
			if ( ! $country_code && $geoip ) {
				$is_range = strpos( $data['ip_range'], '/' ) !== false;
				if ( $is_range ) {
					list($subnet, $mask) = explode( '/', $data['ip_range'] );
					$country_code        = $geoip->get_country( $subnet );
				} else {
					$country_code = $geoip->get_country( $data['ip_range'] );
				}
			}

			// Resolve the custom block page (a post ID) to its post type so the edit
			// form can pre-select the post-type dropdown; the page is then auto-selected
			// by block_page (the post ID) from that type's posts.
			$block_page           = $data['block_page'] ?? null;
			$block_page_post_type = null;
			if ( $block_page && is_numeric( $block_page ) ) {
				$bp_post = get_post( (int) $block_page );
				if ( $bp_post ) {
					$block_page_post_type = $bp_post->post_type;
				}
			}

			$ip_ranges[] = array(
				'ip_range'             => $data['ip_range'],
				'country_code'         => $country_code ?? 'Unknown',
				'block_type'           => $data['block_type'],
				'block_duration'       => $data['block_duration'],
				'block_start_time'     => $data['block_start_time'],
				'time_remaining'       => TimeService::format_block_time_remaining( $data['block_type'], $data['block_start_time'], $data['block_duration'] ),
				'scope'                => $data['scope'],
				'reason'               => $data['reason'],
				'block_page'           => $block_page,
				'block_page_post_type' => $block_page_post_type,
				'is_active'            => $is_active,
			);
		}

		if ( $limit !== null && $page !== null ) {
			return array(
				'data'        => $ip_ranges,
				'total'       => $rules['total'],
				'page'        => $rules['page'],
				'limit'       => $rules['limit'],
				'total_pages' => $rules['total_pages'],
			);
		}

		return $ip_ranges;
	}

	public function count_blocked_ip_ranges( $block_type = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$sql        = $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `key` = 'ip_range_block' AND is_active = 1", $table_name );
		if ( $block_type ) {
			$sql .= $wpdb->prepare( " AND JSON_UNQUOTE(JSON_EXTRACT(value, '$.block_type')) = %s", $block_type );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function count_blocked_countries( $block_type = null ) {
		global $wpdb;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$sql        = $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE `key` = 'country_block' AND is_active = 1", $table_name );
		if ( $block_type ) {
			$sql .= $wpdb->prepare( " AND JSON_UNQUOTE(JSON_EXTRACT(value, '$.block_type')) = %s", $block_type );
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
