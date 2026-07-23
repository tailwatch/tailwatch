<?php
namespace Tailwatch\Admin\App\Api\Controllers\Disk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DiskSpaceController
 *
 * Controller for retrieving server disk space and database usage statistics.
 *
 */
class DiskSpaceController {

	/**
	 * Format bytes to human-readable size.
	 *
	 * @param int $bytes     The size in bytes.
	 * @param int $precision Decimal precision.
	 *
	 * @return string Formatted size string.
	 */
	public static function format_bytes( $bytes, $precision = 2 ) {
		$units  = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$bytes  = max( $bytes, 0 );
		$pow    = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow    = min( $pow, count( $units ) - 1 );
		$bytes /= pow( 1024, $pow );
		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Get disk and database usage information.
	 *
	 * Aggregates file system and database size metrics.
	 *
	 * @return array Usage information with status code.
	 */
	public function wptw_disk_and_db_usage() {
		try {
			$file_sizes    = $this->calculate_directory_sizes();
			$database_info = $this->get_database_info();

			$total_site_size = $file_sizes['files_size'] + $database_info['db_size'];

			return array(
				'message'         => __( 'Disk and database usage calculated successfully.', 'tailwatch' ),
				'files'           => $file_sizes,
				'database'        => $database_info,
				'total_site_size' => self::format_bytes( $total_site_size ),
				'code'            => 200,
			);
		} catch ( \Throwable $e ) {
			return array(
				'message'         => __( 'Failed to calculate disk and database usage.', 'tailwatch' ),
				'files'           => array(),
				'database'        => array(),
				'total_site_size' => '0 B',
				'code'            => 500,
			);
		}
	}

	/**
	 * Calculate WordPress directory sizes.
	 *
	 * Calculates sizes for root, wp-admin, wp-includes, wp-content, plugins, themes, and uploads.
	 *
	 * @return array Directory sizes and totals.
	 */
	public function calculate_directory_sizes() {
		try {
			$upload_dir = wp_upload_dir();

			// Calculate sizes using calculate_folder_size method.
			$root        = self::calculate_folder_size( ABSPATH );
			$wp_admin    = self::calculate_folder_size( ABSPATH . 'wp-admin' );
			$wp_includes = self::calculate_folder_size( ABSPATH . WPINC );
			$wp_content  = self::calculate_folder_size( WP_CONTENT_DIR );
			$plugins     = self::calculate_folder_size( WP_PLUGIN_DIR );
			$themes      = self::calculate_folder_size( get_theme_root() );
			$uploads     = self::calculate_folder_size( $upload_dir['basedir'] );

			$root_size       = $root - $wp_admin - $wp_content - $wp_includes;
			$wp_content_size = $wp_content - $plugins - $themes - $uploads;

			$root_size       = max( 0, $root_size );
			$wp_content_size = max( 0, $wp_content_size );

			$total_site_size = $root_size + $wp_admin + $wp_includes + $wp_content_size + $plugins + $themes + $uploads;

			return array(
				'root'            => self::format_bytes( $root_size ),
				'wp-admin'        => self::format_bytes( $wp_admin ),
				'wp-includes'     => self::format_bytes( $wp_includes ),
				'wp-content'      => self::format_bytes( $wp_content_size ),
				'plugins'         => self::format_bytes( $plugins ),
				'themes'          => self::format_bytes( $themes ),
				'uploads'         => self::format_bytes( $uploads ),
				'total_site_size' => self::format_bytes( $total_site_size ),
				'files_size'      => $total_site_size,
			);
		} catch ( \Throwable $e ) {
			return array(
				'root'            => '0 B',
				'wp-admin'        => '0 B',
				'wp-includes'     => '0 B',
				'wp-content'      => '0 B',
				'plugins'         => '0 B',
				'themes'          => '0 B',
				'uploads'         => '0 B',
				'total_site_size' => '0 B',
				'files_size'      => 0,
			);
		}
	}

	/**
	 * Calculate folder size using WordPress native functions.
	 *
	 * @param string $directory Directory path.
	 *
	 * @return int Size in bytes.
	 */
	public static function calculate_folder_size( $directory ) {
		clean_dirsize_cache( $directory );
		return get_dirsize( $directory ) ? : 0;
	}

	/**
	 * Get database size and table information.
	 *
	 * Retrieves size and row count for all tables in the database.
	 *
	 * @return array Database information including size and table details.
	 */
	public function get_database_info() {
		try {
			global $wpdb;

			$database_info = array(
				'total_size'  => 0,
				'table_count' => 0,
				'tables'      => array(),
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// SHOW TABLE STATUS requires direct query. Real-time data needed, caching would show stale information.
			$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( null === $tables ) {
				throw new \Exception( 'Failed to retrieve database tables' );
			}

			if ( ! empty( $tables ) ) {
				$database_info['table_count'] = count( $tables );

				foreach ( $tables as $table ) {
					$data_length                  = absint( $table['Data_length'] );
					$index_length                 = absint( $table['Index_length'] );
					$table_size                   = $data_length + $index_length;
					$database_info['total_size'] += $table_size;

					$database_info['tables'][] = array(
						'name' => sanitize_text_field( $table['Name'] ),
						'size' => self::format_bytes( $table_size ),
						'rows' => absint( $table['Rows'] ),
					);
				}
			}

			$database_info['db_size']    = $database_info['total_size'];
			$database_info['total_size'] = self::format_bytes( $database_info['total_size'] );

			return $database_info;

		} catch ( \Throwable $e ) {
			return array(
				'total_size'  => '0 B',
				'table_count' => 0,
				'tables'      => array(),
				'db_size'     => 0,
			);
		}
	}
}
