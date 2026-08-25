<?php
/**
 * History Controller
 *
 * Provides API endpoints for retrieving action history for plugins, themes, and core.
 *
 * @package    Tailwatch
 * @subpackage Controllers/History
 */

namespace Tailwatch\Admin\App\Api\Controllers\History;

use Tailwatch\Admin\App\Api\Services\Common\HistoryService;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HistoryController
 *
 * Controller for history retrieval API endpoints.
 *
 */
class HistoryController {

	/**
	 * Get all history with optional filters.
	 *
	 * Filters: item_type (plugin|theme|core), action_type, user_id, action_status, date_from, date_to, page, limit.
	 *
	 * @return array History results with pagination.
	 */
	public function tailwatch_get_history( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			// Build filter arguments.
			$args = array(
				'item_type'     => isset( $data['item_type'] ) ? sanitize_text_field( $data['item_type'] ) : null,
				'item_slug'     => isset( $data['item_slug'] ) ? sanitize_text_field( $data['item_slug'] ) : null,
				'action_type'   => isset( $data['action_type'] ) ? sanitize_text_field( $data['action_type'] ) : null,
				'user_id'       => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : null,
				'action_status' => isset( $data['action_status'] ) ? sanitize_text_field( $data['action_status'] ) : null,
				'date_from'     => isset( $data['date_from'] ) ? sanitize_text_field( $data['date_from'] ) : null,
				'date_to'       => isset( $data['date_to'] ) ? sanitize_text_field( $data['date_to'] ) : null,
				'page'          => isset( $data['page'] ) ? absint( $data['page'] ) : 1,
				'limit'         => isset( $data['limit'] ) ? absint( $data['limit'] ) : 50,
			);

			// Get history from service.
			$result = HistoryService::get_history( $args );

			Log::info(
				'History retrieved successfully',
				array(
					'feature' => 'history',
					'action'  => 'get_history',
					'filters' => array_filter( $args ),
					'total'   => $result['total'],
				)
			);

			return array(
				'message'    => __( 'History retrieved successfully.', 'tailwatch' ),
				'history'    => $result['data'],
				'pagination' => array(
					'total'       => $result['total'],
					'page'        => $result['page'],
					'limit'       => $result['limit'],
					'total_pages' => $result['total_pages'],
				),
				'code'       => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to retrieve history',
				array(
					'feature' => 'history',
					'action'  => 'get_history_error',
					'error'   => $e->getMessage(),
				)
			);

			return array(
				'message' => __( 'Failed to retrieve history.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get history for a specific plugin.
	 *
	 * @return array Plugin history results.
	 */
	public function tailwatch_get_plugin_history( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$plugin_slug = isset( $data['plugin'] ) ? sanitize_text_field( $data['plugin'] ) : '';
			$page        = isset( $data['page'] ) ? absint( $data['page'] ) : 1;
			$limit       = isset( $data['limit'] ) ? absint( $data['limit'] ) : 50;

			if ( empty( $plugin_slug ) ) {
				return array(
					'message' => __( 'Plugin slug is required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Get history from service.
			$result = HistoryService::get_item_history( 'plugin', $plugin_slug, $limit, $page );

			return array(
				'message'    => __( 'Plugin history retrieved successfully.', 'tailwatch' ),
				'plugin'     => $plugin_slug,
				'history'    => $result['data'],
				'pagination' => array(
					'total'       => $result['total'],
					'page'        => $result['page'],
					'limit'       => $result['limit'],
					'total_pages' => $result['total_pages'],
				),
				'code'       => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to retrieve plugin history',
				array(
					'feature' => 'history',
					'action'  => 'get_plugin_history_error',
					'error'   => $e->getMessage(),
				)
			);

			return array(
				'message' => __( 'Failed to retrieve plugin history.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get history for a specific theme.
	 *
	 * @return array Theme history results.
	 */
	public function tailwatch_get_theme_history( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$theme_slug = isset( $data['theme'] ) ? sanitize_text_field( $data['theme'] ) : '';
			$page       = isset( $data['page'] ) ? absint( $data['page'] ) : 1;
			$limit      = isset( $data['limit'] ) ? absint( $data['limit'] ) : 50;

			if ( empty( $theme_slug ) ) {
				return array(
					'message' => __( 'Theme slug is required.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Get history from service.
			$result = HistoryService::get_item_history( 'theme', $theme_slug, $limit, $page );

			return array(
				'message'    => __( 'Theme history retrieved successfully.', 'tailwatch' ),
				'theme'      => $theme_slug,
				'history'    => $result['data'],
				'pagination' => array(
					'total'       => $result['total'],
					'page'        => $result['page'],
					'limit'       => $result['limit'],
					'total_pages' => $result['total_pages'],
				),
				'code'       => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to retrieve theme history',
				array(
					'feature' => 'history',
					'action'  => 'get_theme_history_error',
					'error'   => $e->getMessage(),
				)
			);

			return array(
				'message' => __( 'Failed to retrieve theme history.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Get core update/rollback history.
	 *
	 * @return array Core history results.
	 */
	public function tailwatch_get_core_history( $post_data ) {
		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			$page  = isset( $data['page'] ) ? absint( $data['page'] ) : 1;
			$limit = isset( $data['limit'] ) ? absint( $data['limit'] ) : 50;

			// Get history from service.
			$result = HistoryService::get_item_history( 'core', 'WordPress', $limit, $page );

			return array(
				'message'    => __( 'Core history retrieved successfully.', 'tailwatch' ),
				'history'    => $result['data'],
				'pagination' => array(
					'total'       => $result['total'],
					'page'        => $result['page'],
					'limit'       => $result['limit'],
					'total_pages' => $result['total_pages'],
				),
				'code'       => 200,
			);

		} catch ( \Throwable $e ) {
			Log::error(
				'Failed to retrieve core history',
				array(
					'feature' => 'history',
					'action'  => 'get_core_history_error',
					'error'   => $e->getMessage(),
				)
			);

			return array(
				'message' => __( 'Failed to retrieve core history.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}
}
