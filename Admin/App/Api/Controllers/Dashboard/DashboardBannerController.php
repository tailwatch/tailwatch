<?php
namespace Tailwatch\Admin\App\Api\Controllers\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Controllers\Visit\VisitController;

/**
 * The invitation to connect a free Tailwatch account, on the WordPress Dashboard.
 *
 * Shown on that one screen only, to administrators, once Tailwatch's setup is finished and
 * while the site is not connected. It leaves for good once the site connects, and for an
 * administrator who closes it; "Remind me later" hides it for 7 days. Everything it shows
 * is bundled with the plugin, and it sends nothing anywhere: Connect opens the plugin's own
 * account page, where connecting is the administrator's choice.
 */
class DashboardBannerController {

	/**
	 * User meta: set once the administrator closes the banner for good.
	 */
	const DISMISSED_META = 'tailwatch_connect_banner_dismissed';

	/**
	 * User meta: the time until which "Remind me later" hides the banner.
	 */
	const SNOOZED_META = 'tailwatch_connect_banner_snoozed';

	/**
	 * Days "Remind me later" hides the banner for.
	 */
	const SNOOZE_DAYS = 7;

	/**
	 * The dispatcher action that records a dismissal.
	 */
	const DISMISS_ACTION = 'tailwatch_dismiss_connect_banner';

	/**
	 * Whether this request shows the banner, decided once.
	 *
	 * @var bool|null
	 */
	private $show = null;

	/**
	 * Register the banner and its assets.
	 */
	public function __construct() {
		$hook_controllers = new HookControllers();
		$hook_controllers->add_action_hook( 'admin_notices', array( $this, 'tailwatch_render_connect_banner' ) );
		$hook_controllers->add_action_hook( 'admin_enqueue_scripts', array( $this, 'tailwatch_enqueue_connect_banner_assets' ) );
	}

	/**
	 * Whether the banner belongs on this screen, for this administrator, now.
	 *
	 * @return bool
	 */
	public function tailwatch_should_show_connect_banner() {
		if ( null === $this->show ) {
			$this->show = $this->tailwatch_decide_connect_banner();
		}
		return $this->show;
	}

	/**
	 * The checks, cheapest first. None of them sends a request anywhere.
	 *
	 * @return bool
	 */
	private function tailwatch_decide_connect_banner() {
		if ( ! is_admin() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'dashboard' !== $screen->id ) {
			return false;
		}

		/**
		 * Whether Tailwatch may invite administrators to connect, on the WordPress Dashboard.
		 *
		 * @param bool $show True to allow the banner.
		 */
		if ( ! apply_filters( 'tailwatch_show_connect_banner', true ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, self::DISMISSED_META, true ) ) {
			return false;
		}
		if ( (int) get_user_meta( $user_id, self::SNOOZED_META, true ) > time() ) {
			return false;
		}

		return $this->tailwatch_setup_is_finished() && ! $this->tailwatch_site_is_connected();
	}

	/**
	 * Whether Tailwatch's setup has been gone through. Before it has, the setup record does
	 * not exist and nothing is read or written.
	 *
	 * @return bool
	 */
	private function tailwatch_setup_is_finished() {
		if ( false === get_option( TAILWATCH_VISIT_DATA ) ) {
			return false;
		}
		$progress = ( new VisitController() )->tailwatch_verify_visit_progress();
		return is_array( $progress ) && ! empty( $progress['is_completed'] );
	}

	/**
	 * Whether this site is connected to a Tailwatch account, from the stored connection.
	 *
	 * @return bool
	 */
	private function tailwatch_site_is_connected() {
		$status = ( new VerifyStatusController() )->get_cached_license_status();
		return ! empty( $status['connection_status'] );
	}

	/**
	 * Load the banner's style and script on the Dashboard, only when it is shown.
	 *
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public function tailwatch_enqueue_connect_banner_assets( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix || ! $this->tailwatch_should_show_connect_banner() ) {
			return;
		}

		wp_enqueue_style(
			'tailwatch-connect-banner',
			TAILWATCH_ADMIN_ASSETS_URI . 'css/tailwatch-connect-banner.css',
			array(),
			TAILWATCH_VERSION
		);

		wp_enqueue_script(
			'tailwatch-connect-banner',
			TAILWATCH_ADMIN_ASSETS_URI . 'js/tailwatch-connect-banner.js',
			array(),
			TAILWATCH_VERSION,
			true
		);

		wp_localize_script(
			'tailwatch-connect-banner',
			'tailwatchConnectBanner',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tailwatch_ajax_nonce' ),
				'action'  => self::DISMISS_ACTION,
			)
		);
	}

	/**
	 * Print the banner.
	 *
	 * @return void
	 */
	public function tailwatch_render_connect_banner() {
		if ( ! $this->tailwatch_should_show_connect_banner() ) {
			return;
		}
		?>
		<div id="tailwatch-connect-banner" class="notice notice-info tailwatch-connect-banner">
			<button type="button" class="tailwatch-connect-banner__close" data-tailwatch-banner="never">
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice for good', 'tailwatch' ); ?></span>
			</button>
			<div class="tailwatch-connect-banner__body">
				<h2 class="tailwatch-connect-banner__title"><?php esc_html_e( 'Connect your free Tailwatch account', 'tailwatch' ); ?></h2>
				<p class="tailwatch-connect-banner__text"><?php esc_html_e( 'Get real-time security alerts on your phone and manage this site from the Tailwatch mobile app and dashboard. It is free.', 'tailwatch' ); ?></p>
				<p class="tailwatch-connect-banner__actions">
					<a class="button button-primary tailwatch-connect-banner__connect" href="<?php echo esc_url( admin_url( 'admin.php?page=tailwatch#/dashboard/account' ) ); ?>"><?php esc_html_e( 'Connect free account', 'tailwatch' ); ?></a>
					<button type="button" class="button-link tailwatch-connect-banner__later" data-tailwatch-banner="later"><?php esc_html_e( 'Remind me later', 'tailwatch' ); ?></button>
				</p>
			</div>
			<img class="tailwatch-connect-banner__art" src="<?php echo esc_url( TAILWATCH_ADMIN_ASSETS_URI . 'images/tailwatch-connect-banner.png' ); ?>" alt="" width="288" height="181" />
		</div>
		<?php
	}

	/**
	 * Record an administrator's choice: closed for good, or reminded later.
	 *
	 * @param string $post_data JSON-encoded POST data: {"choice":"never"|"later"}.
	 * @return array Response with code and message.
	 */
	public function tailwatch_dismiss_connect_banner( $post_data ) {
		$data    = json_decode( (string) $post_data, true );
		$choice  = ( is_array( $data ) && isset( $data['choice'] ) && is_string( $data['choice'] ) ) ? sanitize_key( $data['choice'] ) : '';
		$user_id = get_current_user_id();

		if ( ! $user_id || ! in_array( $choice, array( 'never', 'later' ), true ) ) {
			return array(
				'data'    => array(),
				'message' => __( 'Invalid input data.', 'tailwatch' ),
				'code'    => 400,
			);
		}

		if ( 'never' === $choice ) {
			update_user_meta( $user_id, self::DISMISSED_META, 1 );
		} else {
			update_user_meta( $user_id, self::SNOOZED_META, time() + self::SNOOZE_DAYS * DAY_IN_SECONDS );
		}

		return array(
			'data'    => array( 'choice' => $choice ),
			'message' => __( 'Saved.', 'tailwatch' ),
			'code'    => 200,
		);
	}
}
