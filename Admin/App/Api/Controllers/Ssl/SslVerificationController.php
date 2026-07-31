<?php
/**
 * Enhanced SSL Verification Controller
 *
 * Handles comprehensive SSL verification including certificate details,
 * expiration monitoring, and security grading.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Ssl
 */

namespace Tailwatch\Admin\App\Api\Controllers\Ssl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Enhanced SSL Verification Controller
 *
 */
class SslVerificationController extends BaseController {
	private $feature        = 'ssl_verification';
	private $settings_cache = null;

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wptw_trigger_ssl_expiry_notice', array( $this, 'wptw_trigger_ssl_expiry_alert' ) );
	}

	/**
	 * Get feature status (required by BaseController)
	 *
	 * @return array
	 */
	protected function wptw_get_feature_status(): array {
		return $this->wptw_ssl_is_enable();
	}

	/**
	 * Validate domain format
	 *
	 * @param string $domain Domain to validate
	 * @return bool True if valid, false otherwise
	 */
	private function validate_domain( string $domain ): bool {
		if ( empty( $domain ) ) {
			return false;
		}

		// Remove port if present
		$domain = preg_replace( '/:\d+$/', '', $domain );

		// Validate domain format using filter_var
		if ( ! filter_var( $domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			// Allow localhost and IP addresses for development
			if ( $domain === 'localhost' || filter_var( $domain, FILTER_VALIDATE_IP ) ) {
				return true;
			}
			return false;
		}

		// Additional length check (RFC 1035: max 253 characters)
		if ( strlen( $domain ) > 253 ) {
			return false;
		}

		return true;
	}

	/**
	 * Helper to get feature status and value
	 *
	 * @param array|string $field_name Path to the field
	 * @return array
	 */
	public function wptw_ssl_is_enable( $field_name = array( 'field_1' ) ): array {
		// Validate input
		$field_name = (array) $field_name;
		if ( empty( $field_name ) ) {
			Log::warning(
				'SSL feature check failed: Empty field path',
				array(
					'feature' => 'ssl_verification',
					'action'  => 'ssl_feature_check_failed',
					'error'   => 'Field path cannot be empty',
				)
			);
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
				'feature_value'  => null,
			);
		}

		$feature_enable = $this->get_features_options();

		if ( empty( $feature_enable ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
				'feature_value'  => null,
			);
		}

		$current = $feature_enable;

		foreach ( $field_name as $key ) {
			if ( ! isset( $current[ $key ] ) ) {
				return array(
					'parent_enable'  => true,
					'feature_enable' => false,
					'feature_value'  => null,
				);
			}
			$current = $current[ $key ];
		}

		// Handle options container (support both 'options' and 'values' keys)
		$options_container = $current['options'] ?? $current['values'] ?? null;
		$type              = $current['type'] ?? '';

		$is_enabled = false;
		$value      = null;

		if ( $options_container && is_array( $options_container ) ) {
			foreach ( $options_container as $opt ) {
				// For text/input fields, we take the value directly
				if ( $type === 'text' || $type === 'input' ) {
					$value      = $opt['value'] ?? null;
					$is_enabled = true;
					break;
				}

				// For checkbox/radio/select, check 'selected' status
				if ( isset( $opt['selected'] ) && $opt['selected'] === true ) {
					$is_enabled = true;
					$value      = $opt['value'] ?? null;
					break; // Return first selected option
				}
			}
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => $is_enabled,
			'feature_value'  => $value,
		);
	}

	/**
	 * Get features options with caching
	 *
	 * @return array
	 */
	public function get_features_options(): array {
		if ( $this->settings_cache !== null ) {
			return $this->settings_cache;
		}
		$key                  = 'default_feature_settings';
		$option               = 'default_verify_ssl';
		$is_active            = 1;
		$options_controller   = new OptionsController();
		$this->settings_cache = $options_controller->get_features_options( $key, $option, $is_active );
		return $this->settings_cache;
	}

	/**
	 * Insert SSL status into database
	 *
	 * @param array $ssl_data SSL status data to insert
	 * @return void
	 * @throws \InvalidArgumentException If required keys are missing
	 */
	public function wptw_insert_ssl_status( array $ssl_data ): void {
		// Validate required keys
		$required_keys = array(
			'ssl_connected',
			'expiry_time',
			'https_redirect',
			'expiry_alert_attempt',
			'last_run',
			'message',
		);

		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $ssl_data ) ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for developers, key is from internal array
				throw new \InvalidArgumentException( sprintf( 'Missing required key in SSL data: %s', esc_html( $key ) ) );
			}
		}

		// Validate and normalize types
		if ( ! isset( $ssl_data['ssl_connected'] ) ) {
			throw new \InvalidArgumentException( 'Missing ssl_connected in SSL data' );
		}
		$ssl_data['ssl_connected'] = (bool) $ssl_data['ssl_connected'];

		if ( ! isset( $ssl_data['expiry_alert_attempt'] ) ) {
			throw new \InvalidArgumentException( 'Missing expiry_alert_attempt in SSL data' );
		}
		$ssl_data['expiry_alert_attempt'] = (int) $ssl_data['expiry_alert_attempt'];

		$db_data = array(
			'user_id'       => get_current_user_id(),
			'child_of'      => 0,
			'key'           => 'default_ssl_verification',
			'option'        => 'ssl_verification_status',
			'value'         => wp_json_encode( $ssl_data ),
			'type'          => 'JSON',
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		try {
			$db_model = new DBModel();
			$result   = $db_model->insert_row( $db_data, $db_data_format );

			if ( ! $result ) {
				Log::error(
					'Failed to insert SSL status into database',
					array(
						'feature' => 'ssl_verification',
						'action'  => 'ssl_status_insert_failed',
					)
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception while inserting SSL status',
				array(
					'feature'   => 'ssl_verification',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
			throw $e;
		}
	}

	/**
	 * Get SSL verification status from database
	 *
	 * @return array|null
	 */
	public function wptw_get_ssl_verification_status(): ?array {
		$db_model      = new DBModel();
		$wptw_key      = 'default_ssl_verification';
		$option        = 'ssl_verification_status';
		$existing_data = $db_model->get_recent_data( $option, $wptw_key );
		return $existing_data;
	}

	/**
	 * Update SSL verification status in database
	 *
	 * @param array $options SSL status data to update
	 * @return void
	 * @throws \InvalidArgumentException If options is empty
	 */
	public function update_ssl_verification_status( array $options ): void {
		if ( empty( $options ) ) {
			throw new \InvalidArgumentException( 'Options array cannot be empty' );
		}

		$db_model = new DBModel();
		$wptw_key = 'default_ssl_verification';
		$option   = 'ssl_verification_status';

		$db_data = array(
			'value'         => wp_json_encode( $options ),
			'date_modified' => current_time( 'mysql' ),
		);

		$db_model->update_recent_row( $db_data, $wptw_key, $option );
	}

	/**
	 * ENHANCED SSL Verification with comprehensive security checks
	 *
	 * @return array
	 */
	public function wptw_ssl_verification(): array {
		try {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( empty( $domain ) || ! $this->validate_domain( $domain ) ) {
				Log::warning(
					'SSL verification failed: Invalid or empty domain',
					array(
						'feature' => 'ssl_verification',
						'action'  => 'ssl_verification_failed',
						'domain'  => $domain,
						'error'   => empty( $domain ) ? 'Site home URL host could not be determined' : 'Invalid domain format',
						'title'     => 'SSL Verification Failed',
						'meta_data' => array(
							'feature' => 'Smart SSL',
							'event'   => 'Check failed',
							'domain' => $domain,
							'reason' => empty( $domain ) ? 'empty_domain' : 'invalid_domain',
						),
					)
				);
				return array(
					'code'    => 400,
					'message' => empty( $domain ) ? 'Unable to determine domain for SSL verification' : 'Invalid domain format for SSL verification',
				);
			}

			$ssl_status = array(
				'ssl_connected'        => false,
				'expiry_time'          => null,
				'https_redirect'       => false,
				'expiry_alert_attempt' => 0,
				'last_run'             => current_time( 'mysql' ),
				'message'              => '',

				// Enhanced certificate details
				'certificate_details'  => array(
					'issuer'              => null,
					'subject'             => null,
					'signature_algorithm' => null,
					'key_size'            => null,
					'key_type'            => null,
					'is_self_signed'      => false,
					'is_wildcard'         => false,
					'san_domains'         => array(),
					'issued_date'         => null,
					'days_until_expiry'   => null,
					'is_valid'            => false,
					'chain_valid'         => false,
				),

				// Security assessment
				'security_score'       => array(
					'grade'    => null,
					'issues'   => array(),
					'warnings' => array(),
				),
			);

			// Secure SSL context with peer verification
			$stream_context = stream_context_create(
				array(
					'ssl' => array(
						'capture_peer_cert'       => true,
						'capture_peer_cert_chain' => true,
						'verify_peer'             => true,
						'verify_peer_name'        => true,
						'SNI_enabled'             => true,
						'disable_compression'     => true,
						'peer_name'               => $domain,
					),
				)
			);

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- $errno/$errstr and the return value are checked below; suppressed to avoid host-level TLS handshake warnings leaking into output.
			$client = @stream_socket_client(
				"ssl://{$domain}:443",
				$errno,
				$errstr,
				30,
				STREAM_CLIENT_CONNECT,
				$stream_context
			);

			if ( $client ) {
				$ssl_status['ssl_connected'] = true;
				$params                      = stream_context_get_params( $client );

				// Get TLS/Cipher info
				$crypto_meta                = stream_get_meta_data( $client );
				$ssl_status['tls_version']  = $crypto_meta['crypto']['protocol'] ?? 'unknown';
				$ssl_status['cipher_suite'] = $crypto_meta['crypto']['cipher_name'] ?? 'unknown';

				// Get certificate and chain
				$peer_certificate = $params['options']['ssl']['peer_certificate'] ?? null;
				$peer_cert_chain  = $params['options']['ssl']['peer_certificate_chain'] ?? array();

				if ( $peer_certificate ) {
					$cert_info = openssl_x509_parse( $peer_certificate );

					if ( $cert_info ) {
						// Basic certificate info
						$expiry_time               = gmdate( 'Y-m-d H:i:s', $cert_info['validTo_time_t'] );
						$issued_time               = gmdate( 'Y-m-d H:i:s', $cert_info['validFrom_time_t'] );
						$ssl_status['expiry_time'] = $expiry_time;

						// Calculate days until expiry
						$days_until_expiry = floor( ( $cert_info['validTo_time_t'] - time() ) / 86400 );

						// Get certificate fingerprint
						$fingerprint = openssl_x509_fingerprint( $peer_certificate, 'sha256' );

						// Check revocation (OCSP URI presence)
						$revocation_info = $this->check_certificate_revocation( $peer_certificate );

						// Enhanced certificate details
						$ssl_status['certificate_details'] = array(
							'issuer'               => $cert_info['issuer']['CN'] ?? 'Unknown',
							'issuer_organization'  => $cert_info['issuer']['O'] ?? null,
							'subject'              => $cert_info['subject']['CN'] ?? $domain,
							'subject_organization' => $cert_info['subject']['O'] ?? null,
							'signature_algorithm'  => $cert_info['signatureTypeSN'] ?? 'Unknown',
							'issued_date'          => $issued_time,
							'expiry_date'          => $expiry_time,
							'days_until_expiry'    => $days_until_expiry,
							'serial_number'        => $cert_info['serialNumber'] ?? null,
							'fingerprint_sha256'   => $fingerprint,
							'revocation_check'     => $revocation_info,
						);

						// Check if self-signed
						$is_self_signed                                      = (
							isset( $cert_info['issuer']['CN'] ) &&
							isset( $cert_info['subject']['CN'] ) &&
							$cert_info['issuer']['CN'] === $cert_info['subject']['CN']
						);
						$ssl_status['certificate_details']['is_self_signed'] = $is_self_signed;

						// Check if wildcard certificate
						$subject_cn                                       = $cert_info['subject']['CN'] ?? '';
						$ssl_status['certificate_details']['is_wildcard'] = ( strpos( $subject_cn, '*.' ) === 0 );

						// Extract SAN (Subject Alternative Names)
						if ( isset( $cert_info['extensions']['subjectAltName'] ) ) {
							$san_raw     = $cert_info['extensions']['subjectAltName'];
							$san_domains = array_map(
								function ( $item ) {
									return str_replace( 'DNS:', '', trim( $item ) );
								},
								explode( ',', $san_raw )
							);
							$ssl_status['certificate_details']['san_domains'] = $san_domains;
						}

						// Connection succeeded with verify_peer=true, so the certificate is valid.
						$ssl_status['certificate_details']['is_valid'] = true;

						// Validate certificate chain
						$chain_count                                       = count( $peer_cert_chain );
						$ssl_status['certificate_details']['chain_valid']  = ( $chain_count > 0 );
						$ssl_status['certificate_details']['chain_length'] = $chain_count;

						// Get public key details
						$pubkey = openssl_pkey_get_public( $peer_certificate );
						if ( $pubkey ) {
							$key_details                                   = openssl_pkey_get_details( $pubkey );
							$ssl_status['certificate_details']['key_size'] = $key_details['bits'] ?? null;
							$ssl_status['certificate_details']['key_type'] = $this->get_key_type( $key_details['type'] ?? null );
							// Note: openssl_pkey_free() is deprecated in PHP 8.0+ and no longer needed
							// PHP 8+ automatically frees the key when it goes out of scope
						}

						// Security assessment
						$ssl_status = $this->assess_certificate_security( $ssl_status );

						// Set appropriate message
						if ( $is_self_signed ) {
							$ssl_status['message'] = "SSL connected but certificate is self-signed. Expiry: {$expiry_time}.";
						} elseif ( ! $ssl_status['certificate_details']['is_valid'] ) {
							$ssl_status['message'] = "SSL connected but certificate purpose validation failed. Expiry: {$expiry_time}.";
						} else {
							$grade                 = $ssl_status['security_score']['grade'] ?? 'Unknown';
							$ssl_status['message'] = "SSL is valid (Grade: {$grade}). Expires: {$expiry_time} ({$days_until_expiry} days).";
						}
					} else {
						$ssl_status['message'] = 'SSL connected but certificate details could not be parsed.';
						Log::warning(
							'SSL verification warning: Certificate details could not be parsed',
							array(
								'feature' => 'ssl_verification',
								'action'  => 'ssl_verification_failed',
								'domain'  => $domain,
								'error'   => 'Certificate parsing failed',
								'title'     => 'SSL Verification Failed',
								'meta_data' => array(
									'feature' => 'Smart SSL',
									'event'   => 'Check failed',
									'domain' => $domain,
									'reason' => 'Certificate parsing failed',
								),
							)
						);
					}
				} else {
					$ssl_status['message'] = 'SSL connected but certificate could not be retrieved.';
					Log::warning(
						'SSL verification warning: Certificate could not be retrieved',
						array(
							'feature' => 'ssl_verification',
							'action'  => 'ssl_verification_failed',
							'domain'  => $domain,
							'error'   => 'Certificate retrieval failed',
							'title'     => 'SSL Verification Failed',
							'meta_data' => array(
								'feature' => 'Smart SSL',
								'event'   => 'Check failed',
								'domain' => $domain,
								'reason' => 'Certificate retrieval failed',
							),
						)
					);
				}

				// Close socket connection
				if ( is_resource( $client ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort shutdown of an open TLS socket; failure is non-fatal.
					@stream_socket_shutdown( $client, STREAM_SHUT_RDWR );
				}
			} else {
				$ssl_status['message'] = "Unable to establish SSL connection: {$errstr} (Error Code: {$errno})";

				// Log specific SSL errors
				if ( $errno === 0 ) {
					$ssl_status['message'] .= ' - Possible peer verification failure or certificate mismatch.';
				}
			}

			// Check HTTP-to-HTTPS redirection using wp_remote_head.
			// The URL is plain http:// so sslverify is not applicable here.
			$http_url = "http://{$domain}";
			$response = wp_remote_head(
				$http_url,
				array(
					'redirection' => 0,
					'timeout'     => 10,
					'headers'     => array(
						'Accept' => '*/*',
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$http_code = wp_remote_retrieve_response_code( $response );
				$headers   = wp_remote_retrieve_headers( $response );
				$redirect  = isset( $headers['location'] ) ? $headers['location'] : '';

				if ( $http_code >= 300 && $http_code < 400 && strpos( $redirect, 'https://' ) === 0 ) {
					$ssl_status['https_redirect'] = true;
				} else {
					$ssl_status['message'] .= ' HTTP to HTTPS redirection is not working.';
				}
			} else {
				$errorMessage           = $response->get_error_message();
				$ssl_status['message'] .= " Failed to check HTTP to HTTPS redirection: {$errorMessage}.";
			}

			// Update or insert SSL status
			$existing_data = $this->wptw_get_ssl_verification_status();
			if ( ! empty( $existing_data ) ) {
				// Preserve schedule-managed flag. catch_up_fired_for_expiry_ts
				// is owned by schedule_expiry_alerts (it records which cert
				// expiry timestamp the one-shot catch-up was last sent for).
				// wptw_ssl_verification builds a fresh $ssl_status that
				// doesn't carry this field, so without explicit preservation
				// the next write would clear it and the catch-up anti-spam
				// guard in schedule_expiry_alerts would re-fire every cron
				// run. Explicitly cleared on renewal below.
				if ( isset( $existing_data['catch_up_fired_for_expiry_ts'] ) ) {
					$ssl_status['catch_up_fired_for_expiry_ts'] = $existing_data['catch_up_fired_for_expiry_ts'];
				}

				// Connection-failure safeguard: a failed verification leaves
				// expiry_time/certificate_details/tls_version/etc as their
				// NULL initial values. Without this branch a transient network
				// issue would overwrite the entire stored SSL state, wiping
				// the schedule and the UI status. Preserve last-known-good
				// cert data on failure; let last_run/message/ssl_connected
				// reflect the failure so the UI still shows the disconnect.
				if ( empty( $ssl_status['ssl_connected'] ) && ! empty( $existing_data['expiry_time'] ) ) {
					$ssl_status['expiry_time']         = $existing_data['expiry_time'];
					$ssl_status['certificate_details'] = $existing_data['certificate_details'] ?? array();
					$ssl_status['tls_version']         = $existing_data['tls_version'] ?? null;
					$ssl_status['cipher_suite']        = $existing_data['cipher_suite'] ?? null;
					$ssl_status['security_score']      = $existing_data['security_score'] ?? array();
				}

				// Renewal detection: if the new expiry_time is later than the
				// stored one, the cert was renewed — reset alert_attempts to 0
				// so the next expiry cycle can fire its 3 critical alerts
				// fresh. Otherwise we'd permanently silence alerts after the
				// first cert ever expired on this domain. Also clear the
				// catch-up flag so the new cert can get its own catch-up if
				// it happens to be in a past-window state.
				$old_expiry_ts = isset( $existing_data['expiry_time'] ) ? (int) strtotime( $existing_data['expiry_time'] ) : 0;
				$new_expiry_ts = isset( $ssl_status['expiry_time'] ) ? (int) strtotime( $ssl_status['expiry_time'] ) : 0;

				if ( $new_expiry_ts > $old_expiry_ts ) {
					$ssl_status['expiry_alert_attempt'] = 0;
					unset( $ssl_status['catch_up_fired_for_expiry_ts'] );
				} else {
					$ssl_status['expiry_alert_attempt'] = $existing_data['expiry_alert_attempt'];
				}
				$this->update_ssl_verification_status( $ssl_status );
			} else {
				$this->wptw_insert_ssl_status( $ssl_status );
			}

			return $ssl_status;
		} catch ( \Throwable $e ) {
			Log::error(
				'SSL verification failed: Exception occurred',
				array(
					'feature'   => 'ssl_verification',
					'action'    => 'ssl_verification_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
					'title'     => 'SSL Verification Failed',
					'meta_data' => array(
						'feature' => 'Smart SSL',
						'event'   => 'Check failed',
						'domain' => isset( $domain ) ? $domain : '',
						'reason' => 'Unexpected error',
					),
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'SSL verification failed.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Verify SSL connection and log results
	 *
	 * @return array
	 */
	public function wptw_verify_ssl_connection(): array {
		$domain        = null;
		$ssl_connected = false;
		$expiry_time   = null;

		try {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );

			$ssl_status = $this->wptw_ssl_verification();

			if ( isset( $ssl_status['ssl_connected'] ) ) {
				$ssl_connected = $ssl_status['ssl_connected'];
			}
			if ( isset( $ssl_status['expiry_time'] ) ) {
				$expiry_time = $ssl_status['expiry_time'];
			}

			if ( ! $ssl_status['ssl_connected'] ) {
				Log::error(
					'No SSL certificate was found on your website. Your site may not be secure or may show "Not Secure" warnings.',
					array(
						'feature'      => 'ssl_verification',
						'action'       => 'ssl_verification_failed',
						'domain'       => $domain,
						'error_detail' => $ssl_status['message'] ?? 'Unable to establish SSL connection',
						'title'        => 'SSL Not Detected',
						'meta_data'    => array(
							'feature' => 'Smart SSL',
							'event'   => 'Check failed',
							'domain' => $domain,
							'reason' => 'No SSL detected',
						),
					)
				);
			} elseif ( $ssl_status['expiry_time'] && strtotime( $ssl_status['expiry_time'] ) < time() ) {
				Log::error(
					'SSL verification failed: Certificate expired',
					array(
						'feature'     => 'ssl_verification',
						'action'      => 'ssl_verification_failed',
						'domain'      => $domain,
						'expiry_time' => $expiry_time,
						'error'       => 'SSL certificate has expired',
						'title'     => 'SSL Verification Failed',
						'meta_data' => array(
							'feature' => 'Smart SSL',
							'event'   => 'Check failed',
							'domain'     => $domain,
							'expires_at' => $expiry_time,
							'reason'     => 'Certificate expired',
						),
					)
				);

				// Still schedule alerts for expired cert (for monitoring)
				$this->schedule_expiry_alerts( $ssl_status );

			} elseif ( ! $ssl_status['https_redirect'] ) {
				Log::warning(
					'SSL verification warning: HTTPS redirect not working',
					array(
						'feature'       => 'ssl_verification',
						'action'        => 'ssl_verification_warning',
						'domain'        => $domain,
						'ssl_connected' => $ssl_connected,
						'expiry_time'   => $expiry_time,
						'error'         => 'HTTP to HTTPS redirection is not working',
					)
				);

				// Schedule alerts even if redirect not working (SSL is still valid)
				$this->schedule_expiry_alerts( $ssl_status );

			} else {
				// Use the new enhanced scheduling function
				$this->schedule_expiry_alerts( $ssl_status );

				// Log successful verification.
				Log::info(
					'Your SSL certificate is active and properly configured. Your website is securely encrypted.',
					array(
						'feature'        => 'ssl_verification',
						'action'         => 'ssl_verification_completed',
						'domain'         => $domain,
						'ssl_connected'  => $ssl_connected,
						'expiry_time'    => $expiry_time,
						'https_redirect' => $ssl_status['https_redirect'] ?? false,
						'tls_version'    => $ssl_status['tls_version'] ?? 'unknown',
						'security_grade' => isset( $ssl_status['security_score']['grade'] ) ? $ssl_status['security_score']['grade'] : null,
						'title'          => 'SSL Certificate Active',
						'meta_data'      => array(
							'feature' => 'Smart SSL',
							'event'   => 'Check passed',
							'domain'         => $domain,
							'expires_at'     => $expiry_time,
							'https_redirect' => $ssl_status['https_redirect'] ?? false,
							'tls_version'    => $ssl_status['tls_version'] ?? 'unknown',
							'security_grade' => isset( $ssl_status['security_score']['grade'] ) ? $ssl_status['security_score']['grade'] : null,
							'issuer'         => $ssl_status['certificate_details']['issuer'] ?? null,
						),
					)
				);
			}

			return array(
				'code'    => 200,
				'message' => __( 'Ssl Verification run successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'SSL verification failed: Exception occurred',
				array(
					'feature'       => 'ssl_verification',
					'action'        => 'ssl_verification_failed',
					'domain'        => $domain,
					'ssl_connected' => $ssl_connected,
					'expiry_time'   => $expiry_time,
					'error'         => $e->getMessage(),
					'exception'     => $e,
					'title'     => 'SSL Verification Failed',
					'meta_data' => array(
						'feature' => 'Smart SSL',
						'event'   => 'Check failed',
						'domain'        => $domain,
						'ssl_connected' => $ssl_connected,
						'expires_at'    => $expiry_time,
						'reason'        => 'Unexpected error',
					),
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'SSL verification failed.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Trigger SSL expiry alert based on threshold
	 *
	 * @param int|null $days_threshold Days threshold for alert
	 * @return void
	 */
	public function wptw_trigger_ssl_expiry_alert( ?int $days_threshold = null ): void {
		try {
			// Feature gate: drop the alert silently if the user has disabled
			// Smart SSL since this event was scheduled. Single events stay in
			// wp-cron until they fire; without this check, a disabled feature
			// still pushes notifications to the user, which they would rightly
			// consider a bug.
			$enabled = $this->wptw_ssl_is_enable( array( 'field_1' ) );
			if ( empty( $enabled['feature_enable'] ) ) {
				return;
			}

			// Fetch thresholds from settings
			$alert_days_str = $this->wptw_ssl_is_enable( array( 'field_1', 'sub_options', 'field_3' ) )['feature_value'] ?? '30,14,7,1';
			$alert_days     = array_map( 'intval', explode( ',', $alert_days_str ) );
			rsort( $alert_days ); // Sort descending (e.g., 30, 14, 7, 3, 1)

			$ssl_status = $this->wptw_get_ssl_verification_status();

			if ( empty( $ssl_status ) || empty( $ssl_status['expiry_time'] ) ) {
				return;
			}

			$expiry_time    = strtotime( $ssl_status['expiry_time'] );
			$current_time   = time();
			$days_remaining = ( $expiry_time - $current_time ) / 86400;

			$alert_attempts = isset( $ssl_status['expiry_alert_attempt'] ) ? (int) $ssl_status['expiry_alert_attempt'] : 0;

			// Logic if called via Cron with specific threshold
			if ( $days_threshold !== null && is_numeric( $days_threshold ) ) {
				$days_threshold = (int) $days_threshold;
				$domain = wp_parse_url( home_url(), PHP_URL_HOST );

				if ( $days_threshold === 0 ) {
					// Expiry event - certificate has expired.
					// Gate both the log AND the counter increment on $alert_attempts < 3
					// so a permanently-expired cert produces at most 3 critical
					// notifications, not one per cron tick forever.
					if ( $alert_attempts < 3 ) {
						$title   = 'SSL Certificate Expired';
						$message = "SSL certificate for {$domain} has expired. Immediate action required.";

						Log::critical(
							'Your SSL certificate has expired. Visitors may see security warnings. Renew immediately to restore secure access.',
							array(
								'feature'     => 'ssl_verification',
								'action'      => 'ssl_certificate_expired',
								'domain'      => $domain,
								'expiry_time' => $ssl_status['expiry_time'],
								'title'       => $title,
								'meta_data'   => array(
									'feature' => 'Smart SSL',
									'event'   => 'Expired',
									'domain'        => $domain,
									'expires_at'    => $ssl_status['expiry_time'],
									'alert_attempt' => $alert_attempts + 1,
								),
							)
						);

						++$alert_attempts;
					}
				} else {
					// Warning event - certificate expiring in N days
					$title   = 'SSL Expiring Soon';
					$message = "SSL certificate for {$domain} will expire in {$days_threshold} days. Expiry date: {$ssl_status['expiry_time']}";

					// Log warning event
					Log::warning(
						'Your SSL certificate will expire soon. Renew it to avoid browser warnings and downtime.',
						array(
							'feature'        => 'ssl_verification',
							'action'         => 'ssl_certificate_expiring',
							'domain'         => $domain,
							'days_remaining' => $days_remaining,
							'days_threshold' => $days_threshold,
							'expiry_time'    => $ssl_status['expiry_time'],
							'title'          => $title,
							'meta_data'      => array(
								'feature' => 'Smart SSL',
								'event'   => 'Expiring soon',
								'domain'         => $domain,
								'expires_at'     => $ssl_status['expiry_time'],
								'days_remaining' => (int) max( 0, ceil( $days_remaining ) ),
								'days_threshold' => $days_threshold,
							),
						)
					);

				}

				// Update alert attempts in status
				$ssl_status['expiry_alert_attempt'] = $alert_attempts;
				$this->update_ssl_verification_status( $ssl_status );

				return; // Done
			}

			// Fallback logic for manual runs or legacy calls
			// Check if we hit any threshold
			foreach ( $alert_days as $day ) {
				if ( $days_remaining <= $day && $days_remaining > ( $day - 1 ) ) {
					// We are in the window for this alert
					// Need a way to ensure we don't send duplicate alerts for the same threshold
					// For now, simple logging
					Log::info(
						"SSL certificate expiring in {$day} days (fallback check)",
						array(
							'feature'        => 'ssl_verification',
							'action'         => 'ssl_certificate_expiring',
							'days_remaining' => $days_remaining,
							'days_threshold' => $day,
							'meta_data'      => array(
								'feature' => 'Smart SSL',
								'event'   => 'Expiring soon',
								'expires_at'     => $ssl_status['expiry_time'] ?? null,
								'days_remaining' => (int) max( 0, ceil( $days_remaining ) ),
								'days_threshold' => $day,
							),
						)
					);
					break;
				}
			}

			// Original logic for expired certs
			if ( $days_remaining <= 0 ) {
				if ( $alert_attempts < 3 ) {
					++$alert_attempts;
				}
			}

			$ssl_status['expiry_alert_attempt'] = $alert_attempts;
			$this->update_ssl_verification_status( $ssl_status );
		} catch ( \Throwable $e ) {
			Log::error(
				'SSL expiry alert trigger failed: Exception occurred',
				array(
					'feature'   => 'ssl_verification',
					'action'    => 'ssl_expiry_alert_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
					'title'     => 'SSL Expiry Alert Failed',
				)
			);
		}
	}

	/**
	 * Get SSL push notification status
	 *
	 * @return bool
	 */
	public function wptw_ssl_push_notification_status(): bool {
		$key               = 'default_feature_settings';
		$option            = 'default_verify_ssl';
		$field_name        = 'field_1';
		$push_notification = new PushNotificationController();
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Return SSL verification status
	 *
	 * @return array
	 */
	public function wptw_return_ssl_verify_status(): array {
		try {
			$existing_data = $this->wptw_get_ssl_verification_status();
			if ( ! empty( $existing_data ) ) {
				return array(
					'ssl_connected'       => $existing_data['ssl_connected'],
					'expiry_time'         => $existing_data['expiry_time'],
					'last_run'            => $existing_data['last_run'],
					'https_redirect'      => $existing_data['https_redirect'],
					'tls_version'         => $existing_data['tls_version'] ?? 'unknown',
					'cipher_suite'        => $existing_data['cipher_suite'] ?? 'unknown',
					'certificate_details' => $existing_data['certificate_details'] ?? array(), // Return new details
					'security_score'      => $existing_data['security_score'] ?? array(), // Return new score
					'code'                => 200,
					'message'             => $existing_data['message'],
				);
			} else {
				return array(
					'code'    => 200,
					'message' => __( 'No data found', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			return array(
				'code'    => 500,
				'message' => __( 'Failed to retrieve SSL verification status.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Helper to determine key type
	 *
	 * @param int|null $type OpenSSL key type constant
	 * @return string
	 */
	private function get_key_type( ?int $type ): string {
		if ( $type === OPENSSL_KEYTYPE_RSA ) {
			return 'RSA';
		}
		if ( $type === OPENSSL_KEYTYPE_DSA ) {
			return 'DSA';
		}
		if ( $type === OPENSSL_KEYTYPE_DH ) {
			return 'DH';
		}
		if ( $type === OPENSSL_KEYTYPE_EC ) {
			return 'EC';
		}
		return 'Unknown';
	}

	/**
	 * Helper to assess security score
	 *
	 * @param array $ssl_status SSL status array
	 * @return array Modified SSL status with security score
	 */
	private function assess_certificate_security( array $ssl_status ): array {
		$score    = 100;
		$issues   = array();
		$warnings = array();

		$details = $ssl_status['certificate_details'];

		// Check validity
		if ( ! $details['is_valid'] ) {
			$score    = 0;
			$issues[] = 'Certificate is invalid';
		}

		// Check expiry
		if ( $details['days_until_expiry'] < 0 ) {
			$score    = 0;
			$issues[] = 'Certificate has expired';
		} elseif ( $details['days_until_expiry'] < 7 ) {
			$score     -= 20;
			$warnings[] = 'Certificate expires soon (< 7 days)';
		}

		// Check chain
		if ( ! $details['chain_valid'] ) {
			$score   -= 30;
			$issues[] = 'Certificate chain is incomplete or invalid';
		}

		// Check key size (RSA 2048+)
		if ( $details['key_type'] === 'RSA' ) {
			if ( $details['key_size'] < 2048 ) {
				$score   -= 40;
				$issues[] = 'Weak key size (RSA < 2048 bits)';
			}
		}

		// Check TLS Version
		$tls_version = $ssl_status['tls_version'] ?? 'unknown';
		if ( in_array( $tls_version, array( 'TLSv1', 'TLSv1.1', 'SSLv3', 'SSLv2', 'unknown' ) ) ) {
			$score     -= 20;
			$warnings[] = 'Using deprecated or unknown TLS version: ' . $tls_version;
		}

		// Check Cipher Suite (Basic check for weak ciphers)
		$cipher       = $ssl_status['cipher_suite'] ?? 'unknown';
		$weak_ciphers = array( 'RC4', 'DES', '3DES', 'MD5', 'EXPORT', 'NULL', 'aNULL' );
		foreach ( $weak_ciphers as $weak ) {
			if ( stripos( $cipher, $weak ) !== false ) {
				$score   -= 30;
				$issues[] = 'Weak cipher detected: ' . $cipher;
				break;
			}
		}

		// Check self-signed
		if ( $details['is_self_signed'] ) {
			$score     -= 50;
			$warnings[] = 'Self-signed certificate detected';
		}

		// Grade assignment.
		if ( $score >= 90 ) {
			$grade = 'A';
		} elseif ( $score >= 80 ) {
			$grade = 'B';
		} elseif ( $score >= 60 ) {
			$grade = 'C';
		} elseif ( $score >= 40 ) {
			$grade = 'D';
		} else {
			$grade = 'F';
		}

		$ssl_status['security_score'] = array(
			'grade'    => $grade,
			'score'    => $score,
			'issues'   => $issues,
			'warnings' => $warnings,
		);

		return $ssl_status;
	}

	/**
	 * Check for certificate revocation (Basic OCSP URI check)
	 *
	 * @param \OpenSSLCertificate|resource $certificate Certificate resource (resource in PHP 7.x, OpenSSLCertificate in PHP 8.0+).
	 * @return array Revocation status information.
	 */
	private function check_certificate_revocation( $certificate ): array {
		$revocation_status = array(
			'revoked' => false,
			'status'  => 'unknown',
		);
		// Parse certificate to find OCSP URI
		$cert_info = openssl_x509_parse( $certificate );

		if ( isset( $cert_info['extensions']['authorityInfoAccess'] ) ) {
			$aia = $cert_info['extensions']['authorityInfoAccess'];
			if ( preg_match( '/OCSP - URI:(https?:\/\/.+)/i', $aia, $matches ) ) {
				$revocation_status['ocsp_uri'] = trim( $matches[1] );
				// Note: Full OCSP request implementation requires external libraries or raw ASN.1 handling
				// For now, we just confirm the OCSP endpoint exists.
				$revocation_status['status'] = 'checked_uri_exists';
			}
		}

		return $revocation_status;
	}

	/**
	 * Schedule all future expiry alerts
	 *
	 * @param array $ssl_status SSL status array
	 * @return void
	 */
	private function schedule_expiry_alerts( array $ssl_status ): void {
		if ( empty( $ssl_status['expiry_time'] ) ) {
			return;
		}

		$expiry_timestamp = strtotime( $ssl_status['expiry_time'] );
		if ( $expiry_timestamp === false ) {
			Log::warning(
				'Invalid expiry time format in schedule_expiry_alerts',
				array(
					'feature'     => 'ssl_verification',
					'expiry_time' => $ssl_status['expiry_time'],
				)
			);
			return;
		}

		$current_time = time();
		$event_hook   = 'wptw_trigger_ssl_expiry_notice';

		// Clear existing hooks to prevent duplicates
		wp_clear_scheduled_hook( $event_hook );

		// Already-expired case: every threshold offset is in the past AND the
		// expiry-time event check also fails, so without this branch the user
		// would receive no notification at all that their cert is broken.
		// Schedule a single "Expired" event on the next cron tick. The trigger
		// caps Log::critical on $alert_attempts so this won't spam.
		if ( $expiry_timestamp <= $current_time ) {
			wp_schedule_single_event( $current_time + 60, $event_hook, array( 0 ) );
			return;
		}

		// Get alert days from settings
		$alert_days_str = $this->wptw_ssl_is_enable( array( 'field_1', 'sub_options', 'field_3' ) )['feature_value'] ?? '30,14,7,1';
		$alert_days     = array_map( 'intval', explode( ',', $alert_days_str ) );

		// Schedule each alert
		foreach ( $alert_days as $days ) {
			// Calculate when this alert should happen (Expiry - N days)
			$alert_time = $expiry_timestamp - ( $days * 86400 );

			// Only schedule if the time is in the future
			if ( $alert_time > $current_time ) {
				// Pass the specific day threshold as an argument
				wp_schedule_single_event( $alert_time, $event_hook, array( $days ) );
			}
		}

		// Schedule alert at exact expiry time
		if ( $expiry_timestamp > $current_time ) {
			wp_schedule_single_event( $expiry_timestamp, $event_hook, array( 0 ) );
		}

		// Catch-up: if the user enabled the feature (or changed thresholds)
		// AFTER one of the configured thresholds already passed, no future
		// alert covers the gap. Schedule a single immediate warning so the
		// user isn't left thinking everything is fine.
		//
		// Guard against daily spam: schedule_expiry_alerts is called on every
		// verification cron run, and the past-window condition stays TRUE
		// throughout the gap. Without a sticky flag, the catch-up would fire
		// every cron tick. We persist catch_up_fired_for_expiry_ts and only
		// schedule a new catch-up when the cert's expiry changes (renewal),
		// so each cert lifecycle produces at most one catch-up notification.
		$days_remaining = ( $expiry_timestamp - $current_time ) / 86400;
		$largest_passed = 0;
		foreach ( $alert_days as $days ) {
			if ( $days >= $days_remaining ) {
				$largest_passed = max( $largest_passed, $days );
			}
		}
		if ( $largest_passed > 0 && $days_remaining > 0 ) {
			$existing             = $this->wptw_get_ssl_verification_status();
			$already_fired_for_ts = isset( $existing['catch_up_fired_for_expiry_ts'] )
				? (int) $existing['catch_up_fired_for_expiry_ts']
				: 0;

			if ( $already_fired_for_ts !== $expiry_timestamp ) {
				wp_schedule_single_event(
					$current_time + 60,
					$event_hook,
					array( (int) max( 1, ceil( $days_remaining ) ) )
				);
				$ssl_status['catch_up_fired_for_expiry_ts'] = $expiry_timestamp;
				$this->update_ssl_verification_status( $ssl_status );
			}
		}
	}
}
