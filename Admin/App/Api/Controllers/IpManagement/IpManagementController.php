<?php
/**
 * IP Management controller – entire-site blocking, feature flags.
 *
 * @package Tailwatch
 * @subpackage IpManagement
 */

namespace Tailwatch\Admin\App\Api\Controllers\IpManagement;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\IpManagement\RuleModel;
use Tailwatch\Admin\App\Api\Models\IpManagement\WhitelistModel;
use Tailwatch\Admin\App\Api\Services\IpManagement\GetIpServices;
use Tailwatch\Admin\App\Api\Services\IpManagement\IpAccessService;
use Tailwatch\Admin\App\Api\Controllers\Hooks\HookControllers;
use Tailwatch\Admin\App\Api\Services\GeoIp2\GeoIPService;
use Tailwatch\Admin\App\Api\Controllers\Features\OptionsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IpManagementController
 */
class IpManagementController {

	private $rule_model;
	private $geoip;
	private $whitelist;

	public function __construct() {
		$this->rule_model = new RuleModel();
		$this->geoip      = new GeoIPService();
		$this->whitelist  = new WhitelistModel();

		$hooks = new HookControllers();
		// Entire-site blocking covers every human-facing surface so a blocked IP
		// or country can reach nothing but the block page. The front-end has no
		// escape; the login + admin surfaces keep a logged-in-administrator
		// recovery valve so a mistaken rule can never lock the owner out.
		$hooks->add_action_hook( 'template_redirect', array( $this, 'check_and_block_entire_site' ), 1, 0 );
		$hooks->add_action_hook( 'login_init', array( $this, 'check_and_block_login' ), 1, 0 );
		$hooks->add_action_hook( 'admin_init', array( $this, 'check_and_block_admin' ), 1, 0 );
		// Machine surfaces, so "entire site" really means everything: REST returns a
		// 403 JSON error; XML-RPC dies 403. Both honor the same entire_site rules.
		// (Login via REST app-passwords / XML-RPC is already covered by the
		// authenticate hook; these guard the data surfaces.) The init handler is
		// gated on XMLRPC_REQUEST so it is a no-op on every other surface.
		$hooks->add_filter_hook( 'rest_authentication_errors', array( $this, 'check_and_block_rest' ), 10, 1 );
		$hooks->add_action_hook( 'init', array( $this, 'check_and_block_xmlrpc' ), 1, 0 );
	}

	public function wptw_ips_managment_options() {
		$options_controller = new OptionsController();
		return $options_controller->get_features_options( 'default_feature_settings', 'default_ips_managment', true );
	}

	public function wptw_ips_managment_is_enabled() {
		$feature_settings = $this->wptw_ips_managment_options();

		if ( empty( $feature_settings ) ) {
			return array(
				'parent_enable'     => false,
				'whitelist_feature' => false,
				'blacklist_feature' => false,
				'country_feature'   => false,
			);
		}

		// country_feature (field_3) is a pro/Business-only inner feature — pro
		// sets is_locked on it for non-qualifying plans, so a Business→Basic
		// downgrade must read as disabled at runtime even if the stored
		// selection is still true. Whitelist/blacklist are free; no lock guard.
		$country_locked = $feature_settings['field_3']['is_locked'] ?? false;

		return array(
			'parent_enable'     => true,
			'whitelist_feature' => $feature_settings['field_1']['options']['option']['selected'] ?? false,
			'blacklist_feature' => $feature_settings['field_2']['options']['option']['selected'] ?? false,
			'country_feature'   => ! $country_locked && ( $feature_settings['field_3']['options']['option']['selected'] ?? false ),
		);
	}

	/**
	 * Resolve the block-page appearance. The built-in page is ALWAYS rendered
	 * (no enable toggle) — these are the "Block Page" tab fields. Each value
	 * falls back to the built-in default when unset, so the page renders
	 * correctly on every install even before the fields are seeded/saved.
	 *
	 * @return array{background_color:string,accent_color:string,show_logo:bool,show_countdown:bool,heading_temporary:string,message_temporary:string,heading_permanent:string,message_permanent:string}
	 */
	public function wptw_get_block_page_design() {
		$defaults = array(
			'background_color'  => '#667eea',
			'accent_color'      => '#764ba2',
			'background_image'  => '',
			'show_logo'         => true,
			'show_countdown'    => true,
			'heading_temporary' => __( 'Temporarily Blocked', 'tailwatch' ),
			'message_temporary' => __( 'Access to this website is temporarily restricted.', 'tailwatch' ),
			'heading_permanent' => __( 'Access Restricted', 'tailwatch' ),
			'message_permanent' => __( 'Access to this website is restricted.', 'tailwatch' ),
		);

		$settings = $this->wptw_ips_managment_options();
		if ( empty( $settings ) ) {
			return $defaults;
		}

		// The design controls live as sub_options of the type=design parent field_4.
		$sub = $settings['field_4']['sub_options'] ?? array();
		$val = function ( $field, $default ) use ( $sub ) {
			$value = $sub[ $field ]['options']['option']['value'] ?? null;
			return ( null === $value || '' === $value ) ? $default : $value;
		};
		$sel = function ( $field, $default ) use ( $sub ) {
			return isset( $sub[ $field ]['options']['option']['selected'] )
				? (bool) $sub[ $field ]['options']['option']['selected']
				: $default;
		};

		$bg     = sanitize_hex_color( $val( 'field_1', '' ) );
		$accent = sanitize_hex_color( $val( 'field_2', '' ) );
		$bg_img = esc_url_raw( $val( 'field_3', '' ) );

		return array(
			'background_color'  => $bg ? $bg : $defaults['background_color'],
			'accent_color'      => $accent ? $accent : $defaults['accent_color'],
			'background_image'  => $bg_img,
			'show_logo'         => $sel( 'field_4', true ),
			'show_countdown'    => $sel( 'field_5', true ),
			'heading_temporary' => sanitize_text_field( $val( 'field_6', $defaults['heading_temporary'] ) ),
			'message_temporary' => sanitize_textarea_field( $val( 'field_7', $defaults['message_temporary'] ) ),
			'heading_permanent' => sanitize_text_field( $val( 'field_8', $defaults['heading_permanent'] ) ),
			'message_permanent' => sanitize_textarea_field( $val( 'field_9', $defaults['message_permanent'] ) ),
		);
	}

	/**
	 * Render a live preview of the block page from a draft design (the values
	 * the user is currently editing, which may be unsaved). Returns the HTML so
	 * the dashboard can show it in an iframe. Any field not posted falls back to
	 * the saved/default design. `block_type=temporary` previews the countdown.
	 *
	 * Payload contract (JSON in $post_data):
	 *   { "draft": { "<register>": { "value": ..., "selected": bool }, ... },
	 *     "variant": "temporary"|"permanent", "device": "desktop"|"mobile" }
	 * `draft` keys are the sub_option register strings; missing keys fall back
	 * to the saved/default design. Returns the html at the top level so the
	 * dispatcher wraps it as { success:true, data:{ code, html } }.
	 *
	 * @param string $post_data JSON string.
	 * @return array{code:int,html:string}
	 */
	public function wptw_preview_block_page( $post_data ) {
		// $post_data is already unslashed by the dispatcher (AjaxRequestController
		// wp_unslash $_POST['data']); a second unslash here would strip legitimate
		// JSON escapes (e.g. \n in multi-line messages).
		$body = array();
		if ( is_string( $post_data ) && '' !== $post_data ) {
			$decoded = json_decode( $post_data, true );
			if ( is_array( $decoded ) ) {
				$body = $decoded;
			}
		}

		$draft   = ( isset( $body['draft'] ) && is_array( $body['draft'] ) ) ? $body['draft'] : array();
		$variant = isset( $body['variant'] ) ? sanitize_key( $body['variant'] ) : 'temporary';
		if ( ! in_array( $variant, array( 'temporary', 'permanent' ), true ) ) {
			$variant = 'temporary';
		}
		// $body['device'] ('desktop'|'mobile') is accepted but unused — layout is responsive.

		// Saved settings (or built-in defaults) are the base; the draft overrides them.
		$design = $this->wptw_get_block_page_design();

		// Frontend register key => array( internal design key, value type ).
		$map = array(
			'block_page_background_color'  => array( 'background_color', 'color' ),
			'block_page_accent_color'      => array( 'accent_color', 'color' ),
			'block_page_show_logo'         => array( 'show_logo', 'bool' ),
			'block_page_show_countdown'    => array( 'show_countdown', 'bool' ),
			'block_page_heading_temporary' => array( 'heading_temporary', 'text' ),
			'block_page_message_temporary' => array( 'message_temporary', 'textarea' ),
			'block_page_heading_permanent' => array( 'heading_permanent', 'text' ),
			'block_page_message_permanent' => array( 'message_permanent', 'textarea' ),
			'block_page_background_image'  => array( 'background_image', 'url' ),
		);

		foreach ( $map as $register => $info ) {
			if ( ! isset( $draft[ $register ] ) || ! is_array( $draft[ $register ] ) ) {
				continue; // not in the draft → keep saved/default.
			}
			list( $key, $type ) = $info;
			$clean = $this->sanitize_design_value(
				$draft[ $register ]['value'] ?? '',
				! empty( $draft[ $register ]['selected'] ),
				$type
			);

			// An invalid colour must not blank the gradient — keep saved/default.
			if ( 'color' === $type && '' === $clean ) {
				continue;
			}
			$design[ $key ] = $clean;
		}

		$block_data = array(
			'block_type' => $variant,
			'reason'     => '',
			'retry_time' => ( 'temporary' === $variant ) ? ( strtotime( current_time( 'mysql' ) ) + 600 ) : null,
		);

		// The mobile router (Routing::parse_request) requires both 'message' and an
		// integer 'code'; 'data' is optional. The web AJAX path only checks 'code'
		// and reads the top-level 'html', so that stays put.
		return array(
			'code'    => 200,
			'message' => __( 'Request successful', 'tailwatch' ),
			'html'    => $this->build_block_page_html( $block_data, $design ),
		);
	}

	/**
	 * Sanitize one draft design value by its declared type.
	 *
	 * @param mixed  $value    Raw draft value.
	 * @param bool   $selected Draft "selected" flag (checkbox state).
	 * @param string $type     color|bool|text|textarea|url.
	 * @return string|bool
	 */
	private function sanitize_design_value( $value, $selected, $type ) {
		switch ( $type ) {
			case 'color':
				$value = is_string( $value ) ? trim( $value ) : '';
				return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? strtoupper( $value ) : '';
			case 'bool':
				// The checkbox state is carried by `selected`; `value` is just the
				// on-constant ("1") which the frontend keeps even when unchecked.
				// Trusting `value` would make the toggle impossible to turn off.
				return (bool) $selected;
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'url':
				$value = (string) $value;
				// Allow inline data:image URLs so a freshly-picked (unsaved) file previews.
				if ( preg_match( '#^data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=\r\n]+$#i', $value ) ) {
					return $value;
				}
				return esc_url_raw( $value );
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Front-end surface (theme pages). Full block, no exemption — a blocked
	 * visitor sees nothing but the block page here.
	 */
	public function check_and_block_entire_site() {
		if ( is_admin() ) {
			return;
		}
		$this->maybe_block_request( false );
	}

	/**
	 * Login page (wp-login.php). Blocked too, but a logged-in administrator is
	 * exempt so a mistaken rule can never lock the owner out of recovery.
	 */
	public function check_and_block_login() {
		$this->maybe_block_request( true );
	}

	/**
	 * Admin surface (wp-admin, admin-ajax, admin-post). Same recovery valve as
	 * the login page.
	 */
	public function check_and_block_admin() {
		$this->maybe_block_request( true );
	}

	/**
	 * REST API surface (/wp-json/). Returns a 403 WP_Error for a blocked IP/country
	 * so the request never dispatches. A logged-in administrator is exempt (the same
	 * recovery valve as the login/admin surfaces — wp-admin and the block editor ride on REST).
	 *
	 * @param mixed $errors Current REST auth result (null | true | WP_Error).
	 * @return mixed Unchanged result, or a 403 WP_Error when blocked.
	 */
	public function check_and_block_rest( $errors ) {
		if ( is_wp_error( $errors ) ) {
			return $errors; // keep an existing auth failure as-is
		}
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return $errors; // admin recovery valve
		}
		$block = $this->evaluate_entire_site_block( GetIpServices::wptw_get_client_ip() );
		if ( ! $block ) {
			return $errors;
		}
		$message = ! empty( $block['reason'] ) ? $block['reason'] : 'Access to this website is restricted.';
		return new \WP_Error( 'wptw_ip_blocked', $message, array( 'status' => 403 ) );
	}

	/**
	 * XML-RPC surface (xmlrpc.php). Denies the whole request with 403 for a blocked
	 * IP/country. Gated on XMLRPC_REQUEST so this init callback is a no-op on every
	 * other surface (front-end / admin / REST / cron). No cookie-based recovery valve
	 * is possible this early; the wp-admin recovery valve still protects the owner.
	 */
	public function check_and_block_xmlrpc() {
		if ( ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		$block = $this->evaluate_entire_site_block( GetIpServices::wptw_get_client_ip() );
		if ( ! $block ) {
			return;
		}
		status_header( 403 );
		nocache_headers();
		$message = ! empty( $block['reason'] ) ? $block['reason'] : 'Access to this website is restricted.';
		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}

	/**
	 * Shared entry point for every surface: resolve the IP, evaluate the
	 * entire-site rules, and render the block page when blocked.
	 *
	 * @param bool $allow_admin_recovery When true (login/admin), a logged-in
	 *                                   administrator is never blocked so the
	 *                                   owner always keeps a way back in.
	 */
	private function maybe_block_request( $allow_admin_recovery ) {
		if ( $allow_admin_recovery && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$ip    = GetIpServices::wptw_get_client_ip();
		$block = $this->evaluate_entire_site_block( $ip );
		if ( ! $block ) {
			return;
		}

		// The configured block page must itself stay reachable, otherwise the
		// blocked visitor can never see it (and a redirect to it would loop now
		// that every surface is guarded).
		if ( ! empty( $block['block_page'] ) && $this->is_block_page_request( $block['block_page'] ) ) {
			return;
		}

		$this->show_block_page( $block );
		exit;
	}

	/**
	 * Decide whether $ip is blocked by an entire-site IP or country rule.
	 *
	 * @param string $ip Client IP.
	 * @return array|null Block result array when blocked, null when allowed
	 *                    (invalid IP, whitelisted, feature off, or no match).
	 */
	private function evaluate_entire_site_block( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			Log::error(
				'Invalid IP address',
				array(
					'feature' => 'ip_management',
					'action'  => 'check_and_block_entire_site',
					'title'   => 'IP Management',
					'ip'      => $ip,
				)
			);
			return null;
		}

		// Whitelist = fully trusted (single login_defender exemption): a whitelisted
		// IP or country bypasses the entire-site block too.
		if ( $this->whitelist->is_ip_whitelisted( $ip ) ) {
			return null;
		}

		$country = $this->geoip->get_country( $ip );

		if ( $country && 'Unknown' !== $country ) {
			if ( $this->whitelist->is_country_whitelisted( $country ) ) {
				return null;
			}
		}

		$feature_settings = $this->wptw_ips_managment_is_enabled();

		if ( $feature_settings['blacklist_feature'] ) {
			$ip_block_result = $this->check_ip_rules( $ip );
			if ( $ip_block_result['blocked'] ) {
				return $ip_block_result;
			}
		}

		if ( $feature_settings['country_feature'] && $country && 'Unknown' !== $country ) {
			$country_block_result = $this->check_country_rules( $country, $ip );
			if ( $country_block_result['blocked'] ) {
				return $country_block_result;
			}
		}

		return null;
	}

	/**
	 * Is the CURRENT request the configured block page itself? Lets a blocked
	 * visitor sent to a custom block page actually see it (and only it). Only
	 * meaningful on the front-end; login/admin never serve it.
	 *
	 * @param string|int $block_page Post ID or site-relative path.
	 * @return bool
	 */
	private function is_block_page_request( $block_page ) {
		if ( is_numeric( $block_page ) ) {
			return absint( $block_page ) === absint( get_queried_object_id() );
		}

		$current_path = wp_parse_url( add_query_arg( array() ), PHP_URL_PATH );
		$target_path  = wp_parse_url( home_url( $block_page ), PHP_URL_PATH );
		if ( empty( $current_path ) || empty( $target_path ) ) {
			return false;
		}
		return untrailingslashit( $current_path ) === untrailingslashit( $target_path );
	}

	private function check_ip_rules( $ip ) {
		$result = array(
			'blocked'    => false,
			'block_type' => null,
			'reason'     => '',
			'retry_time' => null,
			'block_page' => null,
			'source'     => 'ip',
		);

		// Get all active IP rules
		$rules = $this->rule_model->get_active_rules( 'ip' );

		foreach ( $rules as $rule ) {
			$data = json_decode( $rule->value, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				Log::error(
					'Invalid JSON for IP rule',
					array(
						'feature' => 'ip_management',
						'action'  => 'check_ip_rules',
						'title'  => 'IP Management',
						'option'  => $rule->option,
					)
				);
				continue;
			}

			if ( ( $data['scope'] ?? 'login_forms' ) !== 'entire_site' ) {
				continue;
			}

			if ( ! IpAccessService::is_ip_in_range( $ip, $data['ip_range'] ) ) {
				continue;
			}

			if ( $data['block_type'] === 'temporary' ) {
				$block_end_time = strtotime( $data['block_start_time'] ) + $data['block_duration'];

				if ( strtotime( current_time( 'mysql' ) ) >= $block_end_time ) {
					Log::info(
						'Temporary IP block expired',
						array(
							'feature'  => 'ip_management',
							'action'   => 'check_ip_rules',
							'title'  => 'IP Management',
							'ip_range' => $data['ip_range'],
						)
					);
					$this->rule_model->unblock_ip_range( $data['ip_range'], $this->geoip );
					continue;
				}

				$result['retry_time'] = $block_end_time;
			}

			$result['blocked']    = true;
			$result['block_type'] = $data['block_type'];
			// Empty when the admin set no per-rule message — build_block_page_html
			// then falls back to the (customizable) design message for the type.
			$result['reason']     = $data['reason'] ?? '';
			$result['block_page'] = $data['block_page'] ?? null;

			Log::info(
				'IP blocked by rule with scope=entire_site',
				array(
					'feature'  => 'ip_management',
					'action'   => 'check_ip_rules',
					'title'  => 'IP Management',
					'ip'       => $ip,
					'ip_range' => $data['ip_range'],
				)
			);

			return $result;
		}

		return $result;
	}

	private function check_country_rules( $country, $ip ) {
		$result = array(
			'blocked'    => false,
			'block_type' => null,
			'reason'     => '',
			'retry_time' => null,
			'block_page' => null,
			'source'     => 'country',
		);

		// Allow PRO plugin to filter this
		$result = apply_filters( 'wptw_check_country_entire_site_block', $result, $country, $ip );

		return $result;
	}

	private function show_block_page( $block_data ) {
		$block_page = $block_data['block_page'];

		if ( ! empty( $block_page ) ) {
			if ( is_numeric( $block_page ) ) {
				$post_id = absint( $block_page );
				$post    = get_post( $post_id );

				if ( $post && $post->post_status === 'publish' ) {
					$redirect_url = get_permalink( $post_id );

					if ( $redirect_url ) {
						wp_safe_redirect( $redirect_url, 302 );
						exit;
					}
				}
			} else {
				$redirect_url = home_url( $block_page );
				wp_safe_redirect( $redirect_url, 302 );
				exit;
			}
		}

		$this->show_default_block_page( $block_data );
	}

	private function show_default_block_page( $block_data ) {
		// Full custom HTML override (advanced) still wins.
		$custom_template = apply_filters( 'wptw_entire_site_block_template', null, $block_data );
		if ( $custom_template ) {
			if ( ! headers_sent() ) {
				status_header( 403 );
				nocache_headers();
			}
			echo wp_kses_post( $custom_template );
			exit;
		}

		$design  = $this->wptw_get_block_page_design();
		$is_temp = ( 'temporary' === ( $block_data['block_type'] ?? 'permanent' ) );
		$heading = $is_temp ? $design['heading_temporary'] : $design['heading_permanent'];

		wp_die(
			wp_kses_post( nl2br( esc_html( $this->build_block_page_html( $block_data, $design ) ) ) ),
			esc_html( $heading ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Build the plain-text block message from the resolved design. Returns a
	 * string (no exit) so it is unit-testable.
	 *
	 * @param array $block_data Block result (block_type, reason, retry_time).
	 * @param array $design     Appearance from wptw_get_block_page_design().
	 * @return string
	 */
	private function build_block_page_html( $block_data, $design ) {
		$is_temp = ( 'temporary' === ( $block_data['block_type'] ?? 'permanent' ) );

		$message = ! empty( $block_data['reason'] )
			? $block_data['reason']
			: ( $is_temp ? $design['message_temporary'] : $design['message_permanent'] );

		$parts = array( $message );
		if ( $is_temp && ! empty( $block_data['retry_time'] ) ) {
			$parts[] = __( 'This is a temporary restriction. Please try again later.', 'tailwatch' );
		}
		$parts[] = __( 'If you believe this is an error, please contact the website administrator.', 'tailwatch' );

		return implode( "\n\n", array_filter( array_map( 'trim', $parts ) ) );
	}
}
