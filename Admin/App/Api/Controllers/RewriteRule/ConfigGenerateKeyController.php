<?php
/**
 * Config Generate Key Controller
 *
 * Manages WordPress security key generation and wp-config.php updates.
 *
 * @package    Tailwatch
 * @subpackage Controllers/RewriteRule
 */

namespace Tailwatch\Admin\App\Api\Controllers\RewriteRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\PushNotifications\PushNotificationController;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\WpConfigLocator;

/**
 * Class ConfigGenerateKeyController
 *
 * Manages WordPress security key generation and wp-config.php updates.
 *
 */
class ConfigGenerateKeyController {

	/**
	 * Security key length in characters.
	 */
	const KEY_LENGTH = 64;

	/**
	 * Get feature options for config key generation.
	 *
	 * @return array Feature settings array.
	 */
	public function get_features_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_config_generate_key';
		$is_active          = 1;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Check if push notifications are enabled for config key generation.
	 *
	 * @return bool Whether push notifications are enabled.
	 */
	public function config_key_generate_push_notification() {
		$push_notification = new PushNotificationController();
		$key               = 'default_feature_settings';
		$option            = 'default_config_generate_key';
		$field_name        = 'field_1';
		return $push_notification->wptw_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Generate and update WordPress security keys in wp-config.php.
	 *
	 * Creates backup, generates new security keys, validates syntax, and updates config file.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $status  'success' or 'error'.
	 *     @type string $message Response message.
	 * }
	 */
	public function generate_security_keys_callback() {
		$backup_file = null;

		try {
			$config_file = WpConfigLocator::locate();

			// Validate config file exists and is readable.
			if ( null === $config_file || ! is_readable( $config_file ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Cannot read wp-config.php file. Check file permissions.', 'tailwatch' ),
				);
			}

			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( ! $wp_filesystem->is_writable( $config_file ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Cannot write to wp-config.php file. Check file permissions.', 'tailwatch' ),
				);
			}

			// Ensure backup directory exists with proper security.
			$backup_dir = $this->ensure_backup_directory();
			if ( false === $backup_dir ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to create secure backup directory.', 'tailwatch' ),
				);
			}

			// Create backup of the config file with .bak extension (not .php for security).
			$backup_file = $backup_dir . '/wp-config.backup-' . time() . '.bak';
			if ( ! $wp_filesystem->copy( $config_file, $backup_file ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to create backup of wp-config.php file.', 'tailwatch' ),
				);
			}

			// Verify backup was created.
			if ( ! file_exists( $backup_file ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Backup file verification failed.', 'tailwatch' ),
				);
			}

			$config_content = $wp_filesystem->get_contents( $config_file );

			if ( false === $config_content || empty( $config_content ) ) {
				$this->cleanup_backup( $backup_file );
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to read wp-config.php content.', 'tailwatch' ),
				);
			}

			// Generate secure keys.
			$security_keys = $this->generate_security_keys();

			$key_definitions = $this->build_key_definitions( $security_keys );

			// Check if keys already exist in config.
			$keys_exist = $this->check_keys_exist( $config_content );

			if ( $keys_exist ) {
				// Replace existing keys.
				$new_config_content = $this->replace_existing_keys( $config_content, $key_definitions );
			} else {
				// Insert new keys.
				$new_config_content = $this->insert_new_keys( $config_content, $key_definitions );
			}

			if ( false === $new_config_content || empty( $new_config_content ) ) {
				$this->cleanup_backup( $backup_file );
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to generate updated wp-config.php content.', 'tailwatch' ),
				);
			}

			// Verify the PHP syntax before saving.
			$syntax_valid = $this->verify_php_syntax( $new_config_content );
			if ( ! $syntax_valid ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Generated config has syntax errors. No changes were made. Backup kept at ', 'tailwatch' ) . esc_html( basename( $backup_file ) ),
				);
			}

			// Write the updated content.
			$write_result = $wp_filesystem->put_contents( $config_file, $new_config_content, FS_CHMOD_FILE );

			if ( false === $write_result ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to write updated wp-config.php. Backup kept at ', 'tailwatch' ) . esc_html( basename( $backup_file ) ),
				);
			}

			// Log completion (also triggers notification via NotificationActions).
			Log::info(
				'Your WordPress security keys were successfully rotated to strengthen login security and invalidate old sessions. You may need to log in again on some devices.',
				array(
					'feature'   => 'config_generate_key',
					'action'    => 'config_key_generate_completed',
					'title'     => 'Security Keys Rotated',
					'meta_data' => array(
						'feature' => 'Security Keys Rotation',
						'event'   => 'Completed',
					),
				)
			);

			// Clean up backup file on success.
			$this->cleanup_backup( $backup_file );

			return array(
				'status'  => 'success',
				'message' => __( 'Security keys have been successfully updated in wp-config.php', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			// Clean up backup file if it exists.
			if ( $backup_file && file_exists( $backup_file ) ) {
				$this->cleanup_backup( $backup_file );
			}

			return array(
				'status'  => 'error',
				'message' => __( 'An error occurred while generating security keys.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Ensure backup directory exists with proper security files.
	 *
	 * Creates the wptw-logs directory if it doesn't exist and adds security files
	 * (.htaccess and index.php) to prevent direct access.
	 *
	 * @return string|false Backup directory path or false on failure.
	 */
	private function ensure_backup_directory() {
		$backup_dir = WPTW_LOGS_DIRECTORY;

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Create directory if it doesn't exist.
		if ( ! file_exists( $backup_dir ) ) {
			if ( ! wp_mkdir_p( $backup_dir ) ) {
				return false;
			}
		}

		// Verify directory is writable.
		if ( ! $wp_filesystem->is_writable( $backup_dir ) ) {
			return false;
		}

		// Create .htaccess file to prevent direct access (Apache).
		$htaccess_file = $backup_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content  = "# Deny access to all files in this directory\n";
			$htaccess_content .= "Order deny,allow\n";
			$htaccess_content .= "Deny from all\n";
			$htaccess_content .= "\n";
			$htaccess_content .= "# Block access via Apache 2.4+ syntax as well\n";
			$htaccess_content .= "<IfModule mod_authz_core.c>\n";
			$htaccess_content .= "    Require all denied\n";
			$htaccess_content .= "</IfModule>\n";

			$wp_filesystem->put_contents( $htaccess_file, $htaccess_content, FS_CHMOD_FILE );
		}

		// Create index.php file to prevent directory listing.
		$index_file = $backup_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			$index_content = "<?php\n// Silence is golden.\n";
			$wp_filesystem->put_contents( $index_file, $index_content, FS_CHMOD_FILE );
		}

		return $backup_dir;
	}

	/**
	 * Build key definition strings.
	 *
	 * @param array $security_keys Generated security keys.
	 *
	 * @return array Array of key definition strings.
	 */
	private function build_key_definitions( $security_keys ) {
		return array(
			'AUTH_KEY'         => "define( 'AUTH_KEY', '" . addcslashes( $security_keys['auth_key'], "'\\" ) . "' );",
			'SECURE_AUTH_KEY'  => "define( 'SECURE_AUTH_KEY', '" . addcslashes( $security_keys['secure_auth_key'], "'\\" ) . "' );",
			'LOGGED_IN_KEY'    => "define( 'LOGGED_IN_KEY', '" . addcslashes( $security_keys['logged_in_key'], "'\\" ) . "' );",
			'NONCE_KEY'        => "define( 'NONCE_KEY', '" . addcslashes( $security_keys['nonce_key'], "'\\" ) . "' );",
			'AUTH_SALT'        => "define( 'AUTH_SALT', '" . addcslashes( $security_keys['auth_salt'], "'\\" ) . "' );",
			'SECURE_AUTH_SALT' => "define( 'SECURE_AUTH_SALT', '" . addcslashes( $security_keys['secure_auth_salt'], "'\\" ) . "' );",
			'LOGGED_IN_SALT'   => "define( 'LOGGED_IN_SALT', '" . addcslashes( $security_keys['logged_in_salt'], "'\\" ) . "' );",
			'NONCE_SALT'       => "define( 'NONCE_SALT', '" . addcslashes( $security_keys['nonce_salt'], "'\\" ) . "' );",
		);
	}

	/**
	 * Check if security keys already exist in config content.
	 *
	 * @param string $config_content The wp-config.php content.
	 *
	 * @return bool True if at least one key exists, false otherwise.
	 */
	private function check_keys_exist( $config_content ) {
		$key_names = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		foreach ( $key_names as $key_name ) {
			// Check for define statement with this key name.
			if ( preg_match( "/define\s*\(\s*['\"]" . preg_quote( $key_name, '/' ) . "['\"]/", $config_content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace existing security keys in config content.
	 *
	 * @param string $config_content  The wp-config.php content.
	 * @param array  $key_definitions Array of key definition strings.
	 *
	 * @return string|false Updated config content or false on failure.
	 */
	private function replace_existing_keys( $config_content, $key_definitions ) {
		$new_content = $config_content;

		foreach ( $key_definitions as $key_name => $definition ) {
			// Pattern to match existing key definition (handles various formats).
			$pattern = "/define\s*\(\s*['\"]" . preg_quote( $key_name, '/' ) . "['\"]\s*,\s*['\"].*?['\"]\s*\)\s*;/s";

			$new_content = preg_replace( $pattern, $definition, $new_content, 1, $count );

			// If key wasn't found, we need to insert it.
			if ( 0 === $count ) {
				$new_content = $this->insert_single_key( $new_content, $key_name, $definition );
			}
		}

		return $new_content;
	}

	/**
	 * Insert new security keys into config content.
	 *
	 * @param string $config_content  The wp-config.php content.
	 * @param array  $key_definitions Array of key definition strings.
	 *
	 * @return string|false Updated config content or false on failure.
	 */
	private function insert_new_keys( $config_content, $key_definitions ) {
		$constants_block  = "\n/**#@+\n";
		$constants_block .= " * Authentication unique keys and salts.\n";
		$constants_block .= " *\n";
		$constants_block .= " * Change these to different unique phrases! You can generate these using\n";
		$constants_block .= " * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.\n";
		$constants_block .= " *\n";
		$constants_block .= " * @since 2.6.0\n";
		$constants_block .= " */\n";

		foreach ( $key_definitions as $definition ) {
			$constants_block .= $definition . "\n";
		}

		$constants_block .= "/**#@-*/\n\n";

		// Find the best insertion point using multiple strategies.
		$insertion_point = $this->find_insertion_point( $config_content );

		if ( false === $insertion_point ) {
			return false;
		}

		// Insert the constants block at the found position.
		return substr_replace( $config_content, $constants_block, $insertion_point, 0 );
	}

	/**
	 * Find the best insertion point for security keys.
	 *
	 * @param string $config_content The wp-config.php content.
	 *
	 * @return int|false Position for insertion or false if not found.
	 */
	private function find_insertion_point( $config_content ) {
		// Strategy 1: Look for "That's all, stop editing!" comment.
		$markers = array(
			"/* That's all, stop editing!",
			"/* That's all, stop editing!",
			'/* Thats all, stop editing!',
			"/** That's all, stop editing!",
		);

		foreach ( $markers as $marker ) {
			$pos = strpos( $config_content, $marker );
			if ( false !== $pos ) {
				return $pos;
			}
		}

		// Strategy 2: Look for ABSPATH definition followed by wp-settings require.
		$abspath_pattern = "/if\s*\(\s*!\s*defined\s*\(\s*['\"]ABSPATH['\"]\s*\)\s*\)/";
		if ( preg_match( $abspath_pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $matches[0][1];
		}

		// Strategy 3: Look for wp-settings.php require line.
		$wp_settings_patterns = array(
			"/require_once\s*\(?\s*ABSPATH\s*\.\s*['\"]wp-settings\.php['\"]\s*\)?\s*;/",
			"/require_once\s+ABSPATH\s*\.\s*['\"]wp-settings\.php['\"];/",
			"/require\s*\(?\s*ABSPATH\s*\.\s*['\"]wp-settings\.php['\"]\s*\)?\s*;/",
		);

		foreach ( $wp_settings_patterns as $pattern ) {
			if ( preg_match( $pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
				return $matches[0][1];
			}
		}

		// Strategy 4: Look for $table_prefix definition and insert after it.
		$table_prefix_pattern = "/\\\$table_prefix\s*=\s*['\"].*?['\"]\s*;/";
		if ( preg_match( $table_prefix_pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			// Insert after the table_prefix line.
			$pos = $matches[0][1] + strlen( $matches[0][0] );
			// Find the next newline.
			$newline_pos = strpos( $config_content, "\n", $pos );
			if ( false !== $newline_pos ) {
				return $newline_pos + 1;
			}
			return $pos;
		}

		// Strategy 5: Look for WP_DEBUG definition and insert after it.
		$debug_pattern = "/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,.*?\)\s*;/";
		if ( preg_match( $debug_pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$pos         = $matches[0][1] + strlen( $matches[0][0] );
			$newline_pos = strpos( $config_content, "\n", $pos );
			if ( false !== $newline_pos ) {
				return $newline_pos + 1;
			}
			return $pos;
		}

		// Strategy 6: Insert before the closing PHP tag if present, or at end.
		$closing_tag_pos = strrpos( $config_content, '?>' );
		if ( false !== $closing_tag_pos ) {
			return $closing_tag_pos;
		}

		// Last resort: return end of file.
		return strlen( $config_content );
	}

	/**
	 * Insert a single key definition into config content.
	 *
	 * @param string $config_content The wp-config.php content.
	 * @param string $key_name       The key name (e.g., 'AUTH_KEY').
	 * @param string $definition     The full define statement.
	 *
	 * @return string Updated config content.
	 */
	private function insert_single_key( $config_content, $key_name, $definition ) {
		// Try to find a related key to insert near.
		$related_keys = array(
			'AUTH_KEY'         => array( 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY' ),
			'SECURE_AUTH_KEY'  => array( 'AUTH_KEY', 'LOGGED_IN_KEY' ),
			'LOGGED_IN_KEY'    => array( 'SECURE_AUTH_KEY', 'NONCE_KEY' ),
			'NONCE_KEY'        => array( 'LOGGED_IN_KEY', 'AUTH_SALT' ),
			'AUTH_SALT'        => array( 'NONCE_KEY', 'SECURE_AUTH_SALT' ),
			'SECURE_AUTH_SALT' => array( 'AUTH_SALT', 'LOGGED_IN_SALT' ),
			'LOGGED_IN_SALT'   => array( 'SECURE_AUTH_SALT', 'NONCE_SALT' ),
			'NONCE_SALT'       => array( 'LOGGED_IN_SALT', 'AUTH_SALT' ),
		);

		if ( isset( $related_keys[ $key_name ] ) ) {
			foreach ( $related_keys[ $key_name ] as $related_key ) {
				$pattern = "/define\s*\(\s*['\"]" . preg_quote( $related_key, '/' ) . "['\"]\s*,\s*['\"].*?['\"]\s*\)\s*;/";
				if ( preg_match( $pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
					$insert_pos  = $matches[0][1] + strlen( $matches[0][0] );
					$newline_pos = strpos( $config_content, "\n", $insert_pos );
					if ( false !== $newline_pos ) {
						$insert_pos = $newline_pos + 1;
					}
					return substr_replace( $config_content, $definition . "\n", $insert_pos, 0 );
				}
			}
		}

		// Fallback: use the general insertion point.
		$insertion_point = $this->find_insertion_point( $config_content );
		if ( false !== $insertion_point ) {
			return substr_replace( $config_content, $definition . "\n", $insertion_point, 0 );
		}

		// Last resort: append to end.
		return $config_content . "\n" . $definition . "\n";
	}

	/**
	 * Clean up backup file.
	 *
	 * @param string|null $backup_file Path to backup file.
	 *
	 * @return void
	 */
	private function cleanup_backup( $backup_file ) {
		if ( $backup_file && file_exists( $backup_file ) ) {
			wp_delete_file( $backup_file );
		}
	}

	/**
	 * Generate all security keys.
	 *
	 * @return array Associative array of security keys.
	 */
	private function generate_security_keys() {
		return array(
			'auth_key'         => $this->generate_safe_password(),
			'secure_auth_key'  => $this->generate_safe_password(),
			'logged_in_key'    => $this->generate_safe_password(),
			'nonce_key'        => $this->generate_safe_password(),
			'auth_salt'        => $this->generate_safe_password(),
			'secure_auth_salt' => $this->generate_safe_password(),
			'logged_in_salt'   => $this->generate_safe_password(),
			'nonce_salt'       => $this->generate_safe_password(),
		);
	}

	/**
	 * Generate a safe password for security keys.
	 *
	 * Generates a random password suitable for use in wp-config.php constants.
	 *
	 * @param int $length Password length. Default 64.
	 *
	 * @return string Generated password.
	 */
	private function generate_safe_password( $length = null ) {
		if ( null === $length ) {
			$length = self::KEY_LENGTH;
		}

		$length = absint( $length );

		if ( function_exists( 'wp_generate_password' ) ) {
			$password = wp_generate_password( $length, true, true );
			// Extra safety: remove any characters that might cause issues.
			$password = str_replace( array( "'", '\\', '"' ), '', $password );
			return $password;
		}

		// Fallback if wp_generate_password is not available.
		$chars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+[]{}|;:,.<>?';
		$password = '';
		$max      = strlen( $chars ) - 1;

		for ( $i = 0; $i < $length; $i++ ) {
			$password .= $chars[ wp_rand( 0, $max ) ];
		}

		// Remove potentially problematic characters for PHP syntax.
		$password = str_replace( array( "'", '\\', '"' ), '', $password );
		return $password;
	}

	/**
	 * Verify PHP syntax of config content.
	 *
	 * Uses PHP's native token_get_all() function to validate syntax without
	 * requiring shell access. This approach is compatible with all hosting
	 * environments, including those where exec() is disabled.
	 *
	 * The function uses token_get_all() with TOKEN_PARSE flag which throws
	 * ParseError for invalid PHP syntax in PHP 7.0+. Additional bracket
	 * balancing validation ensures structural correctness.
	 *
	 * @link https://www.php.net/manual/en/function.token-get-all.php PHP token_get_all() documentation.
	 * @link https://github.com/wp-cli/wp-config-transformer WP-CLI config transformer approach.
	 *
	 * @param string $content PHP content to validate.
	 *
	 * @return bool True if syntax is valid, false otherwise.
	 */
	private function verify_php_syntax( $content ) {
		// Validate input.
		if ( empty( $content ) || ! is_string( $content ) ) {
			return false;
		}

		// Ensure content starts with PHP opening tag for proper tokenization.
		$content_to_check = $content;
		if ( 0 !== strpos( trim( $content ), '<?php' ) && 0 !== strpos( trim( $content ), '<?' ) ) {
			$content_to_check = '<?php ' . $content;
		}

		try {
			// Use PHP's native tokenizer to validate syntax.
			// token_get_all() with TOKEN_PARSE throws ParseError for invalid PHP syntax.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional: token_get_all may trigger warnings for edge cases, we handle errors via try-catch.
			$tokens = @token_get_all( $content_to_check, TOKEN_PARSE );

			// If tokenization failed, tokens will be false or empty.
			if ( false === $tokens || empty( $tokens ) ) {
				return false;
			}

			// Validate token structure for common wp-config.php patterns.
			return $this->validate_token_structure( $tokens );

		} catch ( \ParseError $e ) {
			// PHP 7+ throws ParseError for syntax errors - this is expected behavior.
			return false;
		} catch ( \Error $e ) {
			// Catch any other errors.
			return false;
		} catch ( \Exception $e ) {
			// Fallback for any unexpected exceptions.
			return false;
		}
	}

	/**
	 * Validate token structure for wp-config.php content.
	 *
	 * Performs additional validation on tokenized PHP content to ensure
	 * the structure is valid for a wp-config.php file.
	 *
	 * @param array $tokens Array of PHP tokens from token_get_all().
	 *
	 * @return bool True if token structure is valid, false otherwise.
	 */
	private function validate_token_structure( array $tokens ) {
		// Check for balanced brackets and parentheses.
		$bracket_count     = 0;
		$parenthesis_count = 0;
		$brace_count       = 0;

		foreach ( $tokens as $token ) {
			// Handle single character tokens.
			if ( is_string( $token ) ) {
				switch ( $token ) {
					case '(':
						++$parenthesis_count;
						break;
					case ')':
						--$parenthesis_count;
						break;
					case '[':
						++$bracket_count;
						break;
					case ']':
						--$bracket_count;
						break;
					case '{':
						++$brace_count;
						break;
					case '}':
						--$brace_count;
						break;
				}

				// Early exit if counts go negative (closing before opening).
				if ( $parenthesis_count < 0 || $bracket_count < 0 || $brace_count < 0 ) {
					return false;
				}
			}
		}

		// All brackets must be balanced.
		if ( 0 !== $parenthesis_count || 0 !== $bracket_count || 0 !== $brace_count ) {
			return false;
		}

		return true;
	}
}
