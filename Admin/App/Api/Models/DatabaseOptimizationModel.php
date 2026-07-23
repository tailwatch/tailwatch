<?php
/**
 * Database Optimization Model
 *
 * Model for database optimization operations (revisions, comments, transients, logs).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Models
 */

namespace Tailwatch\Admin\App\Api\Models;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DatabaseOptimizationModel
 *
 */
class DatabaseOptimizationModel {

	/**
	 * Calculates the revision date based on retention days.
	 *
	 * @param int|string $number_of_days Number of days to retain data.
	 * @return string Date in 'Y-m-d H:i:s' format (UTC).
	 */
	private function get_revision_date( $number_of_days ): string {
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_gmdate -- Building cutoff date from relative days in UTC.
		return gmdate( 'Y-m-d H:i:s', strtotime( '-' . $number_of_days ) );
	}

	/**
	 * Get spam comments for cleanup.
	 *
	 * @param int|null   $number_interval Limit.
	 * @param int|string $number_of_days Retention days.
	 * @return array
	 */
	public function get_spam_comments( $number_interval, $number_of_days ): array {
		global $wpdb;
		try {
			$maintain_revision = $this->get_revision_date( $number_of_days );
			return get_comments(
				array(
					'status'     => 'spam',
					'number'     => $number_interval,
					'date_query' => array(
						array(
							'before'    => $maintain_revision,
							'inclusive' => false,
						),
					),
				)
			);
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Delete a comment by ID.
	 *
	 * @param int $comment_id Comment ID.
	 * @return bool
	 */
	public function delete_comment( $comment_id ): bool {
		global $wpdb;
		try {
			return false !== wp_delete_comment( $comment_id, true );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get trashed comments for cleanup.
	 *
	 * @param int|null   $number_interval Limit.
	 * @param int|string $number_of_days Retention days.
	 * @return array
	 */
	public function get_trash_comments( $number_interval, $number_of_days ): array {
		global $wpdb;
		try {
			$maintain_revision = $this->get_revision_date( $number_of_days );
			return get_comments(
				array(
					'status'     => 'trash',
					'number'     => $number_interval,
					'date_query' => array(
						array(
							'before'    => $maintain_revision,
							'inclusive' => false,
						),
					),
				)
			);
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Permanently delete a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function delete_post( $post_id ): bool {
		global $wpdb;
		try {
			return false !== wp_delete_post( $post_id, true );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get auto-draft posts for cleanup.
	 *
	 * @param int|null   $number_interval Limit.
	 * @param int|string $number_of_days Retention days.
	 * @return array
	 */
	public function get_auto_drafts( $number_interval, $number_of_days ): array {
		global $wpdb;
		try {
			$maintain_revision = $this->get_revision_date( $number_of_days );
			if ( null !== $number_interval ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core posts table cleanup.
				return $wpdb->get_col( $wpdb->prepare(
					'SELECT ID FROM %i WHERE post_status = %s AND post_modified < %s LIMIT %d',
					$wpdb->posts,
					'auto-draft',
					$maintain_revision,
					$number_interval
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core posts table cleanup.
			return $wpdb->get_col( $wpdb->prepare(
				'SELECT ID FROM %i WHERE post_status = %s AND post_modified < %s',
				$wpdb->posts,
				'auto-draft',
				$maintain_revision
			) );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Get trashed posts for cleanup.
	 *
	 * @param int|null   $number_interval Limit.
	 * @param int|string $number_of_days Retention days.
	 * @return array
	 */
	public function get_trashed_posts( $number_interval, $number_of_days ): array {
		global $wpdb;
		try {
			$maintain_revision = $this->get_revision_date( $number_of_days );
			if ( null !== $number_interval ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core posts table cleanup.
				return $wpdb->get_col( $wpdb->prepare(
					'SELECT ID FROM %i WHERE post_status = %s AND post_modified < %s LIMIT %d',
					$wpdb->posts,
					'trash',
					$maintain_revision,
					$number_interval
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core posts table cleanup.
			return $wpdb->get_col( $wpdb->prepare(
				'SELECT ID FROM %i WHERE post_status = %s AND post_modified < %s',
				$wpdb->posts,
				'trash',
				$maintain_revision
			) );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Get orphaned post meta for cleanup.
	 *
	 * @param int|null   $number_interval Limit.
	 * @param int|string $number_of_days Retention days (unused for this query).
	 * @return array
	 */
	public function get_orphaned_post_meta( $number_interval, $number_of_days ): array {
		global $wpdb;
		try {
			if ( null !== $number_interval ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables; orphaned-meta cleanup.
				return $wpdb->get_results( $wpdb->prepare(
					'SELECT pm.meta_id FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL LIMIT %d',
					$wpdb->postmeta,
					$wpdb->posts,
					$number_interval
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables; orphaned-meta cleanup.
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT pm.meta_id FROM %i pm LEFT JOIN %i p ON p.ID = pm.post_id WHERE p.ID IS NULL',
				$wpdb->postmeta,
				$wpdb->posts
			) );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Delete post meta by meta ID.
	 *
	 * @param int $meta_id Meta ID.
	 * @return bool
	 */
	public function delete_post_meta( $meta_id ): bool {
		global $wpdb;
		try {
			return delete_metadata_by_mid( 'post', $meta_id );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get expired transient timeout option names.
	 *
	 * @param int|null $number_interval Limit.
	 * @return array
	 */
	public function get_expired_transients( $number_interval ): array {
		global $wpdb;
		try {
			// LIKE patterns passed as %s values to preserve bare `%` wildcards
			// through prepare().
			if ( null !== $number_interval ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; expired-transient sweep.
				return $wpdb->get_col( $wpdb->prepare(
					'SELECT option_name FROM %i WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < UNIX_TIMESTAMP() LIMIT %d',
					$wpdb->options,
					'_transient_timeout_%',
					'_site_transient_timeout_%',
					$number_interval
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; expired-transient sweep.
			return $wpdb->get_col( $wpdb->prepare(
				'SELECT option_name FROM %i WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < UNIX_TIMESTAMP()',
				$wpdb->options,
				'_transient_timeout_%',
				'_site_transient_timeout_%'
			) );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Delete a single expired transient by timeout option name.
	 *
	 * @param string $transient_timeout Timeout option name.
	 * @return bool
	 */
	public function delete_expired_transient( string $transient_timeout ): bool {
		global $wpdb;
		try {
			if ( 0 === strpos( $transient_timeout, '_site_transient_timeout_' ) ) {
				$transient_name   = str_replace( '_site_transient_timeout_', '', $transient_timeout );
				$timeout_option   = '_site_transient_timeout_' . $transient_name;
				$transient_option = '_site_transient_' . $transient_name;
			} else {
				$transient_name   = str_replace( '_transient_timeout_', '', $transient_timeout );
				$timeout_option   = '_transient_timeout_' . $transient_name;
				$transient_option = '_transient_' . $transient_name;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; transient cleanup.
			return false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name IN (%s, %s)', $wpdb->options, $timeout_option, $transient_option ) );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get trackback/pingback comments for cleanup.
	 *
	 * @param int|null   $number_interval Limit.
	 * @param int|string $number_of_days Retention days.
	 * @return array
	 */
	public function get_trackback_pingbacks( $number_interval, $number_of_days ): array {
		global $wpdb;
		try {
			$maintain_revision = $this->get_revision_date( $number_of_days );
			return get_comments(
				array(
					'type'       => array( 'pingback', 'trackback' ),
					'number'     => $number_interval,
					'date_query' => array(
						array(
							'before'    => $maintain_revision,
							'inclusive' => false,
						),
					),
				)
			);
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Get log IDs by option for cleanup.
	 *
	 * @param string     $option Option key.
	 * @param int|string $number_of_days Retention days.
	 * @param int|null   $number_interval Limit.
	 * @return array
	 */
	public function get_logs_by_option( string $option, $number_of_days, $number_interval ): array {
		global $wpdb;
		try {
			$maintain_revision = $this->get_revision_date( $number_of_days );
			$table_name        = $wpdb->prefix . WPTW_DB_LOGS_TABLE_NAME;
			if ( null !== $number_interval ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table cleanup.
				return $wpdb->get_col( $wpdb->prepare(
					'SELECT id FROM %i WHERE `key` = %s AND `option` = %s AND date_created < %s LIMIT %d',
					$table_name,
					'default_feature_logs',
					$option,
					$maintain_revision,
					$number_interval
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom logs table cleanup.
			return $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM %i WHERE `key` = %s AND `option` = %s AND date_created < %s',
				$table_name,
				'default_feature_logs',
				$option,
				$maintain_revision
			) );
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Delete a row by ID from the logs table.
	 *
	 * @param int    $id Row ID.
	 * @param string $table Table name (without prefix).
	 * @return bool
	 */
	public function delete_data_by_id( int $id, string $table = WPTW_DB_LOGS_TABLE_NAME ): bool {
		global $wpdb;
		try {
			$db_table_name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Using $wpdb->delete() with prepared args.
			return false !== $wpdb->delete( $db_table_name, array( 'id' => $id ), array( '%d' ) );
		} catch ( Exception $e ) {
			return false;
		}
	}
}
