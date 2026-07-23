<?php
namespace Tailwatch\Admin\App\Api\Controllers\Users\Management;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Traits\ContextAuthorizationTrait;

/**
 * User Deletion Controller.
 *
 * ## Authorization model
 *
 * These methods are reachable through two distinct entry points:
 *
 *   1. The wp-admin AJAX router (AjaxRequestController) — upstream gate
 *      verifies `wp_ajax_nonce` AND `current_user_can('manage_options')`.
 *   2. The JWT-gated mobile route (MobileAppController) — upstream gate
 *      verifies the JWT signature, expiry, license-connected state, and
 *      JTI revocation.
 *
 * Each public method calls `is_authorized_request()` (provided by
 * ContextAuthorizationTrait) as defense in depth. AJAX path re-verifies
 * `manage_options`; JWT path verifies the license is still connected.
 */
class UserDeletionController {

	use ContextAuthorizationTrait;
	/**
	 * Delete a user and optionally reassign their content
	 *
	 * @param string $post_data JSON string containing user_id and optional reassign_id
	 * @return array Response with success status
	 */
	public function wptw_delete_user( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			// Include WordPress user functions
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}

			// Parse and sanitize input
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'User deletion failed: Invalid JSON data provided.',
					array(
						'feature' => 'users',
						'action'  => 'delete_user_failed',
						'title'  => 'User Delete Failed',
						'detail'  => 'Invalid JSON data provided',
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
				);
			}

			// Validate user_id
			$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;

			if ( empty( $user_id ) || $user_id <= 0 ) {
				Log::error(
					'User deletion failed: User ID is missing or invalid.',
					array(
						'feature' => 'users',
						'action'  => 'delete_user_failed',
						'title'  => 'User Delete Failed',
						'detail'  => 'User ID is missing or invalid',
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Valid user ID is required.', 'tailwatch' ),
				);
			}

			// Get user object to verify existence
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				Log::error(
					'User deletion failed: User not found.',
					array(
						'feature' => 'users',
						'action'  => 'delete_user_failed',
						'title'  => 'User Delete Failed',
						'detail'  => "User with ID $user_id not found",
					)
				);

				return array(
					'success' => false,
					'code'    => 404,
					'message' => __( 'User not found.', 'tailwatch' ),
				);
			}

			// Prevent deletion of current user
			$current_user_id = get_current_user_id();
			if ( $user_id === $current_user_id ) {
				Log::error(
					'User deletion failed: Self deletion attempt.',
					array(
						'feature' => 'users',
						'action'  => 'delete_user_failed',
						'title'  => 'User Delete Failed',
						'detail'  => "User attempted to delete their own account: $user_id",
					)
				);

				return array(
					'success' => false,
					'code'    => 403,
					'message' => __( 'You cannot delete your own account.', 'tailwatch' ),
				);
			}

			// Check if user is a super admin (for multisite)
			if ( is_multisite() && is_super_admin( $user_id ) ) {
				Log::error(
					'User deletion failed: Super admin protection.',
					array(
						'feature' => 'users',
						'action'  => 'delete_user_failed',
						'title'  => 'User Delete Failed',
						'detail'  => "Attempted to delete super admin: $user_id",
					)
				);

				return array(
					'success' => false,
					'code'    => 403,
					'message' => __( 'Super administrators cannot be deleted.', 'tailwatch' ),
				);
			}

			// Prevent deletion of the last real administrator. Passwordless temporary
			// admins (created via the Pro plugin's temporary login feature) carry the
			// administrator role too, so a naive count of administrators would mis-treat
			// them as guarantees of admin presence — they expire, get revoked, or hit
			// their usage limit. Real-admin presence is "administrator role AND
			// _wptw_user_status !== 'without_password'". Two conditions in this check:
			//   1. The TARGET must be a real admin — deleting a temp admin doesn't
			//      reduce real-admin count, so no last-admin gate applies to them.
			//   2. After exclusion, real_admin_count must be > 1.
			if ( in_array( 'administrator', $user->roles, true ) ) {
				$target_status        = get_user_meta( $user_id, '_wptw_user_status', true );
				$target_is_real_admin = ( 'without_password' !== $target_status );

				if ( $target_is_real_admin ) {
					$real_admin_count = count(
						get_users(
							array(
								'role'       => 'administrator',
								'fields'     => 'ID',
								'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only listing endpoint; bounded result set.
									'relation' => 'OR',
									array(
										'key'     => '_wptw_user_status',
										'compare' => 'NOT EXISTS',
									),
									array(
										'key'     => '_wptw_user_status',
										'value'   => 'without_password',
										'compare' => '!=',
									),
								),
							)
						)
					);

					if ( $real_admin_count <= 1 ) {
						Log::error(
							'User deletion failed: Last administrator protection.',
							array(
								'feature' => 'users',
								'action'  => 'delete_user_failed',
								'title'   => 'User Delete Failed',
								'detail'  => "Attempted to delete the last real administrator: $user_id",
							)
						);

						return array(
							'success' => false,
							'code'    => 403,
							'message' => __( 'Cannot delete the last administrator.', 'tailwatch' ),
						);
					}
				}
			}

			// Handle content reassignment
			$reassign_id = isset( $data['reassign_id'] ) ? absint( $data['reassign_id'] ) : null;

			if ( null !== $reassign_id && $reassign_id > 0 ) {
				// Validate reassign user exists.
				$reassign_user = get_userdata( $reassign_id );

				if ( ! $reassign_user ) {
					Log::error(
						'User deletion failed: Invalid reassign user.',
						array(
							'feature' => 'users',
							'action'  => 'delete_user_failed',
							'title'  => 'User Delete Failed',
							'detail'  => "Reassign user ID $reassign_id not found",
						)
					);

					return array(
						'success' => false,
						'code'    => 400,
						'message' => __( 'Invalid reassign user ID.', 'tailwatch' ),
					);
				}

				// Cannot reassign to the user being deleted
				if ( $reassign_id === $user_id ) {
					return array(
						'success' => false,
						'code'    => 400,
						'message' => __( 'Cannot reassign content to the user being deleted.', 'tailwatch' ),
					);
				}
			}

			// Store user information for logging
			$username   = $user->user_login;
			$user_email = $user->user_email;
			$user_roles = implode( ', ', $user->roles );

			// Allow plugins to perform actions before deletion
			do_action( 'wptw_before_delete_user', $user_id, $reassign_id, $user );

			// Resolve reassign: must be null (delete path) or a valid positive ID (reassign path).
			// Never pass 0 — WP core treats any non-null as reassign and would corrupt posts to author=0.
			$reassign = ( $reassign_id > 0 ) ? $reassign_id : null;

			// Delete the user. Mirrors WP core wp-admin/users.php:
			//   - On multisite, remove from the current site only via remove_user_from_blog().
			//     Network-wide deletion (wpmu_delete_user) requires super admin and is not exposed here.
			//   - On single-site, wp_delete_user() removes from wp_users.
			// remove_user_from_blog() expects '' (not null) for "delete posts" and an int for reassign.
			if ( is_multisite() ) {
				$result  = remove_user_from_blog( $user_id, get_current_blog_id(), null === $reassign ? '' : $reassign );
				$deleted = ! is_wp_error( $result );
			} else {
				$deleted = wp_delete_user( $user_id, $reassign );
			}

			if ( ! $deleted ) {
				Log::error(
					'User deletion failed: Failed to delete user.',
					array(
						'feature' => 'users',
						'action'  => 'delete_user_failed',
						'title'  => 'User Delete Failed',
						'detail'  => "Failed to delete user ID $user_id",
					)
				);

				return array(
					'success' => false,
					'code'    => 500,
					'message' => __( 'Failed to delete user.', 'tailwatch' ),
				);
			}

			Log::info(
				'User deleted successfully.',
				array(
					'feature'       => 'users',
					'action'        => 'user_deleted',
					'title'  => 'User Deleted',
					'deleted_id'    => $user_id,
					'username'      => $username,
					'reassigned_to' => $reassign_id,
				)
			);

			// Allow plugins to perform actions after deletion
			do_action( 'wptw_after_delete_user', $user_id, $reassign_id, $username, $user_email );

			return array(
				'success' => true,
				'code'    => 200,
				'message' => __( 'User deleted successfully.', 'tailwatch' ),
				'data'    => array(
					'deleted_user_id'    => $user_id,
					'username'           => $username,
					'content_reassigned' => null !== $reassign_id && $reassign_id > 0,
					'reassigned_to'      => $reassign_id,
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'delete_user_failed',
					'title'  => 'User Delete Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to delete user due to server error.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Bulk delete users
	 *
	 * @param string $post_data JSON string containing array of user_ids and optional reassign_id
	 * @return array Response with success status and results for each user
	 */
	public function wptw_bulk_delete_users( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
				);
			}

			$user_ids    = isset( $data['user_ids'] ) && is_array( $data['user_ids'] ) ? $data['user_ids'] : array();
			$reassign_id = isset( $data['reassign_id'] ) ? absint( $data['reassign_id'] ) : null;

			if ( empty( $user_ids ) ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'No user IDs provided.', 'tailwatch' ),
				);
			}

			// Validate reassign user exists before processing deletions
			if ( null !== $reassign_id && $reassign_id > 0 ) {
				if ( ! get_userdata( $reassign_id ) ) {
					return array(
						'success' => false,
						'code'    => 400,
						'message' => __( 'Invalid reassign user ID.', 'tailwatch' ),
					);
				}
			}

			$current_user_id = get_current_user_id();
			$results         = array(
				'success' => array(),
				'failed'  => array(),
				'skipped' => array(),
			);

			foreach ( $user_ids as $user_id ) {
				$user_id = absint( $user_id );

				// Skip invalid IDs
				if ( $user_id <= 0 ) {
					$results['skipped'][] = array(
						'user_id' => $user_id,
						'reason'  => __( 'Invalid user ID', 'tailwatch' ),
					);
					continue;
				}

				// Skip current user
				if ( $user_id === $current_user_id ) {
					$results['skipped'][] = array(
						'user_id' => $user_id,
						'reason'  => __( 'Cannot delete your own account', 'tailwatch' ),
					);
					continue;
				}

				$user = get_userdata( $user_id );

				if ( ! $user ) {
					$results['failed'][] = array(
						'user_id' => $user_id,
						'reason'  => __( 'User not found', 'tailwatch' ),
					);
					continue;
				}

				// Skip super admins
				if ( is_multisite() && is_super_admin( $user_id ) ) {
					$results['skipped'][] = array(
						'user_id'  => $user_id,
						'username' => $user->user_login,
						'reason'   => __( 'Super administrator cannot be deleted', 'tailwatch' ),
					);
					continue;
				}

				// Skip if this would delete the last REAL administrator. Same logic as
				// the single-delete path: passwordless temporary admins are excluded from
				// the real-admin count, and the gate only fires when the target itself
				// is a real admin (deleting a temp admin doesn't reduce real presence).
				if ( in_array( 'administrator', $user->roles, true ) ) {
					$target_status        = get_user_meta( $user_id, '_wptw_user_status', true );
					$target_is_real_admin = ( 'without_password' !== $target_status );

					if ( $target_is_real_admin ) {
						$real_admin_count = count(
							get_users(
								array(
									'role'       => 'administrator',
									'fields'     => 'ID',
									'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only listing endpoint; bounded result set.
										'relation' => 'OR',
										array(
											'key'     => '_wptw_user_status',
											'compare' => 'NOT EXISTS',
										),
										array(
											'key'     => '_wptw_user_status',
											'value'   => 'without_password',
											'compare' => '!=',
										),
									),
								)
							)
						);

						if ( $real_admin_count <= 1 ) {
							$results['skipped'][] = array(
								'user_id'  => $user_id,
								'username' => $user->user_login,
								'reason'   => __( 'Cannot delete the last administrator', 'tailwatch' ),
							);
							continue;
						}
					}
				}

				// Resolve reassign: must be null (delete path) or a valid positive ID (reassign path).
				$reassign = ( $reassign_id > 0 ) ? $reassign_id : null;

				// Attempt deletion — multisite uses remove_user_from_blog (per-site removal),
				// single-site uses wp_delete_user. Matches WP core wp-admin/users.php.
				if ( is_multisite() ) {
					$result  = remove_user_from_blog( $user_id, get_current_blog_id(), null === $reassign ? '' : $reassign );
					$deleted = ! is_wp_error( $result );
				} else {
					$deleted = wp_delete_user( $user_id, $reassign );
				}

				if ( $deleted ) {
					$results['success'][] = array(
						'user_id'  => $user_id,
						'username' => $user->user_login,
					);

					Log::info(
						'User deleted in bulk operation.',
						array(
							'feature'  => 'users',
							'action'   => 'user_deleted',
							'title'  => 'User Deleted',
							'user_id'  => $user_id,
							'username' => $user->user_login,
						)
					);
				} else {
					$results['failed'][] = array(
						'user_id'  => $user_id,
						'username' => $user->user_login,
						'reason'   => __( 'Deletion failed', 'tailwatch' ),
					);
				}
			}

			$total_requested = count( $user_ids );
			$total_deleted   = count( $results['success'] );
			$total_failed    = count( $results['failed'] );
			$total_skipped   = count( $results['skipped'] );

			return array(
				'success' => $total_deleted > 0,
				'code'    => 200,
				'message' => sprintf(
					/* translators: 1: deleted count, 2: failed count, 3: skipped count, 4: requested count */
					__( 'Bulk deletion completed: %1$d deleted, %2$d failed, %3$d skipped out of %4$d requested.', 'tailwatch' ),
					$total_deleted,
					$total_failed,
					$total_skipped,
					$total_requested
				),
				'data'    => $results,
				'summary' => array(
					'total_requested' => $total_requested,
					'deleted'         => $total_deleted,
					'failed'          => $total_failed,
					'skipped'         => $total_skipped,
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'bulk_delete_users_failed',
					'title'  => 'User Delete Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to perform bulk deletion due to server error.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get a summary of all content owned by a user before deletion.
	 * Returns post type breakdown so the admin can decide to reassign or delete.
	 *
	 * @param string $post_data JSON string containing user_id
	 * @return array Response with content summary per post type
	 */
	public function wptw_get_user_content_summary( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
				);
			}

			$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;

			if ( empty( $user_id ) || $user_id <= 0 ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Valid user ID is required.', 'tailwatch' ),
				);
			}

			$user = get_userdata( $user_id );

			if ( ! $user ) {
				return array(
					'success' => false,
					'code'    => 404,
					'message' => __( 'User not found.', 'tailwatch' ),
				);
			}

			global $wpdb;

			// Use the same post type filter WordPress core uses in wp_delete_user():
			// delete_with_user === true, OR null + supports 'author'.
			// Explicitly exclude 'revision' — it passes the filter but is internal WP data, not real content.
			$post_types_to_check = array();
			foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
				if ( 'revision' === $post_type->name ) {
					continue;
				}
				if ( true === $post_type->delete_with_user ) {
					$post_types_to_check[] = $post_type;
				} elseif ( null === $post_type->delete_with_user && post_type_supports( $post_type->name, 'author' ) ) {
					$post_types_to_check[] = $post_type;
				}
			}

			$content_summary = array();
			$total_content   = 0;

			foreach ( $post_types_to_check as $post_type ) {
				// attachment uses 'inherit' status; include it alongside standard statuses.
				$statuses = ( 'attachment' === $post_type->name )
					? array( 'inherit', 'private', 'trash' )
					: array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft' );

				// Build placeholders dynamically for the IN clause.
				$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

				// One COUNT query per post type grouped by status — same approach as WP core, no full object loads.
				// IN-clause placeholders are generated dynamically to match the
				// $statuses count and bound via array_merge() to prepare(); the
				// static analyzer can't see that the counts align, so we disable
				// the related sniffs across this single call.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT post_status, COUNT(*) AS total FROM %i WHERE post_author = %d AND post_type = %s AND post_status IN ( ' . $placeholders . ' ) GROUP BY post_status',
						array_merge( array( $wpdb->posts, $user_id, $post_type->name ), $statuses )
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

				if ( empty( $rows ) ) {
					continue;
				}

				$counts     = array();
				$type_total = 0;
				foreach ( $rows as $row ) {
					$counts[ $row['post_status'] ] = (int) $row['total'];
					$type_total                   += (int) $row['total'];
				}

				$content_summary[] = array(
					'post_type'      => $post_type->name,
					'label'          => $post_type->labels->name,
					'label_singular' => $post_type->labels->singular_name,
					'total'          => $type_total,
					'by_status'      => $counts,
				);
				$total_content += $type_total;
			}

			return array(
				'success'         => true,
				'code'            => 200,
				'message'         => __( 'User content summary retrieved successfully.', 'tailwatch' ),
				'data'            => array(
					'user_id'         => $user_id,
					'username'        => $user->user_login,
					'has_content'     => $total_content > 0,
					'total_content'   => $total_content,
					'content_summary' => $content_summary,
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'get_user_content_summary_failed',
					'title'  => 'User Retrieval Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to retrieve user content summary.', 'tailwatch' ),
			);
		}
	}
}
