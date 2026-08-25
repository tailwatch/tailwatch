<?php
/**
 * License Verification and Management Controller
 *
 * Handles plugin activation, license verification, and status updates.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Verification
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Controllers\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Controllers\Features\SecurityFeaturesVerifyController;

/**
 * Class VerifyStatusController
 *
 * Handles plugin activation, license verification, and status updates.
 *
 * @since 1.0.0
 */
class VerifyStatusController {

	private $key    = 'plugin_activation';
	private $option = 'site_settings';

	const VERIFY_CACHE_KEY = 'tailwatch_license_verification_cache';
	const VERIFY_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	public function get_plugin_activation_status() {
		try {
			$key       = $this->key;
			$option    = $this->option;
			$db_model  = new DBModel();
			$json_data = $db_model->get_value( $option, $key );

			if ( ! $json_data ) {

				return array(
					'data'    => array(),
					'message' => __( 'No data found.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			return $json_data;
		} catch ( \Throwable $e ) {

			return array(
				'data'    => array(),
				'message' => __( 'Error retrieving extended status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_update_plugin_activation( $post_data ) {
		try {
			$existing_data = $this->get_plugin_activation_status();

			$json_data = isset( $post_data ) ? wp_unslash( $post_data ) : '';
			$data      = json_decode( $json_data, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return array(
					'data'    => array(),
					'message' => __( 'The provided data is not valid JSON.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Validate data is an array
			if ( ! is_array( $data ) ) {
				Log::warning(
					'Plugin activation update failed: Invalid data format',
					array(
						'feature'   => 'verification',
						'action'    => 'plugin_activation_update_failed',
						'title'     => 'License Verification',
						'error'     => 'Invalid data format. Expected JSON object',
						'meta_data' => array(
							'feature' => 'License Verification',
							'event'   => 'Update failed',
							'reason' => 'invalid_payload',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid data format. Expected JSON object.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			// Validate required fields for license connection
			$required_fields = array(
				'email',
				'licenseKey',
				'planName',
				'connectionDateTime',
				'userId',
				'startDate',
				'role',
				'headerKey',
			);

			if ( isset( $data['planName'] ) ) {
				$data['planName'] = self::normalize_plan_name( $data['planName'] );
			}

			// Add endDate as required only for premium plans
			if ( isset( $data['planName'] ) && in_array( $data['planName'], array( 'Business', 'Agency' ), true ) ) {
				$required_fields[] = 'endDate';
			}

			foreach ( $required_fields as $field ) {
				if ( ! isset( $data[ $field ] ) || empty( $data[ $field ] ) ) {

					return array(
						'data'    => array(),
						'message' => "Missing required field: {$field}",
						'code'    => 400,
					);
				}
			}

			// Hard-reject on invalid role — connect is user-initiated, refuse
			// rather than partial-accept. (Verify path falls back to stored.)
			$role = self::normalize_role( $data['role'] );
			if ( '' === $role ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid role provided. Allowed values are "user" or "suspended".', 'tailwatch' ),
					'code'    => 400,
				);
			}

			if ( ! filter_var( $data['email'], FILTER_VALIDATE_EMAIL ) ) {
				Log::warning(
					'Plugin activation update failed: Invalid email format',
					array(
						'feature'   => 'verification',
						'action'    => 'plugin_activation_update_failed',
						'title'     => 'License Verification',
						'email'     => $data['email'],
						'error'     => 'Invalid email format',
						'meta_data' => array(
							'feature' => 'License Verification',
							'event'   => 'Update failed',
							'reason' => 'invalid_email_format',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid email format', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$allowed_plans = array( 'Basic', 'Business', 'Agency' );
			if ( ! in_array( $data['planName'], $allowed_plans, true ) ) {
				return array(
					'data'    => array(),
					'message' => __( 'Invalid plan name provided', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$start_timestamp   = strtotime( $data['startDate'] );
			$current_timestamp = time();

			if ( ! $start_timestamp ) {
				Log::warning(
					'Plugin activation update failed: Invalid start date format',
					array(
						'feature'    => 'verification',
						'action'     => 'plugin_activation_update_failed',
						'title'      => 'License Verification',
						'start_date' => $data['startDate'] ?? 'not provided',
						'error'      => 'Invalid start date format',
						'meta_data'  => array(
							'feature' => 'License Verification',
							'event'   => 'Update failed',
							'reason' => 'invalid_start_date',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Invalid start date format', 'tailwatch' ),
					'code'    => 400,
				);
			}

			$end_timestamp = null;
			if ( in_array( $data['planName'], array( 'Business', 'Agency' ), true ) ) {
				$end_timestamp = strtotime( $data['endDate'] );
				if ( ! $end_timestamp ) {

					return array(
						'data'    => array(),
						'message' => __( 'Invalid end date format', 'tailwatch' ),
						'code'    => 400,
					);
				}

				if ( $end_timestamp < $current_timestamp ) {
					Log::warning(
						'Plugin activation update failed: License already expired',
						array(
							'feature'   => 'verification',
							'action'    => 'plugin_activation_update_failed',
							'title'     => 'License Verification',
							'end_date'  => $data['endDate'],
							'error'     => 'Cannot connect expired license',
							'meta_data' => array(
								'feature' => 'License Verification',
								'event'   => 'Update failed',
								'reason' => 'license_expired',
							),
						)
					);
					return array(
						'data'    => array(),
						'message' => __( 'Cannot connect expired license', 'tailwatch' ),
						'code'    => 400,
					);
				}
			}

			$license_data = array(
				'devices'              => $data['devices'] ?? 1,
				'email'                => sanitize_email( $data['email'] ),
				'license_key'          => sanitize_text_field( $data['licenseKey'] ),
				'plan_name'            => sanitize_text_field( $data['planName'] ),
				'connection_date_time' => sanitize_text_field( $data['connectionDateTime'] ),
				'user_id'              => sanitize_text_field( $data['userId'] ),
				'start_date'           => sanitize_text_field( $data['startDate'] ),
				'end_date'             => isset( $data['endDate'] ) ? sanitize_text_field( $data['endDate'] ) : '',
				'role'                 => $role,
				'connected_at'         => current_time( 'mysql' ),
				'connected_from_ip'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown',
				'site_url'             => TAILWATCH_GET_SITE_URL,
				'plugin_version'       => defined( 'TAILWATCH_VERSION' ) ? TAILWATCH_VERSION : '1.0.0',
				'connection_hash'      => hash( 'sha256', $data['userId'] . $data['licenseKey'] . wp_generate_password( 32, true, true ) . time() ),
				// Issued by the central API at license-issue time. Sent as
				// X-Tailwatch-Header-Key on outbound calls to the central API so
				// it can authenticate requests from this plugin instance.
				// Wiped automatically on disconnect when extended_connected
				// is removed.
				'header_key'           => sanitize_text_field( $data['headerKey'] ),
				// Per-site route tokens (sent as X-Tailwatch-Route to the relay); wiped on disconnect.
				'route_tokens'         => array(
					'push_notification' => isset( $data['route_tokens']['push_notification'] ) ? sanitize_text_field( $data['route_tokens']['push_notification'] ) : '',
					'get_user_external' => isset( $data['route_tokens']['get_user_external'] ) ? sanitize_text_field( $data['route_tokens']['get_user_external'] ) : '',
				),
			);

			$existing_data['extended_connected'] = $license_data;

			$db_data = array(
				'value' => wp_json_encode( $existing_data ),
			);

			$where = array(
				'option' => $this->option,
				'key'    => $this->key,
			);

			$db_model = new DBModel();
			$result   = $db_model->update_rows( $db_data, $where );

			if ( $result ) {
				// Clear any existing license verification cache
				delete_transient( self::VERIFY_CACHE_KEY );
				delete_option( 'tailwatch_last_license_check' );

				// Trigger immediate license verification
				$verify_controller   = new VerifyStatusController();
				$verification_result = $verify_controller->tailwatch_verify_license(true);

				$connected = $existing_data['extended_connected'] ?? array();
				$response = array(
					'data'    => array(
						'license_info'        => array(
							'email'      => $connected['email'] ?? '',
							'plan_name'  => $connected['plan_name'] ?? '',
							'user_id'    => $connected['user_id'] ?? '',
							'start_date' => $connected['start_date'] ?? '',
							'end_date'   => $connected['end_date'] ?? '',
						),
						'verification_result' => $verification_result,
					),
					'message' => __( 'License connected and verified successfully.', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				$response = array(
					'data'    => array(),
					'message' => __( 'Failed to update license information.', 'tailwatch' ),
					'code'    => 400,
				);
			}

			return $response;
		} catch ( \Throwable $e ) {
			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while connecting the license.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	public function tailwatch_get_plugin_activation() {
		try {
			$license_data = $this->get_plugin_activation_status();

			if ( $license_data && isset( $license_data['extended_connected'] ) ) {
				$extended_connected = $license_data['extended_connected'];

				// Validate license data integrity
				$validation_result = $this->validate_stored_license_data( $extended_connected );
				if ( ! $validation_result['valid'] ) {

					return array(
						'data'    => array(),
						'message' => __( 'License data validation failed: ', 'tailwatch' ) . $validation_result['error'],
						'code'    => 400,
					);
				}

				// Check license expiry
				if ( isset( $extended_connected['end_date'] ) ) {
					$end_timestamp     = strtotime( $extended_connected['end_date'] );
					$current_timestamp = time();

					if ( $end_timestamp < $current_timestamp ) {
						// License expired - mark as such
						$extended_connected['status']        = 'expired';
						$extended_connected['expired_since'] = $current_timestamp - $end_timestamp;
					} else {
						$extended_connected['status']     = 'active';
						$extended_connected['expires_in'] = $end_timestamp - $current_timestamp;
					}
				}

				$response = array(
					'data'    => $this->build_public_activation_payload( $extended_connected ),
					'message' => __( 'License connection status retrieved successfully.', 'tailwatch' ),
					'code'    => 200,
				);
			} else {
				$response = array(
					'data'    => array(),
					'message' => __( 'No license connection found. Please connect your license first.', 'tailwatch' ),
					'code'    => 404,
				);
			}

			return $response;
		} catch ( \Throwable $e ) {

			return array(
				'data'    => array(),
				'message' => __( 'An error occurred while retrieving the license connection status.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Build the public-facing license activation payload.
	 *
	 * Returns only the fields the dashboard / mobile app needs to render the
	 * connection card. Uses an explicit allowlist (not a denylist) so any
	 * future field added to extended_connected won't accidentally leak
	 * sensitive data.
	 *
	 * Intentionally NOT returned (kept server-side only):
	 *   - connection_hash    — internal pairing token; never exposed.
	 *   - header_key         — outbound API auth credential.
	 *   - connection_date_time, connected_at, connected_from_ip,
	 *     site_url, plugin_version, user_id — diagnostic / internal context.
	 *   - Per-device `_id`, `user` — Mongo IDs from the central API.
	 *   - Per-device `is_linked`, `is_primary`, `createdAt`, `updatedAt`,
	 *     `__v`, `linked_at`, `status` — bookkeeping fields the UI does
	 *     not surface; reduces payload size and information leakage.
	 *   - Inside `device`: `device_number`, `device_id` — hardware
	 *     identifiers not needed on the dashboard side.
	 *
	 * @param array $extended_connected Raw stored license blob.
	 * @return array Filtered payload safe for client consumption.
	 */
	private function build_public_activation_payload( array $extended_connected ) {
		$public_keys = array(
			'devices',
			'email',
			'license_key',
			'plan_name',
			'start_date',
			'end_date',
			'role',
			'status',
			'expired_since',
			'expires_in',
		);

		// Per-device top-level: keep only the nested `device` block.
		$device_keys = array(
			'device',
		);

		// Inside the nested `device` block: keep only the human-readable
		// model details, drop the hardware identifiers.
		$device_inner_keys = array(
			'device_name',
			'device_model',
		);

		$payload = array();
		foreach ( $public_keys as $key ) {
			if ( ! array_key_exists( $key, $extended_connected ) ) {
				continue;
			}
			if ( 'devices' === $key && is_array( $extended_connected['devices'] ) ) {
				$payload['devices'] = array();
				foreach ( $extended_connected['devices'] as $device ) {
					if ( ! is_array( $device ) ) {
						continue;
					}
					$filtered_device = array();
					foreach ( $device_keys as $dkey ) {
						if ( ! array_key_exists( $dkey, $device ) ) {
							continue;
						}
						if ( 'device' === $dkey && is_array( $device['device'] ) ) {
							$inner = array();
							foreach ( $device_inner_keys as $ikey ) {
								if ( array_key_exists( $ikey, $device['device'] ) ) {
									$inner[ $ikey ] = $device['device'][ $ikey ];
								}
							}
							$filtered_device['device'] = $inner;
							continue;
						}
						$filtered_device[ $dkey ] = $device[ $dkey ];
					}
					$payload['devices'][] = $filtered_device;
				}
				continue;
			}
			$payload[ $key ] = $extended_connected[ $key ];
		}

		return $payload;
	}

	/**
	 * Stored shared secret issued by the central API at license-connect
	 * time. Sent as the X-Tailwatch-Header-Key header on every outbound call
	 * to the central API. Returns '' when no license is connected —
	 * callers should skip sending the header in that case rather than
	 * sending an empty value.
	 *
	 * Callers that already have plugin_activation_status in scope (e.g.
	 * the verify flow which passes $get_extended_data through) should
	 * read extended_connected['header_key'] inline instead of going
	 * through this helper — avoids a second DB read on the same request.
	 *
	 * @return string
	 */
	public static function get_central_api_header_key() {
		$activation = ( new self() )->get_plugin_activation_status();
		if ( empty( $activation['extended_connected']['header_key'] ) ) {
			return '';
		}
		return (string) $activation['extended_connected']['header_key'];
	}

	/**
	 * Route capability token issued by the central API at license-connect time.
	 * Sent as the X-Tailwatch-Route header to select the operation on the relay
	 * endpoint (TAILWATCH_RELAY). Returns '' when no license is connected or the
	 * named token is absent — callers should skip the call in that case.
	 *
	 * @param string $name Route name, e.g. 'push_notification' or 'get_user_external'.
	 * @return string
	 */
	public static function get_route_token( $name ) {
		$activation = ( new self() )->get_plugin_activation_status();
		if ( empty( $activation['extended_connected']['route_tokens'][ $name ] ) ) {
			return '';
		}
		return (string) $activation['extended_connected']['route_tokens'][ $name ];
	}

	/**
	 * Canonical role normaliser. Returns 'user' or 'suspended' (lowercase,
	 * trimmed), or '' when input is missing / non-string / outside the
	 * allowlist. Case-insensitive on input so API responses with 'User' or
	 * 'Suspended' don't break sessions.
	 */
	public static function normalize_role( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$normalized = strtolower( trim( $raw ) );
		return in_array( $normalized, array( 'user', 'suspended' ), true ) ? $normalized : '';
	}

	/**
	 * Canonical plan-name normaliser. Case-insensitive on input, returns
	 * the canonical display form. Unknown values fall through unchanged
	 * for the caller to validate.
	 */
	public static function normalize_plan_name( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$aliases = array(
			'free'     => 'Free',
			'basic'    => 'Basic',
			'business' => 'Business',
			'agency'   => 'Agency',
		);
		$key = strtolower( trim( $raw ) );
		return $aliases[ $key ] ?? $raw;
	}

	/**
	 * Validate stored license data integrity
	 */
	private function validate_stored_license_data( $license_data ) {
		$required_fields = array( 'user_id', 'license_key', 'plan_name', 'email' );

		foreach ( $required_fields as $field ) {
			if ( ! isset( $license_data[ $field ] ) || empty( $license_data[ $field ] ) ) {
				return array(
					'valid' => false,
					'error' => "Missing required field: {$field}",
				);
			}
		}

		if ( ! filter_var( $license_data['email'], FILTER_VALIDATE_EMAIL ) ) {
			return array(
				'valid' => false,
				'error' => 'Invalid email format in stored license data',
			);
		}

		$allowed_plans = array( 'Basic', 'Business', 'Agency' );
		if ( ! in_array( $license_data['plan_name'], $allowed_plans, true ) ) {
			return array(
				'valid' => false,
				'error' => 'Invalid plan name in stored license data',
			);
		}

		// Role is optional for backward compat (pre-role-storage licenses get
		// populated by the next verify cycle). But if present, it must
		// normalise to the allowlist — unknown value implies tampering or
		// drift, both of which warrant invalidation.
		if ( isset( $license_data['role'] ) && '' !== $license_data['role'] ) {
			if ( '' === self::normalize_role( $license_data['role'] ) ) {
				return array(
					'valid' => false,
					'error' => 'Invalid role in stored license data',
				);
			}
		}

		return array( 'valid' => true );
	}

	public function tailwatch_update_site_settings( $existing_value ) {
		$db_model = new DBModel();

		$db_data = array(
			'value' => wp_json_encode( $existing_value ),
		);

		$where = array(
			'option' => $this->option,
			'key'    => $this->key,
		);

		return $db_model->update_rows( $db_data, $where );
	}

	public function tailwatch_delete_plugin_activation_data( $post_data ) {
		$delete_key = null;

		try {
			$option = 'site_settings';
			$key    = 'plugin_activation';
			$data   = $post_data;

			if ( null === $data || '' === $data ) {
				Log::warning(
					'Plugin activation data deletion failed: Missing required data',
					array(
						'feature'   => 'verification',
						'action'    => 'plugin_activation_delete_failed',
						'title'     => 'License Disconnect Failed',
						'error'     => 'Missing required data',
						'meta_data' => array(
							'feature' => 'License Verification',
							'event'   => 'Removal failed',
							'reason' => 'missing_params',
						),
					)
				);
				return array(
					'data'    => array(),
					'message' => __( 'Missing required data.', 'tailwatch' ),
					'code'    => 400,
				);
			}
			$delete_key = $data;

			$db_model = new DBModel();

			// Remove stale verification metadata that would otherwise leak
			// the last-known plan/user info even after the license is gone.
			delete_option( 'tailwatch_last_license_check' );

			// Invalidate the verify-result transient so the next call hits a
			// fresh "no license" early-return path instead of returning a
			// stale 'live' cache from before this disconnect.
			delete_transient( self::VERIFY_CACHE_KEY );

			// Also clear pro's failure-grace window option. If a reconnect
			// happens later, the new attempt should start from a clean state
			// rather than inherit a half-counted grace window from before.
			delete_option( 'tailwatch_license_failure_window' );

			$existing_data = $db_model->get_value( $option, $key );

			if ( $existing_data ) {
				$existing_value = $existing_data;

				if ( ! is_array( $existing_value ) ) {
					$existing_value = array();
				}

				if ( isset( $existing_value[ $delete_key ] ) ) {
					unset( $existing_value[ $delete_key ] );

					$result = $this->tailwatch_update_site_settings( $existing_value );

					if ( $result ) {
						// $force = true bypasses the pro-side throttle — disconnect
						// is a real state transition, not an opportunistic re-check.
						// Guard so the action only fires for the license key, not
						// for any other unrelated keys this method might delete.
						if ( 'extended_connected' === $delete_key ) {
							do_action( 'tailwatch_license_disconnected', true );

							// Sever the Connect session on disconnect: drop the pairing
							// (CTA) keys so the mobile app / cloud can no longer
							// re-authenticate, and revoke every outstanding JWT so
							// existing access/refresh tokens stop validating.
							$db_model->tailwatch_delete_all_cta_keys();
							$db_model->tailwatch_revoke_all_tokens();

							try {
								( new SecurityFeaturesVerifyController() )->tailwatch_start_security_features_process();
							} catch ( \Throwable $e ) {
								Log::error(
									'Failed to re-run security verification after license disconnect: ' . $e->getMessage(),
									array(
										'feature' => 'verification',
										'action'  => 'post_disconnect_reverify_failed',
										'title'  => 'License Reverify Failed',
										'origin'  => 'system',
									)
								);
							}
						}

						// Log successful deletion.
						Log::info(
							"Plugin activation data deleted successfully: {$delete_key}",
							array(
								'feature'    => 'verification',
								'action'     => 'plugin_activation_delete_completed',
								'delete_key' => $delete_key,
								'title'      => 'License Disconnected',
							)
						);

						$response = array(
							'data'    => $existing_value,
							'message' => __( 'Successfully deleted specified key from value.', 'tailwatch' ),
							'code'    => 200,
						);
					} else {
						Log::error(
							'Plugin activation data deletion failed: Database update failed',
							array(
								'feature'    => 'verification',
								'action'     => 'plugin_activation_delete_failed',
								'title'      => 'License Disconnect Failed',
								'delete_key' => $delete_key,
								'error'      => 'Database update returned false',
								'meta_data'  => array(
									'feature' => 'License Verification',
									'event'   => 'Removal failed',
									'delete_key' => sanitize_key( (string) $delete_key ),
									'reason'     => 'db_update_failed',
								),
							)
						);
						$response = array(
							'data'    => array(),
							'message' => __( 'Failed to update data.', 'tailwatch' ),
							'code'    => 400,
						);
					}
				} else {
					// Key already removed — idempotent success. License is already
					// disconnected, so this state is the goal; no error.
					Log::info(
						"Plugin activation data already removed (idempotent): {$delete_key}",
						array(
							'feature'    => 'verification',
							'action'     => 'plugin_activation_delete_idempotent',
							'delete_key' => $delete_key,
							'title'      => 'License Already Disconnected',
						)
					);
					$response = array(
						'data'    => $existing_value,
						'message' => __( 'License key already disconnected (idempotent).', 'tailwatch' ),
						'code'    => 200,
					);
				}
			} else {
				// No license data exists — idempotent success. License was never
				// connected (or already disconnected), so the desired state is
				// already achieved. Cleanup actions (cache clear, stale metadata
				// removal) already ran above.
				Log::info(
					"Plugin activation data never existed (idempotent): {$delete_key}",
					array(
						'feature'    => 'verification',
						'action'     => 'plugin_activation_delete_idempotent',
						'delete_key' => $delete_key,
						'title'      => 'License Never Connected',
					)
				);
				$response = array(
					'data'    => array(),
					'message' => __( 'License was not connected (idempotent).', 'tailwatch' ),
					'code'    => 200,
				);
			}

			return $response;
		} catch ( \Throwable $e ) {
			Log::error(
				'Plugin activation data deletion failed: Exception occurred',
				array(
					'feature'    => 'verification',
					'action'     => 'plugin_activation_delete_failed',
					'title'      => 'License Disconnect Failed',
					'delete_key' => $delete_key,
					'error'      => $e->getMessage(),
					'exception'  => $e,
					'meta_data'  => array(
						'feature' => 'License Verification',
						'event'   => 'Removal failed',
						'delete_key' => sanitize_key( (string) $delete_key ),
						'reason'     => 'exception',
					),
				)
			);

			return array(
				'data'    => array(),
				'message' => __( 'Error occurred while deleting column data.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * License status read from cached DB state — no outbound HTTP. Used by
	 * high-frequency dashboard endpoints so they don't trigger a verify call
	 * per fetch. Freshness is owned by the 12h cron and the manual
	 * verify / connect / disconnect actions, which write extended_connected.
	 *
	 * @return array
	 */
	public function get_cached_license_status() {
		$pro_active      = (bool) apply_filters( 'tailwatch_is_premium_plugin_active', false );
		$activation_data = $this->get_plugin_activation_status();

		if ( empty( $activation_data['extended_connected'] ) || empty( $activation_data['extended_connected']['user_id'] ) ) {
			return array(
				'connection'        => 'killed',
				'connection_status' => false,
				'pro_plugin_active' => $pro_active,
				'cached'            => true,
			);
		}

		$connected = $activation_data['extended_connected'];
		$plan_name = isset( $connected['plan_name'] ) ? (string) $connected['plan_name'] : '';
		$plan_tier = isset( $connected['plan_tier'] ) ? (int) $connected['plan_tier'] : 0;

		// Allow an add-on to intercept the cached status before it's
		// returned (e.g. to substitute a "limited" view of its own
		// derivation). When no listener hooks in, return the raw
		// cached values unchanged.
		$override = apply_filters( 'tailwatch_cached_license_status_override', null, $connected, $pro_active );
		if ( is_array( $override ) ) {
			return $override;
		}

		return array(
			'connection'        => 'live',
			'connection_status' => true,
			'pro_plugin_active' => $pro_active,
			'planName'          => $plan_name,
			'plan_tier'         => $plan_tier,
			'userId'            => isset( $connected['user_id'] ) ? (string) $connected['user_id'] : '',
			'start_date'        => isset( $connected['start_date'] ) ? (string) $connected['start_date'] : '',
			'end_date'          => isset( $connected['end_date'] ) ? (string) $connected['end_date'] : '',
			'cached'            => true,
		);
	}

	/**
	 * Verify the license.
	 *
	 * Internal callers pass a bool — true bypasses the transient and runs
	 * a live API check, false uses the cached path.
	 *
	 * Router callers (AJAX / Mobile) pass the request body — a JSON string
	 * (or array) which the method decodes and reads `force_refresh` from.
	 * The manual "Verify" button should send `{"force_refresh": true}` to
	 * skip the cache; any other body (or missing body) uses the cached
	 * path.
	 *
	 * Only `connection: 'live'` and `connection: 'limited'` results are
	 * cached. Network failures and the no-license fast-path are not.
	 *
	 * @param bool|string|array $post_data Bool from internal callers, or
	 *                                     request body from the router.
	 * @return array
	 */
	public function tailwatch_verify_license( $post_data = false ) {
		if ( is_bool( $post_data ) ) {
			$force_refresh = $post_data;
		} else {
			$decoded       = is_string( $post_data )
				? json_decode( wp_unslash( $post_data ), true )
				: $post_data;
			$force_refresh = is_array( $decoded ) && ! empty( $decoded['force_refresh'] );
		}

		if ( $force_refresh ) {
			delete_transient( self::VERIFY_CACHE_KEY );
			return $this->run_verify_and_cache( $post_data );
		}

		$cached = get_transient( self::VERIFY_CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		return $this->run_verify_and_cache();
	}

	private function run_verify_and_cache( $post_data = false ) {
		$result = $this->run_verify_internal( $post_data );

		// Cache only settled states. Network failures and the no-license
		// fast-path re-check every call — transient retries are cheap and
		// the no-license path is cheap anyway (no HTTP).
		if ( is_array( $result ) && isset( $result['data']['connection'] ) ) {
			$connection = $result['data']['connection'];
			if ( 'live' === $connection || 'limited' === $connection ) {
				set_transient( self::VERIFY_CACHE_KEY, $result, self::VERIFY_CACHE_TTL );
			}
		}

		return $result;
	}

	private function run_verify_internal( $post_data = false ) {
		try {
			// Compute before any early return so pro_plugin_active is
			// always accurate regardless of license state.
			$pro_active = apply_filters( 'tailwatch_is_premium_plugin_active', false );

			$get_extended_data = $this->get_plugin_activation_status();

			if ( empty( $get_extended_data['extended_connected'] ) || empty( $get_extended_data['extended_connected']['user_id'] ) ) {
				// Broadcast even on no-license fast-path so pro (if present)
				// can re-apply locks for premium rows mutated since the original
				// disconnect (reset / bulk activation can wrote is_active=1 on a
				// premium feature without knowing the license was gone). $force
				// is false here so pro's throttle absorbs repeat dashboard hits;
				// real kill paths pass $force = true.
				do_action( 'tailwatch_license_disconnected', false );

				return array(
					'data'    => array(
						'connection'        => 'killed',
						'connection_status' => false,
						'pro_plugin_active' => $pro_active,
					),
					'code'    => 200,
					'message' => __( 'No license connected.', 'tailwatch' ),
				);
			}

			$user_id = $get_extended_data['extended_connected']['user_id'];

			// When pro is active it owns the entire verification via this
			// filter — independent API call, never trusts stored DB data.
			$pro_result = apply_filters( 'tailwatch_perform_license_verification', null, $user_id, $get_extended_data, $post_data );
			if ( null !== $pro_result ) {
				return $pro_result;
			}

			return $this->tailwatch_run_free_verification( $user_id, $get_extended_data );
		} catch ( \Throwable $e ) {
			return array(
				'data'    => array(),
				'message' => __( 'Error occurred during license verification.', 'tailwatch' ),
				'code'    => 500,
			);
		}
	}

	/**
	 * Free-side fallback license verify. Pro intercepts the
	 * tailwatch_perform_license_verification filter and owns the verify when
	 * active; this runs only when no pro listener answered.
	 */
	private function tailwatch_run_free_verification( $user_id, $get_extended_data ) {
		$header_key = $get_extended_data['extended_connected']['header_key'] ?? '';
		if ( '' === $header_key ) {
			// header_key is a required field at license-connect time. Missing
			// it here means a legacy row from before this feature shipped or
			// DB tampering. Either way, the central API would reject the
			// request — fail fast with a clearer local error.
			Log::error(
				'License verify aborted: missing header_key in stored license data.',
				array(
					'feature' => 'verification',
					'action'  => 'verify_missing_header_key',
					'user_id' => $user_id,
				)
			);
			return array(
				'data'    => array(
					'connection'        => 'failed',
					'connection_status' => false,
					'pro_plugin_active' => false,
					'userId'            => $user_id,
				),
				'code'    => 401,
				'message' => __( 'Missing auth header key. Please reconnect your license.', 'tailwatch' ),
			);
		}

		$route_token = $get_extended_data['extended_connected']['route_tokens']['get_user_external'] ?? '';
		if ( '' === $route_token ) {
			return array(
				'data'    => array(
					'connection'        => 'failed',
					'connection_status' => false,
					'pro_plugin_active' => false,
					'userId'            => $user_id,
				),
				'code'    => 401,
				'message' => __( 'Missing route token. Please reconnect your license.', 'tailwatch' ),
			);
		}

		$check_connected_url = TAILWATCH_RELAY . '?userId=' . urlencode( $user_id ) . '&website=' . urlencode( TAILWATCH_GET_SITE_URL );

		$response = wp_remote_get(
			$check_connected_url,
			array(
				'headers' => array(
					'Accept'            => 'application/json',
					'X-Tailwatch-Header-Key' => $header_key,
					'X-Tailwatch-Route'      => $route_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'data'    => array(
					'connection'        => 'failed',
					'connection_status' => false,
					'pro_plugin_active' => false,
					'userId'            => $user_id,
				),
				'code'    => 200,
				'message' => __( 'Connection Error: ', 'tailwatch' ) . $response->get_error_message(),
			);
		}

		$httpCode  = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );

		$plan_json = json_decode( $body, true );
		return $this->tailwatch_process_license_activation( $httpCode, $plan_json, $user_id, $get_extended_data );
	}

	private function tailwatch_process_license_activation( $httpCode, $plan_json, $user_id, $get_extended_data ) {
		if ( $httpCode === 200 && is_array( $plan_json ) ) {

			$plan_name = self::normalize_plan_name(
				$plan_json['User']['plan']['plan']['plan_name'] ?? 'unknown'
			);

			$valid_plans = array( 'Basic', 'Business', 'Agency' );
			if ( ! in_array( $plan_name, $valid_plans, true ) ) {
				return $this->tailwatch_handle_suspended_license( $user_id, $plan_name, 'invalid_plan' );
			}

			$notification_credit      = $plan_json['User']['plan']['plan']['plan_features']['notification_credit'] ?? 0;
			$notification_credit_left = $plan_json['User']['plan']['available_limit']['notification_credit'] ?? 0;

			update_option(
				'tailwatch_last_license_check',
				array(
					'timestamp'         => time(),
					'user_id'           => $user_id,
					'plan'              => $plan_name,
					'status'            => 'active',
					'connection_status' => true,
					'hash'              => hash( 'sha256', $user_id . $plan_name . time() ),
				),
				false
			);

			if ( $get_extended_data['extended_connected']['plan_name'] !== $plan_name ) {
				$get_extended_data['extended_connected']['plan_name'] = $plan_name;
				$this->tailwatch_update_site_settings( $get_extended_data );
			}


			// Devices: empty array [] means user removed all devices and must
			// be saved. Key absent entirely → keep stored value unchanged.
			$api_devices = array_key_exists( 'devices', $plan_json )
				? $plan_json['devices']
				: ( $get_extended_data['extended_connected']['devices'] ?? array() );

			if ( ( $get_extended_data['extended_connected']['devices'] ?? null ) !== $api_devices ) {
				$get_extended_data['extended_connected']['devices'] = $api_devices;
				$this->tailwatch_update_site_settings( $get_extended_data );
			}

			$api_start_date = $plan_json['User']['plan']['start_date'] ?? null;

			if ( $api_start_date && $get_extended_data['extended_connected']['start_date'] !== $api_start_date ) {
				$get_extended_data['extended_connected']['start_date'] = $api_start_date;
				$this->tailwatch_update_site_settings( $get_extended_data );
			}

			$api_end_date = $plan_json['User']['plan']['end_date'] ?? null;

			if ( $api_end_date && $get_extended_data['extended_connected']['end_date'] !== $api_end_date ) {
				$get_extended_data['extended_connected']['end_date'] = $api_end_date;
				$this->tailwatch_update_site_settings( $get_extended_data );
			}

			// Only persist role when normalize_role returns a canonical value
			// AND it differs from stored — never overwrite a known-good role
			// with garbage from a flaky API response.
			$api_role = self::normalize_role( $plan_json['User']['role'] ?? null );

			if ( '' !== $api_role && ( $get_extended_data['extended_connected']['role'] ?? null ) !== $api_role ) {
				$get_extended_data['extended_connected']['role'] = $api_role;
				$this->tailwatch_update_site_settings( $get_extended_data );
			}

			// Suspended account: keep showing the real plan name with
			// planStatus='suspended' so UI renders "Agency (suspended)".
			// Distinct from tailwatch_handle_suspended_license which downgrades
			// planName to 'Basic'.
			$effective_role = '' !== $api_role
				? $api_role
				: ( $get_extended_data['extended_connected']['role'] ?? '' );

			if ( 'suspended' === $effective_role ) {
				return $this->tailwatch_handle_role_suspended_license( $user_id, $plan_name, $get_extended_data );
			}

			$start_date = $get_extended_data['extended_connected']['start_date'] ?? '';
			$end_date   = $get_extended_data['extended_connected']['end_date'] ?? '';
			$expires_in = $end_date ? max( 0, strtotime( $end_date ) - time() ) : null;

			return array(
				'data'    => array(
					'connection'               => 'live',
					'role'                     => $api_role,
					'connection_status'        => true,
					'pro_plugin_active'        => false,
					'planName'                 => $plan_name,
					'planStatus'               => 'active',
					'userId'                   => $user_id,
					'devices'                  => $api_devices,
					'notification_credit'      => $notification_credit,
					'notification_credit_left' => $notification_credit_left,
					'start_date'               => $start_date,
					'end_date'                 => $end_date,
					'expires_in'               => $expires_in,
					'last_verified'            => time(),
				),
				'code'    => 200,
				'message' => __( 'Connection Alive - Plan: ', 'tailwatch' ) . $plan_name,
			);
		}

		if ( in_array( $httpCode, array( 400, 401, 403, 418, 419 ), true ) ) {
			return $this->tailwatch_handle_connection_killed();
		}

		return array(
			'data'    => array(
				'connection'        => 'failed',
				'connection_status' => false,
				'pro_plugin_active' => false,
				'userId'            => $user_id,
			),
			'code'    => 200,
			'message' => __( 'Connection Error to receive response.', 'tailwatch' ),
		);
	}

	private function tailwatch_handle_connection_killed( $pro_active = false ) {
		// Removing extended_connected fires tailwatch_license_disconnected(true)
		// inside tailwatch_delete_plugin_activation_data, locking premium features
		// before we return.
		$this->tailwatch_delete_plugin_activation_data( 'extended_connected' );

		return array(
			'data'    => array(
				'connection'        => 'killed',
				'connection_status' => false,
				'pro_plugin_active' => $pro_active,
			),
			'code'    => 200,
			'message' => __( 'Connection Killed and Data Deleted', 'tailwatch' ),
		);
	}

	private function tailwatch_handle_suspended_license( $user_id, $plan_name, $plan_status ) {
		$suspension_messages = array(
			'suspended' => 'Your license has been suspended. You still have access to Basic plan features.',
			'expired'   => 'Your license has expired. You still have access to Basic plan features.',
			'cancelled' => 'Your subscription has been cancelled. You still have access to Basic plan features.',
			'inactive'  => 'Your account is inactive. You still have access to Basic plan features.',
		);

		$suspension_message = $suspension_messages[ strtolower( $plan_status ) ] ?? 'Your license is not active. Basic plan features are still available.';

		update_option(
			'tailwatch_last_license_check',
			array(
				'timestamp'     => time(),
				'user_id'       => $user_id,
				'plan'          => 'Basic',
				'original_plan' => $plan_name,
				'status'        => $plan_status,
				'suspended'     => true,
				'hash'          => hash( 'sha256', $user_id . 'Basic' . time() ),
			),
			false
		);

		return array(
			'data'    => array(
				'connection'        => 'limited',
				'connection_status' => false,
				'pro_plugin_active' => false,
				'planName'          => 'Basic',
				'originalPlan'      => $plan_name,
				'planStatus'        => $plan_status,
				'userId'            => $user_id,
				'suspension_reason' => $suspension_message,
				'plan_tier'         => 1,
				'access_level'      => 'basic_only',
			),
			'code'    => 200,
			'message' => __( 'License Suspended - Basic Plan Access Maintained', 'tailwatch' ),
		);
	}

	/**
	 * Response shape for API-returned role='suspended'. Preserves the real
	 * plan name in `planName` (so UI can render "Agency (suspended)") while
	 * functionally treating the user as Basic via plan_tier=1.
	 *
	 * tailwatch_handle_suspended_license is the sibling for the other suspension
	 * causes (HTTP 401/403/418/419, invalid plan name); that one downgrades
	 * `planName` to 'Basic' in display.
	 */
	private function tailwatch_handle_role_suspended_license( $user_id, $plan_name, $get_extended_data ) {
		update_option(
			'tailwatch_last_license_check',
			array(
				'timestamp'         => time(),
				'user_id'           => $user_id,
				'plan'              => $plan_name,
				'role'              => 'suspended',
				'status'            => 'suspended',
				'connection_status' => false,
				'hash'              => hash( 'sha256', $user_id . $plan_name . 'suspended' . time() ),
			),
			false
		);

		$start_date = $get_extended_data['extended_connected']['start_date'] ?? '';
		$end_date   = $get_extended_data['extended_connected']['end_date'] ?? '';
		$expires_in = $end_date ? max( 0, strtotime( $end_date ) - time() ) : null;

		return array(
			'data'    => array(
				'connection'        => 'limited',
				'connection_status' => false,
				'pro_plugin_active' => false,
				'planName'          => $plan_name,
				'originalPlan'      => $plan_name,
				'planStatus'        => 'suspended',
				'role'              => 'suspended',
				'plan_tier'         => 1,
				'userId'            => $user_id,
				'start_date'        => $start_date,
				'end_date'          => $end_date,
				'expires_in'        => $expires_in,
				'suspension_reason' => 'Your account is suspended. Basic features remain available — reactivate your subscription to restore full access.',
				'access_level'      => 'basic_only',
				'last_verified'     => time(),
			),
			'code'    => 200,
			'message' => __( 'Account suspended — Basic plan access maintained.', 'tailwatch' ),
		);
	}

}
