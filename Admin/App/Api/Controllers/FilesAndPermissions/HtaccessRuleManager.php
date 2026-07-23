<?php

namespace Tailwatch\Admin\App\Api\Controllers\FilesAndPermissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;

/**
 * HtaccessRuleManager - Specialized class for atomic .htaccess rule management
 *
 * This class handles all .htaccess file operations with complete safety:
 * - Atomic file operations (temp file -> validate -> replace)
 * - File locking to prevent concurrent modifications
 * - Robust regex patterns for reliable block detection
 * - Multi-layer validation to prevent syntax errors
 * - Proper newline handling for clean formatting
 * - Priority-based rule insertion ordering
 */
class HtaccessRuleManager {

	/**
	 * Main method to manage .htaccess rules (insert or remove)
	 *
	 * @param string $directory Rule type: wp-includes, wp-admin, wp-content, root, xmlrpc
	 * @param bool   $enable True to insert rule, false to remove rule
	 * @param bool   $just_verify True to only verify if rule exists (no modification)
	 * @return bool|null Success status or null for verification
	 */
	public function manage_htaccess_rule( $directory, $enable, $just_verify = false ) {
		// Defense in depth: whitelist directory keys to prevent path/marker injection
		// if a future caller passes user-controlled input. All current callers pass
		// hardcoded values, but the method is public and could be reached from new code.
		$allowed_directories = array( 'security_headers', 'wp-includes', 'wp-admin', 'wp-content', 'root', 'xmlrpc' );
		if ( ! in_array( $directory, $allowed_directories, true ) ) {
			return false;
		}

		$htaccess_file = ABSPATH . '.htaccess';
		$rules         = '';
		$site_url      = home_url();

		// Refuse to interpolate base_dir into rewrite rules unless it matches a
		// strict allowlist. home_url() can in principle be set by an admin to any
		// string, and special characters would break Apache parsing of the
		// generated .htaccess. Fallback to '/' on anything unexpected.
		$candidate_base_dir = wp_parse_url( $site_url, PHP_URL_PATH );
		if ( is_string( $candidate_base_dir ) && '' !== $candidate_base_dir && preg_match( '#^[A-Za-z0-9_\-./]+$#', $candidate_base_dir ) ) {
			$base_dir = rtrim( $candidate_base_dir, '/' );
		} else {
			$base_dir = '';
		}

		// Derive URL segment from content_url() rather than wp_basename(WP_CONTENT_DIR);
		// filesystem and URL relocation can differ (e.g. Bedrock sets WP_CONTENT_URL
		// independently of WP_CONTENT_DIR via the content_url filter).
		$content_url_path = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		$content_segment  = ltrim( substr( $content_url_path, strlen( $base_dir ) ), '/' );
		if ( '' === $content_segment || ! preg_match( '#^[A-Za-z0-9_-]+$#', $content_segment ) ) {
			$content_segment = 'wp-content';
		}

		switch ( $directory ) {
			case 'security_headers':
				// Note: 'Header unset Server' has no effect — Apache sets the
				// Server header at the core HTTP layer before mod_headers runs.
				// Removing Server requires `ServerTokens Prod` in main httpd.conf
				// (Apache) or `server_tokens off` (nginx). We only ship the
				// X-Powered-By unset which mod_headers CAN intercept on most
				// configs (PHP also calls header_remove at the runtime layer).
				$rules  = "\n# BEGIN Remove Security Headers\n";
				$rules .= "<IfModule mod_headers.c>\n";
				$rules .= "    Header unset X-Powered-By\n";
				$rules .= "    Header always unset X-Powered-By\n";
				$rules .= "</IfModule>\n";
				$rules .= "# END Remove Security Headers\n\n";
				break;
			case 'wp-includes':
				$rules  = "\n# Disable direct access to wp-includes\n";
				$rules .= "<IfModule mod_rewrite.c>\n";
				$rules .= "RewriteEngine On\n";
				$rules .= "RewriteBase $base_dir/\n";

				$rules .= "RewriteCond %{REQUEST_URI} ^(.*)//(.*)$\n";
				$rules .= "RewriteRule . %1/%2 [R=301,L]\n";

				// Block direct access to PHP files in wp-includes unless the request is made by WordPress
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/wp-includes/.*\\.php$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				// Allow access to assets (CSS, JS, images, fonts, media) only if requested by the website itself, not directly from the browser
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/wp-includes/.*\\.(css|js|map|jpg|jpeg|png|gif|svg|webp|avif|ico|woff|woff2|ttf|otf|eot|json|mp4|webm|mp3|ogg|pdf)$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				// Block direct access to the wp-includes directory itself
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/wp-includes/ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule ^wp-includes/ - [F,L]\n";

				$rules .= "</IfModule>\n\n";
				break;
			case 'wp-content':
				$rules  = "\n# Disable direct access to wp-content\n";
				$rules .= "<IfModule mod_rewrite.c>\n";
				$rules .= "RewriteEngine On\n";
				$rules .= "RewriteBase $base_dir/\n";

				$rules .= "RewriteCond %{REQUEST_URI} ^(.*)//(.*)$\n";
				$rules .= "RewriteRule . %1/%2 [R=301,L]\n";

				// WHITELIST: Allow direct access to backup directory
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/$content_segment/tailwatch/wptw-backup/ [NC]\n";
				$rules .= "RewriteRule ^$content_segment/tailwatch/wptw-backup/ - [L]\n";

				// Block direct access to PHP files in wp-content
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/$content_segment/.*\\.php$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				// Allow access to assets (CSS, JS, images) only if requested by the website itself, not directly from the browser
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/$content_segment/.*\\.(css|js|jpg|jpeg|png|gif|svg|woff|woff2|ttf|otf|eot|json)$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				// Block direct access to the wp-content directory itself
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/$content_segment/ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule ^$content_segment/ - [F,L]\n";

				$rules .= "</IfModule>\n\n";
				break;
			case 'wp-admin':
				$rules  = "\n# Disable direct access to wp-admin\n";
				$rules .= "<IfModule mod_rewrite.c>\n";
				$rules .= "RewriteEngine On\n";
				$rules .= "RewriteBase $base_dir/\n";

				$rules .= "RewriteCond %{REQUEST_URI} ^(.*)//(.*)$\n";
				$rules .= "RewriteRule . %1/%2 [R=301,L]\n";

				// Allow direct access to wp-admin/index.php for admin panel access
				$rules .= "RewriteRule ^wp-admin/index\\.php$ - [L]\n";

				// Allow access to essential WordPress core endpoints:
				//   admin-ajax.php   - AJAX (frontend + admin)
				//   admin-post.php   - Form submissions (admin_post_ + admin_post_nopriv_)
				//   load-styles.php  - Concatenated CSS bundles for wp-admin and wp-login (CVE-2018-6389 fix uses CONCATENATE_SCRIPTS=false but file still serves single bundles)
				//   load-scripts.php - Concatenated JS bundles for wp-admin and wp-login
				//   async-upload.php - Media Library uploader (multipart POSTs)
				//   media-upload.php - Legacy uploader (still used by some flows)
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/wp-admin/(admin-ajax|admin-post|load-styles|load-scripts|async-upload|media-upload)\\.php$ [NC]\n";
				$rules .= "RewriteRule ^wp-admin/ - [L]\n";

				// Block direct access to other PHP files in wp-admin unless the request is made by WordPress
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/wp-admin/.*\\.php$ [NC]\n";
				$rules .= "RewriteCond %{REQUEST_URI} !^$base_dir/wp-admin/index\\.php$ [NC]\n";
				$rules .= "RewriteCond %{REQUEST_URI} !^$base_dir/wp-admin/(admin-ajax|admin-post|load-styles|load-scripts|async-upload|media-upload)\\.php$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				// Allow access to assets (CSS, JS, images, fonts, media) only if requested by the website itself
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/wp-admin/.*\\.(css|js|map|jpg|jpeg|png|gif|svg|webp|avif|ico|woff|woff2|ttf|otf|eot|json|mp4|webm|mp3|ogg|pdf)$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				$rules .= "</IfModule>\n\n";
				break;
			case 'root':
				$rules  = "\n# Disable direct access to root files, allow assets only from website, block other root PHP files\n";
				$rules .= "<IfModule mod_rewrite.c>\n";
				$rules .= "RewriteEngine On\n";
				$rules .= "RewriteBase $base_dir/\n";

				$rules .= "RewriteCond %{REQUEST_URI} ^(.*)//(.*)$\n";
				$rules .= "RewriteRule . %1/%2 [R=301,L]\n";

				// Skip specific folders and files directly
				$rules .= "RewriteRule ^wp-includes/ - [L]\n";
				$rules .= "RewriteRule ^$content_segment/ - [L]\n";
				$rules .= "RewriteRule ^wp-admin/ - [L]\n";
				$rules .= "RewriteRule ^xmlrpc.php$ - [L]\n";
				$rules .= "RewriteRule ^index.php$ - [L]\n";
				$rules .= "RewriteRule ^wp-login.php$ - [L]\n";
				$rules .= "RewriteRule ^wp-cron.php$ - [L]\n";

				// Block public info files that expose WordPress details (readme.html reveals version; license.txt is boilerplate)
				$rules .= "RewriteRule ^(readme\\.html|license\\.txt)$ - [F,L,NC]\n";

				// Block sensitive backup, editor-swap, dump, and credential files anywhere in the site.
				// Covers: wp-config.php.bak/.old/.original/.save/.tmp/etc, tilde backups (file.php~),
				// .env variants, common backup extensions, vim swap files, SQL dumps, log files, shell scripts.
				$rules .= '<FilesMatch "(?i)(^wp-config\.php\..+$|~$|^\.env(\.|$)|\.(bak|backup|copy|old|orig|original|save|swp|swo|swn|sql|log|sh)$|\.sql\.gz$)">' . "\n";
				$rules .= "    Order Allow,Deny\n";
				$rules .= "    Deny from all\n";
				$rules .= "</FilesMatch>\n";

				// Allow access to assets (CSS, JS, images) only if requested by the website itself
				$rules .= "RewriteCond %{REQUEST_URI} ^$base_dir/.*\\.(css|js|jpg|jpeg|png|gif|svg|woff|woff2|ttf|otf|eot|json)$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";

				// Block direct access to any other root PHP files unless referred by the website
				$rules .= "RewriteCond %{REQUEST_URI} \.php$ [NC]\n";
				$rules .= 'RewriteCond %{HTTP_REFERER} !^' . $site_url . " [NC]\n";
				$rules .= "RewriteCond %{HTTP_USER_AGENT} !WordPress.* [NC]\n";
				$rules .= "RewriteRule .* - [F,L]\n";
				$rules .= "</IfModule>\n\n";
				break;
			case 'xmlrpc':
				$rules = "\n# Disable direct access to xmlrpc.php\n<Files xmlrpc.php>\nOrder Deny,Allow\nDeny from all\n</Files>\n\n";
				break;
		}

		if ( $just_verify ) {
			$fs      = FilesystemService::get_filesystem();
			$content = ( $fs && $fs->exists( $htaccess_file ) ) ? $fs->get_contents( $htaccess_file ) : false;
			if ( false === $content ) {
				return false;
			}
			$marker  = ( $directory === 'security_headers' ) ? '# BEGIN Remove Security Headers' : "# Disable direct access to $directory";
			if ( strpos( $content, $marker ) !== false ) {
				return true;
			}
		} elseif ( $enable ) {
				// Insert rules if not already present
				return $this->insert_htaccess_rule( $htaccess_file, $rules, $directory );
		} else {
			// Remove rules if present
			return $this->remove_htaccess_rule( $htaccess_file, $directory );
		}
	}

	/**
	 * Insert .htaccess rule using atomic operations
	 */
	private function insert_htaccess_rule( $file, $rule, $directory ) {
		return $this->insert_htaccess_rule_safely( $file, $rule, $directory );
	}

	/**
	 * Safely insert .htaccess rule with atomic operations and file locking
	 */
	private function insert_htaccess_rule_safely( $file, $rule, $directory ) {
		$fs = FilesystemService::get_filesystem();
		if ( ! file_exists( $file ) || ! $fs || ! $fs->is_writable( $file ) ) {
			return false;
		}

		// Step 1: Create lock file to prevent concurrent modifications
		$lock_file     = $file . '.wptw_lock';
		$lock_acquired = false;
		$max_attempts  = 10;
		$attempt       = 0;

		while ( ! $lock_acquired && $attempt < $max_attempts ) {
			if ( ! file_exists( $lock_file ) ) {
				if ( $fs->put_contents( $lock_file, (string) getmypid() ) !== false ) {
					$lock_acquired = true;
				} else {
					return false;
				}
			} else {
				// Check if lock is stale (older than 30 seconds)
				if ( time() - filemtime( $lock_file ) > 30 ) {
					wp_delete_file( $lock_file );
				}
				usleep( 100000 ); // Wait 0.1 seconds
				++$attempt;
			}
		}

		if ( ! $lock_acquired ) {
			return false;
		}

		// Step 2: Create working copy
		$temp_file = $file . '.temp_' . bin2hex( random_bytes( 8 ) );

		if ( ! $fs->copy( $file, $temp_file, true ) ) {
			wp_delete_file( $lock_file );
			return false;
		}

		// Step 3: Set correct permissions on temp file
		$original_perms = fileperms( $file );
		if ( $original_perms !== false ) {
			$fs->chmod( $temp_file, $original_perms );
		}

		try {
			// Step 4: Read temp file content
			$content = $fs->get_contents( $temp_file );
			if ( false === $content ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 5: Check if rule already exists
			$marker = ( $directory === 'security_headers' ) ? '# BEGIN Remove Security Headers' : "# Disable direct access to $directory";
			if ( strpos( $content, $marker ) !== false ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return true; // Already exists, no need to add
			}

			// Step 6: Add rule with priority (keep your existing logic)
			$new_content = $this->insert_rule_with_priority( $content, $rule, $directory );

			if ( $fs->put_contents( $temp_file, $new_content ) === false ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 7: Validate temp file thoroughly
			if ( ! $this->validate_htaccess_syntax( $temp_file ) ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 8: Atomic replace
			if ( ! $fs->move( $temp_file, $file, true ) ) {
				if ( file_exists( $temp_file ) ) {
					wp_delete_file( $temp_file );
				}
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 9: Release lock
			wp_delete_file( $lock_file );

			return true;

		} catch ( \Throwable $e ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			if ( file_exists( $lock_file ) ) {
				wp_delete_file( $lock_file );
			}
			return false;
		}
	}

	/**
	 * Insert rule with proper priority ordering
	 */
	private function insert_rule_with_priority( $content, $rule, $directory ) {
		// Define the priority order - root should always be last
		$rule_priority_order = array(
			'security_headers' => 0, // Highest priority
			'wp-includes'      => 1,
			'wp-admin'         => 2,
			'wp-content'       => 3,
			'xmlrpc'           => 4,
			'root'             => 5,  // Root always has highest priority (inserted last)
		);

		$current_rule_priority = $rule_priority_order[ $directory ] ?? 999;

		// Check if root rule exists - if it does, insert before it (unless we are inserting root)
		$root_rule_pattern = '/# Disable direct access to root files.*?<\/IfModule>/s';
		if ( $directory !== 'root' && preg_match( $root_rule_pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			// Root rule exists, insert current rule before it
			$root_start_position = $matches[0][1];
			$content             = substr_replace( $content, $rule, $root_start_position, 0 );
			return $content;
		}

		// If inserting root rule, find the last position of other existing rules
		if ( $directory === 'root' ) {
			$rules_to_check = array(
				'# Disable direct access to wp-includes',
				'# Disable direct access to wp-admin',
				'# Disable direct access to wp-content',
				'# Disable direct access to xmlrpc.php',
			);

			$last_position = 0;

			foreach ( $rules_to_check as $check_rule ) {
				if ( preg_match( '/' . preg_quote( $check_rule, '/' ) . '/', $content ) ) {
					// Find the end of this rule block
					if ( $check_rule === '# Disable direct access to xmlrpc.php' ) {
						// For xmlrpc, find the end of </Files> block
						if ( preg_match( '/' . preg_quote( $check_rule, '/' ) . '.*?<\/Files>/s', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
							$end_position  = $matches[0][1] + strlen( $matches[0][0] );
							$last_position = max( $last_position, $end_position );
						}
					} else {
						// For other rules, find the end of </IfModule> block
						if ( preg_match( '/' . preg_quote( $check_rule, '/' ) . '.*?<\/IfModule>/s', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
							$end_position  = $matches[0][1] + strlen( $matches[0][0] );
							$last_position = max( $last_position, $end_position );
						}
					}
				}
			}

			// Insert root rule after the last found rule, or at the end if no rules found
			if ( $last_position > 0 ) {
				// Ensure proper spacing before insertion
				$rule_to_insert = $this->ensure_proper_newlines_before_insertion( $content, $last_position, $rule );
				$content        = substr_replace( $content, $rule_to_insert, $last_position, 0 );
			} else {
				// Ensure file ends with newline before appending
				$content = rtrim( $content ) . "\n" . $rule;
			}
			return $content;
		}

		// For other rules (wp-includes, wp-admin, wp-content, xmlrpc) when root doesn't exist
		// Check if any higher priority rules exist and insert before them, or append at end
		$rules_to_check_before = array();
		foreach ( $rule_priority_order as $rule_type => $priority ) {
			if ( $priority > $current_rule_priority ) {
				switch ( $rule_type ) {
					case 'security_headers':
						$rules_to_check_before[] = '# BEGIN Remove Security Headers';
						break;
					case 'wp-includes':
						$rules_to_check_before[] = '# Disable direct access to wp-includes';
						break;
					case 'wp-admin':
						$rules_to_check_before[] = '# Disable direct access to wp-admin';
						break;
					case 'wp-content':
						$rules_to_check_before[] = '# Disable direct access to wp-content';
						break;
					case 'xmlrpc':
						$rules_to_check_before[] = '# Disable direct access to xmlrpc.php';
						break;
				}
			}
		}

		// Find the first rule with higher priority and insert before it
		foreach ( $rules_to_check_before as $check_rule ) {
			if ( preg_match( '/' . preg_quote( $check_rule, '/' ) . '/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				$insert_position = $matches[0][1];
				$rule_to_insert  = $this->ensure_proper_newlines_before_insertion( $content, $insert_position, $rule );
				$content         = substr_replace( $content, $rule_to_insert, $insert_position, 0 );
				return $content;
			}
		}

		// If no higher priority rules found, append at the end
		$content = rtrim( $content ) . "\n" . $rule;
		return $content;
	}

	/**
	 * Ensure proper newlines before rule insertion
	 */
	private function ensure_proper_newlines_before_insertion( $content, $position, $rule ) {
		// Check what comes before the insertion position
		$char_before = $position > 0 ? $content[ $position - 1 ] : '';

		// If the character before is not a newline, add one
		if ( $char_before !== "\n" && $char_before !== '' ) {
			return "\n" . $rule;
		}

		return $rule;
	}

	/**
	 * Remove .htaccess rule using atomic operations
	 */
	private function remove_htaccess_rule( $file, $directory ) {
		return $this->remove_htaccess_rule_safely( $file, $directory );
	}

	/**
	 * Safely remove .htaccess rule with atomic operations and file locking
	 */
	private function remove_htaccess_rule_safely( $file, $directory ) {
		$fs = FilesystemService::get_filesystem();
		if ( ! file_exists( $file ) || ! $fs || ! $fs->is_writable( $file ) ) {
			return false;
		}

		// Step 1: Create lock file to prevent concurrent modifications
		$lock_file     = $file . '.wptw_lock';
		$lock_acquired = false;
		$max_attempts  = 10;
		$attempt       = 0;

		while ( ! $lock_acquired && $attempt < $max_attempts ) {
			if ( ! file_exists( $lock_file ) ) {
				if ( $fs->put_contents( $lock_file, (string) getmypid() ) !== false ) {
					$lock_acquired = true;
				} else {
					return false;
				}
			} else {
				// Check if lock is stale (older than 30 seconds)
				if ( time() - filemtime( $lock_file ) > 30 ) {
					wp_delete_file( $lock_file );
				}
				usleep( 100000 ); // Wait 0.1 seconds
				++$attempt;
			}
		}

		if ( ! $lock_acquired ) {
			return false;
		}

		// Step 2: Create working copy
		$temp_file = $file . '.temp_' . bin2hex( random_bytes( 8 ) );

		if ( ! $fs->copy( $file, $temp_file, true ) ) {
			wp_delete_file( $lock_file );
			return false;
		}

		// Step 3: Set correct permissions on temp file
		$original_perms = fileperms( $file );
		if ( $original_perms !== false ) {
			$fs->chmod( $temp_file, $original_perms );
		}

		try {
			// Step 4: Read temp file content
			$content = $fs->get_contents( $temp_file );
			if ( false === $content ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 5: Check if rule exists
			$marker = ( $directory === 'security_headers' ) ? '# BEGIN Remove Security Headers' : "# Disable direct access to $directory";
			if ( strpos( $content, $marker ) === false ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return true; // Rule doesn't exist, nothing to remove
			}

			// Step 6: Remove rule using exact boundary matching
			$new_content = $this->remove_rule_from_content( $content, $directory );

			if ( $new_content === false || $new_content === $content ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			if ( $fs->put_contents( $temp_file, $new_content ) === false ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 7: Validate temp file thoroughly
			if ( ! $this->validate_htaccess_syntax( $temp_file ) ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 8: Extra validation - ensure no orphaned fragments
			if ( $this->has_orphaned_fragments( $new_content, $directory ) ) {
				wp_delete_file( $temp_file );
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 9: Atomic replace
			if ( ! $fs->move( $temp_file, $file, true ) ) {
				if ( file_exists( $temp_file ) ) {
					wp_delete_file( $temp_file );
				}
				wp_delete_file( $lock_file );
				return false;
			}

			// Step 10: Release lock
			wp_delete_file( $lock_file );

			return true;

		} catch ( \Throwable $e ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			if ( file_exists( $lock_file ) ) {
				wp_delete_file( $lock_file );
			}
			return false;
		}
	}

	/**
	 * Remove rule content using robust regex patterns
	 */
	private function remove_rule_from_content( $content, $directory ) {
		// Define robust regex patterns to handle edge cases like missing newlines
		$block_patterns = array(
			'security_headers' => array(
				'/(\n|^)# BEGIN Remove Security Headers\s*\n<IfModule[^>]*>.*?<\/IfModule>\s*# END Remove Security Headers(\s*\n|$)/s',
				'/(\w|>)# BEGIN Remove Security Headers\s*\n<IfModule[^>]*>.*?<\/IfModule>\s*# END Remove Security Headers(\s*\n|$)/s',
			),
			'wp-includes'      => array(
				// Pattern 1: Normal case with proper newlines
				'/(\n|^)# Disable direct access to wp-includes\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
				// Pattern 2: Edge case - concatenated with previous line
				'/(\w|>)# Disable direct access to wp-includes\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
			),
			'wp-admin'         => array(
				'/(\n|^)# Disable direct access to wp-admin\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
				'/(\w|>)# Disable direct access to wp-admin\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
			),
			'wp-content'       => array(
				'/(\n|^)# Disable direct access to wp-content\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
				'/(\w|>)# Disable direct access to wp-content\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
			),
			'root'             => array(
				'/(\n|^)# Disable direct access to root files, allow assets only from website, block other root PHP files\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
				'/(\w|>)# Disable direct access to root files, allow assets only from website, block other root PHP files\s*\n<IfModule[^>]*>.*?<\/IfModule>(\s*\n|$)/s',
			),
			'xmlrpc'           => array(
				'/(\n|^)# Disable direct access to xmlrpc\.php\s*\n<Files[^>]*>.*?<\/Files>(\s*\n|$)/s',
				'/(\w|>)# Disable direct access to xmlrpc\.php\s*\n<Files[^>]*>.*?<\/Files>(\s*\n|$)/s',
			),
		);

		if ( ! isset( $block_patterns[ $directory ] ) ) {
			return false;
		}

		$patterns    = $block_patterns[ $directory ];
		$new_content = $content;
		$rule_found  = false;

		// Try each pattern until we find and remove the rule
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $new_content ) ) {

				// For concatenated cases, preserve proper separation
				if ( strpos( $pattern, '(\w|>)' ) !== false ) {
					// Replace with newline to separate properly
					$new_content = preg_replace( $pattern, '$1' . "\n", $new_content );
				} else {
					// Normal removal
					$new_content = preg_replace( $pattern, '$1', $new_content );
				}

				if ( $new_content === null ) {
					return false;
				}

				$rule_found = true;
				break;
			}
		}

		if ( ! $rule_found ) {
			return $content; // Rule not found, return original content
		}

		// Clean up any extra blank lines that might be left
		$new_content = preg_replace( '/\n{3,}/', "\n\n", $new_content );

		// Ensure file ends with exactly one newline
		$new_content = rtrim( $new_content ) . "\n";

		return $new_content;
	}

	/**
	 * Validate .htaccess syntax to prevent server errors
	 */
	private function validate_htaccess_syntax( $file ) {
		if ( ! file_exists( $file ) ) {
			return false;
		}

		$fs      = FilesystemService::get_filesystem();
		$content = $fs ? $fs->get_contents( $file ) : false;
		if ( $content === false ) {
			return false;
		}

		// Validation 1: Check for unmatched tags
		$ifmodule_open  = substr_count( $content, '<IfModule' );
		$ifmodule_close = substr_count( $content, '</IfModule>' );

		if ( $ifmodule_open !== $ifmodule_close ) {
			return false;
		}

		// Count <Files ...> separately from <FilesMatch ...>. A bare '<Files' substring
		// match would incorrectly include FilesMatch occurrences in the open count while
		// '</Files>' wouldn't include </FilesMatch>, causing valid rules to be rejected
		// as syntactically unbalanced.
		$files_open  = substr_count( $content, '<Files ' );
		$files_close = substr_count( $content, '</Files>' );

		if ( $files_open !== $files_close ) {
			return false;
		}

		$filesmatch_open  = substr_count( $content, '<FilesMatch' );
		$filesmatch_close = substr_count( $content, '</FilesMatch>' );

		if ( $filesmatch_open !== $filesmatch_close ) {
			return false;
		}

		// Validation 2: Check for orphaned RewriteRule without RewriteEngine
		$lines                 = explode( "\n", $content );
		$rewrite_engine_active = false;
		$inside_ifmodule       = false;

		foreach ( $lines as $line_num => $line ) {
			$line = trim( $line );

			// Track IfModule blocks
			if ( preg_match( '/<IfModule\s+mod_rewrite\.c>/i', $line ) ) {
				$inside_ifmodule       = true;
				$rewrite_engine_active = false;
			} elseif ( preg_match( '/<\/IfModule>/i', $line ) ) {
				$inside_ifmodule       = false;
				$rewrite_engine_active = false;
			}

			// Check for RewriteEngine
			if ( $inside_ifmodule && preg_match( '/^RewriteEngine\s+On/i', $line ) ) {
				$rewrite_engine_active = true;
			}

			// Check for RewriteRule without RewriteEngine
			if ( preg_match( '/^RewriteRule/i', $line ) && ! $rewrite_engine_active ) {
				return false;
			}
		}

		// Validation 3: Check for concatenated comments (edge case detection)
		if ( preg_match( '/\w#\s*(Disable direct access|BEGIN Remove Security Headers)/', $content ) ) {
			return false;
		}

		// Validation 4: Check for critical syntax errors only
		$critical_patterns = array(
			'/<IfModule[^>]*>[^<]*<IfModule/',  // Nested IfModule without proper closing
			'/RewriteRule\s*$/',  // Empty RewriteRule
			'/RewriteCond\s*$/',  // Empty RewriteCond
			'/\[\s*\]/',  // Empty flag brackets
		);

		foreach ( $critical_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check for orphaned rule fragments after removal
	 */
	private function has_orphaned_fragments( $content, $directory ) {
		// Check for specific orphaned fragments that shouldn't exist without the main rule
		$orphan_patterns = array();

		switch ( $directory ) {
			case 'security_headers':
				$orphan_patterns = array(
					'# BEGIN Remove Security Headers',
					'Header unset Server',
					'Header unset X-Powered-By',
				);
				break;

			case 'wp-includes':
				$orphan_patterns = array(
					'# Disable direct access to wp-includes',
					'RewriteCond.*wp-includes.*\.php',
					'RewriteRule.*wp-includes',
				);
				break;

			case 'wp-admin':
				$orphan_patterns = array(
					'# Disable direct access to wp-admin',
					'RewriteCond.*wp-admin.*\.php',
					'RewriteRule.*wp-admin',
				);
				break;

			case 'wp-content':
				$orphan_patterns = array(
					'# Disable direct access to wp-content',
					'RewriteCond.*wp-content.*\.php',
					'RewriteRule.*wp-content',
				);
				break;

			case 'root':
				$orphan_patterns = array(
					'# Disable direct access to root files, allow assets only from website, block other root PHP files',
					'RewriteCond.*REQUEST_URI.*\.php\$.*NC.*',
					'RewriteCond.*HTTP_REFERER.*NC.*',
					'RewriteCond.*HTTP_USER_AGENT.*WordPress.*NC.*',
				);
				break;

			case 'xmlrpc':
				$orphan_patterns = array(
					'# Disable direct access to xmlrpc.php',
					'<Files xmlrpc.php>',
					'Deny from all',
				);
				break;

		}

		foreach ( $orphan_patterns as $pattern ) {
			if ( preg_match( '/' . preg_quote( $pattern, '/' ) . '/i', $content ) ) {
				return true;
			}
		}

		return false;
	}
}
