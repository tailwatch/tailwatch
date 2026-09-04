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
	 * Transient key for the cached disk/database usage result.
	 */
	const USAGE_CACHE_KEY = 'tailwatch_disk_db_usage';

	/**
	 * How long a complete usage result is cached, in seconds (12 hours).
	 */
	const USAGE_CACHE_TTL = 43200;

	/**
	 * How long a partial (timed-out) result is cached before retry, in seconds (5 minutes).
	 */
	const USAGE_PARTIAL_TTL = 300;

	/**
	 * Total time budget for the file walk, in seconds. get_dirsize() returns null once
	 * this is exceeded instead of risking a fatal timeout.
	 */
	const WALK_TIME_BUDGET = 20;

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
	 * Serves a cached result on normal loads so the expensive directory walk does not
	 * run on every dashboard request (which can exhaust memory or time on large sites
	 * with a low PHP limit). Pass a `force_refresh` payload flag to recompute from disk.
	 *
	 * @param string|array|null $post_data Request payload (JSON string or array).
	 * @return array Usage information with status code.
	 */
	public function tailwatch_disk_and_db_usage( $post_data = null ) {
		$force_refresh = $this->is_force_refresh( $post_data );

		if ( ! $force_refresh ) {
			$cached = get_transient( self::USAGE_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		return $this->build_usage_response( $force_refresh );
	}

	/**
	 * Whether the request explicitly asked for a fresh recomputation.
	 *
	 * @param string|array|null $post_data Request payload.
	 * @return bool
	 */
	private function is_force_refresh( $post_data ) {
		if ( is_string( $post_data ) && '' !== $post_data ) {
			$decoded   = json_decode( $post_data, true );
			$post_data = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $post_data ) ) {
			return false;
		}
		return isset( $post_data['force_refresh'] )
			&& filter_var( $post_data['force_refresh'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Compute the usage result, cache it, and return it.
	 *
	 * Always returns code 200 with an `available` flag: a non-critical stat widget must
	 * degrade gracefully rather than surface a 500 that blocks the dashboard.
	 *
	 * @param bool $force_refresh Whether to drop cached directory sizes first.
	 * @return array
	 */
	private function build_usage_response( $force_refresh ) {
		try {
			// Give the walk headroom on hosts with a low PHP memory_limit. Helper only:
			// hard-capped hosts ignore it, so safety rests on the cache and the time bound
			// below, not on raising memory.
			wp_raise_memory_limit( 'admin' );

			$file_sizes    = $this->calculate_directory_sizes( $force_refresh );
			$database_info = $this->get_database_info();

			$available       = empty( $file_sizes['partial'] );
			$total_site_size = $file_sizes['files_size'] + $database_info['db_size'];

			$response = array(
				'message'         => __( 'Disk and database usage calculated successfully.', 'tailwatch' ),
				'files'           => $file_sizes,
				'database'        => $database_info,
				'total_site_size' => self::format_bytes( $total_site_size ),
				'available'       => $available,
				'code'            => 200,
			);

			// Cache a complete result for the full window; a partial (timed-out) walk is
			// retried much sooner so a transient spike is not frozen in.
			set_transient(
				self::USAGE_CACHE_KEY,
				$response,
				$available ? self::USAGE_CACHE_TTL : self::USAGE_PARTIAL_TTL
			);

			return $response;
		} catch ( \Throwable $e ) {
			// Never surface a 500 for a stat widget; report unavailable so the dashboard
			// keeps working and the user can still navigate.
			return array(
				'message'         => __( 'Disk and database usage is temporarily unavailable.', 'tailwatch' ),
				'files'           => array(),
				'database'        => array(),
				'total_site_size' => '0 B',
				'available'       => false,
				'code'            => 200,
			);
		}
	}

	/**
	 * Calculate WordPress directory sizes.
	 *
	 * Calculates sizes for root, wp-admin, wp-includes, wp-content, plugins, themes, and uploads.
	 *
	 * @param bool $force_refresh Whether to drop cached directory sizes before walking.
	 * @return array Directory sizes and totals, plus a `partial` flag when the walk timed out.
	 */
	public function calculate_directory_sizes( $force_refresh = false ) {
		try {
			$upload_dir = wp_upload_dir();

			// 1. Build paths cleanly using core constants and safe slash helpers
			$abspath_dir  = ABSPATH;
			$wp_admin_dir = trailingslashit( ABSPATH ) . 'wp-admin';
			$wp_inc_dir   = trailingslashit( ABSPATH ) . WPINC;
			$content_dir  = WP_CONTENT_DIR;
			$plugins_dir  = WP_PLUGIN_DIR;
			$themes_dir   = get_theme_root();
			$uploads_dir  = $upload_dir['basedir'];

			// On an explicit refresh, drop WordPress's cached directory sizes ONCE so the
			// walk recomputes from disk. On a normal (cached-miss) build we keep the cache,
			// so the overlapping directories below reuse core cached sizes instead of re-
			// walking the same files: uploads sits inside wp-content inside root, so cleaning
			// per-directory (the previous behaviour) re-walked it three times.
			if ( $force_refresh ) {
				foreach ( array( $abspath_dir, $wp_admin_dir, $wp_inc_dir, $content_dir, $plugins_dir, $themes_dir, $uploads_dir ) as $dir ) {
					clean_dirsize_cache( $dir );
				}
			}

			// 2. Calculate sizes, bounded by a shared time budget. get_dirsize() returns null
			// once the budget is exceeded ("give up instead of risking a fatal timeout"),
			// which we record as a partial (unavailable) result rather than a hard failure.
			// calculate_folder_size() is intentionally NOT used here: it is a public helper
			// the Backup controller relies on to return a plain int.
			$deadline = time() + self::WALK_TIME_BUDGET;
			$partial  = false;

			$measure = function ( $dir ) use ( $deadline, &$partial ) {
				$size = get_dirsize( $dir, max( 1, $deadline - time() ) );
				if ( null === $size ) {
					$partial = true;
					return 0;
				}
				return (int) $size;
			};

			$root        = $measure( $abspath_dir );
			$wp_admin    = $measure( $wp_admin_dir );
			$wp_includes = $measure( $wp_inc_dir );
			$wp_content  = $measure( $content_dir );
			$plugins     = $measure( $plugins_dir );
			$themes      = $measure( $themes_dir );
			$uploads     = $measure( $uploads_dir );

			$root_size       = max( 0, $root - $wp_admin - $wp_content - $wp_includes );
			$wp_content_size = max( 0, $wp_content - $plugins - $themes - $uploads );

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
				'partial'         => $partial,
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
				'partial'         => true,
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
