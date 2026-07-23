<?php
/**
 * Log Handler Interface
 *
 * Defines the contract for log handlers (where logs are written).
 *
 * @package    Tailwatch
 * @subpackage Logging/Handlers
 */

namespace Tailwatch\Admin\App\Api\Logging\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface LogHandlerInterface
 *
 * Contract for log handlers that write logs to various destinations.
 *
 */
interface LogHandlerInterface {

	/**
	 * Check if handler accepts this log level.
	 *
	 * @param string $level Log level.
	 *
	 * @return bool True if handler accepts this level, false otherwise.
	 */
	public function handles( $level );

	/**
	 * Handle the log record.
	 *
	 * @param array $record Log record array with level, message, context, datetime, extra.
	 *
	 * @return void
	 */
	public function handle( array $record );
}
