<?php
/**
 * Mobile App Request Controller
 *
 * Centralized handler for all mobile app API requests.
 * Handles JWT-authenticated requests from the mobile application.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Routes
 */

namespace Tailwatch\Admin\App\Api\Controllers\Routes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Controllers\Features\FeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Dashboard\DashboardController;
use Tailwatch\Admin\App\Api\Controllers\Disk\DiskSpaceController;
use Tailwatch\Admin\App\Api\Controllers\Ssl\SslVerificationController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Logs\GetLogs;
use Tailwatch\Admin\App\Api\Controllers\Logs\DeleteLogs;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\WebsiteStatsController;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetByFeatureOptionController;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetAllFeatureController;
use Tailwatch\Admin\App\Api\Controllers\Settings\SettingsController;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\PluginThemeController;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\Plugin\PluginController;
use Tailwatch\Admin\App\Api\Controllers\PluginTheme\Theme\ThemeController;
use Tailwatch\Admin\App\Api\Controllers\Core\CoreController;
use Tailwatch\Admin\App\Api\Controllers\History\HistoryController;
use Tailwatch\Admin\App\Api\Controllers\Login\AutoLoginController;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupMaintainController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\GetTablesOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Controllers\Users\Management\UserRetrievalController;
use Tailwatch\Admin\App\Api\Controllers\Users\Management\UserModificationController;
use Tailwatch\Admin\App\Api\Controllers\Users\Management\UserDeletionController;
use Tailwatch\Admin\App\Api\Controllers\Users\UserRolesController;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController;
use Tailwatch\Admin\App\Api\Controllers\Features\SecurityFeaturesVerifyController;
use Tailwatch\Admin\App\Api\Controllers\Email\Smtp\SmtpTestController;
use Tailwatch\Admin\App\Api\Controllers\Redirections\RedirectionsManager;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkChecker;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkStatus;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl\GetCronJobDetailsController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl\CronJobManagerController;
use Tailwatch\Admin\App\Api\Controllers\ProcessMonitoring\ProcessMonitoringController;
use Tailwatch\Admin\App\Api\Controllers\RecoveryMode\RecoveryModeService;
use Tailwatch\Admin\App\Api\Controllers\RecoveryMode\RecoveryModeController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronHeal\CronHealer;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\Features\BulkFeatureActivationController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\BlackList\IpBlackListController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\WhiteList\IpWhiteListController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\WhiteList\CountryWhiteListController;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections\IpProtectionController;
use Tailwatch\Admin\App\Api\Controllers\Logs\FeatureCounts\IpManagementLogCount;
use Tailwatch\Admin\App\Api\Controllers\Logs\FeatureCounts\LoginDefenderLogCount;
use Tailwatch\Admin\App\Api\Controllers\Integration\IntegrationController;
use Tailwatch\Admin\App\Api\Controllers\Integration\GeoIp2\GeoLiteTwoController;
use Tailwatch\Admin\App\Api\Controllers\Media\MediaController;
use Tailwatch\Admin\App\Api\Logging\Log;

/**
 * Class MobileAppController
 *
 * Routes mobile app API requests to appropriate controllers.
 * Authentication is handled via JWT tokens at the API layer.
 *
 */
class MobileAppController {

	/**
	 * Global handler for all mobile-app-facing endpoints.
	 *
	 * Routes requests to appropriate controllers and returns JSON responses.
	 *
	 * ## Why this endpoint does not use wp_verify_nonce()
	 *
	 * The caller is the Tailwatch mobile app, a remote client with no WordPress
	 * session — there is no logged-in user cookie for wp_verify_nonce() or
	 * current_user_can() to inspect. A wp_nonce is a per-user CSRF token bound
	 * to a browser session; this is a stateless mobile-app-to-server channel
	 * where the equivalent control is a bearer credential validated
	 * cryptographically.
	 *
	 * ## Authorization
	 *
	 * Authentication is enforced upstream in Routing.php BEFORE this handler
	 * runs. Every request must carry:
	 *   1. HTTP header `X-Tailwatch-Auth-Key: <jwt>` — HS256 signature verified
	 *      against WPTW_JWT_AUTH_SECRET_KEY (constant-time hash_equals compare).
	 *   2. Non-revoked JTI (checked against the tokens table).
	 *   3. Non-expired exp claim.
	 *   4. Matching device fingerprint bound at license-pairing time.
	 *
	 * If any check fails, Routing.php returns 401 and this handler is never
	 * reached. By the time execution enters wptw_mobile_global_handler(), the
	 * caller has already been authenticated as a paired mobile device — the
	 * cryptographic equivalent of an authenticated WP session.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $data        Response data.
	 *     @type string $message     Response message.
	 *     @type int    $code        HTTP response code.
	 *     @type bool   $site_health Site health status.
	 * }
	 */
	public function wptw_mobile_global_handler() {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Mobile app API endpoint, authentication handled via JWT tokens in the API layer
			if ( ! isset( $_POST['action_type'] ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Missing required parameters', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Mobile app API endpoint, authentication handled via JWT tokens in the API layer
			$action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

			// $post_data is intentionally not blanket-sanitized here. The mobile app sends a JSON
			// string in $_POST['data']; each downstream controller json_decode()s it and then
			// applies the correct sanitizer per field (sanitize_email for email, absint for IDs,
			// sanitize_textarea_field for descriptions, esc_url_raw for URLs, etc.). A blanket
			// sanitize_text_field across the whole JSON string would strip HTML from legitimate
			// content fields BEFORE json_decode runs. This pattern mirrors AjaxRequestController
			// so both routers behave identically.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See deferred-sanitization rationale above; per-field sanitization is performed by each controller after json_decode().
			$post_data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : null;

			// Allow the pro plugin to verify plan access before executing any route.
			$access_check = apply_filters( 'wptw_verify_ajax_action', null, $action_type );
			if ( is_array( $access_check ) && false === ( $access_check['status'] ?? true ) ) {
				// Derive the suggested frontend action from the specific reason
				// code returned by the access check. Mirrors AjaxRequestController
				// — suspended licenses must show "Reactivate" not "Upgrade".
				$reason_code = $access_check['code'] ?? '';
				switch ( $reason_code ) {
					case 'license_suspended':
						$frontend_action = 'reactivate_required';
						break;
					case 'license_required':
						$frontend_action = 'connect_license';
						break;
					case 'insufficient_plan':
					default:
						$frontend_action = 'upgrade_required';
						break;
				}

				return array(
					'data'    => array(),
					'code'    => 402,
					'message' => $access_check['message'] ?? 'Access denied.',
					'role'    => $access_check['role'] ?? null,
					'reason'  => $reason_code,
					'action'  => $frontend_action,
				);
			}

			// Get controller and method based on action type.
			$route = $this->get_route_for_action( $action_type );

			if ( null === $route ) {
				// Allow the premium plugin to handle routes it owns before returning an error.
				$premium_response = apply_filters( 'wptw_handle_premium_mobile_routes', null, $action_type, $post_data, '' );

				if ( null !== $premium_response ) {
					if ( is_array( $premium_response ) && ! isset( $premium_response['site_health'] ) ) {
						$premium_response['site_health'] = true;
					}
					return $premium_response;
				}

				// Pro plugin is installed but did not claim this action — it is truly unknown.
				// If pro is NOT installed, the action may be a premium feature; return an upgrade
				// prompt instead of a misleading "invalid action" error.
				if ( ! apply_filters( 'wptw_is_premium_plugin_active', false ) ) {
					return array(
						'data'    => array(),
						'message' => __( 'This feature requires the Tailwatch Pro Plugin.', 'tailwatch' ),
						'code'    => 402,
						'action'  => 'premium_plugin_required',
					);
				}

				return array(
					'data'    => array(),
					'message' => __( 'Invalid action type', 'tailwatch' ),
					'code'    => 401,
				);
			}

			$controller = $route['controller'];
			$method     = $route['method'];

			// Ensure the method exists.
			if ( ! is_callable( array( $controller, $method ) ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid method', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Global Feature Check: If controller implements feature checking.
			if ( method_exists( $controller, 'wptw_check_feature_enabled' ) ) {
				$feature_status = $controller->wptw_check_feature_enabled( $method );

				if ( isset( $feature_status['feature_enable'] ) && false === $feature_status['feature_enable'] ) {
					return array(
						'feature_enable' => false,
						'parent_enable'  => isset( $feature_status['parent_enable'] ) ? $feature_status['parent_enable'] : false,
						'message'        => isset( $feature_status['message'] ) ? $feature_status['message'] : 'Feature is not enabled. Please enable it first.',
						'code'           => 400,
					);
				}
			}

			// Call the method and pass the data.
			$response = $controller->$method( $post_data );

			// Add site health status to response.
			$response['site_health'] = true;

			return $response;

		} catch ( \Throwable $e ) {
			Log::error(
				'Mobile route handler failure: ' . $e->getMessage(),
				array(
					'feature'   => 'mobile_api',
					'action'    => 'route_dispatch_failed',
					'detail'    => $e->getMessage(),
					'origin'    => 'system',
					'severity'  => 'high',
					'exception' => $e,
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'Internal server error occurred while processing the request.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get route (controller and method) for a given action type.
	 *
	 * Maps action types to their corresponding controllers and methods for mobile app.
	 *
	 * @param string $action_type The action type from the mobile app request.
	 *
	 * @return array|null Array with 'controller' and 'method' keys, or null if not found.
	 */
	private function get_route_for_action( $action_type ) {

		$routes = array(

			// Dashboard Routes.
			'wptw_dashboard_logs_count'              => array(
				'controller' => new DashboardController(),
				'method'     => 'wptw_dashboard_logs_count',
			),
			'wptw_dashboard_features'                => array(
				'controller' => new DashboardController(),
				'method'     => 'wptw_dashboard_features',
			),
			'wptw_scanning_feature_detail'           => array(
				'controller' => new DashboardController(),
				'method'     => 'wptw_scanning_feature_detail',
			),

			// Login Routes.
			'wptw_login_into_dashboard'              => array(
				'controller' => new AutoLoginController(),
				'method'     => 'wptw_login_into_dashboard',
			),

			// Disk & Usage.
			'wptw_disk_and_db_usage'                 => array(
				'controller' => new DiskSpaceController(),
				'method'     => 'wptw_disk_and_db_usage',
			),

			// SSL Routes.
			'wptw_return_ssl_verify_status'          => array(
				'controller' => new SslVerificationController(),
				'method'     => 'wptw_return_ssl_verify_status',
			),
			'wptw_verify_ssl_connection'             => array(
				'controller' => new SslVerificationController(),
				'method'     => 'wptw_verify_ssl_connection',
			),

			// Features Routes.
			'wptw_get_feature'                       => array(
				'controller' => new FeaturesController(),
				'method'     => 'wptw_get_feature',
			),
			'wptw_update_feature_status'             => array(
				'controller' => new FeaturesController(),
				'method'     => 'wptw_update_feature_status',
			),
			'wptw_update_inner_feature'              => array(
				'controller' => new FeaturesController(),
				'method'     => 'wptw_update_inner_feature',
			),
			'wptw_update_push_notification_value'    => array(
				'controller' => new FeaturesController(),
				'method'     => 'wptw_update_push_notification_value',
			),

			// Push Notification Routes.
			'wptw_get_push_notification'             => array(
				'controller' => new PushNotificationController(),
				'method'     => 'wptw_get_push_notification',
			),
			'wptw_enable_disable_push_notification'  => array(
				'controller' => new PushNotificationController(),
				'method'     => 'wptw_enable_disable_push_notification',
			),
			'wptw_push_notification_activity'        => array(
				'controller' => new PushNotificationController(),
				'method'     => 'wptw_push_notification_activity',
			),

			'wptw_verify_license'                    => array(
				'controller' => new VerifyStatusController(),
				'method'     => 'wptw_verify_license',
			),

			// Logs Routes.
			'wptw_logs_feature'                      => array(
				'controller' => new GetLogs(),
				'method'     => 'wptw_logs_feature',
			),
			'wptw_logs_filter_options'                  => array(
				'controller' => new GetLogs(),
				'method'     => 'wptw_logs_filter_options',
			),
			'wptw_get_log_by_id'                        => array(
				'controller' => new GetLogs(),
				'method'     => 'wptw_get_log_by_id',
			),
			'wptw_delete_logs'                          => array(
				'controller' => new DeleteLogs(),
				'method'     => 'wptw_delete_logs',
			),
			'wptw_delete_entries_and_logs'              => array(
				'controller' => new DeleteLogs(),
				'method'     => 'wptw_delete_entries_and_logs',
			),

			// Website Stats.
			'wptw_get_formatted_website_stats'       => array(
				'controller' => new WebsiteStatsController(),
				'method'     => 'wptw_get_formatted_website_stats',
			),

			// Feature Reset Controller.
			'wptw_reset_feature_by_option'           => array(
				'controller' => new ResetByFeatureOptionController(),
				'method'     => 'wptw_reset_feature_by_option',
			),
			'wptw_start_reset_all_settings'          => array(
				'controller' => new ResetAllFeatureController(),
				'method'     => 'wptw_start_reset_all_settings',
			),
			'wptw_reset_all_settings_status'         => array(
				'controller' => new ResetAllFeatureController(),
				'method'     => 'wptw_reset_all_settings_status',
			),
			'wptw_reset_cron_if_failed'              => array(
				'controller' => new ResetAllFeatureController(),
				'method'     => 'wptw_reset_cron_if_failed',
			),

			'wptw_verify_import_and_reset_status'    => array(
				'controller' => new SettingsController(),
				'method'     => 'wptw_verify_import_and_reset_status',
			),

			// Theme Controller
			'wptw_get_all_installed_themes'          => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_get_all_installed_themes',
			),
			'wptw_delete_theme'                      => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_delete_theme',
			),
			'wptw_activate_theme'                    => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_activate_theme',
			),
			'wptw_update_theme'                      => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_update_theme',
			),
			'wptw_get_theme_versions'                => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_get_theme_versions',
			),
			'wptw_theme_details'                     => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_theme_details',
			),
			'wptw_theme_rollback'                    => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_theme_rollback',
			),
			'wptw_check_theme_compatibility'         => array(
				'controller' => new ThemeController(),
				'method'     => 'wptw_check_theme_compatibility',
			),

			// Plugin Controller
			'wptw_get_all_installed_plugins'         => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_get_all_installed_plugins',
			),
			'wptw_update_plugin'                     => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_update_plugin',
			),
			'wptw_get_plugin_versions'               => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_get_plugin_versions',
			),
			'wptw_plugin_details'                    => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_plugin_details',
			),
			'wptw_plugin_rollback'                   => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_plugin_rollback',
			),
			'wptw_activate_plugin'                   => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_activate_plugin',
			),
			'wptw_deactivate_plugin'                 => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_deactivate_plugin',
			),
			'wptw_delete_plugin'                     => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_delete_plugin',
			),
			'wptw_bulk_plugin_action'                => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_bulk_plugin_action',
			),
			'wptw_check_plugin_compatibility'        => array(
				'controller' => new PluginController(),
				'method'     => 'wptw_check_plugin_compatibility',
			),

			// Core Controller
			'wptw_get_wordpress_details'             => array(
				'controller' => new CoreController(),
				'method'     => 'wptw_get_wordpress_details',
			),
			'wptw_get_core_updates'                  => array(
				'controller' => new CoreController(),
				'method'     => 'wptw_get_core_updates',
			),
			'wptw_update_core'                       => array(
				'controller' => new CoreController(),
				'method'     => 'wptw_update_core',
			),
			'wptw_rollback_update_core'              => array(
				'controller' => new CoreController(),
				'method'     => 'wptw_rollback_update_core',
			),
			'wptw_get_core_versions'                 => array(
				'controller' => new CoreController(),
				'method'     => 'wptw_get_core_versions',
			),
			'wptw_check_core_compatibility'          => array(
				'controller' => new CoreController(),
				'method'     => 'wptw_check_core_compatibility',
			),

			// Plugin + Theme Controller.
			'wptw_get_total_updates_available'       => array(
				'controller' => new PluginThemeController(),
				'method'     => 'wptw_get_total_updates_available',
			),

			// History Controller.
			'wptw_get_history'                       => array(
				'controller' => new HistoryController(),
				'method'     => 'wptw_get_history',
			),
			'wptw_get_plugin_history'                => array(
				'controller' => new HistoryController(),
				'method'     => 'wptw_get_plugin_history',
			),
			'wptw_get_theme_history'                 => array(
				'controller' => new HistoryController(),
				'method'     => 'wptw_get_theme_history',
			),
			'wptw_get_core_history'                  => array(
				'controller' => new HistoryController(),
				'method'     => 'wptw_get_core_history',
			),

			// Backup.
			'wptw_get_backup_settings'               => array(
				'controller' => new BackupMaintainController(),
				'method'     => 'wptw_get_backup_settings',
			),
			'wptw_get_backup_status'                 => array(
				'controller' => new BackupMaintainController(),
				'method'     => 'wptw_get_backup_status',
			),
			'wptw_delete_backup_folder'              => array(
				'controller' => new BackupMaintainController(),
				'method'     => 'wptw_delete_backup_folder',
			),
			'wptw_get_live_logs'                     => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_get_live_logs',
			),
			'wptw_instant_backup_scanner'            => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_instant_backup_scanner',
			),
			'wptw_start_backup_with_optimize_or_not' => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_start_backup_with_optimize_or_not',
			),
			'wptw_verify_backup_status'              => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_verify_backup_status',
			),
			'wptw_resume_backup'                     => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_resume_backup',
			),
			'wptw_pause_backup_creation'             => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_pause_backup_creation',
			),
			'wptw_get_backup_folders_info'           => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_get_backup_folders_info',
			),
			'wptw_get_backup_folder_files'           => array(
				'controller' => new BackupController(),
				'method'     => 'wptw_get_backup_folder_files',
			),

			// Database Optimizer.
			'wptw_database_optimize'                 => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'wptw_database_optimize',
			),
			'wptw_get_optimize_live_logs'            => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'wptw_get_optimize_live_logs',
			),
			'wptw_resume_db_optimize'                => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'wptw_resume_db_optimize',
			),
			'wptw_verify_db_optimize_status'         => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'wptw_verify_db_optimize_status',
			),
			'wptw_pause_db_optimize'                 => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'wptw_pause_db_optimize',
			),
			'wptw_db_optimization_cron_if_failed'    => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'wptw_db_optimization_cron_if_failed',
			),
			'wptw_check_db_optimization_status'      => array(
				'controller' => new GetTablesOptimizerController(),
				'method'     => 'wptw_check_db_optimization_status',
			),
			'wptw_get_db_optimizer_status'           => array(
				'controller' => new GetTablesOptimizerController(),
				'method'     => 'wptw_get_db_optimizer_status',
			),

			// Search Replace.
			'wptw_get_all_table_names'               => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_get_all_table_names',
			),
			'wptw_start_search_replace'              => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_start_search_replace',
			),
			'wptw_live_search_replace_logs'          => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_live_search_replace_logs',
			),
			'wptw_resume_search_replace'             => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_resume_search_replace',
			),
			'wptw_cancel_pause_search_replace'       => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_cancel_pause_search_replace',
			),
			'wptw_verify_search_replace_status'      => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_verify_search_replace_status',
			),
			'wptw_search_replace_cron_if_failed'     => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'wptw_search_replace_cron_if_failed',
			),

			// User Controllers.
			'wptw_get_user_roles_data'                  => array(
				'controller' => new UserRolesController(),
				'method'     => 'wptw_get_user_roles_data',
			),
			'wptw_get_user_status'                      => array(
				'controller' => new UserRetrievalController(),
				'method'     => 'wptw_get_user_status',
			),
			'wptw_get_user_by_id'                       => array(
				'controller' => new UserRetrievalController(),
				'method'     => 'wptw_get_user_by_id',
			),
			'wptw_update_user_status'                   => array(
				'controller' => new UserModificationController(),
				'method'     => 'wptw_update_user_status',
			),
			'wptw_get_all_roles'                        => array(
				'controller' => new UserRetrievalController(),
				'method'     => 'wptw_get_all_roles',
			),
			'wptw_change_user_role'                     => array(
				'controller' => new UserModificationController(),
				'method'     => 'wptw_change_user_role',
			),
			'wptw_create_user'                          => array(
				'controller' => new UserModificationController(),
				'method'     => 'wptw_create_user',
			),
			'wptw_update_user'                          => array(
				'controller' => new UserModificationController(),
				'method'     => 'wptw_update_user',
			),
			'wptw_get_user_content_summary'             => array(
				'controller' => new UserDeletionController(),
				'method'     => 'wptw_get_user_content_summary',
			),
			'wptw_delete_user'                          => array(
				'controller' => new UserDeletionController(),
				'method'     => 'wptw_delete_user',
			),
			'wptw_bulk_delete_users'                    => array(
				'controller' => new UserDeletionController(),
				'method'     => 'wptw_bulk_delete_users',
			),

			// Files Integration Controller.
			'wptw_instant_files_integrity_check'     => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_instant_files_integrity_check',
			),
			'wptw_verify_integrity_current_status'   => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_verify_integrity_current_status',
			),
			'wptw_files_integrity_comparison_logs'   => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_files_integrity_comparison_logs',
			),
			'wptw_cancel_pause_integrity'            => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_cancel_pause_integrity',
			),
			'wptw_files_integrity_cron_if_failed'    => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_files_integrity_cron_if_failed',
			),
			'wptw_resume_files_integrity'            => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_resume_files_integrity',
			),
			'wptw_get_file_logs_data'                => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_get_file_logs_data',
			),
			'wptw_delete_comparison_by_id'           => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_delete_comparison_by_id',
			),
			'wptw_get_files_log_by_id'               => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_get_files_log_by_id',
			),
			'wptw_get_file_integrity_status'         => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'wptw_get_file_integrity_status',
			),

			// Hardening Audit
			'wptw_start_hardening_audit'             => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_start_hardening_audit',
			),
			'wptw_verify_hardening_audit_status'     => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_verify_hardening_audit_status',
			),
			'wptw_resume_hardening_audit'            => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_resume_hardening_audit',
			),
			'wptw_cancel_pause_hardening_audit'      => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_cancel_pause_hardening_audit',
			),
			'wptw_list_hardening_audit_reports'      => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_list_hardening_audit_reports',
			),
			'wptw_get_hardening_audit_report_by_id'  => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_get_hardening_audit_report_by_id',
			),
			'wptw_delete_hardening_audit_report_by_id' => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_delete_hardening_audit_report_by_id',
			),
			'wptw_get_hardening_audit_live_logs'     => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_get_hardening_audit_live_logs',
			),
			'wptw_hardening_audit_cron_if_failed'    => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'wptw_hardening_audit_cron_if_failed',
			),

			'wptw_features_calculate_score'          => array(
				'controller' => new SecurityFeaturesVerifyController(),
				'method'     => 'wptw_features_calculate_score',
			),
			'wptw_start_security_features_process'   => array(
				'controller' => new SecurityFeaturesVerifyController(),
				'method'     => 'wptw_start_security_features_process',
			),
			'wptw_execute_security_features_cron_if_failed' => array(
				'controller' => new SecurityFeaturesVerifyController(),
				'method'     => 'wptw_execute_security_features_cron_if_failed',
			),

			// Email and Smtp Controllers.
			'wptw_smtp_test_email'                   => array(
				'controller' => new SmtpTestController(),
				'method'     => 'wptw_smtp_test_email',
			),

			// Redirection Rules
			'wptw_create_redirection_rules'          => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'wptw_create_redirection_rules',
			),
			'wptw_get_all_post_types'                => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'wptw_get_all_post_types',
			),
			'wptw_get_posts_by_post_type'            => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'wptw_get_posts_by_post_type',
			),
			'wptw_update_redirect_rule'              => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'wptw_update_redirect_rule',
			),
			'wptw_get_all_redirect_rules'            => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'wptw_get_all_redirect_rules',
			),

			// Broken Link Checker
			'wptw_start_broken_link_checker'         => array(
				'controller' => new BrokenLinkChecker(),
				'method'     => 'wptw_start_broken_link_checker',
			),
			'wptw_broken_link_checker_live_logs'     => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'wptw_broken_link_checker_live_logs',
			),
			'wptw_resume_broken_link_checker'        => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'wptw_resume_broken_link_checker',
			),
			'wptw_cancel_pause_broken_link'          => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'wptw_cancel_pause_broken_link',
			),
			'wptw_broken_links_cron_if_failed'       => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'wptw_broken_links_cron_if_failed',
			),
			'wptw_verify_broken_link_status'         => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'wptw_verify_broken_link_status',
			),
			'wptw_get_broken_links_logs'             => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'wptw_get_broken_links_logs',
			),

			// Cron Jobs Routes
			'wptw_get_cron_jobs_with_source'         => array(
				'controller' => new GetCronJobDetailsController(),
				'method'     => 'wptw_get_cron_jobs_with_source',
			),
			'wptw_get_schedules'                     => array(
				'controller' => new GetCronJobDetailsController(),
				'method'     => 'wptw_get_schedules',
			),
			'wptw_run_cron_job'                      => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_run_cron_job',
			),
			'wptw_pause_cron_job'                    => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_pause_cron_job',
			),
			'wptw_resume_cron_job'                   => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_resume_cron_job',
			),
			'wptw_delete_cron_job'                   => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_delete_cron_job',
			),
			'wptw_add_cron_job'                      => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_add_cron_job',
			),
			'wptw_edit_cron_job'                     => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_edit_cron_job',
			),
			'wptw_bulk_cron_action'                  => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_bulk_cron_action',
			),
			'wptw_add_schedule'                      => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_add_schedule',
			),
			'wptw_edit_schedule'                     => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_edit_schedule',
			),
			'wptw_delete_schedule'                   => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'wptw_delete_schedule',
			),

			'wptw_get_process_monitoring_status'     => array(
				'controller' => new ProcessMonitoringController(),
				'method'     => 'wptw_get_process_monitoring_status',
			),

			// Recovery
			'wptw_instant_generate_recovery_mode_link' => array(
				'controller' => new RecoveryModeController(),
				'method'     => 'wptw_instant_generate_recovery_mode_link',
			),

			// Recovery Mode
			'wptw_generate_recovery_cookie'          => array(
				'controller' => new RecoveryModeService(),
				'method'     => 'wptw_generate_recovery_cookie',
			),

			'wptw_cron_healer'                       => array(
				'controller' => new CronHealer(),
				'method'     => 'wptw_cron_healer',
			),

			'wptw_get_php_settings'                  => array(
				'controller' => new PerformanceOptimizerController(),
				'method'     => 'wptw_get_php_settings',
			),
			'wptw_save_php_settings'                 => array(
				'controller' => new PerformanceOptimizerController(),
				'method'     => 'wptw_save_php_settings',
			),
			'wptw_remove_php_settings'               => array(
				'controller' => new PerformanceOptimizerController(),
				'method'     => 'wptw_remove_php_settings',
			),

			// Bulk Feature Activation
			'wptw_activate_features_bulk'            => array(
				'controller' => new BulkFeatureActivationController(),
				'method'     => 'wptw_activate_features_bulk',
			),

			// IP Management (Blacklist)
			'wptw_handle_get_blocked_ip_ranges'      => array(
				'controller' => new IpBlackListController(),
				'method'     => 'wptw_handle_get_blocked_ip_ranges',
			),
			'wptw_handle_add_ip_rule'                => array(
				'controller' => new IpBlackListController(),
				'method'     => 'wptw_handle_add_ip_rule',
			),
			'wptw_handle_unblock_ip_range'           => array(
				'controller' => new IpBlackListController(),
				'method'     => 'wptw_handle_unblock_ip_range',
			),
			'wptw_handle_delete_ip_rule'             => array(
				'controller' => new IpBlackListController(),
				'method'     => 'wptw_handle_delete_ip_rule',
			),
			'wptw_handle_update_ip_rule'             => array(
				'controller' => new IpBlackListController(),
				'method'     => 'wptw_handle_update_ip_rule',
			),
			'wptw_preview_block_page'               => array(
				'controller' => new IpManagementController(),
				'method'     => 'wptw_preview_block_page',
			),

			// IP Management (Whitelist)
			'wptw_handle_add_ip_whitelist'           => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'wptw_handle_add_ip_whitelist',
			),
			'wptw_handle_update_ip_whitelist'        => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'wptw_handle_update_ip_whitelist',
			),
			'wptw_handle_get_ip_whitelists'          => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'wptw_handle_get_ip_whitelists',
			),
			'wptw_handle_delete_ip_whitelist'        => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'wptw_handle_delete_ip_whitelist',
			),
			'wptw_handle_update_country_whitelist'   => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'wptw_handle_update_country_whitelist',
			),
			'wptw_handle_add_country_whitelist'      => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'wptw_handle_add_country_whitelist',
			),
			'wptw_handle_delete_country_whitelist'   => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'wptw_handle_delete_country_whitelist',
			),
			'wptw_handle_get_country_whitelists'     => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'wptw_handle_get_country_whitelists',
			),

			// Login Defender (IP Protection)
			'wptw_handle_get_ip_activity_history'    => array(
				'controller' => new IpProtectionController(),
				'method'     => 'wptw_handle_get_ip_activity_history',
			),
			'wptw_handle_get_all_ip_activities'      => array(
				'controller' => new IpProtectionController(),
				'method'     => 'wptw_handle_get_all_ip_activities',
			),
			'wptw_handle_delete_ip_activity'         => array(
				'controller' => new IpProtectionController(),
				'method'     => 'wptw_handle_delete_ip_activity',
			),
			'wptw_handle_unblock_ip'                 => array(
				'controller' => new IpProtectionController(),
				'method'     => 'wptw_handle_unblock_ip',
			),

			// Feature log counts
			'wptw_login_defender_log_count'          => array(
				'controller' => new LoginDefenderLogCount(),
				'method'     => 'wptw_login_defender_log_count',
			),
			'wptw_ip_management_log_count'           => array(
				'controller' => new IpManagementLogCount(),
				'method'     => 'wptw_ip_management_log_count',
			),

			// Integrations (MaxMind GeoIP2)
			'wptw_get_integration_data'              => array(
				'controller' => new IntegrationController(),
				'method'     => 'wptw_get_integration_data',
			),
			'wptw_update_integration_data'           => array(
				'controller' => new IntegrationController(),
				'method'     => 'wptw_update_integration_data',
			),
			'wptw_delete_integration_data'           => array(
				'controller' => new IntegrationController(),
				'method'     => 'wptw_delete_integration_data',
			),
			'wptw_is_geo_lite_connected_or_exist'    => array(
				'controller' => new GeoLiteTwoController(),
				'method'     => 'wptw_is_geo_lite_connected_or_exist',
			),

			// Media Routes.
			'wptw_get_wp_media'                      => array(
				'controller' => new MediaController(),
				'method'     => 'wptw_get_wp_media',
			),
			'wptw_upload_wp_media'                   => array(
				'controller' => new MediaController(),
				'method'     => 'wptw_upload_wp_media',
			),
			'wptw_delete_wp_media'                   => array(
				'controller' => new MediaController(),
				'method'     => 'wptw_delete_wp_media',
			),

		);

		return isset( $routes[ $action_type ] ) ? $routes[ $action_type ] : null;
	}
}
