<?php
/**
 * Unified Logger Class - PSR-3 Compatible
 *
 * Centralized logging system that combines activity and error logging.
 * Inspired by Monolog and Laravel's logging patterns, adapted for WordPress.
 *
 * @package    Tailwatch
 * @subpackage Logging
 */

namespace Tailwatch\Admin\App\Api\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Handlers\LogHandlerInterface;
use Tailwatch\Admin\App\Api\Logging\Handlers\DatabaseHandler;
use Tailwatch\Admin\App\Api\Logging\Processors\LogProcessorInterface;
use Tailwatch\Admin\App\Api\Logging\Processors\UserProcessor;
use Tailwatch\Admin\App\Api\Logging\Processors\RequestProcessor;
use Tailwatch\Admin\App\Api\Logging\Processors\MemoryProcessor;
use Tailwatch\Admin\App\Api\Logging\Config\NotificationActions;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Services\IpService;

/**
 * Class Logger
 *
 * Central logging system with PSR-3 compatible log levels.
 * Handles both activity and error logging through unified interface.
 *
 */
class Logger {

	/**
	 * PSR-3 Log Levels.
	 */
	const EMERGENCY = 'emergency'; // System unusable.
	const ALERT     = 'alert';     // Action must be taken immediately.
	const CRITICAL  = 'critical';  // Critical conditions.
	const ERROR     = 'error';     // Error conditions.
	const WARNING   = 'warning';   // Warning conditions.
	const NOTICE    = 'notice';    // Normal but significant.
	const INFO      = 'info';      // Informational messages.
	const DEBUG     = 'debug';     // Debug-level messages.

	/**
	 * Singleton instance.
	 *
	 * @var Logger|null
	 */
	private static $instance = null;

	/**
	 * Log handlers (where logs are written).
	 *
	 * @var array<LogHandlerInterface>
	 */
	private $handlers = array();

	/**
	 * Log processors (add context to logs).
	 *
	 * @var array<LogProcessorInterface>
	 */
	private $processors = array();

	/**
	 * Rate limiting tracking.
	 *
	 * @var array<string, int>
	 */
	private static $log_count = array();

	/**
	 * Rate limit per level per minute.
	 *
	 * @var int
	 */
	private static $rate_limit = 100;

	/**
	 * Re-entrancy guard for notifications.
	 *
	 * Prevents infinite loop: Log → notification → license verify → Log → notification → ...
	 * When true, trigger_notification_if_needed() returns immediately.
	 *
	 * @var bool
	 */
	private static $is_notifying = false;

	/**
	 * Private constructor to enforce singleton pattern.
	 */
	private function __construct() {
		$this->setup_default_handlers();
		$this->setup_default_processors();
	}

	/**
	 * Prevent cloning of singleton.
	 *
	 * @return void
	 */
	private function __clone() {
		// Prevent cloning.
	}

	/**
	 * Prevent unserialization of singleton.
	 *
	 * @throws \Exception Always throws exception.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Get singleton instance.
	 *
	 * @return Logger Logger instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Setup default handlers based on logging settings.
	 *
	 * @return void
	 */
	private function setup_default_handlers() {
		// Single DB read for both flags instead of two separate calls.
		$logging_status   = $this->get_logging_status();
		$activity_enabled = $logging_status['activity_logs_recording'];
		$error_enabled    = $logging_status['error_logs_recording'];

		// Database handler for errors (if enabled).
		if ( $error_enabled ) {
			$this->add_handler(
				new DatabaseHandler(
					array( self::ERROR, self::CRITICAL, self::ALERT, self::EMERGENCY ),
					'error_logs'
				)
			);
		}

		// Database handler for activity (if enabled).
		if ( $activity_enabled ) {
			$this->add_handler(
				new DatabaseHandler(
					array( self::INFO, self::NOTICE, self::WARNING ),
					'activity_logs'
				)
			);
		}

		/**
		 * Filter to add custom log handlers.
	     *
		 * @param array $handlers Array of log handlers.
		 */
		$this->handlers = apply_filters( 'tailwatch_log_handlers', $this->handlers );
	}

	/**
	 * Setup default processors to add context.
	 *
	 * @return void
	 */
	private function setup_default_processors() {
		$this->add_processor( new UserProcessor() );
		$this->add_processor( new RequestProcessor() );
		$this->add_processor( new MemoryProcessor() );

		/**
		 * Filter to add custom log processors.
	     *
		 * @param array $processors Array of log processors.
		 */
		$this->processors = apply_filters( 'tailwatch_log_processors', $this->processors );
	}

	/**
	 * Get logging status for both activity and error logging.
	 *
	 * Hardcoded off in 1.0.0 — no user-facing toggle ships in this release.
	 * Log:: call sites still execute (rate-limit + sanitize + notification
	 * dispatch) but no DatabaseHandler is registered, so nothing is written
	 * to the logs table.
	 *
	 * @return array {
	 *     @type bool $activity_logs_recording Always false in 1.0.0.
	 *     @type bool $error_logs_recording    Always false in 1.0.0.
	 * }
	 */
	private function get_logging_status() {
		return array(
			'activity_logs_recording' => false,
			'error_logs_recording'    => false,
		);
	}

	/**
	 * Check if WordPress error logging is enabled.
	 *
	 * WordPress recommends checking WP_DEBUG_LOG before using error_log().
	 *
	 * @return bool True if WP_DEBUG_LOG is enabled, false otherwise.
	 */
	private function is_wp_error_log_enabled() {
		return defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Add a log handler.
	 *
	 * @param LogHandlerInterface $handler Handler instance.
	 *
	 * @return void
	 */
	public function add_handler( LogHandlerInterface $handler ) {
		$this->handlers[] = $handler;
	}

	/**
	 * Add a log processor.
	 *
	 * @param LogProcessorInterface $processor Processor instance.
	 *
	 * @return void
	 */
	public function add_processor( LogProcessorInterface $processor ) {
		$this->processors[] = $processor;
	}

	/**
	 * Core logging method.
	 *
	 * @param string $level   Log level (PSR-3).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		// Validate log level.
		$valid_levels = array(
			self::EMERGENCY,
			self::ALERT,
			self::CRITICAL,
			self::ERROR,
			self::WARNING,
			self::NOTICE,
			self::INFO,
			self::DEBUG,
		);

		if ( ! in_array( $level, $valid_levels, true ) ) {
			$level = self::ERROR; // Default to error for invalid levels.
		}

		// Rate limiting per level per minute.
		$minute_key = gmdate( 'Y-m-d-H-i' );
		$rate_key   = $level . ':' . $minute_key;

		if ( ! isset( self::$log_count[ $rate_key ] ) ) {
			self::$log_count[ $rate_key ] = 0;
		}

		++self::$log_count[ $rate_key ];

		if ( self::$log_count[ $rate_key ] > self::$rate_limit ) {
			// Log warning only once when limit is exceeded.
			if ( self::$log_count[ $rate_key ] === self::$rate_limit + 1 ) {
				// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
				if ( $this->is_wp_error_log_enabled() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when logging system itself fails
					error_log(
						sprintf(
							"Logger: Rate limit reached for level '%s' - suppressing further logs this minute",
							$level
						)
					);
				}
			}
			return; // Drop log if rate limit exceeded.
		}

		// Validate and convert message to string.
		if ( ! is_string( $message ) ) {
			if ( is_array( $message ) || is_object( $message ) ) {
				$message = wp_json_encode( $message );
			} else {
				$message = (string) $message;
			}
		}

		// Truncate very long messages to prevent database overflow.
		if ( strlen( $message ) > 5000 ) {
			$message = substr( $message, 0, 5000 ) . '... [truncated]';
		}

		// Create log record.
		$record = array(
			'level'    => $level,
			'message'  => sanitize_text_field( $message ),
			'context'  => $this->sanitize_context( $context ),
			'datetime' => current_time( 'mysql' ),
			'extra'    => array(),
		);

		// Apply processors to add context (with error handling).
		foreach ( $this->processors as $processor ) {
			try {
				$record = $processor->process( $record );
			} catch ( \Exception $e ) {
				// Continue to next processor even if one fails.
				// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
				if ( $this->is_wp_error_log_enabled() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when logging system itself fails
					error_log(
						sprintf(
							'Log processor failed (%s): %s',
							get_class( $processor ),
							$e->getMessage()
						)
					);
				}
			}
		}

		// Pass to all handlers that accept this level (with error handling).
		foreach ( $this->handlers as $handler ) {
			try {
				if ( $handler->handles( $level ) ) {
					$handler->handle( $record );
				}
			} catch ( \Exception $e ) {
				// Prevent logging failure from breaking app.
				// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
				if ( $this->is_wp_error_log_enabled() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when logging system itself fails
					error_log(
						sprintf(
							'Log handler failed (%s): %s - Original message: %s',
							get_class( $handler ),
							$e->getMessage(),
							$message
						)
					);
				}
			}
		}

		// Trigger notification if needed (decoupled from logging).
		$this->trigger_notification_if_needed( $level, $message, $context );
	}

	/**
	 * Sanitize context data.
	 *
	 * Recursively sanitizes context array to prevent XSS and ensure data safety.
	 * Preserves debugging data (exceptions, traces) for troubleshooting.
	 * Includes recursion depth and size limits to prevent infinite loops and memory issues.
	 *
	 * @param array $context  Raw context data.
	 * @param int   $depth    Current recursion depth.
	 * @param int   $max_depth Maximum recursion depth allowed.
	 * @param int   $size     Current size of sanitized data (by reference).
	 * @param int   $max_size Maximum size allowed (50KB).
	 *
	 * @return array|string Sanitized context data or truncation message.
	 */
	private function sanitize_context( array $context, $depth = 0, $max_depth = 10, &$size = 0, $max_size = 50000 ) {
		// Prevent infinite recursion.
		if ( $depth > $max_depth ) {
			return '[Max depth reached]';
		}

		// Prevent oversized contexts.
		if ( $size > $max_size ) {
			return '[Context too large]';
		}

		$sanitized = array();

		// Keys that should preserve original data for debugging (strings/arrays only, not exception objects).
		$preserve_keys = array( 'exception', 'trace', 'error', 'stack_trace', 'debug_data', 'raw_data' );

		foreach ( $context as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			// Exception objects should always be formatted, even if in preserve_keys.
			if ( is_object( $value ) && ( $value instanceof \Exception || $value instanceof \Throwable ) ) {
				$exception_data = array(
					'class'   => get_class( $value ),
					'message' => sanitize_text_field( $value->getMessage() ),
					'code'    => $value->getCode(),
				);
				// Include file paths and traces only when WP_DEBUG is enabled.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$exception_data['file']  = sanitize_text_field( $value->getFile() );
					$exception_data['line']  = absint( $value->getLine() );
					$exception_data['trace'] = $value->getTraceAsString();
				}
				$sanitized[ $sanitized_key ] = $exception_data;
				$size                       += isset( $exception_data['trace'] ) ? strlen( $exception_data['trace'] ) : 0;
			} elseif ( in_array( $key, $preserve_keys, true ) ) {
				// Preserve debugging data (strings/arrays) without over-sanitizing.
				if ( is_string( $value ) ) {
					$sanitized[ $sanitized_key ] = $value;
					$size                       += strlen( $value );
				} elseif ( is_array( $value ) ) {
					$sanitized[ $sanitized_key ] = $value;
					$size                       += 100; // Approximate size for arrays.
				} else {
					$sanitized[ $sanitized_key ] = $value;
				}
			} elseif ( is_array( $value ) ) {
				$sanitized[ $sanitized_key ] = $this->sanitize_context( $value, $depth + 1, $max_depth, $size, $max_size );
			} elseif ( is_string( $value ) ) {
				// Truncate very long strings.
				if ( strlen( $value ) > 10000 ) {
					$value = substr( $value, 0, 10000 ) . '... [truncated]';
				}
				$sanitized[ $sanitized_key ] = sanitize_text_field( $value );
				$size                       += strlen( $value );
			} elseif ( is_int( $value ) ) {
				$sanitized[ $sanitized_key ] = absint( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $sanitized_key ] = (bool) $value;
			} elseif ( is_object( $value ) ) {
				// Convert other objects to arrays for JSON encoding.
				$sanitized[ $sanitized_key ] = $this->sanitize_context( (array) $value, $depth + 1, $max_depth, $size, $max_size );
			} else {
				$sanitized[ $sanitized_key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Trigger notification if needed (decoupled from logging).
	 *
	 * Checks NotificationActions config to determine if notification should be sent.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	private function trigger_notification_if_needed( $level, $message, $context ) {
		// Re-entrancy guard: notification process calls Log:: internally
		// (license verification, email failure logging, etc.) which would
		// re-enter this method and cause an infinite loop.
		if ( self::$is_notifying ) {
			return;
		}

		$action = isset( $context['action'] ) ? sanitize_text_field( $context['action'] ) : null;

		if ( ! $action ) {
			return;
		}

		try {
			// Check if this action should trigger a notification.
			if ( NotificationActions::should_notify( $action ) ) {
				self::$is_notifying = true;

				$push_notification = new PushNotificationController();

				// Map log levels to notification severity.
				$severity_map = array(
					self::EMERGENCY => 'critical',
					self::ALERT     => 'critical',
					self::CRITICAL  => 'critical',
					self::ERROR     => 'critical',
					self::WARNING   => 'warning',
					self::NOTICE    => 'info',
					self::INFO      => 'info',
					self::DEBUG     => 'info',
				);

				$severity     = isset( $severity_map[ $level ] ) ? $severity_map[ $level ] : 'info';
				$title        = isset( $context['title'] ) ? sanitize_text_field( $context['title'] ) : $message;
				$error_detail = isset( $context['error'] ) ? ( is_string( $context['error'] ) ? sanitize_text_field( $context['error'] ) : wp_json_encode( $context['error'] ) ) : $message;

				// Baseline metadata attached to every notification — forensics
				// the central API / mobile app benefit from regardless of which
				// feature fired the log. Caller-provided $context['meta_data']
				// is merged on top, so a caller can extend with feature-specific
				// fields (or override these baseline keys when needed).
				//
				// Intentionally excluded: credentials, tokens, license keys,
				// auth headers, anything caller passes via $context['error']
				// that may carry secrets. Callers must NOT put sensitive fields
				// into $context['meta_data'].
				$auto_meta = array(
					'feature'    => isset( $context['feature'] ) ? sanitize_text_field( $context['feature'] ) : 'general',
					'event'      => $action,
					'level'      => $level,
					'IP address' => IpService::get_client_ip(),
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized inline for notification payload.
					'browser'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
					'timestamp'  => time(),
				);

				$caller_meta = ( isset( $context['meta_data'] ) && is_array( $context['meta_data'] ) )
					? $context['meta_data']
					: array();

				// 'feature' and 'event' are reserved mapping-key slugs: 'feature' is the
				// log-context subsystem and 'event' is the action. If a caller also put a
				// human-readable feature/event inside meta_data, keep it as a display
				// variable (feature_name / state) so the dashboard maps on the stable
				// slugs while still interpolating the display text — the slugs are never
				// overwritten by a display string.
				if ( isset( $caller_meta['feature'] ) && ! isset( $caller_meta['feature_name'] ) ) {
					$caller_meta['feature_name'] = $caller_meta['feature'];
				}
				if ( isset( $caller_meta['event'] ) && ! isset( $caller_meta['state'] ) ) {
					$caller_meta['state'] = $caller_meta['event'];
				}
				unset( $caller_meta['feature'], $caller_meta['event'] );

				$merged = array_merge( $auto_meta, $caller_meta );

				// Reorder the merged payload into a canonical key sequence so the
				// outgoing JSON reads the way the mobile app expects: subject of
				// the event first (feature / event / state diff / narrative
				// impact), then any caller-specific extras, then the forensic
				// auto-baseline (level / timestamp / ip / browser) last.
				$head_order = array( 'feature', 'event', 'feature_name', 'state', 'now', 'before', 'impact' );
				$tail_order = array( 'level', 'timestamp', 'IP address', 'browser' );

				$meta_data = array();
				foreach ( $head_order as $key ) {
					if ( array_key_exists( $key, $merged ) ) {
						$meta_data[ $key ] = $merged[ $key ];
					}
				}
				foreach ( $merged as $key => $value ) {
					if ( ! array_key_exists( $key, $meta_data ) && ! in_array( $key, $tail_order, true ) ) {
						$meta_data[ $key ] = $value;
					}
				}
				foreach ( $tail_order as $key ) {
					if ( array_key_exists( $key, $merged ) ) {
						$meta_data[ $key ] = $merged[ $key ];
					}
				}

				// Use correct notification method (tailwatch_trigger_notification supports mobile push).
				$push_notification->tailwatch_trigger_notification( $severity, $title, $error_detail, $meta_data );

				self::$is_notifying = false;
			}
		} catch ( \Exception $e ) {
			self::$is_notifying = false;
			if ( $this->is_wp_error_log_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when notification system fails
				error_log( 'Failed to trigger notification: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * PSR-3 Interface Methods.
	 */

	/**
	 * System is unusable.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function emergency( $message, array $context = array() ) {
		$this->log( self::EMERGENCY, $message, $context );
	}

	/**
	 * Action must be taken immediately.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function alert( $message, array $context = array() ) {
		$this->log( self::ALERT, $message, $context );
	}

	/**
	 * Critical conditions.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function critical( $message, array $context = array() ) {
		$this->log( self::CRITICAL, $message, $context );
	}

	/**
	 * Error conditions.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( self::ERROR, $message, $context );
	}

	/**
	 * Warning conditions.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( self::WARNING, $message, $context );
	}

	/**
	 * Normal but significant.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function notice( $message, array $context = array() ) {
		$this->log( self::NOTICE, $message, $context );
	}

	/**
	 * Informational messages.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( self::INFO, $message, $context );
	}

	/**
	 * Debug-level messages.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		// Only log debug messages if WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->log( self::DEBUG, $message, $context );
		}
	}
}
