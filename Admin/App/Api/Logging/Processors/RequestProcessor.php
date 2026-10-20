<?php
namespace Tailwatch\Admin\App\Api\Logging\Processors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequestProcessor implements LogProcessorInterface {

	/**
	 * Query arguments that carry a credential: login and download links, core recovery and
	 * password-reset links, nonces, OAuth callbacks and Connect secrets. A log handler added
	 * through `tailwatch_log_handlers` receives the request URL, so these never reach it.
	 */
	const CREDENTIAL_QUERY_ARGS = array(
		'tailwatch_access_token',
		'token',
		'access_token',
		'rm_token',
		'rm_key',
		'key',
		'_wpnonce',
		'code',
		'state',
		'client_secret',
		'cookie_value',
		'auth_header_key',
	);

	/**
	 * Process log record and add request context.
	 *
	 * Handles both web and CLI contexts appropriately.
	 *
	 * @param array $record Log record array.
	 *
	 * @return array Modified log record with request context.
	 */
	public function process( array $record ) {
		// Check if running in CLI context (command-line tools, cron jobs).
		if ( php_sapi_name() === 'cli' ) {
			$record['extra']['context'] = 'cli';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
			$record['extra']['script'] = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : 'unknown';
			return $record;
		}

		// Web context - collect request information.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging
		$remote_port = isset( $_SERVER['REMOTE_PORT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_PORT'] ) ) : '';

		$record['extra']['url']         = $this->url_for_log( $request_uri );
		$record['extra']['method']      = $request_method;
		$record['extra']['ip']          = $remote_addr;
		$record['extra']['user_agent']  = $user_agent;
		$record['extra']['remote_port'] = $remote_port;

		return $record;
	}

	/**
	 * The request URL as it may be logged, with every credential argument removed.
	 *
	 * @param string $request_uri Request URI, already unslashed and escaped.
	 * @return string
	 */
	private function url_for_log( $request_uri ) {
		$url = '' !== (string) $request_uri ? home_url( $request_uri ) : home_url();

		return esc_url_raw( remove_query_arg( self::CREDENTIAL_QUERY_ARGS, $url ) );
	}
}
