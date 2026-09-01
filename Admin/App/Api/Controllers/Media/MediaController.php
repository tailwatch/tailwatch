<?php
/**
 * Media Controller
 *
 * Manages WordPress media-library items for the plugin dashboard. The same three
 * actions serve every surface: the in-WP React admin (admin-ajax, nonce +
 * manage_options), the cloud dashboard, and the mobile app (REST /dispatch, which
 * authenticates with the per-site key + JWT and runs as the paired admin via
 * wp_set_current_user()). Because wp.media() exists only inside wp-admin, remote
 * surfaces need a server-side endpoint; this mirrors how WordPress.com/Jetpack
 * expose remote media management.
 *
 * All uploads go through the WordPress-standard media_handle_upload() (which runs
 * wp_handle_upload's own MIME/size checks and builds the attachment + metadata).
 * On top of that, every action is capability-gated and every upload is restricted
 * to images — the only media this plugin uses (block-page background / logo).
 *
 * @package    Tailwatch
 * @subpackage Controllers/Media
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Media;

use Tailwatch\Admin\App\Api\Logging\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MediaController {

	/**
	 * Maximum upload size in bytes (2 MB).
	 */
	const MAX_FILE_SIZE = 2097152;

	/**
	 * Maximum items returned per page.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * List media-library attachments (paginated).
	 *
	 * @param string $post_data JSON-encoded query parameters.
	 * @return array { code, message, data, pagination }
	 */
	public function tailwatch_get_wp_media( $post_data ) {
		// Capability gate. On the REST/cloud path the dispatcher has already run
		// wp_set_current_user() for the paired admin, so this resolves correctly
		// on every surface.
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'code'    => 403,
				'message' => __( 'You are not allowed to access the media library.', 'tailwatch' ),
			);
		}

		$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
		$data      = json_decode( (string) $json_data, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$query    = isset( $data['query'] ) && is_array( $data['query'] ) ? $data['query'] : array();
		$page     = isset( $data['page'] ) ? absint( $data['page'] ) : 1;
		$per_page = isset( $data['limit'] ) ? absint( $data['limit'] ) : 10;
		$page     = max( 1, $page );
		$per_page = max( 1, min( $per_page, self::MAX_PER_PAGE ) );

		// Only allow a known set of attachment query vars — never pass the raw
		// client query into WP_Query.
		$keys = array(
			's',
			'order',
			'orderby',
			'post_mime_type',
			'post_parent',
			'author',
			'post__in',
			'post__not_in',
			'year',
			'monthnum',
		);

		foreach ( get_taxonomies_for_attachments( 'objects' ) as $taxonomy ) {
			if ( $taxonomy->query_var && isset( $query[ $taxonomy->query_var ] ) ) {
				$keys[] = $taxonomy->query_var;
			}
		}

		$query = array_intersect_key( (array) $query, array_flip( $keys ) );

		$query['post_type']      = 'attachment';
		$query['paged']          = $page;
		$query['posts_per_page'] = $per_page;

		if (
			defined( 'MEDIA_TRASH' ) && MEDIA_TRASH &&
			! empty( $data['query']['post_status'] ) &&
			'trash' === $data['query']['post_status']
		) {
			$query['post_status'] = 'trash';
		} else {
			$query['post_status'] = 'inherit';
		}

		// Include private attachments only for users allowed to read them.
		$attachment_caps = get_post_type_object( 'attachment' )->cap;
		if ( current_user_can( $attachment_caps->read_private_posts ) ) {
			$query['post_status'] .= ',private';
		}

		$added_filename_filter = false;
		if ( ! empty( $query['s'] ) ) {
			add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
			$added_filename_filter = true;
		}

		$attachments_query = new \WP_Query( $query );

		update_post_parent_caches( $attachments_query->posts );

		$posts = array_map( 'wp_prepare_attachment_for_js', $attachments_query->posts );
		$posts = array_filter( $posts );

		$total_posts = absint( $attachments_query->found_posts );

		if ( $added_filename_filter ) {
			remove_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		}

		$total_pages = $per_page > 0 ? absint( ceil( $total_posts / $per_page ) ) : 0;
		$from        = $total_posts > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$to          = min( $page * $per_page, $total_posts );

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
	 * Upload an image to the media library.
	 *
	 * Runs the WordPress-standard media_handle_upload() after a capability gate,
	 * a size check, and an images-only MIME check. The nonce (admin-ajax) or the
	 * per-site key + JWT (REST /dispatch) is verified by the caller before this
	 * method runs.
	 *
	 * @param string $post_data JSON-encoded upload parameters.
	 * @return array { code, message, data }
	 */
	public function tailwatch_upload_wp_media( $post_data ) {
		$file_name = null;
		$file_size = null;

		try {
			// Capability gate FIRST — before any file handling. media_handle_upload()
			// does not check capabilities itself; that is the caller's job.
			if ( ! current_user_can( 'upload_files' ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'You are not allowed to upload files.', 'tailwatch' ),
				);
			}

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( (string) $json_data, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce (admin-ajax) / key + JWT (REST) verified by the dispatcher before this method runs.
			if ( ! isset( $_FILES['async_upload'] ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'No file uploaded.', 'tailwatch' ),
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See note above; filename is unslashed then sanitize_file_name'd.
			$file_name = isset( $_FILES['async_upload']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['async_upload']['name'] ) ) : 'unknown';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Numeric size, cast with absint().
			$file_size = isset( $_FILES['async_upload']['size'] ) ? absint( $_FILES['async_upload']['size'] ) : 0;

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES array validated by the checks below and by wp_handle_upload().
			$size_validation = $this->validate_file_size( $_FILES['async_upload'], self::MAX_FILE_SIZE );
			if ( true !== $size_validation ) {
				return array(
					'code'    => 413,
					'message' => $size_validation,
				);
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Upload error code compared against PHP UPLOAD_ERR_* constants.
			if ( isset( $_FILES['async_upload']['error'] ) && UPLOAD_ERR_OK !== $_FILES['async_upload']['error'] ) {
				$error_messages = array(
					UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the server upload size limit.', 'tailwatch' ),
					UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the form size limit.', 'tailwatch' ),
					UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', 'tailwatch' ),
					UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'tailwatch' ),
					UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder on the server.', 'tailwatch' ),
					UPLOAD_ERR_CANT_WRITE => __( 'Failed to write the file to disk.', 'tailwatch' ),
					UPLOAD_ERR_EXTENSION  => __( 'A server extension stopped the file upload.', 'tailwatch' ),
				);
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Error code keys a fixed message map.
				$error_code    = (int) $_FILES['async_upload']['error'];
				$error_message = isset( $error_messages[ $error_code ] ) ? $error_messages[ $error_code ] : __( 'Unknown upload error.', 'tailwatch' );

				return array(
					'code'    => 400,
					'message' => $error_message,
				);
			}

			$post_id = null;
			if ( isset( $data['post_id'] ) ) {
				$post_id = absint( $data['post_id'] );
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return array(
						'code'    => 403,
						'message' => __( 'You are not allowed to attach files to this post.', 'tailwatch' ),
					);
				}
			}

			// _wp_translate_postdata()/_wp_get_allowed_postdata() live in wp-admin/includes/post.php,
			// which is not loaded on the REST/cloud path — require it explicitly before use.
			if ( ! function_exists( '_wp_translate_postdata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
			$post_data_arr = array();
			if ( ! empty( $data['post_data'] ) ) {
				$translated = _wp_translate_postdata( false, (array) $data['post_data'] );
				if ( is_wp_error( $translated ) ) {
					return array(
						'code'    => 400,
						'message' => $translated->get_error_message(),
					);
				}
				$post_data_arr = _wp_get_allowed_postdata( $translated );
				if ( is_wp_error( $post_data_arr ) ) {
					return array(
						'code'    => 400,
						'message' => $post_data_arr->get_error_message(),
					);
				}
			}

			// Images only. Validate the real type by inspecting the uploaded temp
			// file (not the client-supplied MIME), for EVERY upload — the plugin
			// only ever stores images (block-page background / logo).
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			// $tmp_name is a server-generated path handed straight to wp_check_filetype_and_ext(); filename is unslashed + sanitized.
			$tmp_name = isset( $_FILES['async_upload']['tmp_name'] ) ? $_FILES['async_upload']['tmp_name'] : '';
			$filename = isset( $_FILES['async_upload']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['async_upload']['name'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			$wp_filetype = wp_check_filetype_and_ext( $tmp_name, $filename );
			if ( empty( $wp_filetype['type'] ) || ! wp_match_mime_types( 'image', $wp_filetype['type'] ) ) {
				Log::warning(
					'Media upload rejected: not an image',
					array(
						'feature'   => 'media',
						'action'    => 'media_upload_failed',
						'file_name' => $file_name,
						'file_type' => $wp_filetype['type'] ?? 'unknown',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Only image files can be uploaded here.', 'tailwatch' ),
				);
			}

			if ( ! function_exists( 'media_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( ! function_exists( 'wp_read_image_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			// Second guard: restrict wp_handle_upload itself to image MIME types.
			$image_mimes = array();
			foreach ( get_allowed_mime_types() as $ext => $mime ) {
				if ( 0 === strpos( $mime, 'image/' ) ) {
					$image_mimes[ $ext ] = $mime;
				}
			}
			$overrides = array(
				'test_form' => false,
				'mimes'     => $image_mimes,
			);

			$attachment_id = media_handle_upload( 'async_upload', $post_id, $post_data_arr, $overrides );

			if ( is_wp_error( $attachment_id ) ) {
				Log::error(
					'Media upload failed: WordPress upload handler error',
					array(
						'feature'   => 'media',
						'action'    => 'media_upload_failed',
						'file_name' => $file_name,
						'file_size' => $file_size,
						'error'     => $attachment_id->get_error_message(),
					)
				);
				return array(
					'code'    => 400,
					'message' => $attachment_id->get_error_message(),
				);
			}

			if ( isset( $post_data_arr['context'], $post_data_arr['theme'] ) ) {
				if ( 'custom-background' === $post_data_arr['context'] ) {
					update_post_meta( $attachment_id, '_wp_attachment_is_custom_background', sanitize_text_field( $post_data_arr['theme'] ) );
				}
				if ( 'custom-header' === $post_data_arr['context'] ) {
					update_post_meta( $attachment_id, '_wp_attachment_is_custom_header', sanitize_text_field( $post_data_arr['theme'] ) );
				}
			}

			$attachment = wp_prepare_attachment_for_js( $attachment_id );
			if ( ! $attachment ) {
				return array(
					'code'    => 500,
					'message' => __( 'The file was uploaded but its data could not be prepared.', 'tailwatch' ),
				);
			}

			Log::info(
				'Media uploaded successfully',
				array(
					'feature'       => 'media',
					'action'        => 'media_upload_completed',
					'file_name'     => $file_name,
					'file_size'     => $file_size,
					'attachment_id' => $attachment_id,
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
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An error occurred during file upload.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Validate the uploaded file size.
	 *
	 * @param array $file     Entry from $_FILES.
	 * @param int   $max_size Maximum allowed size in bytes.
	 * @return bool|string True when valid, otherwise a message.
	 */
	private function validate_file_size( $file, $max_size = self::MAX_FILE_SIZE ) {
		if ( ! isset( $file['size'] ) ) {
			return __( 'File size information is missing.', 'tailwatch' );
		}
		if ( 0 === (int) $file['size'] ) {
			return __( 'The uploaded file is empty (0 bytes).', 'tailwatch' );
		}
		if ( (int) $file['size'] > $max_size ) {
			$max_mb  = round( $max_size / 1048576, 2 );
			$file_mb = round( (int) $file['size'] / 1048576, 2 );
			/* translators: 1: uploaded file size in MB, 2: maximum allowed size in MB. */
			return sprintf( __( 'File size (%1$sMB) exceeds the maximum allowed size (%2$sMB). Please upload a smaller file.', 'tailwatch' ), $file_mb, $max_mb );
		}
		return true;
	}

	/**
	 * Delete a media-library attachment (trash or permanent).
	 *
	 * @param string $post_data JSON-encoded deletion parameters.
	 * @return array { code, message, data }
	 */
	public function tailwatch_delete_wp_media( $post_data ) {
		$attachment_id = null;
		$force_delete  = false;

		try {
			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( (string) $json_data, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			if ( ! isset( $data['attachment_id'] ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'Attachment ID is required.', 'tailwatch' ),
				);
			}

			$attachment_id = absint( $data['attachment_id'] );
			$attachment    = get_post( $attachment_id );

			// Only ever operate on attachments — never an arbitrary post ID.
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return array(
					'code'    => 404,
					'message' => __( 'Attachment not found.', 'tailwatch' ),
				);
			}

			if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
				return array(
					'code'    => 403,
					'message' => __( 'You are not allowed to delete this attachment.', 'tailwatch' ),
				);
			}

			$force_delete = isset( $data['force_delete'] ) && true === $data['force_delete'];

			$result = wp_delete_attachment( $attachment_id, $force_delete );
			if ( false === $result || null === $result ) {
				return array(
					'code'    => 500,
					'message' => __( 'Failed to delete the attachment.', 'tailwatch' ),
				);
			}

			Log::info(
				'Media deleted successfully',
				array(
					'feature'       => 'media',
					'action'        => 'media_delete_completed',
					'attachment_id' => $attachment_id,
					'force_delete'  => $force_delete,
					'title'         => $force_delete ? 'Media Permanently Deleted' : 'Media Moved to Trash',
				)
			);

			return array(
				'code'    => 200,
				'message' => $force_delete
					? __( 'Attachment permanently deleted successfully.', 'tailwatch' )
					: __( 'Attachment moved to trash successfully.', 'tailwatch' ),
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
					'force_delete'  => $force_delete,
					'error'         => $e->getMessage(),
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'An error occurred during attachment deletion.', 'tailwatch' ),
			);
		}
	}
}
