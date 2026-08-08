<?php
/**
 * Notification Actions Configuration
 *
 * Defines which actions should trigger push notifications.
 * Supports both static (boolean) and dynamic (controller method) configurations.
 *
 * @package    Tailwatch
 * @subpackage Logging/Config
 */

namespace Tailwatch\Admin\App\Api\Logging\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationActions
 *
 * Manages notification rules for different actions.
 *
 */
class NotificationActions {


	/**
	 * Check if WordPress error logging is enabled.
	 *
	 * WordPress recommends checking WP_DEBUG_LOG before using error_log().
	 *
	 * @return bool True if WP_DEBUG_LOG is enabled, false otherwise.
	 */
	private static function is_wp_error_log_enabled() {
		return defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Class name mapping for dynamic handlers.
	 *
	 * Maps short handler names to full class names.
	 *
	 * @var array<string, string>
	 */
	private static $class_map = array(
		'SslVerificationController'     => 'Tailwatch\Admin\App\Api\Controllers\Ssl\SslVerificationController',
		'BackupController'              => 'Tailwatch\Admin\App\Api\Controllers\Backup\BackupController',
		'SearchReplaceController'       => 'Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController',
		'FilesIntegrityCheckController' => 'Tailwatch\Admin\App\Api\Controllers\FilesIntegrity\FilesIntegrityCheckController',
		'IntegrityWatchController'      => 'Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController',
		'DatabaseOptimizerController'   => 'Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController',
		'EmailLogController'            => 'Tailwatch\Admin\App\Api\Controllers\Email\EmailLogController',
		'BrokenLinkChecker'             => 'Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkChecker',
		'HardeningAuditController'      => 'Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController',
		'IpProtectionController'        => 'Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections\IpProtectionController',
	);

	/**
	 * Action configuration.
	 *
	 * Structure: 'action' => ['type' => 'static|dynamic', 'value' => bool, 'handler' => class, 'method' => method]
	 *
	 * @var array<string, array>
	 */
	private static $action_config = array(
		// Feature management.
		'feature_status_update'                        => array(
			'type'  => 'static',
			'value' => true,
		),
		'feature_inner_value_update'                   => array(
			'type'  => 'static',
			'value' => true,
		),
		'feature_inner_value_delete'                   => array(
			'type'  => 'static',
			'value' => true,
		),
		'feature_inner_value_duplicate'                => array(
			'type'  => 'static',
			'value' => true,
		),
		'feature_retrieval_failed'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'feature_status_update_failed'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'feature_inner_value_update_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),

		// Push notifications.
		'push_notification_update'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'push_notification_update_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),

		// Visit features.
		'visit_recommended_feature_update'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'visit_recommended_feature_update_failed'      => array(
			'type'  => 'static',
			'value' => false,
		),

		// Cron jobs.
		'cron_job_run_failed'                          => array(
			'type'  => 'static',
			'value' => true,
		),

		// Recommended features.
		'recommended_feature_implementation_started'   => array(
			'type'  => 'static',
			'value' => false,
		),
		'recommended_feature_implementation_completed' => array(
			'type'  => 'static',
			'value' => false,
		),
		'recommended_feature_implementation_failed'    => array(
			'type'  => 'static',
			'value' => true,
		),

		// Feature reset.
		'feature_reset_completed'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'feature_reset_failed'                         => array(
			'type'  => 'static',
			'value' => true,
		),

		// Media operations.
		'media_upload_completed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'media_delete_completed'                       => array(
			'type'  => 'static',
			'value' => false,
		),

		// Plugin activation/license.
		'plugin_activation_update_completed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_activation_update_failed'              => array(
			'type'  => 'static',
			'value' => true,
		),
		'plugin_activation_delete_completed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_activation_delete_failed'              => array(
			'type'  => 'static',
			'value' => true,
		),

		// Push notification enable/disable.
		'push_notification_enable_disable'             => array(
			'type'  => 'static',
			'value' => false,
		),

		// Bulk plugin actions (for bulk operations).
		'plugin_bulk_action_failed'                    => array(
			'type'  => 'static',
			'value' => true,
		),

		// Plugin/Theme additional operations.
		'plugin_reactivated'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_reactivated_network'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_compatibility_check_completed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_compatibility_check_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'theme_compatibility_check_completed'          => array(
			'type'  => 'static',
			'value' => false,
		),
		'theme_compatibility_check_failed'             => array(
			'type'  => 'static',
			'value' => false,
		),

		// Log deletion operations.
		'log_delete_completed'                         => array(
			'type'  => 'static',
			'value' => false,
		),
		'log_delete_failed'                            => array(
			'type'  => 'static',
			'value' => true,
		),

		// Backup operations.
		'backup_complete'                              => array(
			'type'    => 'dynamic',
			'handler' => 'BackupController',
			'method'  => 'backup_push_notification',
		),
		'backup_started'                               => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_start_failed'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_process_failed'                        => array(
			'type'    => 'dynamic',
			'handler' => 'BackupController',
			'method'  => 'backup_push_notification',
		),
		'backup_invalid_type'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_if_cron_failed'                        => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_if_cron_failed_on_attempt'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_start_backup_with_optimize_or_not'     => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_start_backup_with_optimize_or_not_failed' => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_verify_backup_status_failed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_resume'                                => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_resume_failed'                         => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_pause'                                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_cancel'                                => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_pause_cancel_failed'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_get_live_logs_failed'                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_folders_info_failed'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_folders_verification_failed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_folders_empty'                         => array(
			'type'  => 'static',
			'value' => false,
		),

		// Backup database operations.
		'backup_db_scan_failed'                        => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_db_backup_failed'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'db_schema_write_failed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'db_schema_fetch_failed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'db_file_write_failed'                         => array(
			'type'  => 'static',
			'value' => false,
		),

		// Backup download operations.
		'backup_download_process_start'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_download_process_start_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_download_completed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_download_process_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_download_status_check_failed'          => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_download_cron_if_failed_on_attempt'    => array(
			'type'  => 'static',
			'value' => false,
		),

		// Backup maintenance operations.
		'backup_folder_deleted'                        => array(
			'type'    => 'dynamic',
			'handler' => 'BackupController',
			'method'  => 'backup_push_notification',
		),
		'backup_folder_delete_failed'                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'backup_status_retrieval_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),

		// Database optimizer operations.
		'db_optimize_complete'                         => array(
			'type'    => 'dynamic',
			'handler' => 'DatabaseOptimizerController',
			'method'  => 'database_optimizer_push_notification',
		),
		'database_optimizer_started'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_start_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_process_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_if_cron_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_cron_if_failed_on_attempt' => array(
			'type'  => 'static',
			'value' => false,
		),
		'db_optimize_cron_exception'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_live_logs_failed'          => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_cancel'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_pause'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_cancel_pause_failed'       => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_resume'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_resume_failed'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_remove_entries'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_remove_entries_failed'     => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_verify_status_failed'      => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_optimize_status_failed'    => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_get_tables_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'database_optimizer_get_tables_status_failed'  => array(
			'type'  => 'static',
			'value' => false,
		),

		// Hardening Audit operations.
		'hardening_audit_completed'                    => array(
			'type'    => 'dynamic',
			'handler' => 'HardeningAuditController',
			'method'  => 'hardening_audit_push_notification',
		),

		// Integrity Watch operations.
		'files_integrity_completed'                    => array(
			'type'    => 'dynamic',
			'handler' => 'IntegrityWatchController',
			'method'  => 'files_integrity_push_notification',
		),
		'files_integrity_started'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_start_failed'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_complete_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_status_verify_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_live_logs_failed'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_resumed'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_resume_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_cancelled'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_paused'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_cancel_pause_failed'          => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_deleted'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_delete_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_cron_if_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_cron_if_failed_on_attempt'    => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_entries_retrieval_failed'     => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_entry_details_by_id_failed'   => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_status_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),

		// Integrity Watch - Baseline operations (pro plugin).
		'files_integrity_baseline_updated'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_baseline_no_update'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_baseline_update_failed'       => array(
			'type'  => 'static',
			'value' => false,
		),
		'files_integrity_comparison_update_failed'     => array(
			'type'  => 'static',
			'value' => false,
		),
		'baseline_updated_for_cleaned_files'           => array(
			'type'  => 'static',
			'value' => false,
		),

		// Search & Replace operations.
		'search_replace_completed'                     => array(
			'type'    => 'dynamic',
			'handler' => 'SearchReplaceController',
			'method'  => 'search_replace_push_notification',
		),
		'search_replace_started'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_start_failed'                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_process_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_if_cron_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_cron_if_failed_on_attempt'     => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_live_logs_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_resume'                        => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_resume_failed'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_cancel'                        => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_pause'                         => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_cancel_pause_failed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_verify_status_failed'          => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_remove_entries'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'search_replace_remove_entries_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'recursive_unserialize_replace'                => array(
			'type'  => 'static',
			'value' => false,
		),

		// Content Replace operations.
		'create_replacement'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'create_replacement_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'update_replacement'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'update_replacement_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'delete_replacement'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'delete_replacement_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'delete_replacement_partial'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'delete_all_replacements'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'delete_all_replacements_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'toggle_replacement'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'toggle_replacement_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'preview_replacement_failed'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'clear_cache'                                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'clear_cache_failed'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'bulk_action_failed'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'bulk_action_partial'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'replacement_engine_timeout'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'replacement_create_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),

		// SSL verification operations.
		'ssl_verification_completed'                   => array(
			'type'    => 'dynamic',
			'handler' => 'SslVerificationController',
			'method'  => 'tailwatch_ssl_push_notification_status',
		),
		'ssl_verification_failed'                      => array(
			'type'    => 'dynamic',
			'handler' => 'SslVerificationController',
			'method'  => 'tailwatch_ssl_push_notification_status',
		),
		'ssl_certificate_expired'                      => array(
			'type'    => 'dynamic',
			'handler' => 'SslVerificationController',
			'method'  => 'tailwatch_ssl_push_notification_status',
		),
		'ssl_certificate_expiring'                     => array(
			'type'    => 'dynamic',
			'handler' => 'SslVerificationController',
			'method'  => 'tailwatch_ssl_push_notification_status',
		),
		'ssl_verification_warning'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'ssl_feature_check_failed'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'ssl_status_insert_failed'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'ssl_expiry_alert_failed'                      => array(
			'type'  => 'static',
			'value' => false,
		),

		// Broken Links operations.
		'broken_link_check_completed'                  => array(
			'type'    => 'dynamic',
			'handler' => 'BrokenLinkChecker',
			'method'  => 'broken_link_push_notification',
		),
		'broken_link_check_started'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_start_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_complete_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_resumed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_resume_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_paused'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_cancelled'                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_cancel_pause_failed'        => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_cron_if_failed_on_attempt'  => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_live_logs_failed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'broken_link_check_status_verify_failed'       => array(
			'type'  => 'static',
			'value' => false,
		),

		// Email logging operations.
		'email_send_success'                           => array(
			'type'    => 'dynamic',
			'handler' => 'EmailLogController',
			'method'  => 'should_notify_email_success',
		),
		'smtp_authentication_failed'                   => array(
			'type'    => 'dynamic',
			'handler' => 'EmailLogController',
			'method'  => 'should_notify_smtp_failed',
		),
		'email_send_failed'                            => array(
			'type'    => 'dynamic',
			'handler' => 'EmailLogController',
			'method'  => 'should_notify_email_failed',
		),
		'email_error_log_failed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'email_activity_log_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'email_error_log_disabled'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'email_activity_log_disabled'                  => array(
			'type'  => 'static',
			'value' => false,
		),

		// Authentication operations.
		'auth_login_rate_limited'                      => array(
			'type'  => 'static',
			'value' => true,
		),
		'auth_login_failed'                            => array(
			'type'  => 'static',
			'value' => true,
		),
		'auth_login_completed'                         => array(
			'type'  => 'static',
			'value' => false,
		),
		'auth_login_error'                             => array(
			'type'  => 'static',
			'value' => true,
		),
		'auth_refresh_failed'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'auth_refresh_completed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'auth_refresh_error'                           => array(
			'type'  => 'static',
			'value' => true,
		),

		// Verification — license/plugin activation verification.
		'plugin_activation_update_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_activation_delete_completed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'plugin_activation_delete_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'post_disconnect_reverify_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),

		// reCAPTCHA — verification operations (no mobile notification support).
		'recaptcha_v2_completed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'recaptcha_v2_failed'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'recaptcha_v3_completed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'recaptcha_v3_failed'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'recaptcha_image_completed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'recaptcha_image_failed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'recaptcha_image_session_error'                => array(
			'type'  => 'static',
			'value' => false,
		),

		// Redirections — rule management and log operations (no mobile notification support).
		'redirection_create_rule'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_create_rule_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_update_rule'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_update_rule_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_rule_retrieval_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_rule_processing_failed'           => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_log_count_failed'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'redirection_log_encode_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'post_types_retrieval_failed'                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'posts_by_post_type_retrieval_failed'          => array(
			'type'  => 'static',
			'value' => false,
		),

		// Settings — export/import/reset operations (admin-only, no push support).
		'settings_export_successful'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_export_failed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_export_retrieve_features_failed'     => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_preview_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_process_started'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_process_start_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_process_completed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_process_batch_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_batch_scheduled'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_cron_if_failed'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_cron_retry_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_feature_processed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_feature_deactivated'          => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_insert'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_update'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_row'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_license_cached'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_mobile_notification_skipped'  => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_notifications_logs_restored'  => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_status_failed'                => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_import_reset_status_verify_failed'   => array(
			'type'  => 'static',
			'value' => false,
		),
		'feature_reset_completed'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'feature_reset_failed'                         => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_all_reset_started'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_all_reset_completed'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_start_reset_all_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_all_reset_batch_process_failed'      => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_all_reset_cron_if_failed'            => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_all_reset_cron_retry_failed'         => array(
			'type'  => 'static',
			'value' => false,
		),
		'settings_all_reset_status_verify_failed'      => array(
			'type'  => 'static',
			'value' => false,
		),

		// User Management — user CRUD and role operations (admin-only, no push support).
		'user_created'                                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'create_user_failed'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_updated'                                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'update_user_failed'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_deleted'                                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'delete_user_failed'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'bulk_delete_users_failed'                     => array(
			'type'  => 'static',
			'value' => false,
		),
		'get_user_by_id_failed'                        => array(
			'type'  => 'static',
			'value' => false,
		),
		'get_user_content_summary_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_status_updated'                          => array(
			'type'  => 'static',
			'value' => false,
		),
		'update_user_status_failed'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_status_retrieval_failed'                 => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_role_changed'                            => array(
			'type'  => 'static',
			'value' => false,
		),
		'change_user_role_failed'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'get_roles_failed'                             => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_roles_retrieval_failed'                  => array(
			'type'  => 'static',
			'value' => false,
		),
		'user_roles_data_retrieval_failed'             => array(
			'type'  => 'static',
			'value' => false,
		),
		'new_user_roles_detection_failed'              => array(
			'type'  => 'static',
			'value' => false,
		),

		// User Hardening — username/display name/password operations.
		'username_changed'                             => array(
			'type'  => 'static',
			'value' => false,
		),
		'change_username_failed'                       => array(
			'type'  => 'static',
			'value' => false,
		),
		'change_username_exception'                    => array(
			'type'  => 'static',
			'value' => false,
		),
		'display_name_fixed'                           => array(
			'type'  => 'static',
			'value' => false,
		),
		'display_name_fixed_bulk'                      => array(
			'type'  => 'static',
			'value' => false,
		),
		'fix_display_name_exception'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'password_reset_forced'                        => array(
			'type'  => 'static',
			'value' => false,
		),
		'bulk_password_reset_forced'                   => array(
			'type'  => 'static',
			'value' => false,
		),
		'force_password_reset_exception'               => array(
			'type'  => 'static',
			'value' => false,
		),
		'ajax_strength_check_error'                    => array(
			'type'  => 'static',
			'value' => false,
		),

		'authentication_blocked_ip'                    => array(
			'type'    => 'dynamic',
			'handler' => 'IpProtectionController',
			'method'  => 'login_defender_push_notification',
		),
	);

	/**
	 * Check if an action should trigger a notification.
	 *
	 * @param string $action Action identifier.
	 *
	 * @return bool True if notification should be sent, false otherwise.
	 */
	public static function should_notify( $action ) {
		if ( empty( $action ) ) {
			return false;
		}

		$action = sanitize_text_field( $action );

		// Allow pro plugin to register additional actions.
		$pro_result = apply_filters( 'tailwatch_notification_should_notify', null, $action );
		if ( null !== $pro_result ) {
			return (bool) $pro_result;
		}

		// Check if action exists in config.
		if ( ! isset( self::$action_config[ $action ] ) ) {
			// Default: don't notify for unknown actions.
			return false;
		}

		$config = self::$action_config[ $action ];

		// Handle static configuration.
		if ( isset( $config['type'] ) && 'static' === $config['type'] ) {
			return isset( $config['value'] ) && true === $config['value'];
		}

		// Handle dynamic configuration (calls controller method).
		if ( isset( $config['type'] ) && 'dynamic' === $config['type'] ) {
			return self::check_dynamic_notification( $config );
		}

		return false;
	}

	/**
	 * Check dynamic notification configuration.
	 *
	 * Calls controller method to determine if notification should be sent.
	 *
	 * @param array $config Configuration array with handler and method.
	 *
	 * @return bool True if notification should be sent, false otherwise.
	 */
	private static function check_dynamic_notification( array $config ) {
		if ( ! isset( $config['handler'] ) || ! isset( $config['method'] ) ) {
			return false;
		}

		$handler_class = sanitize_text_field( $config['handler'] );
		$method_name   = sanitize_text_field( $config['method'] );

		// Get full class name from mapping or build it.
		$full_class_name = isset( self::$class_map[ $handler_class ] )
			? self::$class_map[ $handler_class ]
			: 'Tailwatch\Admin\App\Api\Controllers\\' . $handler_class;

		// Check if class exists.
		if ( ! class_exists( $full_class_name ) ) {
			return false;
		}

		// Check if method exists.
		if ( ! method_exists( $full_class_name, $method_name ) ) {
			return false;
		}

		try {
			// Use reflection to check if method is static.
			$reflection = new \ReflectionMethod( $full_class_name, $method_name );

			if ( $reflection->isStatic() ) {
				$result = call_user_func( array( $full_class_name, $method_name ) );
			} else {
				// Check if constructor has required parameters.
				$class_reflection = new \ReflectionClass( $full_class_name );
				$constructor      = $class_reflection->getConstructor();

				if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
					// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
					if ( self::is_wp_error_log_enabled() ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when notification system fails
						error_log(
							sprintf(
								'Cannot instantiate %s: constructor requires parameters',
								$full_class_name
							)
						);
					}
					return false;
				}

				$controller = new $full_class_name();
				$result     = call_user_func( array( $controller, $method_name ) );
			}

			return (bool) $result;
		} catch ( \Exception $e ) {
			// If dynamic check fails, default to false.
			// Only log if WP_DEBUG_LOG is enabled (WordPress recommendation).
			if ( self::is_wp_error_log_enabled() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Critical fallback logging when notification system fails
				error_log( 'Dynamic notification check failed: ' . $e->getMessage() );
			}
			return false;
		}
	}

	/**
	 * Get all configured actions.
	 *
	 * @return array All action configurations.
	 */
	public static function get_all_actions() {
		return self::$action_config;
	}

	/**
	 * Get all action names (keys only).
	 *
	 * @return array Array of action names.
	 */
	public static function get_all_action_names() {
		return array_keys( self::$action_config );
	}

	/**
	 * Add or update action configuration.
	 *
	 * @param string $action Action identifier.
	 * @param array  $config Configuration array.
	 *
	 * @return void
	 */
	public static function set_action_config( $action, array $config ) {
		$action                         = sanitize_text_field( $action );
		self::$action_config[ $action ] = $config;
	}

	/**
	 * Register class mapping for dynamic handlers.
	 *
	 * @param string $short_name Short class name.
	 * @param string $full_class_name Full class name with namespace.
	 *
	 * @return void
	 */
	public static function register_class_mapping( $short_name, $full_class_name ) {
		$short_name                     = sanitize_text_field( $short_name );
		self::$class_map[ $short_name ] = $full_class_name;
	}

	/**
	 * Check if action is registered.
	 *
	 * @param string $action Action identifier.
	 *
	 * @return bool True if registered, false otherwise.
	 */
	public static function is_action_registered( $action ) {
		return isset( self::$action_config[ $action ] );
	}
}
