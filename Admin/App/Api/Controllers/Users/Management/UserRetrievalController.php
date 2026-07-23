<?php
namespace Tailwatch\Admin\App\Api\Controllers\Users\Management;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Traits\ContextAuthorizationTrait;

class UserRetrievalController {

	use ContextAuthorizationTrait;

	/**
	 * Get users with their status, pagination support
	 *
	 * @param string $post_data JSON string containing limit and page parameters
	 * @return array Response with users data and pagination info
	 */
	public function wptw_get_user_status( $post_data ) {
		try {
			// Defense-in-depth: both routers gate this upstream (nonce+manage_options
			// / JWT), but this endpoint returns full user PII, so re-check at the
			// method level to match the write-side user controllers.
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			$json_data = is_string( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = is_string( $json_data ) ? json_decode( $json_data, true ) : array();

			$limit  = isset( $data['limit'] ) && is_numeric( $data['limit'] ) && $data['limit'] > 0 ? absint( $data['limit'] ) : 10;
			$page   = isset( $data['page'] ) && is_numeric( $data['page'] ) && $data['page'] > 0 ? absint( $data['page'] ) : 1;
			$offset = ( $page - 1 ) * $limit;

			$limit = min( $limit, 100 );

			// Single WP_User_Query with count_total => true — fetches the page AND the total
			// in one round-trip via SQL_CALC_FOUND_ROWS. Mirrors WP core wp-admin/users.php.
			// Replaces the previous two-query pattern that hydrated all matching IDs into PHP
			// just to count them.
			$args = array(
				'role__in'    => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
				'orderby'     => 'user_nicename',
				'order'       => 'ASC',
				'number'      => $limit,
				'offset'      => $offset,
				'fields'      => 'all_with_meta',
				'count_total' => true,
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only listing endpoint; bounded result set.
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
			);

			$user_query        = new \WP_User_Query( $args );
			$users             = $user_query->get_results();
			$total             = (int) $user_query->get_total();
			$users_with_status = array();

			if ( empty( $users ) ) {
				return array(
					'code'       => 200,
					'data'       => $users_with_status,
					'message'    => __( 'No users found.', 'tailwatch' ),
					'pagination' => array(
						'total'       => $total,
						'page'        => $page,
						'limit'       => $limit,
						'total_pages' => ceil( $total / $limit ),
					),
				);
			}

			foreach ( $users as $user ) {

				$users_with_status[] = array(
					'id'                    => $user->ID,
					'username'              => $user->user_login,
					'name'                  => $user->display_name,
					'email'                 => $user->user_email,
					'role'                  => implode( ', ', $user->roles ),
					'roles'                 => $user->roles,
					'first_name'            => $user->first_name,
					'last_name'             => $user->last_name,
					'display_name'          => $user->display_name,
					'nickname'              => $user->nickname,
					'description'           => $user->description,
					'website'               => $user->user_url,
					'registered_date'       => $user->user_registered,
					'wptw_user_status'      => get_user_meta( $user->ID, '_wptw_user_status', true ) ?: '',
					'user_status'           => $this->wptw_user_block_status( $user->ID ),
					'two_step_verification' => $this->wptw_two_fa_status( $user->ID ),
					'user_expiry_status'    => $this->wptw_user_expiry_status( $user->ID ),
					'login_as_another'      => $this->wptw_login_as_another_user( $user->ID ),
				);
			}

			return array(
				'code'       => 200,
				'data'       => $users_with_status,
				'message'    => __( 'Users fetched successfully.', 'tailwatch' ),
				'pagination' => array(
					'total'       => $total,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => ceil( $total / $limit ),
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'user_status_retrieval_failed',
					'title'  => 'User Status Retrieval Failed',
					'exception' => $e,
				)
			);

			return array(
				'code'       => 500,
				'data'       => array(),
				'message'    => __( 'Failed to retrieve user status.', 'tailwatch' ),
				'pagination' => array(
					'total'       => 0,
					'page'        => 1,
					'limit'       => 10,
					'total_pages' => 0,
				),
			);
		}
	}

	/**
	 * Get single user details by ID
	 *
	 * @param string $post_data JSON string containing user_id
	 * @return array Response with user details
	 */
	public function wptw_get_user_by_id( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

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

			$user_data = array(
				'id'                    => $user->ID,
				'username'              => $user->user_login,
				'email'                 => $user->user_email,
				'name'                  => $user->display_name,
				'role'                  => implode( ', ', $user->roles ),
				'first_name'            => $user->first_name,
				'last_name'             => $user->last_name,
				'display_name'          => $user->display_name,
				'nickname'              => $user->nickname,
				'description'           => $user->description,
				'website'               => $user->user_url,
				'roles'                 => $user->roles,
				'registered_date'       => $user->user_registered,
				'wptw_user_status'      => get_user_meta( $user->ID, '_wptw_user_status', true ) ?: '',
				'user_status'           => $this->wptw_user_block_status( $user->ID ),
				'two_step_verification' => $this->wptw_two_fa_status( $user->ID ),
				'user_expiry_status'    => $this->wptw_user_expiry_status( $user->ID ),
				'login_as_another'      => $this->wptw_login_as_another_user( $user->ID ),
			);

			return array(
				'success' => true,
				'code'    => 200,
				'data'    => $user_data,
				'message' => __( 'User details retrieved successfully.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'get_user_by_id_failed',
					'title'  => 'User Retrieval Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to retrieve user details.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get all available roles with descriptions
	 *
	 * @return array Response with all WordPress roles
	 */
	public function wptw_get_all_roles() {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			$wp_roles  = wp_roles();
			$all_roles = $wp_roles->roles;

			// Descriptions for the five WordPress built-in roles.
			// WordPress stores no description natively — this is the correct approach.
			// Third-party plugins that register custom roles can provide descriptions
			// by hooking 'wptw_role_descriptions' and adding their role slug => text.
			$built_in_descriptions = array(
				'administrator' => __( 'Has access to all the administration features within a single site.', 'tailwatch' ),
				'editor'        => __( 'Can publish and manage posts including the posts of other users.', 'tailwatch' ),
				'author'        => __( 'Can publish and manage their own posts.', 'tailwatch' ),
				'contributor'   => __( 'Can write and manage their own posts but cannot publish them.', 'tailwatch' ),
				'subscriber'    => __( 'Can only manage their profile.', 'tailwatch' ),
			);
			$role_descriptions = apply_filters( 'wptw_role_descriptions', $built_in_descriptions );

			// Count users per role in one query instead of per-role queries.
			$user_counts = count_users();

			$roles_with_details = array();

			foreach ( $all_roles as $role_key => $role_data ) {
				$roles_with_details[] = array(
					'role_key'           => $role_key,
					'role_name'          => translate_user_role( $role_data['name'] ),
					'description'        => $role_descriptions[ $role_key ] ?? '',
					'capabilities_count' => count( $role_data['capabilities'] ),
					'user_count'         => $user_counts['avail_roles'][ $role_key ] ?? 0,
				);
			}

			return array(
				'success' => true,
				'code'    => 200,
				'data'    => $roles_with_details,
				'message' => __( 'Roles retrieved successfully.', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'get_roles_failed',
					'title'  => 'User Roles Retrieval Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'data'    => array(),
				'message' => __( 'Failed to retrieve roles.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Account-expiry status payload for a single user row.
	 *
	 * The free plugin ships no account-expiry feature, so it returns
	 * the default `{ is_upgrade_feature: true, data: null }`. The
	 * `is_upgrade_feature` marker lets the frontend render the same
	 * "Available with an extension" card it uses elsewhere instead
	 * of an empty cell. Add-ons that implement user-expiry hook
	 * `wptw_get_user_expiry_status_response` to substitute a real
	 * payload (typically clearing `is_upgrade_feature`).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Payload shape `{ is_upgrade_feature: bool, data: mixed }` after filter.
	 */
	public function wptw_user_expiry_status( $user_id ) {
		return apply_filters(
			'wptw_get_user_expiry_status_response',
			array(
				'is_upgrade_feature' => true,
				'data'               => null,
			),
			$user_id
		);
	}

	/**
	 * "Login as this user" payload for a single user row.
	 *
	 * The free plugin ships no impersonation feature, so it returns
	 * the default `{ is_upgrade_feature: true, data: null }`. The
	 * `is_upgrade_feature` marker drives the frontend's "Available
	 * with an extension" card. Add-ons may hook
	 * `wptw_get_login_as_another_user_response` to substitute a real
	 * payload for the requesting admin.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Payload shape `{ is_upgrade_feature: bool, data: mixed }` after filter.
	 */
	public function wptw_login_as_another_user( $user_id ) {
		return apply_filters(
			'wptw_get_login_as_another_user_response',
			array(
				'is_upgrade_feature' => true,
				'data'               => null,
			),
			$user_id
		);
	}

	/**
	 * Two-factor authentication status payload for a single user row.
	 *
	 * The free plugin ships no 2FA implementation, so it returns the
	 * default `{ is_upgrade_feature: true, data: null }`. The
	 * `is_upgrade_feature` marker lets the frontend render its
	 * uniform "Available with an extension" card. Add-ons hook
	 * `wptw_get_user_two_fa_status_response` to surface real 2FA
	 * state (typically clearing `is_upgrade_feature`).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Payload shape `{ is_upgrade_feature: bool, data: mixed }` after filter.
	 */
	public function wptw_two_fa_status( $user_id ) {
		return apply_filters(
			'wptw_get_user_two_fa_status_response',
			array(
				'is_upgrade_feature' => true,
				'data'               => null,
			),
			$user_id
		);
	}

	/**
	 * User block-status payload for a single user row.
	 *
	 * The free plugin ships no user-block feature, so it returns the default
	 * `{ is_upgrade_feature: true, data: null }`. Pro hooks
	 * `wptw_get_user_block_status_response` to surface the real block state —
	 * same per-user-status pattern as 2FA (`wptw_two_fa_status`) and expiry.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array Payload shape `{ is_upgrade_feature: bool, data: mixed }` after filter.
	 */
	public function wptw_user_block_status( $user_id ) {
		return apply_filters(
			'wptw_get_user_block_status_response',
			array(
				'is_upgrade_feature' => true,
				'data'               => null,
			),
			$user_id
		);
	}
}
