<?php
namespace Tailwatch\Admin\App\Api\Controllers\Routes;

defined( 'ABSPATH' ) || exit;

class FieldsValidationController {

	public static function wptw_validate_json_and_fields( $post_data, $required_fields ) {
		$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
		$data      = json_decode( $json_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid JSON data', 'tailwatch' ),
				'code'    => 400,
			);
		}

		foreach ( $required_fields as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				return array(
					'success' => false,
					'message' => "Missing required field: $field",
					'code'    => 400,
				);
			}
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}
}
