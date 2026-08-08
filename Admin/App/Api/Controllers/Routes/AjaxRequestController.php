<?php
/**
 * AJAX Request Controller
 *
 * Centralized handler for all AJAX requests in the plugin.
 * Provides nonce verification, capability checks, and feature gating.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Routes
 */

namespace Tailwatch\Admin\App\Api\Controllers\Routes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Logs\DeleteLogs;
use Tailwatch\Admin\App\Api\Controllers\Logs\GetLogs;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Dashboard\DashboardController;
use Tailwatch\Admin\App\Api\Controllers\Features\FeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Visit\VisitController;
use Tailwatch\Admin\App\Api\Controllers\Visit\RecommendedFeaturesController;
use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Ssl\SslVerificationController;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\WebsiteStatsController;
use Tailwatch\Admin\App\Api\Controllers\Disk\DiskSpaceController;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetByFeatureOptionController;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetAllFeatureController;
use Tailwatch\Admin\App\Api\Controllers\Settings\SettingsController;
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
use Tailwatch\Admin\App\Api\Controllers\Redirections\RedirectionsManager;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkChecker;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkStatus;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl\GetCronJobDetailsController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl\CronJobManagerController;
use Tailwatch\Admin\App\Api\Controllers\ProcessMonitoring\ProcessMonitoringController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronHeal\CronHealer;
use Tailwatch\Admin\App\Api\Controllers\Features\BulkFeatureActivationController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\BlackList\IpBlackListController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\WhiteList\IpWhiteListController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\WhiteList\CountryWhiteListController;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\IpProtections\IpProtectionController;
use Tailwatch\Admin\App\Api\Controllers\Logs\FeatureCounts\IpManagementLogCount;
use Tailwatch\Admin\App\Api\Controllers\Logs\FeatureCounts\LoginDefenderLogCount;

/**
 * Class AjaxRequestController
 *
 * ## Single chokepoint for all plugin AJAX requests
 *
 * Every AJAX-driven feature in the Tailwatch plugin (~968 distinct action types)
 * is dispatched through this one class. The constructor registers a single
 * WordPress AJAX hook (`wp_ajax_tailwatch_global_ajax_handler`) — there are NO
 * `wp_ajax_nopriv_*` registrations anywhere in the plugin, so anonymous AJAX
 * is impossible by construction.
 *
 * ## Centralized nonce + capability gate
 *
 * The single entry point `tailwatch_global_ajax_handler()` enforces — BEFORE any
 * downstream controller method is invoked — the following gate (see the very
 * first lines of that method below):
 *
 *   1. wp_verify_nonce( $_POST['nonce'], 'tailwatch_ajax_nonce' ). Reject the request
 *      with a JSON error if the nonce is missing or invalid. The nonce is
 *      minted server-side via wp_create_nonce('tailwatch_ajax_nonce') and emitted to
 *      the admin UI via wp_localize_script() (see InterfaceController).
 *      Anonymous, cross-origin, and replayed requests fail at this gate.
 *
 *   2. current_user_can( 'manage_options' ). Reject with a JSON error if the
 *      current user is not an administrator. Subscribers, editors, authors,
 *      and contributors are all rejected — the plugin has no per-role AJAX
 *      surface and no anonymous AJAX surface at all.
 *
 *   3. Only AFTER both gates pass does the handler read $_POST['action_type']
 *      and dispatch to the appropriate sub-controller method via the action
 *      map in get_route_for_action(). Every downstream controller therefore
 *      inherits "admin + valid nonce" as a precondition.
 *
 * ## Why downstream controllers do not repeat the checks
 *
 * The individual controller methods do NOT contain inline
 * wp_verify_nonce() / current_user_can() calls — the protection is enforced
 * once, upstream, in this class. Repeating the checks in every downstream
 * method would be redundant (the gate is unconditional and cannot be bypassed)
 * and would obscure the actual auth model.
 *
 */
class AjaxRequestController {

	/**
	 * Constructor - Register AJAX handler.
	 */
	public function __construct() {
		$hook_controllers = new HookControllers();
		$hook_controllers->add_action_hook( 'wp_ajax_tailwatch_global_ajax_handler', array( $this, 'tailwatch_global_ajax_handler' ) );
		// Intentionally NOT registering wp_ajax_nopriv_* — every plugin route requires an authenticated admin session.
	}

	/**
	 * Global AJAX handler for all plugin AJAX requests.
	 *
	 * Verifies nonce, checks capabilities, routes to appropriate controller,
	 * and handles responses.
	 *
	 * @return void
	 */
	public function tailwatch_global_ajax_handler() {
		try {
			// Verify nonce for security.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in next line
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'tailwatch_ajax_nonce' ) ) {
				wp_send_json_error( __( 'Invalid nonce', 'tailwatch' ) );
				return;
			}

			// Ensure user has permission to access the plugin.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions', 'tailwatch' ) );
				return;
			}

			// Check for required parameters.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
			if ( ! isset( $_POST['action_type'] ) ) {
				wp_send_json_error( __( 'Missing required parameters', 'tailwatch' ) );
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
			$action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );

			// $_POST['data'] is a JSON payload. Following the WordPress "sanitizing array input
			// data" guidance, structured input is sanitized element-by-element after decoding —
			// not by blanket-sanitizing the raw JSON string, which would corrupt URLs, credentials
			// and percent-encoded values. tailwatch_sanitize_request_payload() decodes the payload,
			// sanitizes every value with the function that matches its type, and re-encodes it, so
			// the premium filter and each controller receive already-sanitized data. Controllers
			// then re-sanitize per field as a second layer.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified earlier in this method; $_POST['data'] is unslashed and immediately passed to tailwatch_sanitize_request_payload(), which decodes the JSON and sanitizes every value element-by-element with a context-appropriate function. A JSON string cannot be blanket-sanitized inline (e.g. sanitize_text_field) without corrupting URLs, credentials and percent-encoded values, so a custom sanitizer is used here; PHPCS cannot recognise it, hence this annotation.
			$post_data = $this->tailwatch_sanitize_request_payload( isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : null );

			// Allow the pro plugin to verify plan access before executing any route.
			// Pro plugin hooks in via LicenseFeatureController and uses CallAccessVerifyController.
			// Default null = allow. Array with status => false = deny with 402.
			$access_check = apply_filters( 'tailwatch_verify_ajax_action', null, $action_type );
			if ( is_array( $access_check ) && false === ( $access_check['status'] ?? true ) ) {
				// Derive the suggested frontend action from the specific reason
				// code returned by the access check. Suspended licenses must NOT
				// show "Upgrade your plan" — the user already has the plan, they
				// need to reactivate. Plan-insufficient still maps to upgrade.
				// Missing license maps to connect. Default keeps backward compat
				// for any future code we don't recognise here.
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

				wp_send_json_error(
					array(
						'code'          => 402,
						'message'       => $access_check['message'] ?? 'Access denied.',
						'role'          => $access_check['role'] ?? null,
						'reason'        => $reason_code,
						'action'        => $frontend_action,
					)
				);
				return;
			}

			// Get controller and method based on action type.
			$route = $this->get_route_for_action( $action_type );

			if ( null === $route ) {
				// Allow the premium plugin to handle routes it owns before returning an error.
				$premium_response = apply_filters( 'tailwatch_handle_premium_ajax_routes', null, $action_type, $post_data );

				if ( null !== $premium_response ) {
					if ( isset( $premium_response['code'] ) && 200 === $premium_response['code'] ) {
						wp_send_json_success( $premium_response );
					} else {
						wp_send_json_error( $premium_response );
					}
					return;
				}

				// Pro plugin is installed but did not claim this action — it is truly unknown.
				// If pro is NOT installed, the action may be a premium feature; return an upgrade
				// prompt instead of a misleading "invalid action" error.
				if ( ! apply_filters( 'tailwatch_is_premium_plugin_active', false ) ) {
					wp_send_json_error(
						array(
							'code'    => 402,
							'message' => __( 'This feature requires the Tailwatch Pro Plugin.', 'tailwatch' ),
							'action'  => 'premium_plugin_required',
						)
					);
					return;
				}

				wp_send_json_error(
					array(
						'code'    => 400,
						'message' => __( 'Invalid action type provided', 'tailwatch' ),
						'action'  => 'invalid_action_type',
					)
				);
				return;
			}

			$controller = $route['controller'];
			$method     = $route['method'];

			// Ensure the method exists.
			if ( ! method_exists( $controller, $method ) ) {
				wp_send_json_error( __( 'Invalid method', 'tailwatch' ) );
				return;
			}

			// Global Feature Check: If controller implements feature checking.
			if ( method_exists( $controller, 'tailwatch_check_feature_enabled' ) ) {
				$feature_status = $controller->tailwatch_check_feature_enabled( $method );

				if ( isset( $feature_status['feature_enable'] ) && false === $feature_status['feature_enable'] ) {
					wp_send_json_error(
						array(
							'feature_enable' => false,
							'parent_enable'  => isset( $feature_status['parent_enable'] ) ? $feature_status['parent_enable'] : false,
							'message'        => isset( $feature_status['message'] ) ? $feature_status['message'] : 'Feature is not enabled. Please enable it first.',
							'code'           => 400,
						)
					);
					return;
				}
			}

			// Call the method and pass the data.
			$response = $controller->$method( $post_data );

			if ( isset( $response['code'] ) && 200 === $response['code'] ) {
				wp_send_json_success( $response );
			} else {
				wp_send_json_error( $response );
			}
		} catch ( \Exception $e ) {

			wp_send_json_error(
				array(
					'code'    => 500,
					'message' => __( 'Internal server error occurred while processing the request', 'tailwatch' ),
				)
			);
		}
	}

	/**
	 * Sanitize the raw AJAX request payload at the entry point.
	 *
	 * The payload is normally a JSON-encoded object. It is decoded and every value
	 * is sanitized with the function appropriate to its type (WordPress "sanitizing
	 * array input data" guidance), then re-encoded so the premium filter and each
	 * controller receive already-sanitized data and re-sanitize per field as a
	 * second layer. A non-JSON scalar payload (for example a bare action keyword)
	 * is sanitized as plain text.
	 *
	 * @param string|null $raw Unslashed raw request body.
	 * @return string|null Sanitized payload, or null when nothing was provided.
	 */
	private function tailwatch_sanitize_request_payload( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return wp_json_encode(
				$this->tailwatch_sanitize_entry_value( $decoded ),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		}

		// Not a JSON object/array — a bare scalar route (e.g. 'extended_connected').
		return sanitize_text_field( $raw );
	}

	/**
	 * Recursively sanitize a decoded request value, selecting the sanitizer that
	 * matches each field's type.
	 *
	 * Credentials/secrets are preserved byte-exact (they are hashed or encrypted
	 * downstream); URLs use esc_url_raw() so valid percent-encoding and relative
	 * paths survive; e-mail values use sanitize_email(); rich-text message fields
	 * allow safe HTML via wp_kses_post(); everything else uses
	 * sanitize_textarea_field() (which preserves newlines). Non-string scalars
	 * (int/float/bool/null) are left intact.
	 *
	 * @param mixed  $value Decoded value.
	 * @param string $key   Key the value was stored under (drives sanitizer choice).
	 * @return mixed Sanitized value.
	 */
	private function tailwatch_sanitize_entry_value( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			// Feature settings travel as sub-option objects shaped like
			// { "id": "custom_password", "value": <secret>, "selected": true }, where the
			// value's real type is named by its sibling "id"/"key"/"register" — not by the
			// literal key "value". Classify the "value" element by that sibling so password,
			// URL and e-mail sub-options are handled correctly (a password sub-option must
			// reach the controller byte-exact for encryption, never blanket-sanitized here).
			$sibling_type_key = '';
			if ( array_key_exists( 'value', $value ) ) {
				foreach ( array( 'id', 'key', 'register' ) as $sibling ) {
					if ( isset( $value[ $sibling ] ) && is_string( $value[ $sibling ] ) && '' !== $value[ $sibling ] ) {
						$sibling_type_key = $value[ $sibling ];
						break;
					}
				}
			}

			$clean = array();
			foreach ( $value as $inner_key => $inner_value ) {
				$clean_key     = is_string( $inner_key ) ? sanitize_text_field( $inner_key ) : $inner_key;
				$effective_key = ( 'value' === $inner_key && '' !== $sibling_type_key ) ? $sibling_type_key : (string) $inner_key;
				$clean[ $clean_key ] = $this->tailwatch_sanitize_entry_value( $inner_value, $effective_key );
			}
			return $clean;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$field = strtolower( $key );

		// Credentials / secrets: must reach the controller byte-exact for hashing/encryption.
		// Kept deliberately broad — treating a non-secret field as a secret only means it
		// is sanitized by its controller instead of here (safe), whereas missing a real
		// secret would corrupt it before hashing/encryption.
		if ( preg_match( '/(pass|pwd|secret|token|key$|credential|refresh|private|salt|signature|client[_-]?id|oauth|bearer|jwt|passphrase)/', $field ) ) {
			return $value;
		}

		// URLs (by key suffix or value shape), excluding data: URIs so inline base64
		// survives below. esc_url_raw() keeps valid percent-encoding and relative paths.
		if ( 0 !== stripos( ltrim( $value ), 'data:' )
			&& ( preg_match( '/(_url$|^url$|^website$)/', $field )
				|| preg_match( '#^\s*https?://#i', $value ) )
		) {
			return esc_url_raw( trim( $value ) );
		}

		// E-mail values.
		if ( is_email( $value ) ) {
			return sanitize_email( $value );
		}

		// Rich-text message fields rendered with wp_kses_post downstream: keep safe HTML.
		if ( preg_match( '/(message|template|heading|content|body|description)/', $field ) ) {
			return wp_kses_post( $value );
		}

		// Default: multi-line-safe text (keeps newlines, strips tags).
		return sanitize_textarea_field( $value );
	}

	/**
	 * Get route (controller and method) for a given action type.
	 *
	 * Maps action types to their corresponding controllers and methods.
	 *
	 * @param string $action_type The action type from the AJAX request.
	 *
	 * @return array|null Array with 'controller' and 'method' keys, or null if not found.
	 */
	private function get_route_for_action( $action_type ) {
		// Define all routes in a structured way.
		$routes = array(
			// Features Controller.
			'tailwatch_get_feature'                          => array(
				'controller' => new FeaturesController(),
				'method'     => 'tailwatch_get_feature',
			),
			'tailwatch_update_feature_status'                => array(
				'controller' => new FeaturesController(),
				'method'     => 'tailwatch_update_feature_status',
			),
			'tailwatch_update_inner_feature'                 => array(
				'controller' => new FeaturesController(),
				'method'     => 'tailwatch_update_inner_feature',
			),
			'tailwatch_update_push_notification_value'       => array(
				'controller' => new FeaturesController(),
				'method'     => 'tailwatch_update_push_notification_value',
			),

			// Push Notification Controller.
			'tailwatch_enable_disable_push_notification'     => array(
				'controller' => new PushNotificationController(),
				'method'     => 'tailwatch_enable_disable_push_notification',
			),
			'tailwatch_get_push_notification'                => array(
				'controller' => new PushNotificationController(),
				'method'     => 'tailwatch_get_push_notification',
			),

			// Settings Controller.
			'tailwatch_update_plugin_activation'             => array(
				'controller' => new VerifyStatusController(),
				'method'     => 'tailwatch_update_plugin_activation',
			),
			'tailwatch_get_plugin_activation'                => array(
				'controller' => new VerifyStatusController(),
				'method'     => 'tailwatch_get_plugin_activation',
			),
			'tailwatch_delete_plugin_activation_data'        => array(
				'controller' => new VerifyStatusController(),
				'method'     => 'tailwatch_delete_plugin_activation_data',
			),
			'tailwatch_verify_license'                       => array(
				'controller' => new VerifyStatusController(),
				'method'     => 'tailwatch_verify_license',
			),
			'tailwatch_get_user_info'                        => array(
				'controller' => new SettingsController(),
				'method'     => 'tailwatch_get_user_info',
			),
			// Logs Controllers.
			'tailwatch_logs_feature'                         => array(
				'controller' => new GetLogs(),
				'method'     => 'tailwatch_logs_feature',
			),
			'tailwatch_logs_filter_options'                  => array(
				'controller' => new GetLogs(),
				'method'     => 'tailwatch_logs_filter_options',
			),
			'tailwatch_get_log_by_id'                        => array(
				'controller' => new GetLogs(),
				'method'     => 'tailwatch_get_log_by_id',
			),
			'tailwatch_delete_logs'                          => array(
				'controller' => new DeleteLogs(),
				'method'     => 'tailwatch_delete_logs',
			),
			'tailwatch_delete_entries_and_logs'              => array(
				'controller' => new DeleteLogs(),
				'method'     => 'tailwatch_delete_entries_and_logs',
			),

			// Visit Controller.
			'tailwatch_verify_visit_progress'                => array(
				'controller' => new VisitController(),
				'method'     => 'tailwatch_verify_visit_progress',
			),
			'tailwatch_check_php_version'                    => array(
				'controller' => new VisitController(),
				'method'     => 'tailwatch_check_php_version',
			),
			'tailwatch_check_wordpress_version'              => array(
				'controller' => new VisitController(),
				'method'     => 'tailwatch_check_wordpress_version',
			),
			'check_tailwatch_table'                          => array(
				'controller' => new VisitController(),
				'method'     => 'check_tailwatch_table',
			),
			'tailwatch_check_cron_status'                    => array(
				'controller' => new VisitController(),
				'method'     => 'tailwatch_check_cron_status',
			),
			'tailwatch_verify_initialize_completed'          => array(
				'controller' => new VisitController(),
				'method'     => 'tailwatch_verify_initialize_completed',
			),

			// Dashboard Controller.
			'tailwatch_dashboard_logs_count'                 => array(
				'controller' => new DashboardController(),
				'method'     => 'tailwatch_dashboard_logs_count',
			),
			'tailwatch_dashboard_features'                   => array(
				'controller' => new DashboardController(),
				'method'     => 'tailwatch_dashboard_features',
			),
			'tailwatch_scanning_feature_detail'              => array(
				'controller' => new DashboardController(),
				'method'     => 'tailwatch_scanning_feature_detail',
			),

			// Recommended Features Controller.
			'tailwatch_start_features_implementation'        => array(
				'controller' => new RecommendedFeaturesController(),
				'method'     => 'tailwatch_start_features_implementation',
			),
			'tailwatch_recommended_features_process'         => array(
				'controller' => new RecommendedFeaturesController(),
				'method'     => 'tailwatch_recommended_features_process',
			),
			'tailwatch_run_feature_implement_cron_if_failed' => array(
				'controller' => new RecommendedFeaturesController(),
				'method'     => 'tailwatch_run_feature_implement_cron_if_failed',
			),

			// SSL Verification Controller.
			'tailwatch_return_ssl_verify_status'             => array(
				'controller' => new SslVerificationController(),
				'method'     => 'tailwatch_return_ssl_verify_status',
			),
			'tailwatch_verify_ssl_connection'                => array(
				'controller' => new SslVerificationController(),
				'method'     => 'tailwatch_verify_ssl_connection',
			),

			// Disk Space Controller.
			'tailwatch_disk_and_db_usage'                    => array(
				'controller' => new DiskSpaceController(),
				'method'     => 'tailwatch_disk_and_db_usage',
			),

			// Website Stats Controller.
			'tailwatch_get_formatted_website_stats'          => array(
				'controller' => new WebsiteStatsController(),
				'method'     => 'tailwatch_get_formatted_website_stats',
			),

			// Feature Reset Controller.
			'tailwatch_reset_feature_by_option'              => array(
				'controller' => new ResetByFeatureOptionController(),
				'method'     => 'tailwatch_reset_feature_by_option',
			),
			'tailwatch_start_reset_all_settings'             => array(
				'controller' => new ResetAllFeatureController(),
				'method'     => 'tailwatch_start_reset_all_settings',
			),
			'tailwatch_reset_all_settings_status'            => array(
				'controller' => new ResetAllFeatureController(),
				'method'     => 'tailwatch_reset_all_settings_status',
			),
			'tailwatch_reset_cron_if_failed'                 => array(
				'controller' => new ResetAllFeatureController(),
				'method'     => 'tailwatch_reset_cron_if_failed',
			),

			'tailwatch_verify_import_and_reset_status'       => array(
				'controller' => new SettingsController(),
				'method'     => 'tailwatch_verify_import_and_reset_status',
			),

			// Backup.
			'tailwatch_instant_backup_scanner'               => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_instant_backup_scanner',
			),
			'tailwatch_get_live_logs'                        => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_get_live_logs',
			),
			'tailwatch_get_backup_folders_info'              => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_get_backup_folders_info',
			),
			'tailwatch_get_backup_folder_files'              => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_get_backup_folder_files',
			),
			'tailwatch_resume_backup'                        => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_resume_backup',
			),
			'tailwatch_pause_backup_creation'                => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_pause_backup_creation',
			),
			'tailwatch_verify_backup_status'                 => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_verify_backup_status',
			),
			'tailwatch_execute_cron_if_failed'               => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_execute_cron_if_failed',
			),
			'tailwatch_start_backup_with_optimize_or_not'    => array(
				'controller' => new BackupController(),
				'method'     => 'tailwatch_start_backup_with_optimize_or_not',
			),
			'tailwatch_delete_backup_folder'                 => array(
				'controller' => new BackupMaintainController(),
				'method'     => 'tailwatch_delete_backup_folder',
			),
			'tailwatch_get_backup_status'                    => array(
				'controller' => new BackupMaintainController(),
				'method'     => 'tailwatch_get_backup_status',
			),

			// Database Optimizer.
			'tailwatch_database_optimize'                    => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'tailwatch_database_optimize',
			),
			'tailwatch_get_optimize_live_logs'               => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'tailwatch_get_optimize_live_logs',
			),
			'tailwatch_resume_db_optimize'                   => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'tailwatch_resume_db_optimize',
			),
			'tailwatch_verify_db_optimize_status'            => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'tailwatch_verify_db_optimize_status',
			),
			'tailwatch_pause_db_optimize'                    => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'tailwatch_pause_db_optimize',
			),
			'tailwatch_db_optimization_cron_if_failed'       => array(
				'controller' => new DatabaseOptimizerController(),
				'method'     => 'tailwatch_db_optimization_cron_if_failed',
			),
			'tailwatch_check_db_optimization_status'         => array(
				'controller' => new GetTablesOptimizerController(),
				'method'     => 'tailwatch_check_db_optimization_status',
			),
			'tailwatch_get_db_optimizer_status'              => array(
				'controller' => new GetTablesOptimizerController(),
				'method'     => 'tailwatch_get_db_optimizer_status',
			),

			// Search Replace.
			'tailwatch_get_all_table_names'                  => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_get_all_table_names',
			),
			'tailwatch_start_search_replace'                 => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_start_search_replace',
			),
			'tailwatch_live_search_replace_logs'             => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_live_search_replace_logs',
			),
			'tailwatch_resume_search_replace'                => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_resume_search_replace',
			),
			'tailwatch_cancel_pause_search_replace'          => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_cancel_pause_search_replace',
			),
			'tailwatch_verify_search_replace_status'         => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_verify_search_replace_status',
			),
			'tailwatch_search_replace_cron_if_failed'        => array(
				'controller' => new SearchReplaceController(),
				'method'     => 'tailwatch_search_replace_cron_if_failed',
			),

			// User Controllers.
			'tailwatch_get_user_roles_data'                  => array(
				'controller' => new UserRolesController(),
				'method'     => 'tailwatch_get_user_roles_data',
			),
			'tailwatch_get_user_status'                      => array(
				'controller' => new UserRetrievalController(),
				'method'     => 'tailwatch_get_user_status',
			),
			'tailwatch_get_user_by_id'                       => array(
				'controller' => new UserRetrievalController(),
				'method'     => 'tailwatch_get_user_by_id',
			),
			'tailwatch_update_user_status'                   => array(
				'controller' => new UserModificationController(),
				'method'     => 'tailwatch_update_user_status',
			),
			'tailwatch_get_all_roles'                        => array(
				'controller' => new UserRetrievalController(),
				'method'     => 'tailwatch_get_all_roles',
			),
			'tailwatch_change_user_role'                     => array(
				'controller' => new UserModificationController(),
				'method'     => 'tailwatch_change_user_role',
			),
			'tailwatch_create_user'                          => array(
				'controller' => new UserModificationController(),
				'method'     => 'tailwatch_create_user',
			),
			'tailwatch_update_user'                          => array(
				'controller' => new UserModificationController(),
				'method'     => 'tailwatch_update_user',
			),
			'tailwatch_get_user_content_summary'             => array(
				'controller' => new UserDeletionController(),
				'method'     => 'tailwatch_get_user_content_summary',
			),
			'tailwatch_delete_user'                          => array(
				'controller' => new UserDeletionController(),
				'method'     => 'tailwatch_delete_user',
			),
			'tailwatch_bulk_delete_users'                    => array(
				'controller' => new UserDeletionController(),
				'method'     => 'tailwatch_bulk_delete_users',
			),

			// Files Integration Controller.
			'tailwatch_instant_files_integrity_check'        => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_instant_files_integrity_check',
			),
			'tailwatch_verify_integrity_current_status'      => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_verify_integrity_current_status',
			),
			'tailwatch_files_integrity_comparison_logs'      => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_files_integrity_comparison_logs',
			),
			'tailwatch_cancel_pause_integrity'               => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_cancel_pause_integrity',
			),
			'tailwatch_files_integrity_cron_if_failed'       => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_files_integrity_cron_if_failed',
			),
			'tailwatch_resume_files_integrity'               => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_resume_files_integrity',
			),
			'tailwatch_get_file_logs_data'                   => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_get_file_logs_data',
			),
			'tailwatch_delete_comparison_by_id'              => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_delete_comparison_by_id',
			),
			'tailwatch_get_files_log_by_id'                  => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_get_files_log_by_id',
			),
			'tailwatch_get_file_integrity_status'            => array(
				'controller' => new IntegrityWatchController(),
				'method'     => 'tailwatch_get_file_integrity_status',
			),

			// Hardening Audit
			'tailwatch_start_hardening_audit'                => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_start_hardening_audit',
			),
			'tailwatch_verify_hardening_audit_status'        => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_verify_hardening_audit_status',
			),
			'tailwatch_resume_hardening_audit'               => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_resume_hardening_audit',
			),
			'tailwatch_cancel_pause_hardening_audit'         => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_cancel_pause_hardening_audit',
			),
			'tailwatch_list_hardening_audit_reports'         => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_list_hardening_audit_reports',
			),
			'tailwatch_get_hardening_audit_report_by_id'     => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_get_hardening_audit_report_by_id',
			),
			'tailwatch_delete_hardening_audit_report_by_id'  => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_delete_hardening_audit_report_by_id',
			),
			'tailwatch_get_hardening_audit_live_logs'        => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_get_hardening_audit_live_logs',
			),
			'tailwatch_hardening_audit_cron_if_failed'       => array(
				'controller' => new HardeningAuditController(),
				'method'     => 'tailwatch_hardening_audit_cron_if_failed',
			),

			'tailwatch_features_calculate_score'             => array(
				'controller' => new SecurityFeaturesVerifyController(),
				'method'     => 'tailwatch_features_calculate_score',
			),
			'tailwatch_start_security_features_process'      => array(
				'controller' => new SecurityFeaturesVerifyController(),
				'method'     => 'tailwatch_start_security_features_process',
			),
			'tailwatch_execute_security_features_cron_if_failed' => array(
				'controller' => new SecurityFeaturesVerifyController(),
				'method'     => 'tailwatch_execute_security_features_cron_if_failed',
			),

			// Redirection Rules
			'tailwatch_create_redirection_rules'             => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'tailwatch_create_redirection_rules',
			),
			'tailwatch_get_all_post_types'                   => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'tailwatch_get_all_post_types',
			),
			'tailwatch_get_posts_by_post_type'               => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'tailwatch_get_posts_by_post_type',
			),
			'tailwatch_update_redirect_rule'                 => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'tailwatch_update_redirect_rule',
			),
			'tailwatch_get_all_redirect_rules'               => array(
				'controller' => new RedirectionsManager(),
				'method'     => 'tailwatch_get_all_redirect_rules',
			),

			// Broken Link Checker
			'tailwatch_start_broken_link_checker'            => array(
				'controller' => new BrokenLinkChecker(),
				'method'     => 'tailwatch_start_broken_link_checker',
			),
			'tailwatch_broken_link_checker_live_logs'        => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'tailwatch_broken_link_checker_live_logs',
			),
			'tailwatch_resume_broken_link_checker'           => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'tailwatch_resume_broken_link_checker',
			),
			'tailwatch_cancel_pause_broken_link'             => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'tailwatch_cancel_pause_broken_link',
			),
			'tailwatch_broken_links_cron_if_failed'          => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'tailwatch_broken_links_cron_if_failed',
			),
			'tailwatch_verify_broken_link_status'            => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'tailwatch_verify_broken_link_status',
			),
			'tailwatch_get_broken_links_logs'                => array(
				'controller' => new BrokenLinkStatus(),
				'method'     => 'tailwatch_get_broken_links_logs',
			),

			// Cron Jobs Routes
			'tailwatch_get_cron_jobs_with_source'            => array(
				'controller' => new GetCronJobDetailsController(),
				'method'     => 'tailwatch_get_cron_jobs_with_source',
			),
			'tailwatch_get_schedules'                        => array(
				'controller' => new GetCronJobDetailsController(),
				'method'     => 'tailwatch_get_schedules',
			),
			'tailwatch_run_cron_job'                         => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_run_cron_job',
			),
			'tailwatch_pause_cron_job'                       => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_pause_cron_job',
			),
			'tailwatch_resume_cron_job'                      => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_resume_cron_job',
			),
			'tailwatch_delete_cron_job'                      => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_delete_cron_job',
			),
			'tailwatch_add_cron_job'                         => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_add_cron_job',
			),
			'tailwatch_edit_cron_job'                        => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_edit_cron_job',
			),
			'tailwatch_bulk_cron_action'                     => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_bulk_cron_action',
			),
			'tailwatch_add_schedule'                         => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_add_schedule',
			),
			'tailwatch_edit_schedule'                        => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_edit_schedule',
			),
			'tailwatch_delete_schedule'                      => array(
				'controller' => new CronJobManagerController(),
				'method'     => 'tailwatch_delete_schedule',
			),

			'tailwatch_get_process_monitoring_status'        => array(
				'controller' => new ProcessMonitoringController(),
				'method'     => 'tailwatch_get_process_monitoring_status',
			),

			'tailwatch_cron_healer'                          => array(
				'controller' => new CronHealer(),
				'method'     => 'tailwatch_cron_healer',
			),

			// Bulk Feature Activation
			'tailwatch_activate_features_bulk'               => array(
				'controller' => new BulkFeatureActivationController(),
				'method'     => 'tailwatch_activate_features_bulk',
			),

			// IP Management (Blacklist)
			'tailwatch_handle_get_blocked_ip_ranges'         => array(
				'controller' => new IpBlackListController(),
				'method'     => 'tailwatch_handle_get_blocked_ip_ranges',
			),
			'tailwatch_handle_add_ip_rule'                   => array(
				'controller' => new IpBlackListController(),
				'method'     => 'tailwatch_handle_add_ip_rule',
			),
			'tailwatch_handle_unblock_ip_range'              => array(
				'controller' => new IpBlackListController(),
				'method'     => 'tailwatch_handle_unblock_ip_range',
			),
			'tailwatch_handle_delete_ip_rule'                => array(
				'controller' => new IpBlackListController(),
				'method'     => 'tailwatch_handle_delete_ip_rule',
			),
			'tailwatch_handle_update_ip_rule'                => array(
				'controller' => new IpBlackListController(),
				'method'     => 'tailwatch_handle_update_ip_rule',
			),
			'tailwatch_preview_block_page'                   => array(
				'controller' => new IpManagementController(),
				'method'     => 'tailwatch_preview_block_page',
			),

			// IP Management (Whitelist)
			'tailwatch_handle_add_ip_whitelist'              => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'tailwatch_handle_add_ip_whitelist',
			),
			'tailwatch_handle_update_ip_whitelist'           => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'tailwatch_handle_update_ip_whitelist',
			),
			'tailwatch_handle_get_ip_whitelists'             => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'tailwatch_handle_get_ip_whitelists',
			),
			'tailwatch_handle_delete_ip_whitelist'           => array(
				'controller' => new IpWhiteListController(),
				'method'     => 'tailwatch_handle_delete_ip_whitelist',
			),
			'tailwatch_handle_update_country_whitelist'      => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'tailwatch_handle_update_country_whitelist',
			),
			'tailwatch_handle_add_country_whitelist'         => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'tailwatch_handle_add_country_whitelist',
			),
			'tailwatch_handle_delete_country_whitelist'      => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'tailwatch_handle_delete_country_whitelist',
			),
			'tailwatch_handle_get_country_whitelists'        => array(
				'controller' => new CountryWhiteListController(),
				'method'     => 'tailwatch_handle_get_country_whitelists',
			),

			// Login Defender (IP Protection)
			'tailwatch_handle_get_ip_activity_history'       => array(
				'controller' => new IpProtectionController(),
				'method'     => 'tailwatch_handle_get_ip_activity_history',
			),
			'tailwatch_handle_get_all_ip_activities'         => array(
				'controller' => new IpProtectionController(),
				'method'     => 'tailwatch_handle_get_all_ip_activities',
			),
			'tailwatch_handle_delete_ip_activity'            => array(
				'controller' => new IpProtectionController(),
				'method'     => 'tailwatch_handle_delete_ip_activity',
			),
			'tailwatch_handle_unblock_ip'                    => array(
				'controller' => new IpProtectionController(),
				'method'     => 'tailwatch_handle_unblock_ip',
			),

			// Feature log counts
			'tailwatch_login_defender_log_count'             => array(
				'controller' => new LoginDefenderLogCount(),
				'method'     => 'tailwatch_login_defender_log_count',
			),
			'tailwatch_ip_management_log_count'              => array(
				'controller' => new IpManagementLogCount(),
				'method'     => 'tailwatch_ip_management_log_count',
			),

		);

		return isset( $routes[ $action_type ] ) ? $routes[ $action_type ] : null;
	}
}
