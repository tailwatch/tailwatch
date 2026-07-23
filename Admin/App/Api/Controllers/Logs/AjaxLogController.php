<?php
/**
 * Network Log Controller.
 *
 * Records request/response detail for API-style WordPress traffic:
 * AJAX (admin-ajax.php), REST API (/wp-json/...), cron (wp-cron.php),
 * and XML-RPC. Plain admin and frontend page renders are intentionally
 * NOT logged — they generate too much noise and are not "calls" in the
 * sense this controller cares about. Capture happens on `wp_loaded`
 * (request side) and `shutdown` (response + persist).
 *
 * NOTE: Class name kept as AjaxLogController for backward compatibility
 * with existing callers; the file/class will be renamed to NetworkLog
 * in a follow-up.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Logs
 */

namespace Tailwatch\Admin\App\Api\Controllers\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\IpService;

/**
 * Class AjaxLogController
 *
 * Monitors and logs all incoming WordPress request/response activity.
 *
 */
class AjaxLogController {

	/**
	 * Maximum size in bytes for response body and raw body capture.
	 *
	 * @var int
	 */
	const MAX_BODY_SIZE = 10240;

	/**
	 * Maximum size in bytes for an individual header value.
	 *
	 * @var int
	 */
	const MAX_HEADER_VALUE_SIZE = 1024;

	/**
	 * Performance-flag thresholds, in milliseconds. A request whose
	 * duration is >= the threshold gets the corresponding flag.
	 *
	 */
	const PERF_WARNING_MS  = 1000;
	const PERF_BAD_MS      = 3000;
	const PERF_CRITICAL_MS = 5000;

	/**
	 * Guard so hooks are registered only once even if the class is
	 * instantiated multiple times in the same request.
	 *
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Captured request data from Phase 1 (wp_loaded).
	 *
	 * @var array|null
	 */
	private $request_data_captured = null;

	/**
	 * Request start time for duration calculation.
	 *
	 * @var float|null
	 */
	private $start_time = null;

	/**
	 * Output buffer level our ob_start() call sits at.
	 *
	 * Recorded so the shutdown phase can flush everything above it
	 * and read the final response body before PHP itself flushes
	 * buffers (PHP flushes buffers AFTER shutdown functions run, so
	 * relying on an ob_start callback would fire too late to log).
	 *
	 * @var int|null
	 */
	private $our_ob_level = null;

	/**
	 * Header names (lowercased) whose values must be redacted before logging.
	 *
	 * @var string[]
	 */
	private static $sensitive_headers = array(
		'cookie',
		'authorization',
		'proxy-authorization',
		'x-wp-nonce',
		'x-api-key',
	);

	/**
	 * Field names (lowercased) whose values must be redacted before logging.
	 *
	 * @var string[]
	 */
	private static $sensitive_fields = array(
		'pwd',
		'password',
		'pass1',
		'pass2',
		'user_pass',
		'current_password',
		'new_password',
		'_wpnonce',
		'nonce',
	);

	/**
	 * Constructor - Initialize hooks for two-phase capture.
	 *
	 * Phase 1 (wp_loaded): Capture request details and start output buffering.
	 * Phase 2 (shutdown): Capture response details and persist the full log.
	 *
	 * Hook registration is guarded by a static flag so the controller can
	 * be instantiated elsewhere (e.g. for `wptw_ajax_log_is_enabled()`)
	 * without double-registering capture hooks.
	 *
	 */
	public function __construct() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wp_loaded', array( $this, 'capture_network_request' ) );

		// Priority 0: WordPress registers `wp_ob_end_flush_all` on `shutdown`
		// at priority 1 (see wp-includes/default-filters.php), which closes
		// every output buffer. We MUST run before that so our ob_get_contents()
		// can still see the response body. Anything >= 1 captures empty bodies.
		$hook_controller->add_action_hook( 'shutdown', array( $this, 'log_network_request' ), 0 );
	}

	/**
	 * Get network log feature options.
	 *
	 * @return array Feature settings array.
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_ajax_log';
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Check if network logging is enabled.
	 *
	 * Method name retained for backward compatibility with existing callers.
	 *
	 * @return array {
	 *     Network logging status array.
	 *
	 *     @type bool $parent_enable  Whether parent feature is enabled.
	 *     @type bool $feature_enable Whether network logging is enabled.
	 * }
	 */
	public function wptw_ajax_log_is_enabled() {
		$feature_settings = $this->get_features_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$any_feature_enabled = isset( $feature_settings['field_1']['options']['option']['selected'] ) &&
								true === $feature_settings['field_1']['options']['option']['selected'];

		return array(
			'parent_enable'  => true,
			'feature_enable' => $any_feature_enabled,
		);
	}

	/**
	 * Phase 1: Capture request data on wp_loaded.
	 *
	 * Captures only API-style traffic (AJAX, REST, cron, XML-RPC) — see
	 * is_loggable_request(). Plain admin and frontend page renders return
	 * early without any work. For captured requests, scrapes request-side
	 * detail and starts output buffering for the response body.
	 *
	 * ## Why this method uses file_get_contents('php://input')
	 *
	 * The raw HTTP request body for JSON / REST / XML-RPC payloads is only
	 * accessible via PHP's `php://input` stream wrapper. WordPress's
	 * Filesystem API (WP_Filesystem::get_contents) operates on filesystem
	 * paths and explicitly does not support the `php://` family of stream
	 * wrappers — there is no WordPress-provided alternative for reading the
	 * raw request body. PHP's own `$HTTP_RAW_POST_DATA` was removed in
	 * PHP 7.0, so `file_get_contents('php://input')` is the only available
	 * source for the unmodified request body needed by this logging feature.
	 * The captured body is truncated to MAX_BODY_SIZE before storage to
	 * bound log size.
	 *
	 * ## Why this endpoint does not use wp_verify_nonce()
	 *
	 * This is a `wp_loaded` observer hook, not a state-changing endpoint. It
	 * runs on EVERY incoming request to record request/response telemetry,
	 * including anonymous third-party AJAX calls, REST calls from
	 * unauthenticated clients, cron pings, and XML-RPC traffic.
	 * wp_verify_nonce() and current_user_can() are structurally inapplicable
	 * because:
	 *   (a) there is no per-user CSRF surface — the method performs no state
	 *       mutation, only read-only observation and a log write;
	 *   (b) requiring a nonce would defeat the purpose of the feature, which
	 *       is precisely to capture requests that arrive without any WP
	 *       session (unauthenticated REST calls, cron pings, third-party
	 *       integrations).
	 *
	 * All captured $_GET / $_POST / $_COOKIE data is normalized through
	 * `map_deep( wp_unslash(), 'sanitize_text_field' )` and `redact_fields()`
	 * before persistence; sensitive header and cookie values are stripped by
	 * `redact_headers()` and `redact_raw_body()` so no credential or secret
	 * material is written to the log store.
	 *
	 * @return void
	 */
	public function capture_network_request() {

		$get_feature = $this->get_features_options();

		if ( empty( $get_feature ) ) {
			return;
		}

		if ( ! isset( $get_feature['field_1']['options']['option']['selected'] ) ||
			true !== $get_feature['field_1']['options']['option']['selected'] ) {
			return;
		}

		// Only capture API-style calls. Admin and frontend page renders
		// would flood the log with low-value entries and are skipped before
		// any expensive work (ob_start, header/body scrape) happens.
		if ( ! $this->is_loggable_request() ) {
			return;
		}

		$this->start_time = microtime( true );

		$action = $this->derive_action();

		// Capture request headers (with sensitive values redacted, individual values capped).
		$request_headers = $this->redact_headers( $this->get_request_headers() );

		// Capture query parameters (supports nested arrays via map_deep).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Network logging hook, capturing request data for monitoring purposes
		$query_params = ! empty( $_GET ) ? map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ) : array();
		$query_params = $this->redact_fields( $query_params );

		// Capture POST data (supports nested arrays via map_deep).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Network logging hook, capturing request data for monitoring purposes
		$post_data = ! empty( $_POST ) ? map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) : array();
		$post_data = $this->redact_fields( $post_data );

		// Capture raw body (for JSON / REST payloads).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading php://input for raw request body logging
		$raw_body = file_get_contents( 'php://input' );

		if ( false === $raw_body ) {
			$raw_body = '';
		}

		if ( strlen( $raw_body ) > self::MAX_BODY_SIZE ) {
			$raw_body = substr( $raw_body, 0, self::MAX_BODY_SIZE );
		}

		// Preserve JSON structure: sanitize_text_field would strip HTML tags and line breaks.
		$raw_body = wp_check_invalid_utf8( $raw_body, true );
		$raw_body = $this->redact_raw_body( $raw_body );

		// Capture cookie names only (not values, for security).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only reading cookie names for logging, values are not captured
		$cookie_names = ! empty( $_COOKIE ) ? array_map( 'sanitize_text_field', array_keys( $_COOKIE ) ) : array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'UNKNOWN';

		$meta = array(
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			'referer'    => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : 'none',
		);

		// For cron requests, capture the hook names that are about to run.
		// Captured here at wp_loaded — wp-cron.php hasn't dispatched them yet,
		// so wp_get_ready_cron_jobs() still returns the full set.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$meta['cron_hooks'] = $this->get_ready_cron_hooks();
		}

		$this->request_data_captured = array(
			'endpoint' => array(
				'action' => $action,
				'url'    => $request_uri,
				'method' => $request_method,
			),
			'request'  => array(
				'headers'      => $request_headers,
				'query_params' => $query_params,
				'post_data'    => $post_data,
				'raw_body'     => $raw_body,
				'cookies'      => $cookie_names,
			),
			'meta'     => $meta,
		);

		// Start an output buffer so we can read the response body in the
		// shutdown phase. We deliberately do NOT use an ob_start callback:
		// PHP runs registered shutdown functions BEFORE flushing output
		// buffers, so a callback would fire too late for logging.
		//
		// ## Buffer pairing
		//
		// This ob_start() is paired with the cascade-flush + ob_end_flush()
		// block in log_network_request() (Phase 2, registered on the
		// `shutdown` action at priority 0 by the constructor). That handler
		// is the PRIMARY closer and runs in the normal request lifecycle.
		//
		// As defense-in-depth we also register a PHP-level shutdown function
		// below that closes the buffer if (and only if) Phase 2 did not run
		// — e.g., because a fatal error fired before WordPress reached the
		// shutdown hook, or the action was unregistered by another plugin.
		// This guarantees the buffer is always closed within the same
		// request, even when WordPress's shutdown hook chain breaks.
		ob_start();
		$this->our_ob_level = ob_get_level();

		// Defensive close: idempotent — does nothing if Phase 2 already
		// closed our buffer. Captures $this and the level by-value so the
		// closure does not pin the controller in memory past shutdown.
		$our_level = $this->our_ob_level;
		register_shutdown_function(
			static function () use ( $our_level ) {
				while ( ob_get_level() >= $our_level && $our_level > 0 ) {
					ob_end_flush();
					if ( ob_get_level() < $our_level ) {
						break;
					}
				}
			}
		);
	}

	/**
	 * Phase 2: Log request with response data on shutdown.
	 *
	 * Captures the response status, headers, body, and timing/memory
	 * information, then persists the complete request/response log to
	 * the database with a request-type tag.
	 *
	 * @return void
	 */
	public function log_network_request() {

		// Only proceed if Phase 1 captured request data.
		if ( null === $this->request_data_captured ) {
			return;
		}

		// Classify at shutdown so REST_REQUEST etc. are reliably defined.
		$request_type = $this->detect_request_type();

		// Read the response body from our output buffer BEFORE PHP flushes
		// it. If other code added buffers above ours, cascade-flush them
		// down so our buffer holds the final output.
		$raw_response = '';
		if ( null !== $this->our_ob_level ) {
			while ( ob_get_level() > $this->our_ob_level ) {
				ob_end_flush();
			}
			if ( ob_get_level() >= $this->our_ob_level ) {
				$raw_response = (string) ob_get_contents();
				// Hand the buffer back to the next layer so the client still gets the response.
				ob_end_flush();
			}
		}

		$response_truncated = strlen( $raw_response ) > self::MAX_BODY_SIZE;
		if ( $response_truncated ) {
			$raw_response = substr( $raw_response, 0, self::MAX_BODY_SIZE );
		}
		$response_body = wp_check_invalid_utf8( $raw_response, true );

		// Capture response headers (with sensitive values redacted, capped).
		$response_headers = $this->redact_headers(
			array_map( 'sanitize_text_field', $this->parse_response_headers( headers_list() ) )
		);

		// Capture final HTTP status code (default to 200 when not explicitly set).
		$status_code = (int) ( http_response_code() ?: 200 );

		// Calculate request duration.
		$duration_ms = null !== $this->start_time
			? round( ( microtime( true ) - $this->start_time ) * 1000, 2 )
			: 0;

		// Calculate peak memory usage.
		$memory_peak_mb = round( memory_get_peak_usage( true ) / 1048576, 2 );

		// Resolve current user, if any.
		$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$user_login = '';
		if ( $user_id > 0 && function_exists( 'wp_get_current_user' ) ) {
			$current_user = wp_get_current_user();
			if ( $current_user && ! empty( $current_user->user_login ) ) {
				$user_login = sanitize_text_field( $current_user->user_login );
			}
		}

		// Build complete log data.
		$data = array_merge(
			$this->request_data_captured,
			array(
				'response' => array(
					'status_code'    => $status_code,
					'headers'        => $response_headers,
					'body'           => $response_body,
					'body_truncated' => $response_truncated,
				),
			)
		);

		$data['meta']['request_type']     = $request_type;
		$data['meta']['user_id']          = $user_id;
		$data['meta']['user_login']       = $user_login;
		$data['meta']['duration_ms']      = $duration_ms;
		$data['meta']['memory_peak_mb']   = $memory_peak_mb;
		$data['meta']['request_time']     = current_time( 'mysql' );
		$data['meta']['performance_flag'] = $this->classify_performance( $duration_ms );
		$data['meta']['status_flag']      = $this->classify_status( $status_code );

		$option            = 'default_ajax_logs';
		$log_type          = $request_type . '_log';
		$activity_log_data = new MonitoringLogController();
		$activity_log_data->wptw_send_log_report(
			$data,
			$option,
			$log_type,
			null,
			'',
			array(
				'action'      => $this->request_data_captured['endpoint']['action'] ?? '',
				'facet_2'     => $this->request_data_captured['endpoint']['method'] ?? '',
				'ip_address'  => IpService::get_client_ip(),
			)
		);
	}

	/**
	 * Classify request duration into a UI-facing performance flag.
	 *
	 * Buckets:
	 *  - normal   — < PERF_WARNING_MS  (default 1000ms)
	 *  - warning  — >= PERF_WARNING_MS
	 *  - bad      — >= PERF_BAD_MS     (default 3000ms)
	 *  - critical — >= PERF_CRITICAL_MS (default 5000ms)
	 *
	 * Filterable via `wptw_network_log_performance_flag` for downstream
	 * code that needs different thresholds or labels.
	 *
	 * @param float|int $ms Request duration in milliseconds.
	 *
	 * @return string One of: normal|warning|bad|critical.
	 */
	private function classify_performance( $ms ) {
		$ms = (float) $ms;
		if ( $ms >= self::PERF_CRITICAL_MS ) {
			$flag = 'critical';
		} elseif ( $ms >= self::PERF_BAD_MS ) {
			$flag = 'bad';
		} elseif ( $ms >= self::PERF_WARNING_MS ) {
			$flag = 'warning';
		} else {
			$flag = 'normal';
		}

		/**
		 * Filter the performance flag for a logged request.
		 *
		 * @param string $flag Computed flag (normal|warning|bad|critical).
		 * @param float  $ms   Request duration in milliseconds.
		 */
		return (string) apply_filters( 'wptw_network_log_performance_flag', $flag, $ms );
	}

	/**
	 * Classify HTTP status code into a UI-facing status flag.
	 *
	 * Buckets:
	 *  - success      — 2xx
	 *  - redirect     — 3xx
	 *  - blocked      — 401, 403, 429 (auth/permission/rate-limit)
	 *  - failed       — other 4xx
	 *  - server_error — 5xx
	 *  - unknown      — anything else / 0
	 *
	 * Filterable via `wptw_network_log_status_flag`.
	 *
	 * @param int $code HTTP status code.
	 *
	 * @return string One of: success|redirect|blocked|failed|server_error|unknown.
	 */
	private function classify_status( $code ) {
		$code = (int) $code;
		if ( $code >= 200 && $code < 300 ) {
			$flag = 'success';
		} elseif ( $code >= 300 && $code < 400 ) {
			$flag = 'redirect';
		} elseif ( 401 === $code || 403 === $code || 429 === $code ) {
			$flag = 'blocked';
		} elseif ( $code >= 400 && $code < 500 ) {
			$flag = 'failed';
		} elseif ( $code >= 500 && $code < 600 ) {
			$flag = 'server_error';
		} else {
			$flag = 'unknown';
		}

		/**
		 * Filter the status flag for a logged request.
		 *
		 * @param string $flag Computed flag (success|redirect|blocked|failed|server_error|unknown).
		 * @param int    $code HTTP status code.
		 */
		return (string) apply_filters( 'wptw_network_log_status_flag', $flag, $code );
	}

	/**
	 * Derive a meaningful "action" label for the captured request.
	 *
	 * Type-aware so each request kind gets the right thing:
	 *  - AJAX:    $_REQUEST['action']  (e.g. "heartbeat")
	 *  - REST:    parsed route         (e.g. "/wp/v2/users/me")
	 *  - Cron:    "wp_cron"            (the hook list lives in meta.cron_hooks)
	 *  - XML-RPC: "xmlrpc"
	 *  - Other:   $_REQUEST['action'] or "Unknown"
	 *
	 * @return string Action label (max 256 chars).
	 */
	private function derive_action() {
		$action = '';

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Network logging hook
			$action = isset( $_REQUEST['action'] )
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Network logging hook
				? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) )
				: 'admin-ajax';
		} elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$action = 'wp_cron';
		} elseif ( $this->is_rest_request() ) {
			$route  = $this->extract_rest_route();
			$action = ( '' !== $route ) ? $route : 'rest';
		} elseif ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$action = 'xmlrpc';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Network logging hook
			$action = isset( $_REQUEST['action'] )
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Network logging hook
				? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) )
				: 'Unknown';
		}

		if ( strlen( $action ) > 256 ) {
			$action = substr( $action, 0, 256 );
		}

		return $action;
	}

	/**
	 * Pull the REST route from REQUEST_URI.
	 *
	 * Handles both pretty-permalink form (`/wp-json/wp/v2/posts`) and the
	 * plain-permalink fallback (`/?rest_route=/wp/v2/posts`). Honors a
	 * customized prefix via rest_get_url_prefix().
	 *
	 * @return string Route like "/wp/v2/users/me", or "" if not found.
	 */
	private function extract_rest_route() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Read-only; sanitized below.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '';
		}

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( null === $path || false === $path ) {
			$path = $uri;
		}

		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$marker = '/' . $prefix . '/';
		$pos    = strpos( $path, $marker );
		if ( false !== $pos ) {
			$route = substr( $path, $pos + strlen( $marker ) );
			$route = '/' . trim( $route, '/' );
			return sanitize_text_field( $route );
		}

		// ?rest_route=/wp/v2/posts form.
		$query = wp_parse_url( $uri, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$params = array();
			parse_str( $query, $params );
			if ( ! empty( $params['rest_route'] ) ) {
				return sanitize_text_field( (string) $params['rest_route'] );
			}
		}

		return '';
	}

	/**
	 * List the cron hooks scheduled to run for the current cron tick.
	 *
	 * Called at wp_loaded BEFORE wp-cron.php iterates the queue, so the
	 * returned set matches what is about to execute.
	 *
	 * @return string[] Unique hook names (may be empty).
	 */
	private function get_ready_cron_hooks() {
		$hooks = array();
		if ( ! function_exists( 'wp_get_ready_cron_jobs' ) ) {
			return $hooks;
		}
		$crons = wp_get_ready_cron_jobs();
		if ( ! is_array( $crons ) ) {
			return $hooks;
		}
		foreach ( $crons as $cronhooks ) {
			if ( is_array( $cronhooks ) ) {
				foreach ( array_keys( $cronhooks ) as $hook ) {
					$hooks[] = sanitize_text_field( (string) $hook );
				}
			}
		}
		return array_values( array_unique( $hooks ) );
	}

	/**
	 * Decide whether the current request is an API-style call we should log.
	 *
	 * Runs at `wp_loaded`, where REST_REQUEST is not yet defined — we
	 * therefore detect REST by URI prefix in addition to the constant.
	 * Anything that is not AJAX/REST/cron/XML-RPC is treated as a page
	 * load and skipped.
	 *
	 * @return bool True if the request should be captured and logged.
	 */
	private function is_loggable_request() {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return true;
		}
		if ( $this->is_rest_request() ) {
			return true;
		}
		return false;
	}

	/**
	 * Detect a REST API request before WP_REST_Server has had a chance
	 * to define REST_REQUEST (which happens after wp_loaded fires).
	 *
	 * @return bool True if this is a REST API request.
	 */
	private function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Read-only routing classification; sanitized below.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}

		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		if ( false !== strpos( $uri, '/' . $prefix . '/' ) ) {
			return true;
		}

		if ( false !== strpos( $uri, 'rest_route=' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect the WordPress request type for the current request.
	 *
	 * Called at shutdown, after REST_REQUEST etc. are reliably defined.
	 * In normal operation only ajax/rest/cron/xmlrpc are returned, since
	 * is_loggable_request() prevents admin/frontend captures upstream.
	 *
	 * @return string One of: ajax|rest|cron|xmlrpc|admin|frontend.
	 */
	private function detect_request_type() {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return 'admin';
		}
		return 'frontend';
	}

	/**
	 * Redact sensitive header values and cap each value's length.
	 *
	 * @param array $headers Header name => value pairs.
	 *
	 * @return array Headers with sensitive values replaced and long values truncated.
	 */
	private function redact_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return array();
		}

		/**
		 * Filter the list of sensitive header names that get redacted.
		 *
		 * @param string[] $names Lowercased header names.
		 */
		$sensitive = apply_filters( 'wptw_network_log_sensitive_headers', self::$sensitive_headers );

		foreach ( $headers as $name => $value ) {
			if ( in_array( strtolower( (string) $name ), $sensitive, true ) ) {
				$headers[ $name ] = '[redacted]';
			} else {
				$headers[ $name ] = $this->truncate_value( (string) $value );
			}
		}

		return $headers;
	}

	/**
	 * Recursively redact sensitive field values in $_GET/$_POST style arrays.
	 *
	 * @param mixed $data Array (possibly nested) of request fields.
	 *
	 * @return mixed Same shape with sensitive leaf values replaced.
	 */
	private function redact_fields( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		/**
		 * Filter the list of sensitive field names that get redacted in
		 * $_GET / $_POST and the raw request body.
		 *
		 * @param string[] $names Lowercased field names.
		 */
		$sensitive = apply_filters( 'wptw_network_log_sensitive_fields', self::$sensitive_fields );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->redact_fields( $value );
			} elseif ( in_array( strtolower( (string) $key ), $sensitive, true ) ) {
				$data[ $key ] = '[redacted]';
			}
		}

		return $data;
	}

	/**
	 * Best-effort redaction of credential fields inside a raw request body.
	 *
	 * Handles application/x-www-form-urlencoded and JSON payloads. Multipart
	 * bodies and unusual encodings are left as-is (callers can extend via the
	 * `wptw_network_log_sensitive_fields` filter if needed).
	 *
	 * @param string $body Raw request body.
	 *
	 * @return string Body with credential values replaced by `[redacted]`.
	 */
	private function redact_raw_body( $body ) {
		if ( '' === $body || null === $body ) {
			return (string) $body;
		}

		$sensitive = apply_filters( 'wptw_network_log_sensitive_fields', self::$sensitive_fields );

		foreach ( $sensitive as $field ) {
			$field_q = preg_quote( $field, '/' );

			// form-encoded: field=value (until & or end).
			$body = preg_replace(
				'/(?<![A-Za-z0-9_])' . $field_q . '=([^&]*)/i',
				$field . '=[redacted]',
				$body
			);

			// JSON: "field":"value" (string values only; numeric/bool kept).
			$body = preg_replace(
				'/"' . $field_q . '"\s*:\s*"[^"]*"/i',
				'"' . $field . '":"[redacted]"',
				$body
			);
		}

		return $body;
	}

	/**
	 * Convert PHP's headers_list() output ("Name: value") into an
	 * associative array of name => value, so it can be redacted by name.
	 *
	 * @param array $headers_list Raw output of headers_list().
	 *
	 * @return array Associative header map.
	 */
	private function parse_response_headers( $headers_list ) {
		$result = array();

		foreach ( (array) $headers_list as $entry ) {
			$pos = strpos( $entry, ':' );
			if ( false === $pos ) {
				continue;
			}
			$name  = trim( substr( $entry, 0, $pos ) );
			$value = trim( substr( $entry, $pos + 1 ) );
			if ( '' !== $name ) {
				$result[ $name ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Truncate an individual value (e.g. header) to MAX_HEADER_VALUE_SIZE.
	 *
	 * @param string $value Value to cap.
	 *
	 * @return string Possibly truncated value with a "...[truncated]" suffix.
	 */
	private function truncate_value( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_HEADER_VALUE_SIZE ) {
			return substr( $value, 0, self::MAX_HEADER_VALUE_SIZE ) . '...[truncated]';
		}
		return $value;
	}

	/**
	 * Collect all request headers.
	 *
	 * Uses getallheaders() if available, otherwise parses HTTP_* server variables.
	 *
	 * @return array Associative array of header name => value.
	 */
	private function get_request_headers() {
		$headers = array();

		if ( function_exists( 'getallheaders' ) ) {
			$all_headers = getallheaders();

			if ( is_array( $all_headers ) ) {
				foreach ( $all_headers as $name => $value ) {
					$headers[ sanitize_text_field( $name ) ] = sanitize_text_field( $value );
				}
			}

			return $headers;
		}

		// Fallback: parse from $_SERVER HTTP_* variables.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variables sanitized below for logging
		foreach ( $_SERVER as $key => $value ) {
			if ( 0 === strpos( $key, 'HTTP_' ) ) {
				$header_name = str_replace( '_', '-', substr( $key, 5 ) );
				$header_name = ucwords( strtolower( $header_name ), '-' );

				$headers[ sanitize_text_field( $header_name ) ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		// Include Content-Type and Content-Length which don't have HTTP_ prefix.
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			$headers['Content-Type'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
		}

		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			$headers['Content-Length'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) );
		}

		return $headers;
	}
}