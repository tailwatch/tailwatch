<?php
/**
 * Push Notification Controller
 *
 * Manages mobile push notifications via FCM. Email notifications and severity-based
 * filtering have been removed; only the mobile channel remains. Severity is preserved
 * as a metadata tag forwarded to the mobile app (so it can render appropriate UI),
 * not as a user-configurable filter.
 *
 * @package    Tailwatch
 * @subpackage Controllers/PushNotifications
 */

namespace Tailwatch\Admin\App\Api\Controllers\PushNotifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\FCMController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class PushNotificationController
 *
 * Mobile-only push notification dispatcher.
 *
 */
class PushNotificationController {

	/**
	 * Get global mobile notification status.
	 *
	 * @return array {
	 *     @type bool $mobile_notification Whether mobile notifications are enabled.
	 * }
	 */
	public function tailwatch_global_get_push_notification() {
		$db_model = new DBModel();
		$option   = 'site_settings';
		$key      = 'plugin_activation';
		$get_data = $db_model->get_recent_data( $option, $key );

		$response = array(
			'mobile_notification' => false,
		);

		if ( ! empty( $get_data ) && isset( $get_data['mobile_notification'] ) ) {
			$response['mobile_notification'] = (bool) $get_data['mobile_notification'];
		}

		return $response;
	}

	/**
	 * Check if push notifications are enabled for a specific feature/field.
	 *
	 * @param string $key        Feature key.
	 * @param string $option     Feature option.
	 * @param string $field_name Field name to check.
	 *
	 * @return bool True if push notifications enabled for the feature, false otherwise.
	 */
	public function tailwatch_notification_enable_for_feature( $key, $option, $field_name ) {
		$db_model      = new DBModel();
		$existing_data = $db_model->get_recent_data( $option, $key );

		if ( ! isset( $existing_data['options'][ $field_name ]['push_notification'] ) ) {
			return false;
		}

		return (bool) $existing_data['options'][ $field_name ]['push_notification'];
	}

	/**
	 * Get mobile notification status with license info.
	 *
	 * @return array
	 */
	public function tailwatch_get_push_notification() {
		try {
			$notification_status = $this->tailwatch_global_get_push_notification();
			$verify_license      = $this->tailwatch_push_notification_verify_license();

			return array(
				'code'                     => 200,
				'mobile_notification'      => $notification_status['mobile_notification'],
				'license_connected'        => $verify_license['status'],
				'notification_credit_left' => $verify_license['notification_credit_left'],
				'message'                  => $notification_status['mobile_notification']
					? 'Mobile notifications are enabled.'
					: 'Mobile notifications are disabled.',
			);
		} catch ( \Exception $e ) {
			return array(
				'code'                     => 500,
				'mobile_notification'      => false,
				'license_connected'        => false,
				'notification_credit_left' => 0,
				'message'                  => __( 'Failed to retrieve mobile notification status.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Enable or disable mobile notifications.
	 *
	 * @param string $post_data JSON encoded payload with mobile_notification boolean.
	 *
	 * @return array
	 */
	public function tailwatch_enable_disable_push_notification( $post_data ) {
		$mobile_notification = null;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( ! is_array( $data ) || ! isset( $data['mobile_notification'] ) ) {
				Log::warning(
					'Mobile notification settings update failed: Missing required parameter',
					array(
						'feature' => 'push_notifications',
						'action'  => 'push_notification_enable_disable',
						'error'   => 'mobile_notification parameter is required',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'mobile_notification parameter is required.', 'tailwatch' ),
				);
			}

			$mobile_notification = (bool) $data['mobile_notification'];

			$db_model = new DBModel();
			$option   = 'site_settings';
			$key      = 'plugin_activation';

			$existing_data = $db_model->get_recent_data( $option, $key );

			if ( ! $existing_data ) {
				Log::error(
					'Mobile notification settings update failed: Failed to retrieve existing settings',
					array(
						'feature'             => 'push_notifications',
						'action'              => 'push_notification_enable_disable',
						'mobile_notification' => $mobile_notification,
						'error'               => 'Failed to retrieve existing settings from database',
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to retrieve existing settings.', 'tailwatch' ),
				);
			}

			$existing_data['mobile_notification'] = $mobile_notification;

			$db_data       = array( 'value' => wp_json_encode( $existing_data ) );
			$update_result = $db_model->update_recent_row( $db_data, $key, $option );

			if ( $update_result ) {
				Log::info(
					'Mobile notification settings updated successfully',
					array(
						'feature'             => 'push_notifications',
						'action'              => 'push_notification_enable_disable',
						'mobile_notification' => $existing_data['mobile_notification'],
						'title'               => 'Push Notification Settings Updated',
					)
				);

				return array(
					'code'    => 200,
					'message' => __( 'Mobile notification settings updated successfully.', 'tailwatch' ),
					'data'    => array(
						'mobile_notification' => $existing_data['mobile_notification'],
					),
				);
			}

			Log::error(
				'Mobile notification settings update failed: Database update failed',
				array(
					'feature'             => 'push_notifications',
					'action'              => 'push_notification_enable_disable',
					'mobile_notification' => $mobile_notification,
					'error'               => 'Database update returned false',
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to update mobile notification settings.', 'tailwatch' ),
			);
		} catch ( \Exception $e ) {
			Log::error(
				'Mobile notification settings update failed: Exception occurred',
				array(
					'feature'             => 'push_notifications',
					'action'              => 'push_notification_enable_disable',
					'mobile_notification' => $mobile_notification,
					'error'               => $e->getMessage(),
					'exception'           => $e,
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'Failed to update mobile notification settings.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Verify license for push notifications.
	 *
	 * @return array {
	 *     @type bool        $status                   Whether license is valid and connected.
	 *     @type string|null $userId                   User ID if valid, null otherwise.
	 *     @type int         $notification_credit_left Remaining notification credits.
	 * }
	 */
	private function tailwatch_push_notification_verify_license() {
		// Read cached license data from DB — never make a live API call here.
		// Notifications fire inside the logging system; a live API call would
		// add latency, risk timeouts, and trigger Log:: calls that cause loops.
		$verify_status = new VerifyStatusController();
		$license_data  = $verify_status->get_plugin_activation_status();

		if ( ! empty( $license_data['extended_connected'] ) && ! empty( $license_data['extended_connected']['user_id'] ) ) {
			$last_check = get_option( 'tailwatch_last_license_check', array() );
			$credits    = 0;

			if ( ! empty( $last_check ) && ( $last_check['status'] ?? '' ) === 'active' ) {
				$credits = absint( $last_check['notification_credit_left'] ?? 1 );
			} else {
				// No cached check or check not active — allow 1 credit as fallback
				// so notifications aren't silently dropped when cache is stale.
				$credits = 1;
			}

			return array(
				'status'                   => true,
				'userId'                   => $license_data['extended_connected']['user_id'],
				'notification_credit_left' => $credits,
			);
		}

		return array(
			'status'                   => false,
			'userId'                   => null,
			'notification_credit_left' => 0,
		);
	}

	/**
	 * Send mobile push notification via FCM.
	 *
	 * @param string     $severity  Notification severity tag (critical, warning, info) forwarded to FCM.
	 * @param string     $userId    User ID for the notification.
	 * @param array|null $meta_data  Optional structured payload the mobile app
	 *                              can act on (deep-link target, entity IDs,
	 *                              action buttons, etc.). Forwarded verbatim
	 *                              when non-empty; omitted otherwise.
	 *
	 * @return bool True if notification sent successfully, false otherwise.
	 */
	private function tailwatch_send_mobile_push_notification( $severity, $userId, $meta_data = null ) {
		try {
			if ( empty( $userId ) || ! is_string( $userId ) ) {
				return false;
			}

			$sanitized_user_id = sanitize_text_field( $userId );
			if ( empty( $sanitized_user_id ) ) {
				return false;
			}

			$notification_data = array(
				'userId' => $sanitized_user_id,
				'domain' => esc_url_raw( TAILWATCH_GET_SITE_URL ),
				'type'   => sanitize_text_field( $severity ),
			);

			if ( is_array( $meta_data ) && ! empty( $meta_data ) ) {
				$notification_data['dataMeta'] = $meta_data;
			}

			$fcm_controller    = new FCMController();
			$notification_sent = $fcm_controller->send_push_notification( $notification_data );

			return $notification_sent;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Trigger a mobile push notification.
	 *
	 * Severity is preserved as a metadata tag forwarded to FCM (so the mobile
	 * app can render appropriate UI). It no longer gates whether the
	 * notification fires — that's purely the mobile_notification toggle.
	 *
	 * @param string     $severity Notification severity (critical, warning, info).
	 * @param string     $title    Notification title.
	 * @param string     $message  Notification message.
	 * @param array|null $meta_data Optional structured payload for the mobile
	 *                             app (deep-link target, entity IDs, action
	 *                             buttons, etc.). Sent verbatim when present.
	 *
	 * @return array
	 */
	public function tailwatch_trigger_notification( $severity, $title, $message, $meta_data = null ) {
		try {
			if ( empty( $severity ) || empty( $title ) || empty( $message ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Severity, title, and message are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$severity = sanitize_text_field( $severity );

			$notification_status = $this->tailwatch_global_get_push_notification();
			$mobile_enabled      = (bool) $notification_status['mobile_notification'];

			$response_data = array(
				'severity'    => $severity,
				'mobile_sent' => false,
			);

			if ( ! $mobile_enabled ) {
				$response_data['mobile_reason'] = 'mobile_notifications_disabled';
				return array(
					'data'    => $response_data,
					'message' => __( 'Mobile notifications are disabled.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$verify_license = $this->tailwatch_push_notification_verify_license();

			if ( ! $verify_license['status'] ) {
				$response_data['mobile_reason'] = 'license_not_connected';
				return array(
					'data'    => $response_data,
					'message' => __( 'Mobile notification skipped: License not connected.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( empty( $verify_license['notification_credit_left'] ) || $verify_license['notification_credit_left'] <= 0 ) {
				$response_data['mobile_reason'] = 'no_credits';
				return array(
					'data'    => $response_data,
					'message' => __( 'Mobile notification skipped: Notification credits exhausted.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( empty( $verify_license['userId'] ) ) {
				$response_data['mobile_reason'] = 'no_user_id';
				return array(
					'data'    => $response_data,
					'message' => __( 'Mobile notification skipped: User identifier missing.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$mobile_sent = $this->tailwatch_send_mobile_push_notification(
				$severity,
				$verify_license['userId'],
				$meta_data
			);

			$response_data['mobile_sent'] = $mobile_sent;

			return array(
				'data'    => $response_data,
				'message' => $mobile_sent
					? 'Mobile push notification sent successfully.'
					: 'Mobile push notification failed.',
				'code'    => $mobile_sent ? 200 : 500,
			);
		} catch ( \Exception $e ) {
			return array(
				'data'    => array(),
				'message' => __( 'Failed to trigger notification. Please try again.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Dispatch a critical mobile push notification that bypasses the user's push toggle.
	 *
	 * Standard "transactional override" pattern (cf. Stripe payment-failure emails,
	 * AWS billing alerts, Auth0 security events): safety-critical messages override
	 * user unsubscribe preferences. Use this ONLY for events the site operator must
	 * hear about regardless of preferences — recovery mode activation, hard security
	 * incidents, etc. For everything else, use tailwatch_trigger_notification().
	 *
	 * Bypasses (user-controlled toggles):
	 *  - mobile_notification toggle (channel enable)
	 *
	 * Still honors (external/paid constraints, not user preferences):
	 *  - License connectivity
	 *  - Notification credit balance
	 *
	 * @param string     $title    Notification title.
	 * @param string     $message  Notification message.
	 * @param array|null $meta_data Optional structured payload for the mobile
	 *                             app (deep-link target, entity IDs, action
	 *                             buttons, etc.). Sent verbatim when present.
	 * @return array Standard response shape with mobile_sent flag.
	 */
	public function tailwatch_trigger_critical_push_notification( $title, $message, $meta_data = null ) {
		try {
			if ( empty( $title ) || empty( $message ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Title and message are required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$severity    = 'critical';
			$mobile_sent = false;
			$reason      = '';

			$verify_license = $this->tailwatch_push_notification_verify_license();
			if ( ! $verify_license['status'] ) {
				$reason = 'license_not_connected';
			} elseif ( empty( $verify_license['notification_credit_left'] ) ) {
				$reason = 'no_credits';
			} elseif ( empty( $verify_license['userId'] ) ) {
				$reason = 'no_user_id';
			} else {
				$mobile_sent = $this->tailwatch_send_mobile_push_notification(
					$severity,
					$verify_license['userId'],
					$meta_data
				);
				if ( ! $mobile_sent ) {
					$reason = 'send_failed';
				}
			}

			return array(
				'data'    => array(
					'severity'    => $severity,
					'mobile_sent' => $mobile_sent,
					'forced'      => true,
					'reason'      => $mobile_sent ? '' : $reason,
				),
				'message' => $mobile_sent
					? 'Critical push notification dispatched.'
					: 'Critical push notification could not be delivered.',
				'code'    => $mobile_sent ? 200 : 500,
			);
		} catch ( \Exception $e ) {
			return array(
				'data'    => array(),
				'message' => __( 'Failed to dispatch critical push notification.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}
}