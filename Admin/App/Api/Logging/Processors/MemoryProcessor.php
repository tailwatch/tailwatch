<?php
namespace Tailwatch\Admin\App\Api\Logging\Processors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MemoryProcessor implements LogProcessorInterface {

	/**
	 * Process log record and add memory context.
	 *
	 * @param array $record Log record array.
	 *
	 * @return array Modified log record with memory context.
	 */
	public function process( array $record ) {
		$record['extra']['memory_usage'] = memory_get_usage( true );
		$record['extra']['memory_peak']  = memory_get_peak_usage( true );

		return $record;
	}
}
