<?php

namespace Tailwatch\Admin\App\Api\Controllers\PluginTheme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;

/**
 * Class PluginThemeController
 *
 * Handles plugin and theme update checks, feature enablement, and notifications.
 *
 */
class PluginThemeController {

	/**
	 * Get plugin and theme options.
	 *
	 * @return array Feature settings array.
	 */
	public static function tailwatch_plugin_theme_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_updates_rollback';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Check if theme update feature is enabled.
	 *
	 * @return array {
	 *     Feature status array.
	 *
	 *     @type bool $parent_enable    Whether header options are valid.
	 *     @type bool $feature_enable   Whether theme updates are enabled.
	 *     @type bool $scheduler_enable Whether scheduling is enabled.
	 * }
	 */
	public function tailwatch_theme_update_feature_enable() {
		$feature_settings = self::tailwatch_plugin_theme_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'    => false,
				'feature_enable'   => false,
				'scheduler_enable' => false,
			);
		}

		$theme_enabled = $feature_settings['field_1']['options']['option']['selected'] ?? false;

		$scheduler_enabled = $feature_settings['field_1']['sub_options']['field_2']['options']['option']['selected'] ?? false;

		if ( $theme_enabled === true ) {
			return array(
				'parent_enable'    => true,
				'feature_enable'   => true,
				'scheduler_enable' => $scheduler_enabled,
			);
		}

		return array(
			'parent_enable'    => true,
			'feature_enable'   => false,
			'scheduler_enable' => false,
		);
	}

	/**
	 * Check if plugin update feature is enabled.
	 *
	 * @return array {
	 *     Feature status array.
	 *
	 *     @type bool $parent_enable    Whether header options are valid.
	 *     @type bool $feature_enable   Whether plugin updates are enabled.
	 *     @type bool $scheduler_enable Whether scheduling is enabled.
	 * }
	 */
	public function tailwatch_plugin_update_feature_enable() {
		$feature_settings = self::tailwatch_plugin_theme_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'    => false,
				'feature_enable'   => false,
				'scheduler_enable' => false,
			);
		}

		$plugin_enabled = $feature_settings['field_4']['options']['option']['selected'] ?? false;

		$scheduler_enabled = $feature_settings['field_4']['sub_options']['field_5']['options']['option']['selected'] ?? false;

		if ( $plugin_enabled === true ) {
			return array(
				'parent_enable'    => true,
				'feature_enable'   => true,
				'scheduler_enable' => $scheduler_enabled,
			);
		}

		return array(
			'parent_enable'    => true,
			'feature_enable'   => false,
			'scheduler_enable' => false,
		);
	}

	/**
	 * Get theme scheduler interval.
	 *
	 * @return string Scheduler interval (e.g., '1_day').
	 */
	public function tailwatch_get_theme_scheduler_interval() {
		$feature_settings = self::tailwatch_plugin_theme_options();

		if ( empty( $feature_settings ) ) {
			return '1_day'; // Default
		}

		$interval_options = $feature_settings['field_1']['sub_options']['field_2']['sub_options']['field_3']['options'] ?? array();

		foreach ( $interval_options as $option ) {
			if ( isset( $option['selected'] ) && $option['selected'] === true ) {
				return $option['value'];
			}
		}

		return '1_day'; // Default
	}

	/**
	 * Get plugin scheduler interval.
	 *
	 * @return string Scheduler interval (e.g., '1_day').
	 */
	public function tailwatch_get_plugin_scheduler_interval() {
		$feature_settings = self::tailwatch_plugin_theme_options();

		if ( empty( $feature_settings ) ) {
			return '1_day'; // Default
		}

		$interval_options = $feature_settings['field_4']['sub_options']['field_5']['sub_options']['field_6']['options'] ?? array();

		foreach ( $interval_options as $option ) {
			if ( isset( $option['selected'] ) && $option['selected'] === true ) {
				return $option['value'];
			}
		}

		return '1_day'; // Default
	}

	/**
	 * Get total available updates for themes, plugins, and core.
	 *
	 * @return array {
	 *     Update statistics.
	 *
	 *     @type array  $themes  { @type int $updates, @type int $installed }
	 *     @type array  $plugins { @type int $updates, @type int $installed }
	 *     @type array  $core    { @type int $updates, @type int $installed }
	 *     @type string $message Status message.
	 *     @type int    $code    HTTP status code.
	 * }
	 */
	public function tailwatch_get_total_updates_available() {
		try {
			$theme_updates  = 0;
			$plugin_updates = 0;
			$core_updates   = 0;
			$total_themes   = 0;
			$total_plugins  = 0;

			$theme_updates_data = get_site_transient( 'update_themes' );
			if ( false === $theme_updates_data ) {
				wp_update_themes();
				$theme_updates_data = get_site_transient( 'update_themes' );
			}
			if ( isset( $theme_updates_data->response ) && ! empty( $theme_updates_data->response ) ) {
				$theme_updates = count( $theme_updates_data->response );
			}

			// Get total installed themes
			$all_themes   = wp_get_themes();
			$total_themes = count( $all_themes );

			// Get plugin updates
			$plugin_updates_data = get_site_transient( 'update_plugins' );
			if ( false === $plugin_updates_data ) {
				wp_update_plugins();
				$plugin_updates_data = get_site_transient( 'update_plugins' );
			}
			if ( isset( $plugin_updates_data->response ) && ! empty( $plugin_updates_data->response ) ) {
				$plugin_updates = count( $plugin_updates_data->response );
			}

			// Get total installed plugins
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all_plugins   = get_plugins();
			$total_plugins = count( $all_plugins );

			// Get core updates
			global $wp_version;
			$current_version = $wp_version;

			$response = wp_remote_get( 'https://api.wordpress.org/core/version-check/1.7/' );
			if ( is_wp_error( $response ) ) {
				return array(
					'message' => __( 'Unable to fetch core updates.', 'tailwatch' ),
					'code'    => 500,
				);
			}

			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( ! empty( $data['offers'] ) && isset( $data['offers'][0] ) ) {
				$latest_version = $data['offers'][0]['version'];
				if ( version_compare( $current_version, $latest_version, '<' ) ) {
					$core_updates = 1;
				}
			}

			return array(
				'themes'  => array(
					'updates'   => $theme_updates,
					'installed' => $total_themes,
				),
				'plugins' => array(
					'updates'   => $plugin_updates,
					'installed' => $total_plugins,
				),
				'core'    => array(
					'updates'   => $core_updates,
					'installed' => 1,
				),
				'message' => __( 'Total available updates retrieved successfully.', 'tailwatch' ),
				'code'    => 200,
			);
		} catch ( \Throwable $e ) {
			return array(
				'message' => __( 'Failed to retrieve total updates available.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Check if push notifications are enabled for theme updates.
	 *
	 * Used by NotificationActions for dynamic notification checks.
	 *
	 * @return bool True if push notifications enabled for themes, false otherwise.
	 */
	public function should_notify_theme_update() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_updates_rollback';
		$field_name        = 'field_1'; // enable_updates_rollback_theme

		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Check if push notifications are enabled for plugin updates.
	 *
	 * Used by NotificationActions for dynamic notification checks.
	 *
	 * @return bool True if push notifications enabled for plugins, false otherwise.
	 */
	public function should_notify_plugin_update() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_updates_rollback';
		$field_name        = 'field_4'; // enable_updates_rollback_plugin

		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Check if push notifications are enabled for core updates.
	 *
	 * Used by NotificationActions for dynamic notification checks.
	 *
	 * @return bool True if push notifications enabled for core, false otherwise.
	 */
	public function should_notify_core_update() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_updates_rollback';
		$field_name        = 'field_7'; // enable_updates_rollback_core

		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}
}
