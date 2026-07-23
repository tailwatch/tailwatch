<?php
/**
 * IP Protection Controller (Login Defender)
 *
 * Login Defender IP protection: blocks, rate limiting, cleanup, unblock/activity APIs.
 *
 * @package Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections
 */

namespace Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;
use Tailwatch\Admin\App\Api\Services\IpManagement\IpAccessService;
use Tailwatch\Admin\App\Api\Services\IpManagement\GetIpServices;
use Tailwatch\Admin\App\Api\Models\LoginDefender\IpActivityModel;
use Tailwatch\Admin\App\Api\Models\IpManagement\WhitelistModel;
use Tailwatch\Admin\App\Api\Models\IpManagement\RuleModel;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Services\Time\TimeService;

/**
 * IP Protection Controller
 */
class IpProtectionController extends BaseController {
	protected $wptw_feature_check_exemptions = array();

	protected function wptw_get_feature_status() {
		return $this->wptw_ip_login_defender_is_enabled();
	}

	private $ip_access_service;
	private $geoip;
	private static $failed_attempts_logged   = array();
	private static $successful_logins_logged = array();
	private $settings;

	public function __construct() {
		$this->settings = $this->login_defender_ip_protection_settings();

		$this->geoip               = new GeoIPService();
		$this->ip_access_service = new IpAccessService(
			new IpActivityModel( $this->geoip, $this->settings ),
			new WhitelistModel(),
			new RuleModel(),
			$this->geoip,
			$this->settings
		);

		// Cron scheduling and handler registration for 'wptw_login_defender_cleanup' and
		// 'wptw_login_defender_cleanup_expired' is owned by CronJobs framework jobs:
		// LoginDefenderLogsCleanupCronJob + LoginDefenderExpiredBlocksCronJob.
	}

	public function wptw_ips_login_defender_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_login_defender_management';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	public function login_defender_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_login_defender_management';
		$field_name        = 'field_13';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	public function wptw_ip_login_defender_is_enabled() {
		$feature_settings = $this->wptw_ips_login_defender_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = $feature_settings['field_13']['options']['option']['selected'] ?? false;

		if ( $selected === true ) {
			return array(
				'parent_enable'  => true,
				'feature_enable' => true,
			);
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => false,
		);
	}

	public function login_defender_ip_protection_settings() {
		$external_settings = $this->wptw_ips_login_defender_options();
		$field_13          = $external_settings['field_13'] ?? array();
		$is_enabled        = $field_13['options']['option']['selected'] ?? false;

		if ( ! $is_enabled ) {
			return array(
				'ip_protection_enabled'    => false,
				'failed_attempt_threshold' => 3,
				'rate_limit_attempts'      => 3,
				'rate_limit_window'        => 3600,
				'min_submission_time'      => 20,
				'block_type'               => 'temporary',
				'temp_block_duration'      => 200,
				'escalation_threshold'     => 3,
				'escalation_window'        => 86400,
				'error_messages'           => array(
					'ip_blocked'           => 'Your access is temporarily blocked due to repeated failed login attempts.',
					'ip_blocked_permanent' => 'Your access has been permanently blocked due to repeated failed login attempts. Please contact the site administrator.',
					'country_blocked'      => 'Access from [country] is restricted.',
					'rate_limit'           => 'Too many attempts. Try again in [minutes] minutes.',
					'retry_message'        => 'Please try again in about [time].',
				),
				'notifications'            => array(
					'email'     => get_option( 'admin_email' ),
					'dashboard' => true,
				),
				'throttling_delays'        => array( 5, 10, 20 ),
				'log_retention_days'       => 30,
			);
		}

		$sub = $field_13['sub_options'] ?? array();

		// Helper to get value from sub_option with safe access
		$get_val = function ( $field, $default = null ) use ( $sub ) {
			return $sub[ $field ]['options']['option']['value'] ?? $default;
		};

		// Block type (temporary/permanent). Collect BOTH options' sub_options, not
		// just the selected one: the temporary settings (field_19-22) live under the
		// "temporary" option and the permanent message (field_23) lives under the
		// "permanent" option. Reading only the selected option dropped field_23 in
		// temporary mode — so a temporary block that ESCALATES to permanent showed
		// the default permanent message instead of the admin's custom one.
		$block_type = 'temporary';
		$temp_sub   = array();
		$perm_sub   = array();
		if ( isset( $sub['field_18']['options'] ) && is_array( $sub['field_18']['options'] ) ) {
			foreach ( $sub['field_18']['options'] as $opt ) {
				$opt_value = $opt['value'] ?? '';
				if ( 'temporary' === $opt_value ) {
					$temp_sub = $opt['sub_options'] ?? array();
				} elseif ( 'permanent' === $opt_value ) {
					$perm_sub = $opt['sub_options'] ?? array();
				}
				if ( isset( $opt['selected'] ) && $opt['selected'] === true ) {
					$block_type = in_array( $opt_value, array( 'temporary', 'permanent' ), true ) ? $opt_value : 'temporary';
				}
			}
		}

		return array(
			'ip_protection_enabled'    => (bool) $is_enabled,
			'failed_attempt_threshold' => absint( $get_val( 'field_14', 3 ) ),
			'rate_limit_attempts'      => absint( $get_val( 'field_15', 3 ) ),
			'rate_limit_window'        => absint( $get_val( 'field_16', 3600 ) ),
			'min_submission_time'      => absint( $get_val( 'field_17', 20 ) ),
			'block_type'               => $block_type,
			'temp_block_duration'      => absint( $temp_sub['field_19']['options']['option']['value'] ?? 200 ),
			'escalation_threshold'     => absint( $temp_sub['field_20']['options']['option']['value'] ?? 3 ),
			'escalation_window'        => absint( $temp_sub['field_21']['options']['option']['value'] ?? 86400 ),
			'error_messages'           => array(
				'ip_blocked'           => sanitize_text_field( $temp_sub['field_22']['options']['option']['value'] ?? 'Your access is temporarily blocked due to repeated failed login attempts.' ),
				'ip_blocked_permanent' => sanitize_text_field( $perm_sub['field_23']['options']['option']['value'] ?? 'Your access has been permanently blocked due to repeated failed login attempts. Please contact the site administrator.' ),
				'country_blocked'      => 'Access from [country] is restricted.',
				'rate_limit'           => 'Too many attempts. Try again in [minutes] minutes.',
				'retry_message'        => 'Please try again in about [time].',
			),
			'notifications'            => array(
				'email'     => get_option( 'admin_email' ),
				'dashboard' => true,
			),
			'throttling_delays'        => array( 5, 10, 20 ),
			'log_retention_days'       => 30,
		);
	}

	public function handle_failed_login( $username, $error ) {
		if ( ! $this->settings['ip_protection_enabled'] ) {
			return;
		}

		$ip = GetIpServices::wptw_get_client_ip();

		// This is for fix stop consider failed attempt on page reload
		$method              = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$request_uri         = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$reauth              = isset( $_GET['reauth'] ) && $_GET['reauth'] == 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$is_login_form       = strpos( $request_uri, 'wp-login.php' ) !== false;
		$has_form_submission = ( $method === 'POST' ) && ( isset( $_POST['log'] ) || isset( $_POST['pwd'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		if ( $method !== 'POST' || ! $is_login_form || $reauth || ! $has_form_submission ) {
			return;
		}

		if ( $this->is_logout_request() || $this->has_logged_attempt( $ip ) ) {
			return;
		}

		$ip_blocked = $this->is_blocked( $ip );

		if ( $ip_blocked['allowed'] === false ) {
			if ( $ip_blocked['method'] === 'ip_management' ) {
				$message = ! empty( $ip_blocked['block_reason'] ) ? $ip_blocked['block_reason'] : 'Your access to this site is blocked.';
			} else {
				$message = $this->get_block_message();
			}
			set_transient( 'login_defender_error_' . md5( $ip ), $message, 30 );
			return;
		}

		// Count the attempt when IP is allowed via ip_activity check OR when IP management
		// features are disabled (method='none'). Previously method='none' silently dropped attempts.
		if ( in_array( $ip_blocked['method'], array( 'ip_activity', 'none' ), true ) ) {
			$result = $this->log_failed_attempt( $ip, $username );
			if ( $result && ! $result['is_blocked'] ) {
				$remaining = $result['remaining_attempts'];
				$message   = sprintf( 'Login failed. You have %d attempt(s) left before your IP is temporarily blocked.', $remaining );
				set_transient( 'login_defender_error_' . md5( $ip ), $message, 30 );
			} elseif ( $result && $result['is_blocked'] ) {
				// The block was created on THIS attempt (the threshold just crossed).
				// Fire the push here — once — instead of on every later blocked
				// attempt, which early-returns above before reaching this point.
				$this->notify_ip_blocked( $ip, $result );
			}
		}
	}

	/**
	 * Log the block-created event once, which drives the IP-blocked push
	 * (action 'authentication_blocked_ip', gated by login_defender_push_notification).
	 *
	 * @param string $ip        The blocked IP.
	 * @param string $lock_type 'temporary' or 'permanent'.
	 */
	private function notify_ip_blocked( $ip, $block ) {
		$lock_type    = is_array( $block ) ? ( $block['lock_type'] ?? 'temporary' ) : (string) $block;
		$is_permanent = 'permanent' === $lock_type;

		$meta = array(
			'feature'    => 'Login Defender',
			'event'      => 'IP Blocked',
			'impact'     => '',
			'IP address' => $ip,
			'block_ip'   => $ip,
			'block_type' => $is_permanent ? 'Permanent' : 'Temporary',
		);

		if ( $is_permanent ) {
			$message        = sprintf(
				'We blocked the IP address %s after too many failed login attempts. It can no longer try to sign in until you remove the block.',
				$ip
			);
			$meta['impact'] = 'This protects your site from repeated login attempts. The IP stays blocked until you unblock it.';
		} else {
			$duration_text = $this->format_duration_friendly( (int) ( $block['lock_duration'] ?? 0 ) );
			$threshold     = (int) ( $block['escalation_threshold'] ?? 0 );
			$count         = (int) ( $block['temp_block_count'] ?? 0 );
			$remaining     = max( 0, $threshold - $count );

			$message = sprintf(
				'We temporarily blocked the IP address %s for %s after too many failed login attempts. It can try again automatically once the block ends.',
				$ip,
				$duration_text
			);
			if ( $remaining > 0 ) {
				$message .= sprintf(
					' If it keeps failing, about %d more block%s may lead to a permanent ban.',
					$remaining,
					1 === $remaining ? '' : 's'
				);
			}

			$meta['impact']            = 'This protects your site from repeated login attempts. The block lifts on its own once it ends.';
			$meta['block_duration']    = $duration_text;
			$meta['blocks_before_ban'] = $remaining;
		}

		Log::error(
			$message,
			array(
				'feature'   => 'login_defender',
				'action'    => 'authentication_blocked_ip',
				'title'     => 'IP address blocked',
				'meta_data' => $meta,
			)
		);
	}

	/**
	 * Human-friendly duration, e.g. 600 -> "10 minutes", 3900 -> "1 hour 5 minutes".
	 *
	 * @param int $seconds Block duration in seconds.
	 * @return string
	 */
	private function format_duration_friendly( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return 'a short while';
		}
		$units = array(
			'day'    => 86400,
			'hour'   => 3600,
			'minute' => 60,
		);
		$parts = array();
		foreach ( $units as $label => $size ) {
			if ( $seconds >= $size ) {
				$count    = intdiv( $seconds, $size );
				$seconds %= $size;
				$parts[]  = $count . ' ' . $label . ( $count > 1 ? 's' : '' );
			}
		}
		if ( empty( $parts ) ) {
			$parts[] = $seconds . ' second' . ( 1 === $seconds ? '' : 's' );
		}
		return implode( ' ', $parts );
	}

	public function handle_successful_login( $user_login, $user ) {
		if ( ! $this->settings['ip_protection_enabled'] ) {
			return;
		}

		$ip = GetIpServices::wptw_get_client_ip();

		if ( $this->has_logged_successful_login( $ip ) ) {
			return;
		}

		$this->mark_successful_login_logged( $ip );
		$username = ( $user instanceof \WP_User ) ? $user->user_login : (string) $user_login;
		$this->reset_attempts( $ip, $username );
		unset( self::$failed_attempts_logged[ $ip ] );
	}

	public function handle_authentication( $user, $username, $password ) {
		$ip = GetIpServices::wptw_get_client_ip();

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $user;
		}

		if ( $this->is_logout_request() ) {
			return $user;
		}

		$is_blocked = $this->is_blocked( $ip );

		if ( $is_blocked['allowed'] === false ) {
			if ( $is_blocked['method'] === 'ip_management' ) {
				$message = ! empty( $is_blocked['block_reason'] ) ? $is_blocked['block_reason'] : 'Your access to this site is blocked.';
			} else {
				$message = $this->get_block_message();
			}
			return new \WP_Error(
				'login_defender_blocked',
				$message,
				array( 'status' => 403 )
			);
		}

		return $user;
	}

	public function check_access() {
		// No ip_protection_enabled guard here — this method must also enforce
		// IP Management rules (blacklist, country) for logged-in admin users,
		// even when brute-force protection is disabled. IpAccessService::check_access()
		// handles the separation internally.

		if ( $this->is_logout_request() ) {
			return;
		}

		$ip         = GetIpServices::wptw_get_client_ip();
		$ip_blocked = $this->is_blocked( $ip );
		if ( is_array( $ip_blocked ) && $ip_blocked['allowed'] === false ) {
			$message = in_array( $ip_blocked['method'], array( 'ip_management', 'geo_restrictions' ), true )
			? ( ! empty( $ip_blocked['block_reason'] ) ? $ip_blocked['block_reason'] : 'Your access to this site is blocked.' )
			: $this->get_block_message();

			if ( is_user_logged_in() ) {
				set_transient( 'login_defender_block_message_' . md5( $ip ), $message, 30 );
				// Log out the user
				wp_logout();
				// Redirect to login page
				wp_safe_redirect( wp_login_url() );
				exit;
			}
			wp_die( esc_html( $message ), 'Access Denied', array( 'response' => 403 ) );
		}
	}

	public function wptw_handle_unblock_ip( $postData ) {
		try {
			$json_data = isset( $postData ) ? wp_unslash( $postData ) : '';
			$data      = json_decode( $json_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON data for IP unblock',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_unblock_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to parse JSON data for IP unblock request',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON data', 'tailwatch' ),
				);
			}

			$ips = isset( $data['ips'] ) ? (array) $data['ips'] : array();
			if ( empty( $ips ) ) {
				Log::error(
					'No IPs provided for unblock',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_unblock_failed',
						'title'  => 'Login Defender',
						'detail'  => 'No IP addresses provided for unblock operation',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'No IPs provided', 'tailwatch' ),
				);
			}

			$success = array();
			$failed  = array();

			foreach ( $ips as $ip ) {
				$ip = sanitize_text_field( $ip );
				if ( $this->ip_access_service->ip_activity->unblock_ip( $ip ) ) {
					$success[] = $ip;
				} else {
					$failed[] = $ip;
				}
			}

			if ( ! empty( $failed ) ) {
				Log::error(
					'IP unblock partially failed',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_unblock_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to unblock IPs: ' . implode( ', ', $failed ),
					)
				);
			}

			Log::info(
				'IP unblock completed',
				array(
					'feature' => 'login_defender',
					'action'  => 'login_defender_ip_unblock_completed',
					'title'  => 'Login Defender',
					'detail'  => 'Successfully unblocked ' . count( $success ) . ' IPs. Failed: ' . count( $failed ) . '. IPs: ' . implode( ', ', $success ),
				)
			);

			return array(
				'code'    => empty( $failed ) ? 200 : 207,
				'message' => sprintf(
					/* translators: 1: success count, 2: failed count */
					__( 'Unblock completed. Success: %1$d, Failed: %2$d', 'tailwatch' ),
					count( $success ),
					count( $failed )
				),
				'success' => $success,
				'failed'  => $failed,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP unblock exception',
				array(
					'feature'   => 'login_defender',
					'action'    => 'login_defender_ip_unblock_failed',
					'title'  => 'Login Defender',
					'detail'    => 'Exception occurred while unblocking IPs: ' . $e->getMessage(),
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while unblocking IPs', 'tailwatch' ),
			);
		}
	}

	public function wptw_handle_delete_ip_activity( $postData ) {
		try {
			$json_data = isset( $postData ) ? wp_unslash( $postData ) : '';
			$data      = json_decode( $json_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON data for IP activity deletion',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_delete_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to parse JSON data for IP activity deletion request',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON data', 'tailwatch' ),
				);
			}

			$ips        = isset( $data['ips'] ) ? (array) $data['ips'] : array();
			$delete_all = isset( $data['is_delete'] ) ? (bool) $data['is_delete'] : false;

			if ( empty( $ips ) && ! $delete_all ) {
				Log::error(
					'No IPs provided for activity deletion',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_delete_failed',
						'title'  => 'Login Defender',
						'detail'  => 'No IP addresses provided and is_delete not set for IP activity deletion',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'No IPs provided or is_delete not set', 'tailwatch' ),
				);
			}

			$result = $this->ip_access_service->ip_activity->delete_ip_activity( $ips, $delete_all );
			if ( $result['success'] ) {
				$message = $delete_all ? __( 'All IP activity deleted successfully', 'tailwatch' ) : sprintf(
					/* translators: %s: list of IPs */
					__( 'IP activity deleted successfully for IPs: %s', 'tailwatch' ),
					implode( ', ', $ips )
				);
				Log::info(
					'IP activity deleted',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_deleted',
						'title'  => 'Login Defender',
						'detail'  => $message,
					)
				);
				return array(
					'code'    => 200,
					'message' => $message,
					'data'    => $result,
				);
			} else {
				$message = 'Failed to delete some IP activity: ' . implode( ', ', array_keys( $result['failed'] ) );
				Log::error(
					'IP activity deletion failed',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_delete_failed',
						'title'  => 'Login Defender',
						'detail'  => $message,
					)
				);
				return array(
					'code'    => 500,
					'message' => $message,
					'data'    => $result,
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'IP activity deletion exception',
				array(
					'feature'   => 'login_defender',
					'action'    => 'login_defender_ip_delete_failed',
					'title'  => 'Login Defender',
					'detail'    => 'Exception occurred while deleting IP activity: ' . $e->getMessage(),
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while deleting IP activity', 'tailwatch' ),
			);
		}
	}

	public function wptw_handle_get_all_ip_activities( $postData ) {
		try {
			$json_data = isset( $postData ) ? wp_unslash( $postData ) : '';
			$data      = json_decode( $json_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON data for IP activities retrieval',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ips_retrieval_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to parse JSON data for IP activities retrieval request',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON data', 'tailwatch' ),
				);
			}

			$limit      = isset( $data['limit'] ) ? absint( $data['limit'] ) : null;
			$page       = isset( $data['page'] ) ? absint( $data['page'] ) : null;
			$activities = $this->ip_access_service->ip_activity->get_all_ip_activities( $limit, $page );

			if ( $activities === false ) {
				Log::error(
					'IP activities retrieval failed',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ips_retrieval_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to retrieve IP activities from database',
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to retrieve IP activities', 'tailwatch' ),
				);
			}

			return array_merge(
				array(
					'code'    => 200,
					'message' => __( 'IP activities retrieved successfully', 'tailwatch' ),
				),
				$activities
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP activities retrieval exception',
				array(
					'feature'   => 'login_defender',
					'action'    => 'login_defender_ips_retrieval_failed',
					'title'  => 'Login Defender',
					'detail'    => 'Exception occurred while retrieving IP activities: ' . $e->getMessage(),
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while retrieving IP activities', 'tailwatch' ),
			);
		}
	}

	public function wptw_handle_get_ip_activity_history( $postData ) {
		try {
			$json_data = isset( $postData ) ? wp_unslash( $postData ) : '';
			$data      = json_decode( $json_data, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON data for IP activity history',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_by_history_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to parse JSON data for IP activity history request',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON data', 'tailwatch' ),
				);
			}

			$ip    = sanitize_text_field( $data['ip'] ?? '' );
			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : null;
			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : null;

			if ( empty( $ip ) ) {
				Log::error(
					'No IP provided for activity history',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_by_history_failed',
						'title'  => 'Login Defender',
						'detail'  => 'No IP address provided for IP activity history retrieval',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'No IP address provided', 'tailwatch' ),
				);
			}

			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				Log::error(
					'Invalid IP address for activity history',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_by_history_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Invalid IP address provided for IP activity history: ' . $ip,
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid IP address', 'tailwatch' ),
				);
			}

			$history = $this->ip_access_service->ip_activity->get_ip_activity_history( $ip, $limit, $page );

			if ( $history === false ) {
				Log::error(
					'IP activity history retrieval failed',
					array(
						'feature' => 'login_defender',
						'action'  => 'login_defender_ip_by_history_failed',
						'title'  => 'Login Defender',
						'detail'  => 'Failed to retrieve IP activity history for IP: ' . $ip,
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to retrieve IP activity history', 'tailwatch' ),
				);
			}

			Log::info(
				'IP activity history retrieved',
				array(
					'feature' => 'login_defender',
					'action'  => 'login_defender_ip_by_history',
					'title'  => 'Login Defender',
					'detail'  => 'Successfully retrieved activity history for IP: ' . $ip . ', history count: ' . count( $history['history'] ),
				)
			);

			return array_merge(
				array(
					'code'    => 200,
					'message' => __( 'IP activity history retrieved successfully', 'tailwatch' ),
				),
				$history
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'IP activity history retrieval exception',
				array(
					'feature'   => 'login_defender',
					'action'    => 'login_defender_ip_by_history_failed',
					'title'  => 'Login Defender',
					'detail'    => 'Exception occurred while retrieving IP activity history: ' . $e->getMessage(),
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while retrieving IP activity history', 'tailwatch' ),
			);
		}
	}

	private function is_logout_request() {
		$is_logout = (
			( isset( $_GET['action'] ) && $_GET['action'] === 'logout' ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			( isset( $_GET['loggedout'] ) && $_GET['loggedout'] == true ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			( isset( $_POST['action'] ) && $_POST['action'] === 'logout' ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'logout' ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			false !== strpos( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', 'wp-login.php?action=logout' ) ||
			false !== strpos( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', 'action=logout' )
		);

		return $is_logout;
	}

	private function has_logged_attempt( $ip ) {
		return isset( self::$failed_attempts_logged[ $ip ] );
	}

	private function mark_attempt_logged( $ip ) {
		self::$failed_attempts_logged[ $ip ] = true;
	}

	private function has_logged_successful_login( $ip ) {
		return isset( self::$successful_logins_logged[ $ip ] );
	}

	private function mark_successful_login_logged( $ip ) {
		self::$successful_logins_logged[ $ip ] = true;
	}

	public function log_failed_attempt( $ip, $username = '' ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( $this->has_logged_attempt( $ip ) ) {
			return false;
		}
		$this->mark_attempt_logged( $ip );
		return $this->ip_access_service->log_failed_attempt( $ip, 'login', $username );
	}

	public function reset_attempts( $ip, $username = '' ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}
		$this->ip_access_service->ip_activity->reset_attempts( $ip, false, $username );
	}

	public function is_blocked( $ip ) {
		// Always return the standard envelope so callers can safely read
		// ['allowed'] / ['method'] without a type check. An unidentifiable IP
		// can't be blocked, so fail open (allowed).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array(
				'allowed'      => true,
				'method'       => 'none',
				'block_reason' => '',
			);
		}
		$result = $this->ip_access_service->check_access( $ip );
		return is_array( $result ) ? $result : array(
			'allowed'      => true,
			'method'       => 'none',
			'block_reason' => '',
		);
	}

	public function get_block_message() {
		$settings = $this->settings;

		$ip      = GetIpServices::wptw_get_client_ip();
		$country = $this->geoip->get_country( $ip );
		$message = $settings['error_messages']['ip_blocked'];
		if ( empty( $message ) ) {
			$message = 'Your access is temporarily blocked due to repeated failed login attempts.';
		}

		$activity = $this->ip_access_service->ip_activity->get_activity( $ip );
		if ( $activity ) {
			$data = json_decode( $activity->value, true );
			if ( is_array( $data ) && isset( $data['lock_type'] ) && $data['lock_type'] !== 'none' ) {
				if ( $data['lock_type'] === 'temporary' ) {
					// WP-local time basis to match how lock_time is stored
					// (current_time('mysql')); mixing time() (UTC) skews the countdown
					// by the GMT offset on non-UTC sites.
					$now       = strtotime( current_time( 'mysql' ) );
					$unlock_ts = strtotime( $data['lock_time'] ) + ( $data['lock_duration'] ?? 0 );
					$remaining = $unlock_ts - $now;
					if ( $remaining > 0 ) {
						// Human-friendly relative duration ("10 minutes", "1 hour, 5 minutes")
						// instead of a raw UTC timestamp the visitor would have to convert.
						$human_time    = TimeService::format_time_remaining( $unlock_ts, $now );
						$message       = str_replace( '[time]', $human_time, $message );
						$message       = str_replace( '[minutes]', ceil( $remaining / 60 ), $message );
						$retry_message = str_replace( '[time]', $human_time, $settings['error_messages']['retry_message'] );
						$message      .= ' ' . $retry_message;
					} else {
						$this->ip_access_service->ip_activity->reset_attempts( $ip, false );
					}
				} else {
					$permanent = $settings['error_messages']['ip_blocked_permanent'];
					if ( empty( $permanent ) ) {
						$permanent = 'Your access has been permanently blocked due to repeated failed login attempts. Please contact the site administrator.';
					}
					$message = str_replace( '[time]', 'permanently', $permanent );
					$message = str_replace( '[minutes]', 'permanently', $message );
				}
			}
		}

		$message = str_replace( '[country]', $country, $message );
		return wp_kses_post( $message );
	}

	public function custom_login_error_message( $error ) {
		$ip = GetIpServices::wptw_get_client_ip();
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $error;
		}

		$cache_key     = 'login_defender_block_message_' . md5( $ip );
		$block_message = get_transient( $cache_key );
		if ( $block_message ) {
			delete_transient( $cache_key );
			return wp_kses_post( $block_message );
		}

		$ip_blocked = $this->is_blocked( $ip );
		if ( $ip_blocked['allowed'] === false ) {
			$message = in_array( $ip_blocked['method'], array( 'ip_management', 'geo_restrictions' ), true )
				? ( ! empty( $ip_blocked['block_reason'] ) ? $ip_blocked['block_reason'] : 'Your access to this site is blocked.' )
				: $this->get_block_message();
			return wp_kses_post( $message );
		}

		$error_cache_key = 'login_defender_error_' . md5( $ip );
		$custom_error    = get_transient( $error_cache_key );
		if ( $custom_error ) {
			delete_transient( $error_cache_key );
			return wp_kses_post( $custom_error );
		}

		return $error;
	}

	public function cleanup_old_logs() {
		$settings       = $this->login_defender_ip_protection_settings();
		$retention_days = $settings['log_retention_days'] ?? 30;
		$cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( "-$retention_days days" ) );
		global $wpdb;
		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
		$result     = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'DELETE FROM %i WHERE `key` IN (%s, %s) AND date_created < %s',
				$table_name,
				'ip_activity',
				'ip_activity_history',
				$cutoff
			)
		);
	}

	public function cleanup_expired_blocks() {
		global $wpdb;
		$table_name = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;

		$activities        = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND is_active = %d',
				$table_name,
				'ip_activity',
				1
			)
		);
		$count             = 0;
		$current_timestamp = strtotime( current_time( 'mysql' ) );
		foreach ( $activities as $activity ) {
			$data = json_decode( $activity->value, true );
			if ( ! is_array( $data ) ) {
				continue; // Skip malformed rows rather than fatal on null index.
			}
			$updated = false;

			if ( ( $data['lock_type'] ?? 'none' ) === 'temporary' && strtotime( $data['lock_time'] ?? 'now' ) + ( $data['lock_duration'] ?? 0 ) < $current_timestamp ) {
				$this->ip_access_service->ip_activity->reset_attempts( $data['ip_address'], false );
				++$count;
				continue;
			}

			if ( isset( $data['escalation_window_start'] ) && $data['escalation_window_start'] && strtotime( $data['escalation_window_start'] ) + $data['escalation_window_duration'] < $current_timestamp ) {
				$this->ip_access_service->ip_activity->log_history_event(
					$data['ip_address'],
					'reset_escalation',
					array(
						'previous_temp_block_count' => $data['temp_block_count'] ?? 0,
					)
				);
				$data['temp_block_count']           = 0;
				$data['escalation_window_start']    = '';
				$data['escalation_window_duration'] = 0;
				$updated                            = true;
				++$count;
			}

			if ( $updated ) {
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
						'option' => $data['ip_address'],
					),
					array( '%s', '%s', '%s', '%d' ),
					array( '%s', '%s' )
				);
				$cache_key = 'login_defender_ip_activity_' . md5( $data['ip_address'] );
				set_transient( $cache_key, $data, HOUR_IN_SECONDS );
			}
		}

		$rules = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` IN (%s, %s) AND type_state = %s AND is_active = %d',
				$table_name,
				'ip_range_block',
				'country_block',
				'active',
				1
			)
		);
		foreach ( $rules as $rule ) {
			$data = json_decode( $rule->value, true );
			if ( $data['block_type'] === 'temporary' && strtotime( $data['block_start_time'] ) + $data['block_duration'] < $current_timestamp ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_name,
					array(
						'is_active'  => 0,
						'type_state' => 'expired',
					),
					array( 'id' => $rule->id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
				$transient = $rule->key === 'ip_range_block' ? 'login_defender_ip_rules' : 'login_defender_country_rules';
				delete_transient( $transient );
				++$count;
			}
		}
	}
}
