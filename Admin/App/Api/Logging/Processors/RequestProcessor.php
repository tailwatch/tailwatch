<?php
namespace Tailwatch\Admin\App\Api\Logging\Processors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequestProcessor implements LogProcessorInterface {

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
		// Check if running in CLI context (WP-CLI, cron jobs).
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

		$record['extra']['url']         = ! empty( $request_uri ) ? esc_url_raw( home_url( $request_uri ) ) : home_url();
		$record['extra']['method']      = $request_method;
		$record['extra']['ip']          = $remote_addr;
		$record['extra']['user_agent']  = $user_agent;
		$record['extra']['remote_port'] = $remote_port;

		return $record;
	}
}
