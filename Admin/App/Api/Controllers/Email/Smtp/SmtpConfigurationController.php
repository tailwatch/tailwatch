<?php

namespace Tailwatch\Admin\App\Api\Controllers\Email\Smtp;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Email\EmailLogController;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Crypto\EncryptionService;

class SmtpConfigurationController {

	private $current_email = null;

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'phpmailer_init', array( $this, 'configure_smtp' ), 1 );
		$hook_controller->add_filter_hook( 'pre_wp_mail', array( $this, 'process_email' ), 10, 2 );
		$hook_controller->add_filter_hook( 'wp_mail', array( $this, 'process_email_headers' ), 20, 1 );
		// wp_mail() applies these filters before it validates the From address, so they
		// set the configured sender for every message; configure_smtp() (below, on
		// phpmailer_init) runs after that validation and configures the transport.
		$hook_controller->add_filter_hook( 'wp_mail_from', array( $this, 'filter_wp_mail_from' ), 99, 1 );
		$hook_controller->add_filter_hook( 'wp_mail_from_name', array( $this, 'filter_wp_mail_from_name' ), 99, 1 );

		new EmailLogController();
	}

	public function tailwatch_get_smtp_configuration() {
		$key                = 'default_feature_settings';
		$option             = 'default_email_configure';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	public function tailwatch_get_smtp_configure_settings() {
		try {
			$config = $this->tailwatch_get_smtp_configuration();
			if ( empty( $config ) ) {
				// Feature is disabled or not yet configured — this is normal, not an error.
				return new \stdClass();
			}

			$settings = new \stdClass();

			if ( ! isset( $config['field_3']['options'] ) ) {
				Log::error(
					'SMTP configuration missing field_3 options',
					array(
						'feature'  => 'email_smtp',
						'action'   => 'smtp_settings_retrieval_failed',
						'detail'   => 'SMTP configuration missing field_3 options',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return $settings;
			}

			$options  = $config['field_3']['options'];
			$provider = null;
			foreach ( $options as $opt_key => $opt ) {
				if ( $opt['selected'] ) {
					$provider = $opt['value'];
					break;
				}
			}
			$settings->smtp_provider = $provider ?? null;

			// Default settings
			$settings->default = new \stdClass();
			if ( isset( $options['option']['sub_options'] ) ) {
				$default_opts                       = $options['option']['sub_options'];
				$settings->default->smtp_from_email = $default_opts['field_4']['options']['option']['value'] ?? '';
				$settings->default->smtp_from_name  = $default_opts['field_5']['options']['option']['value'] ?? '';
			}

			// Custom settings
			$settings->custom = new \stdClass();
			if ( isset( $options['option2']['sub_options'] ) ) {
				$custom_opts                       = $options['option2']['sub_options'];
				$settings->custom->smtp_host       = $custom_opts['field_6']['options']['option']['value'] ?? '';
				$settings->custom->smtp_port       = $custom_opts['field_7']['options']['option']['value'] ?? 587;
				$settings->custom->smtp_username   = $custom_opts['field_8']['options']['option']['value'] ?? '';
				$settings->custom->smtp_password   = $custom_opts['field_9']['options']['option']['value'] ?? '';
				$settings->custom->smtp_encryption = 'none';
				if ( $custom_opts['field_10']['options']['option2']['selected'] ) {
					$settings->custom->smtp_encryption = 'tls';
				} elseif ( $custom_opts['field_10']['options']['option3']['selected'] ) {
					$settings->custom->smtp_encryption = 'ssl';
				}
				$settings->custom->smtp_allow_self_signed = ! empty( $custom_opts['field_11']['options']['option']['selected'] );
				$settings->custom->smtp_from_email        = $custom_opts['field_12']['options']['option']['value'] ?? '';
				$settings->custom->smtp_from_name         = $custom_opts['field_13']['options']['option']['value'] ?? '';
				$settings->custom->smtp_keep_alive        = ! empty( $custom_opts['field_14']['options']['option']['selected'] );
			}

			// Gmail settings
			$settings->gmail = new \stdClass();
			if ( isset( $options['option7']['sub_options'] ) ) {
				$gmail_opts                                = $options['option7']['sub_options'];
				$settings->gmail->smtp_from_email          = $gmail_opts['field_36']['options']['option']['value'] ?? '';
				$settings->gmail->smtp_from_name           = $gmail_opts['field_37']['options']['option']['value'] ?? '';
				$settings->gmail->smtp_gmail_client_id     = $gmail_opts['field_38']['options']['option']['value'] ?? '';
				// Decrypt credentials encrypted at rest by FeaturesController password-type gate.
				$raw_secret = $gmail_opts['field_39']['options']['option']['value'] ?? '';
				$raw_token  = $gmail_opts['field_40']['options']['option']['value'] ?? '';
				$dec_secret = EncryptionService::decrypt( $raw_secret );
				$dec_token  = EncryptionService::decrypt( $raw_token );
				$settings->gmail->smtp_gmail_client_secret = ( false !== $dec_secret ) ? $dec_secret : '';
				$settings->gmail->smtp_gmail_refresh_token = ( false !== $dec_token ) ? $dec_token : '';
				$settings->gmail->smtp_redirect_uri        = ! empty( $gmail_opts['field_41']['options']['option']['selected'] );
				$settings->gmail->smtp_keep_alive          = ! empty( $gmail_opts['field_42']['options']['option']['selected'] );
				$settings->gmail->smtp_connect_google      = ! empty( $gmail_opts['field_43']['options']['option']['selected'] );
			}

			// Allow premium plugin to extend settings with additional providers.
			$settings = apply_filters( 'tailwatch_extend_smtp_settings', $settings, $provider );

			return $settings;
		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to retrieve SMTP settings: ' . $e->getMessage(),
				array(
					'feature'  => 'email_smtp',
					'action'   => 'smtp_settings_retrieval_failed',
					'detail'   => 'Failed to retrieve SMTP settings: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return new \stdClass();
		}
	}

	public function configure_smtp( $phpmailer ) {
		try {
			$settings = $this->tailwatch_get_smtp_configure_settings();
			if ( empty( $settings ) || empty( $settings->smtp_provider ) ) {
				// Feature disabled or not configured — let WordPress (and any other
				// mail plugin) handle email natively. We intentionally do NOT remove
				// other plugins' phpmailer_init / wp_mail handlers in this case, so
				// Tailwatch never interferes with mail when its SMTP feature is off.
				return;
			}

			$provider = $settings->smtp_provider;
			if ( $provider === 'gmail' && ! $this->is_system_email() ) {
				return;
			}

			if ( $provider === 'default' ) {
				try {
					// Default PHPMailer: no SMTP configuration
					$phpmailer->isMail();
					$phpmailer->XMailer   = 'WordPress Default Mailer';
					$phpmailer->CharSet   = 'UTF-8';
					$phpmailer->Encoding  = 'base64';
					$phpmailer->Timeout   = 30;
					// setFrom() runs secureHeader + address validation; direct From/FromName writes bypass both.
					$default_from_email = (string) ( $settings->default->smtp_from_email ?? get_option( 'admin_email' ) );
					$default_from_name  = (string) ( $settings->default->smtp_from_name ?? get_bloginfo( 'name' ) );
					if ( '' !== $default_from_email ) {
						try {
							$phpmailer->setFrom( $default_from_email, $default_from_name );
						} catch ( \Exception $e ) {
							Log::error(
								'PHPMailer setFrom rejected default From address',
								array(
									'feature' => 'email_smtp',
									'action'  => 'smtp_default_set_from_failed',
									'detail'  => $e->getMessage(),
								)
							);
						}
					}
					$phpmailer->SMTPDebug = defined( 'WP_DEBUG' ) && WP_DEBUG ? ( $settings->default->smtp_debug_level ?? 2 ) : 0;
					if ( $phpmailer->SMTPDebug > 0 ) {
						// Suppress PHPMailer's stdout debug output; we only need the Log::debug entries below.
						$phpmailer->Debugoutput = function ( $str, $level ) {};
					}

					Log::debug(
						'Successfully configured default WordPress mailer',
						array(
							'feature' => 'email_smtp',
							'action'  => 'smtp_settings_configured',
							'origin'  => 'system',
						)
					);
					return;
				} catch ( \Throwable $e ) {
					Log::error(
						'Failed to configure default mailer: ' . $e->getMessage(),
						array(
							'feature'  => 'email_smtp',
							'action'   => 'smtp_settings_configuration_failed',
							'detail'   => 'Failed to configure default mailer: ' . $e->getMessage(),
							'origin'   => 'system',
							'severity' => 'high',
						)
					);
					return;
				}
			}

			// Allow premium plugin to handle premium SMTP providers.
			$premium_handled = apply_filters( 'tailwatch_handle_premium_smtp_provider', false, $provider, $settings, $phpmailer );
			if ( $premium_handled !== false ) {
				// Validate hook response format
				if ( is_array( $premium_handled ) && isset( $premium_handled['success'] ) ) {
					if ( $premium_handled['success'] ) {
						return; // Premium plugin handled successfully
					} else {
						Log::error(
							'Premium SMTP provider configuration failed: ' . ( $premium_handled['message'] ?? 'Unknown error' ),
							array(
								'feature'  => 'email_smtp',
								'action'   => 'premium_smtp_configuration_failed',
								'detail'   => 'Premium SMTP provider configuration failed: ' . ( $premium_handled['message'] ?? 'Unknown error' ),
								'origin'   => 'system',
								'severity' => 'high',
							)
						);
						return;
					}
				} else {
					// Log unexpected return format but continue processing
					Log::error(
						'Premium SMTP hook returned invalid format (type: ' . gettype( $premium_handled ) . ')',
						array(
							'feature'  => 'email_smtp',
							'action'   => 'premium_hook_invalid_response',
							'detail'   => 'Premium SMTP hook returned invalid format',
							'origin'   => 'system',
							'severity' => 'medium',
						)
					);
				}
			}

			try {
				$phpmailer->XMailer  = 'TAILWATCH SMTP Mailer';
				$phpmailer->CharSet  = 'UTF-8';
				$phpmailer->Encoding = 'base64';
				$phpmailer->Timeout  = 30;

				switch ( $provider ) {
					case 'custom':
						if ( empty( $settings->custom ) ) {
							throw new \Exception( 'Custom SMTP settings not found' );
						}
						$phpmailer->isSMTP();
						$phpmailer->Host       = $settings->custom->smtp_host ?? '';
						$phpmailer->SMTPAuth   = true;
						$phpmailer->Username   = $settings->custom->smtp_username ?? '';
						$phpmailer->Password   = $this->get_decrypted_password( 'custom', 'smtp_password' );
						$phpmailer->SMTPSecure = ( $settings->custom->smtp_encryption ?? 'none' ) == 'none' ? '' : ( $settings->custom->smtp_encryption ?? 'tls' );
						$phpmailer->Port       = $settings->custom->smtp_port ?? 587;
						if ( $settings->custom->smtp_allow_self_signed ?? false ) {
							$phpmailer->SMTPOptions = array(
								'ssl' => array(
									'verify_peer'       => false,
									'verify_peer_name'  => false,
									'allow_self_signed' => true,
								),
							);
						}
						break;
					default:
						// Allow premium plugin to handle unknown providers.
						$premium_result = apply_filters( 'tailwatch_smtp_unknown_provider', null, $provider, $settings, $phpmailer );
						if ( $premium_result === null ) {
							throw new \Exception( "Unsupported SMTP provider: {$provider}" );
						}
						return; // Premium plugin handled it
				}

				// setFrom() runs secureHeader + address validation; direct From/FromName writes bypass both.
				$from_email = (string) ( $settings->$provider->smtp_from_email ?? '' );
				$from_name  = (string) ( $settings->$provider->smtp_from_name ?? '' );
				if ( '' !== $from_email ) {
					try {
						$phpmailer->setFrom( $from_email, $from_name );
					} catch ( \Exception $e ) {
						Log::error(
							'PHPMailer setFrom rejected configured From address',
							array(
								'feature' => 'email_smtp',
								'action'  => 'smtp_set_from_failed',
								'detail'  => $e->getMessage(),
							)
						);
					}
				}
				$phpmailer->SMTPDebug = defined( 'WP_DEBUG' ) && WP_DEBUG ? ( $settings->$provider->smtp_debug_level ?? 2 ) : 0;
				if ( $phpmailer->SMTPDebug > 0 ) {
					// Suppress PHPMailer's stdout debug output; we only need the Log::debug entries elsewhere.
					$phpmailer->Debugoutput = function ( $str, $level ) {};
				}
				$phpmailer->SMTPKeepAlive = $settings->$provider->smtp_keep_alive ?? true;
				if ( $settings->$provider->smtp_dkim_enabled ?? false ) {
					$this->configure_dkim( $phpmailer, $provider );
				}

				Log::debug(
					'Successfully configured SMTP for provider: ' . $provider,
					array(
						'feature' => 'email_smtp',
						'action'  => 'smtp_settings_configured',
						'origin'  => 'system',
					)
				);

			} catch ( \Throwable $e ) {
				Log::error(
					'Failed to configure SMTP provider ' . $provider . ': ' . $e->getMessage(),
					array(
						'feature'  => 'email_smtp',
						'action'   => 'smtp_settings_configuration_failed',
						'detail'   => 'Failed to configure SMTP provider ' . $provider . ': ' . $e->getMessage(),
						'origin'   => 'system',
						'severity' => 'high',
					)
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to configure SMTP: ' . $e->getMessage(),
				array(
					'feature'  => 'email_smtp',
					'action'   => 'smtp_settings_configuration_failed',
					'detail'   => 'Failed to configure SMTP: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
		}
	}

	public function process_email( $null, $args ) {
		try {
			$this->current_email = $args;
			$settings            = $this->tailwatch_get_smtp_configure_settings();

			if ( empty( $settings ) || empty( $settings->smtp_provider ) ) {
				// Feature disabled or not configured — let WordPress handle natively.
				return null;
			}

			$provider = $settings->smtp_provider;

			// Allow premium plugin to handle premium email processing.
			$premium_processed = apply_filters( 'tailwatch_handle_premium_email_processing', null, $provider, $args, $settings );
			if ( $premium_processed !== null ) {
				return $premium_processed; // Premium plugin handled the email
			}

			// Default / Custom SMTP is configured via phpmailer_init in configure_smtp().
			// pre_wp_mail just lets WordPress proceed natively from here.
			return null;
		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to process email in pre_wp_mail filter: ' . $e->getMessage(),
				array(
					'feature'  => 'email_smtp',
					'action'   => 'smtp_email_processing_failed',
					'detail'   => 'Failed to process email in pre_wp_mail filter: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return null;
		}
	}

	public function process_email_headers( $args ) {
		try {
			$settings = $this->tailwatch_get_smtp_configure_settings();
			if ( empty( $settings ) || empty( $settings->smtp_provider ) ) {
				// Feature disabled or not configured — leave email headers untouched
				// so Tailwatch never changes core/other plugins' mail formatting
				// (e.g. forcing text/html) when its SMTP feature is off.
				return $args;
			}

			if ( empty( $args ) || ! is_array( $args ) ) {
				Log::error(
					'Invalid arguments provided for email header processing',
					array(
						'feature'  => 'email_smtp',
						'action'   => 'smtp_header_processing_failed',
						'detail'   => 'Invalid arguments provided for email header processing',
						'origin'   => 'system',
						'severity' => 'medium',
					)
				);
				return $args;
			}

			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}

			$headers          = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", str_replace( "\r\n", "\n", $args['headers'] ) );
			$clean_headers    = array();
			$has_content_type = false;
			$has_charset      = false;

			foreach ( $headers as $header ) {
				$header = trim( $header );
				if ( empty( $header ) ) {
					continue;
				}
				if ( stripos( $header, 'Content-Type:' ) === 0 ) {
					$has_content_type = true;
					if ( stripos( $header, 'charset=' ) !== false ) {
						$has_charset = true;
					}
				}
				$clean_headers[] = $header;
			}

			if ( ! $has_content_type ) {
				$clean_headers[] = 'Content-Type: text/html; charset=UTF-8';
			} elseif ( ! $has_charset ) {
				foreach ( $clean_headers as $key => $header ) {
					if ( stripos( $header, 'Content-Type:' ) === 0 ) {
						$clean_headers[ $key ] .= '; charset=UTF-8';
						break;
					}
				}
			}

			$args['headers'] = $clean_headers;

			Log::debug(
				'Email headers processed successfully with ' . count( $clean_headers ) . ' headers',
				array(
					'feature' => 'email_smtp',
					'action'  => 'smtp_headers_processed',
					'origin'  => 'system',
				)
			);

			return $args;
		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to process email headers: ' . $e->getMessage(),
				array(
					'feature'  => 'email_smtp',
					'action'   => 'smtp_header_processing_failed',
					'detail'   => 'Failed to process email headers: ' . $e->getMessage(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return $args;
		}
	}

	private function extract_emails_from_headers( $headers ) {
		$result = array(
			'cc'       => array(),
			'bcc'      => array(),
			'reply_to' => array(),
		);
		if ( empty( $headers ) ) {
			return $result;
		}
		$headers = is_array( $headers ) ? $headers : explode( "\n", $headers );
		foreach ( $headers as $header ) {
			$header = trim( $header );
			if ( empty( $header ) ) {
				continue;
			}
			if ( preg_match( '/^CC: (.+)$/i', $header, $matches ) ) {
				$emails = array_map( 'trim', explode( ',', $matches[1] ) );
				foreach ( $emails as $email ) {
					if ( $this->is_valid_email( $email ) ) {
						$result['cc'][] = $email;
					}
				}
			} elseif ( preg_match( '/^BCC: (.+)$/i', $header, $matches ) ) {
				$emails = array_map( 'trim', explode( ',', $matches[1] ) );
				foreach ( $emails as $email ) {
					if ( $this->is_valid_email( $email ) ) {
						$result['bcc'][] = $email;
					}
				}
			} elseif ( preg_match( '/^Reply-To: (.+)$/i', $header, $matches ) ) {
				$email = trim( $matches[1] );
				if ( $this->is_valid_email( $email ) ) {
					$result['reply_to'][] = $email;
				}
			}
		}
		return $result;
	}

	private function is_valid_email( $email ) {
		return ! empty( $email ) && filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	private function configure_dkim( $phpmailer, $provider ) {
		$settings = $this->tailwatch_get_smtp_configure_settings();
		if ( $settings->$provider->smtp_dkim_domain ?? '' && $settings->$provider->smtp_dkim_private_key ?? '' ) {
			$phpmailer->DKIM_domain     = $settings->$provider->smtp_dkim_domain;
			$phpmailer->DKIM_private    = $settings->$provider->smtp_dkim_private_key;
			$phpmailer->DKIM_selector   = $settings->$provider->smtp_dkim_selector ?? 'mail';
			$phpmailer->DKIM_passphrase = $settings->$provider->smtp_dkim_passphrase ?? '';
			$phpmailer->DKIM_identity   = $settings->$provider->smtp_from_email ?? '';
		}
	}

	private function get_decrypted_password( $provider, $option_name ) {
		$settings = $this->tailwatch_get_smtp_configure_settings();
		$password = $settings->$provider->$option_name ?? '';

		if ( empty( $password ) ) {
			return '';
		}

		$decrypted = EncryptionService::decrypt( $password );
		return ( false !== $decrypted ) ? $decrypted : '';
	}

	/**
	 * Delegate to EncryptionService::encrypt().
	 *
	 * @deprecated Use EncryptionService::encrypt() directly.
	 */
	public static function encrypt_data( $data ) {
		return EncryptionService::encrypt( $data );
	}

	/**
	 * Delegate to EncryptionService::decrypt().
	 *
	 * @deprecated Use EncryptionService::decrypt() directly.
	 */
	public static function decrypt_data( $data ) {
		return EncryptionService::decrypt( $data );
	}

	/**
	 * Provide the configured sender address for the wp_mail_from filter.
	 *
	 * wp_mail() applies wp_mail_from and validates the address (PHPMailer::setFrom)
	 * before firing phpmailer_init, so the sender is resolved here rather than in
	 * configure_smtp(). This applies the configured sender to every message --
	 * including mail from other plugins that set no From of their own -- and keeps
	 * the sender aligned with the authenticated SMTP account for deliverability.
	 *
	 * @param string $from_email Address WordPress resolved before this filter.
	 * @return string Configured sender address, or the original if unset/off.
	 */
	public function filter_wp_mail_from( $from_email ) {
		$configured = $this->get_configured_from_email();
		return ( '' !== $configured ) ? $configured : $from_email;
	}

	/**
	 * Provide the configured sender name for the wp_mail_from_name filter.
	 *
	 * @param string $from_name Name WordPress resolved before this filter.
	 * @return string Configured sender name, or the original if unset/off.
	 */
	public function filter_wp_mail_from_name( $from_name ) {
		$configured = $this->get_configured_from_name();
		return ( '' !== $configured ) ? $configured : $from_name;
	}

	/**
	 * Resolve the configured From address for the active provider, applying the
	 * same guards as configure_smtp() so we only override when Tailwatch SMTP
	 * actually handles the send. Returns '' (leave WordPress default) otherwise,
	 * and never returns an invalid address.
	 *
	 * @return string A valid configured From address, or '' for no override.
	 */
	private function get_configured_from_email() {
		$settings = $this->tailwatch_get_smtp_configure_settings();
		if ( empty( $settings ) || empty( $settings->smtp_provider ) ) {
			return '';
		}
		$provider = $settings->smtp_provider;
		if ( 'gmail' === $provider && ! $this->is_system_email() ) {
			return '';
		}
		$from = (string) ( $settings->$provider->smtp_from_email ?? '' );
		return is_email( $from ) ? $from : '';
	}

	/**
	 * Resolve the configured From name for the active provider (same guards as
	 * get_configured_from_email()).
	 *
	 * @return string Configured From name, or '' to leave WordPress default.
	 */
	private function get_configured_from_name() {
		$settings = $this->tailwatch_get_smtp_configure_settings();
		if ( empty( $settings ) || empty( $settings->smtp_provider ) ) {
			return '';
		}
		$provider = $settings->smtp_provider;
		if ( 'gmail' === $provider && ! $this->is_system_email() ) {
			return '';
		}
		return (string) ( $settings->$provider->smtp_from_name ?? '' );
	}

	private function is_system_email() {
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 10 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Used to detect system-initiated email sends by inspecting the call stack.
		foreach ( $backtrace as $trace ) {
			if (
				isset( $trace['function'] ) && in_array(
					$trace['function'],
					array(
						'wp_mail',
						'wp_new_user_notification',
						'wp_password_change_notification',
						'retrieve_password',
						'wp_notify_postauthor',
					)
				)
			) {
				return true;
			}
			if ( isset( $trace['file'] ) && strpos( $trace['file'], ABSPATH . WPINC ) !== false ) {
				return true;
			}
		}
		return false;
	}
}
