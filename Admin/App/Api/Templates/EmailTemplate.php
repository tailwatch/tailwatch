<?php
/**
 * Email Template
 *
 * Builds the HTML body for the SMTP configuration test email. All dynamic values
 * are escaped at output; inline CSS is used deliberately because email clients do
 * not load external or enqueued stylesheets.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Templates
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Templates;

defined( 'ABSPATH' ) || exit;

class EmailTemplate {

	/**
	 * Build the SMTP test email HTML.
	 *
	 * @param string $site_name    Site name.
	 * @param string $site_url      Site URL.
	 * @param string $admin_email   Admin email.
	 * @param string $current_date  Formatted date.
	 * @param string $current_time  Formatted time.
	 * @param array  $provider_details Provider display details (provider_name, from_name, from_email).
	 * @return string HTML email body.
	 */
	public function tailwatch_test_email_template( $site_name, $site_url, $admin_email, $current_date, $current_time, $provider_details = array() ) {
		$provider_name = isset( $provider_details['provider_name'] ) ? $provider_details['provider_name'] : 'Unknown';
		$from_name     = isset( $provider_details['from_name'] ) ? $provider_details['from_name'] : 'N/A';
		$from_email    = isset( $provider_details['from_email'] ) ? $provider_details['from_email'] : 'N/A';

		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html__( 'Tailwatch SMTP Test', 'tailwatch' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
					line-height: 1.6;
					color: #374151;
					max-width: 600px;
					margin: 0 auto;
					padding: 0;
					background-color: #f3f4f6;
				}
				.wrapper {
					background-color: #ffffff;
					border-radius: 8px;
					overflow: hidden;
					margin: 20px auto;
					box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
				}
				.header {
					background-color: #1e40af;
					background-image: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
					color: white;
					padding: 25px 30px;
					text-align: center;
				}
				.header h1 {
					margin: 0;
					font-size: 28px;
					font-weight: 700;
					letter-spacing: -0.025em;
				}
				.header p {
					margin: 8px 0 0;
					opacity: 0.9;
					font-size: 16px;
				}
				.content {
					background-color: #ffffff;
					padding: 30px;
				}
				.content h2 {
					color: #111827;
					font-size: 22px;
					font-weight: 600;
					margin-top: 0;
					margin-bottom: 16px;
				}
				.content p {
					margin: 16px 0;
					font-size: 16px;
					color: #4b5563;
				}
				.details {
					background-color: #f9fafb;
					border: 1px solid #e5e7eb;
					padding: 20px;
					margin: 24px 0;
					border-radius: 6px;
				}
				.details h3 {
					color: #111827;
					font-size: 18px;
					font-weight: 600;
					margin-top: 0;
					margin-bottom: 16px;
				}
				table {
					width: 100%;
					border-collapse: collapse;
				}
				table td {
					padding: 12px 8px;
					font-size: 14px;
					border-bottom: 1px solid #e5e7eb;
					color: #4b5563;
				}
				table tr:last-child td {
					border-bottom: none;
				}
				table td:first-child {
					font-weight: 500;
					color: #111827;
					width: 40%;
				}
				.status-container {
					text-align: center;
					margin: 20px 0 10px;
				}
				.status-badge {
					background-color: #10b981;
					color: white;
					padding: 8px 16px;
					border-radius: 50px;
					display: inline-block;
					font-weight: 500;
					font-size: 14px;
				}
				.next-steps {
					margin-top: 24px;
					background-color: #eff6ff;
					border-left: 4px solid #3b82f6;
					padding: 16px 20px;
					border-radius: 4px;
				}
				.next-steps h3 {
					color: #1e40af;
					margin-top: 0;
					margin-bottom: 8px;
					font-size: 16px;
					font-weight: 600;
				}
				.next-steps ul {
					margin: 0;
					padding-left: 20px;
				}
				.next-steps li {
					margin: 6px 0;
					color: #4b5563;
					font-size: 14px;
				}
				.footer {
					background-color: #f9fafb;
					padding: 20px 30px;
					text-align: center;
					font-size: 13px;
					color: #6b7280;
					border-top: 1px solid #e5e7eb;
				}
				.footer a {
					color: #3b82f6;
					text-decoration: none;
				}
				.footer p {
					margin: 5px 0;
				}
				.logo {
					display: block;
					margin: 0 auto 10px;
					width: 48px;
					height: 48px;
				}
				.divider {
					height: 1px;
					background-color: #e5e7eb;
					margin: 20px 0;
				}
			</style>
		</head>
		<body>
			<div class="wrapper">
				<div class="header">
					<svg class="logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M12 2L2 7l10 5 10-5-10-5z"></path>
						<path d="M2 17l10 5 10-5"></path>
						<path d="M2 12l10 5 10-5"></path>
					</svg>
					<h1><?php echo esc_html__( 'Tailwatch', 'tailwatch' ); ?></h1>
					<p><?php echo esc_html__( 'Email Configuration Test', 'tailwatch' ); ?></p>
				</div>
				<div class="content">
					<h2><?php echo esc_html__( 'SMTP Configuration Test', 'tailwatch' ); ?></h2>
					<p>
					<?php
						printf(
							/* translators: 1: SMTP provider name, 2: sender name, 3: sender email address. */
							esc_html__( 'Congratulations! If you are reading this message, your SMTP configuration for Tailwatch is working correctly. This confirms that your WordPress site can successfully send emails using the %1$s provider with the sender %2$s <%3$s>.', 'tailwatch' ),
							'<strong>' . esc_html( $provider_name ) . '</strong>',
							'<strong>' . esc_html( $from_name ) . '</strong>',
							esc_html( $from_email )
						);
						?>
					</p>

					<div class="details">
						<h3><?php echo esc_html__( 'Test Details', 'tailwatch' ); ?></h3>
						<table>
							<tr>
								<td><?php echo esc_html__( 'Provider:', 'tailwatch' ); ?></td>
								<td><?php echo esc_html( $provider_name ); ?></td>
							</tr>
							<tr>
								<td><?php echo esc_html__( 'From Name:', 'tailwatch' ); ?></td>
								<td><?php echo esc_html( $from_name ); ?></td>
							</tr>
							<tr>
								<td><?php echo esc_html__( 'From Email:', 'tailwatch' ); ?></td>
								<td><?php echo esc_html( $from_email ); ?></td>
							</tr>
							<tr>
								<td><?php echo esc_html__( 'Website Name:', 'tailwatch' ); ?></td>
								<td><?php echo esc_html( $site_name ); ?></td>
							</tr>
							<tr>
								<td><?php echo esc_html__( 'Website URL:', 'tailwatch' ); ?></td>
								<td><a href="<?php echo esc_url( $site_url ); ?>" style="color: #3b82f6; text-decoration: none;"><?php echo esc_html( $site_url ); ?></a></td>
							</tr>
							<tr>
								<td><?php echo esc_html__( 'Admin Email:', 'tailwatch' ); ?></td>
								<td><?php echo esc_html( $admin_email ); ?></td>
							</tr>
							<tr>
								<td><?php echo esc_html__( 'Test Date:', 'tailwatch' ); ?></td>
								<td><?php echo esc_html( $current_date ) . ' ' . esc_html__( 'at', 'tailwatch' ) . ' ' . esc_html( $current_time ); ?></td>
							</tr>
						</table>

						<div class="status-container">
							<div class="status-badge">
								<?php echo esc_html__( 'Email Delivery Successful', 'tailwatch' ); ?>
							</div>
						</div>
					</div>

					<div class="next-steps">
						<h3><?php echo esc_html__( 'Next Steps', 'tailwatch' ); ?></h3>
						<ul>
							<li><?php echo esc_html__( 'Configure your preferred email settings in the Tailwatch dashboard', 'tailwatch' ); ?></li>
							<li><?php echo esc_html__( 'Test your form submissions to ensure proper delivery', 'tailwatch' ); ?></li>
							<li><?php echo esc_html__( 'Set up notification preferences for important site events', 'tailwatch' ); ?></li>
						</ul>
					</div>

					<div class="divider"></div>

					<p>
					<?php
						printf(
							/* translators: %s: SMTP provider name. */
							esc_html__( 'This test confirms that your WordPress site can send emails through the configured %s SMTP provider. You can now proceed with configuring your email notification preferences and other settings in the Tailwatch dashboard.', 'tailwatch' ),
							'<strong>' . esc_html( $provider_name ) . '</strong>'
						);
						?>
					</p>
				</div>
				<div class="footer">
					<p><?php echo esc_html__( 'This is an automated message from the Tailwatch plugin.', 'tailwatch' ); ?></p>
					<p><?php echo esc_html__( 'Please do not reply to this email as it was sent for testing purposes only.', 'tailwatch' ); ?></p>
					<p>
						<?php
						printf(
							/* translators: %s: current year. */
							esc_html__( '© %s Tailwatch', 'tailwatch' ),
							esc_html( gmdate( 'Y' ) )
						);
						?>
						| <a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_html__( 'Visit Website', 'tailwatch' ); ?></a>
					</p>
				</div>
			</div>
		</body>
		</html>
		<?php

		return ob_get_clean();
	}
}
