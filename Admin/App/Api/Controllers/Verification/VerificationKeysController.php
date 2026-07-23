<?php
/**
 * Verification Keys Controller
 *
 * Manages generation and cleanup of verification keys and secrets.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Verification
 */

namespace Tailwatch\Admin\App\Api\Controllers\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\WpConfigLocator;
use Tailwatch\Admin\App\Api\Services\Auth\JwtService;

/**
 * Class VerificationKeysController
 *
 * Manages generation and cleanup of verification keys and secrets.
 *
 */
class VerificationKeysController {

	public function wptw_get_generated_cta_keys() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {

				return array(
					'code'    => 403,
					'message' => __( 'You do not have permission to access this resource.', 'tailwatch' ),
				);
			}

			$db_model = new DBModel();
			$db_model->wptw_delete_all_cta_keys();

			$cta_id          = bin2hex( random_bytes( 16 ) );
			$cta_secret      = bin2hex( random_bytes( 32 ) );
			// Secondary credential the mobile app must send as the
			// X-Tailwatch-Auth-Key header on /login, /refresh-token, and
			// /v1. Stored plaintext for per-request hash_equals
			// validation (compared on every Auth-route hit; bcrypt-verify
			// would add cost to every mobile action).
			$auth_header_key = bin2hex( random_bytes( 32 ) );

			update_option( 'wptw_cta_id', $cta_id );
			update_option( 'wptw_cta_secret_' . $cta_id, password_hash( $cta_secret, PASSWORD_BCRYPT ) );
			update_option( 'wptw_auth_header_key', $auth_header_key, false );

			return array(
				'cta_id'          => $cta_id,
				'cta_secret'      => $cta_secret,
				'auth_header_key' => $auth_header_key,
				'code'            => 200,
				'message'         => __( 'Client ID, Secret and Auth Header Key retrieved successfully.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {

			return array(
				'code'    => 500,
				'message' => __( 'Failed to generate CTA keys', 'tailwatch' ),
			);
		}
	}

	/**
	 * Delete expired JWT token tracking rows from the options table.
	 *
	 * Scans all `wptw_token_jti_*` options and removes any whose `expires`
	 * timestamp is in the past. Called daily by JwtCleanupCronJob so these
	 * tracking rows never accumulate indefinitely in wp_options.
	 *
	 * @return int Number of expired token rows deleted.
	 */
	public function cleanup_expired_jti_tokens() {
		global $wpdb;

		// Pass 1 — delete JTI tracking rows whose tokens have expired.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; JWT-token cleanup.
		$jti_options = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'wptw_token_jti_' ) . '%'
		) );

		$deleted = 0;
		foreach ( $jti_options as $option ) {
			$data = get_option( $option->option_name );
			if ( is_array( $data ) && isset( $data['expires'] ) && $data['expires'] < time() ) {
				delete_option( $option->option_name );
				++$deleted;
			}
		}

		// Pass 2 — clean orphaned revocation flags. A wptw_token_revoked_<jti>
		// row whose matching wptw_token_jti_<jti> row no longer exists is
		// redundant: the token's natural lifetime has passed (refresh TTL is
		// 7 days, and the JTI row was cleaned in pass 1 or earlier), so the
		// JWT itself would fail signature/expiry verification regardless.
		// Removing these prevents wp_options bloat over time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; revocation-flag cleanup.
		$revoked_options = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'wptw_token_revoked_' ) . '%'
		) );

		foreach ( $revoked_options as $option ) {
			$jti = substr( $option->option_name, strlen( 'wptw_token_revoked_' ) );

			// Skip malformed entries — don't auto-delete; leave for inspection.
			if ( ! JwtService::is_valid_jti_format( $jti ) ) {
				continue;
			}

			// $jti is validated by is_valid_jti_format() above — only /^[a-f0-9]{32}(_refresh)?$/
			// can reach here, so the option name is always wptw_token_jti_<hex32>. No arbitrary reads.
			$matching_jti = 'wptw_token_jti_' . $jti;
			if ( false === get_option( $matching_jti, false ) ) {
				delete_option( $option->option_name );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Rotate the JWT signing secret.
	 *
	 * Generates a new 256-bit cryptographically-secure key and writes it to
	 * wp-config.php (replacing any existing WPTW_JWT_SECRET_KEY define), or
	 * to the wptw_jwt_secret_key option if wp-config.php is not writable.
	 *
	 * Side effect: every JWT signed with the previous secret immediately fails
	 * signature verification on the next request. This is the desired behavior
	 * during a license disconnect — closes the "leaked secret can forge new
	 * tokens with new JTIs (not in revocation list)" attack vector. Mobile
	 * apps will need to re-authenticate.
	 *
	 * Idempotent: safe to call when no previous secret exists.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on successful rotation, false on failure.
	 */
	public function wptw_rotate_secret_key() {
		try {
			// Two legitimate callers, mapped to two branches of this check:
			//
			//   1. Admin-initiated disconnect from wp-admin (AJAX route) — has a
			//      WP user-cookie context, so current_user_can() applies.
			//   2. Scheduled license verification detecting a server-side revoke
			//      via wptw_handle_connection_killed() — runs inside WP-Cron, so
			//      there is no user context and we authorize via wp_doing_cron().
			//
			// The WP-CLI branch that used to be here has been removed: there is no
			// Tailwatch CLI command in the codebase that would need to rotate the
			// secret. If a CLI command is added later, re-add the WP_CLI branch
			// explicitly rather than re-broadening the check.
			if ( ! current_user_can( 'manage_options' ) && ! wp_doing_cron() ) {
				Log::warning(
					'JWT secret rotation skipped: caller is neither an admin session nor a cron callback.',
					array(
						'feature' => 'verification',
						'action'  => 'jwt_secret_rotation_unauthorized',
						'origin'  => 'system',
					)
				);
				return false;
			}

			$jwt_secret_key = bin2hex( random_bytes( 32 ) );
			$config_file    = WpConfigLocator::locate();

			$wrote_to_config = false;

			if ( null !== $config_file ) {
				global $wp_filesystem;
				if ( empty( $wp_filesystem ) ) {
					require_once ABSPATH . '/wp-admin/includes/file.php';
					WP_Filesystem();
				}

				if ( ! empty( $wp_filesystem ) && $wp_filesystem->is_writable( $config_file ) ) {
					$content = $wp_filesystem->get_contents( $config_file );
					if ( false !== $content ) {
						// If a define already exists, replace it (rotation).
						$pattern = "/define\s*\(\s*['\"]WPTW_JWT_SECRET_KEY['\"]\s*,\s*['\"][^'\"]*['\"]\s*\)\s*;/";
						if ( preg_match( $pattern, $content ) ) {
							$new_content = preg_replace(
								$pattern,
								"define('WPTW_JWT_SECRET_KEY', '$jwt_secret_key');",
								$content
							);
							if ( null !== $new_content && $this->safely_write_config_file( $config_file, $content, $new_content ) ) {
								$wrote_to_config = true;
								// Remove DB fallback if it existed — wp-config is now authoritative.
								delete_option( 'wptw_jwt_secret_key' );
							}
						}
					}
				}
			}

			if ( ! $wrote_to_config ) {
				// Fallback: store in DB option. This is the same fallback path
				// used by wptw_generate_secret_key() at first activation.
				update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
			}

			return true;
		} catch ( \Throwable $e ) {
			// Best-effort fallback: at least store the new secret somewhere
			// rather than leaving the system with a known-leaked secret.
			if ( isset( $jwt_secret_key ) ) {
				update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
			}
			return false;
		}
	}

	public function wptw_generate_secret_key() {
		try {
			// Check permissions before modifying wp-config.php
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Generate 256-bit secure key
			$jwt_secret_key = bin2hex( random_bytes( 32 ) );

			// Locate wp-config.php (probes both ABSPATH and dirname(ABSPATH) — Bedrock-compat).
			$config_file = WpConfigLocator::locate();

			if ( null === $config_file ) {
				update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
				return;
			}

			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				WP_Filesystem();
			}

			// Check if wp-config.php is writable
			if ( ! $wp_filesystem->is_writable( $config_file ) ) {
				update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
				return;
			}

			// Use WP_Filesystem API instead of direct file functions
			$content = $wp_filesystem->get_contents( $config_file );
			if ( $content === false ) {
				update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
				return;
			}

			// Define possible markers
			$primary_marker   = "/* That's all, stop editing! Happy blogging. */";
			$secondary_marker = "/* That's all, stop editing! Happy publishing. */";
			$fallback_marker  = '/** Absolute path to the WordPress directory. */';
			$new_content      = $content;

			// Check if secret key is already defined
			if ( strpos( $content, "define('WPTW_JWT_SECRET_KEY'" ) === false ) {
				// Try primary marker (Happy blogging)
				if ( strpos( $content, $primary_marker ) !== false ) {
					$new_content = str_replace(
						$primary_marker,
						"define('WPTW_JWT_SECRET_KEY', '$jwt_secret_key');\n$primary_marker",
						$content
					);
				}
				// Try secondary marker (Happy publishing)
				elseif ( strpos( $content, $secondary_marker ) !== false ) {
					$new_content = str_replace(
						$secondary_marker,
						"define('WPTW_JWT_SECRET_KEY', '$jwt_secret_key');\n$secondary_marker",
						$content
					);
				}
				// Fallback to inserting after ABSPATH marker
				elseif ( strpos( $content, $fallback_marker ) !== false ) {
					$new_content = str_replace(
						$fallback_marker,
						"$fallback_marker\n\ndefine('WPTW_JWT_SECRET_KEY', '$jwt_secret_key');",
						$content
					);
				} else {
					update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
					return;
				}

				// Write with backup + PHP syntax validation + rollback.
				if ( ! $this->safely_write_config_file( $config_file, $content, $new_content ) ) {
					update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
					return;
				}
			}
		} catch ( \Throwable $e ) {
			// Fallback to database storage on any error
			if ( isset( $jwt_secret_key ) ) {
				update_option( 'wptw_jwt_secret_key', $jwt_secret_key );
			}
		}

		// Generate encryption key for sensitive data (SMTP passwords, etc.)
		if ( ! defined( 'WPTW_ENCRYPTION_KEY' ) && ! get_option( 'wptw_secret_encryption_key' ) ) {
			$encryption_key = bin2hex( random_bytes( 32 ) );
			$this->write_encryption_key_to_config( $encryption_key );

			// Make the key usable immediately on this same request: Constants.php
			// already ran at bootstrap (before the key existed), so define the
			// canonical constant now instead of waiting for the next request.
			if ( ! defined( 'WPTW_ENCRYPTION_KEY' ) ) {
				define( 'WPTW_ENCRYPTION_KEY', $encryption_key );
			}
		}
	}

	/**
	 * Write the encryption key to wp-config.php, falling back to wp_options.
	 *
	 * Mirrors the JWT secret key storage strategy: wp-config.php is preferred
	 * because a database compromise cannot expose the key there.
	 *
	 * @param string $encryption_key The generated encryption key.
	 */
	private function write_encryption_key_to_config( $encryption_key ) {
		$config_file = WpConfigLocator::locate();

		if ( null === $config_file ) {
			update_option( 'wptw_secret_encryption_key', $encryption_key );
			return;
		}

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->is_writable( $config_file ) ) {
			update_option( 'wptw_secret_encryption_key', $encryption_key );
			return;
		}

		$content = $wp_filesystem->get_contents( $config_file );
		if ( $content === false ) {
			update_option( 'wptw_secret_encryption_key', $encryption_key );
			return;
		}

		if ( strpos( $content, "define('WPTW_SECRET_ENCRYPTION_KEY'" ) !== false ) {
			return; // Already defined.
		}

		$primary_marker   = "/* That's all, stop editing! Happy blogging. */";
		$secondary_marker = "/* That's all, stop editing! Happy publishing. */";
		$fallback_marker  = '/** Absolute path to the WordPress directory. */';
		$new_content      = $content;
		$inserted         = false;

		if ( strpos( $content, $primary_marker ) !== false ) {
			$new_content = str_replace(
				$primary_marker,
				"define('WPTW_SECRET_ENCRYPTION_KEY', '$encryption_key');\n$primary_marker",
				$content
			);
			$inserted = true;
		} elseif ( strpos( $content, $secondary_marker ) !== false ) {
			$new_content = str_replace(
				$secondary_marker,
				"define('WPTW_SECRET_ENCRYPTION_KEY', '$encryption_key');\n$secondary_marker",
				$content
			);
			$inserted = true;
		} elseif ( strpos( $content, $fallback_marker ) !== false ) {
			$new_content = str_replace(
				$fallback_marker,
				"$fallback_marker\n\ndefine('WPTW_SECRET_ENCRYPTION_KEY', '$encryption_key');",
				$content
			);
			$inserted = true;
		}

		if ( ! $inserted || ! $this->safely_write_config_file( $config_file, $content, $new_content ) ) {
			update_option( 'wptw_secret_encryption_key', $encryption_key );
		}
	}

	public function wptw_remove_secret_key_from_config() {
		// Check permissions before modifying wp-config.php
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config_file = WpConfigLocator::locate();

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( null !== $config_file && $wp_filesystem->is_writable( $config_file ) ) {
			$content = $wp_filesystem->get_contents( $config_file );
			if ( $content !== false ) {
				// Remove both JWT and encryption key defines.
				$patterns = array(
					"/^\\s*define\\s*\\(\\s*'WPTW_JWT_SECRET_KEY'\\s*,.*?\\);\\s*$/m",
					"/^\\s*define\\s*\\(\\s*'WPTW_SECRET_ENCRYPTION_KEY'\\s*,.*?\\);\\s*$/m",
				);
				$new_content = $content;
				foreach ( $patterns as $pattern ) {
					$new_content = preg_replace( $pattern, '', $new_content );
				}
				if ( $new_content !== null && $new_content !== $content ) {
					$this->safely_write_config_file( $config_file, $content, $new_content );
				}
			}
		}
		// Remove fallback options from DB
		delete_option( 'wptw_jwt_secret_key' );
		delete_option( 'wptw_secret_encryption_key' );
	}

	/**
	 * Write new wp-config.php content with backup, syntax validation, and rollback.
	 *
	 * Mirrors FilesPermissionController::safely_write_config_file so every
	 * wp-config.php writer in the plugin shares the same safety guarantees:
	 * refuse to write if the new content fails a PHP syntax check, back up the
	 * original before writing, and restore it if the write fails or the written
	 * file does not re-parse cleanly. Callers fall back to the DB option when
	 * this returns false, so a non-writable or invalid config never corrupts the
	 * site.
	 *
	 * @param string $file_path        Absolute path to wp-config.php.
	 * @param string $original_content Current file contents (rollback source).
	 * @param string $new_content      Proposed new file contents.
	 * @return bool True on success, false on any failure.
	 */
	private function safely_write_config_file( $file_path, $original_content, $new_content ) {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( empty( $wp_filesystem ) || ! $wp_filesystem->is_writable( $file_path ) ) {
			return false;
		}
		if ( ! $this->is_valid_php_content( $new_content ) ) {
			Log::error(
				'Refusing to modify wp-config.php: generated JWT-secret content failed PHP syntax check.',
				array(
					'feature' => 'verification',
					'action'  => 'wp_config_write_failed',
					'origin'  => 'system',
				)
			);
			return false;
		}

		$backup_path = $file_path . '.tailwatch-bak';
		$wp_filesystem->put_contents( $backup_path, $original_content, FS_CHMOD_FILE );

		$write_result = $wp_filesystem->put_contents( $file_path, $new_content, FS_CHMOD_FILE );
		if ( false === $write_result ) {
			$wp_filesystem->put_contents( $file_path, $original_content, FS_CHMOD_FILE );
			return false;
		}

		// Post-write validation: re-read and re-parse the file; restore on failure.
		$written = $wp_filesystem->get_contents( $file_path );
		if ( false === $written || ! $this->is_valid_php_content( $written ) ) {
			$wp_filesystem->put_contents( $file_path, $original_content, FS_CHMOD_FILE );
			Log::error(
				'wp-config.php failed post-write syntax validation; restored JWT-secret backup.',
				array(
					'feature' => 'verification',
					'action'  => 'wp_config_write_failed',
					'origin'  => 'system',
				)
			);
			return false;
		}

		if ( $wp_filesystem->exists( $backup_path ) ) {
			$wp_filesystem->delete( $backup_path );
		}
		return true;
	}

	/**
	 * Validate that a string is parseable PHP using token_get_all() with TOKEN_PARSE.
	 *
	 * @param string $content Source code (opening tag added if missing).
	 * @return bool True if syntax is valid, false otherwise.
	 */
	private function is_valid_php_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}
		if ( 0 !== strpos( ltrim( $content ), '<?php' ) && 0 !== strpos( ltrim( $content ), '<?' ) ) {
			$content = '<?php ' . $content;
		}
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- token_get_all may emit warnings; ParseError caught below.
			@token_get_all( $content, TOKEN_PARSE );
			return true;
		} catch ( \ParseError $e ) {
			return false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
