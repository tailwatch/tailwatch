<?php
/**
 * Redirections manager: feature options and CRUD for redirect rules.
 *
 * @package Tailwatch\Admin\App\Api\Controllers\Redirections
 */

namespace Tailwatch\Admin\App\Api\Controllers\Redirections;

defined( 'ABSPATH' ) || exit;

use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Models\RedirectionModel;
use Tailwatch\Admin\App\Api\Controllers\Base\BaseController;

/**
 * Manages redirection feature and redirect rules.
 */
class RedirectionsManager extends BaseController {

	/**
	 * Feature check exemptions (methods that skip feature checks).
	 *
	 * @var array
	 */
	protected $tailwatch_feature_check_exemptions = array(
		'tailwatch_get_posts_by_post_type',
		'tailwatch_get_all_post_types',
	);

	/**
	 * Constructor: bootstraps redirection rules.
	 */
	public function __construct() {
		new RedirectionRules();
	}

	/**
	 * Returns redirection feature status.
	 *
	 * @return array
	 */
	public function tailwatch_get_feature_status() {
		return $this->tailwatch_redirection_feature_enable();
	}

	/**
	 * Gets default redirection manager options.
	 *
	 * @return array|null
	 */
	public function tailwatch_redirect_manager_options() {
		$key                = 'default_feature_settings';
		$option             = 'default_redirection_manager';
		$is_active          = true;
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( $key, $option, $is_active );
	}

	/**
	 * Whether redirection feature is enabled.
	 *
	 * @return array{parent_enable:bool,feature_enable:bool}
	 */
	public function tailwatch_redirection_feature_enable() {
		$feature_enable = $this->tailwatch_redirect_manager_options();

		if ( empty( $feature_enable ) ) {
			return array(
				'parent_enable'  => false,
				'feature_enable' => false,
			);
		}

		$selected = $feature_enable['field_1']['options']['option']['selected'] ?? false;

		if ( true === $selected ) {
			return array(
				'parent_enable'  => true,
				'feature_enable' => true,
			);
		}

		return array(
			'parent_enable'  => true,
			'feature_enable' => false,
		);
	}

	/**
	 * Inserts a redirect rule into the database.
	 *
	 * @param array  $rule_data    Rule data.
	 * @param string $source_url   Source URL.
	 * @param string $match_type   Match type.
	 * @param string $option_value Option (base path) value.
	 * @return int|false
	 */
	private function tailwatch_insert_redirect_rule( $rule_data, $source_url, $match_type, $option_value ) {

		$db_data = array(
			'user_id'       => get_current_user_id(),
			'child_of'      => 0,
			'key'           => 'default_redirection_rules',
			'option'        => $option_value,
			'value'         => wp_json_encode( $rule_data ),
			'type'          => $match_type,
			'type_state'    => 'active',
			'date_created'  => current_time( 'mysql' ),
			'date_modified' => current_time( 'mysql' ),
			'is_active'     => 1,
		);

		$db_data_format     = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );
		$feature_controller = new DBModel();
		return $feature_controller->insert_row( $db_data, $db_data_format );
	}

	/**
	 * Computes base path (option key) for rule grouping.
	 *
	 * @param string $source_url        Source URL.
	 * @param string $match_type        Match type.
	 * @param bool   $ignore_parameters Whether to ignore query parameters.
	 * @param bool   $case_sensitive    Whether comparison is case-sensitive.
	 * @return string
	 */
	private function get_base_path( $source_url, $match_type, $ignore_parameters, $case_sensitive ) {
		$parsed_url = wp_parse_url( $source_url );
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : $source_url;
		$path       = '/' . ltrim( $path, '/' );

		// Strip home URL subdirectory prefix from absolute source URLs
		$path = RedirectionRules::strip_home_path( $path );

		if ( 'exact' === $match_type ) {
			$path = RedirectionRules::trim_trailing_slash( $path );
			if ( $ignore_parameters ) {
				$parsed_path = wp_parse_url( $path, PHP_URL_PATH );
				$path        = ( false !== $parsed_path && null !== $parsed_path && '' !== $parsed_path ) ? $parsed_path : $path;
			}
			if ( ! $case_sensitive ) {
				$path = strtolower( $path );
			}
			return $path;
		}

		// For pattern and regex, extract the static prefix.
		if ( 'pattern' === $match_type ) {
			// Split at wildcard (*).
			$parts = explode( '*', $path, 2 );
			$path  = $parts[0];
		} elseif ( 'regex' === $match_type ) {
			// Extract the longest static prefix before regex-specific characters.
			$static_parts = preg_split( '/[\[\(\{<].*?[\]\)\}>]|\\d\+/', $path, 2, PREG_SPLIT_NO_EMPTY );
			$path         = ! empty( $static_parts[0] ) ? $static_parts[0] : $path;
		}

		$path = RedirectionRules::trim_trailing_slash( $path );
		if ( $ignore_parameters ) {
			$parsed_path = wp_parse_url( $path, PHP_URL_PATH );
			$path        = ( false !== $parsed_path && null !== $parsed_path && '' !== $parsed_path ) ? $parsed_path : $path;
		}
		if ( ! $case_sensitive ) {
			$path = strtolower( $path );
		}

		return ( '' !== $path && null !== $path ) ? $path : '/';
	}

	/**
	 * Auto-detects match type from the source URL shape.
	 *
	 * - `#...#` wrapped → regex
	 * - contains `*`    → pattern
	 * - otherwise       → exact
	 *
	 * @param string $source_url Source URL.
	 * @return string One of 'exact', 'pattern', 'regex'.
	 */
	private function detect_match_type( $source_url ) {
		if ( preg_match( '/^#[^#]+#$/', $source_url ) ) {
			return 'regex';
		}
		if ( false !== strpos( $source_url, '*' ) ) {
			return 'pattern';
		}
		return 'exact';
	}

	/**
	 * Creates a new redirection rule.
	 *
	 * @param string $post_data JSON post data.
	 * @return array
	 */
	public function tailwatch_create_redirection_rules( $post_data ) {
		try {

			if ( empty( $post_data ) || ! is_string( $post_data ) ) {
				Log::error(
					'Invalid or empty input data for creating redirection rule.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_create_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Input data is empty or not a string.',
					)
				);

				return array(
					'code'    => 400,
					'message' => __( 'Invalid or empty input data.', 'tailwatch' ),
				);
			}

			// Decode JSON data.
			$data = json_decode( wp_unslash( $post_data ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Invalid JSON format for redirection rule data.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_create_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => json_last_error_msg(),
					)
				);

				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON format: ', 'tailwatch' ) . json_last_error_msg(),
				);
			}

			// Define required fields.
			$required_fields = array( 'source_url', 'target_url', 'status_code' );
			foreach ( $required_fields as $field ) {
				if ( ! isset( $data[ $field ] ) || empty( trim( $data[ $field ] ) ) ) {
					Log::error(
						"Missing or empty required field: $field",
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_create_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Field $field is not set or empty.",
						)
					);

					return array(
						'code'    => 400,
						/* translators: %s: the required field name */
						'message' => sprintf( __( 'Missing or empty required field: %s', 'tailwatch' ), $field ),
					);
				}
			}

			$source_url = esc_url_raw( trim( $data['source_url'] ) );

			$site_url    = home_url();
			$site_host   = wp_parse_url( $site_url, PHP_URL_HOST );
			$source_host = wp_parse_url( $source_url, PHP_URL_HOST );

			if ( $source_host && $site_host ) {
				$site_domain     = preg_quote( $site_host, '/' );
				$is_valid_domain = preg_match( "/^(.+\.)?$site_domain$/i", $source_host );

				if ( ! $is_valid_domain ) {
					Log::error(
						"Invalid source URL domain: $source_url",
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_create_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Expected domain: $site_host",
						)
					);

					return array(
						'data'    => array(),
						/* translators: %s: the allowed site host */
						'message' => sprintf( __( 'Invalid source URL domain. Only URLs from %s are allowed.', 'tailwatch' ), $site_host ),
						'code'    => 400,
					);
				}
			}

			$match_type        = $this->detect_match_type( $source_url );
			$ignore_parameters = isset( $data['ignore_parameters'] ) ? filter_var( $data['ignore_parameters'], FILTER_VALIDATE_BOOLEAN ) : false;
			$case_sensitive    = isset( $data['case_sensitive'] ) ? filter_var( $data['case_sensitive'], FILTER_VALIDATE_BOOLEAN ) : false;

			$option_value       = $this->get_base_path( $source_url, $match_type, $ignore_parameters, $case_sensitive );
			$feature_controller = new DBModel();
			$existing_rule      = $feature_controller->get_logs_only_by_type( $option_value, 'default_redirection_rules' );
			if ( $existing_rule ) {
				Log::error(
					'Duplicate redirect rule detected.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_create_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => "A rule with source URL $source_url and match type $match_type already exists.",
					)
				);

				return array(
					'code'    => 400,
					'message' => __( 'A redirect rule with this source URL and match type already exists.', 'tailwatch' ),
				);
			}

			$rule_data = array(
				'source_url'              => $source_url,
				'target_url'              => '',
				'status_code'             => absint( $data['status_code'] ),
				'match_type'              => $match_type,
				'case_sensitive'          => $case_sensitive,
				'ignore_parameters'       => $ignore_parameters,
				'conditions'              => isset( $data['conditions'] ) ? wp_json_encode( $data['conditions'] ) : '',
				'condition_logic'         => isset( $data['condition_logic'] ) ? sanitize_text_field( $data['condition_logic'] ) : 'AND',
				'apply_conditions'        => isset( $data['apply_conditions'] ) ? filter_var( $data['apply_conditions'], FILTER_VALIDATE_BOOLEAN ) : false,
				'enabled'                 => isset( $data['enabled'] ) ? filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN ) : true,
				'post_id'                 => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0,
				'post_type'               => isset( $data['post_type'] ) ? sanitize_text_field( $data['post_type'] ) : null,
				'last_count'              => 0,
				'last_access'             => null,
			);

			if ( is_numeric( $data['target_url'] ) ) {
				// Assume target_url is a post ID.
				$post_id   = absint( $data['target_url'] );
				$permalink = get_permalink( $post_id );
				if ( false === $permalink ) {
					Log::error(
						'Invalid post ID for target URL.',
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_create_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Post ID $post_id does not exist or has no permalink.",
						)
					);

					return array(
						'code'    => 400,
						'message' => __( 'Invalid post ID for target URL.', 'tailwatch' ),
					);
				}
				$rule_data['target_url'] = esc_url_raw( $permalink );
				$rule_data['post_id']    = $post_id;
			} else {
				// Assume target_url is a custom URL.
				$rule_data['target_url'] = esc_url_raw( trim( $data['target_url'] ) );
				if ( empty( $rule_data['target_url'] ) ) {
					Log::error(
						'Invalid target URL.',
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_create_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => 'Target URL is empty after sanitization.',
						)
					);

					return array(
						'code'    => 400,
						'message' => __( 'Invalid target URL.', 'tailwatch' ),
					);
				}
			}

			$valid_status_codes = array( 301, 302, 303, 304, 307, 308 );
			if ( ! in_array( $rule_data['status_code'], $valid_status_codes, true ) ) {
				Log::error(
					'Invalid status code.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_create_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Status code ' . $rule_data['status_code'] . ' is not in allowed list: ' . implode( ', ', $valid_status_codes ),
					)
				);

				return array(
					'code'    => 400,
					'message' => __( 'Invalid status code. Must be one of: ', 'tailwatch' ) . implode( ', ', $valid_status_codes ),
				);
			}

			// Validate condition_logic.
			$valid_condition_logic = array( 'AND', 'OR' );
			if ( ! in_array( $rule_data['condition_logic'], $valid_condition_logic, true ) ) {
				Log::error(
					'Invalid condition logic.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_create_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Condition logic ' . $rule_data['condition_logic'] . ' is not AND or OR.',
					)
				);

				return array(
					'code'    => 400,
					'message' => __( 'Invalid condition logic. Must be AND or OR.', 'tailwatch' ),
				);
			}

			// Validate conditions.
			if ( ! empty( $rule_data['conditions'] ) ) {
				$conditions = json_decode( $rule_data['conditions'], true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $conditions ) ) {
					Log::error(
						'Invalid conditions format.',
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_create_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => 'Conditions are not a valid JSON array: ' . json_last_error_msg(),
						)
					);

					return array(
						'code'    => 400,
						'message' => __( 'Invalid conditions format. Must be a valid JSON array.', 'tailwatch' ),
					);
				}

				$valid_condition_types = array( 'login_status', 'user_role', 'referrer', 'ip_addresses' );
				foreach ( $conditions as $condition ) {
					if ( ! isset( $condition['type'] ) || ! in_array( $condition['type'], $valid_condition_types, true ) ) {
						Log::error(
							'Invalid condition type.',
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_create_rule_failed',
								'title'  => '301 Redirection Failed',
								'detail'  => 'Condition type ' . ( $condition['type'] ?? 'undefined' ) . ' is not valid.',
							)
						);

						return array(
							'code'    => 400,
							'message' => __( 'Invalid condition type. Must be one of: ', 'tailwatch' ) . implode( ', ', $valid_condition_types ),
						);
					}

					// Validate condition-specific fields.
					switch ( $condition['type'] ) {
						case 'login_status':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid login_status enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid login_status enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || ! in_array( $condition['value'], array( 'logged_in', 'not_logged_in' ), true ) ) {
								Log::error(
									'Invalid login_status value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Value ' . ( $condition['value'] ?? 'undefined' ) . ' is not logged_in or not_logged_in.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid login_status value. Must be logged_in or not_logged_in.', 'tailwatch' ),
								);
							}
							break;
						case 'user_role':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid user_role enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid user_role enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['operator'] ) || ! in_array( $condition['operator'], array( 'has', 'doesnt_have' ), true ) ) {
								Log::error(
									'Invalid user_role operator.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Operator ' . ( $condition['operator'] ?? 'undefined' ) . ' is not has or doesnt_have.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid user_role operator. Must be has or doesnt_have.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || ! is_array( $condition['value'] ) ) {
								Log::error(
									'Invalid user_role value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Value is not an array of roles.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid user_role value. Must be an array of roles.', 'tailwatch' ),
								);
							}

							if ( ! function_exists( 'get_editable_roles' ) ) {
								include_once ABSPATH . 'wp-admin/includes/user.php';
							}
							$valid_roles = array_keys( get_editable_roles() );
							foreach ( $condition['value'] as $role ) {
								if ( ! in_array( $role, $valid_roles, true ) ) {
									Log::error(
										'Invalid user role.',
										array(
											'feature' => 'redirections',
											'action'  => 'redirection_create_rule_failed',
											'title'  => '301 Redirection Failed',
											'detail'  => "Role $role is not valid.",
										)
									);

									return array(
										'code'    => 400,
										/* translators: %s: the user role */
										'message' => sprintf( __( 'Invalid user role: %s', 'tailwatch' ), $role ),
									);
								}
							}
							break;
						case 'referrer':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid referrer enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid referrer enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || empty( trim( $condition['value'] ) ) ) {
								Log::error(
									'Invalid referrer value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Referrer value is empty or not set.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid referrer value. Must be a non-empty string.', 'tailwatch' ),
								);
							}
							break;
						case 'ip_addresses':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid ip_addresses enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid ip_addresses enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || ! is_array( $condition['value'] ) ) {
								Log::error(
									'Invalid ip_addresses value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_create_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Value is not an array of IPs.',
									)
								);

								return array(
									'code'    => 400,
									'message' => __( 'Invalid ip_addresses value. Must be an array of IPs.', 'tailwatch' ),
								);
							}
							foreach ( $condition['value'] as $ip ) {
								if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) && ! preg_match( '/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip ) ) {
									Log::error(
										'Invalid IP address or range.',
										array(
											'feature' => 'redirections',
											'action'  => 'redirection_create_rule_failed',
											'title'  => '301 Redirection Failed',
											'detail'  => "IP $ip is not valid.",
										)
									);

									return array(
										'code'    => 400,
										/* translators: %s: the IP address or range */
										'message' => sprintf( __( 'Invalid IP address or range: %s', 'tailwatch' ), $ip ),
									);
								}
							}
							break;
					}
				}
			}

			$result = $this->tailwatch_insert_redirect_rule( $rule_data, $rule_data['source_url'], $rule_data['match_type'], $option_value );
			if ( false === $result ) {
				global $wpdb;
				Log::error(
					'Failed to create redirect rule.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_create_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => $wpdb->last_error,
					)
				);

				return array(
					'code'    => 500,
					'message' => __( 'Failed to create redirect rule.', 'tailwatch' ),
				);
			}

			$log_message = sprintf(
				'Redirect rule created: %s → %s [Status: %s, Match: %s]',
				$rule_data['source_url'],
				$rule_data['target_url'],
				$rule_data['status_code'],
				$rule_data['match_type']
			);

			Log::info(
				$log_message,
				array(
					'feature' => 'redirections',
					'action'  => 'redirection_create_rule',
					'title'  => '301 Redirection',
				)
			);

			// Return success response with the inserted rule ID.
			return array(
				'code'    => 200,
				'message' => __( 'Redirect rule created successfully.', 'tailwatch' ),
			);

		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature'   => 'redirections',
					'action'    => 'redirection_create_rule_failed',
					'title'  => '301 Redirection Failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to create redirect rule.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Get all public post types with labels.
	 *
	 * @return array Response data including post types.
	 */
	public function tailwatch_get_all_post_types() {
		try {
			$post_types = get_post_types(
				array(
					'public'  => true,
					'show_ui' => true,
				),
				'objects'
			);

			$result = array();

			foreach ( $post_types as $post_type ) {
				$result[] = array(
					'post_type' => $post_type->name,
					'label'     => $post_type->labels->name,
				);
			}

			return array(
				'code'    => 200,
				'message' => __( 'Post types retrieved successfully.', 'tailwatch' ),
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature'   => 'redirections',
					'action'    => 'post_types_retrieval_failed',
					'title'  => '301 Redirection',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to retrieve post types.', 'tailwatch' ),
				'data'    => array(),
			);
		}
	}

	/**
	 * Get posts by post type from JSON request payload.
	 *
	 * @param string $post_data Raw JSON string with post type and pagination.
	 * @return array Response data including posts or error details.
	 */
	public function tailwatch_get_posts_by_post_type( $post_data ) {
		try {
			if ( empty( $post_data ) || ! is_string( $post_data ) ) {
				Log::error(
					'Invalid or empty input data for retrieving posts.',
					array(
						'feature' => 'redirections',
						'action'  => 'posts_by_post_type_retrieval_failed',
						'title'  => '301 Redirection',
						'detail'  => 'Input data is empty or not a string.',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid or empty input data.', 'tailwatch' ),
				);
			}

			$json_data = json_decode( wp_unslash( $post_data ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Invalid JSON format for post retrieval.',
					array(
						'feature' => 'redirections',
						'action'  => 'posts_by_post_type_retrieval_failed',
						'title'  => '301 Redirection',
						'detail'  => json_last_error_msg(),
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON format: ', 'tailwatch' ) . json_last_error_msg(),
				);
			}

			// Validate required post_type.
			if ( ! isset( $json_data['post_type'] ) || empty( $json_data['post_type'] ) ) {
				Log::error(
					'Post type is required.',
					array(
						'feature' => 'redirections',
						'action'  => 'posts_by_post_type_retrieval_failed',
						'title'  => '301 Redirection',
						'detail'  => 'Post type field is missing or empty.',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Post type is required.', 'tailwatch' ),
				);
			}

			$post_type = sanitize_text_field( $json_data['post_type'] );
			if ( ! post_type_exists( $post_type ) ) {
				Log::error(
					'Invalid post type.',
					array(
						'feature' => 'redirections',
						'action'  => 'posts_by_post_type_retrieval_failed',
						'title'  => '301 Redirection',
						'detail'  => "Post type $post_type does not exist.",
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid post type.', 'tailwatch' ),
				);
			}

			$limit = isset( $json_data['limit'] ) ? absint( $json_data['limit'] ) : 10;
			$page  = isset( $json_data['page'] ) ? absint( $json_data['page'] ) : 1;

			$max_limit = 100;
			$limit     = min( $limit, $max_limit );
			if ( $limit < 1 ) {
				$limit = 10;
			}
			if ( $page < 1 ) {
				$page = 1;
			}

			// Calculate offset for pagination.
			$offset = ( $page - 1 ) * $limit;

			// Fetch posts.
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => $limit,
					'offset'         => $offset,
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( false === $posts ) {
				Log::error(
					'Failed to retrieve posts for post type.',
					array(
						'feature' => 'redirections',
						'action'  => 'posts_by_post_type_retrieval_failed',
						'title'  => '301 Redirection',
						'detail'  => "Error retrieving posts for post type $post_type.",
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to retrieve posts for post type.', 'tailwatch' ),
					'data'    => array(),
				);
			}

			$post_data = array();
			foreach ( $posts as $post ) {
				$permalink = get_permalink( $post->ID );
				if ( $permalink ) {
					$post_data[] = array(
						'id'    => $post->ID,
						'title' => get_the_title( $post->ID ) ? get_the_title( $post->ID ) : '(No Title)',
						'url'   => $permalink,
					);
				}
			}

			$total = wp_count_posts( $post_type )->publish;

			return array(
				'code'       => 200,
				'message'    => __( 'Posts retrieved successfully.', 'tailwatch' ),
				'post_type'  => $post_type,
				'posts'      => $post_data,
				'pagination' => array(
					'total'       => (int) $total,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => ceil( $total / $limit ),
				),
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature'   => 'redirections',
					'action'    => 'posts_by_post_type_retrieval_failed',
					'title'  => '301 Redirection',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to retrieve posts.', 'tailwatch' ),
				'data'    => array(),
			);
		}
	}

	/**
	 * Get all redirect rules with pagination support.
	 *
	 * @param string $post_data Raw JSON string containing pagination parameters.
	 * @return array Response including redirect rules and pagination meta.
	 */
	public function tailwatch_get_all_redirect_rules( $post_data ) {
		try {
			if ( empty( $post_data ) || ! is_string( $post_data ) ) {
				Log::error(
					'Invalid or empty input data for retrieving redirect rules.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_rule_retrieval_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Input data is empty or not a string.',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid or empty input data.', 'tailwatch' ),
				);
			}

			$json_data = json_decode( wp_unslash( $post_data ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Invalid JSON format for retrieving redirect rules.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_rule_retrieval_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => json_last_error_msg(),
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON format: ', 'tailwatch' ) . json_last_error_msg(),
				);
			}

			$limit = isset( $json_data['limit'] ) ? absint( $json_data['limit'] ) : 10;
			$page  = isset( $json_data['page'] ) ? absint( $json_data['page'] ) : 1;
			if ( $limit <= 0 || $page <= 0 ) {
				Log::error(
					'Invalid pagination parameters.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_rule_retrieval_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => "Limit: $limit, Page: $page are invalid.",
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid limit or page number.', 'tailwatch' ),
				);
			}

			$redirection_model = new RedirectionModel();
			$rules             = $redirection_model->get_all_redirect_rules( 'default_redirection_rules', 'active', $limit, $page );
			if ( $rules['data'] ) {
				return array(
					'data'       => $rules['data'],
					'pagination' => array(
						'total'       => $rules['total'],
						'page'        => $rules['page'],
						'limit'       => $rules['limit'],
						'total_pages' => $rules['total_pages'],
					),
					'message'    => __( 'Redirect rules retrieved successfully.', 'tailwatch' ),
					'code'       => 200,
				);
			} else {
				return array(
					'code'    => 404,
					'message' => __( 'No redirect rules found.', 'tailwatch' ),
				);
			}
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature'   => 'redirections',
					'action'    => 'redirection_rule_retrieval_failed',
					'title'  => '301 Redirection Failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to retrieve redirect rules.', 'tailwatch' ),
			);
		}
	}

	/**
	 * Update an existing redirect rule.
	 *
	 * @param string $post_data Raw JSON string containing rule data.
	 * @return array Response indicating success or failure.
	 */
	public function tailwatch_update_redirect_rule( $post_data ) {
		try {

			if ( empty( $post_data ) || ! is_string( $post_data ) ) {
				Log::error(
					'Invalid or empty input data for updating redirect rule.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Input data is empty or not a string.',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid or empty input data.', 'tailwatch' ),
				);
			}

			// Decode JSON data.
			$data = json_decode( wp_unslash( $post_data ), true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Invalid JSON format for updating redirect rule.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => json_last_error_msg(),
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid JSON format: ', 'tailwatch' ) . json_last_error_msg(),
				);
			}

			// Validate ID.
			$required_fields = array( 'id' );
			foreach ( $required_fields as $field ) {
				if ( ! isset( $data[ $field ] ) || empty( trim( $data[ $field ] ) ) ) {
					Log::error(
						"Missing or empty required field: $field",
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_update_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Field $field is not set or empty.",
						)
					);
					return array(
						'code'    => 400,
						/* translators: %s: the required field name */
						'message' => sprintf( __( 'Missing or empty required field: %s', 'tailwatch' ), $field ),
					);
				}
			}

			$id = absint( $data['id'] );
			if ( $id <= 0 ) {
				Log::error(
					'Invalid rule ID.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => "Rule ID $id is not valid.",
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid rule ID.', 'tailwatch' ),
				);
			}

			$feature_controller = new DBModel();
			// Fetch existing rule.
			$value = $feature_controller->get_feature_value( $id );
			if ( empty( $value ) ) {
				Log::error(
					'No data found for the specified rule ID.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => "Rule ID $id does not exist.",
					)
				);
				return array(
					'code'    => 404,
					'message' => __( 'No data found for the specified rule ID.', 'tailwatch' ),
				);
			}

			$rules_data = json_decode( $value, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				Log::error(
					'Failed to parse existing rule data.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => json_last_error_msg(),
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to parse existing rule data.', 'tailwatch' ),
				);
			}

			// Build updated rule data, merging with existing data.
			$rule_data = array(
				'source_url'              => isset( $data['source_url'] ) ? esc_url_raw( trim( $data['source_url'] ) ) : ( isset( $rules_data['source_url'] ) ? $rules_data['source_url'] : '' ),
				'target_url'              => isset( $data['target_url'] ) ? $data['target_url'] : ( isset( $rules_data['target_url'] ) ? $rules_data['target_url'] : '' ), // Handle as string or post ID.
				'status_code'             => isset( $data['status_code'] ) ? absint( $data['status_code'] ) : ( isset( $rules_data['status_code'] ) ? $rules_data['status_code'] : 301 ),
				'match_type'              => isset( $rules_data['match_type'] ) ? $rules_data['match_type'] : 'exact',
				'case_sensitive'          => isset( $data['case_sensitive'] ) ? filter_var( $data['case_sensitive'], FILTER_VALIDATE_BOOLEAN ) : ( isset( $rules_data['case_sensitive'] ) ? $rules_data['case_sensitive'] : false ),
				'ignore_parameters'       => isset( $data['ignore_parameters'] ) ? filter_var( $data['ignore_parameters'], FILTER_VALIDATE_BOOLEAN ) : ( isset( $rules_data['ignore_parameters'] ) ? $rules_data['ignore_parameters'] : false ),
				'conditions'              => isset( $data['conditions'] ) ? wp_json_encode( $data['conditions'] ) : ( isset( $rules_data['conditions'] ) ? $rules_data['conditions'] : '' ),
				'condition_logic'         => isset( $data['condition_logic'] ) ? sanitize_text_field( $data['condition_logic'] ) : ( isset( $rules_data['condition_logic'] ) ? $rules_data['condition_logic'] : 'AND' ),
				'apply_conditions'        => isset( $data['apply_conditions'] ) ? filter_var( $data['apply_conditions'], FILTER_VALIDATE_BOOLEAN ) : ( isset( $rules_data['apply_conditions'] ) ? (bool) $rules_data['apply_conditions'] : true ),
				'enabled'                 => isset( $data['enabled'] ) ? filter_var( $data['enabled'], FILTER_VALIDATE_BOOLEAN ) : ( isset( $rules_data['enabled'] ) ? $rules_data['enabled'] : true ),
				'post_id'                 => isset( $data['post_id'] ) ? absint( $data['post_id'] ) : ( isset( $rules_data['post_id'] ) ? $rules_data['post_id'] : 0 ),
				'post_type'               => isset( $data['post_type'] ) ? sanitize_text_field( $data['post_type'] ) : ( isset( $rules_data['post_type'] ) ? $rules_data['post_type'] : null ),
				'last_count'              => isset( $rules_data['last_count'] ) ? absint( $rules_data['last_count'] ) : 0,
				'last_access'             => isset( $rules_data['last_access'] ) ? $rules_data['last_access'] : null,
			);

			// Validate required fields if provided.
			$required_fields_check = array( 'source_url', 'target_url', 'status_code' );
			foreach ( $required_fields_check as $field ) {
				if ( isset( $data[ $field ] ) && empty( trim( $data[ $field ] ) ) ) {
					Log::error(
						"Empty required field: $field",
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_update_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Field $field is empty in update data.",
						)
					);
					return array(
						'code'    => 400,
						/* translators: %s: the required field name */
						'message' => sprintf( __( 'Empty required field: %s', 'tailwatch' ), $field ),
					);
				}
			}

			// Ensure required fields are not empty in the final rule_data.
			foreach ( array( 'source_url', 'status_code' ) as $field ) {
				if ( empty( $rule_data[ $field ] ) ) {
					Log::error(
						"Required field $field cannot be empty.",
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_update_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Field $field is empty in the rule data.",
						)
					);
					return array(
						'code'    => 400,
						/* translators: %s: the required field name */
						'message' => sprintf( __( 'Required field %s cannot be empty in the rule.', 'tailwatch' ), $field ),
					);
				}
			}

			$source_url  = $rule_data['source_url'];
			$site_url    = home_url();
			$site_host   = wp_parse_url( $site_url, PHP_URL_HOST );
			$source_host = wp_parse_url( $source_url, PHP_URL_HOST );

			if ( $source_host && $site_host ) {
				$site_domain     = preg_quote( $site_host, '/' );
				$is_valid_domain = preg_match( "/^(.+\.)?$site_domain$/i", $source_host );

				if ( ! $is_valid_domain ) {
					Log::error(
						"Invalid source URL domain: $source_url",
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_update_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => "Expected domain: $site_host",
						)
					);
					return array(
						'data'    => array(),
						/* translators: %s: the allowed site host */
						'message' => sprintf( __( 'Invalid source URL domain. Only URLs from %s are allowed.', 'tailwatch' ), $site_host ),
						'code'    => 400,
					);
				}
			}

			// Handle target_url (post ID or URL).
			if ( isset( $data['target_url'] ) ) {
				if ( is_numeric( $data['target_url'] ) ) {
					$post_id   = absint( $data['target_url'] );
					$permalink = get_permalink( $post_id );
					if ( false === $permalink ) {
						Log::error(
							'Invalid post ID for target URL.',
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_update_rule_failed',
								'title'  => '301 Redirection Failed',
								'detail'  => "Post ID $post_id does not exist or has no permalink.",
							)
						);
						return array(
							'code'    => 400,
							'message' => __( 'Invalid post ID for target URL.', 'tailwatch' ),
						);
					}
					$rule_data['target_url'] = esc_url_raw( $permalink );
					$rule_data['post_id']    = $post_id;
				} else {
					$rule_data['target_url'] = esc_url_raw( trim( $data['target_url'] ) );
					if ( empty( $rule_data['target_url'] ) ) {
						Log::error(
							'Invalid target URL.',
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_update_rule_failed',
								'title'  => '301 Redirection Failed',
								'detail'  => 'Target URL is empty after sanitization.',
							)
						);
						return array(
							'code'    => 400,
							'message' => __( 'Invalid target URL.', 'tailwatch' ),
						);
					}
					$rule_data['post_id'] = 0;
				}
			} elseif ( empty( $rule_data['target_url'] ) ) {
				Log::error(
					'Target URL cannot be empty.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Target URL is empty in the rule data.',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Target URL cannot be empty in the rule.', 'tailwatch' ),
				);
			}

			// Re-derive match_type from the (possibly updated) source URL.
			$rule_data['match_type'] = $this->detect_match_type( $rule_data['source_url'] );

			// Generate option value.
			$option_value = $this->get_base_path(
				$rule_data['source_url'],
				$rule_data['match_type'],
				$rule_data['ignore_parameters'],
				$rule_data['case_sensitive']
			);

			// Check for duplicate option value, excluding the current rule.
			$existing_rules = $feature_controller->get_logs_only_by_type( $option_value, 'default_redirection_rules' );

			if ( ! empty( $existing_rules ) ) {
				foreach ( $existing_rules as $existing_rule ) {
					if ( absint( $existing_rule['id'] ) !== $id ) {
						Log::error(
							'Duplicate redirect rule detected.',
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_update_rule_failed',
								'title'  => '301 Redirection Failed',
								'detail'  => "A rule with source URL {$rule_data['source_url']} and match type {$rule_data['match_type']} already exists.",
							)
						);
						return array(
							'code'    => 400,
							'message' => __( 'A redirect rule with this source URL and match type already exists.', 'tailwatch' ),
						);
					}
				}
			}

			// Validate status_code.
			$valid_status_codes = array( 301, 302, 303, 304, 307, 308 );
			if ( ! in_array( $rule_data['status_code'], $valid_status_codes, true ) ) {
				Log::error(
					'Invalid status code.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Status code ' . $rule_data['status_code'] . ' is not in allowed list: ' . implode( ', ', $valid_status_codes ),
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid status code. Must be one of: ', 'tailwatch' ) . implode( ', ', $valid_status_codes ),
				);
			}

			// Validate condition_logic.
			$valid_condition_logic = array( 'AND', 'OR' );
			if ( ! in_array( $rule_data['condition_logic'], $valid_condition_logic, true ) ) {
				Log::error(
					'Invalid condition logic.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => 'Condition logic ' . $rule_data['condition_logic'] . ' is not AND or OR.',
					)
				);
				return array(
					'code'    => 400,
					'message' => __( 'Invalid condition logic. Must be AND or OR.', 'tailwatch' ),
				);
			}

			// Validate conditions.
			if ( isset( $data['conditions'] ) ) {
				$conditions = json_decode( $rule_data['conditions'], true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $conditions ) ) {
					Log::error(
						'Invalid conditions format.',
						array(
							'feature' => 'redirections',
							'action'  => 'redirection_update_rule_failed',
							'title'  => '301 Redirection Failed',
							'detail'  => 'Conditions are not a valid JSON array: ' . json_last_error_msg(),
						)
					);
					return array(
						'code'    => 400,
						'message' => __( 'Invalid conditions format. Must be a valid JSON array.', 'tailwatch' ),
					);
				}
				$valid_condition_types = array( 'login_status', 'user_role', 'referrer', 'ip_addresses' );
				foreach ( $conditions as $condition ) {
					if ( ! isset( $condition['type'] ) || ! in_array( $condition['type'], $valid_condition_types, true ) ) {
						Log::error(
							'Invalid condition type.',
							array(
								'feature' => 'redirections',
								'action'  => 'redirection_update_rule_failed',
								'title'  => '301 Redirection Failed',
								'detail'  => 'Condition type ' . ( $condition['type'] ?? 'undefined' ) . ' is not valid.',
							)
						);
						return array(
							'code'    => 400,
							'message' => __( 'Invalid condition type. Must be one of: ', 'tailwatch' ) . implode( ', ', $valid_condition_types ),
						);
					}
					switch ( $condition['type'] ) {
						case 'login_status':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid login_status enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid login_status enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || ! in_array( $condition['value'], array( 'logged_in', 'not_logged_in' ), true ) ) {
								Log::error(
									'Invalid login_status value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Value ' . ( $condition['value'] ?? 'undefined' ) . ' is not logged_in or not_logged_in.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid login_status value. Must be logged_in or not_logged_in.', 'tailwatch' ),
								);
							}
							break;
						case 'user_role':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid user_role enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid user_role enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['operator'] ) || ! in_array( $condition['operator'], array( 'has', 'doesnt_have' ), true ) ) {
								Log::error(
									'Invalid user_role operator.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Operator ' . ( $condition['operator'] ?? 'undefined' ) . ' is not has or doesnt_have.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid user_role operator. Must be has or doesnt_have.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || ! is_array( $condition['value'] ) ) {
								Log::error(
									'Invalid user_role value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Value is not an array of roles.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid user_role value. Must be an array of roles.', 'tailwatch' ),
								);
							}

							if ( ! function_exists( 'get_editable_roles' ) ) {
								include_once ABSPATH . 'wp-admin/includes/user.php';
							}
							$valid_roles = array_keys( get_editable_roles() );
							foreach ( $condition['value'] as $role ) {
								if ( ! in_array( $role, $valid_roles, true ) ) {
									Log::error(
										'Invalid user role.',
										array(
											'feature' => 'redirections',
											'action'  => 'redirection_update_rule_failed',
											'title'  => '301 Redirection Failed',
											'detail'  => "Role $role is not valid.",
										)
									);
									return array(
										'code'    => 400,
										/* translators: %s: the user role */
										'message' => sprintf( __( 'Invalid user role: %s', 'tailwatch' ), $role ),
									);
								}
							}
							break;
						case 'referrer':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid referrer enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid referrer enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || empty( trim( $condition['value'] ) ) ) {
								Log::error(
									'Invalid referrer value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Referrer value is empty or not set.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid referrer value. Must be a non-empty string.', 'tailwatch' ),
								);
							}
							break;
						case 'ip_addresses':
							if ( ! isset( $condition['enabled'] ) || ! is_bool( $condition['enabled'] ) ) {
								Log::error(
									'Invalid ip_addresses enabled value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Enabled value is not a boolean.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid ip_addresses enabled value. Must be true or false.', 'tailwatch' ),
								);
							}
							if ( ! isset( $condition['value'] ) || ! is_array( $condition['value'] ) ) {
								Log::error(
									'Invalid ip_addresses value.',
									array(
										'feature' => 'redirections',
										'action'  => 'redirection_update_rule_failed',
										'title'  => '301 Redirection Failed',
										'detail'  => 'Value is not an array of IPs.',
									)
								);
								return array(
									'code'    => 400,
									'message' => __( 'Invalid ip_addresses value. Must be an array of IPs.', 'tailwatch' ),
								);
							}
							foreach ( $condition['value'] as $ip ) {
								if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) && ! preg_match( '/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip ) ) {
									Log::error(
										'Invalid IP address or range.',
										array(
											'feature' => 'redirections',
											'action'  => 'redirection_update_rule_failed',
											'title'  => '301 Redirection Failed',
											'detail'  => "IP $ip is not valid.",
										)
									);
									return array(
										'code'    => 400,
										/* translators: %s: the IP address or range */
										'message' => sprintf( __( 'Invalid IP address or range: %s', 'tailwatch' ), $ip ),
									);
								}
							}
							break;
					}
				}
			}

			// Prepare database update.
			$db_data = array(
				'value'         => wp_json_encode( $rule_data ),
				'option'        => $option_value,
				'type'          => $rule_data['match_type'],
				'date_modified' => current_time( 'mysql' ),
			);

			$where = array(
				'id' => $id,
			);

			$updated = $feature_controller->update_rows( $db_data, $where );

			if ( false === $updated ) {
				global $wpdb;
				Log::error(
					'Failed to update redirect rule.',
					array(
						'feature' => 'redirections',
						'action'  => 'redirection_update_rule_failed',
						'title'  => '301 Redirection Failed',
						'detail'  => $wpdb->last_error,
					)
				);
				return array(
					'code'    => 500,
					'message' => __( 'Failed to update redirect rule.', 'tailwatch' ),
				);
			}

			$log_message = sprintf(
				'Redirect rule updated: %s → %s [Status: %s, Match: %s]',
				$rule_data['source_url'],
				$rule_data['target_url'],
				$rule_data['status_code'],
				$rule_data['match_type']
			);

			Log::info(
				$log_message,
				array(
					'feature' => 'redirections',
					'action'  => 'redirection_update_rule',
					'title'  => '301 Redirection',
					'rule_id' => $id,
				)
			);

			return array(
				'code'    => 200,
				'message' => __( 'Redirect rule updated successfully.', 'tailwatch' ),
				'rule_id' => $id,
			);
		} catch ( \Throwable $e ) {
			Log::error(
				$e->getMessage() . "\nStack Trace: " . $e->getTraceAsString(),
				array(
					'feature'   => 'redirections',
					'action'    => 'redirection_update_rule_failed',
					'title'  => '301 Redirection Failed',
					'exception' => $e,
				)
			);
			return array(
				'code'    => 500,
				'message' => __( 'Failed to update redirect rule.', 'tailwatch' ),
			);
		}
	}
}
