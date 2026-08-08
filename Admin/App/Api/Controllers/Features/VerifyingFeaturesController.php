<?php
namespace Tailwatch\Admin\App\Api\Controllers\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;

class VerifyingFeaturesController {

	public function tailwatch_verify_options_status( $all_options ) {
		$selected_options = array();

		foreach ( $all_options as $option ) {
			if ( ! empty( $option['values'] ) ) {
				foreach ( $option['values'] as $feature ) {
					if ( ! empty( $feature['selected'] ) && true === $feature['selected'] ) {
						$selected_options[] = $option['id'];
						break;
					}
				}
			}
		}

		return $selected_options;
	}

	public function tailwatch_get_option_labels( $all_options ) {
		$labels = array();

		foreach ( $all_options as $option ) {
			if ( isset( $option['id'], $option['label'] ) ) {
				$labels[ $option['id'] ] = $option['label'];
			}
		}

		return $labels;
	}

	public function tailwatch_counts_logs_activity( $option, $key, $type_state = null ) {
		$db_model      = new DBModel();
		$existing_data = $db_model->get_logs_count_by_type( $key, $option, $type_state );

		return empty( $existing_data ) ? 0 : $existing_data;
	}

}