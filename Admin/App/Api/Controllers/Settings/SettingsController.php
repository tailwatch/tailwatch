<?php
namespace Tailwatch\Admin\App\Api\Controllers\Settings;

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsController {

	/**
	 * Get current user information
	 *
	 * @return array User data with status code and message.
	 */
	public function tailwatch_get_user_info() {
		try {
			// Ensure the user is logged in.
			if ( ! is_user_logged_in() ) {
				$response = array(
					'data'    => array(),
					'message' => esc_html__( 'You must be logged in to view this resource.', 'tailwatch' ),
					'code'    => 401,
				);

				return $response;
			}

			$current_user = wp_get_current_user();

			$user_data = array(
				'name'            => $current_user->display_name,
				'email'           => $current_user->user_email,
				'role'            => $current_user->roles,
				'profile_picture' => get_avatar_url( $current_user->ID ),
				'logout_url'      => wp_logout_url(),
			);

			$response = array(
				'data'    => $user_data,
				'message' => __( 'User information retrieved successfully.', 'tailwatch' ),
				'code'    => 200,
			);

			return $response;

		} catch ( \Throwable $e ) {
			// Error occurred while retrieving user information.
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while retrieving user information.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_verify_import_and_reset_status() {
		try {
			$DBModel = new DBModel();
			// Check reset status first
			$reset_id = $DBModel->get_latest_reset_option_name();
			if ( $reset_id ) {
				$batch_state = get_transient( $reset_id );
				if ( $batch_state && isset( $batch_state['option_keys'] ) ) {
					$current_index = $batch_state['current_index'] ?? 0;
					$total         = count( $batch_state['option_keys'] );
					$completed     = isset( $batch_state['completed'] ) ? $batch_state['completed'] : ( $current_index >= $total );
					if ( ! $completed ) {
						return array(
							'running'   => 'reset',
							'completed' => false,
							'message'   => __( 'Reset process is running.', 'tailwatch' ),
							'code'      => 200,
							'details'   => array(
								'reset_id'      => $reset_id,
								'current_index' => $current_index,
								'total'         => $total,
								'completed'     => $completed,
							),
						);
					}
				}
			}

			return array(
				'running'   => null,
				'completed' => true,
				'message'   => __( 'No reset process is running.', 'tailwatch' ),
				'code'      => 200,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to verify import and reset status: ' . $e->getMessage(),
				array(
					'feature'  => 'settings',
					'action'   => 'settings_import_reset_status_verify_failed',
					'title'  => 'Settings Import Failed',
					'detail'   => $e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
					'origin'   => 'system',
					'severity' => 'high',
				)
			);
			return array(
				'message' => __( 'An error occurred while verifying the import or reset status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}
}
