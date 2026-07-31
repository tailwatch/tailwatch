<?php
/**
 * Feature Notification Messages Controller
 *
 * Owns the user-facing message strings shown in notifications when a feature
 * is toggled or its inner settings change. Kept separate from FeaturesController
 * so the orchestration logic stays focused on state management while this class
 * holds the (much larger) body of notification text.
 *
 * Two public entry points:
 *   - get_impact_text()      — nature-based body sentence used in the push
 *                              notification body. Generic per feature nature
 *                              (scan / blocker / hardener / monitor / config /
 *                              maintenance), not per individual feature.
 *   - get_consequence_text() — per-feature persuasive sentence used in the
 *                              meta_data['impact'] field that the mobile app
 *                              renders as the card body. Covers the toggle
 *                              scenarios; falls back to get_impact_text() for
 *                              non-toggle scenarios or unmapped features.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Features
 */

namespace Tailwatch\Admin\App\Api\Controllers\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FeatureNotificationMessagesController
 *
 * Static helper that returns the body / impact strings keyed by
 * feature_option + scenario. No instance state — the maps are baked into
 * the methods via `static` arrays.
 *
 */
class FeatureNotificationMessagesController {

	/**
	 * Build the nature-based impact sentence used in the push notification body.
	 *
	 * The sentence varies by feature nature so the body describes the actual
	 * effect of the change. A generic "scheduled checks" line would be wrong
	 * for blockers (Content Restrictions, Geo-blocking), hardeners (Security
	 * Headers, Hide WP), monitors (Activity Logs), or config tools
	 * (Search & Replace).
	 *
	 * Nature buckets (see OptionsModel for the full feature list):
	 *   scan        — runs scheduled checks/scans (Smart SSL, Backup, Malware, etc.)
	 *   blocker     — runtime gate intercepting requests (2FA, Firewall, etc.)
	 *   hardener    — one-time config applied site-wide (Security Headers, etc.)
	 *   monitor     — passive logging (Activity Logs, Error Logs, etc.)
	 *   config      — dashboard UI tool (Search & Replace, Cron Jobs, etc.)
	 *   maintenance — Maintenance Mode (special case)
	 *
	 * @param string $feature_option Feature option key (e.g. 'default_verify_ssl').
	 * @param string $scenario       One of 'enabled', 'disabled', 'settings_updated',
	 *                               'entries_duplicated', 'entries_removed'.
	 *
	 * @return string Single-sentence impact text.
	 */
	public static function get_impact_text( $feature_option, $scenario ) {
		static $nature_map = array(
			// Scan: scheduled checks/scans.
			'default_verify_ssl'             => 'scan',
			'default_backup_enable'          => 'scan',
			'default_malware_scan'           => 'scan',
			'default_file_integrity_check'   => 'scan',
			'default_hardening_audit'        => 'scan',
			'broken_link_checker_settings'   => 'scan',
			// Blocker: runtime gates intercepting requests.
			'default_disable_keys'           => 'blocker',
			'default_two_step_authenticate'  => 'blocker',
			// Hardener: one-time config applied site-wide.
			'default_files_and_permission'   => 'hardener',
			// Monitor: passive logging.
			'default_log_activity'           => 'monitor',
			'default_monitoring_logs'        => 'monitor',
			'default_email_configure'        => 'monitor',
			// Config: dashboard tools / utilities.
			'default_database_optimizer'     => 'config',
			'default_search_replace'         => 'config',
			'advanced_user_access_control'   => 'config',
			'default_redirection_manager'    => 'config',
			'default_cron_job_list'          => 'config',
			// Special: Maintenance Mode.
		);

		$nature = isset( $nature_map[ $feature_option ] ) ? $nature_map[ $feature_option ] : 'generic';

		$templates = array(
			'enabled'            => array(
				'scan'        => 'Tailwatch will now run scheduled checks for this feature on your site.',
				'blocker'     => 'Tailwatch will now enforce this protection on incoming requests to your site.',
				'hardener'    => 'The hardening is now applied to your site and will stay in effect until you turn it off.',
				'monitor'     => 'Tailwatch will now record all the errors on your site. You can review the logs at any time.',
				'config'      => 'This tool is now available in your dashboard.',
				'maintenance' => 'Your site is now in maintenance mode and visitors will see the holding page.',
				'generic'     => 'This feature is now active on your site.',
			),
			'disabled'           => array(
				'scan'        => "Tailwatch will no longer run these checks. You won't get alerts from this feature.",
				'blocker'     => 'Tailwatch will no longer enforce this protection on incoming requests.',
				'hardener'    => 'The hardening has been removed from your site.',
				'monitor'     => "Tailwatch will no longer record these events. New events won't be logged until you turn it back on.",
				'config'      => 'This tool is no longer available in your dashboard.',
				'maintenance' => 'Your site is no longer in maintenance mode and is publicly accessible again.',
				'generic'     => 'This feature is no longer active on your site.',
			),
			'settings_updated'   => array(
				'scan'        => 'The new settings will be used the next time Tailwatch runs this check.',
				'blocker'     => 'The new rules apply to incoming requests from now on.',
				'hardener'    => 'The change takes effect on your site immediately.',
				'monitor'     => 'The new logging settings apply to events from now on.',
				'config'      => 'Your changes are saved and will be used the next time you open this tool.',
				'maintenance' => 'The maintenance page now reflects your changes.',
				'generic'     => 'Your settings have been saved.',
			),
			'entries_duplicated' => array(
				'scan'        => 'The new entries will be included from the next scheduled check.',
				'blocker'     => 'The new rules are now active on incoming requests.',
				'hardener'    => 'The new entries are now applied to your site.',
				'monitor'     => 'The new entries will be applied to logging from now on.',
				'config'      => 'The duplicated entries are saved in your configuration.',
				'maintenance' => 'The maintenance page now includes the new entries.',
				'generic'     => 'Your duplicated entries are saved.',
			),
			'entries_removed'    => array(
				'scan'        => 'The removed entries will not be checked from now on.',
				'blocker'     => 'The removed rules no longer apply to incoming requests.',
				'hardener'    => 'The removed entries are no longer enforced on your site.',
				'monitor'     => 'The removed entries are no longer being logged.',
				'config'      => 'The removed entries are gone from your configuration.',
				'maintenance' => 'The maintenance page no longer includes the removed entries.',
				'generic'     => 'Your removed entries are gone.',
			),
		);

		if ( ! isset( $templates[ $scenario ] ) ) {
			return '';
		}
		return isset( $templates[ $scenario ][ $nature ] )
			? $templates[ $scenario ][ $nature ]
			: $templates[ $scenario ]['generic'];
	}

	/**
	 * Build the per-feature consequence sentence for meta_data['impact'].
	 *
	 * Differs from get_impact_text() in two ways:
	 *   1. Per-feature, not nature-grouped — Backup says "if your site crashes,
	 *      you may not be able to recover", Firewall says "attack traffic will
	 *      reach WordPress directly", etc.
	 *   2. Written to attract the user's attention — names the concrete risk of
	 *      having the feature off, or the concrete benefit of having it on.
	 *
	 * Covers the 'enabled' / 'disabled' scenarios (the highest-stakes ones).
	 * For other scenarios — or for any feature not in the map — falls back to
	 * the nature-based text from get_impact_text().
	 *
	 * @param string $feature_option Feature option key (e.g. 'default_backup_enable').
	 * @param string $scenario       'enabled', 'disabled', 'settings_updated',
	 *                               'entries_duplicated', or 'entries_removed'.
	 *
	 * @return string Consequence sentence suitable for the mobile-app card body.
	 */
	public static function get_consequence_text( $feature_option, $scenario ) {
		static $consequences = array(
			'default_files_and_permission'   => array(
				'enabled'  => 'Tailwatch is enforcing safe file permissions on wp-content, uploads, wp-admin, plugins, and themes. Attackers can no longer drop or modify files in those folders.',
				'disabled' => 'File-permission protection is off. Attackers who reach a vulnerability can write to wp-content, plugins, or uploads — a common path to a full site compromise.',
			),
			'default_log_activity'           => array(
				'enabled'  => 'Every login, logout, signup, and password reset is being recorded. You\'ll have a complete audit trail if anything goes wrong.',
				'disabled' => 'User activity is no longer being logged. If someone gains access to your site, you\'ll have no record of who did what or when.',
			),
			'default_monitoring_logs'        => array(
				'enabled'  => '4xx and 5xx errors on your site are being captured in real time. You\'ll know the moment something breaks — before your visitors complain.',
				'disabled' => 'Site errors are no longer being captured. When your site breaks, you\'ll find out from your users instead of your dashboard.',
			),
			'default_email_configure'        => array(
				'enabled'  => 'Every outgoing email is being logged and SMTP is configured for reliable delivery. You\'ll catch delivery failures before customers do.',
				'disabled' => 'Outgoing email is no longer logged. If welcome emails, password resets, or order confirmations stop reaching customers, you won\'t notice.',
			),
			'default_verify_ssl'             => array(
				'enabled'  => 'Your SSL certificate is being checked daily. You\'ll get an alert well before it expires, so visitors never see a "Not Secure" warning.',
				'disabled' => 'Your SSL certificate is no longer being monitored. If it expires unnoticed, visitors will see browser warnings and your site will look broken in search results.',
			),
			'default_backup_enable'          => array(
				'enabled'  => 'Your site is being backed up automatically. If it ever crashes, gets hacked, or you make a mistake, you can restore a working version in minutes.',
				'disabled' => 'Your site is no longer being backed up. If it crashes, gets hacked, or you make a major mistake, you may not be able to recover your content at all.',
			),
			'default_malware_scan'           => array(
				'enabled'  => 'Tailwatch is scanning your files for malware continuously. Any infected or modified file gets flagged so you can clean it before it spreads.',
				'disabled' => 'Malware scanning is off. Infected files can sit on your server unnoticed and quietly serve malware to visitors or hijack your site for SEO spam.',
			),
			'default_database_optimizer'     => array(
				'enabled'  => 'Database optimizer tools are available in your dashboard. You can change your table prefix, clean revisions, and remove orphaned data whenever you need.',
				'disabled' => 'Database optimizer tools are hidden. Your database may slowly accumulate junk rows, which can slow the site and inflate storage usage over time.',
			),
			'default_file_integrity_check'   => array(
				'enabled'  => 'Tailwatch is comparing your files against a known-good baseline. Any unexpected modification, addition, or deletion gets flagged immediately.',
				'disabled' => 'File-integrity checks are off. If an attacker modifies your theme or plugin files, you won\'t notice until something visibly breaks.',
			),
			'default_hardening_audit'        => array(
				'enabled'  => 'Tailwatch is auditing your PHP version, database, file permissions, SSL, REST, and XML-RPC on a schedule. You\'ll get a report any time the site drifts from best practice.',
				'disabled' => 'Site hardening audits are off. Configuration drift — outdated PHP, weak permissions, exposed endpoints — can sit unnoticed for weeks.',
			),
			'default_search_replace'         => array(
				'enabled'  => 'Bulk find-and-replace across your database is available. You can fix migration URLs, broken strings, or stale references in one go.',
				'disabled' => 'The search-and-replace tool is hidden. Database-level string fixes will have to be done manually with SQL.',
			),
			'advanced_user_access_control'   => array(
				'enabled'  => 'User management tools are active: passwordless login, inactivity alerts, role limits, and login attempt caps.',
				'disabled' => 'Advanced user management is off. You\'re back to WordPress defaults — no inactivity alerts, no role-based 2FA, no extra login lockout beyond the standard.',
			),
			'default_redirection_manager'    => array(
				'enabled'  => 'The 301 redirect manager is available. You can fix broken inbound links and keep your SEO juice flowing to the right pages.',
				'disabled' => 'The redirect manager is hidden. New 404s from moved or deleted pages won\'t be redirected — bad for visitors and bad for SEO.',
			),
			'broken_link_checker_settings'   => array(
				'enabled'  => 'Tailwatch is scanning your content for broken links. You\'ll get a list of pages with dead links and one-click fixes.',
				'disabled' => 'Broken-link scanning is off. Dead links in your content can stay there for months, frustrating visitors and hurting your search rankings.',
			),
			'default_disable_keys'           => array(
				'enabled'  => 'Right-click, copy/paste, and view-source are disabled for visitors. It makes it harder to scrape your content or download your images.',
				'disabled' => 'Content restrictions are off. Visitors can right-click, copy your text and images, and inspect your source code freely.',
			),
			'default_two_step_authenticate'  => array(
				'enabled'  => 'Two-factor authentication is enforced for the roles you selected. Even if a password is stolen, attackers can\'t get in without the second factor.',
				'disabled' => 'Two-factor authentication is off. Admin accounts are now protected only by their password — a single leak is enough for a full takeover.',
			),
			'default_cron_job_list'          => array(
				'enabled'  => 'The WP-Cron manager is available in your dashboard. You can pause, edit, or manually run scheduled tasks whenever you need.',
				'disabled' => 'The cron manager is hidden. If a scheduled task is stuck or misconfigured, you\'ll need WP-CLI or another plugin to fix it.',
			),
		);

		if ( isset( $consequences[ $feature_option ][ $scenario ] ) ) {
			return $consequences[ $feature_option ][ $scenario ];
		}

		// Unmapped feature OR scenario without per-feature copy
		// (settings_updated / entries_duplicated / entries_removed)
		// falls back to the nature-based generic text.
		return self::get_impact_text( $feature_option, $scenario );
	}
}