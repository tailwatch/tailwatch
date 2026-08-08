<?php
namespace Tailwatch\Admin\Includes;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Models\OptionsModel;
use Tailwatch\Admin\App\Api\Controllers\Routes\AjaxRequestController;
use Tailwatch\Admin\View\Controller\InterfaceController;
use Tailwatch\Admin\App\Api\Controllers\Logs\LogActivityController;
use Tailwatch\Admin\App\Api\Controllers\Logs\MonitoringLogController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Controllers\Visit\VisitController;
use Tailwatch\Admin\App\Api\Controllers\Features\SecurityFeaturesVerifyController;
use Tailwatch\Admin\App\Api\Controllers\Visit\RecommendedFeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Ssl\SslVerificationController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Controllers\Email\EmailLogController;
use Tailwatch\Admin\App\Api\Controllers\Backup\BackupController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\DatabaseOptimizerController;
use Tailwatch\Admin\App\Api\Controllers\Database\DBOptimizer\TablesOptimizeController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Controllers\IntegrityWatch\IntegrityWatchController;
use Tailwatch\Admin\App\Api\Controllers\Email\Smtp\SmtpConfigurationController;
use Tailwatch\Admin\App\Api\Controllers\BrowserKeys\DisableKeysController;
use Tailwatch\Admin\App\Api\Controllers\FilesAndPermissions\FilesPermissionController;
use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronControl\GetCronJobDetailsController;
use Tailwatch\Admin\App\Api\Controllers\ProcessRecovery\ProcessRecoveryController;
use Tailwatch\Admin\App\Api\Controllers\Users\UserRolesController;
use Tailwatch\Admin\App\Api\Controllers\Redirections\RedirectionsManager;
use Tailwatch\Admin\App\Api\Controllers\BrokenLinkChecker\BrokenLinkChecker;
use Tailwatch\Admin\App\Api\Controllers\Settings\Reset\ResetAllFeatureController;
use Tailwatch\Admin\App\Api\Controllers\HardeningAudit\HardeningAuditController;
use Tailwatch\Admin\App\Api\Controllers\IpManagement\IpManagementController;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\AuthenticationController;
use Tailwatch\Admin\App\Api\Controllers\LoginDefender\LoginProtection\LoginProtectionController;

/**
 * Bootstraps the Tailwatch plugin — registers lifecycle hooks
 * (activation/deactivation/plugins_loaded) and instantiates every controller
 * once the autoloader is in place.
 */
class Bootstrap {

	/** Guards against double-loading when plugins_loaded fires twice. */
	private static $loaded = false;

	public function __construct() {
		register_activation_hook( TAILWATCH_PLUGIN_FILE, array( $this, 'upon_activation' ) );
		register_deactivation_hook( TAILWATCH_PLUGIN_FILE, array( $this, 'upon_deactivation' ) );
		add_action( 'plugins_loaded', array( $this, 'plugin_loaded' ) );
		add_action( 'admin_init', array( $this, 'ensure_private_storage' ) );

		require_once TAILWATCH_ADMIN_API_DIR . 'Models/OptionsModel.php';
	}

	public function plugin_loaded() {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		$this->load_autoload();
	}

	public function load_autoload() {
		$autoload_file = TAILWATCH_DIR . 'tw_autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
		} else {
			wp_die( esc_html__( 'Something went wrong, please contact plugin support.', 'tailwatch' ) );
		}
		new Deactivation();
		new OptionsModel();
		new AjaxRequestController();
		new InterfaceController();
		new LogActivityController();
		new MonitoringLogController();
		new OptionsController();
		new VisitController();
		new SecurityFeaturesVerifyController();
		new RecommendedFeaturesController();
		new SslVerificationController();
		CronJobManager::get_instance();
		new EmailLogController();
		new BackupController();
		new DatabaseOptimizerController();
		new TablesOptimizeController();
		new SearchReplaceController();
		new IntegrityWatchController();
		new SmtpConfigurationController();
		new DisableKeysController();
		new FilesPermissionController();
		new GetCronJobDetailsController();
		new ProcessRecoveryController();
		new UserRolesController();
		new RedirectionsManager();
		new BrokenLinkChecker();
		new ResetAllFeatureController();
		new HardeningAuditController();
		new IpManagementController();
		new AuthenticationController();
		new LoginProtectionController();
	}

	/**
	 * Activation gate — runs version checks (PHP, WP, WP-Cron), generates the
	 * verification secret key, sets up DB tables, then flushes rewrite rules.
	 * Any failing precondition deactivates the plugin and dies with a message.
	 */
	public function upon_activation() {
		$autoload_file = TAILWATCH_DIR . 'tw_autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
		} else {
			wp_die( esc_html__( 'Something went wrong, please contact plugin support.', 'tailwatch' ) );
		}

		$options_model = new OptionsModel();
		$options_model->tailwatch_upon_activation();

		if ( version_compare( PHP_VERSION, TAILWATCH_PHP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( TAILWATCH_PLUGIN_FILE ) );

			$error_message = sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version, 3: Plugin page URL */
				__(
					'<strong><u>WP Tail Watch:</u></strong><br/><br/>This plugin cannot be activated because it requires <strong>PHP %1$s</strong> or <strong>Higher</strong>. Your current <strong>PHP Version</strong> is <strong>%2$s</strong>.
                    <br/><br/>Please contact your hosting provider to upgrade your PHP version, or <a href="https://dashboard.wptailwatch.com/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=activation_php_notice" target="_blank" title="Open a Support Ticket">Open a Support Ticket</a> to get further assistance.
                    <br/><br/><a href="%3$s" title="Back to Plugins Page">Back to Plugins Page</a>',
					'tailwatch'
				),
				TAILWATCH_PHP_VERSION,
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

		if ( version_compare( get_bloginfo( 'version' ), TAILWATCH_WP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( TAILWATCH_PLUGIN_FILE ) );

			$error_message = sprintf(
				/* translators: 1: Required WordPress version, 2: Current WordPress version, 3: Plugin page URL */
				__( '<strong><u>WP Tail Watch:</u></strong><br/><br/>This plugin cannot be activated because it requires <strong>WordPress %1$s</strong> or <strong>Higher</strong>. Your current <strong>WordPress Version</strong> is <strong>%2$s</strong>. Please contact your developer, your hosting provider, or <a href="https://dashboard.wptailwatch.com/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=activation_wp_notice" target="_blank" title="Open a Support Ticket">Open a Support Ticket</a> to fix this issue. <br/><br/><a href="%3$s" title="Back to Plugins Page">Back to Plugins Page</a>', 'tailwatch' ),
				TAILWATCH_WP_VERSION,
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

		// Create the private uploads folder for logs and generated data and seal it
		// with deny files so its contents are not reachable over the web.
		\Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService::ensure_private_root( TAILWATCH_LOGS_DIRECTORY );

		// Seal the backup storage root the same way. Backup archives live under
		// wp-content (the sanctioned location for backup plugins); the deny files
		// keep those archives from being reachable by direct URL.
		\Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService::ensure_private_root( TAILWATCH_BACKUP_DIR );

		$this->rewrite_rules();
	}

	/**
	 * Keep the private storage roots sealed. Hooked to admin_init but throttled
	 * with a transient — WordPress's standard mechanism for not repeating
	 * filesystem work on every request — so the on-disk check runs at most once
	 * per day rather than on every admin page load. The deny files are dropped
	 * when each directory is created (activation and at mkdir time); this pass
	 * is only a periodic self-heal that restores them if they are later removed.
	 * Covers both the logs storage (in uploads) and the backup storage (in
	 * wp-content).
	 *
	 * @return void
	 */
	public function ensure_private_storage() {
		// Skip the filesystem check when it was verified recently. Keyed to the
		// plugin version so an update (which may change the deny-file contents)
		// forces a fresh verification.
		$sealed_flag = 'tailwatch_storage_sealed_' . TAILWATCH_VERSION;
		if ( get_transient( $sealed_flag ) ) {
			return;
		}

		$roots = array();
		if ( defined( 'TAILWATCH_LOGS_DIRECTORY' ) ) {
			$roots[] = TAILWATCH_LOGS_DIRECTORY;
		}
		if ( defined( 'TAILWATCH_BACKUP_DIR' ) ) {
			$roots[] = TAILWATCH_BACKUP_DIR;
		}

		$all_sealed = true;
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			if ( ! \Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService::is_protected( $root ) ) {
				\Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService::protect_existing( $root );
				if ( ! \Tailwatch\Admin\App\Api\Services\Common\SecureDirectoryService::is_protected( $root ) ) {
					$all_sealed = false;
				}
			}
		}

		// Cache the verified state only when every existing root is sealed, so a
		// transient write/permission failure is retried on the next admin load.
		if ( $all_sealed ) {
			set_transient( $sealed_flag, 1, DAY_IN_SECONDS );
		}
	}

	public function upon_deactivation() {
		$autoload_file = TAILWATCH_DIR . 'tw_autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
		} else {
			wp_die( esc_html__( 'Something went wrong, please contact plugin support.', 'tailwatch' ) );
		}

		// Always unschedule the plugin's WP-Cron events on deactivation, independent of the
		// user's "delete data" choice. These events are recurring and self-reschedule, so
		// leaving them behind keeps firing orphaned hooks after the plugin is deactivated.
		// Reactivation re-registers them via the normal scheduling path.
		CronJobManager::clear_all_cron_events();

		$deactivate = new Deactivation();
		$deactivate->tailwatch_remove_plugin_data();

		$this->rewrite_rules();
	}

	private function rewrite_rules() {
		flush_rewrite_rules();
	}
}