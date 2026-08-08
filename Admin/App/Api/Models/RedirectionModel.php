<?php

namespace Tailwatch\Admin\App\Api\Models;

use Exception;

defined( 'ABSPATH' ) || exit;

class RedirectionModel {

	public function get_all_redirect_rules( $key, $type_state, $limit = 10, $page = 1, $table = TAILWATCH_DB_TABLE_NAME ) {
		global $wpdb;

		$table_name = $wpdb->prefix . $table;
		$offset     = ( $page - 1 ) * $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; admin listing.
		$result_all = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE `key` = %s AND `type_state` = %s LIMIT %d OFFSET %d',
				$table_name,
				$key,
				$type_state,
				$limit,
				$offset
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; admin listing.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE `key` = %s AND `type_state` = %s',
				$table_name,
				$key,
				$type_state
			)
		);

		return array(
			'data'        => $result_all,
			'total'       => $total,
			'page'        => $page,
			'limit'       => $limit,
			'total_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 0,
		);
	}
}