<?php
/**
 * Tailwatch - API Routes Configuration
 *
 * Defines all API routes for the plugin.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Routes
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Routes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Routes' ) ) {

	/**
	 * Class Routes
	 *
	 * Configures and initializes all API routes for the plugin.
	 *
	 */
	class Routes {

		/**
		 * Singleton instance.
		 *
		 * @var Routes|null
		 */
		private static $instance;

		/**
		 * Initialize routes singleton.
	     *
		 * @return Routes Routes instance.
		 */
		public static function initialize() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor for singleton pattern.
		 *
		 */
		private function __construct() {
			self::execute_routes();
		}

		/**
		 * Execute and register all routes.
	     *
		 * @return Routing|null Routing instance.
		 */
		private static function execute_routes() {
			$routes = array(
				'Auth'   => array(
					'POST'   => array(
						'/v1' => 'wptw_mobile_global_handler@Routes\MobileAppController',
					),
					'GET'    => array(),
					'DELETE' => array(),
					'PUT'    => array(),
				),
				'Public' => array(
					'GET'    => array(),
					'POST'   => array(
						'/login'         => 'Login@Auth\AuthController',
						'/refresh-token' => 'refresh_token@Auth\AuthController',
					),
					'PUT'    => array(),
					'DELETE' => array(),
				),

			);
			$routing = \Tailwatch\Admin\App\Api\Routes\Routing::initialize( $routes );
			return $routing;
		}
	}
}
