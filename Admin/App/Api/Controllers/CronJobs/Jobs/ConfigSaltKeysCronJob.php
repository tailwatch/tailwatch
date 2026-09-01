<?php
// phpcs:disable WordPress.Files.FileName -- Legacy controller filename.
/**
 * Security Keys Rotation Cron Job
 *
 * Handles scheduled regeneration of WordPress security keys (AUTH_KEY, SECURE_AUTH_KEY, etc.).
 *
 * @package    Tailwatch
 * @subpackage Controllers/CronJobs/Jobs
 */

namespace Tailwatch\Admin\App\Api\Controllers\CronJobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\AbstractCronJob;
use Tailwatch\Admin\App\Api\Controllers\RewriteRule\ConfigGenerateKeyController;

/**
 * Class ConfigSaltKeysCronJob
 *
 * Cron job for the Security Keys Rotation feature:
 * - Regenerates WordPress security keys on a schedule
 * - Supports configurable rotation intervals
 *
 * Controller is lazy-loaded only when needed.
 */
class ConfigSaltKeysCronJob extends AbstractCronJob {

	/**
	 * Config Generate Key Controller instance (lazy loaded).
	 *
	 * @var ConfigGenerateKeyController|null
	 */
	private $config_controller = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cron_hook_name   = 'tailwatch_generate_keys_in_config';
		$this->schedule_name    = 'tailwatch_config_keys';
		$this->default_interval = '15 Days';

		parent::__construct();
	}

	/**
	 * Get config controller instance (lazy loading).
	 *
	 * @return ConfigGenerateKeyController
	 */
	private function get_config_controller() {
		if ( null === $this->config_controller ) {
			$this->config_controller = new ConfigGenerateKeyController();
		}
		return $this->config_controller;
	}

	/**
	 * Get feature settings.
	 *
	 * @return array Feature settings array.
	 */
	protected function get_feature_settings() {
		return $this->get_config_controller()->get_features_options();
	}

	/**
	 * Get human-readable schedule display name.
	 *
	 * @return string Display name.
	 */
	protected function get_schedule_display_name() {
		return esc_html__( 'Security Keys Rotation', 'tailwatch' );
	}

	/**
	 * Execute the config salt keys regeneration cron job.
	 *
	 * @return void
	 */
	public function execute() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		/**
		 * Fires before the config salt keys regeneration cron executes.
		 */
		do_action( 'tailwatch_before_config_keys_cron_execute' );

		// Regenerate security keys (controller is lazy loaded here).
		$result = $this->get_config_controller()->generate_security_keys_callback();

		/**
		 * Fires after the config salt keys regeneration cron executes.
		 *
		 * @param mixed $result The result of key regeneration.
		 */
		do_action( 'tailwatch_after_config_keys_cron_execute', $result );
	}
}
