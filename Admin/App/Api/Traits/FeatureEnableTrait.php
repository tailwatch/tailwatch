<?php
/**
 * Tailwatch - Feature Enable Trait
 *
 * Provides feature enablement verification methods for controllers.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Traits
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait FeatureEnableTrait {

	/**
	 * Public method for AjaxRequestController to call
	 * This wraps the protected wptw_get_feature_status()
	 *
	 * @param string|null $method_name The name of the method being called (optional)
	 * @return array Feature status with 'feature_enable' key
	 */
	public function wptw_check_feature_enabled( $method_name = null ) {
		// Check for whitelist in the controller
		// Controllers can define protected $wptw_feature_check_exemptions = ['method_name'];
		if ( $method_name && isset( $this->wptw_feature_check_exemptions ) && is_array( $this->wptw_feature_check_exemptions ) ) {
			if ( in_array( $method_name, $this->wptw_feature_check_exemptions ) ) {
				return array(
					'feature_enable' => true,
					'parent_enable'  => true,
					'message'        => __( 'Feature check skipped for exempted method.', 'tailwatch' ),
				);
			}
		}

		return $this->wptw_get_feature_status();
	}

	/**
	 * Protected method for use within controller methods
	 * Returns error array if disabled, null if enabled
	 *
	 * @return array|null
	 */
	protected function wptw_validate_feature_enabled() {
		$status = $this->wptw_get_feature_status();

		if ( ! $status['feature_enable'] ) {
			return array(
				'feature_enable' => $status['feature_enable'],
				'parent_enable'  => $status['parent_enable'],
				'message'        => __( 'Feature is not enabled. Please enable it first.', 'tailwatch' ),
				'code'           => 400,
			);
		}

		return null; // Feature is enabled
	}

	/**
	 * Abstract method that using classes must implement
	 *
	 * @return array
	 */
	abstract protected function wptw_get_feature_status();
}
