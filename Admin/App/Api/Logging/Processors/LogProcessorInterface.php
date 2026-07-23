<?php
/**
 * Log Processor Interface
 *
 * Defines the contract for log processors (add context to logs).
 *
 * @package    Tailwatch
 * @subpackage Logging/Processors
 */

namespace Tailwatch\Admin\App\Api\Logging\Processors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface LogProcessorInterface
 *
 * Contract for log processors that add context to log records.
 *
 */
interface LogProcessorInterface {

	/**
	 * Process log record and add context.
	 *
	 * @param array $record Log record array.
	 *
	 * @return array Modified log record with added context.
	 */
	public function process( array $record );
}
