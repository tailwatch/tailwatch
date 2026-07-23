<?php
/**
 * Disable Keys Controller
 *
 * Enqueues scripts and options for disabling browser dev tools shortcuts (context menu, inspect, copy/paste, etc.).
 *
 * @package Tailwatch\Admin\App\Api\Controllers\BrowserKeys
 */

namespace Tailwatch\Admin\App\Api\Controllers\BrowserKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;

/**
 * Disable Keys Controller
 */
class DisableKeysController {

	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'wp_enqueue_scripts', array( $this, 'wptw_disable_keys_options' ) );
	}

	/**
	 * Get feature options for disable keys.
	 *
	 * @return array|false
	 */
	private function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_disable_keys';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Enqueue disable-keys script and pass options to JS.
	 */
	public function wptw_disable_keys_options() {
		$get_feature = $this->get_features_options();

		if ( $get_feature ) {
			wp_enqueue_style(
				'wptw-toast-css',
				WPTW_URI . 'Admin/Assets/css/wptw-toast.css',
				array(),
				WPTW_VERSION
			);

			wp_enqueue_script(
				'wptw-toast-js',
				WPTW_URI . 'Admin/Assets/js/wptw-toast.js',
				array(),
				WPTW_VERSION,
				true
			);

			wp_enqueue_script(
				'wptw-disable-keys-js',
				WPTW_URI . 'Admin/Assets/js/wptw-disable-keys.js',
				array( 'wptw-toast-js' ),
				WPTW_VERSION,
				true
			);

			$disable_keys_options = array(
				'contextMenu'    => isset( $get_feature['field_1']['options']['option']['selected'] ) && $get_feature['field_1']['options']['option']['selected'],
				'inspectElement' => isset( $get_feature['field_2']['options']['option']['selected'] ) && $get_feature['field_2']['options']['option']['selected'],
				'copyPasteCut'   => isset( $get_feature['field_3']['options']['option']['selected'] ) && $get_feature['field_3']['options']['option']['selected'],
				'viewSource'     => isset( $get_feature['field_4']['options']['option']['selected'] ) && $get_feature['field_4']['options']['option']['selected'],
				'dragDrop'       => isset( $get_feature['field_5']['options']['option']['selected'] ) && $get_feature['field_5']['options']['option']['selected'],
			);

			wp_localize_script( 'wptw-disable-keys-js', 'wptwDisableKeysOptions', $disable_keys_options );
		}
	}
}
