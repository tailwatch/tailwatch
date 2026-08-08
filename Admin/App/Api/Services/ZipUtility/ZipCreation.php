<?php
/**
 * ZIP Creation Service
 *
 * Creates ZIP archives using ZipArchive (primary) or WordPress PclZip (fallback).
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\ZipUtility
 */

namespace Tailwatch\Admin\App\Api\Services\ZipUtility;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ZipCreation
 *
 */
class ZipCreation {

	/**
	 * Filesystem instance.
	 *
	 * @var \WP_Filesystem_Base|false
	 */
	private $filesystem;

	public function __construct() {
		$this->filesystem = FilesystemService::get_filesystem();
	}

	/**
	 * Global function to create a ZIP archive using ZipArchive or PclZip.
	 *
	 * @param array|string $source          Single file path or array of files/directories to zip.
	 * @param string       $destination     Path to save the ZIP file.
	 * @param string       $path_prefix     Prefix for paths in the ZIP (e.g., 'wp-content/plugins').
	 * @param array        $exclude         Array of paths to exclude (optional).
	 * @param int          $start_size      Minimum cumulative size to start including files (bytes, optional).
	 * @param int|null     $end_size        Maximum cumulative size to stop including files (bytes, optional).
	 * @param string|null  $log_file        Path to log file for debugging (optional - DEPRECATED).
	 * @param bool         $skip_size_limit Skip the 50MB file size limit (optional, default false).
	 * @return bool True on success, false on failure.
	 */
	public function tailwatch_create_zip_global( $source, $destination, $path_prefix = '', $exclude = array(), $start_size = 0, $end_size = null, $log_file = null, $skip_size_limit = false ) {
		if ( ! $this->filesystem ) {
			Log::error(
				'Filesystem not initialized for ZipCreation.',
				array(
					'feature' => 'zip',
					'action'  => 'zip_creation_failed',
				)
			);
			return false;
		}

		try {
			// Validate inputs.
			if ( ( is_array( $source ) && empty( $source ) ) || ( ! is_array( $source ) && ( ! is_string( $source ) || ! $this->filesystem->is_readable( $source ) ) ) || empty( $destination ) || ! is_string( $destination ) ) {
				Log::error(
					'Invalid source or destination provided for ZIP creation',
					array(
						'feature' => 'zip',
						'action'  => 'zip_creation_failed',
						'context' => 'invalid_input',
					)
				);
				return false;
			}

			// Validate path_prefix.
			if ( ! is_string( $path_prefix ) ) {
				Log::error(
					'Invalid path_prefix (expected string)',
					array(
						'feature' => 'zip',
						'action'  => 'zip_creation_failed',
					)
				);
				$path_prefix = '';
			}

			$destination_dir = dirname( $destination );
			if ( ! $this->filesystem->is_writable( $destination_dir ) ) {
				Log::error(
					"Destination directory is not writable: {$destination_dir}",
					array(
						'feature' => 'zip',
						'action'  => 'zip_creation_failed',
					)
				);
				return false;
			}

			// Normalize exclude paths.
			$exclude = array_filter(
				array_map(
					function ( $path ) {
						return is_string( $path ) ? realpath( $path ) : false;
					},
					$exclude
				)
			);

			// Try ZipArchive.
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new \ZipArchive();
				if ( true === $zip->open( $destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
					$total_size   = 0;
					$files        = is_array( $source ) ? $source : array( $source );
					$is_directory = is_array( $source ) ? false : $this->filesystem->is_dir( $source );

					if ( $is_directory ) {
						$directory_iterator = new \RecursiveIteratorIterator(
							new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
							\RecursiveIteratorIterator::SELF_FIRST
						);
						$files              = $directory_iterator;
					}

					foreach ( $files as $index => $file ) {
						$file_path = $is_directory ? ( $file instanceof \SplFileInfo ? $file->getRealPath() : $file ) : $file;

						// Validate file path.
						if ( ! is_string( $file_path ) || ! $file_path || ! $this->filesystem->exists( $file_path ) || ! $this->filesystem->is_readable( $file_path ) ) {
							continue;
						}

						$file_rel_path = $is_directory ? str_replace( $source . DIRECTORY_SEPARATOR, '', $file->getPathname() ) : basename( $file );
						$relative_path = empty( $path_prefix ) ? $file_rel_path : $path_prefix . ( empty( $file_rel_path ) ? '' : DIRECTORY_SEPARATOR . $file_rel_path );

						// Skip excluded paths.
						foreach ( $exclude as $excluded_path ) {
							if ( $excluded_path && 0 === strpos( $file_path, $excluded_path ) ) {
								continue 2;
							}
						}

						if ( $is_directory && $this->filesystem->is_dir( $file_path ) ) {
							$zip->addEmptyDir( $relative_path );
						} elseif ( $this->filesystem->is_file( $file_path ) ) {
							$file_size = $this->filesystem->size( $file_path );

							// Skip files larger than 50MB unless skip_size_limit is true.
							if ( ! $skip_size_limit && $file_size > 52428800 ) {
								continue;
							}

							$total_size += $file_size;

							// Add file if within size range.
							if ( $total_size >= $start_size && ( null === $end_size || $total_size <= $end_size ) ) {
								$zip->addFile( $file_path, $relative_path );
							}
						}
					}

					$result = $zip->close();

					if ( $result ) {
						Log::info(
							"Successfully created ZIP using ZipArchive: {$destination}",
							array(
								'feature' => 'zip',
								'action'  => 'zip_creation_success',
							)
						);
					} else {
						Log::error(
							"ZipArchive failed to close: {$destination}",
							array(
								'feature' => 'zip',
								'action'  => 'zip_creation_failed',
							)
						);
					}

					return $result;
				}

				Log::error(
					"ZipArchive failed to create ZIP: {$destination}",
					array(
						'feature' => 'zip',
						'action'  => 'zip_creation_failed',
					)
				);
				return false;
			}

			// Fallback to WordPress PclZip.
			if ( ! extension_loaded( 'zlib' ) ) {
				Log::error(
					'zlib extension required for PclZip fallback.',
					array(
						'feature' => 'zip',
						'action'  => 'zip_creation_failed',
					)
				);
				return false;
			}
			if ( ! class_exists( 'PclZip' ) && file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			}
			if ( ! class_exists( 'PclZip' ) ) {
				Log::error(
					'PclZip not available (WordPress class-pclzip.php missing).',
					array(
						'feature' => 'zip',
						'action'  => 'zip_creation_failed',
					)
				);
				return false;
			}

			try {
				$archive    = new \PclZip( $destination );
				$file_list  = array();
				$total_size = 0;

				// For array source, filter to valid files.
				if ( is_array( $source ) ) {
					$file_list = array_filter(
						$source,
						function ( $file ) {
							return is_string( $file ) && $this->filesystem->exists( $file ) && $this->filesystem->is_readable( $file );
						}
					);

					if ( empty( $file_list ) ) {
						Log::error(
							'tailwatch_create_zip_global: No valid files in source array.',
							array(
								'feature' => 'zip',
								'action'  => 'zip_creation_failed',
							)
						);
						return false;
					}

					// Apply size and exclusion filters.
					$filtered_file_list = array();
					foreach ( $file_list as $file_path ) {
						// Skip excluded paths.
						foreach ( $exclude as $excluded_path ) {
							if ( $excluded_path && 0 === strpos( $file_path, $excluded_path ) ) {
								continue 2;
							}
						}

						if ( $this->filesystem->is_file( $file_path ) ) {
							$file_size = $this->filesystem->size( $file_path );

							// Skip files larger than 50MB unless skip_size_limit is true.
							if ( ! $skip_size_limit && $file_size > 52428800 ) {
								continue;
							}

							$total_size += $file_size;

							// Add file if within size range.
							if ( $total_size >= $start_size && ( null === $end_size || $total_size <= $end_size ) ) {
								$filtered_file_list[] = $file_path;
							}
						}
					}

					if ( empty( $filtered_file_list ) ) {
						Log::error(
							'tailwatch_create_zip_global: No files to add to ZIP.',
							array(
								'feature' => 'zip',
								'action'  => 'zip_creation_failed',
							)
						);
						return false;
					}

					// Create the zip with file list.
					$result = ! empty( $path_prefix ) ?
						$archive->create( $filtered_file_list, PCLZIP_OPT_ADD_PATH, $path_prefix ) :
						$archive->create( $filtered_file_list );
				} elseif ( $this->filesystem->is_dir( $source ) ) {
					// For directory source.
					$directory_iterator = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
						\RecursiveIteratorIterator::SELF_FIRST
					);

					foreach ( $directory_iterator as $index => $file ) {
						$file_path = $file->getRealPath();

						// Validate file path.
						if ( ! is_string( $file_path ) || ! $file_path || ! $this->filesystem->exists( $file_path ) || ! $this->filesystem->is_readable( $file_path ) ) {
							continue;
						}

						// Skip excluded paths.
						foreach ( $exclude as $excluded_path ) {
							if ( $excluded_path && 0 === strpos( $file_path, $excluded_path ) ) {
								continue 2;
							}
						}

						if ( $this->filesystem->is_file( $file_path ) ) {
							$file_size = $this->filesystem->size( $file_path );

							// Skip files larger than 50MB unless skip_size_limit is true.
							if ( ! $skip_size_limit && $file_size > 52428800 ) {
								continue;
							}

							$total_size += $file_size;

							// Add file if within size range.
							if ( $total_size >= $start_size && ( null === $end_size || $total_size <= $end_size ) ) {
								$file_list[] = $file_path;
							}
						}
					}

					if ( empty( $file_list ) ) {
						Log::error(
							'tailwatch_create_zip_global: No files to add to ZIP.',
							array(
								'feature' => 'zip',
								'action'  => 'zip_creation_failed',
							)
						);
						return false;
					}

					// Create the zip with directory.
					$result = ! empty( $path_prefix ) ?
						$archive->create( $file_list, PCLZIP_OPT_REMOVE_PATH, realpath( $source ), PCLZIP_OPT_ADD_PATH, $path_prefix ) :
						$archive->create( $file_list, PCLZIP_OPT_REMOVE_PATH, realpath( $source ) );
				} elseif ( $this->filesystem->is_file( $source ) ) {
					// For single file source.
					$file_size = $this->filesystem->size( $source );

					// Skip files larger than 50MB unless skip_size_limit is true.
					if ( ! $skip_size_limit && $file_size > 52428800 ) {
						return false;
					}

					$total_size += $file_size;

					// Check size range.
					if ( $total_size < $start_size || ( null !== $end_size && $total_size > $end_size ) ) {
						return false;
					}

					// Skip excluded paths.
					foreach ( $exclude as $excluded_path ) {
						if ( $excluded_path && 0 === strpos( $source, $excluded_path ) ) {
							return false;
						}
					}

					// Create the zip with single file.
					$result = ! empty( $path_prefix ) ?
						$archive->create( $source, PCLZIP_OPT_REMOVE_PATH, dirname( $source ), PCLZIP_OPT_ADD_PATH, $path_prefix ) :
						$archive->create( $source, PCLZIP_OPT_REMOVE_PATH, dirname( $source ) );

				} else {
					Log::error(
						'tailwatch_create_zip_global: Invalid single file source.',
						array(
							'feature' => 'zip',
							'action'  => 'zip_creation_failed',
						)
					);
					return false;
				}

				if ( 0 === $result ) {
					Log::error(
						'tailwatch_create_zip_global: PclZip error: ' . $archive->errorInfo( true ),
						array(
							'feature' => 'zip',
							'action'  => 'zip_creation_failed',
						)
					);
					return false;
				}

				$success = $this->filesystem->exists( $destination );
				if ( $success ) {
					Log::info(
						"Successfully created ZIP using PclZip: {$destination}",
						array(
							'feature' => 'zip',
							'action'  => 'zip_creation_success',
						)
					);
				}

				return $success;
			} catch ( \Exception $e ) {
				Log::error(
					'PclZip exception: ' . $e->getMessage(),
					array(
						'feature'   => 'zip',
						'action'    => 'zip_creation_failed',
						'exception' => $e,
					)
				);
				return false;
			}
		} catch ( \Throwable $e ) {
			Log::error(
				'Exception during ZIP creation: ' . $e->getMessage(),
				array(
					'feature'   => 'zip',
					'action'    => 'zip_creation_failed',
					'exception' => $e,
				)
			);
			return false;
		}
	}

	/**
	 * Check if ZIP functionality is supported on the server.
	 *
	 * @return array Compatibility status and message.
	 */
	public function tailwatch_check_zip_compatibility() {
		$status = array(
			'is_compatible' => true,
			'message'       => '',
		);

		// Check PHP version.
		if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
			$status['is_compatible'] = false;
			$status['message']       = 'PHP 5.6 or higher is required for ZIP functionality.';
			return $status;
		}

		// Check if either ext-zip or ext-zlib is available.
		if ( ! extension_loaded( 'zip' ) && ! extension_loaded( 'zlib' ) ) {
			$status['is_compatible'] = false;
			$status['message']       = 'Either the zip or zlib extension is required for ZIP functionality. Contact your hosting provider to enable one.';
			return $status;
		}

		// Check PclZip availability if ext-zip is missing.
		if ( ! extension_loaded( 'zip' ) && ! file_exists( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ) {
			$status['is_compatible'] = false;
			$status['message']       = 'PclZip is not available in this WordPress installation.';
			return $status;
		}

		// Recommend ext-zip for performance.
		if ( ! extension_loaded( 'zip' ) ) {
			$status['message'] = 'ZIP functionality is available via PclZip, but enabling the zip extension will improve performance.';
		} else {
			$status['message'] = 'ZIP functionality is fully supported with the zip extension.';
		}

		return $status;
	}
}
