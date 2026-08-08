<?php
/**
 * Notification logging for IP management events.
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Services\IpManagement;

use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationService
 */
class NotificationService {

	public function log_notification( $type, $data ) {
		global $wpdb;
		$table_name   = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$notification = array(
			'type'      => sanitize_text_field( $type ),
			'data'      => wp_json_encode( $data ),
			'timestamp' => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
			$table_name,
			array(
				'key'           => 'notification',
				'option'        => $type,
				'value'         => wp_json_encode( $notification ),
				'type'          => 'notification',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified' => current_time( 'mysql' ),
				'is_active'     => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( $result === false ) {
			Log::error(
				'Failed to log notification',
				array(
					'feature' => 'ip_management',
					'action'  => 'log_notification',
					'type'    => $type,
				)
			);
			return false;
		}

		// Invalidate the cached feed so the next read rebuilds from the DB.
		// (Re-reading via get_notification_logs() here would return the stale
		// cache and miss the row just inserted.)
		delete_transient( 'tailwatch_login_defender_notification_logs' );

		Log::info(
			'Logged notification',
			array(
				'feature' => 'ip_management',
				'action'  => 'log_notification',
				'type'    => $type,
			)
		);
		return true;
	}

	public function get_notification_logs( $limit = 100, $offset = 0 ) {
		global $wpdb;
		$cache_key = 'tailwatch_login_defender_notification_logs';
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return array_slice( $cached, $offset, $limit );
		}

		$table_name = $wpdb->prefix . TAILWATCH_DB_LOGS_TABLE_NAME;
		$logs       = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, no WP cache API.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND is_active = %d ORDER BY date_created DESC LIMIT %d OFFSET %d',
				$table_name,
				'notification',
				1,
				$limit,
				$offset
			)
		);

		$result = array();
		foreach ( $logs as $log ) {
			$data = json_decode( $log->value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON for notification',
					array(
						'feature' => 'ip_management',
						'action'  => 'get_notification_logs',
						'id'      => $log->id,
					)
				);
				continue;
			}
			$result[] = array(
				'type'      => $data['type'],
				'data'      => $data['data'],
				'timestamp' => $data['timestamp'],
			);
		}

		set_transient( $cache_key, $result, DAY_IN_SECONDS );
		return $result;
	}
}
