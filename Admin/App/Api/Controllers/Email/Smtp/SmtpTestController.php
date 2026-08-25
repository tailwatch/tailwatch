<?php
/**
 * SMTP Test Controller
 *
 * Sends a single SMTP configuration test email through the site's currently
 * configured provider and reports success or the transport error. Reached only via
 * the authenticated dispatcher (wp-admin nonce + manage_options, or Connect JWT).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Email\Smtp
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Email\Smtp;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Templates\EmailTemplate;
use Tailwatch\Admin\App\Api\Logging\Log;

class SmtpTestController {

	/**
	 * Send an SMTP configuration test email.
	 *
	 * @param string $post_data JSON payload carrying test_email.
	 * @return array Response with code, data, message.
	 */
	public function tailwatch_smtp_test_email( $post_data = '' ) {
		try {
			$json_data  = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data       = json_decode( $json_data, true );
			$test_email = isset( $data['test_email'] ) ? sanitize_email( $data['test_email'] ) : '';

			if ( empty( $test_email ) || ! is_email( $test_email ) ) {
				Log::error(
					'Test email failed due to invalid or empty email address',
					array(
						'feature'  => 'email_smtp',
						'action'   => 'smtp_test_email_send_failed',
						'detail'   => 'Test email failed due to invalid or empty email address.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'Please provide a valid email address.', 'tailwatch' ),
				);
			}

			$smtp_config = new SmtpConfigurationController();
			$settings    = $smtp_config->tailwatch_get_smtp_configure_settings();
			if ( empty( $settings->smtp_provider ) ) {
				Log::error(
					'Test email failed because no SMTP provider is configured',
					array(
						'feature'  => 'email_smtp',
						'action'   => 'smtp_test_email_send_failed',
						'detail'   => 'Test email failed because no SMTP provider is configured.',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return array(
					'code'    => 400,
					'data'    => array(),
					'message' => __( 'No SMTP provider configured.', 'tailwatch' ),
				);
			}

			$provider_map = array(
				'default' => 'Default',
				'custom'  => 'Custom',
				'gmail'   => 'Gmail',
			);

			$provider_key     = $settings->smtp_provider;
			$provider_name    = isset( $provider_map[ $provider_key ] ) ? $provider_map[ $provider_key ] : 'Unknown Provider';
			$provider_details = array(
				'provider_name' => $provider_name,
				'from_email'    => '',
				'from_name'     => '',
			);

			switch ( $provider_key ) {
				case 'default':
					$provider_details['from_email'] = $settings->default->smtp_from_email ?? 'N/A';
					$provider_details['from_name']  = $settings->default->smtp_from_name ?? 'N/A';
					break;
				case 'custom':
					$provider_details['from_email'] = $settings->custom->smtp_from_email ?? 'N/A';
					$provider_details['from_name']  = $settings->custom->smtp_from_name ?? 'N/A';
					break;
				case 'gmail':
					$provider_details['from_email'] = $settings->gmail->smtp_from_email ?? 'N/A';
					$provider_details['from_name']  = $settings->gmail->smtp_from_name ?? 'N/A';
					break;
				default:
					$provider_details['from_email'] = 'N/A';
					$provider_details['from_name']  = 'N/A';
			}

			$site_name    = get_bloginfo( 'name' );
			$site_url     = get_bloginfo( 'url' );
			$admin_email  = get_option( 'admin_email' );
			$current_date = gmdate( 'F j, Y' );
			$current_time = gmdate( 'H:i:s' );
			$subject      = __( 'Tailwatch SMTP Configuration Test', 'tailwatch' );

			$email_template = new EmailTemplate();
			$message        = $email_template->tailwatch_test_email_template(
				$site_name,
				$site_url,
				$admin_email,
				$current_date,
				$current_time,
				$provider_details
			);

			// Strip CR/LF: defense in depth against header injection via saved From settings.
			$from_name_clean  = sanitize_text_field( str_replace( array( "\r", "\n" ), '', (string) $provider_details['from_name'] ) );
			$from_email_clean = sanitize_email( (string) $provider_details['from_email'] );
			$headers          = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $from_name_clean . ' <' . $from_email_clean . '>',
			);

			$result = wp_mail( $test_email, $subject, $message, $headers );

			if ( $result ) {
				Log::info(
					'Test email sent successfully using ' . $provider_name . ' provider',
					array(
						'feature' => 'email_smtp',
						'action'  => 'smtp_test_email_sent',
						'origin'  => 'system',
					)
				);
				return array(
					'code'    => 200,
					'data'    => array(),
					// translators: %s is the recipient email address.
					'message' => sprintf( __( 'Test email sent successfully to %s!', 'tailwatch' ), $test_email ),
				);
			}

			global $phpmailer;
			$error = ( $phpmailer && isset( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : 'Unknown error';

			Log::error(
				'Test email failed to send using ' . $provider_name . ' provider. Error: ' . $error,
				array(
					'feature'  => 'email_smtp',
					'action'   => 'smtp_test_email_send_failed',
					'detail'   => 'Test email failed to send using ' . $provider_name . ' provider.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);

			return array(
				'code'    => 500,
				'data'    => array(),
				// translators: %s is the transport error message.
				'message' => sprintf( __( 'Test email failed: %s', 'tailwatch' ), $error ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception occurred during SMTP test email process: ' . $e->getMessage(),
				array(
					'feature'  => 'email_smtp',
					'action'   => 'smtp_test_email_send_failed',
					'detail'   => 'Exception occurred during SMTP test email process.',
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Test email failed due to an unexpected error.', 'tailwatch' ),
			);
		}
	}
}
