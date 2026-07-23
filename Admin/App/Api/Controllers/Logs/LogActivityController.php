<?php
/**
 * Log Activity Controller
 *
 * Handles logging of user authentication activities and password management events.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Logs
 */

namespace Tailwatch\Admin\App\Api\Controllers\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\IpService;

/**
 * Class LogActivityController
 *
 * Monitors and logs user login, logout, registration, and password reset activities.
 *
 */
class LogActivityController {

	/**
	 * Constructor - Initialize hooks.
	 *
	 */
	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wp_login', array( $this, 'wptw_login_alert' ), 10, 2 );
		$hook_controller->add_action_hook( 'wp_logout', array( $this, 'wptw_logout_alert' ), 10, 1 );
		$hook_controller->add_action_hook( 'wp_login_failed', array( $this, 'wptw_failed_alert' ), 10, 2 );
		$hook_controller->add_action_hook( 'user_register', array( $this, 'wptw_register_alert' ), 10, 1 );
		$hook_controller->add_action_hook( 'retrieve_password', array( $this, 'wptw_password_reset_request_alert' ), 10, 1 );
		$hook_controller->add_filter_hook( 'lostpassword_user_data', array( $this, 'wptw_password_reset_non_exist_user' ), 10, 1 );
		$hook_controller->add_action_hook( 'password_reset', array( $this, 'wptw_password_reset_success_alert' ), 10, 2 );
	}

	/**
	 * Get log activity feature options.
	 *
	 * @return array Feature settings array.
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_log_activity';
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Check if log activity is enabled.
	 *
	 * @return array {
	 *     Activity logging status array.
	 *
	 *     @type bool $parent_enable  Whether parent feature is enabled.
	 *     @type bool $feature_enable Whether any feature is enabled.
	 * }
	 */
	public function wptw_log_activity_is_enabled() {
		$feature_settings = $this->get_features_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$any_feature_enabled = false;
		for ( $i = 1; $i <= 8; $i++ ) {
			$field_key = 'field_' . $i;
			if ( isset( $feature_settings[ $field_key ]['options']['option']['selected'] ) &&
				true === $feature_settings[ $field_key ]['options']['option']['selected'] ) {
				$any_feature_enabled = true;
				break;
			}
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => $any_feature_enabled,
		);
	}

	/**
	 * Check if push notifications are enabled for log activity feature.
	 *
	 * @param string $field_name Field name to check.
	 *
	 * @return bool Whether push notifications are enabled.
	 */
	public function wptw_logs_activity_push_notification( $field_name ) {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_log_activity';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Send push notification for log activity.
	 *
	 * @param array  $new_one    Activity data array.
	 * @param string $field_name Field name for notification settings.
	 *
	 * @return void
	 */
	public function wptw_send_logs_activity_notification( $new_one, $field_name, $record_id = 0 ) {
		if ( $this->wptw_logs_activity_push_notification( $field_name ) ) {
			$severity = 'info';
			$title    = isset( $new_one['title'] ) ? sanitize_text_field( $new_one['title'] ) : '';
			$message  = isset( $new_one['body'] ) ? sanitize_text_field( $new_one['body'] ) : '';

			// Map each tracked field to a human-readable event label for the
			// mobile app's card view. Order here matches the field numbering
			// in the Activity Logs feature options (field_1..field_8).
			$event_map = array(
				'field_1' => 'Login',
				'field_2' => 'Logout',
				'field_3' => 'Incorrect password',
				'field_4' => 'Failed login (unknown user)',
				'field_5' => 'User registration',
				'field_6' => 'Password reset requested',
				'field_7' => 'Password reset requested (unknown user)',
				'field_8' => 'Password reset successful',
			);

			// Stable event-key slugs (dashboard mapping) parallel to the display labels above.
			$event_slug_map = array(
				'field_1' => 'login',
				'field_2' => 'logout',
				'field_3' => 'incorrect_password',
				'field_4' => 'failed_login_unknown_user',
				'field_5' => 'user_registration',
				'field_6' => 'password_reset_requested',
				'field_7' => 'password_reset_requested_unknown_user',
				'field_8' => 'password_reset_successful',
			);

			$meta_data = array(
				'feature'      => 'activity_logs',
				'event'        => isset( $event_slug_map[ $field_name ] ) ? $event_slug_map[ $field_name ] : sanitize_key( $field_name ),
				'feature_name' => 'Activity Logs',
				'state'        => isset( $event_map[ $field_name ] ) ? $event_map[ $field_name ] : $field_name,
			);

			if ( $record_id > 0 ) {
				$meta_data['record_id'] = absint( $record_id );
			}

			// user_data is wp_json_encode'd in $new_one. Decode and map its
			// snake_case keys (username / ip_address / browser) into the
			// canonical human-readable shape the mobile app expects.
			if ( isset( $new_one['user_data'] ) && is_string( $new_one['user_data'] ) ) {
				$decoded = json_decode( $new_one['user_data'], true );
				if ( is_array( $decoded ) ) {
					if ( isset( $decoded['username'] ) ) {
						$meta_data['username'] = $decoded['username'];
					}
					if ( isset( $decoded['ip_address'] ) ) {
						$meta_data['IP address'] = $decoded['ip_address'];
					}
					if ( isset( $decoded['browser'] ) ) {
						$meta_data['browser'] = $decoded['browser'];
					}
				}
			}

			// Offer a "block IP" action only for failed-login events (the IP is an attacker).
			if ( in_array( $field_name, array( 'field_3', 'field_4' ), true ) && isset( $meta_data['IP address'] ) ) {
				$meta_data['block_ip'] = $meta_data['IP address'];
			}

			$push_notification = new PushNotificationController();
			$push_notification->wptw_trigger_notification( $severity, $title, $message, $meta_data );
		}
	}

	/**
	 * Record log activity to database.
	 *
	 * @param array  $data        Log data to store.
	 * @param int    $get_user_id User ID associated with activity.
	 * @param string $type_is     Type of log entry. Default 'JSON'.
	 *
	 * @return int|false The inserted row id, or false on failure.
	 */
	public function wptw_record_logs_activity( $data, $get_user_id, $type_is = 'JSON' ) {
		$option = 'default_logs_activity';

		// Facets for the dynamic filter: the username (real or attempted) is
		// preserved in the log's user_data; the client IP resolves at write time.
		$facets = array( 'ip_address' => IpService::get_client_ip() );
		if ( isset( $data['user_data'] ) ) {
			$decoded = json_decode( (string) $data['user_data'], true );
			if ( is_array( $decoded ) && isset( $decoded['username'] ) && '' !== $decoded['username'] ) {
				$facets['username'] = $decoded['username'];
			}
		}

		$activity_log_data = new MonitoringLogController();
		return $activity_log_data->wptw_send_log_report( $data, $option, $type_is, null, absint( $get_user_id ), $facets );
	}

	/**
	 * Get current user data for logging.
	 *
	 * @param string $username Optional username associated with the activity. For
	 *                         non-existing-user events this is the raw submitted
	 *                         value (useful for forensics).
	 *
	 * @return array User data array with username, IP, browser, and port.
	 */
	public function wptw_user_data_is( $username = '' ) {
		return array(
			'username'    => sanitize_text_field( (string) $username ),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized inline for logging
			'ip_address'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized inline for logging
			'browser'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized inline for logging
			'remote_port' => isset( $_SERVER['REMOTE_PORT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_PORT'] ) ) : '',
		);
	}

	/**
	 * Log user login activity.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 *
	 * @return void
	 */
	public function wptw_login_alert( $user_login, $user ) {
		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		if ( isset( $get_feature['field_1']['options']['option']['selected'] ) &&
			true === $get_feature['field_1']['options']['option']['selected'] ) {

			$new_one = array(
				'domain'    => WPTW_GET_SITE_URL,
				'title'     => 'New Login Detected',
				'body'      => 'A user successfully logged in to your website. If this wasn\'t you, review activity immediately or secure your account.',
				'type'      => 'normal',
				'user_data' => wp_json_encode( $this->wptw_user_data_is( $user_login ) ),
				'status'    => 'active',
			);

			$user_roles = isset( $user->roles ) ? $user->roles : array();

			$get_user_id = absint( $user->ID );
			$record_id   = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'login_alert' );

			if ( in_array( 'administrator', $user_roles, true ) ) {
				$this->wptw_send_logs_activity_notification( $new_one, 'field_1', $record_id );
			}
		}
	}

	/**
	 * Log user logout activity.
	 *
	 * @param int $username User ID.
	 *
	 * @return void
	 */
	public function wptw_logout_alert( $username ) {
		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		if ( isset( $get_feature['field_2']['options']['option']['selected'] ) &&
			true === $get_feature['field_2']['options']['option']['selected'] ) {

			$user_data = get_userdata( absint( $username ) );
			if ( ! $user_data ) {
				return;
			}

			$get_user_id = absint( $user_data->ID );

			$new_one = array(
				'domain'    => WPTW_GET_SITE_URL,
				'title'     => 'User Logged Out',
				'body'      => 'A user has successfully logged out from your website.',
				'type'      => 'normal',
				'user_data' => wp_json_encode( $this->wptw_user_data_is( $user_data->user_login ) ),
				'status'    => 'active',
			);

			$user_roles = isset( $user_data->roles ) ? $user_data->roles : array();

			$record_id = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'logout_alert' );

			if ( in_array( 'administrator', $user_roles, true ) ) {
				$this->wptw_send_logs_activity_notification( $new_one, 'field_2', $record_id );
			}
		}
	}

	/**
	 * Failed-login error codes that actually mean the request was BLOCKED
	 * (IP / country / user block) rather than "wrong credentials". A block
	 * returns one of these from the `authenticate` filter, which makes WP fire
	 * wp_login_failed — so without this list a blocked request (even with the
	 * CORRECT password) would be mislabeled as an incorrect-password failure.
	 */
	private const BLOCK_ERROR_CODES = array(
		'login_defender_blocked', // LD auto-lockout + IP-Management login-scope blacklist/country.
		'wptw_user_blocked',      // Admin on-demand user block (Pro).
	);

	/**
	 * Log failed login attempts.
	 *
	 * @param string $username Username or email.
	 * @param mixed  $error    WP_Error from wp_login_failed (null on older WP).
	 *
	 * @return void
	 */
	public function wptw_failed_alert( $username, $error = null ) {
		$username = sanitize_text_field( $username );

		if ( empty( $username ) ) {
			return;
		}

		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		// WordPress login accepts either username OR email. get_user_by('login')
		// only matches the username, so an existing user logging in with their
		// email + wrong password would falsely register as "non-existing user"
		// in the logs. Fall back to email lookup when the input looks like one.
		$user = get_user_by( 'login', $username );
		if ( ! $user && is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}
		$is_non_existing_user = ! $user;

		// A blocked request is NOT a credential failure, and the block was
		// already logged once when it was created. Skip it entirely here so it
		// is never mislabeled as an incorrect-password attempt (which would also
		// be a lie when the submitted password was actually correct).
		if ( $this->is_block_failure( $error ) ) {
			return;
		}

		// Log incorrect password for existing users.
		if ( isset( $get_feature['field_3']['options']['option']['selected'] ) &&
			true === $get_feature['field_3']['options']['option']['selected'] ) {

			if ( $user ) {
				$new_one = array(
					'domain'    => WPTW_GET_SITE_URL,
					'title'     => 'Failed Login Attempt',
					'body'      => 'A login attempt failed due to incorrect credentials. Multiple failures may indicate a brute-force attempt.',
					'type'      => 'normal',
					'user_data' => wp_json_encode( $this->wptw_user_data_is( $user->user_login ) ),
					'status'    => 'active',
					'user_id'   => absint( $user->ID ),
				);

				$user_roles = isset( $user->roles ) ? $user->roles : array();

				$get_user_id = absint( $user->ID );
				$record_id   = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'incorrect_password' );

				if ( in_array( 'administrator', $user_roles, true ) ) {
					$this->wptw_send_logs_activity_notification( $new_one, 'field_3', $record_id );
				}
			}
		}

		// Log failed login for non-existing users.
		if ( isset( $get_feature['field_4']['options']['option']['selected'] ) &&
			true === $get_feature['field_4']['options']['option']['selected'] ) {

			if ( $is_non_existing_user ) {
				$new_one = array(
					'domain'    => WPTW_GET_SITE_URL,
					'title'     => 'Suspicious Login Attempt',
					'body'      => 'A login attempt was made for a non-existent user. This may indicate automated probing or bot activity.',
					'type'      => 'normal',
					'user_data' => wp_json_encode( $this->wptw_user_data_is( $username ) ),
					'status'    => 'active',
				);

				$get_user_id = 0;
				$record_id = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'failed_non_user' );

				$this->wptw_send_logs_activity_notification( $new_one, 'field_4', $record_id );
			}
		}
	}

	/**
	 * Whether a wp_login_failed event was caused by one of our access blocks
	 * (IP / country / user) rather than wrong credentials.
	 *
	 * @param mixed $error WP_Error passed to wp_login_failed (may be null).
	 * @return bool
	 */
	private function is_block_failure( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		foreach ( $error->get_error_codes() as $code ) {
			if ( in_array( $code, self::BLOCK_ERROR_CODES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Log user registration activity.
	 *
	 * @param int $user_id New user ID.
	 *
	 * @return void
	 */
	public function wptw_register_alert( $user_id ) {
		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		if ( isset( $get_feature['field_5']['options']['option']['selected'] ) &&
			true === $get_feature['field_5']['options']['option']['selected'] ) {

			$user_info = get_userdata( absint( $user_id ) );
			if ( ! $user_info ) {
				return;
			}

			$new_one = array(
				'domain'    => WPTW_GET_SITE_URL,
				'title'     => 'New User Registered',
				'body'      => 'A new user has successfully signed up on your website. Review user details if moderation is required.',
				'type'      => 'normal',
				'user_data' => wp_json_encode( $this->wptw_user_data_is( $user_info->user_login ) ),
				'status'    => 'active',
			);

			$get_user_id = absint( $user_info->ID );
			$record_id = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'user_register' );

			$this->wptw_send_logs_activity_notification( $new_one, 'field_5', $record_id );
		}
	}

	/**
	 * Log password reset request activity.
	 *
	 * @param string $user_login_or_email Username or email.
	 *
	 * @return void
	 */
	public function wptw_password_reset_request_alert( $user_login_or_email ) {
		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		$user = get_user_by( 'login', sanitize_text_field( $user_login_or_email ) );
		if ( ! $user ) {
			$user = get_user_by( 'email', sanitize_email( $user_login_or_email ) );
		}

		if ( $user && isset( $get_feature['field_6']['options']['option']['selected'] ) &&
			true === $get_feature['field_6']['options']['option']['selected'] ) {

			$new_one = array(
				'domain'    => WPTW_GET_SITE_URL,
				'title'     => 'Password Reset Requested',
				'body'      => 'A password reset request was initiated for a user account. If this wasn\'t expected, ensure your site is secure.',
				'type'      => 'normal',
				'user_data' => wp_json_encode( $this->wptw_user_data_is( $user->user_login ) ),
				'status'    => 'active',
			);

			$user_roles = isset( $user->roles ) ? $user->roles : array();

			$get_user_id = absint( $user->ID );
			$record_id   = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'password_request' );

			if ( in_array( 'administrator', $user_roles, true ) ) {
				$this->wptw_send_logs_activity_notification( $new_one, 'field_6', $record_id );
			}
		}
	}

	/**
	 * Log password reset attempt for non-existing user.
	 *
	 * @param WP_User|WP_Error $user_data User object or WP_Error if user doesn't exist.
	 *
	 * @return WP_User|WP_Error User data passed through.
	 */
	public function wptw_password_reset_non_exist_user( $user_data ) {
		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return $user_data;
		}

		if ( isset( $get_feature['field_7']['options']['option']['selected'] ) &&
			true === $get_feature['field_7']['options']['option']['selected'] ) {

			if ( is_wp_error( $user_data ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress password reset action hook, nonce is verified by WordPress core
				$user_login_or_email = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';

				$new_one = array(
					'domain'    => WPTW_GET_SITE_URL,
					'title'     => 'Suspicious Password Reset Attempt',
					'body'      => 'A password reset request was made for a non-existent user. This may indicate automated abuse or probing activity.',
					'type'      => 'normal',
					'user_data' => wp_json_encode( $this->wptw_user_data_is( $user_login_or_email ) ),
					'status'    => 'active',
				);

				$get_user_id = 0;
				$record_id = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'non_user_pass_request' );

				$this->wptw_send_logs_activity_notification( $new_one, 'field_7', $record_id );
			}
		}

		return $user_data;
	}

	/**
	 * Log successful password reset activity.
	 *
	 * @param \WP_User $user         User object.
	 * @param string   $new_password New password (not logged).
	 *
	 * @return void
	 */
	public function wptw_password_reset_success_alert( $user, $new_password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameter required by WordPress hook signature.
		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		if ( isset( $get_feature['field_8']['options']['option']['selected'] ) &&
			true === $get_feature['field_8']['options']['option']['selected'] ) {

			$new_one = array(
				'domain'    => WPTW_GET_SITE_URL,
				'title'     => 'Password Reset Completed',
				'body'      => 'A user successfully reset their password. If this wasn\'t authorized, take immediate action to secure the account.',
				'type'      => 'normal',
				'user_data' => wp_json_encode( $this->wptw_user_data_is( isset( $user->user_login ) ? $user->user_login : '' ) ),
				'status'    => 'active',
			);

			$user_roles = isset( $user->roles ) ? $user->roles : array();

			$get_user_id = absint( $user->ID );
			$record_id   = $this->wptw_record_logs_activity( $new_one, $get_user_id, 'reset_success' );

			if ( in_array( 'administrator', $user_roles, true ) ) {
				$this->wptw_send_logs_activity_notification( $new_one, 'field_8', $record_id );
			}
		}
	}
}
