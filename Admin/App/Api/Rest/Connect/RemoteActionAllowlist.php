<?php
namespace Tailwatch\Admin\App\Api\Rest\Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-purpose action gate for the machine plane (the /dispatch REST route).
 *
 * Every JWT carries a `purpose` claim: 'manage' (mobile app / connected
 * dashboard, the default) or 'provision' (minted only by the provisioning
 * bootstrap for dashboard-driven onboarding). This gate runs inside the
 * /dispatch permission_callback, BEFORE the shared ActionDispatcher, and
 * governs ONLY the machine plane - the wp-admin AJAX plane (nonce +
 * manage_options) is untouched.
 *
 * Semantics:
 * - purpose 'provision': STRICT allowlist - only the onboarding subset below.
 *   Deny-by-default: a bootstrap credential can never manage the site.
 * - purpose 'manage': everything the dispatcher (or the premium routes filter)
 *   exposes EXCEPT the denied set below, so the mobile app and connected
 *   dashboard keep full management functionality, including premium actions
 *   resolved through the tailwatch_handle_premium_ajax_routes filter.
 * - any other purpose: nothing (fail closed).
 */
class RemoteActionAllowlist {

	const PURPOSE_MANAGE    = 'manage';
	const PURPOSE_PROVISION = 'provision';

	/**
	 * Never machine-reachable, on ANY purpose (browser / App-Password planes only):
	 * - get_generated_cta_keys: credential minting - a held token must not be able
	 *   to rotate the trust anchor it authenticates with.
	 * - delete_plugin_activation_data: disconnect / trust-anchor teardown, kept
	 *   browser-only.
	 * - update_plugin_activation: writes the license binding. On the machine plane
	 *   its only use is REBINDING an already-connected site to another dashboard
	 *   account (sidestepping the 409 re-pair guard) - first connect cannot use it
	 *   (no valid JWT exists before the binding), plan changes sync through
	 *   verify_license, and the legitimate writers are the wp-admin AJAX plane and
	 *   the provisioning bootstrap.
	 */
	const DENIED_ACTIONS = array(
		'tailwatch_get_generated_cta_keys',
		'tailwatch_delete_plugin_activation_data',
		'tailwatch_update_plugin_activation',
	);

	/**
	 * The complete action set a 'provision' token may dispatch: the onboarding
	 * wizard steps (the full set the Visit flow fires),
	 * plus reading activation state and running the license verification that
	 * confirms the connection end-to-end. Nothing here mints credentials, mints
	 * logins, writes files, or manages content.
	 */
	const PROVISION_ACTIONS = array(
		'tailwatch_verify_visit_progress',
		'tailwatch_check_php_version',
		'tailwatch_check_wordpress_version',
		'check_tailwatch_table',
		'tailwatch_check_cron_status',
		'tailwatch_verify_initialize_completed',
		'tailwatch_mark_setup_started',
		'tailwatch_get_feature',
		'tailwatch_update_feature_status',
		'tailwatch_start_features_implementation',
		'tailwatch_recommended_features_process',
		'tailwatch_run_feature_implement_cron_if_failed',
		'tailwatch_cron_healer',
		'tailwatch_start_security_features_process',
		'tailwatch_get_plugin_activation',
		'tailwatch_verify_license',
	);

	/**
	 * Actions that overwrite the site's own files or database.
	 *
	 * Recorded separately from DENIED_ACTIONS because these ARE legitimate on the
	 * manage plane — remotely restoring a site is a product feature — but they must
	 * never be reachable with a provisioning token. A provisioning token is minted
	 * during onboarding on the strength of an Application Password, before any
	 * dashboard has proven it holds the site's long-lived credentials; letting that
	 * bootstrap credential overwrite the site would make an interrupted onboarding a
	 * destructive event.
	 *
	 * The list is asserted against PROVISION_ACTIONS in the test suite, so adding a
	 * destructive action to both fails loudly rather than silently widening the
	 * provisioning plane.
	 */
	const DESTRUCTIVE_ACTIONS = array(
		'tailwatch_start_restore',
		// Publishes a restorable set built from files the caller supplied.
		'tailwatch_begin_manual_import',
		'tailwatch_upload_migration_chunk',
		// A migration ends by replacing this site with another one's data, so it belongs
		// in the same tier — and must never be reachable from a provisioning token. A
		// retry is the same act on a pairing that is already made.
		'tailwatch_start_migration',
		'tailwatch_retry_migration',
	);

	/**
	 * Whether a machine-plane token with the given purpose may dispatch an action.
	 *
	 * @param string $action  The requested action_type.
	 * @param string $purpose The token's purpose claim ('manage'/'provision').
	 * @return bool
	 */
	public static function is_allowed( $action, $purpose ) {
		if ( ! is_string( $action ) || '' === $action ) {
			return false;
		}

		if ( self::PURPOSE_PROVISION === $purpose ) {
			// Belt and braces: a destructive action is refused here even if one is ever
			// added to PROVISION_ACTIONS by mistake.
			if ( in_array( $action, self::DESTRUCTIVE_ACTIONS, true ) ) {
				return false;
			}
			return in_array( $action, self::PROVISION_ACTIONS, true );
		}

		if ( self::PURPOSE_MANAGE === $purpose ) {
			return ! in_array( $action, self::DENIED_ACTIONS, true );
		}

		return false;
	}
}
