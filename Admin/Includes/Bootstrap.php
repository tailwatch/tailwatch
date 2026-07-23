<?php
namespace Tailwatch\Admin\Includes;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Routes\Routes;
use Tailwatch\Admin\App\Api\Models\OptionsModel;
use Tailwatch\Admin\App\Api\Controllers\Routes\AjaxRequestController;
use Tailwatch\Admin\View\Controller\InterfaceController;
use Tailwatch\Admin\App\Api\Controllers\Logs\LogActivityController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\Logs\AjaxLogController;
use Tailwatch\Admin\App\Api\Controllers\Login\AutoLoginController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Visit\VisitController;
use Tailwatch\Admin\App\Api\Controllers\Features\SecurityFeaturesVerifyController;
use Tailwatch\Admin\App\Api\Controllers\Visit\RecommendedFeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Ssl\SslVerificationController;
use Tailwatch\Admin\App\Api\Controllers\RewriteRule\HtaccessManager;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Controllers\Email\EmailLogController;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerificationKeysController;
use Tailwatch\Admin\App\Api\Controllers\History\HistoryHookController;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\TablesOptimizeController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Controllers\Email\Smtp\SmtpConfigurationController;
use Tailwatch\Admin\App\Api\Controllers\BrowserKeys\DisableKeysController;
use Tailwatch\Admin\App\Api\Controllers\FilesAndPermissions\FilesPermissionController;
use Tailwatch\Admin\App\Api\Controllers\RecoveryMode\RecoveryModeController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl\GetCronJobDetailsController;
use Tailwatch\Admin\App\Api\Controllers\ProcessRecovery\ProcessRecoveryController;
use Tailwatch\Admin\App\Api\Controllers\Users\UserRolesController;
use Tailwatch\Admin\App\Api\Controllers\Redirections\RedirectionsManager;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkChecker;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetAllFeatureController;
use Tailwatch\Admin\App\Api\Controllers\LimitIncrease\PerformanceOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\AuthenticationController;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\LoginProtection\LoginProtectionController;
use Tailwatch\Admin\App\Api\Controllers\Integration\GeoIp2\GeoLiteTwoController;

/**
 * Bootstraps the Tailwatch plugin — registers lifecycle hooks
 * (activation/deactivation/plugins_loaded) and instantiates every controller
 * once the autoloader is in place.
 */
class Bootstrap {

	/** Guards against double-loading when plugins_loaded fires twice. */
	private static $loaded = false;

	public function __construct() {
		register_activation_hook( WPTW_PLUGIN_FILE, array( $this, 'upon_activation' ) );
		register_deactivation_hook( WPTW_PLUGIN_FILE, array( $this, 'upon_deactivation' ) );
		add_action( 'plugins_loaded', array( $this, 'plugin_loaded' ) );

		require_once WPTW_ADMIN_API_DIR . 'Models/OptionsModel.php';
	}

	public function plugin_loaded() {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		$this->load_autoload();
	}

	public function load_autoload() {
		$autoload_file = WPTW_DIR . 'tw_autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
			Routes::initialize();
		} else {
			wp_die( esc_html__( 'Something went wrong, please contact plugin support.', 'tailwatch' ) );
		}
		new Deactivation();
		new OptionsModel();
		new AjaxRequestController();
		new InterfaceController();
		new LogActivityController();
		new MonitoringLogController();
		new AjaxLogController();
		new AutoLoginController();
		new OptionsController();
		new VisitController();
		new SecurityFeaturesVerifyController();
		new RecommendedFeaturesController();
		new SslVerificationController();
		new HtaccessManager();
		CronJobManager::get_instance();
		new EmailLogController();
		new HistoryHookController();
		new BackupController();
		new DatabaseOptimizerController();
		new TablesOptimizeController();
		new SearchReplaceController();
		new IntegrityWatchController();
		new SmtpConfigurationController();
		new DisableKeysController();
		new FilesPermissionController();
		new RecoveryModeController();
		new GetCronJobDetailsController();
		new ProcessRecoveryController();
		new UserRolesController();
		new RedirectionsManager();
		new BrokenLinkChecker();
		new ResetAllFeatureController();
		new PerformanceOptimizerController();
		new HardeningAuditController();
		new IpManagementController();
		new AuthenticationController();
		new LoginProtectionController();
		new GeoLiteTwoController();
	}

	/**
	 * Activation gate — runs version checks (PHP, WP, WP-Cron), generates the
	 * verification secret key, sets up DB tables, then flushes rewrite rules.
	 * Any failing precondition deactivates the plugin and dies with a message.
	 */
	public function upon_activation() {
		$autoload_file = WPTW_DIR . 'tw_autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
			Routes::initialize();
		} else {
			wp_die( esc_html__( 'Something went wrong, please contact plugin support.', 'tailwatch' ) );
		}

		$verification_controller = new VerificationKeysController();
		$verification_controller->wptw_generate_secret_key();

		$options_model = new OptionsModel();
		$options_model->wptw_upon_activation();

		if ( version_compare( PHP_VERSION, WPTW_PHP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( WPTW_PLUGIN_FILE ) );

			$error_message = sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version, 3: Plugin page URL */
				__(
					'<strong><u>WP Tail Watch:</u></strong><br/><br/>This plugin cannot be activated because it requires <strong>PHP %1$s</strong> or <strong>Higher</strong>. Your current <strong>PHP Version</strong> is <strong>%2$s</strong>.
                    <br/><br/>Please contact your hosting provider to upgrade your PHP version, or <a href="https://dashboard.wptailwatch.com/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=activation_php_notice" target="_blank" title="Open a Support Ticket">Open a Support Ticket</a> to get further assistance.
                    <br/><br/><a href="%3$s" title="Back to Plugins Page">Back to Plugins Page</a>',
					'tailwatch'
				),
				WPTW_PHP_VERSION,
				PHP_VERSION,
				esc_url( admin_url( 'plugins.php' ) )
			);

			wp_die(
				sprintf(
					'<div class="error">%s</div>',
					wp_kses_post( $error_message )
				)
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), WPTW_WP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( WPTW_PLUGIN_FILE ) );

			$error_message = sprintf(
				/* translators: 1: Required WordPress version, 2: Current WordPress version, 3: Plugin page URL */
				__( '<strong><u>WP Tail Watch:</u></strong><br/><br/>This plugin cannot be activated because it requires <strong>WordPress %1$s</strong> or <strong>Higher</strong>. Your current <strong>WordPress Version</strong> is <strong>%2$s</strong>. Please contact your developer, your hosting provider, or <a href="https://dashboard.wptailwatch.com/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=activation_wp_notice" target="_blank" title="Open a Support Ticket">Open a Support Ticket</a> to fix this issue. <br/><br/><a href="%3$s" title="Back to Plugins Page">Back to Plugins Page</a>', 'tailwatch' ),
				WPTW_WP_VERSION,
				get_bloginfo( 'version' ),
				esc_url( admin_url( 'plugins.php' ) )
			);

			wp_die(
				sprintf(
					'<div class="error">%s</div>',
					wp_kses_post( $error_message )
				)
			);
		}

		// WP-Cron health is intentionally NOT gated at activation. DISABLE_WP_CRON,
		// ALTERNATE_WP_CRON and external cron runners (Cavalcade, Cron Control) are all
		// valid setups where scheduled tasks still run, so blocking activation would lock
		// out managed hosts. Cron health is surfaced in the setup wizard and admin notices
		// via CronHealthService instead of preventing activation.

		// Schedule the always-on maintenance events now so they run even when the plugin is
		// activated without a browser admin session (e.g. via WP-CLI) on a front-end-only site.
		// Feature-specific events stay unscheduled until their feature is enabled (their
		// settings do not exist yet); each job is isolated so one cannot break activation.
		CronJobManager::get_instance()->schedule_all( true );

		$this->rewrite_rules();
	}

	public function upon_deactivation() {
		$autoload_file = WPTW_DIR . 'tw_autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
			Routes::initialize();
		} else {
			wp_die( esc_html__( 'Something went wrong, please contact plugin support.', 'tailwatch' ) );
		}

		// Always unschedule the plugin's WP-Cron events on deactivation, independent of the
		// user's "delete data" choice. These events are recurring and self-reschedule, so
		// leaving them behind keeps firing orphaned hooks after the plugin is deactivated.
		// Reactivation re-registers them via the normal scheduling path.
		CronJobManager::clear_all_cron_events();

		$deactivate = new Deactivation();
		$deactivate->wptw_remove_plugin_data();

		$this->rewrite_rules();
	}

	private function rewrite_rules() {
		flush_rewrite_rules();
	}
}