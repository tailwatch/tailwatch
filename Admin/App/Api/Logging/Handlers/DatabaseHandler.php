<?php
/**
 * Database Handler
 *
 * Writes logs to the custom database table using existing DBModel.
 * Implements batch inserts for better performance.
 *
 * @package    Tailwatch
 * @subpackage Logging/Handlers
 */

namespace Tailwatch\Admin\App\Api\Logging\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Logger;
use Tailwatch\Admin\App\Api\Logging\Handlers\LogHandlerInterface;
use Tailwatch\Admin\App\Api\Models\DBModel;

/**
 * Class DatabaseHandler
 *
 * Handles writing logs to the database table.
 * Uses batch inserts for performance optimization.
 *
 */
class DatabaseHandler implements LogHandlerInterface {

	/**
	 * Log levels this handler accepts.
	 *
	 * @var array<string>
	 */
	private $levels;

	/**
	 * Log type (e.g., 'error_logs', 'activity_logs').
	 *
	 * @var string
	 */
	private $log_type;

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
	 * Batch array for collecting logs before insert.
	 *
	 * @var array
	 */
	private $batch = array();

	/**
	 * Batch size before flushing to database.
	 *
	 * @var int
	 */
	private $batch_size = 10;

	/**
	 * DBModel instance.
	 *
	 * @var DBModel
	 */
	private $db_model;

	/**
	 * Flushing flag to prevent race conditions.
	 *
	 * @var bool
	 */
	private $flushing = false;

	/**
	 * Constructor.
	 *
	 * @param array  $levels  Log levels this handler accepts.
	 * @param string $log_type Log type identifier.
	 */
	public function __construct( array $levels, $log_type ) {
		$this->levels   = $levels;
		$this->log_type = sanitize_text_field( $log_type );
		$this->db_model = new DBModel();

		// Flush batch on shutdown to ensure all logs are written.
		register_shutdown_function( array( $this, 'flush' ) );
	}

	/**
	 * Check if handler accepts this log level.
	 *
	 * @param string $level Log level.
	 *
	 * @return bool True if handler accepts this level, false otherwise.
	 */
	public function handles( $level ) {
		return in_array( $level, $this->levels, true );
	}

	/**
	 * Handle the log record.
	 *
	 * Adds record to batch and flushes if batch is full.
	 *
	 * @param array $record Log record array.
	 *
	 * @return void
	 */
	public function handle( array $record ) {
		// Add to batch.
		$this->batch[] = $this->format_record( $record );

		// Flush if batch is full.
		if ( count( $this->batch ) >= $this->batch_size ) {
			$this->flush();
		}
	}

	/**
	 * Format log record for database insertion.
	 *
	 * Converts log record to database row format compatible with existing structure.
	 *
	 * @param array $record Log record array.
	 *
	 * @return array Formatted database row data.
	 */
	private function format_record( array $record ) {
		$context = isset( $record['context'] ) ? $record['context'] : array();
		$extra   = isset( $record['extra'] ) ? $record['extra'] : array();

		// Extract feature and action from context.
		$feature = isset( $context['feature'] ) ? sanitize_text_field( $context['feature'] ) : 'general';
		$action  = isset( $context['action'] ) ? sanitize_text_field( $context['action'] ) : '';

		// Build value data (stored as JSON).
		$value_data = array(
			'message' => $record['message'],
			'context' => $context,
			'extra'   => $extra,
		);

		// Add exception/error trace if available.
		if ( isset( $context['exception'] ) ) {
			$value_data['trace'] = $this->format_exception( $context['exception'] );
		} elseif ( isset( $context['error'] ) ) {
			$value_data['error'] = is_string( $context['error'] ) ? sanitize_text_field( $context['error'] ) : wp_json_encode( $context['error'] );
		}

		// Map PSR-3 levels to priority for backward compatibility.
		$priority_map = array(
			Logger::EMERGENCY => 'high',
			Logger::ALERT     => 'high',
			Logger::CRITICAL  => 'high',
			Logger::ERROR     => 'high',
			Logger::WARNING   => 'medium',
			Logger::NOTICE    => 'medium',
			Logger::INFO      => 'low',
			Logger::DEBUG     => 'low',
		);

		$priority = isset( $priority_map[ $record['level'] ] ) ? $priority_map[ $record['level'] ] : 'medium';

		return array(
			'user_id'       => isset( $extra['user_id'] ) ? absint( $extra['user_id'] ) : 0,
			'child_of'      => 0,
			'key'           => $feature,
			'option'        => $priority,
			'value'         => wp_json_encode( $value_data ),
			'type'          => $this->log_type,
			'type_state'    => 'active',
			'date_created'  => $record['datetime'],
			'date_modified' => $record['datetime'],
			'is_active'     => 1,
		);
	}

	/**
	 * Format exception for logging.
	 *
	 * Uses proper exception getter methods instead of accessing protected properties.
	 *
	 * @param mixed $exception Exception object or array.
	 *
	 * @return array|string Formatted exception data.
	 */
	private function format_exception( $exception ) {
		if ( is_object( $exception ) && ( $exception instanceof \Exception || $exception instanceof \Throwable ) ) {
			$data = array(
				'message' => sanitize_text_field( $exception->getMessage() ),
				'code'    => $exception->getCode(),
			);
			// Include file paths and traces only when WP_DEBUG is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$data['file']  = sanitize_text_field( $exception->getFile() );
				$data['line']  = absint( $exception->getLine() );
				$data['trace'] = $exception->getTraceAsString();
			}
			return $data;
		} elseif ( is_array( $exception ) ) {
			$data = array(
				'message' => isset( $exception['message'] ) ? sanitize_text_field( $exception['message'] ) : 'Unknown error',
			);
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$data['file'] = isset( $exception['file'] ) ? sanitize_text_field( $exception['file'] ) : 'unknown';
				$data['line'] = isset( $exception['line'] ) ? absint( $exception['line'] ) : 0;
			}
			return $data;
		}

		return sanitize_text_field( (string) $exception );
	}

	/**
	 * Flush batch to database.
	 *
	 * Writes all batched logs to database using true bulk insert for performance.
	 * Includes error handling, constant validation, and race condition protection.
	 *
	 * @return void
	 */
	public function flush() {
		// Prevent concurrent flushes (race condition protection).
		if ( $this->flushing || empty( $this->batch ) ) {
			return;
		}

		$this->flushing = true;

		try {
			// Validate constant exists.
			if ( ! defined( 'TAILWATCH_DB_LOGS_TABLE_NAME' ) ) {
				// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
				if ( $this->is_wp_error_log_enabled() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when database handler fails
					error_log( 'DatabaseHandler: TAILWATCH_DB_LOGS_TABLE_NAME constant not defined' );
				}
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;

			// Bulk insert: build a row-placeholder fragment per batch entry
			// (each is a code-literal '(%d, %d, %s, ...)' string) and bind every
			// value via prepare()'s args list. Table name passes through %i.
			$row_placeholder = '(%d, %d, %s, %s, %s, %s, %s, %s, %s, %d)';
			$row_placeholders = array_fill( 0, count( $this->batch ), $row_placeholder );

			$values = array( $table_name );
			foreach ( $this->batch as $data ) {
				$values[] = $data['user_id'];
				$values[] = $data['child_of'];
				$values[] = $data['key'];
				$values[] = $data['option'];
				$values[] = $data['value'];
				$values[] = $data['type'];
				$values[] = $data['type_state'];
				$values[] = $data['date_created'];
				$values[] = $data['date_modified'];
				$values[] = $data['is_active'];
			}

			$query = 'INSERT INTO %i (user_id, child_of, `key`, `option`, `value`, type, type_state, date_created, date_modified, is_active) VALUES '
				. implode( ', ', $row_placeholders );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query built from code-literal fragments only; all values (including table name) bound via prepare() args.
			$result = $wpdb->query( $wpdb->prepare( $query, $values ) );

			// Check for database errors.
			if ( false === $result ) {
				// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
				if ( $this->is_wp_error_log_enabled() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when database handler fails
					error_log(
						sprintf(
							'DatabaseHandler: Failed to insert %d log records. Error: %s',
							count( $this->batch ),
							$wpdb->last_error
						)
					);
				}

				// Try individual inserts as fallback.
				$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
				foreach ( $this->batch as $data ) {
					$this->db_model->insert_row( $data, $db_data_format, TAILWATCH_DB_LOGS_TABLE_NAME );
				}
			}
		} catch ( \Exception $e ) {
			// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
			if ( $this->is_wp_error_log_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when database handler fails
				error_log( 'DatabaseHandler flush failed: ' . $e->getMessage() );
			}
		} finally {
			// Always clear batch to prevent memory leaks.
			$this->batch    = array();
			$this->flushing = false;
		}
	}
}
