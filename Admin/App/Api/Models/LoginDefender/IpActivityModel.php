<?php
namespace Tailwatch\Admin\App\Api\Models\LoginDefender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;
use Tailwatch\Admin\App\Api\Services\Time\TimeService;
use DateTime;

class IpActivityModel {
	private $geoip;
	private $settings;
	private static $reset_in_progress = array();

	public function __construct( GeoIPService $geoip, $settings = array() ) {
		$this->geoip    = $geoip;
		$this->settings = $settings;
	}

	public function log_attempt( $ip, $attempt_type, $username = '' ) {
		if ( ! $this->settings['ip_protection_enabled'] ) {
			return false;
		}

		global $wpdb;
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$activity   = $this->get_activity( $ip );

		$data              = $activity ? json_decode( $activity->value, true ) : $this->get_default_activity( $ip );
		$now               = current_time( 'mysql' );
		$current_timestamp = strtotime( $now );

		// --- NEW LOGIC FOR EXPIRED TEMPORARY BLOCKS ---
		// If the IP was temporarily blocked and the block has expired,
		// reset attempt counters but RETAIN temp_block_count and escalation window status.
		if ( isset( $data['lock_type'] ) && $data['lock_type'] === 'temporary' && strtotime( $data['lock_time'] ) + $data['lock_duration'] < $current_timestamp ) {
			$data['lock_type']             = 'none';
			$data['lock_time']             = '';
			$data['lock_duration']         = 0;
			$data['attempt_count']         = 0; // Reset attempt count for new series of attempts
			$data['rate_limit_violations'] = 0;
			$data['remaining_attempts']    = $this->settings['failed_attempt_threshold'] ?? 3;
			$data['attempt_timestamps']    = array();
			// Crucially, we DO NOT reset temp_block_count or escalation_window_start here.
			// That's handled by reset_escalation_window_if_expired based on escalation_window_duration.
		}
		// --- END NEW LOGIC ---

		// Skip logging if currently blocked (permanent or still active temporary)
		if ( isset( $data['lock_type'] ) && $data['lock_type'] !== 'none' ) {
			if ( $data['lock_type'] === 'temporary' ) {
				return false;
			} elseif ( $data['lock_type'] === 'permanent' ) {
				return false;
			}
		}

		// Check if the escalation window itself has expired and reset temp_block_count if it has.
		// This must happen before logging a new attempt for escalation calculation.
		$this->reset_escalation_window_if_expired( $ip, $data, $current_timestamp );

		$escalation_window_duration = $this->settings['escalation_window'] ?? 86400; // Default 1 day
		if ( $data['attempt_count'] == 0 && empty( $data['escalation_window_start'] ) ) {
			$data['escalation_window_start']    = $now;
			$data['escalation_window_duration'] = $escalation_window_duration;
		}

		$min_submission_time = $this->settings['min_submission_time'] ?? 20;
		$last_attempt        = isset( $data['attempt_time'] ) ? new DateTime( $data['attempt_time'] ) : null;
		// Capture the PREVIOUS attempt timestamp before $data['attempt_time'] is
		// overwritten with $now below. Both the rapid-submission and the
		// rate-limit-window checks must compare against the prior attempt — the
		// window check previously re-read the already-overwritten value (always
		// ~0), so the window branch was always taken and violations never reset.
		$prev_attempt_ts     = $last_attempt ? strtotime( $data['attempt_time'] ) : null;
		$is_rapid            = $last_attempt && ( $current_timestamp - $prev_attempt_ts ) < $min_submission_time;

		$data['attempt_count']                 = ( $data['attempt_count'] ?? 0 ) + 1;
		$data['attempt_type']                  = $attempt_type;
		$data['attempt_time']                  = $now;
		$data['attempt_timestamps']            = isset( $data['attempt_timestamps'] ) ? array_slice( $data['attempt_timestamps'], -99 ) : array();
		$data['attempt_timestamps'][]          = $now;
		$data['country_code']                  = $this->geoip->get_country( $ip );
		$data['escalation_threshold_attempts'] = $this->settings['escalation_threshold'];
		$data['failed_attempt_threshold']      = $this->settings['failed_attempt_threshold'];
		$data['rate_limit_attempts']           = $this->settings['rate_limit_attempts'];

		// Record which login the visitor typed and whether it maps to a real
		// account — lets the admin tell a targeted attack on an existing user
		// from blind username spraying.
		$identity                        = $this->resolve_attempt_identity( $username );
		$data['last_attempted_username'] = $identity['username'];
		$data['last_user_exists']        = $identity['user_exists'];

		$threshold                  = $this->settings['failed_attempt_threshold'] ?? 3;
		$data['remaining_attempts'] = max( 0, $threshold - $data['attempt_count'] );

		$rate_limit_attempts = $this->settings['rate_limit_attempts'] ?? 3;
		$rate_limit_window   = $this->settings['rate_limit_window'] ?? 3600;
		if ( $last_attempt && ( $current_timestamp - $prev_attempt_ts ) < $rate_limit_window ) {
			$data['rate_limit_violations'] = ( $data['rate_limit_violations'] ?? 0 ) + ( $is_rapid ? 1 : 0 );
			$data['last_rate_limit_time']  = $now;
		} else {
			$data['rate_limit_violations'] = $is_rapid ? 1 : 0;
			$data['last_rate_limit_time']  = $is_rapid ? $now : '';
		}

		$escalation_threshold = $this->settings['escalation_threshold'] ?? 3;
		$temp_block_duration  = $this->settings['temp_block_duration'] ?? 200;
		$temp_block_count     = $data['temp_block_count']; // Use the value from current data after potential expiry check
		$block_type           = $this->settings['block_type'];

		$block_reason = '';
		if ( $data['attempt_count'] >= $threshold ) {
			$block_reason = "Exceeded failed attempt threshold ($threshold attempts)";
		} elseif ( $data['rate_limit_violations'] >= $rate_limit_attempts ) {
			$block_reason = "Exceeded rate limit violations ($rate_limit_attempts in $rate_limit_window seconds)";
		}

		if ( $block_reason ) {
			$block_duration = $block_type === 'temporary' ? $temp_block_duration * ( 1 + floor( $temp_block_count / 2 ) ) : 0;

			// $data['temp_block_count'] = $temp_block_count + 1; // Increment ONLY on new block
			$data['lock_time']     = $now;
			$data['lock_duration'] = $block_duration;

			if ( $block_type === 'permanent' ) {
				$data['lock_type']                  = 'permanent';
				$data['escalation_window_start']    = '';
				$data['escalation_window_duration'] = 0;
				$data['temp_block_count']           = 0;
			} else {

				if ( ! isset( $data['lock_type'] ) || $data['lock_type'] === 'none' ) {
					$data['temp_block_count'] = ( $data['temp_block_count'] ?? 0 ) + 1;
				} else {
				}

				// Ensure escalation window start is set if not already
				if ( empty( $data['escalation_window_start'] ) ) {
					$data['escalation_window_start']    = $now;
					$data['escalation_window_duration'] = $escalation_window_duration;
				}

				if ( $data['temp_block_count'] >= $escalation_threshold ) {
					$data['lock_type']                  = 'permanent';
					$data['lock_duration']              = 0; // Permanent block has no duration
					$data['escalation_window_start']    = ''; // Reset escalation window for permanent blocks
					$data['escalation_window_duration'] = 0;
				} else {
					$data['lock_type'] = 'temporary';
				}
			}

			$this->log_history_event(
				$ip,
				'block',
				array(
					'lock_type'        => $data['lock_type'],
					'lock_duration'    => $data['lock_duration'],
					'lock_time'        => $now,
					'reason'               => $block_reason,
					'temp_block_count'     => $data['temp_block_count'],
					'escalation_threshold' => $escalation_threshold,
					'escalation_remaining' => max( 0, $escalation_threshold - (int) $data['temp_block_count'] ),
					'username'             => $identity['username'],
					'user_exists'          => $identity['user_exists'],
					'user_id'              => $identity['user_id'],
				)
			);
		}

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT id FROM %i WHERE `key` = %s AND `option` = %s',
				$table_name,
				'ip_activity',
				$ip
			)
		);

		if ( $existing ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'value'         => wp_json_encode( $data ),
					'type_state'    => $data['lock_type'] ?? 'none',
					'date_modified' => $now,
					'is_active'     => 1,
				),
				array(
					'key'    => 'ip_activity',
					'option' => $ip,
				),
				array( '%s', '%s', '%s', '%d' ),
				array( '%s', '%s' )
			);
		} else {
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'key'           => 'ip_activity',
					'option'        => $ip,
					'value'         => wp_json_encode( $data ),
					'type'          => 'ip_activity',
					'type_state'    => $data['lock_type'] ?? 'none',
					'date_created'  => $activity ? $activity->date_created : $now,
					'date_modified' => $now,
					'is_active'     => 1,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		if ( $result === false ) {
			return false;
		}

		$this->log_history_event(
			$ip,
			'attempt',
			array(
				'attempt_type'       => $attempt_type,
				'attempt_count'      => $data['attempt_count'],
				'remaining_attempts' => $data['remaining_attempts'],
				'username'           => $identity['username'],
				'user_exists'        => $identity['user_exists'],
				'user_id'            => $identity['user_id'],
			)
		);

		$cache_key = 'login_defender_ip_activity_' . md5( $ip );
		set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		return array(
			'is_blocked'           => isset( $data['lock_type'] ) && $data['lock_type'] !== 'none',
			'lock_type'            => $data['lock_type'] ?? 'none',
			'lock_duration'        => (int) ( $data['lock_duration'] ?? 0 ),
			'temp_block_count'     => (int) ( $data['temp_block_count'] ?? 0 ),
			'escalation_threshold' => (int) ( $this->settings['escalation_threshold'] ?? 3 ),
			'remaining_attempts'   => $data['remaining_attempts'],
		);
	}

	private function reset_escalation_window_if_expired( $ip, &$data, $current_timestamp ) {
		if ( ! $this->settings['ip_protection_enabled'] ) {
			return false;
		}

		if ( isset( $data['escalation_window_start'] ) && $data['escalation_window_start'] && $data['escalation_window_duration'] > 0 && strtotime( $data['escalation_window_start'] ) + $data['escalation_window_duration'] < $current_timestamp ) {
			$this->log_history_event(
				$ip,
				'reset_escalation',
				array(
					'previous_temp_block_count' => $data['temp_block_count'] ?? 0,
					'reason'                    => 'Escalation window expired',
				)
			);
			$data['temp_block_count']           = 0;
			$data['escalation_window_start']    = '';
			$data['escalation_window_duration'] = 0;
			// Also reset immediate attempt counters if the escalation window reset happens outside a block
			$data['attempt_count']         = 0;
			$data['rate_limit_violations'] = 0;
			$data['lock_type']             = 'none';
			$data['lock_time']             = '';
			$data['lock_duration']         = 0;
			$data['remaining_attempts']    = $this->settings['failed_attempt_threshold'] ?? 3;
			$data['attempt_timestamps']    = array();

			global $wpdb;
			$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
			$existing   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'ip_activity',
					$ip
				)
			);

			if ( $existing ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'value'         => wp_json_encode( $data ),
						'type_state'    => $data['lock_type'] ?? 'none',
						'date_modified' => current_time( 'mysql' ),
						'is_active'     => 1,
					),
					array(
						'key'    => 'ip_activity',
						'option' => $ip,
					),
					array( '%s', '%s', '%s', '%d' ),
					array( '%s', '%s' )
				);
			} else {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'key'           => 'ip_activity',
						'option'        => $ip,
						'value'         => wp_json_encode( $data ),
						'type'          => 'ip_activity',
						'type_state'    => $data['lock_type'] ?? 'none',
						'date_created'  => $data['date_created'] ?? current_time( 'mysql' ),
						'date_modified' => current_time( 'mysql' ),
						'is_active'     => 1,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
				);
			}
			$cache_key = 'login_defender_ip_activity_' . md5( $ip );
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		}
	}

	public function get_activity( $ip ) {
		if ( ! $this->settings['ip_protection_enabled'] ) {
			return null;
		}

		global $wpdb;

		if ( isset( self::$reset_in_progress[ $ip ] ) ) {
			return null;
		}

		$cache_key         = 'login_defender_ip_activity_' . md5( $ip );
		$cached            = get_transient( $cache_key );
		$current_timestamp = strtotime( current_time( 'mysql' ) );

		if ( $cached !== false ) {
			// Check if temporary block expired and update cache/DB accordingly
			if ( isset( $cached['lock_type'] ) && $cached['lock_type'] === 'temporary' && strtotime( $cached['lock_time'] ) + $cached['lock_duration'] < $current_timestamp ) {
				// We're not doing a full reset here, just updating block state if expired.
				// temp_block_count and escalation_window_start will be handled by reset_escalation_window_if_expired.
				$cached['lock_type']             = 'none';
				$cached['lock_time']             = '';
				$cached['lock_duration']         = 0;
				$cached['attempt_count']         = 0; // Reset immediate attempts
				$cached['rate_limit_violations'] = 0;
				$cached['remaining_attempts']    = $this->settings['failed_attempt_threshold'] ?? 3;
				$cached['attempt_timestamps']    = array();
				// Update DB and cache
				$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
				$existing   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'SELECT id FROM %i WHERE `key` = %s AND `option` = %s',
						$table_name,
						'ip_activity',
						$ip
					)
				);

				if ( $existing ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$table_name,
						array(
							'value'         => wp_json_encode( $cached ),
							'type_state'    => 'none',
							'date_modified' => current_time( 'mysql' ),
							'is_active'     => 1,
						),
						array(
							'key'    => 'ip_activity',
							'option' => $ip,
						),
						array( '%s', '%s', '%s', '%d' ),
						array( '%s', '%s' )
					);
				} else {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$table_name,
						array(
							'key'           => 'ip_activity',
							'option'        => $ip,
							'value'         => wp_json_encode( $cached ),
							'type'          => 'ip_activity',
							'type_state'    => 'none',
							'date_created'  => $cached['date_created'] ?? current_time( 'mysql' ),
							'date_modified' => current_time( 'mysql' ),
							'is_active'     => 1,
						),
						array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
					);
				}
				set_transient( $cache_key, $cached, HOUR_IN_SECONDS );
			}
			// Always check escalation window expiration
			$this->reset_escalation_window_if_expired( $ip, $cached, $current_timestamp );
			return (object) array(
				'value'        => wp_json_encode( $cached ),
				'date_created' => $cached['date_created'] ?? current_time( 'mysql' ),
			);
		}

		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$activity   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND is_active = %d',
				$table_name,
				'ip_activity',
				$ip,
				1
			)
		);

		if ( $activity ) {
			$data = json_decode( $activity->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return null;
			}
			$threshold                  = $this->settings['failed_attempt_threshold'] ?? 3;
			$data['remaining_attempts'] = max( 0, $threshold - ( $data['attempt_count'] ?? 0 ) );

			// Check if temporary block expired and update DB/cache if from DB
			if ( isset( $data['lock_type'] ) && $data['lock_type'] === 'temporary' && strtotime( $data['lock_time'] ) + $data['lock_duration'] < $current_timestamp ) {
				$data['lock_type']             = 'none';
				$data['lock_time']             = '';
				$data['lock_duration']         = 0;
				$data['attempt_count']         = 0;
				$data['rate_limit_violations'] = 0;
				$data['remaining_attempts']    = $this->settings['failed_attempt_threshold'] ?? 3;
				$data['attempt_timestamps']    = array();
				// Update DB
				$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'SELECT id FROM %i WHERE `key` = %s AND `option` = %s',
						$table_name,
						'ip_activity',
						$ip
					)
				);

				if ( $existing ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$table_name,
						array(
							'value'         => wp_json_encode( $data ),
							'type_state'    => 'none',
							'date_modified' => current_time( 'mysql' ),
							'is_active'     => 1,
						),
						array(
							'key'    => 'ip_activity',
							'option' => $ip,
						),
						array( '%s', '%s', '%s', '%d' ),
						array( '%s', '%s' )
					);
				} else {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$table_name,
						array(
							'key'           => 'ip_activity',
							'option'        => $ip,
							'value'         => wp_json_encode( $data ),
							'type'          => 'ip_activity',
							'type_state'    => 'none',
							'date_created'  => $activity->date_created,
							'date_modified' => current_time( 'mysql' ),
							'is_active'     => 1,
						),
						array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
					);
				}
				$activity->value = wp_json_encode( $data ); // Update activity object for consistency
			}
			// Always check escalation window expiration
			$this->reset_escalation_window_if_expired( $ip, $data, $current_timestamp );
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		}

		return $activity;
	}

	public function reset_attempts( $ip, $full_reset = true, $username = '' ) {
		return $this->reset_attempts_blocked( $ip, $full_reset, $username );
	}

	private function reset_attempts_blocked( $ip, $full_reset = true, $username = '' ) {
		global $wpdb;

		if ( isset( self::$reset_in_progress[ $ip ] ) ) {
			return;
		}

		self::$reset_in_progress[ $ip ] = true;

		try {
			$cache_key = 'login_defender_ip_activity_' . md5( $ip );
			delete_transient( $cache_key );

			$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
			$activity   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT * FROM %i WHERE `key` = %s AND `option` = %s AND is_active = %d',
					$table_name,
					'ip_activity',
					$ip,
					1
				)
			);

			if ( ! $activity ) {
				return;
			}

			$data = json_decode( $activity->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return;
			}
			$event_type = $full_reset ? 'reset' : 'successful_login';
			$identity   = $this->resolve_attempt_identity( $username );
			$this->log_history_event(
				$ip,
				$event_type,
				array(
					'previous_lock_type'      => $data['lock_type'] ?? 'none',
					'previous_attempt_count'  => $data['attempt_count'] ?? 0,
					'temp_block_count'        => $data['temp_block_count'] ?? 0,
					'escalation_window_start' => $data['escalation_window_start'] ?? 'N/A',
					'username'                => $identity['username'],
					'user_exists'             => $identity['user_exists'],
					'user_id'                 => $identity['user_id'],
				)
			);

			$data['attempt_count']         = 0;
			$data['rate_limit_violations'] = 0;
			$data['lock_type']             = 'none';
			$data['lock_time']             = '';
			$data['lock_duration']         = 0;
			$data['remaining_attempts']    = $this->settings['failed_attempt_threshold'] ?? 3;
			$data['attempt_timestamps']    = array();

			// Only reset escalation-related fields if full_reset is true
			// This is the core part that preserves temp_block_count on successful login.
			if ( $full_reset ) {
				$data['temp_block_count']           = 0;
				$data['escalation_window_start']    = '';
				$data['escalation_window_duration'] = 0;
			} else {
			}

			$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id FROM %i WHERE `key` = %s AND `option` = %s',
					$table_name,
					'ip_activity',
					$ip
				)
			);

			if ( $existing ) {
				$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'value'         => wp_json_encode( $data ),
						'type_state'    => 'none', // Set to none as attempts are reset
						'date_modified' => current_time( 'mysql' ),
						'is_active'     => 1,
					),
					array(
						'key'    => 'ip_activity',
						'option' => $ip,
					),
					array( '%s', '%s', '%s', '%d' ),
					array( '%s', '%s' )
				);
			} else {
				$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'key'           => 'ip_activity',
						'option'        => $ip,
						'value'         => wp_json_encode( $data ),
						'type'          => 'ip_activity',
						'type_state'    => 'none', // Set to none as attempts are reset
						'date_created'  => $activity->date_created,
						'date_modified' => current_time( 'mysql' ),
						'is_active'     => 1,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
				);
			}

			// Update cache after reset
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		} finally {
			unset( self::$reset_in_progress[ $ip ] );
		}
	}

	public function unblock_ip( $ip ) {
		global $wpdb;
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// A manual unblock should always be a full reset, clearing everything
		$this->reset_attempts_blocked( $ip, true );
		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$result     = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'is_active'     => 1,
				'type_state'    => 'unblocked',
				'date_modified' => current_time( 'mysql' ),
			),
			array(
				'key'    => 'ip_activity',
				'option' => $ip,
			),
			array( '%d', '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( $result === false ) {
			return false;
		}

		$cache_key = 'login_defender_ip_activity_' . md5( $ip );
		delete_transient( $cache_key );
		return true;
	}

	public function get_all_ip_activities( $limit = null, $page = null ) {
		global $wpdb;

		$table_name        = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$current_timestamp = strtotime( current_time( 'mysql' ) );

		// Query using ROW_NUMBER to get the latest record per IP
		$sql = $wpdb->prepare(
			'SELECT * FROM (
                SELECT t.*,
                        ROW_NUMBER() OVER (PARTITION BY t.`option` ORDER BY t.date_modified DESC, t.id DESC) as rn
                FROM %i t
                WHERE t.`key` = %s AND t.is_active = %d
            ) ranked
            WHERE ranked.rn = 1
            ORDER BY ranked.date_modified DESC',
			$table_name,
			'ip_activity',
			1
		);

		// Count query for total unique IPs
		$count_sql = $wpdb->prepare(
			'SELECT COUNT(DISTINCT `option`) FROM %i WHERE `key` = %s AND is_active = %d',
			$table_name,
			'ip_activity',
			1
		);

		// Apply pagination if limit and page are provided
		if ( $limit !== null && $page !== null ) {
			$limit  = absint( $limit );
			$page   = absint( $page );
			$offset = ( $page - 1 ) * $limit;
			$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$activities = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result     = array();

		foreach ( $activities as $activity ) {
			$data = json_decode( $activity->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				continue;
			}

			$ip = $activity->option;

			// Re-evaluate block status based on current time
			$is_blocked = false;
			$lock_type  = $data['lock_type'] ?? 'none';
			if ( $lock_type === 'permanent' ) {
				$is_blocked = true;
			} elseif ( $lock_type === 'temporary' ) {
				$expiration = strtotime( $data['lock_time'] ) + $data['lock_duration'];
				if ( $expiration >= $current_timestamp ) {
					$is_blocked = true;
				} else {
					// If temporary block expired, ensure it's reflected here and potentially cleaned up
					$data['lock_type']     = 'none';
					$data['lock_time']     = '';
					$data['lock_duration'] = 0;
					// Note: temp_block_count and escalation_window_start remain until escalation_window expires
				}
			}

			// Prepare data for frontend
			$result[] = array(
				'ip'                         => $ip,
				'block_status'               => $is_blocked ? ( $data['lock_type'] === 'temporary' ? 'Temporarily Blocked' : 'Permanently Blocked' ) : 'Not Blocked',
				'lock_type'                  => $data['lock_type'] ?? 'none',
				'lock_time'                  => $data['lock_time'] ?? '',
				'lock_duration'              => $data['lock_duration'] ?? 0,
				'time_remaining'             => TimeService::format_block_time_remaining( $data['lock_type'] ?? 'none', $data['lock_time'] ?? '', $data['lock_duration'] ?? 0 ),
				'temp_block_count'           => $data['temp_block_count'] ?? 0,
				'escalation_window_start'    => $data['escalation_window_start'] ?? '',
				'escalation_window_duration' => $data['escalation_window_duration'] ?? 0,
				'reason'                     => $data['reason'] ?? ( $is_blocked ? 'Login Defender or rate limit violation' : '' ),
				'attempt_count'              => $data['attempt_count'] ?? 0,
				'remaining_attempts'         => $data['remaining_attempts'] ?? ( $this->settings['failed_attempt_threshold'] ?? 3 ),
				'last_attempt_time'          => $data['attempt_time'] ?? '',
				'country_code'               => $data['country_code'] ?? $this->geoip->get_country( $ip ),
			);
		}

		if ( $limit !== null && $page !== null ) {
			$total = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'activities' => $result,
				'pagination' => array(
					'total'       => (int) $total,
					'page'        => (int) $page,
					'limit'       => (int) $limit,
					'total_pages' => $total > 0 ? ceil( $total / $limit ) : 0,
				),
			);
		}

		return array(
			'activities' => $result,
		);
	}

	/**
	 * Count distinct tracked IPs in ip_activity without materializing every row.
	 *
	 * Returns the same total as count( get_all_ip_activities()['activities'] ) — the
	 * ROW_NUMBER rn=1 partition yields exactly one row per distinct `option`, which
	 * equals COUNT(DISTINCT `option`) — but runs a single aggregate query instead of
	 * fetching + json_decode + GeoIP-resolving every row. Use this when only the total
	 * is needed (e.g. the dashboard log-count widget) so large ip_activity tables on
	 * heavily-probed sites don't build a huge array just to be counted.
	 *
	 * @return int Number of distinct active IP-activity records.
	 */
	public function get_ip_activity_count() {
		global $wpdb;

		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT `option`) FROM %i WHERE `key` = %s AND is_active = %d',
				$table_name,
				'ip_activity',
				1
			)
		);

		return (int) $count;
	}

	public function get_ip_activity_history( $ip, $limit = null, $page = null ) {
		global $wpdb;
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array();
		}

		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$sql        = $wpdb->prepare(
			'SELECT * FROM %i WHERE `key` = %s AND `option` = %s ORDER BY date_created DESC',
			$table_name,
			'ip_activity_history',
			$ip
		);

		$count_sql = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `option` = %s',
			$table_name,
			'ip_activity_history',
			$ip
		);

		if ( $limit !== null && $page !== null ) {
			$limit  = absint( $limit );
			$page   = absint( $page );
			$offset = ( $page - 1 ) * $limit;
			$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		$history = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result  = array();

		foreach ( $history as $entry ) {
			$data = json_decode( $entry->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				continue;
			}
			$result[] = array(
				'event_type' => $data['event_type'],
				'timestamp'  => $entry->date_created,
				'details'    => $data['details'],
			);
		}

		$current_activity = $this->get_activity( $ip );
		$current_data     = $current_activity ? json_decode( $current_activity->value, true ) : $this->get_default_activity( $ip );
		if ( is_array( $current_data ) ) {
			$current_data['time_remaining'] = TimeService::format_block_time_remaining( $current_data['lock_type'] ?? 'none', $current_data['lock_time'] ?? '', $current_data['lock_duration'] ?? 0 );
		}

		if ( $limit !== null && $page !== null ) {
			$total = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'current_activity' => $current_data,
				'history'          => $result,
				'pagination'       => array(
					'total'       => (int) $total,
					'page'        => (int) $page,
					'limit'       => (int) $limit,
					'total_pages' => $total > 0 ? ceil( $total / $limit ) : 0,
				),
			);
		}

		return array(
			'current_activity' => $current_data,
			'history'          => $result,
		);
	}

	public function delete_ip_activity( $ips = array(), $delete_all = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$results    = array(
			'success' => true,
			'failed'  => array(),
			'deleted' => array(),
		);

		if ( $delete_all ) {
			$keys = array( 'ip_activity', 'ip_activity_history' );
			foreach ( $keys as $key ) {
				$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array( 'key' => $key ),
					array( '%s' )
				);
				if ( $result === false ) {
					$results['success']       = false;
					$results['failed']['all'] = "Database error for $key";
				} else {
					$results['deleted'][] = "all_$key";
				}
			}
			// Clear all relevant transients
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_login_defender_ip_activity_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_login_defender_ip_activity_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		} else {
			$ips = (array) $ips;
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ips as $ip ) {
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					$results['failed'][ $ip ] = 'Invalid IP address';
					$results['success']       = false;
					continue;
				}

				$keys = array( 'ip_activity', 'ip_activity_history' );
				foreach ( $keys as $key ) {
					$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$table_name,
						array(
							'key'    => $key,
							'option' => $ip,
						),
						array( '%s', '%s' )
					);
					if ( $result === false ) {
						$results['failed'][ $ip ] = "Database error for $key";
						$results['success']       = false;
						continue;
					}
					$results['deleted'][] = "$key:$ip";
				}

				$cache_key = 'login_defender_ip_activity_' . md5( $ip );
				delete_transient( $cache_key );
			}

			if ( $results['success'] || empty( $results['failed'] ) ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} else {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}

		return $results;
	}

	public function log_history_event( $ip, $event_type, $details ) {
		global $wpdb;
		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$now        = current_time( 'mysql' );

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_name,
			array(
				'key'           => 'ip_activity_history',
				'option'        => $ip,
				'value'         => wp_json_encode(
					array(
						'event_type' => $event_type,
						'details'    => $details,
					)
				),
				'type'          => 'ip_activity_history',
				'type_state'    => $event_type,
				'date_created'  => $now,
				'date_modified' => $now,
				'is_active'     => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Resolve the typed login to a real account. WordPress accepts either the
	 * username OR the email at the login form, so fall back to email lookup when
	 * the input looks like one (same pattern as LogActivityController / 2FA).
	 *
	 * @param string $username Raw login the visitor submitted.
	 * @return array{username:string,user_exists:bool,user_id:int,user_login:string}
	 */
	private function resolve_attempt_identity( $username ) {
		$typed = sanitize_text_field( (string) $username );
		if ( strlen( $typed ) > 100 ) {
			$typed = substr( $typed, 0, 100 );
		}

		$user = $typed ? get_user_by( 'login', $typed ) : false;
		if ( ! $user && $typed && is_email( $typed ) ) {
			$user = get_user_by( 'email', $typed );
		}

		return array(
			'username'    => $typed,
			'user_exists' => (bool) $user,
			'user_id'     => $user ? (int) $user->ID : 0,
			'user_login'  => $user ? $user->user_login : '',
		);
	}

	private function get_default_activity( $ip ) {
		$threshold = $this->settings['failed_attempt_threshold'] ?? 3;
		return array(
			'ip_address'                 => $ip,
			'country_code'               => $this->geoip->get_country( $ip ),
			'browser'                    => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown' ) ),
			'attempt_count'              => 0,
			'attempt_type'               => '',
			'attempt_time'               => '',
			'attempt_timestamps'         => array(),
			'lock_type'                  => 'none',
			'lock_time'                  => '',
			'lock_duration'              => 0,
			'temp_block_count'           => 0,
			'escalation_window_start'    => '',
			'escalation_window_duration' => 0,
			'rate_limit_violations'      => 0,
			'last_rate_limit_time'       => '',
			'proxy_detected'             => false,
			'remaining_attempts'         => $threshold,
			'last_attempted_username'    => '',
			'last_user_exists'           => false,
		);
	}
}
