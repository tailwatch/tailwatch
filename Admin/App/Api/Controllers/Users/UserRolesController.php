<?php
namespace Tailwatch\Admin\App\Api\Controllers\Users;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;

class UserRolesController {

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'init', array( $this, 'tailwatch_check_for_new_roles' ) );
	}

	/**
	 * Get user roles.
	 *
	 * @return array<string, string> Role slug => translated name.
	 */
	public function tailwatch_get_user_roles() {
		try {
			global $wp_roles;
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new \WP_Roles();
			}
			$all_roles = $wp_roles->roles;
			$result    = array();
			foreach ( $all_roles as $role_name => $role_info ) {
				$result[ $role_name ] = translate_user_role( $role_info['name'] );
			}

			return $result;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'user_roles_retrieval_failed',
					'title'  => 'User Roles Retrieval Failed',
					'exception' => $e,
				)
			);
			return array();
		}
	}

	/**
	 * Get user roles as API response data.
	 *
	 * @return array
	 */
	public function tailwatch_get_user_roles_data() {
		try {
			$editable_roles = $this->tailwatch_get_user_roles();

			if ( empty( $editable_roles ) ) {
				Log::error(
					'User roles data retrieval failed: No user roles found.',
					array(
						'feature' => 'users',
						'action'  => 'user_roles_data_retrieval_failed',
						'title'  => 'User Roles Retrieval Failed',
						'detail'  => 'No user roles found',
					)
				);

				return array(
					'data'    => array(),
					'message' => __( 'No user roles found.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			return array(
				'data'    => $editable_roles,
				'message' => __( 'User roles retrieved successfully.', 'tailwatch' ),
				'code'    => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'user_roles_data_retrieval_failed',
					'title'  => 'User Roles Retrieval Failed',
					'exception' => $e,
				)
			);

			return array(
				'data'    => array(),
				'message' => __( 'Failed to retrieve user roles.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Check for new roles and update stored option.
	 *
	 * @return bool True if new roles were detected and updated.
	 */
	public function tailwatch_check_for_new_roles() {
		try {
			$user_roles     = $this->tailwatch_get_user_roles();
			$existing_json  = get_option( 'tailwatch_user_roles', '[]' );
			$existing_roles = json_decode( $existing_json, true );
			$existing_roles = is_array( $existing_roles ) ? $existing_roles : array();
			$new_roles      = array_diff_key( $user_roles, $existing_roles );

			if ( ! empty( $new_roles ) ) {
				update_option( 'tailwatch_user_roles', wp_json_encode( $user_roles ), false );
				$this->tailwatch_update_user_roles_in_json( $user_roles );

				return true;
			}

			return false;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage(),
				array(
					'feature'   => 'users',
					'action'    => 'new_user_roles_detection_failed',
					'title'  => 'User Roles Detection Failed',
					'exception' => $e,
				)
			);
			return false;
		}
	}

	/**
	 * Update user roles in JSON option values.
	 *
	 * @param array<string, string> $all_roles Role slug => name.
	 */
	public function tailwatch_update_user_roles_in_json( $all_roles ) {
		$key      = 'default_feature_settings';
		$options  = array( 'default_two_step_authenticate' );
		$db_model = new DBModel();

		foreach ( $options as $option ) {
			$option_data = $db_model->get_value( $option, $key );
			if ( is_string( $option_data ) ) {
				$option_data = json_decode( $option_data, true );
				$option_data = is_array( $option_data ) ? $option_data : array();
			}

			$updated = $this->tailwatch_recursive_update_roles( $option_data, $all_roles );

			if ( $updated ) {
				$db_data = array( 'value' => wp_json_encode( $option_data ) );
				$where   = array(
					'key'    => $key,
					'option' => $option,
				);
				$db_model->update_rows( $db_data, $where );
			}
		}
	}

	/**
	 * Recursively update role options in nested data.
	 *
	 * @param array $data      Data by reference.
	 * @param array $all_roles Role slug => name.
	 * @return bool Whether anything was updated.
	 */
	private function tailwatch_recursive_update_roles( &$data, $all_roles ) {
		$updated = false;

		if ( ! is_array( $data ) ) {
			return $updated;
		}

		if ( isset( $data['user_roles'] ) && true === $data['user_roles'] && isset( $data['values'] ) ) {
			$current_roles = array();
			foreach ( $data['values'] as $key => $option ) {
				if ( isset( $option['value'] ) ) {
					$current_roles[ $option['value'] ] = $key;
				}
			}

			$existing_keys  = array_keys( $data['values'] );
			$max_option_num = 0;
			foreach ( $existing_keys as $key ) {
				if ( preg_match( '/^option(\d*)$/', $key, $matches ) ) {
					$num            = (int) ( '' === $matches[1] ? 1 : $matches[1] );
					$max_option_num = max( $max_option_num, $num );
				}
			}

			foreach ( $all_roles as $role_slug => $role_name ) {
				if ( ! isset( $current_roles[ $role_slug ] ) ) {
					$next_option_num                   = $max_option_num + 1;
					$new_option_key                    = $next_option_num > 1 ? 'option' . $next_option_num : 'option';
					$data['values'][ $new_option_key ] = array(
						'label'    => $role_name,
						'value'    => $role_slug,
						'selected' => false,
					);
					++$max_option_num;
					$updated = true;
				}
			}
		}

		foreach ( $data as &$sub_data ) {
			$updated = $this->tailwatch_recursive_update_roles( $sub_data, $all_roles ) || $updated;
		}

		return $updated;
	}

	/**
	 * Generate role options for forms.
	 *
	 * @return array
	 */
	public function tailwatch_generate_role_options() {
		$user_roles = $this->tailwatch_get_user_roles();
		$values     = array();
		$index      = 0;

		foreach ( $user_roles as $role_slug => $role_name ) {
			$option_key            = 0 === $index ? 'option' : 'option' . ( $index + 1 );
			$values[ $option_key ] = array(
				'label'    => $role_name,
				'value'    => $role_slug,
				'selected' => 'administrator' === $role_slug,
			);
			++$index;
		}

		return $values;
	}
}
