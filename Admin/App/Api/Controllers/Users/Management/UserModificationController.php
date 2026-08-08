<?php
namespace Tailwatch\Admin\App\Api\Controllers\Users\Management;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Traits\ContextAuthorizationTrait;

/**
 * User Modification Controller.
 *
 * ## Authorization model
 *
 * These methods are reachable through the wp-admin AJAX router
 * (AjaxRequestController) — the upstream gate verifies `tailwatch_ajax_nonce` AND
 * `current_user_can('manage_options')` before dispatch.
 *
 * Each public method also calls `is_authorized_request()` (provided by
 * ContextAuthorizationTrait) as defense in depth, re-verifying `manage_options`.
 */
class UserModificationController {

	use ContextAuthorizationTrait;

	/**
	 * Apply caller-supplied meta to a user, allowlisting the keys so
	 * `wp_capabilities` / `wp_user_level` / `session_tokens` etc.
	 * can't be written via `sanitize_key()` (which permits them).
	 * Extend the allowlist via the `tailwatch_user_modification_allowed_meta_keys`
	 * filter.
	 *
	 * @return string[] Keys actually applied.
	 */
	private function apply_safe_user_meta( $user_id, $meta ) {
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return array();
		}

		$allowed_keys = apply_filters(
			'tailwatch_user_modification_allowed_meta_keys',
			array(
				'first_name',
				'last_name',
				'nickname',
				'description',
				'user_url',
				'locale',
				'rich_editing',
				'syntax_highlighting',
				'comment_shortcuts',
				'admin_color',
				'show_admin_bar_front',
			)
		);

		$applied = array();
		foreach ( $meta as $meta_key => $meta_value ) {
			$sanitized_key = sanitize_key( (string) $meta_key );
			if ( '' === $sanitized_key || ! in_array( $sanitized_key, $allowed_keys, true ) ) {
				continue;
			}
			update_user_meta( $user_id, $sanitized_key, sanitize_text_field( $meta_value ) );
			$applied[] = $sanitized_key;
		}
		return $applied;
	}

	/**
	 * Create a new WordPress user
	 *
	 * @param string $post_data JSON string containing user data
	 * @return array Response with success status and user ID or error
	 */
	public function tailwatch_create_user( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			// Parse and sanitize input
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'User creation failed: Invalid JSON data provided.',
					array(
						'feature' => 'users',
						'action'  => 'create_user_failed',
						'title'  => 'User Create Failed',
						'detail'  => 'Invalid JSON data provided',
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
				);
			}

			// Validate required fields
			$username = isset( $data['username'] ) ? sanitize_user( $data['username'] ) : '';
			$email    = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			$password = isset( $data['password'] ) ? $data['password'] : '';
			$role     = isset( $data['role'] ) ? sanitize_text_field( wp_unslash( $data['role'] ) ) : 'subscriber';

			// Validation checks
			if ( empty( $username ) ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Username is required.', 'tailwatch' ),
				);
			}

			if ( empty( $email ) ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Email is required.', 'tailwatch' ),
				);
			}

			if ( empty( $password ) ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Password is required.', 'tailwatch' ),
				);
			}

			// Validate email format
			if ( ! is_email( $email ) ) {
				Log::error(
					'User creation failed: Invalid email format.',
					array(
						'feature' => 'users',
						'action'  => 'create_user_failed',
						'title'  => 'User Create Failed',
						'detail'  => "Invalid email format: $email",
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid email format.', 'tailwatch' ),
				);
			}

			// Check if username already exists
			if ( username_exists( $username ) ) {
				Log::error(
					'User creation failed: Username already exists.',
					array(
						'feature' => 'users',
						'action'  => 'create_user_failed',
						'title'  => 'User Create Failed',
						'detail'  => "Username already exists: $username",
					)
				);

				return array(
					'success' => false,
					'code'    => 409,
					'message' => __( 'Username already exists.', 'tailwatch' ),
				);
			}

			// Check if email already exists
			if ( email_exists( $email ) ) {
				Log::error(
					'User creation failed: Email already exists.',
					array(
						'feature' => 'users',
						'action'  => 'create_user_failed',
						'title'  => 'User Create Failed',
						'detail'  => "Email already exists: $email",
					)
				);

				return array(
					'success' => false,
					'code'    => 409,
					'message' => __( 'Email already exists.', 'tailwatch' ),
				);
			}

			// get_editable_roles() respects the editable_roles filter (multisite admin-hiding)
			// and constrains which roles may be assigned. The request is already behind the
			// router's manage_options gate, so role assignment is validated against
			// get_editable_roles() below rather than a separate promote_users check.
			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$editable_roles = get_editable_roles();
			if ( ! array_key_exists( $role, $editable_roles ) ) {
				Log::error(
					'User creation failed: Invalid or non-editable role specified.',
					array(
						'feature' => 'users',
						'action'  => 'create_user_failed',
						'title'  => 'User Create Failed',
						'detail'  => "Role not in editable_roles: $role",
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid role specified.', 'tailwatch' ),
				);
			}

			// Prepare user data.
			$userdata = array(
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => $role,
			);

			// Optional fields.
			if ( isset( $data['first_name'] ) ) {
				$userdata['first_name'] = sanitize_text_field( wp_unslash( $data['first_name'] ) );
			}

			if ( isset( $data['last_name'] ) ) {
				$userdata['last_name'] = sanitize_text_field( wp_unslash( $data['last_name'] ) );
			}

			if ( isset( $data['display_name'] ) ) {
				$userdata['display_name'] = sanitize_text_field( wp_unslash( $data['display_name'] ) );
			}

			if ( isset( $data['website'] ) ) {
				$userdata['user_url'] = esc_url_raw( $data['website'] );
			}

			if ( isset( $data['description'] ) ) {
				$userdata['description'] = sanitize_textarea_field( $data['description'] );
			}

			if ( isset( $data['nickname'] ) ) {
				$userdata['nickname'] = sanitize_text_field( wp_unslash( $data['nickname'] ) );
			}

			$user_id = wp_insert_user( $userdata );

			// Check for errors
			if ( is_wp_error( $user_id ) ) {
				Log::error(
					'User creation failed: ' . $user_id->get_error_message(),
					array(
						'feature' => 'users',
						'action'  => 'create_user_failed',
						'title'  => 'User Create Failed',
						'detail'  => $user_id->get_error_message(),
					)
				);

				return array(
					'success' => false,
					'code'    => 500,
					'message' => sprintf(
						/* translators: %s: WordPress error message */
						__( 'Failed to create user: %s', 'tailwatch' ),
						$user_id->get_error_message()
					),
				);
			}

			// Handle user meta if provided.
			$this->apply_safe_user_meta( $user_id, $data['meta'] ?? null );

			Log::info(
				'User created successfully.',
				array(
					'feature'  => 'users',
					'action'   => 'user_created',
					'title'  => 'User Created',
					'user_id'  => $user_id,
					'username' => $username,
					'role'     => $role,
				)
			);

			// Send notification email (optional).
			$send_notification = isset( $data['send_notification'] ) ? (bool) $data['send_notification'] : true;
			if ( $send_notification ) {
				wp_new_user_notification( $user_id, null, 'both' );
			}

			return array(
				'success' => true,
				'code'    => 201,
				'message' => __( 'User created successfully.', 'tailwatch' ),
				'data'    => array(
					'user_id'  => $user_id,
					'username' => $username,
					'email'    => $email,
					'role'     => $role,
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'create_user_failed',
					'title'  => 'User Create Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to create user due to server error.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Update user information
	 *
	 * @param string $post_data JSON string containing user data to update
	 * @return array Response with success status
	 */
	public function tailwatch_update_user( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			// Parse and sanitize input
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'User update failed: Invalid JSON data provided.',
					array(
						'feature' => 'users',
						'action'  => 'update_user_failed',
						'title'  => 'User Update Failed',
						'detail'  => 'Invalid JSON data provided',
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid JSON data provided.', 'tailwatch' ),
				);
			}

			// Validate user_id.
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
				Log::error(
					'User update failed: User not found.',
					array(
						'feature' => 'users',
						'action'  => 'update_user_failed',
						'title'  => 'User Update Failed',
						'detail'  => "User with ID $user_id not found",
					)
				);

				return array(
					'success' => false,
					'code'    => 404,
					'message' => __( 'User not found.', 'tailwatch' ),
				);
			}

			// Prepare update data
			$userdata     = array( 'ID' => $user_id );
			$updates_made = array();

			// Update email if provided.
			if ( isset( $data['email'] ) ) {
				$new_email = sanitize_email( $data['email'] );

				if ( ! is_email( $new_email ) ) {
					return array(
						'success' => false,
						'code'    => 400,
						'message' => __( 'Invalid email format.', 'tailwatch' ),
					);
				}

				$email_user_id = email_exists( $new_email );
				if ( $email_user_id && (int) $email_user_id !== (int) $user_id ) {
					return array(
						'success' => false,
						'code'    => 409,
						'message' => __( 'Email already in use by another user.', 'tailwatch' ),
					);
				}

				$userdata['user_email'] = $new_email;
				$updates_made[]         = sprintf(
					/* translators: %s: updated user email address */
					__( 'email updated to %s', 'tailwatch' ),
					$new_email
				);
			}

			if ( isset( $data['first_name'] ) ) {
				$userdata['first_name'] = sanitize_text_field( wp_unslash( $data['first_name'] ) );
				$updates_made[]         = __( 'first name updated', 'tailwatch' );
			}

			if ( isset( $data['last_name'] ) ) {
				$userdata['last_name'] = sanitize_text_field( wp_unslash( $data['last_name'] ) );
				$updates_made[]        = __( 'last name updated', 'tailwatch' );
			}

			if ( isset( $data['display_name'] ) ) {
				$userdata['display_name'] = sanitize_text_field( wp_unslash( $data['display_name'] ) );
				$updates_made[]           = __( 'display name updated', 'tailwatch' );
			}

			if ( isset( $data['website'] ) ) {
				$userdata['user_url'] = esc_url_raw( $data['website'] );
				$updates_made[]       = __( 'website updated', 'tailwatch' );
			}

			if ( isset( $data['description'] ) ) {
				$userdata['description'] = sanitize_textarea_field( $data['description'] );
				$updates_made[]          = __( 'description updated', 'tailwatch' );
			}

			if ( isset( $data['nickname'] ) ) {
				$userdata['nickname'] = sanitize_text_field( wp_unslash( $data['nickname'] ) );
				$updates_made[]       = __( 'nickname updated', 'tailwatch' );
			}

			if ( isset( $data['role'] ) && '' !== $data['role'] ) {
				$new_role   = sanitize_text_field( $data['role'] );
				$validation = $this->validate_role_change( $user, $new_role );
				if ( true !== $validation ) {
					return $validation;
				}

				// The current user cannot update their own role through this endpoint.
				// validate_role_change() already rejected an actual self-role-change with 403;
				// the only remaining self case is a no-op echo-back of the user's current role
				// (e.g. a profile-update form that re-sends every field). Drop it silently so
				// wp_update_user() does not fire redundant set_user_role / add_user_role /
				// remove_user_role hooks for a change that isn't happening.
				// wp_update_user() applies the role itself when 'role' is in $userdata —
				// don't pre-apply, or those hooks fire twice.
				if ( (int) $user_id !== get_current_user_id() ) {
					$userdata['role'] = $new_role;
					$updates_made[]   = sprintf(
						/* translators: %s: new role name */
						__( 'role updated to %s', 'tailwatch' ),
						$new_role
					);
				}
			}

			if ( isset( $data['password'] ) && ! empty( $data['password'] ) ) {
				$userdata['user_pass'] = $data['password'];
				$updates_made[]        = __( 'password updated', 'tailwatch' );
			}

			if ( count( $userdata ) === 1 ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'No valid fields provided for update.', 'tailwatch' ),
				);
			}

			$updated_user_id = wp_update_user( $userdata );

			if ( is_wp_error( $updated_user_id ) ) {
				Log::error(
					'User update failed: ' . $updated_user_id->get_error_message(),
					array(
						'feature' => 'users',
						'action'  => 'update_user_failed',
						'title'  => 'User Update Failed',
						'detail'  => $updated_user_id->get_error_message(),
					)
				);

				return array(
					'success' => false,
					'code'    => 500,
					'message' => sprintf(
						/* translators: %s: WordPress error message */
						__( 'Failed to update user: %s', 'tailwatch' ),
						$updated_user_id->get_error_message()
					),
				);
			}

			// Update user meta if provided.
			$applied_meta_keys = $this->apply_safe_user_meta( $user_id, $data['meta'] ?? null );
			foreach ( $applied_meta_keys as $applied_key ) {
				$updates_made[] = sprintf(
					/* translators: %s: updated user meta key */
					__( 'meta %s updated', 'tailwatch' ),
					$applied_key
				);
			}

			Log::info(
				'User information updated.',
				array(
					'feature' => 'users',
					'action'  => 'user_updated',
					'title'  => 'User Updated',
					'user_id' => $user_id,
					'updates' => $updates_made,
				)
			);

			return array(
				'success' => true,
				'code'    => 200,
				'message' => __( 'User updated successfully.', 'tailwatch' ),
				'data'    => array(
					'user_id' => $user_id,
					'updates' => $updates_made,
				),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'update_user_failed',
					'title'  => 'User Update Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to update user due to server error.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Change user role
	 *
	 * @param string $post_data JSON string containing user_id and new_role
	 * @return array Response with success status
	 */
	public function tailwatch_change_user_role( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$new_role = isset( $data['new_role'] ) ? sanitize_text_field( $data['new_role'] ) : '';
			$user_id  = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;

			if ( empty( $user_id ) || empty( $new_role ) ) {
				Log::error(
					'User role change failed: User ID or role parameter is missing.',
					array(
						'feature' => 'users',
						'action'  => 'change_user_role_failed',
						'title'  => 'User Role Change Failed',
						'detail'  => 'User ID or role parameter is missing',
					)
				);

				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'User ID and role are required.', 'tailwatch' ),
				);
			}

			$user = get_userdata( $user_id );

			if ( ! $user ) {
				Log::error(
					'User role change failed: User not found.',
					array(
						'feature' => 'users',
						'action'  => 'change_user_role_failed',
						'title'  => 'User Role Change Failed',
						'detail'  => "User with ID $user_id not found",
					)
				);

				return array(
					'success' => false,
					'code'    => 404,
					'message' => __( 'User not found.', 'tailwatch' ),
				);
			}

			$old_roles  = $user->roles;
			$validation = $this->validate_role_change( $user, $new_role );
			if ( true !== $validation ) {
				return $validation;
			}
			$user->set_role( $new_role );

			Log::info(
				'User role changed successfully.',
				array(
					'feature'  => 'users',
					'action'   => 'user_role_changed',
					'title'  => 'User Role Changed',
					'user_id'  => $user_id,
					'old_role' => $old_roles,
					'new_role' => $new_role,
				)
			);

			return array(
				'success' => true,
				'code'    => 200,
				'message' => __( 'User role updated successfully.', 'tailwatch' ),
				'data'    => array(
					'user_id'  => $user_id,
					'old_role' => $old_roles,
					'new_role' => $new_role,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'change_user_role_failed',
					'title'  => 'User Role Change Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to change user role.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Update user status (e.g. without_password)
	 *
	 * @param string $post_data JSON string containing user_id and status
	 * @return array Response with success status
	 */
	public function tailwatch_update_user_status( $post_data ) {
		try {
			if ( ! $this->is_authorized_request() ) {
				return $this->unauthorized_response();
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
			$status  = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';

			// Whitelist allowed status values. Empty string clears the meta.
			$allowed_statuses = array( '', 'without_password' );
			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'Invalid status value.', 'tailwatch' ),
				);
			}

			if ( empty( $user_id ) ) {
				return array(
					'success' => false,
					'code'    => 400,
					'message' => __( 'User ID is required.', 'tailwatch' ),
				);
			}

			if ( ! get_userdata( $user_id ) ) {
				return array(
					'success' => false,
					'code'    => 404,
					'message' => __( 'User not found.', 'tailwatch' ),
				);
			}

			// Prevent users from disabling/locking their own account.
			if ( (int) $user_id === get_current_user_id() ) {
				Log::error(
					'User status update failed: Self status change attempt.',
					array(
						'feature' => 'users',
						'action'  => 'update_user_status_failed',
						'title'   => 'User Status Update Failed',
						'detail'  => "User attempted to change their own status: $user_id",
					)
				);

				return array(
					'success' => false,
					'code'    => 403,
					'message' => __( 'You cannot change your own account status.', 'tailwatch' ),
				);
			}

			// On multisite, do not let a non-super-admin lock out a super administrator.
			if ( is_multisite() && is_super_admin( $user_id ) && 'without_password' === $status ) {
				return array(
					'success' => false,
					'code'    => 403,
					'message' => __( 'Cannot change the status of a super administrator.', 'tailwatch' ),
				);
			}

			if ( ! empty( $status ) ) {
				update_user_meta( $user_id, '_tailwatch_user_status', $status );
			} else {
				delete_user_meta( $user_id, '_tailwatch_user_status' );
			}

			Log::info(
				'User status updated.',
				array(
					'feature' => 'users',
					'action'  => 'user_status_updated',
					'title'  => 'User Status Updated',
					'user_id' => $user_id,
					'status'  => $status ? $status : 'removed',
				)
			);

			return array(
				'success' => true,
				'code'    => 200,
				'message' => __( 'User status updated successfully.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'update_user_status_failed',
					'title'  => 'User Status Update Failed',
					'exception' => $e,
				)
			);

			return array(
				'success' => false,
				'code'    => 500,
				'message' => __( 'Failed to update user status.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Validate a proposed role change. Pure validation — does NOT mutate the user.
	 * Callers apply the role via wp_update_user() or $user->set_role() once validation passes.
	 *
	 * @param \WP_User $user     The user object to change role for.
	 * @param string   $new_role The new role slug.
	 * @return true|array True on success, error response array on failure.
	 */
	private function validate_role_change( $user, $new_role ) {
		// get_editable_roles() respects the editable_roles filter (multisite admin-hiding).
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$editable_roles = get_editable_roles();
		if ( ! array_key_exists( $new_role, $editable_roles ) ) {
			return array(
				'success' => false,
				'code'    => 400,
				'message' => __( 'Invalid role.', 'tailwatch' ),
			);
		}

		$current_roles = (array) $user->roles;
		// "No change" = the user already has exactly this single role. Mirrors WP core behavior:
		// the user-edit screen hides the role dropdown for self, but wp_update_user() doesn't
		// reject a no-op role payload — only an actual change to your own role is blocked.
		$is_no_op = ( 1 === count( $current_roles ) ) && in_array( $new_role, $current_roles, true );

		// Self-role-change guard. get_current_user_id() returns 0 when there is no logged-in
		// user (e.g. a cron/CLI context) — comparing against a positive $user->ID is
		// safe and only blocks the AJAX path where a real user session exists.
		if ( ! $is_no_op && (int) $user->ID === get_current_user_id() ) {
			return array(
				'success' => false,
				'code'    => 403,
				'message' => __( 'You cannot change your own role.', 'tailwatch' ),
			);
		}

		// On multisite, the target user must be a member of the current blog before their
		// role on this blog can be changed. WP core does this check in wp-admin/users.php
		// for the bulk role action.
		if ( is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
			return array(
				'success' => false,
				'code'    => 403,
				'message' => __( 'User is not a member of this site.', 'tailwatch' ),
			);
		}

		// Super admin status (multisite) is independent of role; demoting their role can lock
		// them out of single-site admin UI without removing their network privileges.
		if ( is_multisite() && is_super_admin( $user->ID ) && 'administrator' !== $new_role ) {
			return array(
				'success' => false,
				'code'    => 403,
				'message' => __( 'Cannot change the role of a super administrator.', 'tailwatch' ),
			);
		}

		// Last real-admin protection on role demotion. Passwordless temporary admins
		// (Pro feature) carry the administrator role but don't constitute real admin
		// presence — they expire, get revoked, or hit usage limits. The gate fires only
		// when:
		//   1. The target is currently an administrator AND being demoted
		//   2. The target is a REAL admin (not a passwordless temp user)
		//   3. There would be no other real admin left after the demotion
		if ( in_array( 'administrator', $current_roles, true ) && 'administrator' !== $new_role ) {
			$target_status        = get_user_meta( $user->ID, '_tailwatch_user_status', true );
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
									'key'     => '_tailwatch_user_status',
									'compare' => 'NOT EXISTS',
								),
								array(
									'key'     => '_tailwatch_user_status',
									'value'   => 'without_password',
									'compare' => '!=',
								),
							),
						)
					)
				);

				if ( $real_admin_count <= 1 ) {
					return array(
						'success' => false,
						'code'    => 403,
						'message' => __( 'Cannot change the role of the last administrator.', 'tailwatch' ),
					);
				}
			}
		}

		return true;
	}
}
