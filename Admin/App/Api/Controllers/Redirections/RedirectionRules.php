<?php
namespace Tailwatch\Admin\App\Api\Controllers\Redirections;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\IpService;

class RedirectionRules {

	/**
	 * Registers redirection hooks when the feature is enabled.
	 */
	public function __construct() {
		$hook_controller = new HookControllers();
		$hook_controller->add_action_hook( 'init', array( $this, 'wptw_check_redirection_feature_status' ) );
	}

	/**
	 * Checks if redirections are enabled and registers template_redirect and related hooks.
	 */
	public function wptw_check_redirection_feature_status() {
		$redirection_controller = new RedirectionsManager();
		$is_enabled             = $redirection_controller->wptw_redirection_feature_enable();

		if ( $is_enabled['feature_enable'] ) {
			$hook_controller = new HookControllers();
			$hook_controller->add_action_hook( 'template_redirect', array( $this, 'process_redirects' ), 1 );
			$hook_controller->add_filter_hook( 'redirect_canonical', array( $this, 'handle_canonical_redirect' ), 10, 2 );
			$hook_controller->add_filter_hook( 'wp_redirect', array( $this, 'wp_redirect' ), 1, 2 );
		}
	}

	/**
	 * Runs on template_redirect: finds a matching rule and performs the redirect.
	 */
	public function process_redirects() {
		try {
			$current_uri           = $this->get_current_uri();
			$request_uri_safe      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$current_query         = wp_parse_url( $request_uri_safe, PHP_URL_QUERY );
			$current_query         = ( false !== $current_query && null !== $current_query && '' !== $current_query ) ? $current_query : '';
			$current_ip            = IpService::get_client_ip();
			$referrer_raw          = wp_get_raw_referer();
			$referrer              = ( $referrer_raw && '' !== $referrer_raw ) ? $referrer_raw : '';

			if ( empty( $current_uri ) ) {
				Log::warning(
					'Current URI is empty after sanitization.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_rule_processing_failed',
						'title'  => '301 Redirection Failed',
					)
				);
				return;
			}

			$match = $this->find_matching_rule( $current_uri, $current_query, $current_ip, $referrer );
			if ( ! $match ) {
				return;
			}

			list($rule_id, $rule_data, $target_url) = $match;

			$this->update_rule_stats( $rule_id, $rule_data );

			$valid_status_codes = array( 301, 302, 303, 304, 307, 308 );
			$status_code        = absint( $rule_data['status_code'] );
			if ( ! in_array( $status_code, $valid_status_codes, true ) ) {
				Log::warning(
					"Status code $status_code is not in allowed list: " . implode( ', ', $valid_status_codes ),
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_rule_processing_failed',
						'title'  => '301 Redirection Failed',
					)
				);
				$status_code = 302;
			}

			if ( empty( $rule_data['ignore_parameters'] ) && $current_query ) {
				$target_url .= false === strpos( $target_url, '?' ) ? '?' : '&';
				$target_url .= $current_query;
			}

			// Use wp_redirect (not wp_safe_redirect): the target was set by an
			// admin via the rule CRUD form and may legitimately point to an
			// external host. wp_safe_redirect would silently fall back to
			// admin_url() for any non-whitelisted host, which is wrong here.
			$redirect_url_safe = esc_url_raw( $target_url );
			if ( $redirect_url_safe ) {
				wp_redirect( $redirect_url_safe, $status_code ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Admin-defined rule may legitimately target external host.
			}
			exit;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'redirections',
					'action'  => 'redirection_rule_processing_failed',
					'title'  => '301 Redirection Failed',
				)
			);
		}
	}

	/**
	 * Filters redirect_canonical to avoid conflicting with plugin redirects.
	 *
	 * @param string $redirect_url    The redirect URL proposed by WordPress.
	 * @param string $requested_url   The requested URL (unused; required by filter signature).
	 * @return string The redirect URL to use.
	 */
	public function handle_canonical_redirect( $redirect_url, $requested_url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		try {
			$current_uri           = $this->get_current_uri();
			$request_uri_safe      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$current_query         = wp_parse_url( $request_uri_safe, PHP_URL_QUERY );
			$current_query         = ( false !== $current_query && null !== $current_query && '' !== $current_query ) ? $current_query : '';
			$current_ip            = IpService::get_client_ip();
			$referrer_raw          = wp_get_raw_referer();
			$referrer              = ( $referrer_raw && '' !== $referrer_raw ) ? $referrer_raw : '';

			if ( empty( $current_uri ) ) {
				Log::warning(
					'Current URI is empty after sanitization.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_rule_processing_failed',
						'title'  => '301 Redirection Failed',
					)
				);
				return $redirect_url;
			}

			$match = $this->find_matching_rule( $current_uri, $current_query, $current_ip, $referrer );
			if ( ! $match ) {
				return $redirect_url;
			}

			list($rule_id, $rule_data, $target_url) = $match;

			$this->update_rule_stats( $rule_id, $rule_data );

			if ( empty( $rule_data['ignore_parameters'] ) && $current_query ) {
				$target_url .= false === strpos( $target_url, '?' ) ? '?' : '&';
				$target_url .= $current_query;
			}

			return $target_url;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'redirections',
					'action'  => 'redirection_rule_processing_failed',
					'title'  => '301 Redirection Failed',
				)
			);
			return $redirect_url;
		}
	}

	/**
	 * Filters wp_redirect location (e.g. to force HTTPS when is_ssl()).
	 *
	 * @param string $location The redirect location URL.
	 * @param int    $status   The redirect status code (unused; required by filter signature).
	 * @return string The location URL.
	 */
	public function wp_redirect( $location, $status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		try {
			if ( is_ssl() && 0 !== strpos( $location, 'https://' ) ) {
				$location = str_replace( 'http://', 'https://', $location );
			}
			return $location;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'redirections',
					'action'  => 'redirection_rule_processing_failed',
					'title'  => '301 Redirection Failed',
				)
			);
			return $location;
		}
	}

	/**
	 * Finds the first matching redirection rule for the current request.
	 *
	 * @param string $current_uri   Current request URI path (and optionally query).
	 * @param string $current_query Current query string.
	 * @param string $current_ip    Client IP.
	 * @param string $referrer      Referrer URL.
	 * @return array|false Array ( rule_id, rule_data, target_url ) or false.
	 */
	private function find_matching_rule( $current_uri, $current_query, $current_ip, $referrer ) {
		try {
			// Normalize current_uri for querying option.
			$normalized_uri = '/' . ltrim( $current_uri, '/' );
			$path_from_uri  = wp_parse_url( $normalized_uri, PHP_URL_PATH );
			$path_from_uri  = ( false !== $path_from_uri && null !== $path_from_uri && '' !== $path_from_uri ) ? $path_from_uri : $normalized_uri;
			$path_no_slash  = self::trim_trailing_slash( $path_from_uri );
			$variants       = array(
				$normalized_uri,
				rtrim( $normalized_uri, '/' ),
				$path_from_uri,
				$path_no_slash,
				strtolower( $normalized_uri ),
				strtolower( rtrim( $normalized_uri, '/' ) ),
				strtolower( $path_from_uri ),
				strtolower( $path_no_slash ),
			);

			// Remove duplicates and any empty entries.
			$variants = array_filter( array_unique( $variants ), 'strlen' );

			// Try each variant with getRecentRow.
			foreach ( $variants as $variant ) {
				$feature_controller = new DBModel();
				$rule               = $feature_controller->get_recent_row( $variant, 'default_redirection_rules' );

				if ( $rule && ! empty( $rule['value'] ) && ! empty( $rule['is_active'] ) ) {
					$rule_data = json_decode( $rule['value'], true );
					if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $rule_data ) ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							Log::warning(
								"JSON decode error for rule ID {$rule['id']}: " . json_last_error_msg(),
								array(
									'feature' => 'redirections',
									'action'  => 'redirection_rule_processing_failed',
									'title'  => '301 Redirection Failed',
								)
							);
						}
						continue;
					}

					if ( empty( $rule_data['enabled'] ) ) {
						continue;
					}

					if ( empty( $rule_data['source_url'] ) || empty( $rule_data['target_url'] ) || empty( $rule_data['status_code'] ) || empty( $rule_data['match_type'] ) ) {
						Log::warning(
							"Missing fields for rule ID {$rule['id']}.",
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_rule_processing_failed',
								'title'  => '301 Redirection Failed',
							)
						);
						continue;
					}

					$source_url        = $rule_data['source_url'];
					$match_type        = $rule_data['match_type'];
					$ignore_parameters = ! empty( $rule_data['ignore_parameters'] );
					$case_sensitive    = ! empty( $rule_data['case_sensitive'] );

					// Normalize source_url and current_uri for comparison.
					$parsed_url = wp_parse_url( $source_url );
					$source_url = $parsed_url['path'] ?? $source_url;
					if ( isset( $parsed_url['query'] ) && $ignore_parameters ) {
						$source_url .= '?' . $parsed_url['query'];
					}
					$source_url = '/' . ltrim( $source_url, '/' );

					$source_url  = self::strip_home_path( $source_url );
					$compare_uri = $current_uri;

					if ( $ignore_parameters ) {
						$path_part   = wp_parse_url( $compare_uri, PHP_URL_PATH );
						$compare_uri = ( false !== $path_part && null !== $path_part && '' !== $path_part ) ? $path_part : $compare_uri;
					}

					// Trim AFTER stripping the query — the slash before `?` only
					// becomes trailing once the query is gone.
					$source_url  = self::trim_trailing_slash( $source_url );
					$compare_uri = self::trim_trailing_slash( $compare_uri );

					if ( ! $case_sensitive ) {
						$source_url  = strtolower( $source_url );
						$compare_uri = strtolower( $compare_uri );
					}

					// Validate match based on match_type.
					$is_match = false;
					switch ( $match_type ) {
						case 'exact':
							$is_match = ( $source_url === $compare_uri );
							break;
						case 'pattern':
							$pattern  = preg_quote( $source_url, '/' );
							$pattern  = str_replace( '\\*', '.*', $pattern );
							$is_match = preg_match( "/^$pattern$/i", $compare_uri );
							break;
						case 'regex':
							$pattern = $source_url;
							if ( ! preg_match( '/^#[^#]+#$/', $pattern ) ) {
								$pattern = '#^' . preg_quote( $pattern, '#' ) . '$#';
							}
							try {
								$is_match = preg_match( $pattern, $compare_uri );
							} catch ( \Throwable $e ) {
								Log::warning(
									"Regex pattern error for rule ID {$rule['id']}: " . $e->getMessage(),
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_rule_processing_failed',
										'title'  => '301 Redirection Failed',
									)
								);
								continue 2;
							}
							break;
						default:
							Log::warning(
								"Match type {$match_type} is not valid for rule ID {$rule['id']}.",
								array(
									'feature' => 'redirections',
									'action'  => 'redirection_rule_processing_failed',
									'title'  => '301 Redirection Failed',
								)
							);
							continue 2;
					}

					if ( ! $is_match ) {
						continue;
					}

					// Check conditions. The `apply_conditions` flag is the master
					// switch — when false the conditions block is skipped entirely
					// and the rule fires on URL match alone. Missing on legacy
					// rules defaults to true so existing behavior is preserved.
					$apply_conditions = $rule_data['apply_conditions'] ?? true;
					if ( $apply_conditions && ! empty( $rule_data['conditions'] ) ) {
						$conditions = json_decode( $rule_data['conditions'], true );
						if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $conditions ) ) {
							Log::warning(
								"JSON decode error for conditions in rule ID {$rule['id']}: " . json_last_error_msg(),
								array(
									'feature' => 'redirections',
									'action'  => 'redirection_rule_processing_failed',
									'title'  => '301 Redirection Failed',
								)
							);
							continue;
						}

						$condition_logic = ! empty( $rule_data['condition_logic'] ) && 'OR' === $rule_data['condition_logic'] ? 'OR' : 'AND';
						$conditions_met  = 'AND' === $condition_logic;
						$evaluated_count = 0;

						foreach ( $conditions as $condition ) {
							// Disabled conditions are skipped entirely — they shouldn't
							// influence the match outcome under either AND or OR.
							if ( empty( $condition['enabled'] ) ) {
								continue;
							}
							++$evaluated_count;
							$condition_result = $this->evaluate_condition( $condition, $current_ip, $referrer );
							if ( 'AND' === $condition_logic && ! $condition_result ) {
								$conditions_met = false;
								break;
							} elseif ( 'OR' === $condition_logic && $condition_result ) {
								$conditions_met = true;
								break;
							}
						}

						// All conditions disabled = no active conditions, so the rule fires.
						if ( 0 === $evaluated_count ) {
							$conditions_met = true;
						}

						if ( ! $conditions_met ) {
							continue;
						}
					}

					$target_url = $rule_data['target_url'];
					if ( $this->is_redirect_loop( $current_uri, $target_url, $rule_data ) ) {
						Log::warning(
							"Redirect loop for rule ID {$rule['id']}: $current_uri -> $target_url",
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_rule_processing_failed',
								'title'  => '301 Redirection Failed',
							)
						);
						continue;
					}

					return array( $rule['id'], $rule_data, $target_url );
				}
			}

			return false;
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature' => 'redirections',
					'action'  => 'redirection_rule_processing_failed',
					'title'  => '301 Redirection Failed',
				)
			);
			return false;
		}
	}

	/**
	 * Gets current request URI (sanitized).
	 *
	 * @return string
	 */
	private function get_current_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$uri = esc_url_raw( rawurldecode( $uri ) );
		if ( ! $uri ) {
			return '';
		}

		return self::strip_home_path( $uri );
	}

	/**
	 * Evaluates a single condition against current request.
	 *
	 * @param array  $condition  Condition config (type, value, operator, etc.).
	 * @param string $current_ip Client IP.
	 * @param string $referrer   Referrer URL.
	 * @return bool
	 */
	private function evaluate_condition( $condition, $current_ip, $referrer ) {
		if ( empty( $condition['type'] ) ) {
			return false;
		}

		switch ( $condition['type'] ) {
			case 'login_status':
				if ( ! $condition['enabled'] ) {
					return false;
				}
				$required_status = $condition['value'] ?? '';
				if ( 'logged_in' === $required_status ) {
					return is_user_logged_in();
				} elseif ( 'not_logged_in' === $required_status ) {
					return ! is_user_logged_in();
				}
				return false;

			case 'user_role':
				if ( ! $condition['enabled'] ) {
					return false;
				}
				if ( ! is_user_logged_in() ) {
					return false;
				}
				$operator = $condition['operator'] ?? 'has';
				$roles    = $condition['value'] ?? array();
				if ( ! is_array( $roles ) ) {
					return false;
				}
				$user       = wp_get_current_user();
				$user_roles = (array) $user->roles;
				$has_role   = ! empty( array_intersect( $roles, $user_roles ) );
				return 'has' === $operator ? $has_role : ! $has_role;

			case 'referrer':
				if ( ! $condition['enabled'] ) {
					return false;
				}
				$required_referrer = $condition['value'] ?? '';
				if ( empty( $required_referrer ) || empty( $referrer ) ) {
					return false;
				}
				return false !== stripos( $referrer, $required_referrer );

			case 'ip_addresses':
				if ( ! $condition['enabled'] ) {
					return false;
				}
				$ip_list = $condition['value'] ?? array();
				if ( ! is_array( $ip_list ) ) {
					return false;
				}
				foreach ( $ip_list as $ip_range ) {
					if ( $this->ip_in_range( $current_ip, $ip_range ) ) {
						return true;
					}
				}
				return false;

			default:
				return false;
		}
	}

	/**
	 * Checks if an IP is within a range or CIDR.
	 *
	 * @param string $ip    IP address.
	 * @param string $range Single IP or CIDR range.
	 * @return bool
	 */
	private function ip_in_range( $ip, $range ) {
		if ( filter_var( $range, FILTER_VALIDATE_IP ) ) {
			return $range === $ip;
		}

		if ( false !== strpos( $range, '/' ) ) {
			list($subnet, $bits) = explode( '/', $range );
			if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) || ! is_numeric( $bits ) || $bits < 0 || $bits > 32 ) {
				return false;
			}
			$ip_long     = ip2long( $ip );
			$subnet_long = ip2long( $subnet );
			if ( false === $ip_long || false === $subnet_long ) {
				return false;
			}
			$mask = ~( ( 1 << ( 32 - $bits ) ) - 1 );
			return ( $ip_long & $mask ) === ( $subnet_long & $mask );
		}

		return false;
	}

	/**
	 * Strips trailing slashes from a path while preserving the root `/`.
	 *
	 * Used everywhere paths are compared so `/foo` and `/foo/` are treated
	 * as equivalent.
	 *
	 * @param string $path A URL path.
	 * @return string Path without trailing slashes (root stays as `/`).
	 */
	public static function trim_trailing_slash( $path ) {
		$trimmed = rtrim( (string) $path, '/' );
		return '' !== $trimmed ? $trimmed : '/';
	}

	/**
	 * Strips the home URL subdirectory prefix from a path so rules stored
	 * as /slug work whether WordPress lives at / or /subdir/.
	 *
	 * @param string $path An absolute-path string.
	 * @return string Path with the home subdirectory stripped.
	 */
	public static function strip_home_path( $path ) {
		$home_path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path ) {
			$home_path = rtrim( $home_path, '/' );
			// Only strip when the home dir is followed by '/' (subpath) or is an exact match.
			// Prevents /mysite-extra/page being wrongly stripped when home is /mysite.
			if ( 0 === strpos( $path, $home_path . '/' ) || $path === $home_path ) {
				$path = substr( $path, strlen( $home_path ) );
				if ( '' === $path || '/' !== $path[0] ) {
					$path = '/' . ltrim( $path, '/' );
				}
			}
		}
		return $path;
	}

	/**
	 * Detects if redirect would create a loop (target equals current).
	 *
	 * @param string $current_uri Current URI.
	 * @param string $target_url  Target URL.
	 * @param array  $rule_data   Rule data (ignore_parameters, etc.).
	 * @return bool
	 */
	private function is_redirect_loop( $current_uri, $target_url, $rule_data ) {
		// Always extract the path from target_url (handles full URLs like http://host/subdir/page).
		$t                 = wp_parse_url( $target_url, PHP_URL_PATH );
		$normalized_target = ( false !== $t && null !== $t && '' !== $t ) ? $t : $target_url;

		$normalized_target  = self::strip_home_path( $normalized_target );
		$normalized_current = $current_uri;

		if ( ! empty( $rule_data['ignore_parameters'] ) ) {
			$c                  = wp_parse_url( $normalized_current, PHP_URL_PATH );
			$normalized_current = ( false !== $c && null !== $c && '' !== $c ) ? $c : $normalized_current;
		}

		// Trim AFTER stripping the query — the slash before `?` only
		// becomes trailing once the query is gone.
		$normalized_target  = self::trim_trailing_slash( $normalized_target );
		$normalized_current = self::trim_trailing_slash( $normalized_current );

		if ( empty( $rule_data['case_sensitive'] ) ) {
			$normalized_target  = strtolower( $normalized_target );
			$normalized_current = strtolower( $normalized_current );
		}

		return $normalized_target === $normalized_current;
	}

	/**
	 * Updates rule hit count and last access time.
	 *
	 * @param int   $rule_id   Rule ID.
	 * @param array $rule_data Rule data to update.
	 */
	private function update_rule_stats( $rule_id, $rule_data ) {
		$rule_data['last_count']  = isset( $rule_data['last_count'] ) ? absint( $rule_data['last_count'] ) + 1 : 1;
		$rule_data['last_access'] = current_time( 'mysql' );

		$db_data = array(
			'value'         => wp_json_encode( $rule_data ),
			'date_modified' => current_time( 'mysql' ),
		);

		$where = array(
			'id' => $rule_id,
		);

		$feature_controller = new DBModel();
		$feature_controller->update_rows( $db_data, $where );
	}
}
