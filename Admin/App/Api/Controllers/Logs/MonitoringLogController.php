<?php
/**
 * Monitoring Log Controller
 *
 * Handles HTTP status code monitoring and logging for website errors.
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
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Services\IpService;

/**
 * Class MonitoringLogController
 *
 * Monitors and logs HTTP status codes and server errors.
 *
 */
class MonitoringLogController {

	/**
	 * Constructor - Initialize hooks.
	 *
	 */
	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'shutdown', array( $this, 'process_http_status_code' ) );
	}

	/**
	 * Get monitoring log feature options.
	 *
	 * @return array Feature settings array.
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_monitoring_logs';
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Check if monitoring is enabled.
	 *
	 * @param int|null $field_id Optional field ID to check specific feature.
	 *
	 * @return array {
	 *     Monitoring status array.
	 *
	 *     @type bool $parent_enable  Whether parent feature is enabled.
	 *     @type bool $feature_enable Whether specific feature is enabled.
	 * }
	 */
	public function tailwatch_monitoring_is_enabled( $field_id = null ) {
		$feature_settings = $this->get_features_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		if ( null !== $field_id ) {
			$is_enabled = isset( $feature_settings[ $field_id ]['options']['option']['selected'] ) && true === $feature_settings[ $field_id ]['options']['option']['selected'];
			return array(
				'parent_enable'  => true,
				'feature_enable' => $is_enabled,
			);
		}

		// Iterate the actual keys returned by OptionsController so newly
		// added fields are automatically considered. The previous
		// hard-coded 1..10 loop quietly missed any future field_11+.
		$any_feature_enabled = false;
		foreach ( $feature_settings as $field_entry ) {
			if ( isset( $field_entry['options']['option']['selected'] )
				&& true === $field_entry['options']['option']['selected'] ) {
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
	 * Check if push notifications are enabled for monitoring feature.
	 *
	 * @param string $field_name Field name to check.
	 *
	 * @return bool Whether push notifications are enabled.
	 */
	public function tailwatch_monitoring_push_notification( $field_name ) {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_monitoring_logs';
		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Send log report to database.
	 *
	 * @param array  $data         Log data to store.
	 * @param string $option       Option name for categorization.
	 * @param string $type         Type of log entry.
	 * @param string $url          Optional URL related to log entry.
	 * @param int    $current_user Optional user ID. Defaults to current user.
	 *
	 * @return int|false The inserted row id, or false on failure.
	 */
	public function tailwatch_send_log_report( $data, $option, $type, $url = null, $current_user = '', $facets = array() ) {

		if ( empty( $current_user ) ) {
			$username     = wp_get_current_user();
			$current_user = $username->ID;
		}

		$current_user = absint( $current_user );

		$logs_activity_data = array(
			'user_id'       => $current_user,
			'child_of'      => 0,
			'key'           => 'default_feature_logs',
			'option'        => sanitize_text_field( $option ),
			'value'         => wp_json_encode( $data ),
			'type'          => sanitize_text_field( $type ),
			'type_state'    => null !== $url ? esc_url_raw( $url ) : 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		// Dynamic-filter facet columns. Callers pass only the facets their log type
		// uses; unset columns stay NULL. Indexed for DISTINCT + IN() filtering.
		if ( is_array( $facets ) ) {
			foreach ( array( 'ip_address', 'username', 'action', 'facet_1', 'facet_2' ) as $facet_key ) {
				if ( isset( $facets[ $facet_key ] ) && '' !== $facets[ $facet_key ] ) {
					$logs_activity_data[ $facet_key ] = sanitize_text_field( (string) $facets[ $facet_key ] );
					$db_data_format[]                 = '%s';
				}
			}
		}

		$db_model = new DBModel();
		// Return the inserted row id so callers can attach it to the notification
		// (record_id) for deep-linking. Callers that ignore the return are unaffected.
		return $db_model->insert_row( $logs_activity_data, $db_data_format, TAILWATCH_DB_LOGS_TABLE_NAME );
	}

	/**
	 * Log HTTP status code with details.
	 *
	 * @param string     $log_message Log message text.
	 * @param int        $status_code HTTP status code.
	 * @param string     $url         URL where error occurred.
	 * @param string     $option      Option name for categorization.
	 * @param array|null $more_data   Optional additional data to log.
	 *
	 * @return int|false The inserted row id, or false on failure.
	 */
	public function log_http_status( $log_message, $status_code, $url, $option, $more_data = null ) {
		$user_data = array(
			// Real client IP via the shared IpService — unwraps common
			// reverse-proxy headers. Plain REMOTE_ADDR would log the
			// proxy edge (Cloudflare / NGINX / ALB) for every user on
			// any production site behind a CDN.
			'ip_address'  => IpService::get_client_ip(),
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			'browser'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			'remote_port' => isset( $_SERVER['REMOTE_PORT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_PORT'] ) ) : '',
		);

		$data = array(
			'domain'      => TAILWATCH_GET_SITE_URL,
			'log_message' => sanitize_text_field( $log_message ),
			'status_code' => absint( $status_code ),
			'user_data'   => wp_json_encode( $user_data ),
			'url'         => esc_url_raw( $url ),
		);

		if ( null !== $more_data && is_array( $more_data ) ) {
			$data = array_merge( $data, $more_data );
		}

		return $this->tailwatch_send_log_report( $data, $option, absint( $status_code ), $url, '', array( 'ip_address' => $user_data['ip_address'] ) );
	}

	/**
	 * Process HTTP status codes on shutdown.
	 *
	 * Two separate concerns, intentionally decoupled:
	 *
	 *   1. **Logs** — every monitored error attempt writes a row in the logs
	 *      table. No log-level dedup. A botnet hammering the same URL still
	 *      produces a log entry per request, which is what forensics needs.
	 *      Volume is managed by the periodic logs-cleanup cron, not here.
	 *
	 *   2. **Push notifications** — rate-limited per `(status_code + URL)`
	 *      over a 12-hour window via WP transients. Once we push for
	 *      "URL X returning code Y", subsequent occurrences of the SAME
	 *      pair are logged but not pushed until the transient expires.
	 *      Different URLs and different status codes each get their own
	 *      12h budget. IP intentionally NOT in the key — the alertable
	 *      signal is "this endpoint is broken", not "this specific IP is
	 *      hitting it"; per-attacker detail lives in the log row.
	 *
	 * Performance: fast-bails on the 99% of requests that return 200 OK
	 * before doing any DB reads.
	 *
	 * Wrapped in try/catch because uncaught exceptions during PHP's
	 * shutdown phase can suppress response output and interfere with
	 * other shutdown handlers — telemetry must never break the response.
	 *
	 * @return void
	 */
	public function process_http_status_code() {
		try {
			$status_code = absint( http_response_code() );

			// Fast path: bail before any DB reads on the 99% of requests
			// that return 200 OK. The monitored set is closed (9 codes) —
			// anything outside it can never match a config.
			static $monitored_codes = array( 401, 403, 404, 413, 429, 500, 502, 503, 504 );
			if ( ! in_array( $status_code, $monitored_codes, true ) ) {
				return;
			}

			$get_feature = $this->get_features_options();
			if ( empty( $get_feature ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for URL construction
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url         = home_url( $request_uri );

			$option            = 'default_monitoring_logs';
			$push_notification = new PushNotificationController();

			// Status-code monitoring configuration.
			// `message` is the technical string stored in the DB log row
			// (URL appended). `title`/`body` are the user-facing strings
			// sent to the mobile push.
			//
			// field_3 is intentionally skipped — historical 501 monitor,
			// removed in an earlier release. Keep field numbering stable
			// so existing stored options keep matching.
			$monitoring_config = array(
				'field_1'  => array(
					'code'    => 503,
					'message' => __( '503 Service Unavailable detected. Server down time recorded for URL: ', 'tailwatch' ),
					'title'   => '503 Error Detected',
					'body'    => 'Your website is currently unavailable (503 error). This may be due to maintenance mode, high traffic, or server overload.',
				),
				'field_2'  => array(
					'code'         => 500,
					'message'      => '',
					'use_recovery' => true,
					'title'        => '500 Error Detected',
					'body'         => 'Your server returned a 500 Internal Server Error. This may be caused by code issues, plugin conflicts, or server misconfiguration. Check logs or contact support.',
				),
				'field_4'  => array(
					'code'    => 404,
					'message' => __( '404 Not Found detected for URL: ', 'tailwatch' ),
					'title'   => '404 Errors Detected',
					'body'    => 'Multiple 404 Not Found errors were detected. This may be due to broken links or missing pages. Fix links or restore content.',
				),
				'field_5'  => array(
					'code'    => 502,
					'message' => __( '502 Bad Gateway detected for URL: ', 'tailwatch' ),
					'title'   => '502 Error Detected',
					'body'    => 'A 502 Bad Gateway error was detected. This usually indicates an upstream server issue or temporary server overload.',
				),
				'field_6'  => array(
					'code'    => 504,
					'message' => __( '504 Gateway Timeout detected for URL: ', 'tailwatch' ),
					'title'   => '504 Error Detected',
					'body'    => 'A 504 Gateway Timeout error occurred. Your server took too long to respond, possibly due to slow backend services.',
				),
				'field_7'  => array(
					'code'    => 403,
					'message' => __( '403 Forbidden detected for URL: ', 'tailwatch' ),
					'title'   => '403 Errors Detected',
					'body'    => 'Access to certain resources is being blocked (403 error). This may be caused by permission rules or security settings.',
				),
				'field_8'  => array(
					'code'    => 401,
					'message' => __( '401 Unauthorized detected for URL: ', 'tailwatch' ),
					'title'   => '401 Error Detected',
					'body'    => 'Unauthorized access attempts were detected. This may indicate missing authentication or incorrect credentials.',
				),
				'field_9'  => array(
					'code'    => 429,
					'message' => __( '429 Too Many Requests detected for URL: ', 'tailwatch' ),
					'title'   => '429 Rate Limit Triggered',
					'body'    => 'Your server is receiving too many requests in a short time. This may indicate bot traffic or rate limiting activation.',
				),
				'field_10' => array(
					'code'    => 413,
					'message' => __( '413 Payload Too Large detected for URL: ', 'tailwatch' ),
					'title'   => '413 Error Detected',
					'body'    => 'A request failed due to large payload size (413 error). This may occur during uploads or API requests exceeding limits.',
				),
			);

			foreach ( $monitoring_config as $field => $config ) {
				// Only the one config whose code matches the current
				// response code can fire — skip the other 8 cheaply.
				if ( $status_code !== $config['code'] ) {
					continue;
				}

				// Field-level enable toggle in the feature options.
				if ( empty( $get_feature[ $field ]['options']['option']['selected'] )
					|| true !== $get_feature[ $field ]['options']['option']['selected'] ) {
					break;
				}

				// Build the log message. error_get_last() is only
				// meaningful for the 500 fatal-error path — deferred
				// here so the other 8 codes don't pay for it.
				if ( ! empty( $config['use_recovery'] ) ) {
					$log_message = $this->generate_recovery_mode_message( error_get_last(), $url );
				} else {
					$log_message = $config['message'] . $url;
				}

				// Push notification — rate-limited per (status + URL) over
				// a 12-hour window via WP transient. The log row below
				// ALWAYS records the attempt; only the push is throttled,
				// so a botnet hammering one URL can't spam the admin's
				// phone but the attempts still show up in the logs.
				//
				// Reads the push_notification flag directly from the
				// already-loaded $get_feature blob. OptionsController's
				// process_fields() preserves this key per field — see
				// the per-field extracted-shape comment there. Avoids
				// the extra DB read tailwatch_notification_enable_for_feature()
				// would do.
				// Always log the attempt first so the notification can deep-link to the
				// row. Volume management happens via the periodic logs-cleanup cron.
				$record_id = $this->log_http_status( $log_message, $status_code, $url, $option );

				if ( ! empty( $get_feature[ $field ]['push_notification'] ) ) {
					$rl_key = 'tailwatch_pn_rl_' . md5( $status_code . '|' . $url );
					if ( false === get_transient( $rl_key ) ) {
						$meta_data = array(
							'feature'      => 'error_logs',
							'event'        => 'http_error',
							'feature_name' => 'Error Logs',
							'state'        => $config['title'],
							'record_id'    => $record_id,
							'status_code'  => $status_code,
							'url'          => $url,
							'IP address'   => IpService::get_client_ip(),
							// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized inline for notification payload.
							'browser'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
						);
						if ( ! empty( $config['use_recovery'] ) ) {
							$meta_data['Recovery mode'] = 'Yes';
						}

						$push_notification->tailwatch_trigger_notification(
							'critical',
							$config['title'],
							$config['body'],
							$meta_data
						);
						set_transient( $rl_key, time(), 12 * HOUR_IN_SECONDS );
					}
				}

				// At most one config can match a given response code; stop
				// here rather than walking the remaining configs.
				break;
			}
		} catch ( \Throwable $e ) {
			// Deliberately swallowed. Uncaught exceptions during PHP's
			// shutdown phase can suppress response output and interfere
			// with sibling shutdown handlers — monitoring telemetry must
			// never break the actual response.
			unset( $e );
		}
	}

	/**
	 * Generate recovery mode error message.
	 *
	 * @param array|null $error Error array from error_get_last().
	 * @param string     $url   URL where error occurred.
	 *
	 * @return string Formatted error message.
	 */
	public function generate_recovery_mode_message( $error, $url ) {
		if ( $error && is_array( $error ) ) {
			$message     = isset( $error['message'] ) ? sanitize_text_field( $error['message'] ) : 'Unknown error';
			$file        = isset( $error['file'] ) ? sanitize_text_field( $error['file'] ) : 'Unknown file';
			$line        = isset( $error['line'] ) ? absint( $error['line'] ) : 0;
			$log_message = "Fatal error: {$message} in {$file} on line {$line}. ";
		} else {
			$log_message = '500 Internal Server Error detected for URL: ' . esc_url_raw( $url ) . '. ';
		}
		return $log_message;
	}
}
