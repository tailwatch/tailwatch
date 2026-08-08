<?php
namespace Tailwatch\Admin\App\Api\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tailwatch - Deactivation Alert Template
 *
 * Handles the deactivation feedback modal and assets.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Templates
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */
class DeactivationAlert {


	/**
	 * Enqueue deactivation assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function tailwatch_enqueue_deactivate_assets( $hook ) {
		if ( 'plugins.php' !== $hook ) {
			return;
		}

		// Register and enqueue styles.
		wp_enqueue_style(
			'tailwatch-plugin-deactivate-style',
			TAILWATCH_ADMIN_ASSETS_URI . 'css/tailwatch-deactivate.css',
			array(),
			TAILWATCH_VERSION
		);

		wp_enqueue_script(
			'tailwatch-plugin-deactivate-script',
			TAILWATCH_ADMIN_ASSETS_URI . 'js/tailwatch-deactivate.js',
			array( 'jquery' ),
			TAILWATCH_VERSION,
			true
		);

		wp_localize_script(
			'tailwatch-plugin-deactivate-script',
			'tailwatch_ajax',
			array(
				'ajax_url'               => esc_url( admin_url( 'admin-ajax.php' ) ),
				'nonce'                  => wp_create_nonce( 'tailwatch_nonce' ),
				'delete_data_action'     => 'tailwatch_delete_plugin_data',
				'submit_feedback_action' => 'tailwatch_submit_deactivation_feedback',
				'site_domain'            => esc_js( wp_parse_url( TAILWATCH_GET_SITE_URL, PHP_URL_HOST ) ),
				'documentation_url'      => esc_url( 'https://doc.wptailwatch.com/' ),
				'support_url'            => esc_url( 'https://wptailwatch.com/contact/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=deactivation_support' ),
				'settings_url'           => esc_url( admin_url( 'admin.php?page=tailwatch-settings' ) ),
			)
		);
	}

	/**
	 * Add deactivation popup modal.
	 *
	 * @return void
	 */
	public function tailwatch_add_deactivate_popup() {
		$current_screen = get_current_screen();

		if ( ! $current_screen || 'plugins' !== $current_screen->id ) {
			return;
		}
		?>
		<div class="tailwatch_modal-overlay" id="deleteModal">
			<div class="tailwatch_modal-container">
				<div class="tailwatch_modal-header">
					<div class="tailwatch_icon">⚠️</div>
					<h3><?php esc_html_e( 'Hold on! Before you go...', 'tailwatch' ); ?></h3>
					<button class="tailwatch_modal-close" type="button"
						aria-label="<?php esc_attr_e( 'Close modal', 'tailwatch' ); ?>">×</button>
				</div>
				<div class="tailwatch_modal-body">
					<form id="deleteForm">
						<div class="tailwatch_form-group">
							<label
								for="deactivation-reason"><?php esc_html_e( 'Why are you deactivating this plugin? *', 'tailwatch' ); ?></label>
							<select id="deactivation-reason" name="deactivation_reason" required>
								<option value=""><?php esc_html_e( 'Select a reason...', 'tailwatch' ); ?></option>
								<option value="not-needed"><?php esc_html_e( 'No longer needed', 'tailwatch' ); ?></option>
								<option value="found-better"><?php esc_html_e( 'Found a better alternative', 'tailwatch' ); ?>
								</option>
								<option value="bugs-issues"><?php esc_html_e( 'Experiencing bugs or issues', 'tailwatch' ); ?>
								</option>
								<option value="too-complex"><?php esc_html_e( 'Too complex or hard to use', 'tailwatch' ); ?>
								</option>
								<option value="performance"><?php esc_html_e( 'Performance issues', 'tailwatch' ); ?></option>
								<option value="compatibility"><?php esc_html_e( 'Compatibility problems', 'tailwatch' ); ?>
								</option>
								<option value="temporary-removal">
									<?php esc_html_e( 'Temporary removal for testing', 'tailwatch' ); ?>
								</option>
								<option value="other"><?php esc_html_e( 'Other reason', 'tailwatch' ); ?></option>
							</select>
						</div>
						<div class="tailwatch_form-group">
							<label for="comments"><?php esc_html_e( 'Additional comments (optional)', 'tailwatch' ); ?></label>
							<textarea id="comments" name="comments"
								placeholder="<?php esc_attr_e( 'Please share any specific feedback that could help us improve our plugin...', 'tailwatch' ); ?>"></textarea>
						</div>
						<div class="tailwatch_form-group">
							<label><?php esc_html_e( 'What should we do with your plugin data?', 'tailwatch' ); ?></label>
							<div class="tailwatch_data-options">
								<div class="tailwatch_option">
									<input type="radio" id="keep-data" name="data_handling" value="keep" checked>
									<div>
										<label
											for="keep-data"><?php esc_html_e( 'Keep my data (recommended)', 'tailwatch' ); ?></label>
										<div class="tailwatch_option-description">
											<?php esc_html_e( 'Your settings and data will be preserved for future use - you can always reactivate later', 'tailwatch' ); ?>
										</div>
									</div>
								</div>
								<div class="tailwatch_option">
									<input type="radio" id="remove-data" name="data_handling" value="remove">
									<div>
										<label for="remove-data"><?php esc_html_e( 'Remove all data', 'tailwatch' ); ?></label>
										<div class="tailwatch_option-description">
											<?php esc_html_e( 'Completely remove all plugin data and settings (this action cannot be undone)', 'tailwatch' ); ?>
										</div>
									</div>
								</div>
							</div>
						</div>
						<div class="tailwatch_form-group">
							<label><?php esc_html_e( 'Need help before deactivating?', 'tailwatch' ); ?></label>
							<div class="tailwatch_help-section">
								<div class="tailwatch_help-links">
									<a href="#" class="tailwatch-help-link" data-action="documentation">
										<div class="tailwatch_icon">📚</div>
										<?php esc_html_e( 'Documentation', 'tailwatch' ); ?>
									</a>
									<a href="#" class="tailwatch-help-link" data-action="support">
										<div class="tailwatch_icon">💬</div>
										<?php esc_html_e( 'Help Support', 'tailwatch' ); ?>
									</a>
								</div>
							</div>
						</div>
					</form>
				</div>
				<div class="tailwatch_modal-footer">
					<div class="tailwatch_button-group">
						<button type="button" class="tailwatch_button tailwatch_button-cancel tailwatch_button-skip-deactivate"
							id="tailwatch-skip-deactivate"><?php esc_html_e( 'Skip & Deactivate', 'tailwatch' ); ?></button>
						<button type="button" class="tailwatch_button tailwatch_button-secondary"
							id="tailwatch-deactivate"><?php esc_html_e( 'Submit & Deactivate', 'tailwatch' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}