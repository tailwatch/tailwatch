<?php
/**
 * GeoLite2 Database Update Cron Job
 *
 * Runs weekly to check whether MaxMind has released a new GeoLite2-Country
 * database. Downloads the latest archive, extracts it, and compares the MD5
 * of the new .mmdb against the currently installed file. Replaces the file
 * only when the hashes differ.
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\Integration\GeoIp2\GeoLiteTwoController;
use Tailwatch\Admin\App\Api\Controllers\Integration\IntegrationController;
use Tailwatch\Admin\App\Api\Logging\Log;

class GeoLiteUpdateCronJob extends AbstractCronJob {

	/**
	 * GeoLiteTwoController instance (lazy loaded).
	 *
	 * @var GeoLiteTwoController|null
	 */
	private $geo_lite_controller = null;

	public function __construct() {
		$this->cron_hook_name   = 'wptw_geo_lite_update_cron';
		$this->schedule_name    = 'wptw_geo_lite_update_schedule';
		$this->default_interval = 'Weekly';

		parent::__construct();
	}

	private function get_geo_lite_controller() {
		if ( null === $this->geo_lite_controller ) {
			$this->geo_lite_controller = new GeoLiteTwoController();
		}
		return $this->geo_lite_controller;
	}

	/**
	 * No feature toggle — enabled state is decided by is_enabled() below.
	 */
	protected function get_feature_settings() {
		return array();
	}

	/**
	 * Enabled only when a license key is saved, the connection is marked
	 * connected, and the .mmdb file already exists (initial download done).
	 *
	 * @param bool $force_refresh Unused.
	 * @return bool
	 */
	protected function is_enabled( $force_refresh = false ) {
		$integration_controller = new IntegrationController();
		$integration_data       = $integration_controller->wptw_get_integration_data( 'maxmind' );
		$info                   = $integration_data['integration_data'] ?? array();

		return ! empty( $info['license_key'] )
			&& (bool) ( $info['is_connected'] ?? false )
			&& GeoLiteTwoController::wptw_is_geo_lite_db_file_exist();
	}

	protected function get_schedule_display_name() {
		return esc_html__( 'GeoLite2 Database Update', 'tailwatch' );
	}

	public function execute() {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			do_action( 'wptw_before_geo_lite_update_cron_execute' );

			$result = $this->get_geo_lite_controller()->wptw_check_and_update_database();

			do_action( 'wptw_after_geo_lite_update_cron_execute', $result );
		} catch ( \Throwable $e ) {
			Log::error(
				'GeoLite2 update cron job failed',
				array(
					'feature'   => 'geo_lite_update_cron',
					'action'    => 'execute_failed',
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);
		}
	}
}
