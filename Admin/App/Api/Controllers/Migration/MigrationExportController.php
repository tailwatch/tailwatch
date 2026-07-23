<?php
// phpcs:ignoreFile WordPress.Files.FileName -- Legacy controller filename.
/**
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\Migration
 */

namespace Tailwatch\Admin\App\Api\Controllers\Migration;

use Tailwatch\Admin\App\Api\Models\DBModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MigrationExportController {

	public function wptw_return_maintain_backup( array $maintain_backups ) {
		$json_data = array();
		$parts     = 0;

		foreach ( $maintain_backups as $backup_id ) {
			$feature_controller = new DBModel();
			$value              = $feature_controller->get_data_by_id( $backup_id );

			$parts = $parts + 1;
			$json_data[ 'backup_' . $parts ] = $value;
		}

		return $json_data;
	}
}