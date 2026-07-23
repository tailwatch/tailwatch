<?php
/**
 * Log Facade
 *
 * Simple static interface for easy logging throughout the codebase.
 * Provides convenient access to the Logger singleton.
 *
 * @package    Tailwatch
 * @subpackage Logging
 *
 * Usage Examples:
 * Log::error('Payment failed', ['feature' => 'payment', 'action' => 'payment_failed', 'user_id' => 123]);
 * Log::info('User updated profile', ['feature' => 'user_profile', 'action' => 'profile_updated']);
 * Log::critical('Database connection failed', ['feature' => 'database', 'action' => 'connection_failed', 'exception' => $e]);
 */

namespace Tailwatch\Admin\App\Api\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log
 *
 * Static facade for easy logging access.
 *
 */
class Log {

	/**
	 * System is unusable.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function emergency( $message, array $context = array() ) {
		Logger::get_instance()->emergency( $message, $context );
	}

	/**
	 * Action must be taken immediately.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function alert( $message, array $context = array() ) {
		Logger::get_instance()->alert( $message, $context );
	}

	/**
	 * Critical conditions.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function critical( $message, array $context = array() ) {
		Logger::get_instance()->critical( $message, $context );
	}

	/**
	 * Error conditions.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		Logger::get_instance()->error( $message, $context );
	}

	/**
	 * Warning conditions.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function warning( $message, array $context = array() ) {
		Logger::get_instance()->warning( $message, $context );
	}

	/**
	 * Normal but significant.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function notice( $message, array $context = array() ) {
		Logger::get_instance()->notice( $message, $context );
	}

	/**
	 * Informational messages.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function info( $message, array $context = array() ) {
		Logger::get_instance()->info( $message, $context );
	}

	/**
	 * Debug-level messages.
	 *
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 *
	 * @return void
	 */
	public static function debug( $message, array $context = array() ) {
		Logger::get_instance()->debug( $message, $context );
	}
}
