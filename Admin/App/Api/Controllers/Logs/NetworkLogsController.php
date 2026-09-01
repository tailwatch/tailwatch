<?php
/**
 * Network / AJAX Request Logger
 *
 * Records lightweight METADATA for API-style WordPress traffic — REST API,
 * admin-ajax, cron, and XML-RPC — so the dashboard can surface recent internal
 * requests, their status, and timing.
 *
 * ## Design: WordPress-native capture, no raw bodies
 *
 * This intentionally does NOT read `php://input`, does NOT dump raw
 * `$_POST`/`$_GET`/`$_COOKIE`, and does NOT buffer or store request/response
 * BODIES. Those patterns capture arbitrary payloads (and are exactly what
 * plugin review flags). Instead:
 *
 *  - REST requests are captured through the official `rest_post_dispatch`
 *    filter, which hands us a fully parsed {@see WP_REST_Request} (route,
 *    method, params, headers) and the {@see WP_HTTP_Response} — no superglobal
 *    or stream reads at all. This is the same hook Query Monitor uses.
 *  - admin-ajax / cron / XML-RPC are captured on `shutdown` (they have no
 *    dispatch filter). Only the sanitized `action` and the request params are
 *    read from the superglobals, each passed through `sanitize_text_field` +
 *    `wp_unslash` and then redacted.
 *
 * All captured params/headers are run through a redaction pass that strips
 * credential, token, and nonce values before anything is stored, cookie VALUES
 * are never captured (names only), and every value is length-capped.
 *
 * Persistence reuses the shared monitoring-log pipeline (tw_logs). The feature
 * is opt-in (default off) and disclosed in readme.txt.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Logs
 */

namespace Tailwatch\Admin\App\Api\Controllers\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Services\IpService;

/**
 * Class NetworkLogsController
 *
 * Captures metadata for API-style traffic via WordPress-native hooks.
 */
class NetworkLogsController {

	/**
	 * Maximum stored length for any single captured value.
	 */
	const MAX_VALUE_SIZE = 1024;

	/**
	 * Header names (lowercased) whose VALUES are always redacted.
	 *
	 * @var string[]
	 */
	private static $sensitive_headers = array(
		'cookie',
		'set-cookie',
		'authorization',
		'proxy-authorization',
		'x-wp-nonce',
		'x-api-key',
		'x-auth-token',
	);

	/**
	 * Field names (lowercased) whose VALUES are redacted on an EXACT match.
	 *
	 * Kept exact (not substring) to avoid false positives on common REST params
	 * such as "author", "monkey", or "keyboard".
	 *
	 * @var string[]
	 */
	private static $sensitive_fields_exact = array(
		'pwd',
		'pass',
		'pass1',
		'pass2',
		'auth',
		'key',
		'otp',
		'pin',
		'card',
		'code',
		'cvv',
		'cvc',
		'nonce',
		'secret',
		'token',
	);

	/**
	 * Substrings that redact any field whose lowercased name CONTAINS them.
	 *
	 * @var string[]
	 */
	private static $sensitive_fields_contains = array(
		'password',
		'passwd',
		'user_pass',
		'secret',
		'token',
		'nonce',
		'apikey',
		'api_key',
		'private_key',
		'client_secret',
		'authorization',
		'credential',
		'session',
	);

	/**
	 * Register the capture hooks.
	 *
	 * The handlers are cheap no-ops until the feature is enabled and the request
	 * is actually API-style, so registering them unconditionally is safe.
	 */
	public function __construct() {
		// REST: official post-dispatch filter — structured, WP-parsed data.
		add_filter( 'rest_post_dispatch', array( $this, 'log_rest_request' ), PHP_INT_MAX, 3 );

		// admin-ajax / cron / XML-RPC: no dispatch filter exists, so capture on shutdown.
		add_action( 'shutdown', array( $this, 'log_non_rest_request' ), 0 );
	}

	/**
	 * Get this feature's stored settings.
	 *
	 * @return array
	 */
	public function get_features_options() {
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( 'default_feature_settings', 'default_network_logs', 1 );
	}

	/**
	 * Whether internal-request logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->get_features_options();
		return isset( $settings['field_1']['options']['option']['selected'] )
			&& true === $settings['field_1']['options']['option']['selected'];
	}

	/**
	 * Feature status in the {parent_enable, feature_enable} shape the logs
	 * endpoint and dashboard consume, matching the other log controllers.
	 *
	 * When the feature row is inactive the settings come back empty and the
	 * parent gate stays closed; otherwise the master toggle (field_1) drives
	 * feature_enable.
	 *
	 * @return array {
	 *     @type bool $parent_enable  Whether the feature row is active.
	 *     @type bool $feature_enable Whether request logging is switched on.
	 * }
	 */
	public function tailwatch_network_logs_is_enabled() {
		$feature_settings = $this->get_features_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$feature_enabled = isset( $feature_settings['field_1']['options']['option']['selected'] )
			&& true === $feature_settings['field_1']['options']['option']['selected'];

		return array(
			'parent_enable'  => true,
			'feature_enable' => $feature_enabled,
		);
	}

	/**
	 * Capture a REST request via the official rest_post_dispatch filter.
	 *
	 * Reads everything from the parsed request/response objects — no superglobal
	 * or php://input access. Always returns $result unchanged.
	 *
	 * @param mixed            $result  Response, usually a WP_HTTP_Response / WP_REST_Response.
	 * @param mixed            $server  REST server instance (unused).
	 * @param \WP_REST_Request $request The dispatched request.
	 *
	 * @return mixed The unmodified $result.
	 */
	public function log_rest_request( $result, $server, $request ) {
		unset( $server );

		if ( ! ( $request instanceof \WP_REST_Request ) || ! $this->is_enabled() ) {
			return $result;
		}

		$route = (string) $request->get_route();

		// Skip Tailwatch's own Connect REST traffic (tailwatch/v1) so the log is
		// not flooded with the plugin's own dashboard / mobile-app calls.
		if ( 0 === strpos( $route, '/tailwatch/' ) ) {
			return $result;
		}

		$status = ( $result instanceof \WP_HTTP_Response )
			? (int) $result->get_status()
			: $this->current_status_code();

		$params  = $this->redact_fields( $this->stringify_scalars( $request->get_params() ) );
		$headers = $this->redact_headers( $this->flatten_headers( $request->get_headers() ) );

		$this->persist(
			'rest',
			$route,
			(string) $request->get_method(),
			$status,
			$params,
			$headers
		);

		return $result;
	}

	/**
	 * Capture an admin-ajax / cron / XML-RPC request on shutdown.
	 *
	 * REST requests are excluded here (they are handled by log_rest_request), so
	 * nothing is double-logged. Reads only the sanitized `action` and request
	 * params from the superglobals; no raw body, no php://input, no ob_start.
	 *
	 * @return void
	 */
	public function log_non_rest_request() {
		$is_ajax   = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$is_cron   = defined( 'DOING_CRON' ) && DOING_CRON;
		$is_xmlrpc = defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;

		if ( ! $is_ajax && ! $is_cron && ! $is_xmlrpc ) {
			return;
		}
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( $is_ajax ) {
			$type = 'ajax';
		} elseif ( $is_cron ) {
			$type = 'cron';
		} else {
			$type = 'xmlrpc';
		}

		// Sanitized action (admin-ajax). Passive telemetry — no state change — so
		// a nonce is structurally inapplicable; the value is sanitized before use.
		// phpcs:ignore WordPress.Security.NonceVerification -- passive request logging, not a state-changing action.
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification -- passive request logging; values sanitized + redacted below.
		$get_params = ! empty( $_GET ) ? map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification -- passive request logging; values sanitized + redacted below.
		$post_params = ! empty( $_POST ) ? map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) : array();

		$params = $this->redact_fields(
			array_merge(
				is_array( $get_params ) ? $get_params : array(),
				is_array( $post_params ) ? $post_params : array()
			)
		);

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: 'UNKNOWN';

		$route  = '' !== $action ? $action : $type;
		$status = $this->current_status_code();

		$this->persist( $type, $route, $method, $status, $params, array() );
	}

	/**
	 * Build the log payload and hand it to the shared monitoring-log pipeline.
	 *
	 * @param string $type    Request type slug (rest|ajax|cron|xmlrpc).
	 * @param string $route   Route or action.
	 * @param string $method  HTTP method.
	 * @param int    $status  HTTP status code.
	 * @param array  $params  Redacted request params.
	 * @param array  $headers Redacted request headers.
	 *
	 * @return void
	 */
	private function persist( $type, $route, $method, $status, $params, $headers ) {
		$duration_ms = 0.0;
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$started     = (float) $_SERVER['REQUEST_TIME_FLOAT'];
			$duration_ms = $started > 0 ? round( ( microtime( true ) - $started ) * 1000, 2 ) : 0.0;
		}

		$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$user_login = '';
		if ( $user_id > 0 ) {
			$current_user = wp_get_current_user();
			if ( $current_user && ! empty( $current_user->user_login ) ) {
				$user_login = sanitize_text_field( $current_user->user_login );
			}
		}

		$data = array(
			'endpoint' => array(
				'action' => $this->truncate_value( $route ),
				'method' => $this->truncate_value( $method ),
				'url'    => $this->truncate_value( $route ),
			),
			'request'  => array(
				'query_params' => array(),
				'post_data'    => $params,
				'headers'      => $headers,
			),
			'response' => array(
				'status_code' => $status,
			),
			'meta'     => array(
				'request_type'   => $type,
				'status_code'    => $status,
				'duration_ms'    => $duration_ms,
				'memory_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 2 ),
				'user_id'        => $user_id,
				'user_login'     => $user_login,
				'ip_address'     => IpService::get_client_ip(),
				'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
				'request_time'   => current_time( 'mysql' ),
			),
		);

		// Log entries persist under the 'default_network_logs' group, matching the
		// feature option. The Database Optimizer prunes this same group in
		// tailwatch_clean_network_logs, so retention continues to apply.
		$monitoring = new MonitoringLogController();
		$monitoring->tailwatch_send_log_report(
			$data,
			'default_network_logs',
			$type . '_log',
			null,
			'',
			array(
				'action'     => $route,
				'facet_2'    => $method,
				'ip_address' => IpService::get_client_ip(),
			)
		);
	}

	/**
	 * Recursively redact sensitive field VALUES in a params array.
	 *
	 * @param mixed $data Array (possibly nested) of request fields.
	 *
	 * @return mixed Same shape with sensitive leaf values replaced by [redacted].
	 */
	private function redact_fields( $data ) {
		if ( ! is_array( $data ) ) {
			return $this->truncate_value( (string) $data );
		}

		/**
		 * Filter the exact + substring sensitive-field lists used for redaction.
		 *
		 * @param array $lists { 'exact': string[], 'contains': string[] }.
		 */
		$lists    = apply_filters(
			'tailwatch_network_log_sensitive_fields',
			array(
				'exact'    => self::$sensitive_fields_exact,
				'contains' => self::$sensitive_fields_contains,
			)
		);
		$exact    = isset( $lists['exact'] ) && is_array( $lists['exact'] ) ? $lists['exact'] : self::$sensitive_fields_exact;
		$contains = isset( $lists['contains'] ) && is_array( $lists['contains'] ) ? $lists['contains'] : self::$sensitive_fields_contains;

		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ $key ] = $this->redact_fields( $value );
				continue;
			}

			if ( $this->is_sensitive_key( (string) $key, $exact, $contains ) ) {
				$out[ $key ] = '[redacted]';
			} else {
				$out[ $key ] = $this->truncate_value( (string) $value );
			}
		}

		return $out;
	}

	/**
	 * Whether a field name is sensitive (exact match or contains a token).
	 *
	 * @param string   $key      Field name.
	 * @param string[] $exact    Exact-match sensitive names.
	 * @param string[] $contains Substring sensitive tokens.
	 *
	 * @return bool
	 */
	private function is_sensitive_key( $key, $exact, $contains ) {
		$lower = strtolower( $key );

		if ( in_array( $lower, $exact, true ) ) {
			return true;
		}

		foreach ( $contains as $needle ) {
			if ( '' !== $needle && false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redact sensitive header VALUES and cap the rest.
	 *
	 * @param array $headers Header name => value pairs.
	 *
	 * @return array
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
		$sensitive = apply_filters( 'tailwatch_network_log_sensitive_headers', self::$sensitive_headers );

		$out = array();
		foreach ( $headers as $name => $value ) {
			if ( in_array( strtolower( (string) $name ), $sensitive, true ) ) {
				$out[ $name ] = '[redacted]';
			} else {
				$out[ $name ] = $this->truncate_value( (string) $value );
			}
		}

		return $out;
	}

	/**
	 * Flatten a WP_REST_Request headers array (name => string[]) to name => string,
	 * sanitizing each value.
	 *
	 * @param array $headers Headers as returned by WP_REST_Request::get_headers().
	 *
	 * @return array
	 */
	private function flatten_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return array();
		}

		$out = array();
		foreach ( $headers as $name => $value ) {
			$joined       = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$out[ $name ] = sanitize_text_field( $joined );
		}

		return $out;
	}

	/**
	 * Sanitize every scalar leaf of a params array with sanitize_text_field.
	 * Structure (nested arrays) is preserved; objects are dropped to a string.
	 *
	 * @param mixed $data Params from WP_REST_Request::get_params().
	 *
	 * @return mixed
	 */
	private function stringify_scalars( $data ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $value ) {
				$out[ $key ] = $this->stringify_scalars( $value );
			}
			return $out;
		}

		if ( is_scalar( $data ) ) {
			return sanitize_text_field( (string) $data );
		}

		return '';
	}

	/**
	 * Current HTTP status code, defaulting to 200 when unavailable (e.g. CLI/cron).
	 *
	 * @return int
	 */
	private function current_status_code() {
		$code = http_response_code();
		return ( is_int( $code ) && $code > 0 ) ? $code : 200;
	}

	/**
	 * Cap a single value to MAX_VALUE_SIZE.
	 *
	 * @param string $value Value to cap.
	 *
	 * @return string
	 */
	private function truncate_value( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_VALUE_SIZE ) {
			return substr( $value, 0, self::MAX_VALUE_SIZE ) . '…';
		}
		return $value;
	}
}
