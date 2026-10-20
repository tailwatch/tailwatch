<?php
/**
 * Tailwatch - Network-wide action gate
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which dispatched actions a subsite administrator may not run.
 *
 * On multisite, `manage_options` is a PER-SITE capability that every subsite administrator
 * holds. The AJAX router gates on it, so without this gate every one of those administrators
 * could dispatch every action — including the ones that do not act on their site at all:
 *
 * - the backup writes and the restore rewrites ONE shared file tree and ONE database that
 *   holds every site's tables;
 * - the database optimizer runs over that whole database;
 * - reset-all and settings-import rewrite the plugin's configuration for the network;
 * - search-replace edits tables belonging to sites the caller has no rights over.
 *
 * A subsite administrator running any of these acts on every other site in the network, up to
 * dropping network-wide tables.
 *
 * The list is an ALLOWLIST of the network-level actions rather than a blanket elevation,
 * because most actions here are legitimately per-site and elevating them all would break
 * ordinary use on a network. Read-only status polls stay open for the same reason: a subsite
 * administrator seeing that a backup is running is not a privilege problem, and blocking it
 * would leave their UI spinning forever.
 */
class NetworkActionGate {

	/**
	 * Actions that act on the whole network, not on the caller's site.
	 *
	 * Deliberately only the ones that WRITE or DESTROY. `..._status`, `..._logs` and the
	 * `get_...` reads are absent on purpose — see the class note.
	 *
	 * @var array<string>
	 */
	const NETWORK_ACTIONS = array(
		// Backup: one shared tree, one shared database.
		'tailwatch_instant_backup_scanner',
		'tailwatch_start_backup_with_optimize_or_not',
		'tailwatch_pause_backup_creation',
		'tailwatch_resume_backup',
		'tailwatch_execute_cron_if_failed',
		'tailwatch_delete_backup_folder',

		// Restore and migration replace the whole install. The migration action lives in
		// the pro plugin and reaches the router through a filter, so it is listed here
		// but is not routable by free alone.
		'tailwatch_start_restore',
		// Listing the other site's backups spends its one-time key and leaves a pairing
		// this install can start a migration with, so it is the first half of the same
		// install-wide act rather than a read.
		'tailwatch_list_source_backups',
		'tailwatch_start_migration',
		'tailwatch_retry_migration',
		// Resuming a stalled restore continues that same install-wide overwrite, so it
		// belongs on the same side of the line as starting one. It is a write despite
		// reading like a status call.
		'tailwatch_resume_restore_if_stalled',
		// One backup holds every site in the network's data, so minting a link to any part
		// of it is not a per-site decision. It reads rather than writes, which is why it is
		// the only read on this list.
		'tailwatch_issue_backup_download',

		// Manual migration writes into the shared backup tree and ends by publishing a
		// restorable set for the whole install.
		'tailwatch_begin_manual_import',
		'tailwatch_upload_migration_chunk',
		'tailwatch_cancel_manual_import',

		// The optimizer runs across every site's tables.
		'tailwatch_database_optimize',
		'tailwatch_pause_db_optimize',
		'tailwatch_resume_db_optimize',
		'tailwatch_db_optimization_cron_if_failed',

		// Search-replace edits tables the caller may own none of.
		'tailwatch_start_search_replace',
		'tailwatch_resume_search_replace',
		'tailwatch_cancel_pause_search_replace',
		'tailwatch_search_replace_cron_if_failed',

		// Plugin-wide configuration, and the PHP settings written into a shared file.
		'tailwatch_start_reset_all_settings',
		'tailwatch_reset_feature_by_option',
		'tailwatch_activate_features_bulk',
		'tailwatch_save_php_settings',
		'tailwatch_remove_php_settings',

		// Integrity baselines describe the shared file tree.
		'tailwatch_instant_files_integrity_check',
		'tailwatch_cancel_pause_integrity',
		'tailwatch_resume_files_integrity',
		'tailwatch_files_integrity_cron_if_failed',
	);

	/**
	 * Whether the current user may dispatch this action.
	 *
	 * Always true on a single site, where `manage_options` already means what the router
	 * assumed it meant.
	 *
	 * @param string $action_type Dispatched action.
	 * @return bool
	 */
	public static function allows( $action_type ) {
		if ( ! is_multisite() ) {
			return true;
		}

		if ( ! is_string( $action_type ) || ! in_array( $action_type, self::network_actions(), true ) ) {
			return true;
		}

		return current_user_can( 'manage_network_options' );
	}

	/**
	 * The action list, filterable so a network owner can extend it.
	 *
	 * Extend only — an entry removed here re-opens a network-level action to every subsite
	 * administrator, which is the situation this exists to end.
	 *
	 * @return array<string>
	 */
	public static function network_actions() {
		/**
		 * Filter the actions that require network-administrator rights on multisite.
		 *
		 * @param array<string> $actions Action types.
		 */
		$actions = apply_filters( 'tailwatch_network_only_actions', self::NETWORK_ACTIONS );

		return is_array( $actions ) ? array_map( 'strval', $actions ) : self::NETWORK_ACTIONS;
	}
}
