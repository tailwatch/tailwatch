<?php
namespace Tailwatch\Admin\App\Api\Rest\Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Controllers\CronJobs\CronJobManager;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerificationKeysController;
use Tailwatch\Admin\App\Api\Controllers\Verification\VerifyStatusController;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Services\Auth\JwtService;
use Tailwatch\Admin\App\Api\Services\IpService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * One-shot remote provisioning bootstrap for dashboard-driven onboarding.
 *
 * POST tailwatch/v1/provision, authenticated by a WordPress core Application
 * Password (the admin approved it on their own site via authorize-application.php;
 * this plugin never sees a WordPress password). Core authenticates the Basic
 * credentials before dispatch, so current_user_can() in the permission_callback
 * evaluates the approving admin. No nonce is used because nonces protect COOKIE
 * authentication from CSRF; a cross-origin form cannot set the Authorization
 * header (REST API Handbook, Authentication).
 *
 * In ONE atomic call it: refuses if already connected (race-safe lock + the
 * minting guard), mints the pairing credentials, stores the license binding
 * (full field validation reused from the browser plane), records append-only
 * consent, notifies the admin, and returns the keys once together with a
 * provisioning-purpose token pair whose /dispatch reach is limited to the
 * onboarding subset (RemoteActionAllowlist). REST-only by design: this action
 * is deliberately NOT in the ActionDispatcher map, so it can never be reached
 * with a JWT.
 */
class ProvisionController {

	const LOCK_OPTION        = 'tailwatch_provision_lock';
	const LOCK_TTL           = 60;
	const CONSENT_LOG_OPTION = 'tailwatch_connect_consent_log';

	/**
	 * Consent records retained. Older ones are dropped as new ones arrive.
	 */
	const CONSENT_LOG_MAX = 50;
	const MAX_BODY_BYTES     = 32768;
	const RATE_LIMIT_PREFIX  = 'tailwatch_provision_rl_';
	const RATE_LIMIT_MAX     = 5;
	const RATE_LIMIT_WINDOW  = 60;

	/**
	 * UUID of the Application Password that authenticated this request, captured
	 * for the consent record. Empty when a different auth scheme was used.
	 *
	 * @var string
	 */
	private static $app_password_uuid = '';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Fires during core authentication (before route callbacks), letting the
		// consent record name the exact credential that performed the provisioning.
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'capture_app_password' ), 10, 2 );
	}

	/**
	 * Remember which Application Password authenticated the request.
	 *
	 * @param \WP_User $user Authenticated user (unused).
	 * @param array    $item Application password row, including its uuid.
	 * @return void
	 */
	public static function capture_app_password( $user, $item ) {
		unset( $user );
		self::$app_password_uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
	}

	/**
	 * Register the provisioning route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'tailwatch/v1',
			'/provision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'provision' ),
				'permission_callback' => array( $this, 'can_provision' ),
			)
		);
	}

	/**
	 * Only an administrator may provision; on multisite only a network admin, the
	 * same elevation rule NetworkActionGate applies to network-affecting operations.
	 *
	 * @return true|WP_Error
	 */
	public function can_provision() {
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'tailwatch_rest_forbidden',
				__( 'You do not have permission to provision this site.', 'tailwatch' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * The one-shot provisioning callback.
	 *
	 * @param WP_REST_Request $request Current request (JSON body = license payload).
	 * @return WP_REST_Response|WP_Error
	 */
	public function provision( WP_REST_Request $request ) {
		// Owner kill switch. Set in wp-config.php, so it can only be changed with
		// filesystem access — an attacker holding an Application Password, or even
		// full wp-admin, cannot lift it. Sites that will never be connected from the
		// cloud dashboard can remove this route's surface entirely; connecting from
		// wp-admin (Settings -> License) is unaffected, it runs on the AJAX plane.
		if ( defined( 'TAILWATCH_DISABLE_REMOTE_PROVISION' ) && TAILWATCH_DISABLE_REMOTE_PROVISION ) {
			return new WP_Error(
				'tailwatch_provision_disabled',
				__( 'Remote provisioning is disabled on this site.', 'tailwatch' ),
				array( 'status' => 403 )
			);
		}

		// Bound repeated attempts per IP. Core auth already rejects bad credentials,
		// so this is not the authentication control — it caps scanning and stops a
		// caller from thrashing the provisioning lock. Counts every attempt, since a
		// legitimate site is provisioned once.
		$ip = IpService::get_client_ip();
		if ( $this->is_rate_limited( $ip ) ) {
			return new WP_Error(
				'tailwatch_provision_rate_limited',
				__( 'Too many provisioning attempts. Please try again shortly.', 'tailwatch' ),
				array( 'status' => 429 )
			);
		}
		$this->increment_rate_limit( $ip );

		// Defense in depth: core already refuses Application Passwords on plain HTTP,
		// but a site can filter them back on; do not inherit that foot-gun. Local
		// development environments are exempt.
		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return new WP_Error(
				'tailwatch_provision_https_required',
				__( 'Provisioning requires an HTTPS site.', 'tailwatch' ),
				array( 'status' => 403 )
			);
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new WP_Error(
				'tailwatch_provision_payload_too_large',
				__( 'Provisioning payload too large.', 'tailwatch' ),
				array( 'status' => 413 )
			);
		}

		$payload = $request->get_json_params();
		$vetted  = $this->vet_payload( is_array( $payload ) ? $payload : array() );
		if ( is_wp_error( $vetted ) ) {
			return $vetted;
		}

		// Winner-takes-all: add_option is INSERT-or-fail, so exactly one concurrent
		// provision call proceeds (the read-then-act 409 alone would be a TOCTOU).
		if ( ! $this->acquire_lock() ) {
			return new WP_Error(
				'tailwatch_provision_in_progress',
				__( 'Another provisioning attempt is already in progress.', 'tailwatch' ),
				array( 'status' => 409 )
			);
		}

		try {
			// Race-protected already-connected refusal (the minting step re-checks it
			// as the authoritative guard; this keeps the orphan sweep below safe).
			$activation = ( new VerifyStatusController() )->get_plugin_activation_status();
			if ( is_array( $activation ) && ! empty( $activation['extended_connected'] ) ) {
				return new WP_Error(
					'tailwatch_provision_already_connected',
					__( 'This site is already connected. Please disconnect it before reconnecting.', 'tailwatch' ),
					array( 'status' => 409 )
				);
			}

			// A disconnect deletes the keys but historically left pairing rows behind;
			// the site is not connected here, so every remaining row is an orphan.
			$this->sweep_orphaned_pairings();

			// Mint the pairing credentials via the same code path the browser plane
			// uses: includes the manage_options re-check and the 409 connected guard.
			$keys = ( new VerificationKeysController() )->tailwatch_get_generated_cta_keys();
			if ( ! isset( $keys['code'] ) || 200 !== $keys['code'] ) {
				return $this->error_from_legacy( $keys );
			}

			// Store the license binding. Reuses the browser-plane validator (required
			// fields, plan/role allowlists, e-mail + expiry validation, per-field
			// sanitisation) and runs the immediate relay verification.
			$stored = ( new VerifyStatusController() )->tailwatch_update_plugin_activation( wp_json_encode( $vetted ) );
			if ( ! isset( $stored['code'] ) || 200 !== $stored['code'] ) {
				// Never leave half-provisioned trust state: retire the minted keys.
				( new DBModel() )->tailwatch_delete_all_cta_keys();
				return $this->error_from_legacy( $stored );
			}

			// The write reported success — but storing the licence also runs an immediate
			// verification against the licence service, and when that service ANSWERS with a
			// rejection (400/401/403/418/419) the plugin treats the connection as killed:
			// it deletes the licence and every pairing key, including the ones minted a few
			// lines above. tailwatch_update_plugin_activation() still returns 200, because
			// from its point of view the write did succeed.
			//
			// Responding 200 here is then the worst possible outcome: the caller is handed
			// credentials that no longer exist, /authenticate refuses them, and — because a
			// connected-looking site refuses to be provisioned again — there is no way back
			// without a manual disconnect. Check the post-condition rather than trying to
			// read the verifier's mind: if the licence is gone, provisioning did not happen.
			$survived = ( new VerifyStatusController() )->get_plugin_activation_status();
			if ( ! is_array( $survived ) || empty( $survived['extended_connected'] ) ) {
				// The kill path clears these already; doing it again costs nothing and keeps
				// the guarantee local to this method rather than inherited from another one.
				( new DBModel() )->tailwatch_delete_all_cta_keys();

				Log::warning(
					'Provisioning was undone by licence verification: the licence service rejected this licence.',
					array(
						'feature' => 'connect',
						'action'  => 'provision_license_rejected',
					)
				);

				return new WP_Error(
					'tailwatch_provision_license_rejected',
					__( 'The licence service refused this licence when the site checked it, so the connection was not kept. Confirm the licence is active for this site, then provision again.', 'tailwatch' ),
					array( 'status' => 502 )
				);
			}

			// Schedule the recurring jobs now, while we know a licence exists.
			//
			// Cron jobs normally self-schedule from an admin_init hook that is only added
			// on an admin or cron request, and at activation there is no licence yet so the
			// licence-verification job opts out. A site provisioned entirely over the API
			// and never opened in wp-admin would therefore never schedule it, silently
			// disabling the 12-hourly licence check that is how a remote disconnect
			// reaches the site. $force bypasses the request-context gate for exactly this.
			try {
				CronJobManager::get_instance()->schedule_all( true );
			} catch ( \Throwable $e ) {
				// Never fail a completed provision over scheduling; the dashboard can still
				// force a check via the tailwatch_verify_license action.
				Log::warning(
					'Provisioning completed but cron scheduling failed.',
					array(
						'feature' => 'connect',
						'action'  => 'provision_cron_schedule_failed',
						'origin'  => 'system',
					)
				);
			}

			// Consent + bookkeeping. setup_started mirrors the wizard consent marker:
			// the admin consented on the dashboard AND approved on this site.
			update_option( 'tailwatch_setup_started', true, false );
			$this->append_consent_record();

			// Surface the pairing in the Activity Log, where administrators actually
			// look — the consent record is an internal option and the e-mail can be
			// missed or filtered. Being a logged event, it can also raise a push
			// notification, so an unexpected pairing is noticed immediately.
			Log::info(
				'Site provisioned remotely from the Tailwatch dashboard',
				array(
					'feature'  => 'auth',
					'action'   => 'remote_provision_completed',
					'title'    => 'Remote provisioning',
					'actor_id' => get_current_user_id(),
					'ip'       => $ip,
				)
			);

			$this->notify_admin_paired();

			// First token pair, provisioning purpose: its /dispatch reach is limited
			// to the onboarding subset. The dashboard obtains full management tokens
			// later via /authenticate with the returned credentials.
			$tokens = ( new JwtService() )->generate_jwt( 'service_account_' . $keys['cta_id'], $ip, 'provision' );

			return new WP_REST_Response(
				array(
					'code'            => 200,
					'message'         => __( 'Site provisioned successfully.', 'tailwatch' ),
					'cta_id'          => $keys['cta_id'],
					'cta_secret'      => $keys['cta_secret'],
					'auth_header_key' => $keys['auth_header_key'],
					'tokens'          => $tokens,
				),
				200
			);
		} catch ( \Throwable $e ) {
			// A finally without a catch releases the lock but lets the throwable
			// escape the REST callback as a raw PHP fatal — no JSON, no status, and
			// a half-provisioned site the caller cannot reason about. Retire any keys
			// minted before the throw so trust state is never left half-built.
			( new DBModel() )->tailwatch_delete_all_cta_keys();
			Log::error(
				'Remote provisioning failed unexpectedly: ' . $e->getMessage(),
				array(
					'feature'   => 'auth',
					'action'    => 'remote_provision_failed',
					'exception' => $e,
				)
			);

			return new WP_Error(
				'tailwatch_provision_failed',
				__( 'Provisioning could not be completed.', 'tailwatch' ),
				array( 'status' => 500 )
			);
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Bound, whitelisted shape check for the license payload. Unknown keys are
	 * dropped; the reused browser-plane validator then enforces required fields
	 * and per-field validation. This layer only caps size and shape so the
	 * downstream validator never sees unbounded input.
	 *
	 * @param array $payload Decoded JSON body.
	 * @return array|WP_Error Vetted payload, or an error.
	 */
	private function vet_payload( $payload ) {
		$invalid = function ( $field ) {
			return new WP_Error(
				'tailwatch_provision_invalid_field',
				__( 'Invalid provisioning payload.', 'tailwatch' ),
				array(
					'status' => 400,
					'field'  => $field,
				)
			);
		};

		$vetted  = array();
		$scalars = array( 'email', 'licenseKey', 'planName', 'connectionDateTime', 'userId', 'startDate', 'endDate', 'role', 'headerKey' );
		foreach ( $scalars as $field ) {
			if ( ! isset( $payload[ $field ] ) ) {
				continue;
			}
			if ( ! is_scalar( $payload[ $field ] ) || strlen( (string) $payload[ $field ] ) > 255 ) {
				return $invalid( $field );
			}
			$vetted[ $field ] = (string) $payload[ $field ];
		}

		if ( isset( $payload['route_tokens'] ) ) {
			if ( ! is_array( $payload['route_tokens'] ) || count( $payload['route_tokens'] ) > 10 ) {
				return $invalid( 'route_tokens' );
			}
			$vetted['route_tokens'] = array();
			foreach ( $payload['route_tokens'] as $key => $value ) {
				if ( ! is_string( $key ) || ! is_scalar( $value ) || strlen( (string) $value ) > 255 ) {
					return $invalid( 'route_tokens' );
				}
				$vetted['route_tokens'][ sanitize_key( $key ) ] = (string) $value;
			}
		}

		if ( isset( $payload['devices'] ) ) {
			// The dashboard sends either a device count (scalar; the browser plane
			// stores it raw with a default of 1) or a device list (array). Accept
			// both, bounded, so the remote flow matches the browser flow exactly.
			$devices_ok = ( is_scalar( $payload['devices'] ) && strlen( (string) $payload['devices'] ) <= 10 )
				|| ( is_array( $payload['devices'] ) && count( $payload['devices'] ) <= 50 );
			if ( ! $devices_ok ) {
				return $invalid( 'devices' );
			}
			// Every other field is cleaned by a sanitiser downstream; this one is
			// stored as-is by VerifyStatusController, and /provision declares no
			// `args` so WP_REST_Request::sanitize_params() does nothing for it.
			// Reaching the same result the /dispatch plane gets from
			// ActionDispatcher::sanitize_request_payload().
			$vetted['devices'] = is_array( $payload['devices'] )
				? map_deep( $payload['devices'], 'sanitize_text_field' )
				: sanitize_text_field( (string) $payload['devices'] );
		}

		// Enforced here rather than only in the reused browser-plane validator,
		// which does not run until after the pairing sweep and the key mint. An
		// empty body vets clean (every field above is optional), so without this
		// it would destroy pairing rows an administrator had minted but not yet
		// bound, and only then fail on the missing fields.
		$required = array( 'email', 'licenseKey', 'planName', 'connectionDateTime', 'userId', 'startDate', 'role', 'headerKey' );
		foreach ( $required as $field ) {
			if ( ! isset( $vetted[ $field ] ) || '' === $vetted[ $field ] ) {
				return $invalid( $field );
			}
		}

		return $vetted;
	}

	/**
	 * Whether this IP has exhausted its provisioning attempts for the window.
	 * Mirrors the AuthController login limiter (transient, fixed window).
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function is_rate_limited( $ip ) {
		$attempts = get_transient( self::RATE_LIMIT_PREFIX . md5( $ip ) );
		return false !== $attempts && $attempts >= self::RATE_LIMIT_MAX;
	}

	/**
	 * Count one provisioning attempt against this IP.
	 *
	 * @param string $ip Client IP.
	 * @return void
	 */
	private function increment_rate_limit( $ip ) {
		$key      = self::RATE_LIMIT_PREFIX . md5( $ip );
		$attempts = get_transient( $key );
		set_transient( $key, ( $attempts ? $attempts + 1 : 1 ), self::RATE_LIMIT_WINDOW );
	}

	/**
	 * INSERT-or-fail mutex via add_option. A crashed request cannot wedge
	 * provisioning forever: a lock older than LOCK_TTL is broken and re-acquired.
	 *
	 * @return bool Whether the lock was acquired.
	 */
	private function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, (string) time(), '', false ) ) {
			return true;
		}
		$stamp = (int) get_option( self::LOCK_OPTION );
		if ( $stamp > 0 && ( time() - $stamp ) > self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
			return (bool) add_option( self::LOCK_OPTION, (string) time(), '', false );
		}
		return false;
	}

	/**
	 * Delete pairing rows left behind by past disconnects. Only called when the
	 * site is NOT connected, so any surviving row is inert residue; the row
	 * belonging to a still-present cta_id is kept regardless as belt-and-braces.
	 *
	 * @return void
	 */
	private function sweep_orphaned_pairings() {
		global $wpdb;

		$current = get_option( 'tailwatch_cta_id' );
		$keep    = ( is_string( $current ) && '' !== $current ) ? 'tailwatch_cta_user_' . $current : '';
		$like    = $wpdb->esc_like( 'tailwatch_cta_user_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; bounded pairing-row sweep during provisioning, mirrors the deactivation cleanup idiom.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$like
			)
		);
		foreach ( (array) $names as $name ) {
			if ( '' !== $keep && $name === $keep ) {
				continue;
			}
			delete_option( $name );
		}
	}

	/**
	 * Append-only consent record: who provisioned, when, over what transport,
	 * with which credential. Entries are never modified or removed by code.
	 *
	 * @return void
	 */
	private function append_consent_record() {
		$log = get_option( self::CONSENT_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$user  = wp_get_current_user();
		$log[] = array(
			'user_id'           => (int) $user->ID,
			'user_login'        => (string) $user->user_login,
			'timestamp'         => gmdate( 'Y-m-d H:i:s' ),
			'is_ssl'            => is_ssl(),
			'provenance'        => 'dashboard_provision',
			'app_password_uuid' => self::$app_password_uuid,
			'plugin_version'    => defined( 'TAILWATCH_VERSION' ) ? TAILWATCH_VERSION : '',
		);

		// Append-only with no cap would grow this option without bound on a site
		// that reconnects repeatedly. Consent records matter most recently, so keep
		// a rolling window rather than the whole history.
		if ( count( $log ) > self::CONSENT_LOG_MAX ) {
			$log = array_slice( $log, -self::CONSENT_LOG_MAX );
		}

		update_option( self::CONSENT_LOG_OPTION, $log, false );
	}

	/**
	 * Tell the admin their site was just paired, so an unexpected pairing is noticed
	 * immediately.
	 *
	 * @return void
	 */
	private function notify_admin_paired() {
		$user = wp_get_current_user();
		if ( empty( $user->user_email ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Site connected to the Tailwatch dashboard', 'tailwatch' ),
			$site_name
		);
		$message = sprintf(
			/* translators: 1: user login, 2: UTC timestamp. */
			__( 'This site was connected to a Tailwatch dashboard account by "%1$s" on %2$s UTC. If you did not expect this, disconnect the site from Tailwatch Settings and review your administrator accounts and application passwords.', 'tailwatch' ),
			$user->user_login,
			gmdate( 'Y-m-d H:i:s' )
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Map a legacy {code,message} controller result onto a REST error.
	 *
	 * @param mixed $result Controller return value.
	 * @return WP_Error
	 */
	private function error_from_legacy( $result ) {
		$code    = ( is_array( $result ) && isset( $result['code'] ) ) ? (int) $result['code'] : 500;
		$message = ( is_array( $result ) && isset( $result['message'] ) && is_string( $result['message'] ) )
			? $result['message']
			: __( 'Provisioning failed.', 'tailwatch' );

		return new WP_Error(
			'tailwatch_provision_failed',
			$message,
			array( 'status' => ( $code >= 400 && $code < 600 ) ? $code : 500 )
		);
	}
}
