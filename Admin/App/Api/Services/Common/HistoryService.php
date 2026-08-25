<?php
/**
 * History Service
 *
 * Provides centralized history/audit trail recording and retrieval for plugin, theme, and core actions.
 *
 * @package    Tailwatch
 * @subpackage Services/Common
 */

namespace Tailwatch\Admin\App\Api\Services\Common;

use Tailwatch\Admin\App\Api\Models\DBModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HistoryService
 *
 * Service class for recording and retrieving action history.
 * Uses existing tw_logs table with type='action_history' to distinguish from other logs.
 *
 */
class HistoryService {

	/**
	 * Allowed values for the `triggered_by` field.
	 *
	 * Identifies the origin of an action (which surface initiated it).
	 *
	 * @var string[]
	 */
	const ALLOWED_TRIGGERED_BY = array( 'system', 'mobile_app', 'manage_worker', 'wp_admin', 'cron' );

	/**
	 * Default `triggered_by` value when none is provided or the value is invalid.
	 *
	 * @var string
	 */
	const DEFAULT_TRIGGERED_BY = 'system';

	/**
	 * Normalize a triggered_by value against the whitelist, falling back to default.
	 *
	 * @param mixed $value Raw value (may be unsanitized request input).
	 *
	 * @return string Whitelisted triggered_by value.
	 */
	public static function normalize_triggered_by( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return self::DEFAULT_TRIGGERED_BY;
		}
		$sanitized = sanitize_text_field( $value );
		return in_array( $sanitized, self::ALLOWED_TRIGGERED_BY, true )
			? $sanitized
			: self::DEFAULT_TRIGGERED_BY;
	}

	/**
	 * Record an action to history.
	 *
	 * @param array $data      History data array. Supports an optional top-level
	 *                         `triggered_by` field identifying the action origin
	 *                         (system, web, manage_worker, wp_admin, cron).
	 * @param bool  $set_flag  Whether to set flag to prevent duplicate tracking from hooks.
	 *
	 * @return int|false History ID or false on failure.
	 */
	public static function record_action( array $data, $set_flag = false ) {
		// Validate required fields.
		$required = array( 'action_type', 'item_type', 'item_slug', 'action_status' );
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) || empty( $data[ $field ] ) ) {
				return false;
			}
		}

		// Normalize triggered_by (whitelist + default).
		$triggered_by = self::normalize_triggered_by( $data['triggered_by'] ?? null );

		// Set flag if requested (for actions from our plugin to prevent duplicate from hooks).
		if ( $set_flag ) {
			set_transient( 'tailwatch_history_tracking_in_progress', true, 30 ); // 30 seconds TTL.
		}

		// Get current user.
		$user    = wp_get_current_user();
		$user_id = $user->ID;

		// Capture request context.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging.
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging.
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Server variable sanitized below for logging.
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		// Build history value JSON.
		$history_value = array(
			'action_type'   => sanitize_text_field( $data['action_type'] ),
			'item_type'     => sanitize_text_field( $data['item_type'] ),
			'item_slug'     => sanitize_text_field( $data['item_slug'] ),
			'item_name'     => isset( $data['item_name'] ) ? sanitize_text_field( $data['item_name'] ) : $data['item_slug'],
			'action_status' => sanitize_text_field( $data['action_status'] ),
			'before_state'  => isset( $data['before_state'] ) ? $data['before_state'] : null,
			'after_state'   => isset( $data['after_state'] ) ? $data['after_state'] : null,
			'metadata'      => array_merge(
				isset( $data['metadata'] ) ? $data['metadata'] : array(),
				array(
					'ip_address'   => $ip_address,
					'user_agent'   => $user_agent,
					'request_uri'  => $request_uri,
					'triggered_by' => $triggered_by,
				)
			),
			'user'          => array(
				'user_id'    => absint( $user_id ),
				'user_login' => $user->user_login ?? '',
				'user_email' => $user->user_email ?? '',
				'user_roles' => is_array( $user->roles ) ? $user->roles : array(),
			),
		);

		// Determine key based on item_type.
		// Format: {type}_history:{item_slug} for fast indexed lookups.
		$key_prefix_map = array(
			'plugin' => 'plugin_history',
			'theme'  => 'theme_history',
			'core'   => 'core_history',
		);
		$key_prefix     = isset( $key_prefix_map[ $data['item_type'] ] )
			? $key_prefix_map[ $data['item_type'] ]
			: 'action_history';

		// Append item_slug to key for fast indexed lookups.
		$key = $key_prefix . ':' . sanitize_text_field( $data['item_slug'] );

		// Prepare database data.
		$logs_data = array(
			'user_id'       => absint( $user_id ),
			'child_of'      => 0,
			'key'           => $key,
			'option'        => sanitize_text_field( $data['action_type'] ),
			'value'         => wp_json_encode( $history_value ),
			'type'          => 'action_history',
			'type_state'    => sanitize_text_field( $data['action_status'] ),
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		// Insert using existing DBModel.
		$db_data_format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
		$db_model       = new DBModel();
		$history_id     = $db_model->insert_row( $logs_data, $db_data_format, TAILWATCH_DB_LOGS_TABLE_NAME );

		return false !== $history_id ? $history_id : false;
	}

	/**
	 * Get history with filters.
	 *
	 * @param array $args Filter arguments.
	 *
	 * @return array {
	 *     History results with pagination.
	 *
	 *     @type array $data        History entries.
	 *     @type int   $total       Total count.
	 *     @type int   $page        Current page.
	 *     @type int   $limit       Items per page.
	 *     @type int   $total_pages Total pages.
	 * }
	 */
	public static function get_history( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'item_type'     => null, // Type: plugin, theme, or core.
			'item_slug'     => null, // Specific plugin or theme slug.
			'action_type'   => null, // Type of action, e.g., plugin_update, plugin_rollback.
			'user_id'       => null, // Specific user ID to filter by.
			'action_status' => null, // Status: success, failed, or partial.
			'date_from'     => null, // Start date for filtering.
			'date_to'       => null, // End date for filtering.
			'page'          => 1,
			'limit'         => 50,
		);

		$args = array_merge( $defaults, $args );

		// Build the WHERE-extras fragment from code-literal column+placeholder
		// pairs only. User-supplied values are collected separately into
		// $extra_values and bound via prepare() — they are NEVER interpolated
		// into the SQL string.
		$where_extras = '';
		$extra_values = array();

		if ( ! empty( $args['item_type'] ) ) {
			$key_prefix_map = array(
				'plugin' => 'plugin_history',
				'theme'  => 'theme_history',
				'core'   => 'core_history',
			);
			if ( isset( $key_prefix_map[ $args['item_type'] ] ) ) {
				if ( ! empty( $args['item_slug'] ) ) {
					$where_extras  .= ' AND `key` = %s';
					$extra_values[] = $key_prefix_map[ $args['item_type'] ] . ':' . sanitize_text_field( $args['item_slug'] );
				} else {
					$where_extras  .= ' AND `key` LIKE %s';
					$extra_values[] = $key_prefix_map[ $args['item_type'] ] . ':%';
				}
			}
		} elseif ( ! empty( $args['item_slug'] ) ) {
			$where_extras  .= ' AND `key` LIKE %s';
			$extra_values[] = '%:' . $wpdb->esc_like( sanitize_text_field( $args['item_slug'] ) );
		}

		if ( ! empty( $args['action_type'] ) ) {
			$where_extras  .= ' AND `option` = %s';
			$extra_values[] = sanitize_text_field( $args['action_type'] );
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where_extras  .= ' AND `user_id` = %d';
			$extra_values[] = absint( $args['user_id'] );
		}
		if ( ! empty( $args['action_status'] ) ) {
			$where_extras  .= ' AND `type_state` = %s';
			$extra_values[] = sanitize_text_field( $args['action_status'] );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where_extras  .= ' AND `date_created` >= %s';
			$extra_values[] = sanitize_text_field( $args['date_from'] );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where_extras  .= ' AND `date_created` <= %s';
			$extra_values[] = sanitize_text_field( $args['date_to'] );
		}

		$limit      = max( 1, min( 100, absint( $args['limit'] ) ) );
		$page       = max( 1, absint( $args['page'] ) );
		$offset     = ( $page - 1 ) * $limit;
		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;

		// $data_sql / $count_sql are built ONLY from code-literal fragments:
		// the static base, the placeholder-only $where_extras, and the literal
		// ORDER BY / LIMIT / OFFSET tail. No user input is ever interpolated
		// into these strings.
		$data_sql  = 'SELECT * FROM %i WHERE `type` = %s' . $where_extras . ' ORDER BY `date_created` DESC LIMIT %d OFFSET %d';
		$count_sql = 'SELECT COUNT(*) FROM %i WHERE `type` = %s' . $where_extras;

		$data_args  = array_merge( array( $table_name, 'action_history' ), $extra_values, array( $limit, $offset ) );
		$count_args = array_merge( array( $table_name, 'action_history' ), $extra_values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $data_sql built from code-literal fragments only; user values bound via prepare() args.
		$results = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_args ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $count_sql built from code-literal fragments only; user values bound via prepare() args.
		$total = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_args ) );

		// Process results - decode JSON values.
		$processed_results = array();
		foreach ( $results as $row ) {
			$value_data = json_decode( $row['value'], true );

			$processed_results[] = array(
				'id'            => absint( $row['id'] ),
				'user_id'       => absint( $row['user_id'] ),
				'action_type'   => $row['option'],
				'item_type'     => $value_data['item_type'] ?? '',
				'item_slug'     => $value_data['item_slug'] ?? '',
				'item_name'     => $value_data['item_name'] ?? '',
				'action_status' => $row['type_state'],
				'triggered_by'  => $value_data['metadata']['triggered_by'] ?? self::DEFAULT_TRIGGERED_BY,
				'before_state'  => $value_data['before_state'] ?? null,
				'after_state'   => $value_data['after_state'] ?? null,
				'metadata'      => $value_data['metadata'] ?? array(),
				'user'          => $value_data['user'] ?? array(),
				'date_created'  => $row['date_created'],
			);
		}

		return array(
			'data'        => $processed_results,
			'total'       => absint( $total ),
			'page'        => $page,
			'limit'       => $limit,
			'total_pages' => $total > 0 ? ceil( $total / $limit ) : 0,
		);
	}

	/**
	 * Get history for specific item.
	 *
	 * @param string $item_type Item type ('plugin', 'theme', 'core').
	 * @param string $item_slug Item slug.
	 * @param int    $limit     Items per page. Default 50.
	 * @param int    $page      Page number. Default 1.
	 *
	 * @return array History results.
	 */
	public static function get_item_history( string $item_type, string $item_slug, int $limit = 50, int $page = 1 ) {
		return self::get_history(
			array(
				'item_type' => $item_type,
				'item_slug' => $item_slug,
				'limit'     => $limit,
				'page'      => $page,
			)
		);
	}

	/**
	 * Get history for specific user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Items per page. Default 50.
	 * @param int $page    Page number. Default 1.
	 *
	 * @return array History results.
	 */
	public static function get_user_history( int $user_id, int $limit = 50, int $page = 1 ) {
		return self::get_history(
			array(
				'user_id' => $user_id,
				'limit'   => $limit,
				'page'    => $page,
			)
		);
	}
}
