<?php

/**
 * Media Controller
 *
 * Provides methods for managing WordPress media library items.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Media
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Media;

use Tailwatch\Admin\App\Api\Logging\Log;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MediaController
 *
 * Provides methods for managing WordPress media library items.
 *
 * @since 1.0.0
 */
class MediaController {

	/**
	 * Maximum file size for uploads in bytes (2MB).
	 */
	const MAX_FILE_SIZE = 2097152;

	/**
	 * Maximum items per page limit.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Get WordPress media attachments with pagination.
	 *
	 * Retrieves media library items based on query parameters with support
	 * for filtering, searching, and pagination.
	 *
	 * @param string $post_data JSON encoded query parameters.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type int    $code       HTTP response code.
	 *     @type string $message    Response message.
	 *     @type array  $data       Array of attachment objects.
	 *     @type array  $pagination Pagination information.
	 * }
	 */
	public function wptw_get_wp_media( $post_data ) {
		$json_data = isset( $post_data ) ? stripslashes( $post_data ) : '';
		$data      = json_decode( $json_data, true );

		$query = isset( $data['query'] ) && is_array( $data['query'] ) ? $data['query'] : array();

		$page     = isset( $data['page'] ) ? absint( $data['page'] ) : 1;
		$per_page = isset( $data['limit'] ) ? absint( $data['limit'] ) : 10;

		$page     = max( 1, $page );
		$per_page = max( 1, min( $per_page, self::MAX_PER_PAGE ) );

		// Allowed query keys for attachments.
		$keys = array(
			's',              // Search term.
			'order',          // ASC or DESC.
			'orderby',        // Order by field.
			'post_mime_type', // Filter by file type (image, video, etc.).
			'post_parent',    // Filter by parent post.
			'author',         // Filter by author.
			'post__in',       // Include specific post IDs.
			'post__not_in',   // Exclude specific post IDs.
			'year',           // Filter by year.
			'monthnum',       // Filter by month.
		);

		// Add taxonomy query vars.
		foreach ( get_taxonomies_for_attachments( 'objects' ) as $taxonomy ) {
			if ( $taxonomy->query_var && isset( $query[ $taxonomy->query_var ] ) ) {
				$keys[] = $taxonomy->query_var;
			}
		}

		$query = array_intersect_key( (array) $query, array_flip( $keys ) );

		$query['post_type']      = 'attachment';
		$query['paged']          = $page;
		$query['posts_per_page'] = $per_page;

		// Handle trash status.
		if (
			defined( 'MEDIA_TRASH' ) && MEDIA_TRASH &&
			! empty( $data['query']['post_status'] ) &&
			'trash' === $data['query']['post_status']
		) {
			$query['post_status'] = 'trash';
		} else {
			$query['post_status'] = 'inherit';
		}

		// Add private posts if user has capability.
		$attachment_caps = get_post_type_object( 'attachment' )->cap;
		if ( current_user_can( $attachment_caps->read_private_posts ) ) {
			$query['post_status'] .= ',private';
		}

		// Enable filename search if search term provided.
		$added_filename_filter = false;
		if ( isset( $query['s'] ) && ! empty( $query['s'] ) ) {
			add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
			$added_filename_filter = true;
		}

		$attachments_query = new \WP_Query( $query );

		update_post_parent_caches( $attachments_query->posts );

		$posts = array_map( 'wp_prepare_attachment_for_js', $attachments_query->posts );
		$posts = array_filter( $posts );

		$total_posts = absint( $attachments_query->found_posts );

		// Remove filename filter if it was added.
		if ( $added_filename_filter ) {
			remove_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		}

		$total_pages = $per_page > 0 ? absint( ceil( $total_posts / $per_page ) ) : 0;

		$from = $total_posts > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$to   = min( $page * $per_page, $total_posts );

		return array(
			'code'       => 200,
			'message'    => __( 'Attachments fetched successfully', 'tailwatch' ),
			'data'       => array_values( $posts ),
			'pagination' => array(
				'total'       => $total_posts,
				'page'        => $page,
				'limit'       => $per_page,
				'total_pages' => $total_pages,
				'from'        => $from,
				'to'          => $to,
				'has_next'    => $page < $total_pages,
				'has_prev'    => $page > 1,
			),
		);
	}

	/**
	 * Upload media file to WordPress media library.
	 *
	 * Handles file upload with validation, capability checks, and metadata assignment.
	 *
	 * @param string $post_data JSON encoded upload parameters.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type int    $code    HTTP response code.
	 *     @type string $message Response message.
	 *     @type array  $data    Attachment data (only on success).
	 * }
	 */
	public function wptw_upload_wp_media( $post_data ) {
		$file_name     = null;
		$file_size     = null;
		$attachment_id = null;

		try {
			$json_data = isset( $post_data ) ? ( ! empty( $post_data ) ? stripslashes( $post_data ) : '' ) : '';
			$data      = json_decode( $json_data, true );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by AjaxRequestController before this method is called
			if ( ! isset( $_FILES['async_upload'] ) ) {
				Log::warning(
					'Media upload failed: No file uploaded',
					array(
						'feature' => 'media',
						'action'  => 'media_upload_failed',
						'error'   => 'No file uploaded',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'No file uploaded.', 'tailwatch' ),
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by AjaxRequestController; filename unslashed then sanitized with sanitize_file_name() below
			$file_name = isset( $_FILES['async_upload']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['async_upload']['name'] ) ) : 'unknown';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by AjaxRequestController, file size is numeric and sanitized with absint()
			$file_size = isset( $_FILES['async_upload']['size'] ) ? absint( $_FILES['async_upload']['size'] ) : 0;

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File array validated by WordPress functions below
			$size_validation = $this->validate_file_size( $_FILES['async_upload'], self::MAX_FILE_SIZE );
			if ( true !== $size_validation ) {
				return array(
					'code'    => 413,
					'message' => $size_validation,
				);
			}

			// Check for file upload errors.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce verified by parent controller, error code is validated against PHP constants
			if ( isset( $_FILES['async_upload']['error'] ) && UPLOAD_ERR_OK !== $_FILES['async_upload']['error'] ) {
				$error_messages = array(
					UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
					UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
					UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
					UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
					UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
					UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
					UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
				);

				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload error code, validated against constants
				$error_code    = $_FILES['async_upload']['error'];
				$error_message = isset( $error_messages[ $error_code ] )
					? $error_messages[ $error_code ]
					: 'Unknown upload error.';

				Log::error(
					'Media upload failed: PHP upload error',
					array(
						'feature'    => 'media',
						'action'     => 'media_upload_failed',
						'file_name'  => $file_name,
						'file_size'  => $file_size,
						'error_code' => $error_code,
						'error'      => $error_message,
					)
				);

				return array(
					'code'    => 400,
					'message' => $error_message,
				);
			}

			$post_id = null;
			if ( isset( $data['post_id'] ) ) {
				$post_id = absint( $data['post_id'] );

				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					Log::warning(
						'Media upload failed: Insufficient permissions',
						array(
							'feature'   => 'media',
							'action'    => 'media_upload_failed',
							'file_name' => $file_name,
							'post_id'   => $post_id,
							'error'     => 'User does not have permission to attach files to this post',
						)
					);
					return array(
						'code'    => 403,
						'message' => __( 'You are not allowed to attach files to this post.', 'tailwatch' ),
					);
				}
			}

			$post_data_arr = array();
			if ( ! empty( $data['post_data'] ) ) {
				// Translate and sanitize post data.
				$translated = _wp_translate_postdata( false, (array) $data['post_data'] );

				if ( is_wp_error( $translated ) ) {
					Log::error(
						'Media upload failed: Post data translation error',
						array(
							'feature'   => 'media',
							'action'    => 'media_upload_failed',
							'file_name' => $file_name,
							'error'     => $translated->get_error_message(),
						)
					);
					return array(
						'code'    => 400,
						'message' => $translated->get_error_message(),
					);
				}

				// Get only allowed post data fields.
				$post_data_arr = _wp_get_allowed_postdata( $translated );

				if ( is_wp_error( $post_data_arr ) ) {
					Log::error(
						'Media upload failed: Post data validation error',
						array(
							'feature'   => 'media',
							'action'    => 'media_upload_failed',
							'file_name' => $file_name,
							'error'     => $post_data_arr->get_error_message(),
						)
					);
					return array(
						'code'    => 400,
						'message' => $post_data_arr->get_error_message(),
					);
				}
			}

			// Validate image type for custom header/background.
			if ( isset( $post_data_arr['context'] ) && in_array( $post_data_arr['context'], array( 'custom-header', 'custom-background' ), true ) ) {
				// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				// Nonce verified by AjaxRequestController. tmp_name is a server-generated path passed raw to wp_check_filetype_and_ext(); filename unslashed then sanitized.
				$tmp_name = isset( $_FILES['async_upload']['tmp_name'] ) ? $_FILES['async_upload']['tmp_name'] : '';
				$filename = isset( $_FILES['async_upload']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['async_upload']['name'] ) ) : '';
				// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

				$wp_filetype = wp_check_filetype_and_ext( $tmp_name, $filename );

				if ( ! wp_match_mime_types( 'image', $wp_filetype['type'] ) ) {
					Log::error(
						'Media upload failed: Invalid file type for custom header/background',
						array(
							'feature'   => 'media',
							'action'    => 'media_upload_failed',
							'file_name' => $file_name,
							'context'   => $post_data_arr['context'],
							'file_type' => $wp_filetype['type'] ?? 'unknown',
							'error'     => 'File is not a valid image',
						)
					);
					return array(
						'code'    => 400,
						'message' => __( 'The uploaded file is not a valid image. Please upload an image file for custom header/background.', 'tailwatch' ),
					);
				}
			}

			// Load required WordPress functions.
			if ( ! function_exists( 'media_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}

			if ( ! function_exists( 'wp_read_image_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$attachment_id = media_handle_upload( 'async_upload', $post_id, $post_data_arr );

			if ( is_wp_error( $attachment_id ) ) {
				Log::error(
					'Media upload failed: WordPress upload handler error',
					array(
						'feature'   => 'media',
						'action'    => 'media_upload_failed',
						'file_name' => $file_name,
						'file_size' => $file_size,
						'post_id'   => $post_id,
						'error'     => $attachment_id->get_error_message(),
					)
				);
				return array(
					'code'    => 400,
					'message' => $attachment_id->get_error_message(),
				);
			}

			// Add custom metadata for theme customizer uploads.
			if ( isset( $post_data_arr['context'] ) && isset( $post_data_arr['theme'] ) ) {
				if ( 'custom-background' === $post_data_arr['context'] ) {
					update_post_meta(
						$attachment_id,
						'_wp_attachment_is_custom_background',
						sanitize_text_field( $post_data_arr['theme'] )
					);
				}

				if ( 'custom-header' === $post_data_arr['context'] ) {
					update_post_meta(
						$attachment_id,
						'_wp_attachment_is_custom_header',
						sanitize_text_field( $post_data_arr['theme'] )
					);
				}
			}

			$attachment = wp_prepare_attachment_for_js( $attachment_id );

			if ( ! $attachment ) {
				Log::error(
					'Media upload failed: Failed to prepare attachment data',
					array(
						'feature'       => 'media',
						'action'        => 'media_upload_failed',
						'file_name'     => $file_name,
						'attachment_id' => $attachment_id,
						'error'         => 'Attachment uploaded but failed to prepare response data',
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Attachment uploaded successfully but failed to prepare response data.', 'tailwatch' ),
				);
			}

			// Log successful upload.
			Log::info(
				"Media uploaded successfully: {$file_name}",
				array(
					'feature'       => 'media',
					'action'        => 'media_upload_completed',
					'file_name'     => $file_name,
					'file_size'     => $file_size,
					'attachment_id' => $attachment_id,
					'post_id'       => $post_id,
					'title'         => 'Media Uploaded',
				)
			);

			return array(
				'code'    => 200,
				'message' => __( 'File uploaded successfully', 'tailwatch' ),
				'data'    => $attachment,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Media upload failed: Exception occurred',
				array(
					'feature'   => 'media',
					'action'    => 'media_upload_failed',
					'file_name' => $file_name,
					'file_size' => $file_size,
					'error'     => $e->getMessage(),
					'exception' => $e,
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'An error occurred during file upload.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Validate uploaded file size.
	 *
	 * @param array $file     File array from $_FILES.
	 * @param int   $max_size Maximum allowed file size in bytes.
	 *
	 * @return bool|string True if valid, error message string if invalid.
	 */
	private function validate_file_size( $file, $max_size = 2097152 ) {
		if ( ! isset( $file['size'] ) ) {
			return 'File size information missing.';
		}

		if ( 0 === $file['size'] ) {
			return 'The uploaded file is empty (0 bytes).';
		}

		if ( $file['size'] > $max_size ) {
			$max_mb  = round( $max_size / 1048576, 2 );
			$file_mb = round( $file['size'] / 1048576, 2 );
			return 'File size (' . $file_mb . 'MB) exceeds maximum allowed size (' . $max_mb . 'MB). Please upload a smaller file.';
		}

		return true;
	}

	/**
	 * Delete WordPress media attachment.
	 *
	 * Removes attachment from media library, either to trash or permanently.
	 *
	 * @param string $post_data JSON encoded deletion parameters.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type int    $code    HTTP response code.
	 *     @type string $message Response message.
	 *     @type array  $data    Deletion details (only on success).
	 * }
	 */
	public function wptw_delete_wp_media( $post_data ) {
		$attachment_id = null;
		$file_name     = null;
		$force_delete  = false;

		try {
			$json_data = isset( $post_data ) ? stripslashes( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			// Validate attachment ID.
			if ( ! isset( $data['attachment_id'] ) ) {
				Log::warning(
					'Media deletion failed: Attachment ID not provided',
					array(
						'feature' => 'media',
						'action'  => 'media_delete_failed',
						'error'   => 'Attachment ID is required',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Attachment ID is required.', 'tailwatch' ),
				);
			}

			$attachment_id = absint( $data['attachment_id'] );

			$attachment = get_post( $attachment_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				Log::error(
					'Media deletion failed: Attachment not found',
					array(
						'feature'       => 'media',
						'action'        => 'media_delete_failed',
						'attachment_id' => $attachment_id,
						'error'         => 'Attachment not found or invalid post type',
					)
				);
				return array(
					'code'    => 404,
					'message' => __( 'Attachment not found.', 'tailwatch' ),
				);
			}

			$file_name = get_the_title( $attachment_id ) ?: 'Unknown';

			// Check user capability to delete attachment.
			if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
				Log::warning(
					'Media deletion failed: Insufficient permissions',
					array(
						'feature'       => 'media',
						'action'        => 'media_delete_failed',
						'attachment_id' => $attachment_id,
						'file_name'     => $file_name,
						'error'         => 'User does not have permission to delete this attachment',
					)
				);
				return array(
					'code'    => 403,
					'message' => __( 'You are not allowed to delete this attachment.', 'tailwatch' ),
				);
			}

			// Determine if we should force delete or move to trash.
			$force_delete = isset( $data['force_delete'] ) && true === $data['force_delete'];

			$result = wp_delete_attachment( $attachment_id, $force_delete );

			if ( false === $result ) {
				Log::error(
					'Media deletion failed: WordPress deletion error',
					array(
						'feature'       => 'media',
						'action'        => 'media_delete_failed',
						'attachment_id' => $attachment_id,
						'file_name'     => $file_name,
						'force_delete'  => $force_delete,
						'error'         => 'wp_delete_attachment returned false',
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to delete attachment.', 'tailwatch' ),
				);
			}

			// Log successful deletion.
			Log::info(
				"Media deleted successfully: {$file_name}",
				array(
					'feature'       => 'media',
					'action'        => 'media_delete_completed',
					'attachment_id' => $attachment_id,
					'file_name'     => $file_name,
					'force_delete'  => $force_delete,
					'title'         => $force_delete ? 'Media Permanently Deleted' : 'Media Moved to Trash',
				)
			);

			return array(
				'code'    => 200,
				'message' => $force_delete
					? 'Attachment permanently deleted successfully.'
					: 'Attachment moved to trash successfully.',
				'data'    => array(
					'attachment_id'       => $attachment_id,
					'permanently_deleted' => $force_delete,
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				'Media deletion failed: Exception occurred',
				array(
					'feature'       => 'media',
					'action'        => 'media_delete_failed',
					'attachment_id' => $attachment_id,
					'file_name'     => $file_name,
					'force_delete'  => $force_delete,
					'error'         => $e->getMessage(),
					'exception'     => $e,
				)
			);

			return array(
				'code'    => 500,
				'message' => __( 'An error occurred during attachment deletion.', 'tailwatch' ),
			);
		}
	}
}
