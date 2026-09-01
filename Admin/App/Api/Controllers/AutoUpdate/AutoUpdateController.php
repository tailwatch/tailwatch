<?php
/**
 * Automatic Updates Controller
 *
 * Opt-in scheduled auto-updates for plugins, themes, and WordPress core.
 *
 * This does NOT run any updater itself. It hooks WordPress's native
 * auto-update decision filters (auto_update_plugin / auto_update_theme /
 * allow_minor_auto_core_updates) so that WordPress's own WP_Automatic_Updater
 * performs the updates on its own schedule — inside maintenance mode, with
 * locking, result emails, and rollback-on-fatal for plugins. This mirrors the
 * pattern used by the established auto-update managers and is the approach the
 * WordPress documentation recommends.
 *
 * Updates only ever run when the site owner has explicitly enabled the
 * "Updates & Rollback" feature AND turned on the per-type automatic-update
 * toggle. When a toggle is off, the filters return WordPress's existing
 * decision unchanged, so nothing about the site's default behaviour changes.
 *
 * @package    Tailwatch
 * @subpackage Controllers/AutoUpdate
 */

namespace Tailwatch\Admin\App\Api\Controllers\AutoUpdate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\PluginTheme\PluginThemeController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class AutoUpdateController
 *
 * Registers WordPress's native auto-update filters based on the opt-in
 * feature settings, and reports the outcome once WordPress has run.
 */
class AutoUpdateController {

	/**
	 * Feature key / option for the Updates & Rollback feature.
	 */
	const FEATURE_KEY    = 'default_feature_settings';
	const FEATURE_OPTION = 'default_updates_rollback';

	/**
	 * Cached per-type enabled state for this request. Null until first read.
	 *
	 * @var array<string,bool>|null
	 */
	private $toggles = null;

	/**
	 * Register the native auto-update filters and the completion reporter.
	 */
	public function __construct() {
		add_filter( 'auto_update_plugin', array( $this, 'filter_auto_update_plugin' ), 10, 2 );
		add_filter( 'auto_update_theme', array( $this, 'filter_auto_update_theme' ), 10, 2 );

		// Core: minor + security releases only. Major upgrades intentionally stay
		// manual, so allow_major_auto_core_updates is left at WordPress's default.
		add_filter( 'allow_minor_auto_core_updates', array( $this, 'filter_allow_minor_core' ) );

		// Report what WordPress actually auto-updated (mobile push + activity log).
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_updates_complete' ) );
	}

	/**
	 * Resolve and cache the per-type opt-in state for this request.
	 *
	 * A type is "on" only when both the feature's per-type management toggle and
	 * its nested automatic-update toggle are selected. Reads the normalized
	 * settings once; the filters fire many times per run.
	 *
	 * @return array<string,bool> Keys: plugin, theme, core.
	 */
	private function get_toggles() {
		if ( null !== $this->toggles ) {
			return $this->toggles;
		}

		$settings = PluginThemeController::tailwatch_plugin_theme_options();

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			$this->toggles = array(
				'plugin' => false,
				'theme'  => false,
				'core'   => false,
			);
			return $this->toggles;
		}

		$this->toggles = array(
			'theme'  => ( true === ( $settings['field_1']['options']['option']['selected'] ?? false ) )
				&& ( true === ( $settings['field_1']['sub_options']['field_2']['options']['option']['selected'] ?? false ) ),
			'plugin' => ( true === ( $settings['field_4']['options']['option']['selected'] ?? false ) )
				&& ( true === ( $settings['field_4']['sub_options']['field_5']['options']['option']['selected'] ?? false ) ),
			'core'   => ( true === ( $settings['field_7']['options']['option']['selected'] ?? false ) )
				&& ( true === ( $settings['field_7']['sub_options']['field_8']['options']['option']['selected'] ?? false ) ),
		);

		return $this->toggles;
	}

	/**
	 * Enable plugin auto-updates when the feature is opted in.
	 *
	 * Returns true to let WordPress auto-update the plugin, or the incoming
	 * decision unchanged when the feature is off — so a site's existing
	 * per-plugin choices are never overridden by turning the feature off.
	 *
	 * @param bool|null $update Whether to auto-update. Null means undecided.
	 * @param object    $item   The update offer (unused; all plugins are covered).
	 *
	 * @return bool|null
	 */
	public function filter_auto_update_plugin( $update, $item ) {
		unset( $item );
		return $this->get_toggles()['plugin'] ? true : $update;
	}

	/**
	 * Enable theme auto-updates when the feature is opted in.
	 *
	 * @param bool|null $update Whether to auto-update. Null means undecided.
	 * @param object    $item   The update offer (unused; all themes are covered).
	 *
	 * @return bool|null
	 */
	public function filter_auto_update_theme( $update, $item ) {
		unset( $item );
		return $this->get_toggles()['theme'] ? true : $update;
	}

	/**
	 * Enable minor / security core auto-updates when the feature is opted in.
	 *
	 * Only returns true (never false), so it re-affirms minor core updates when
	 * asked, without forcing them off for sites that disabled them deliberately.
	 *
	 * @param bool $upgrade_minor Whether minor core auto-updates are allowed.
	 *
	 * @return bool
	 */
	public function filter_allow_minor_core( $upgrade_minor ) {
		return $this->get_toggles()['core'] ? true : $upgrade_minor;
	}

	/**
	 * Report the results of a completed WordPress auto-update run.
	 *
	 * Fired by core after WP_Automatic_Updater finishes. Logs a per-type summary
	 * (which triggers the mobile push notification) only for types the site
	 * opted into and that actually had a successful update.
	 *
	 * @param array $results Update results keyed by type (plugin/theme/core/translation).
	 *
	 * @return void
	 */
	public function on_automatic_updates_complete( $results ) {
		if ( empty( $results ) || ! is_array( $results ) ) {
			return;
		}

		$toggles = $this->get_toggles();

		$this->report_type( 'plugin', $results, $toggles, 'plugin_auto_update_completed', 'Plugins Auto-Updated' );
		$this->report_type( 'theme', $results, $toggles, 'theme_auto_update_completed', 'Themes Auto-Updated' );
		$this->report_type( 'core', $results, $toggles, 'core_auto_update_completed', 'WordPress Core Auto-Updated' );
	}

	/**
	 * Log the successful updates for one type, if opted in.
	 *
	 * @param string $type    Result type key (plugin/theme/core).
	 * @param array  $results Full results array from core.
	 * @param array  $toggles Per-type opt-in state.
	 * @param string $action  Log action slug (maps to a push handler).
	 * @param string $title   Human-readable notification title.
	 *
	 * @return void
	 */
	private function report_type( $type, $results, $toggles, $action, $title ) {
		if ( empty( $toggles[ $type ] ) || empty( $results[ $type ] ) || ! is_array( $results[ $type ] ) ) {
			return;
		}

		$names = array();
		foreach ( $results[ $type ] as $result ) {
			// A successful item has result === true (WP_Error / false means it failed).
			if ( is_object( $result ) && isset( $result->result ) && true === $result->result ) {
				$names[] = isset( $result->name ) ? wp_strip_all_tags( (string) $result->name ) : '';
			}
		}

		$names = array_values( array_filter( $names ) );
		if ( empty( $names ) ) {
			return;
		}

		$feature_label = ucfirst( $type );

		Log::info(
			sprintf(
				'Automatic %1$s updates completed: %2$s. Roll back from Update History if any update caused an issue.',
				$feature_label,
				implode( ', ', $names )
			),
			array(
				'feature'   => 'auto_update',
				'action'    => $action,
				'title'     => $title,
				'meta_data' => array(
					'feature' => 'Automatic Updates',
					'event'   => 'Completed',
					'type'    => $type,
					'items'   => $names,
				),
			)
		);
	}

	/**
	 * Push-notification gate for completed plugin auto-updates.
	 *
	 * @return bool
	 */
	public function plugin_auto_update_push_notification() {
		return $this->push_enabled_for_field( 'field_4' );
	}

	/**
	 * Push-notification gate for completed theme auto-updates.
	 *
	 * @return bool
	 */
	public function theme_auto_update_push_notification() {
		return $this->push_enabled_for_field( 'field_1' );
	}

	/**
	 * Push-notification gate for completed core auto-updates.
	 *
	 * @return bool
	 */
	public function core_auto_update_push_notification() {
		return $this->push_enabled_for_field( 'field_7' );
	}

	/**
	 * Whether mobile push is enabled for the given per-type field.
	 *
	 * @param string $field_name Feature field key (field_1/field_4/field_7).
	 *
	 * @return bool
	 */
	private function push_enabled_for_field( $field_name ) {
		$push_notification = new PushNotificationController();
		return (bool) $push_notification->tailwatch_notification_enable_for_feature(
			self::FEATURE_KEY,
			self::FEATURE_OPTION,
			$field_name
		);
	}
}
