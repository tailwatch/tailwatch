<?php

namespace Tailwatch\Admin\App\Api\Controllers\PushNotifications;

use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FCMController {

	/**
	 * HTTP timeout for notification requests in seconds.
	 */
	const NOTIFICATION_TIMEOUT = 15;

	/**
	 * Expected success HTTP response code.
	 */
	const SUCCESS_CODE = 200;

	/**
	 * Send a push notification.
	 *
	 * Sends notification data to the remote push notification service.
	 *
	 * @param array $notification_data {
	 *     Notification data array.
	 *
	 *     @type string $userId   User ID for the notification.
	 *     @type string $domain   Site domain URL.
	 *     @type string $title    Notification title.
	 *     @type string $body     Notification message body.
	 *     @type string $type     Notification type (e.g., 'critical', 'info').
	 *     @type array  $meta_data Optional. Structured payload for the mobile
	 *                            app (deep-link target, entity IDs, action
	 *                            buttons). Forwarded verbatim when present;
	 *                            omitted from the outbound JSON otherwise.
	 * }
	 *
	 * @return bool True if notification sent successfully, false otherwise.
	 */
	public function send_push_notification( $notification_data ) {
		// Validate required data.
		if ( empty( $notification_data ) || ! is_array( $notification_data ) ) {
			return false;
		}

		// Validate required fields.
		$required_fields = array( 'userId', 'domain', 'type' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $notification_data[ $field ] ) ) {
				return false;
			}
		}

		$header_key = VerifyStatusController::get_central_api_header_key();
		if ( '' === $header_key ) {
			// header_key is required to authenticate with the central API.
			// Without it the request would be rejected anyway, so abort
			// locally instead of wasting the round trip.
			return false;
		}

		$route_token = VerifyStatusController::get_route_token( 'push_notification' );
		if ( '' === $route_token ) {
			// No route token means the relay can't dispatch the push; abort locally.
			return false;
		}

		$response = wp_remote_post(
			TAILWATCH_RELAY,
			array(
				'headers'   => array(
					'Content-Type'      => 'application/json',
					'X-Tailwatch-Header-Key' => $header_key,
					'X-Tailwatch-Route'      => $route_token,
				),
				'body'      => wp_json_encode( $notification_data ),
				'timeout'   => self::NOTIFICATION_TIMEOUT,
				'sslverify' => true,
			)
		);

		// Check for errors.
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( self::SUCCESS_CODE !== $http_code ) {
			$response_body = wp_remote_retrieve_body( $response );
			return false;
		}

		return true;
	}
}
