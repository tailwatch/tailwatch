<?php
/**
 * Reset Feature by Option Controller
 *
 * Handles resetting individual plugin features to their default values.
 * Can optionally keep the feature active after reset.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Settings/Reset
 */

namespace Tailwatch\Admin\App\Api\Controllers\Settings\Reset;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\OptionsModel;
use Tailwatch\Admin\App\Api\Controllers\Features\FeatureCacheController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\ProcessGuard;

/**
 * Class ResetByFeatureOptionController
 *
 * Provides functionality to reset plugin features to default configuration.
 *
 */
class ResetByFeatureOptionController {

	/**
	 * Reset a specific feature to its default values.
	 *
	 * Resets a feature option to default configuration from OptionsModel.
	 * Can optionally keep the feature in active state after reset.
	 *
	 * @param string $post_data JSON encoded data containing feature_option and remain_active flag.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type array  $feature_data Reset feature data (empty on error).
	 *     @type int    $code         HTTP response code (200, 400, or 500).
	 *     @type string $message      Response message.
	 * }
	 */
	public function wptw_reset_feature_by_option( $post_data ) {
		$feature_option = null;
		$remain_active  = false;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$feature_option = isset( $data['feature_option'] ) ? sanitize_text_field( $data['feature_option'] ) : null;
			$remain_active  = isset( $data['remain_active'] ) ? (bool) $data['remain_active'] : false;

			if ( empty( $feature_option ) ) {
				Log::warning(
					'Feature reset failed: No feature option provided',
					array(
						'feature'   => 'features',
						'action'    => 'feature_reset_failed',
						'title'     => 'Feature Reset Failed',
						'error'     => 'No feature_option provided',
						'meta_data' => array(
							'feature' => 'Feature Reset',
							'event'   => 'Failed',
							'reason' => 'Missing required information',
						),
					)
				);
				return array(
					'feature_data' => array(),
					'code'         => 400,
					'message'      => __( 'No feature_option provided.', 'tailwatch' ),
				);
			}

			// Block per-feature reset while a process that locks this feature
			// is running, or while a system-wide op (settings_import, reset_all)
			// is mid-flight. Same gate as the feature-save endpoint — resetting
			// a row mid-run is just as disruptive as editing it.
			$blocked = ( new ProcessGuard() )->ensure_can_modify_feature( $feature_option );
			if ( null !== $blocked ) {
				return $blocked;
			}

			$db_model      = new DBModel();
			$options_model = new OptionsModel();
			$defaults      = $options_model->wptw_complete_site_data();

			if ( ! isset( $defaults[ $feature_option ] ) ) {
				Log::error(
					'Feature reset failed: Feature not found',
					array(
						'feature'        => 'features',
						'action'         => 'feature_reset_failed',
						'title'          => 'Feature Reset Failed',
						'feature_option' => $feature_option,
						'error'          => 'Feature not found in defaults',
						'meta_data'      => array(
							'feature' => 'Feature Reset',
							'event'   => 'Failed',
							'feature_option' => $feature_option,
							'reason'         => 'Feature not found',
						),
					)
				);
				return array(
					'feature_data' => array(),
					'code'         => 400,
					'message'      => __( 'Feature not found.', 'tailwatch' ),
				);
			}

			$row = $defaults[ $feature_option ];

			// Keep feature active if requested.
			if ( $remain_active ) {
				$row['type_state'] = 'active';
				$row['is_active']  = 1;
			}

			$where = array(
				'key'    => $row['key'],
				'option' => $row['option'],
			);

			// Try to update existing row, insert if update fails.
			$updated = $db_model->update_rows( $row, $where );

			if ( ! $updated ) {
				$format   = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
				$inserted = $db_model->insert_row( $row, $format );

				if ( ! $inserted ) {
					return array(
						'feature_data' => array(),
						'code'         => 400,
						'message'      => __( 'Failed to reset feature.', 'tailwatch' ),
					);
				}
			}

			// Invalidate feature cache so subsequent reads pick up the reset state
			// instead of serving the pre-reset values from the 1-hour cache.
			// Also clear OptionsController's static request cache so the
			// reapply-locks action below reads fresh DB data instead of the
			// pre-reset value held in memory.
			try {
				$cache = new FeatureCacheController();
				$cache->invalidate_feature_cache( $row['key'], $row['option'] );
				OptionsController::invalidate_request_cache( $row['key'], $row['option'] );
			} catch ( \Throwable $e ) {
				// Silently fail cache invalidation — DB write already succeeded.
			}

			// Allow extensions (e.g. the pro plugin) to reapply any post-reset
			// state to the row we just rewrote. No-op when no listener is
			// hooked. The `$feature_option` arg lets listeners surgically
			// re-apply state for just this row instead of walking every
			// extension target — significant on bulk activation, which
			// calls this method N times. Backward-compatible: listeners
			// registered without `accepted_args` ignore the extra arg.
			do_action( 'wptw_apply_plan_features_to_db', $feature_option );

			$feature_data = $db_model->get_value( $row['option'], $row['key'] );

			// Log successful reset.
			Log::info(
				"Feature reset successfully: {$feature_option}",
				array(
					'feature'        => 'features',
					'action'         => 'feature_reset_completed',
					'feature_option' => $feature_option,
					'remain_active'  => $remain_active,
					'title'          => 'Feature Reset',
				)
			);

			return array(
				'feature_data' => $feature_data ? $feature_data : array(),
				'code'         => 200,
				'message'      => __( 'Feature has been reset to default.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Feature reset failed: Exception occurred',
				array(
					'feature'        => 'features',
					'action'         => 'feature_reset_failed',
					'title'          => 'Feature Reset Failed',
					'feature_option' => $feature_option,
					'remain_active'  => $remain_active,
					'error'          => $e->getMessage(),
					'exception'      => $e,
					'meta_data'      => array(
						'feature' => 'Feature Reset',
						'event'   => 'Failed',
						'feature_option' => (string) $feature_option,
						'reason'         => 'Unexpected error',
					),
				)
			);

			return array(
				'feature_data' => array(),
				'code'         => 500,
				'message'      => __( 'An error occurred while resetting the feature.', 'tailwatch' ),
			);
		}
	}
}
