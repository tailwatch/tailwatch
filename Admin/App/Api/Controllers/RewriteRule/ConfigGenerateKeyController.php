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
 */
class ConfigGenerateKeyController {

	/**
	 * Security key length in characters.
	 */
	const KEY_LENGTH = 64;

	/**
	 * Security key / salt constant names, in canonical wp-config order.
	 *
	 * @var string[]
	 */
	const KEY_NAMES = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

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
		return $push_notification->tailwatch_notification_enable_for_feature( $key, $option, $field_name );
	}

	/**
	 * Generate and update WordPress security keys in wp-config.php.
	 *
	 * Reads the current file, generates new keys, validates the resulting PHP
	 * syntax before writing, then re-reads and verifies the write. If the write
	 * cannot be verified, the original content (held in memory) is restored so
	 * the file is never left in a broken state. No copy of wp-config.php is
	 * written to disk, so its database credentials never reach a web-accessible
	 * location.
	 *
	 * @return array {
	 *     Response array.
	 *
	 *     @type string $status  'success' or 'error'.
	 *     @type string $message Response message.
	 * }
	 */
	public function generate_security_keys_callback() {
		// Salts live in wp-config.php, which is shared across a multisite network.
		// Rotating them logs out every user on every site, so only the primary
		// site is allowed to run the rotation — a single subsite admin must not
		// be able to sign out the whole network.
		if ( is_multisite() && ! is_main_site() ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Security key rotation runs from the primary site only on a multisite network.', 'tailwatch' ),
			);
		}

		$config_file      = null;
		$original_content = null;
		$wrote            = false;

		global $wp_filesystem;

		try {
			$config_file = WpConfigLocator::locate();

			if ( null === $config_file || ! is_readable( $config_file ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Cannot read wp-config.php file. Check file permissions.', 'tailwatch' ),
				);
			}

			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( ! is_object( $wp_filesystem ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Unable to access the filesystem to update wp-config.php.', 'tailwatch' ),
				);
			}

			if ( ! $wp_filesystem->is_writable( $config_file ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Cannot write to wp-config.php file. Check file permissions.', 'tailwatch' ),
				);
			}

			// Keep the original content in memory so we can roll back without ever
			// copying wp-config.php (and its credentials) to disk.
			$original_content = $wp_filesystem->get_contents( $config_file );

			if ( false === $original_content || '' === $original_content ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to read wp-config.php content.', 'tailwatch' ),
				);
			}

			$security_keys   = $this->generate_security_keys();
			$key_definitions = $this->build_key_definitions( $security_keys );

			if ( $this->check_keys_exist( $original_content ) ) {
				$new_content = $this->replace_existing_keys( $original_content, $key_definitions );
			} else {
				$new_content = $this->insert_new_keys( $original_content, $key_definitions );
			}

			if ( ! is_string( $new_content ) || '' === $new_content ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to generate updated wp-config.php content. No changes were made.', 'tailwatch' ),
				);
			}

			// Never write content that would not parse.
			if ( ! $this->verify_php_syntax( $new_content ) ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Generated config failed a syntax check. No changes were made.', 'tailwatch' ),
				);
			}

			$write_result = $wp_filesystem->put_contents( $config_file, $new_content, FS_CHMOD_FILE );

			if ( false === $write_result ) {
				return array(
					'status'  => 'error',
					'message' => __( 'Failed to write updated wp-config.php. No changes were made.', 'tailwatch' ),
				);
			}

			$wrote = true;

			// Re-read the file and confirm every new define landed intact and the
			// result still parses. If not, restore the original from memory.
			$written_content = $wp_filesystem->get_contents( $config_file );

			if ( ! $this->verify_written_config( $written_content, $key_definitions ) ) {
				$wp_filesystem->put_contents( $config_file, $original_content, FS_CHMOD_FILE );
				return array(
					'status'  => 'error',
					'message' => __( 'wp-config.php verification failed after writing. The original file was restored.', 'tailwatch' ),
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

			return array(
				'status'  => 'success',
				'message' => __( 'Security keys have been successfully updated in wp-config.php', 'tailwatch' ),
			);
		} catch ( \Throwable $e ) {
			// If the file was already rewritten, restore the original content from
			// memory so a mid-run failure never leaves a broken wp-config.php.
			if ( $wrote && is_string( $original_content ) && is_object( $wp_filesystem ) && null !== $config_file ) {
				$wp_filesystem->put_contents( $config_file, $original_content, FS_CHMOD_FILE );
			}

			return array(
				'status'  => 'error',
				'message' => __( 'An error occurred while generating security keys.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Build key definition strings.
	 *
	 * @param array $security_keys Generated security keys.
	 *
	 * @return array Array of key definition strings keyed by constant name.
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
		foreach ( self::KEY_NAMES as $key_name ) {
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
	 * @return string Updated config content.
	 */
	private function replace_existing_keys( $config_content, $key_definitions ) {
		$new_content = $config_content;

		foreach ( $key_definitions as $key_name => $definition ) {
			// Match an existing single-line define for this constant.
			$pattern = "/define\s*\(\s*['\"]" . preg_quote( $key_name, '/' ) . "['\"]\s*,\s*['\"].*?['\"]\s*\)\s*;/s";

			// Use a callback so the replacement is treated as a literal string. A generated key
			// can legitimately contain '$' (or '\'), which preg_replace() would interpret as a
			// backreference in the replacement argument and corrupt or drop characters.
			$new_content = preg_replace_callback(
				$pattern,
				static function () use ( $definition ) {
					return $definition;
				},
				$new_content,
				1,
				$count
			);

			// If the constant was not present, insert it alongside a related one.
			if ( 0 === $count ) {
				$new_content = $this->insert_single_key( $new_content, $key_name, $definition );
			}
		}

		return $new_content;
	}

	/**
	 * Insert a full block of security keys into config content.
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

		$insertion_point = $this->find_insertion_point( $config_content );

		if ( false === $insertion_point ) {
			return false;
		}

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
		// Strategy 1: the "stop editing" marker that precedes wp-settings.php.
		$markers = array(
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

		// Strategy 2: the ABSPATH guard block.
		$abspath_pattern = "/if\s*\(\s*!\s*defined\s*\(\s*['\"]ABSPATH['\"]\s*\)\s*\)/";
		if ( preg_match( $abspath_pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $matches[0][1];
		}

		// Strategy 3: the wp-settings.php require line.
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

		// Strategy 4: just after the $table_prefix definition.
		$table_prefix_pattern = "/\\\$table_prefix\s*=\s*['\"].*?['\"]\s*;/";
		if ( preg_match( $table_prefix_pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$pos         = $matches[0][1] + strlen( $matches[0][0] );
			$newline_pos = strpos( $config_content, "\n", $pos );
			if ( false !== $newline_pos ) {
				return $newline_pos + 1;
			}
			return $pos;
		}

		// Strategy 5: just after the WP_DEBUG definition.
		$debug_pattern = "/define\s*\(\s*['\"]WP_DEBUG['\"]\s*,.*?\)\s*;/";
		if ( preg_match( $debug_pattern, $config_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$pos         = $matches[0][1] + strlen( $matches[0][0] );
			$newline_pos = strpos( $config_content, "\n", $pos );
			if ( false !== $newline_pos ) {
				return $newline_pos + 1;
			}
			return $pos;
		}

		// Strategy 6: before a closing PHP tag if present.
		$closing_tag_pos = strrpos( $config_content, '?>' );
		if ( false !== $closing_tag_pos ) {
			return $closing_tag_pos;
		}

		// Last resort: end of file.
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

		$insertion_point = $this->find_insertion_point( $config_content );
		if ( false !== $insertion_point ) {
			return substr_replace( $config_content, $definition . "\n", $insertion_point, 0 );
		}

		return $config_content . "\n" . $definition . "\n";
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
	 * Generate a safe password for a security key.
	 *
	 * Uses wp_generate_password(), which is backed by a CSPRNG on supported
	 * installs, then strips characters that would need escaping inside a PHP
	 * single-quoted string literal.
	 *
	 * @param int|null $length Password length. Defaults to KEY_LENGTH.
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
			return str_replace( array( "'", '\\', '"' ), '', $password );
		}

		// Fallback if wp_generate_password is unavailable.
		$chars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+[]{}|;:,.<>?';
		$password = '';
		$max      = strlen( $chars ) - 1;

		for ( $i = 0; $i < $length; $i++ ) {
			$password .= $chars[ wp_rand( 0, $max ) ];
		}

		return str_replace( array( "'", '\\', '"' ), '', $password );
	}

	/**
	 * Verify PHP syntax of config content.
	 *
	 * Uses PHP's native token_get_all() with the TOKEN_PARSE flag, which throws
	 * ParseError for invalid syntax on PHP 7.0+. Works on hosts where exec() is
	 * disabled. A bracket-balance pass adds a structural sanity check.
	 *
	 * @link https://www.php.net/manual/en/function.token-get-all.php
	 *
	 * @param string $content PHP content to validate.
	 *
	 * @return bool True if syntax is valid, false otherwise.
	 */
	private function verify_php_syntax( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return false;
		}

		$content_to_check = $content;
		if ( 0 !== strpos( trim( $content ), '<?php' ) && 0 !== strpos( trim( $content ), '<?' ) ) {
			$content_to_check = '<?php ' . $content;
		}

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- token_get_all can emit a warning on edge tokens; errors are handled via the catch blocks below.
			$tokens = @token_get_all( $content_to_check, TOKEN_PARSE );

			if ( false === $tokens || empty( $tokens ) ) {
				return false;
			}

			return $this->validate_token_structure( $tokens );
		} catch ( \ParseError $e ) {
			return false;
		} catch ( \Error $e ) {
			return false;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Validate token structure for wp-config.php content.
	 *
	 * @param array $tokens Array of PHP tokens from token_get_all().
	 *
	 * @return bool True if token structure is valid, false otherwise.
	 */
	private function validate_token_structure( array $tokens ) {
		$bracket_count     = 0;
		$parenthesis_count = 0;
		$brace_count       = 0;

		foreach ( $tokens as $token ) {
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

				if ( $parenthesis_count < 0 || $bracket_count < 0 || $brace_count < 0 ) {
					return false;
				}
			}
		}

		if ( 0 !== $parenthesis_count || 0 !== $bracket_count || 0 !== $brace_count ) {
			return false;
		}

		return true;
	}

	/**
	 * Verify that a freshly written wp-config.php is intact.
	 *
	 * Confirms the re-read content still parses and contains every new define
	 * exactly as written. Used to decide whether to keep the write or roll back
	 * to the in-memory original.
	 *
	 * @param mixed $written_content Re-read file content.
	 * @param array $key_definitions Array of key definition strings.
	 *
	 * @return bool True if the write is verified, false otherwise.
	 */
	private function verify_written_config( $written_content, array $key_definitions ) {
		if ( ! is_string( $written_content ) || '' === $written_content ) {
			return false;
		}

		if ( ! $this->verify_php_syntax( $written_content ) ) {
			return false;
		}

		foreach ( $key_definitions as $definition ) {
			if ( false === strpos( $written_content, $definition ) ) {
				return false;
			}
		}

		return true;
	}
}
