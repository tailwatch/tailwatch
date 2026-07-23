<?php
namespace Tailwatch\Admin\App\Api\Controllers\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Logging\Log;


class EmailLogController {

	/**
	 * Re-entrancy guard to prevent recursive email logging.
	 *
	 * When our notification system sends an email via wp_mail(), it triggers
	 * wp_mail_succeeded, which would call log_email_activity() again,
	 * creating an infinite loop. This flag prevents that.
	 *
	 * @var bool
	 */
	private static $is_logging = false;

	/**
	 * Captured email headers from wp_mail filter.
	 *
	 * @var string
	 */
	private $email_headers = '';

	/**
	 * Constructor.
	 *
	 * Registers hooks for capturing email headers and logging status.
	 *
	 */
	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_filter_hook( 'wp_mail', array( $this, 'capture_email_headers' ), 10, 1 );
		$hook_controller->add_action_hook( 'wp_mail_succeeded', array( $this, 'log_email_activity' ), 10, 1 );
		$hook_controller->add_action_hook( 'wp_mail_failed', array( $this, 'log_email_error' ), 10, 1 );
	}

	/**
	 * Get feature settings.
	 *
	 * @return array Feature settings array.
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_email_configure';
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Check if email logging is enabled in settings.
	 *
	 * @return array Status array with parent and feature enablement.
	 */
	public function wptw_email_log_is_enabled() {
		$feature_settings = $this->get_features_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$any_feature_enabled = false;
		for ( $i = 1; $i <= 2; $i++ ) {
			$field_key = 'field_' . $i;
			if (
				isset( $feature_settings[ $field_key ]['options']['option']['selected'] ) &&
				true === $feature_settings[ $field_key ]['options']['option']['selected']
			) {
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
	 * Check if push notification is enabled for a specific field.
	 *
	 * @param string $field_name The field name to check (e.g., 'field_1').
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public function email_log_push_notification( $field_name ) {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_email_configure';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Check if email success notifications should be sent.
	 * Used by NotificationActions for dynamic notification configuration.
	 *
	 * @return bool True if notifications should be sent for email success, false otherwise.
	 */
	public static function should_notify_email_success() {
		$controller = new self();
		return $controller->email_log_push_notification( 'field_1' );
	}

	/**
	 * Check if email failure notifications should be sent.
	 * Used by NotificationActions for dynamic notification configuration.
	 *
	 * @return bool True if notifications should be sent for email failure, false otherwise.
	 */
	public static function should_notify_email_failed() {
		$controller = new self();
		return $controller->email_log_push_notification( 'field_2' );
	}

	/**
	 * Check if SMTP-runtime-failure notifications should be sent.
	 * Used by NotificationActions for SMTP connection / authentication errors.
	 *
	 * Gated by field_3 (smtp_provider) since SMTP runtime errors are tied to
	 * the SMTP configuration itself, distinct from generic wp_mail failures.
	 *
	 * @return bool True if notifications should be sent for SMTP runtime failures, false otherwise.
	 */
	public static function should_notify_smtp_failed() {
		$controller = new self();
		return $controller->email_log_push_notification( 'field_3' );
	}

	/**
	 * Capture email headers from wp_mail filter.
	 *
	 * @param array $args The wp_mail arguments.
	 *
	 * @return array The original arguments.
	 */
	public function capture_email_headers( $args ) {
		// Capture headers for later use in log methods.
		$this->email_headers = $args['headers'];
		return $args;
	}

	/**
	 * Log failed email attempts.
	 *
	 * @param \WP_Error $wp_error The WordPress error object.
	 *
	 * @return void
	 */
	public function log_email_error( $wp_error ) {
		// Prevent recursive logging when our own notification emails trigger wp_mail_failed.
		if ( self::$is_logging ) {
			return;
		}

		self::$is_logging = true;

		try {
			if ( empty( $wp_error ) || ! is_wp_error( $wp_error ) ) {
				Log::warning(
					'Email error logging failed: Invalid error object',
					array(
						'feature' => 'email_smtp',
						'action'  => 'email_error_log_failed',
						'error'   => 'Invalid WP_Error object provided to log_email_error',
					)
				);
				return;
			}

			$get_feature   = $this->get_features_options();
			$error_data    = $wp_error->get_error_data();
			$error_message = $wp_error->get_error_message();
			global $phpmailer;
			$delivery_url = isset( $phpmailer ) && isset( $phpmailer->Host ) && isset( $phpmailer->Port ) ? $phpmailer->Host . ':' . $phpmailer->Port : '';

			$to          = isset( $error_data['to'] ) ? implode( ', ', (array) $error_data['to'] ) : '';
			$subject     = isset( $error_data['subject'] ) ? $error_data['subject'] : '';
			$message     = isset( $error_data['message'] ) ? $error_data['message'] : '';
			$headers     = $this->email_headers;
			$attachments = isset( $error_data['attachments'] ) ? implode( ', ', (array) $error_data['attachments'] ) : '';

			if ( ! empty( $get_feature ) ) {
				if ( isset( $get_feature['field_2']['options']['option']['selected'] ) && $get_feature['field_2']['options']['option']['selected'] ) {

					// Sanitize $_SERVER values according to WordPress coding standards.
					$server_name = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
					$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
					$user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
					$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

					$data = array(
						'subject'         => $subject,
						'sent_to'         => $to,
						'delivery_time'   => current_time( 'mysql' ),
						'error_message'   => $error_message,
						'status'          => 'failed',
						'headers'         => $headers,
						'delivery_url'    => $delivery_url,
						'message'         => $message,
						'attachments'     => $attachments,
						'error'           => $error_message,
						'phpmailer_error' => ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) ? $phpmailer->ErrorInfo : '',
						'server'          => $server_name,
						'request_uri'     => $request_uri,
						'user_agent'      => $user_agent,
						'ip'              => $remote_addr,
					);

					$monitoring_log = new MonitoringLogController();
					$email_record_id = $monitoring_log->wptw_send_log_report( $data, 'default_email_logs', 'failed_email_log', null, '', array( 'facet_1' => $to ) );

					// Log email failure using unified logging system.
					// Headers / attachments / email_data are kept in the log
					// context (DB row) but NOT mirrored into meta_data — too
					// noisy and may contain PII. request_uri excluded from
					// meta_data because query strings can carry tokens.
					Log::error(
						'Your email could not be delivered via SMTP. This may be due to incorrect credentials, server blocking, or recipient issues. Check logs or contact support.',
						array(
							'feature'         => 'email_smtp',
							'action'          => 'email_send_failed',
							'title'           => 'Email Delivery Failed',
							'subject'         => $subject,
							'sent_to'         => $to,
							'error_message'   => $error_message,
							'delivery_url'    => $delivery_url,
							'headers'         => $headers,
							'attachments'     => $attachments,
							'phpmailer_error' => ( isset( $phpmailer ) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) ? $phpmailer->ErrorInfo : '',
							'server'          => $server_name,
							'request_uri'     => $request_uri,
							'user_agent'      => $user_agent,
							'ip'              => $remote_addr,
							'email_data'      => $data,
							'meta_data'       => array(
								'feature'      => 'Email/SMTP Logs',
								'event'        => 'Failed',
								'recipient'    => $to,
								'record_id'    => $email_record_id,
								'subject'      => $subject,
								'delivery_url' => $delivery_url,
								'server'       => $server_name,
								'reason'       => 'Delivery failed',
							),
						)
					);
				} else {
					Log::debug(
						'Email error logging disabled',
						array(
							'feature' => 'email_smtp',
							'action'  => 'email_error_log_disabled',
							'message' => __( 'Email error logging feature is disabled in settings', 'tailwatch' ),
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Email error logging failed: Exception occurred',
				array(
					'feature'   => 'email_smtp',
					'action'    => 'email_error_log_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		} finally {
			self::$is_logging = false;
		}
	}

	/**
	 * Log successful email attempts.
	 *
	 * Hooked to 'wp_mail_succeeded' action. Uses re-entrancy guard to prevent
	 * recursive loops when notification emails trigger this same hook.
	 *
	 * @param array $args The email arguments (to, subject, message, etc.).
	 *
	 * @return void
	 */
	public function log_email_activity( $args ) {
		// Prevent recursive logging when our own notification emails trigger wp_mail_succeeded.
		if ( self::$is_logging ) {
			return;
		}

		self::$is_logging = true;

		try {
			if ( empty( $args ) || ! is_array( $args ) ) {
				Log::warning(
					'Email activity logging failed: Invalid arguments',
					array(
						'feature' => 'email_smtp',
						'action'  => 'email_activity_log_failed',
						'error'   => 'Invalid arguments provided to log_email_activity',
					)
				);
				return;
			}

			$get_feature = $this->get_features_options();
			if ( empty( $get_feature ) ) {
				Log::warning(
					'Email activity logging failed: Feature settings unavailable',
					array(
						'feature' => 'email_smtp',
						'action'  => 'email_activity_log_failed',
						'error'   => 'Unable to retrieve email log feature settings',
					)
				);
				return;
			}

			global $phpmailer;
			$delivery_url = isset( $phpmailer ) && isset( $phpmailer->Host ) && isset( $phpmailer->Port ) ? $phpmailer->Host . ':' . $phpmailer->Port : '';

			$sent_to     = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];
			$subject     = isset( $args['subject'] ) ? $args['subject'] : '';
			$message     = isset( $args['message'] ) ? $args['message'] : '';
			$headers     = $this->email_headers;
			$attachments = isset( $args['attachments'] ) && is_array( $args['attachments'] ) ? implode( ', ', $args['attachments'] ) : ( isset( $args['attachments'] ) ? $args['attachments'] : '' );

			if ( isset( $get_feature['field_1']['options']['option']['selected'] ) && $get_feature['field_1']['options']['option']['selected'] ) {
				// Sanitize $_SERVER values according to WordPress coding standards.
				$server_name = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
				$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

				$data = array(
					'subject'       => $subject,
					'sent_to'       => $sent_to,
					'delivery_time' => current_time( 'mysql' ),
					'status'        => 'Success',
					'headers'       => $headers,
					'delivery_url'  => $delivery_url,
					'message'       => $message,
					'attachments'   => $attachments,
					'server'        => $server_name,
					'request_uri'   => $request_uri,
					'user_agent'    => $user_agent,
					'ip'            => $remote_addr,
				);

				$monitoring_log = new MonitoringLogController();
				$email_record_id = $monitoring_log->wptw_send_log_report( $data, 'default_email_logs', 'success_email_log', null, '', array( 'facet_1' => $sent_to ) );

				// Log email success using unified logging system.
				// meta_data carries only the minimum the mobile app needs for
				// the success card; headers / attachments / full data stay in
				// the log row only.
				Log::info(
					'Your email was successfully sent via SMTP and delivered to the recipient.',
					array(
						'feature'      => 'email_smtp',
						'action'       => 'email_send_success',
						'title'        => 'Email Sent',
						'subject'      => $subject,
						'sent_to'      => $sent_to,
						'delivery_url' => $delivery_url,
						'headers'      => $headers,
						'attachments'  => $attachments,
						'email_data'   => $data,
						'meta_data'    => array(
							'feature' => 'Email/SMTP Logs',
							'event'   => 'Sent',
							'recipient'    => $sent_to,
							'record_id'    => $email_record_id,
							'subject'      => $subject,
							'delivery_url' => $delivery_url,
						),
					)
				);
			} else {
				Log::debug(
					'Email activity logging disabled',
					array(
						'feature' => 'email_smtp',
						'action'  => 'email_activity_log_disabled',
						'message' => __( 'Email activity logging feature is disabled in settings', 'tailwatch' ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Email activity logging failed: Exception occurred',
				array(
					'feature'   => 'email_smtp',
					'action'    => 'email_activity_log_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		} finally {
			self::$is_logging = false;
		}
	}
}
