<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Migration
 */

namespace Tailwatch\Admin\App\Api\Controllers\Migration;

use Tailwatch\Admin\App\Api\Controllers\Features\FeaturesController;
use Tailwatch\Admin\App\Api\Controllers\Visit\RecommendedFeaturesController;
use Tailwatch\Admin\App\Api\Controllers\SearchReplace\SearchReplaceController;
use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationImportController {

	public function wptw_search_replace_after_migration( $site_url, $process_run = 'migrator', $tables = null ) {
		$this->wptw_enable_search_replace();
		$search  = preg_replace( '/^https?:\/\//i', '', $site_url );
		$replace = preg_replace( '/^https?:\/\//i', '', get_site_url() );

		$search_replace = new SearchReplaceController();

		// Caller may scope SR to a specific table list; fall back to all tables.
		if ( null === $tables || ( is_array( $tables ) && empty( $tables ) ) ) {
			$get_all_table = $search_replace->wptw_get_all_table_names();
			$tables        = $get_all_table['all_tables'];
		}

		$post_data = array(
			'search'      => $search,
			'replace'     => $replace,
			'process_run' => $process_run,
			'all_tables'  => $tables,
		);

		$response = $search_replace->wptw_start_search_replace(
			wp_json_encode( $post_data )
		);
		Log::info(
			'Search replace response received',
			array(
				'feature'  => 'migration',
				'action'   => 'search_replace_start',
				'title'    => 'Migration',
				'response' => $response,
			)
		);
	}

	private function wptw_enable_search_replace() {
		$recommended_features = new RecommendedFeaturesController();
		$features_controller  = new FeaturesController();

		$search_replace = $recommended_features->wptw_get_feature_id( 'default_search_replace' );

		$search_replace_data = array(
			'featureId' => $search_replace['id'],
			'is_active' => true,
		);
		$features_controller->wptw_update_feature_status(
			null,
			$search_replace_data,
			true
		);
	}
}