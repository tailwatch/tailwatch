<?php
// phpcs:disable WordPress.Files.FileName -- Legacy controller filename.
/**
 * Performance Optimizer Controller
 *
 * Handles PHP performance settings: save, remove, apply via DB and .htaccess.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Controllers\LimitIncrease
 */

namespace Tailwatch\Admin\App\Api\Controllers\LimitIncrease;

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Services\Common\FilesystemService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Optimizer Controller
 *
 */
class PerformanceOptimizerController {

	/** @var string Option key for performance settings. */
	private $key = 'php_performance_settings';

	/** @var string Option name. */
	private $option = 'tailwatch_performance';

	/** @var array<string> Valid PHP setting keys. */
	private $get_valid_php_settings = array(
		'memory_limit',
		'max_execution_time',
		'max_input_time',
		'upload_max_filesize',
		'post_max_size',
		'max_file_uploads',
	);

	/**
	 * Context for wp_raise_memory_limit() while a scan works. Core turns it into the
	 * `tailwatch_scan_memory_limit` filter.
	 */
	const SCAN_MEMORY_CONTEXT = 'tailwatch_scan';

	/** What a scan asks for when nobody has said otherwise. A floor, never a cap. */
	const SCAN_MEMORY_FLOOR = '512M';

	/** Seconds per request a scan asks for when nobody has said otherwise. */
	const SCAN_TIME_LIMIT = 600;

	/**
	 * The lowest memory limit this screen will save. A saved value applies to the whole site,
	 * and a busy site can come close to 64 MB on an ordinary request.
	 */
	const MIN_MEMORY_LIMIT = '128M';

	/**
	 * The lowest time limit this screen will save, besides 0 for none. PHP's own default; the
	 * site-size walk at a backup start runs up to 20 seconds of CPU.
	 */
	const MIN_EXECUTION_TIME = 30;

	/** @var array<string, int|string> Recommended default values. */
	private $get_recommended_settings = array(
		'memory_limit'         => '512M',
		'max_execution_time'  => 600,
		'max_input_time'      => 600,
		'upload_max_filesize' => '100M',
		'post_max_size'       => '100M',
		'max_file_uploads'    => 20,
	);

	/**
	 * Autoloaded marker option name. Value is '1' when the user has saved
	 * custom PHP performance settings, otherwise the option does not exist.
	 * Used to short-circuit apply_saved_settings_on_load() without hitting
	 * the custom settings table on every request.
	 *
	 * @var string
	 */
	private const HAS_SETTINGS_FLAG = 'tailwatch_has_php_performance';

	/**
	 * Request-level cache for saved settings. Populated on first read and
	 * shared across any PerformanceOptimizerController instance in the same
	 * request. Mutating operations (save / remove) must reset this to null.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $cached_settings = null;

	/**
	 * Whether apply_saved_settings_on_load() has already run in this request.
	 * The controller is instantiated by several modules (init bootstrap, backup,
	 * integrity scan, migration), and applying ini overrides is idempotent but
	 * not free. Tracking this in a static lets the 2nd through Nth constructors
	 * skip the work entirely instead of re-checking the flag and re-running
	 * @ini_set on every instantiation.
	 *
	 * @var bool
	 */
	private static $settings_already_applied = false;

	/**
	 * The memory limit the site runs at: this request's once any saved value is applied,
	 * before Tailwatch raises its own. The Performance screen is itself a Tailwatch request,
	 * so reading ini_get() there would report the raise rather than the saved value.
	 *
	 * @var string|null
	 */
	private static $site_memory_limit = null;

	/**
	 * Constructor. Applies saved settings on load.
	 *
	 */
	public function __construct() {
		if ( self::$settings_already_applied ) {
			return;
		}

		$this->apply_saved_settings_on_load();
		self::$settings_already_applied = true;
		self::$site_memory_limit        = (string) ini_get( 'memory_limit' );
	}

	/**
	 * The memory limit the site runs at, for anything that shows it to the operator.
	 *
	 * Inside a Tailwatch request ini_get() answers with what that request was raised to.
	 * Code sizing its own work wants that; a screen saying what the site runs at wants this.
	 *
	 * @return string
	 */
	public static function tailwatch_site_memory_limit() {
		return null !== self::$site_memory_limit ? self::$site_memory_limit : (string) ini_get( 'memory_limit' );
	}

	/**
	 * A setting as the site runs with it, rather than as this request does.
	 *
	 * @param string $key Setting key.
	 * @return string|false
	 */
	private function site_value( $key ) {
		return 'memory_limit' === $key ? self::tailwatch_site_memory_limit() : ini_get( $key );
	}

	/**
	 * Gets saved performance settings from database.
	 *
	 * Caches the result in a static variable for the duration of the request
	 * so repeated instantiations of this controller (constructor + later
	 * runtime paths) share a single DB read. Save / remove handlers reset
	 * the static cache so stale data is never returned within the same
	 * request.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings() {
		if ( null !== self::$cached_settings ) {
			return self::$cached_settings;
		}

		$db_model              = new DBModel();
		$data                  = $db_model->get_recent_data( $this->option, $this->key );
		self::$cached_settings = is_array( $data ) ? $data : array();

		return self::$cached_settings;
	}

	/**
	 * Validates custom settings against allowed keys and value rules.
	 *
	 * @param array<string, mixed> $custom_settings Settings to validate.
	 * @return array{settings: array<string, mixed>, errors: array<string>}
	 */
	private function validate_settings( $custom_settings ) {
		$valid_settings = $this->get_valid_php_settings;
		$validated      = array();
		$errors         = array();

		foreach ( $custom_settings as $key => $value ) {
			if ( ! in_array( $key, $valid_settings, true ) ) {
				$errors[] = 'Invalid PHP setting: ' . $key;
				continue;
			}

			$normalized = $this->normalize_setting_value( $key, $value );
			if ( null === $normalized ) {
				$errors[] = 'Invalid value for ' . $key . ': ' . $value;
				continue;
			}

			// Store the NORMALIZED value so only [0-9KMG] ever reaches a config
			// file — no whitespace, newlines, signs, decimals, or 1e3-style input.
			$validated[ $key ] = $normalized;
		}

		return array(
			'settings' => $validated,
			'errors'   => $errors,
		);
	}

	/**
	 * The settings in a set that are below what this screen will save, one sentence each.
	 *
	 * @param array<string, string> $settings Normalized settings.
	 * @return string[] Empty when every value is allowed.
	 */
	private function tailwatch_below_minimum( array $settings ) {
		$errors = array();

		if ( isset( $settings['memory_limit'] )
			&& wp_convert_hr_to_bytes( (string) $settings['memory_limit'] ) < wp_convert_hr_to_bytes( self::MIN_MEMORY_LIMIT ) ) {
			$errors[] = sprintf(
				/* translators: 1: the lowest memory limit allowed, e.g. 128M; 2: the value that was entered. */
				__( 'Memory limit must be at least %1$s, or WordPress and its plugins can run out of memory on ordinary pages; %2$s is too low.', 'tailwatch' ),
				self::MIN_MEMORY_LIMIT,
				$settings['memory_limit']
			);
		}

		if ( isset( $settings['max_execution_time'] ) ) {
			$seconds = (int) $settings['max_execution_time'];
			if ( 0 !== $seconds && $seconds < self::MIN_EXECUTION_TIME ) {
				$errors[] = sprintf(
					/* translators: 1: the lowest time limit allowed, in seconds; 2: the value that was entered. */
					__( 'Max execution time must be at least %1$d seconds, or 0 for no limit; %2$d is too low.', 'tailwatch' ),
					self::MIN_EXECUTION_TIME,
					$seconds
				);
			}
		}

		return $errors;
	}

	/**
	 * Reduces a settings map to allowlisted keys with normalized values,
	 * dropping anything invalid. Unlike validate_settings() this reports no
	 * errors — it is the silent final gate applied to the merged set just
	 * before it is written to the DB and config files.
	 *
	 * @param array<string, mixed> $settings Settings to sanitize.
	 * @return array<string, string>
	 */
	private function sanitize_settings_map( $settings ) {
		$clean = array();
		if ( ! is_array( $settings ) ) {
			return $clean;
		}
		foreach ( $settings as $key => $value ) {
			if ( ! in_array( $key, $this->get_valid_php_settings, true ) ) {
				continue;
			}
			$normalized = $this->normalize_setting_value( $key, $value );
			if ( null !== $normalized ) {
				$clean[ $key ] = $normalized;
			}
		}
		return $clean;
	}

	/**
	 * Strictly validates and normalizes a setting value. Returns the canonical
	 * value — a size string like "512M" or a plain integer string like "600" —
	 * or null if invalid. Rejects scientific notation (1e3), signs (+5),
	 * decimals (0.5), and any embedded whitespace/newlines, so nothing but
	 * digits and an optional K/M/G suffix can ever be written to .user.ini.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return string|null Normalized value, or null if invalid.
	 */
	private function normalize_setting_value( $key, $value ) {
		$size_keys = array( 'memory_limit', 'upload_max_filesize', 'post_max_size' );
		$candidate = strtoupper( trim( (string) $value ) );

		if ( in_array( $key, $size_keys, true ) ) {
			return preg_match( '/^\d+[KMG]?$/', $candidate ) ? $candidate : null;
		}

		// Time / count keys: plain non-negative integers only.
		return preg_match( '/^\d+$/', $candidate ) ? $candidate : null;
	}

	/**
	 * Returns current PHP settings (saved, recommended, and live values).
	 *
	 * @return array<string, mixed>
	 */
	public function tailwatch_get_php_settings() {
		$saved_settings = $this->get_settings();

		return array(
			'code'                  => 200,
			'message'               => __( 'PHP settings retrieved successfully', 'tailwatch' ),
			'saved_settings'        => $saved_settings,
			'recommended_settings'  => $this->get_recommended_settings,
			'all_current_values'    => $this->get_all_current_php_values(),
			// Live, per-setting proof of what is actually applied vs host-capped
			// on THIS server (SAPI, changeability, raise-probe verdicts).
			'diagnostic'            => $this->tailwatch_diagnose_php_limits(),
		);
	}

	/**
	 * Saves PHP settings from POST data; inserts or updates DB and applies .htaccess.
	 *
	 * @param string $post_data JSON-encoded settings.
	 * @return array<string, mixed>
	 */
	public function tailwatch_save_php_settings( $post_data ) {
		// Writes .htaccess / .user.ini at ABSPATH, shared across a whole multisite
		// network; require a super-admin-level capability on multisite.
		$fs_network_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $fs_network_cap ) ) {
			return array(
				'code'    => 403,
				'message' => __( 'You are not allowed to modify server performance settings.', 'tailwatch' ),
			);
		}

		$json_data       = isset( $post_data ) ? wp_unslash( $post_data ) : '';
		$custom_settings = json_decode( $json_data, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array(
				'code'    => 400,
				'message' => __( 'Invalid JSON data provided: ', 'tailwatch' ) . json_last_error_msg(),
			);
		}

		if ( ! is_array( $custom_settings ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'Custom settings must be an array', 'tailwatch' ),
			);
		}

		$db_model          = new DBModel();
		$existing_settings = $this->get_settings();
		$is_update         = ! empty( $existing_settings );

		if ( empty( $custom_settings ) ) {
			if ( $is_update ) {
				return array(
					'code'    => 400,
					'message' => __( 'No settings provided for update', 'tailwatch' ),
				);
			}
			$settings = $this->get_recommended_settings;
		} else {
			$validation = $this->validate_settings( $custom_settings );

			if ( ! empty( $validation['errors'] ) ) {
				return array(
					'code'    => 400,
					'message' => __( 'Invalid settings provided', 'tailwatch' ),
					'errors'  => $validation['errors'],
				);
			}

			$settings = $is_update ? array_merge( $existing_settings, $validation['settings'] ) : $validation['settings'];
		}

		// Final gate before persistence: only allowlisted keys with normalized
		// values reach the DB and the .user.ini/.htaccess writers, even if a
		// legacy/tampered row contributed an unexpected key or value via merge.
		$settings = $this->sanitize_settings_map( $settings );
		if ( empty( $settings ) ) {
			return array(
				'code'    => 400,
				'message' => __( 'No valid settings to save', 'tailwatch' ),
			);
		}

		// Checked on the merged set, so a value saved before these minimums existed cannot
		// ride along when another field is changed.
		$too_low = $this->tailwatch_below_minimum( $settings );
		if ( ! empty( $too_low ) ) {
			$too_low[] = __( 'Nothing was saved.', 'tailwatch' );

			return array(
				'code'    => 400,
				'message' => implode( ' ', $too_low ),
				'errors'  => $too_low,
			);
		}

		if ( $is_update ) {
			$db_data = array(
				'value'         => wp_json_encode( $settings ),
				'date_modified' => current_time( 'mysql' ),
			);

			$result    = $db_model->update_recent_row( $db_data, $this->key, $this->option );
			$operation = 'updated';
		} else {
			$db_data = array(
				'user_id'       => 1,
				'child_of'      => 0,
				'key'           => $this->key,
				'option'        => $this->option,
				'value'         => wp_json_encode( $settings ),
				'type'          => 'JSON',
				'type_state'    => 'active',
				'date_created'  => current_time( 'mysql' ),
				'date_modified'  => current_time( 'mysql' ),
				'is_active'     => true,
			);

			$db_data_format = array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
			);

			$result    = $db_model->insert_row( $db_data, $db_data_format );
			$operation = 'inserted';
		}

		if ( $result ) {
			// Mark that saved settings exist so apply_saved_settings_on_load()
			// takes the fast path on subsequent requests, and drop the static
			// cache so the next read returns the freshly persisted values.
			update_option( self::HAS_SETTINGS_FLAG, '1', true );
			self::$cached_settings = null;

			$this->apply_settings_directly( $settings );
			if ( isset( $settings['memory_limit'] ) ) {
				self::$site_memory_limit = (string) ini_get( 'memory_limit' );
			}
			// Clear any existing Tailwatch php_value block first — php_value in
			// .htaccess can 500 mod_php hosts with restrictive AllowOverride and is
			// silently ignored on FPM/LiteSpeed/nginx, so it is re-added below only
			// where the probe allows it.
			$this->strip_legacy_htaccess_block();

			// Persist PHP_INI_PERDIR limits to the right file for this SAPI:
			//  - FPM / FastCGI / LiteSpeed / CGI → .user.ini (no 500 risk).
			//  - Apache mod_php → .htaccess, but ONLY after an isolated probe
			//    proves the host permits php_value (so we never 500 the site).
			$user_ini_supported = $this->user_ini_supported();
			$config_written     = false;
			if ( $user_ini_supported ) {
				$user_ini       = $this->update_user_ini_settings( $settings, true );
				$config_written = ( isset( $user_ini['code'] ) && 200 === $user_ini['code'] );
			} else {
				$config_written = $this->attempt_htaccess_write( $settings );
			}
			$this->log_operation( $operation, $settings );

			// Read back ini_get() to report what actually took effect, instead
			// of claiming success on the DB write alone.
			$verification = $this->verify_applied_settings( $settings, $config_written, $user_ini_supported );

			// apply_settings_directly() lowered this request to the saved limit too, which the
			// read-back needed. Past it, this is a Tailwatch request like any other.
			$this->tailwatch_raise_memory_limit( 'admin' );

			$response = array(
				'code'             => 200,
				'message'          => $verification['message'],
				'applied_settings' => $settings,
				'current_values'   => $verification['current_values'],
				'verification'     => $verification['details'],
			);

			// For any setting we could not apply (host-capped / host-required /
			// write-failed), give the user a ready-to-paste snippet to set it
			// themselves (php.ini / hosting panel, plus .htaccess on mod_php).
			$manual_targets = array();
			foreach ( $verification['details'] as $key => $verdict ) {
				if ( in_array( $verdict, array( 'blocked', 'host_required', 'write_failed' ), true ) && isset( $settings[ $key ] ) ) {
					$manual_targets[ $key ] = $settings[ $key ];
				}
			}
			if ( ! empty( $manual_targets ) ) {
				$response['manual_snippet'] = $this->build_manual_php_snippets( $manual_targets, $user_ini_supported );
			}

			return $response;
		}

		return array(
			'code'    => 500,
			'message' => __( 'Failed to save settings to database', 'tailwatch' ),
		);
	}

	/**
	 * Removes saved PHP settings from database and .htaccess.
	 *
	 * @return array<string, mixed>
	 */
	public function tailwatch_remove_php_settings() {
		// Writes .htaccess / .user.ini at ABSPATH, shared across a whole multisite
		// network; require a super-admin-level capability on multisite.
		$fs_network_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $fs_network_cap ) ) {
			return array(
				'code'    => 403,
				'message' => __( 'You are not allowed to modify server performance settings.', 'tailwatch' ),
			);
		}

		$db_model = new DBModel();
		$existing = $this->get_settings();

		if ( ! $existing ) {
			return array(
				'code'    => 404,
				'message' => __( 'No settings found to remove', 'tailwatch' ),
			);
		}

		$where  = array(
			'option' => $this->option,
			'key'    => $this->key,
		);
		$result = $db_model->delete_rows( $where );

		if ( $result ) {
			// Clear the autoloaded marker so future requests skip the DB
			// lookup in apply_saved_settings_on_load(), and invalidate the
			// static cache so any subsequent read within this request
			// returns the empty post-delete state.
			delete_option( self::HAS_SETTINGS_FLAG );
			delete_transient( 'tailwatch_htaccess_phpvalue_supported' );
			self::$cached_settings = null;

			$this->strip_legacy_htaccess_block();
			$this->update_user_ini_settings( array(), false );
			$this->log_operation( 'removed', $existing );

			return array(
				'code'              => 200,
				'message'            => __( 'PHP settings removed successfully', 'tailwatch' ),
				'removed_settings'   => $existing,
			);
		}

		return array(
			'code'    => 500,
			'message' => __( 'Failed to remove settings from database', 'tailwatch' ),
		);
	}

	/**
	 * Applies settings via ini_set / set_time_limit (runtime only).
	 *
	 * @param array<string, mixed> $settings Settings to apply.
	 */
	private function apply_settings_directly( $settings ) {
		foreach ( $settings as $key => $value ) {
			switch ( $key ) {
				case 'max_execution_time':
					if ( function_exists( 'set_time_limit' ) ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- set_time_limit may be disabled; required for performance limits.
						@set_time_limit( (int) $value );
					}
					break;
				case 'memory_limit':
				case 'max_input_time':
				case 'upload_max_filesize':
				case 'post_max_size':
				case 'max_file_uploads':
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- ini_set may be restricted; required for PHP performance settings.
					@ini_set( $key, $value );
					break;
			}
		}
	}

	/**
	 * Applies saved settings from DB on controller load.
	 *
	 * Fast path: HAS_SETTINGS_FLAG is an autoloaded option that records
	 * whether any saved PHP performance settings exist:
	 *   - '1' : settings exist, read them from the plugin table.
	 *   - '0' : no settings, skip entirely (no DB query).
	 *   - null: flag not yet initialised (fresh install, or an install that
	 *           predates the flag).
	 *           Probe the DB once and populate the flag so subsequent loads
	 *           take the fast path.
	 *
	 * Because the flag is autoloaded, reading it costs zero queries on
	 * typical requests -- it comes from the alloptions cache.
	 *
	 */
	private function apply_saved_settings_on_load() {
		$flag = get_option( self::HAS_SETTINGS_FLAG, null );

		// First run after install / upgrade: probe the DB once and persist
		// the flag so future loads can short-circuit.
		if ( null === $flag ) {
			$saved_settings = $this->get_settings();
			update_option( self::HAS_SETTINGS_FLAG, empty( $saved_settings ) ? '0' : '1', true );

			if ( ! empty( $saved_settings ) ) {
				$this->apply_settings_directly( $saved_settings );
			}
			return;
		}

		// Known: no saved settings exist -- no DB lookup needed.
		if ( '1' !== $flag ) {
			return;
		}

		// Known: saved settings exist -- read them (request-cached) and apply.
		$saved_settings = $this->get_settings();

		if ( ! empty( $saved_settings ) ) {
			$this->apply_settings_directly( $saved_settings );
		}
	}

	/**
	 * Removes the Tailwatch Performance `php_value` block from .htaccess, if present.
	 *
	 * An unchecked php_value can return a 500 on mod_php hosts where
	 * AllowOverride lacks Options, and is ignored on FPM/LiteSpeed/nginx, so the
	 * block is cleared on every save and removal; attempt_htaccess_write() adds
	 * it back only where the probe allows. Never writes new directives → no 500
	 * risk. Returns 200 when there is nothing to do or the strip succeeds.
	 *
	 * @return array{code: int, message: string}
	 */
	private function strip_legacy_htaccess_block() {
		$htaccess_path = ABSPATH . '.htaccess';

		$wp_filesystem = FilesystemService::get_filesystem();
		if ( false === $wp_filesystem || ! $wp_filesystem->exists( $htaccess_path ) ) {
			return array(
				'code'    => 200,
				'message' => '',
			);
		}

		if ( ! $wp_filesystem->is_writable( $htaccess_path ) ) {
			return array(
				'code'    => 500,
				'message' => __( '.htaccess not writable', 'tailwatch' ),
			);
		}

		// Remove any Tailwatch Performance block through core's sanctioned marker API.
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		if ( function_exists( 'insert_with_markers' ) ) {
			insert_with_markers( $htaccess_path, 'Tailwatch Performance Settings', array() );
		}

		return array(
			'code'    => 200,
			'message' => '',
		);
	}

	/**
	 * On Apache mod_php, safely auto-write the php_value block to .htaccess —
	 * but only after an isolated probe confirms the host permits it, so we can
	 * NEVER 500 the live site. The probe writes a test .htaccess in a throwaway
	 * docroot-level subdirectory and loopback-requests it: a disallowed
	 * php_value 500s ONLY that subdirectory (Apache evaluates .htaccess per the
	 * requested path), never the main site. If permitted, we write the real
	 * block, then re-verify the homepage and roll back on any failure. The
	 * capability result is cached (transient) so we don't probe on every save.
	 *
	 * @param array<string, mixed> $settings Settings to persist.
	 * @return bool True if the block was written and the site still responds.
	 */
	private function attempt_htaccess_write( $settings ) {
		// php_value in .htaccess is honored only under Apache mod_php.
		if ( $this->user_ini_supported() ) {
			return false;
		}

		$supported = get_transient( 'tailwatch_htaccess_phpvalue_supported' );
		if ( false === $supported ) {
			$supported = $this->probe_htaccess_php_value() ? '1' : '0';
			set_transient( 'tailwatch_htaccess_phpvalue_supported', $supported, DAY_IN_SECONDS );
		}
		if ( '1' !== $supported ) {
			return false;
		}

		$wp_filesystem = FilesystemService::get_filesystem();
		if ( false === $wp_filesystem ) {
			return false;
		}

		$htaccess_path = ABSPATH . '.htaccess';
		$exists        = $wp_filesystem->exists( $htaccess_path );
		if ( $exists && ! $wp_filesystem->is_writable( $htaccess_path ) ) {
			return false;
		}
		if ( ! $exists && ! $wp_filesystem->is_writable( ABSPATH ) ) {
			return false;
		}

		// Write the php_value block through WordPress core's sanctioned marker API
		// (insert_with_markers — the same routine core uses for permalink rules). It
		// scopes our directives to a BEGIN/END "Tailwatch Performance Settings" block
		// and preserves every other line in the file. We never rewrite the whole file.
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		if ( ! function_exists( 'insert_with_markers' ) ) {
			return false;
		}

		$lines = $this->generate_htaccess_perdir_lines( $settings );
		if ( ! insert_with_markers( $htaccess_path, 'Tailwatch Performance Settings', $lines ) ) {
			return false;
		}

		// Verify the live site still responds; remove our block on any failure (covers
		// the rare case the docroot's AllowOverride differs from the probe directory).
		if ( ! $this->loopback_ok( home_url( '/' ) ) ) {
			insert_with_markers( $htaccess_path, 'Tailwatch Performance Settings', array() );
			set_transient( 'tailwatch_htaccess_phpvalue_supported', '0', DAY_IN_SECONDS );
			return false;
		}

		return true;
	}

	/**
	 * Isolated capability probe. Writes a test php_value .htaccess in a
	 * throwaway docroot-level subdirectory and loopback-requests a nonexistent
	 * file in it. A disallowed php_value 500s ONLY that subdir, so this can
	 * never affect the live site. Fail-closed: any error / unreachable loopback
	 * / 500 returns false. Uses NO executable probe file.
	 *
	 * @return bool
	 */
	private function probe_htaccess_php_value() {
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( false === $wp_filesystem || ! $wp_filesystem->is_writable( ABSPATH ) ) {
			return false;
		}

		$dir_name = 'tailwatch-phpcheck-' . wp_generate_password( 12, false );
		$dir_path = ABSPATH . $dir_name . '/';
		if ( ! $wp_filesystem->mkdir( $dir_path ) ) {
			return false;
		}

		$ok        = false;
		$test_rule = "<IfModule mod_php.c>\nphp_value memory_limit 256M\n</IfModule>\n";
		if ( $wp_filesystem->put_contents( $dir_path . '.htaccess', $test_rule ) ) {
			// Request a nonexistent file in the probe dir — Apache parses that
			// dir's .htaccess first (500 if php_value disallowed, else 404).
			$response = wp_remote_get(
				site_url( $dir_name . '/tailwatch-probe' ),
				array(
					'timeout'   => 10,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter for local loopback SSL verification.
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$ok   = ( 0 !== $code && 500 !== $code );
			}
		}

		$wp_filesystem->delete( $dir_path . '.htaccess' );
		$wp_filesystem->rmdir( $dir_path );

		return $ok;
	}

	/**
	 * Loopback health check — true if the URL responds with a non-500 code.
	 * Fail-closed: unreachable (WP_Error) or 500 returns false, so callers roll
	 * back rather than leave a possibly-broken site.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	private function loopback_ok( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress filter for local loopback SSL verification.
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return ( 0 !== $code && 500 !== $code );
	}

	/**
	 * Builds the .htaccess php_value block (Apache mod_php). Guards each module
	 * name (PHP 8 / 7 / 5). Includes max_file_uploads — php-src registers it
	 * PHP_INI_SYSTEM|PHP_INI_PERDIR, so php_value applies it on most builds; on
	 * a SYSTEM-only build the line is a harmless no-op (permitted directive,
	 * value not applied — never a 500).
	 *
	 * @param array<string, mixed> $settings Settings to output.
	 * @return string
	 */
	private function generate_htaccess_perdir_lines( $settings ) {
		$directives = array();
		foreach ( $settings as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$directives[] = 'php_value ' . $key . ' ' . $value;
		}

		$lines = array();
		foreach ( array( 'mod_php.c', 'mod_php7.c', 'mod_php5.c' ) as $module ) {
			$lines[] = '<IfModule ' . $module . '>';
			foreach ( $directives as $directive ) {
				$lines[] = $directive;
			}
			$lines[] = '</IfModule>';
		}

		return $lines;
	}

	/**
	 * Whether our Tailwatch php_value block is currently present in .htaccess.
	 *
	 * @return bool
	 */
	private function htaccess_block_present() {
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( false === $wp_filesystem ) {
			return false;
		}
		$htaccess_path = ABSPATH . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess_path ) ) {
			return false;
		}
		$content = (string) $wp_filesystem->get_contents( $htaccess_path );
		return false !== strpos( $content, '# BEGIN Tailwatch Performance Settings' );
	}

	/**
	 * Whether our Tailwatch block is currently present in .user.ini.
	 *
	 * @return bool
	 */
	private function user_ini_block_present() {
		$wp_filesystem = FilesystemService::get_filesystem();
		if ( false === $wp_filesystem ) {
			return false;
		}
		$user_ini_path = ABSPATH . '.user.ini';
		if ( ! $wp_filesystem->exists( $user_ini_path ) ) {
			return false;
		}
		$content = (string) $wp_filesystem->get_contents( $user_ini_path );
		return false !== strpos( $content, '; BEGIN Tailwatch Performance Settings' );
	}

	/**
	 * Writes (or removes) the Tailwatch block in .user.ini for PHP-FPM / FastCGI /
	 * LiteSpeed / CGI hosts (the dominant modern stacks); mod_php hosts get
	 * .htaccess php_value instead (see attempt_htaccess_write). PHP caches
	 * .user.ini for user_ini.cache_ttl seconds (default 300), so changes apply
	 * within a few minutes, not instantly. A malformed value is ignored by PHP
	 * (no fatal), so this is safe to write regardless of SAPI — it is a no-op
	 * under mod_php.
	 *
	 * @param array<string, mixed> $settings  Settings to write (empty to remove).
	 * @param bool                 $is_insert True to add/update block, false to remove.
	 * @return array{code: int, message: string}
	 */
	private function update_user_ini_settings( $settings, $is_insert ) {
		$user_ini_path = ABSPATH . '.user.ini';

		$wp_filesystem = FilesystemService::get_filesystem();
		if ( false === $wp_filesystem ) {
			return array(
				'code'    => 500,
				'message' => __( '.user.ini not accessible', 'tailwatch' ),
			);
		}

		$exists = $wp_filesystem->exists( $user_ini_path );
		if ( $exists && ! $wp_filesystem->is_writable( $user_ini_path ) ) {
			return array(
				'code'    => 500,
				'message' => __( '.user.ini not writable', 'tailwatch' ),
			);
		}
		if ( ! $exists && ! $wp_filesystem->is_writable( ABSPATH ) ) {
			return array(
				'code'    => 500,
				'message' => __( '.user.ini not writable', 'tailwatch' ),
			);
		}

		$content = $exists ? $wp_filesystem->get_contents( $user_ini_path ) : '';
		if ( false === $content ) {
			$content = '';
		}

		$marker_start = '; BEGIN Tailwatch Performance Settings';
		$marker_end   = '; END Tailwatch Performance Settings';
		$pattern      = '#\n*' . preg_quote( $marker_start, '#' ) . '.*?' . preg_quote( $marker_end, '#' ) . '\n*#s';

		$stripped    = preg_replace( $pattern, "\n", $content );
		$new_content = is_string( $stripped ) ? $stripped : $content;

		if ( $is_insert && ! empty( $settings ) ) {
			$new_content = rtrim( $new_content ) . "\n\n" . $this->generate_user_ini_block( $settings );
			$new_content = ltrim( $new_content, "\n" );
		}

		if ( $new_content !== $content ) {
			if ( ! $wp_filesystem->put_contents( $user_ini_path, $new_content ) ) {
				return array(
					'code'    => 500,
					'message' => __( 'Failed to update .user.ini', 'tailwatch' ),
				);
			}
		}

		return array(
			'code'    => 200,
			'message' => '',
		);
	}

	/**
	 * Builds the .user.ini block (php.ini syntax). Includes max_file_uploads —
	 * php-src registers it PHP_INI_SYSTEM|PHP_INI_PERDIR, so .user.ini applies
	 * it on most builds; on a SYSTEM-only build it's a harmless no-op.
	 *
	 * @param array<string, mixed> $settings Settings to output.
	 * @return string
	 */
	private function generate_user_ini_block( $settings ) {
		$block = "; BEGIN Tailwatch Performance Settings\n";
		foreach ( $settings as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$block .= $key . ' = ' . $value . "\n";
		}
		$block .= "; END Tailwatch Performance Settings\n";

		return $block;
	}

	/**
	 * Gets current PHP ini values for valid setting keys.
	 *
	 * @return array<string, string>
	 */
	private function get_all_current_php_values() {
		$settings = $this->get_valid_php_settings;
		$current  = array();

		foreach ( $settings as $setting ) {
			$value              = $this->site_value( $setting );
			$current[ $setting ] = false !== $value ? $value : 'N/A';
		}

		return $current;
	}

	/**
	 * Give a heavy job room to work: the highest of the site's limit and ours.
	 *
	 * Called on every tick of a backup, restore, integrity check, malware scan or hardening
	 * audit. Runtime only: nothing is written to the database, .user.ini or .htaccess, and
	 * the next request starts from the site's own limits again.
	 *
	 * Two limits, because they are the only two a running request can change. The other
	 * four this class manages are PHP_INI_PERDIR or PHP_INI_SYSTEM; ini_set() on them
	 * silently does nothing, so the save path writes those to .user.ini instead.
	 *
	 * Never lowers - including on an unlimited host, where memory -1 and time 0 are not
	 * "below the target". A limit saved on the Performance screen is already in force when
	 * this runs, so a higher one stands and a lower one is raised for the job the same as a
	 * low host limit would be.
	 *
	 * @return array<string, array{from: string, to: string}> What was raised, if anything.
	 */
	public function tailwatch_boost_for_scanning() {
		$raised = $this->tailwatch_raise_memory_limit( self::SCAN_MEMORY_CONTEXT );

		if ( ! function_exists( 'set_time_limit' ) ) {
			return $raised;
		}

		$before = (int) ini_get( 'max_execution_time' );

		// 0 is unlimited, and the only way to make that worse is to set a number.
		if ( 0 === $before || $before >= self::SCAN_TIME_LIMIT ) {
			return $raised;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a host may disable set_time_limit; a scan must degrade rather than fail.
		if ( false !== @set_time_limit( self::SCAN_TIME_LIMIT ) ) {
			$raised['max_execution_time'] = array(
				'from' => (string) $before,
				'to'   => (string) self::SCAN_TIME_LIMIT,
			);
		}

		return $raised;
	}

	/**
	 * Raise the memory limit for this request to at least what the context needs.
	 *
	 * Through wp_raise_memory_limit(), so the rules are core's own: it checks the value can
	 * change at all, leaves an unlimited one alone, and has no path that lowers. The
	 * constructor has applied any limit saved on the Performance screen by now, so the
	 * highest of that, the host's and the context's wins. A saved limit below what the
	 * context needs is not honoured here, since a site already using most of a low limit
	 * could not start a backup at all.
	 *
	 * @param string $context 'admin' for an ordinary request, SCAN_MEMORY_CONTEXT for a job.
	 * @return array<string, array{from: string, to: string}> What was raised, if anything.
	 */
	public function tailwatch_raise_memory_limit( $context ) {
		$callback = array( __CLASS__, 'tailwatch_scan_memory_floor' );
		if ( self::SCAN_MEMORY_CONTEXT === $context && false === has_filter( 'tailwatch_scan_memory_limit', $callback ) ) {
			// Priority 5, so a site's own filter at the default 10 has the last word.
			add_filter( 'tailwatch_scan_memory_limit', $callback, 5 );
		}

		$before = (string) ini_get( 'memory_limit' );
		$after  = wp_raise_memory_limit( $context );

		if ( false === $after || (string) $after === $before ) {
			return array();
		}

		return array(
			'memory_limit' => array(
				'from' => $before,
				'to'   => (string) $after,
			),
		);
	}

	/**
	 * Our default for the scan context: what core proposes, or our floor if that is lower.
	 *
	 * @param int|string $limit What core proposes: WP_MAX_MEMORY_LIMIT, or php.ini if higher.
	 * @return int|string
	 */
	public static function tailwatch_scan_memory_floor( $limit ) {
		return wp_convert_hr_to_bytes( (string) $limit ) >= wp_convert_hr_to_bytes( self::SCAN_MEMORY_FLOOR )
			? $limit
			: self::SCAN_MEMORY_FLOOR;
	}

	/**
	 * Reads back ini_get() after applying so the response reports what actually
	 * took effect, instead of claiming success on the DB write alone.
	 *
	 * - Runtime-settable limits (memory_limit, max_execution_time) verify
	 *   immediately: 'active' if the live value meets the target, else 'blocked'
	 *   (the host caps it).
	 * - PHP_INI_PERDIR/SYSTEM limits cannot change mid-request; when written to
	 *   .user.ini they are 'pending' (apply on a later request if the host
	 *   permits); if the .user.ini write failed they are 'write_failed'.
	 *
	 * @param array<string, mixed> $settings           Requested settings.
	 * @param bool                 $config_written     Whether the .user.ini write succeeded.
	 * @param bool                 $user_ini_supported Whether this SAPI reads .user.ini (false on mod_php).
	 * @return array{message: string, current_values: array<string, string>, details: array<string, string>}
	 */
	private function verify_applied_settings( $settings, $config_written = true, $user_ini_supported = true ) {
		$runtime_keys   = array( 'memory_limit', 'max_execution_time' );
		$current_values = array();
		$details        = array();
		$pending        = array();
		$blocked        = array();
		$write_failed   = array();
		$host_required  = array();

		foreach ( $settings as $key => $requested ) {
			$effective              = ini_get( $key );
			$current_values[ $key ] = ( false !== $effective && '' !== $effective ) ? $effective : 'N/A';

			if ( $this->value_meets_target( $key, $requested, $effective ) ) {
				$details[ $key ] = 'active';
			} elseif ( in_array( $key, $runtime_keys, true ) ) {
				$details[ $key ] = 'blocked';
				$blocked[]       = $key;
			} elseif ( $config_written ) {
				// Persisted to .user.ini (FPM) or .htaccess (mod_php); applies
				// on a later request if the host permits it.
				$details[ $key ] = 'pending';
				$pending[]       = $key;
			} elseif ( ! $user_ini_supported ) {
				// mod_php and we could not safely write .htaccess (probe failed
				// / not permitted) → host must set it.
				$details[ $key ] = 'host_required';
				$host_required[] = $key;
			} else {
				// FPM-class but .user.ini was not writable.
				$details[ $key ] = 'write_failed';
				$write_failed[]  = $key;
			}
		}

		if ( empty( $pending ) && empty( $blocked ) && empty( $write_failed ) && empty( $host_required ) ) {
			$message = 'PHP settings applied successfully.';
		} else {
			$message = 'PHP settings saved.';
			if ( ! empty( $pending ) ) {
				$message .= ' Some limits (' . implode( ', ', $pending ) . ') take effect on the next page load or within a few minutes, if your server permits.';
			}
			if ( ! empty( $write_failed ) ) {
				$message .= ' Could not write the .user.ini file for (' . implode( ', ', $write_failed ) . ') — your site root may not be writable; set these in your hosting panel.';
			}
			if ( ! empty( $host_required ) ) {
				$message .= ' (' . implode( ', ', $host_required ) . ') can only be changed by your host (set them in your hosting panel / php.ini).';
			}
			if ( ! empty( $blocked ) ) {
				$message .= ' Your server does not allow changing (' . implode( ', ', $blocked ) . '); please ask your hosting provider to raise these.';
			}
		}

		return array(
			'message'        => $message,
			'current_values' => $current_values,
			'details'        => $details,
		);
	}

	/**
	 * Whether the current SAPI reads .user.ini. False on Apache mod_php
	 * (apache2handler / legacy apache), which uses .htaccess instead and
	 * ignores .user.ini entirely. True for PHP-FPM / FastCGI / LiteSpeed / CGI.
	 *
	 * @return bool
	 */
	private function user_ini_supported() {
		$sapi = function_exists( 'php_sapi_name' ) ? php_sapi_name() : '';
		return ! in_array( $sapi, array( 'apache2handler', 'apache' ), true );
	}

	/**
	 * Builds ready-to-paste config snippets for limits the plugin could not
	 * apply itself (host-capped, host-required, or ini_set disabled). The user
	 * applies these manually and owns the change.
	 *
	 * - php_ini: universal (hosting panel / php.ini), includes every
	 *   requested key.
	 * - htaccess: only on Apache mod_php, and only when the capability probe did
	 *   NOT prove php_value is rejected (we don't hand out a snippet that would
	 *   500 the user's site).
	 *
	 * @param array<string, mixed> $targets            key => target value.
	 * @param bool                 $user_ini_supported Whether this SAPI reads .user.ini (false on mod_php).
	 * @return array{php_ini: string, htaccess?: string, note: string}
	 */
	private function build_manual_php_snippets( $targets, $user_ini_supported ) {
		$php_ini = '';
		foreach ( $targets as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$php_ini .= $key . ' = ' . $value . "\n";
		}

		$snippets = array(
			'php_ini' => rtrim( $php_ini ),
			'note'    => 'These limits are controlled by your server. Set them via your hosting panel (e.g. MultiPHP INI Editor / Select PHP Version) or your php.ini.',
		);

		// Apache mod_php honors php_value in .htaccess where AllowOverride
		// permits it. Only offer the block if the probe did NOT prove it's
		// rejected (transient '0'), so we never hand out a snippet that 500s.
		if ( ! $user_ini_supported && '0' !== get_transient( 'tailwatch_htaccess_phpvalue_supported' ) ) {
			$directives = '';
			foreach ( $targets as $key => $value ) {
				if ( null === $value || '' === $value ) {
					continue;
				}
				$directives .= 'php_value ' . $key . ' ' . $value . "\n";
			}
			if ( '' !== $directives ) {
				$snippets['htaccess'] = "# WP Tail Watch — PHP limits (add to your site root .htaccess)\n<IfModule mod_php.c>\n" . $directives . '</IfModule>';
				$snippets['note']    .= ' On Apache you can instead add the .htaccess block below to your site root .htaccess; if the site then shows a 500 error, your host does not allow it — remove the lines to restore the site.';
			}
		}

		return $snippets;
	}

	/**
	 * Whether the live (effective) ini value meets or exceeds the requested
	 * target. Size keys are compared in bytes; time/count keys numerically.
	 * For memory_limit/time, a value of -1/0 means "unlimited" and always meets.
	 *
	 * @param string $key       Setting key.
	 * @param mixed  $requested Requested value.
	 * @param mixed  $effective Live ini_get() value.
	 * @return bool
	 */
	private function value_meets_target( $key, $requested, $effective ) {
		if ( false === $effective || '' === $effective ) {
			return false;
		}

		$size_keys = array( 'memory_limit', 'upload_max_filesize', 'post_max_size' );
		if ( in_array( $key, $size_keys, true ) ) {
			if ( '-1' === (string) $effective ) {
				return true; // Unlimited.
			}
			// post_max_size = 0 disables the limit (unlimited); the other size
			// keys treat 0 as a real (zero) value, not unlimited.
			if ( 'post_max_size' === $key && '0' === (string) $effective ) {
				return true;
			}
			$eff = wp_convert_hr_to_bytes( (string) $effective );
			$req = wp_convert_hr_to_bytes( (string) $requested );
			return $eff > 0 && $eff >= $req;
		}

		$eff = (int) $effective;
		$req = (int) $requested;

		// 0 = no limit for the time directives.
		if ( in_array( $key, array( 'max_execution_time', 'max_input_time' ), true ) && 0 === $eff ) {
			return true;
		}

		return $eff >= $req;
	}

	/**
	 * Live diagnostic: for each managed setting, reports whether it is actually
	 * applied, host-capped, or only changeable via server config — proven on
	 * THIS server, not assumed.
	 *
	 * For the two runtime-settable directives (memory_limit, max_execution_time)
	 * it performs a raise probe: read the value, attempt the raise, read it back.
	 * A host hard cap (php_admin_value / FPM pool / OS or extension limits) leaves
	 * the read-back unchanged — the only reliable signal, since ini_set may
	 * "succeed" yet be silently clamped. The probe only ever RAISES (never
	 * lowers), so leaving the raised value in place for this read request is
	 * harmless. PHP_INI_PERDIR/SYSTEM directives cannot change at runtime, so
	 * they are reported as config-pending / host-required.
	 *
	 * Note: OS-level per-account memory caps act ABOVE PHP and cannot be seen
	 * here — 'active' proves PHP accepted the value, not that the kernel will.
	 *
	 * @return array<string, mixed>
	 */
	public function tailwatch_diagnose_php_limits() {
		$saved        = $this->get_settings();
		$recommended  = $this->get_recommended_settings;
		$runtime_keys = array( 'memory_limit', 'max_execution_time' );

		$disabled                 = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		$ini_set_available        = function_exists( 'ini_set' ) && ! in_array( 'ini_set', $disabled, true );
		$set_time_limit_available = function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true );
		$user_ini_supported       = $this->user_ini_supported();
		// On mod_php, whether our php_value block is already present in .htaccess
		// (written by a prior save once the capability probe passed). On FPM-class
		// SAPIs, whether our .user.ini block actually landed.
		$htaccess_block = ! $user_ini_supported && $this->htaccess_block_present();
		$user_ini_block = $user_ini_supported && $this->user_ini_block_present();

		$ini_all  = function_exists( 'ini_get_all' ) ? ini_get_all( null, true ) : array();
		$settings = array();

		foreach ( $this->get_valid_php_settings as $key ) {
			$target  = isset( $saved[ $key ] ) ? $saved[ $key ] : ( isset( $recommended[ $key ] ) ? $recommended[ $key ] : null );
			$current = $this->site_value( $key );
			$local   = isset( $ini_all[ $key ]['local_value'] ) ? $ini_all[ $key ]['local_value'] : null;
			if ( 'memory_limit' === $key && null !== self::$site_memory_limit ) {
				$local = self::$site_memory_limit;
			}

			$entry = array(
				'master'     => isset( $ini_all[ $key ]['global_value'] ) ? $ini_all[ $key ]['global_value'] : null,
				'local'      => $local,
				'current'    => ( false !== $current && '' !== $current ) ? $current : 'N/A',
				'target'     => $target,
				'changeable' => function_exists( 'wp_is_ini_value_changeable' ) ? wp_is_ini_value_changeable( $key ) : null,
			);

			if ( null === $target ) {
				$entry['verdict'] = 'inspect_only';
				$entry['message'] = '';
				$settings[ $key ] = $entry;
				continue;
			}

			if ( $this->value_meets_target( $key, $target, $current ) ) {
				$entry['verdict'] = 'active';
				$entry['message'] = 'Active — current value meets or exceeds the requested value.';
				$settings[ $key ] = $entry;
				continue;
			}

			if ( in_array( $key, $runtime_keys, true ) ) {
				if ( ! $ini_set_available ) {
					$entry['verdict'] = 'cannot_attempt';
					$entry['message'] = 'ini_set is disabled on this server (disable_functions). Ask your host to enable it or set this value directly.';
					$settings[ $key ] = $entry;
					continue;
				}

				// Raise probe (raise-only — leaving it raised on this read
				// request is harmless; we never lower).
				if ( 'max_execution_time' === $key && $set_time_limit_available ) {
					@set_time_limit( (int) $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions -- Silenced because host may disable set_time_limit/ini_set; degrade gracefully.
				} else {
					@ini_set( $key, (string) $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions -- Silenced because host may disable set_time_limit/ini_set; degrade gracefully.
				}
				$after = ini_get( $key );
				$meets = $this->value_meets_target( $key, $target, $after );

				if ( $meets ) {
					$entry['verdict'] = 'active';
					$entry['message'] = 'Active — can be raised at runtime on this server.';
				} else {
					$entry['verdict'] = 'host_capped';
					$entry['message'] = sprintf(
						'Your server caps %1$s at %2$s and it cannot be raised from WordPress. Raise it in your hosting panel (e.g. MultiPHP INI Editor / Select PHP Version) or contact your host.',
						$key,
						$entry['current']
					);
				}
				$settings[ $key ] = $entry;
				continue;
			}

			// PHP_INI_PERDIR — not runtime-settable; written to .htaccess (mod_php)
			// or .user.ini (FPM-class) and applied on a later request. Only report
			// 'config_pending' when the block is actually present; otherwise the
			// write never landed (root not writable / probe failed) and the host
			// must set it.
			if ( ! $user_ini_supported ) {
				// Apache mod_php: limits come from .htaccess php_value (which we
				// write only after the safety probe passes).
				if ( $htaccess_block ) {
					$entry['verdict'] = 'config_pending';
					$entry['message'] = 'Written to .htaccess; applies on the next request if your host permits it.';
				} else {
					$entry['verdict'] = 'host_required';
					$entry['message'] = 'Apache mod_php uses .htaccess for these limits. Add the provided .htaccess snippet to your site root, or set them in your hosting panel / php.ini.';
				}
			} elseif ( $user_ini_block ) {
				$entry['verdict'] = 'config_pending';
				$entry['message'] = 'Written to .user.ini; takes effect on a later request (within a few minutes) if your host permits it.';
			} else {
				$entry['verdict'] = 'host_required';
				$entry['message'] = 'Could not write .user.ini (your site root may not be writable). Set this in your hosting panel / php.ini, or make the WordPress root writable.';
			}
			$settings[ $key ] = $entry;
		}

		$result = array(
			'sapi'                     => function_exists( 'php_sapi_name' ) ? php_sapi_name() : 'unknown',
			'ini_set_available'        => $ini_set_available,
			'set_time_limit_available' => $set_time_limit_available,
			'user_ini_supported'       => $user_ini_supported,
			'settings'                 => $settings,
		);

		// Copy-paste snippet only for settings the plugin genuinely can't apply
		// (host-capped / host-required / ini_set-disabled). 'config_pending'
		// means we already wrote it (.user.ini or .htaccess), so it's excluded.
		$manual_targets = array();
		foreach ( $settings as $key => $entry ) {
			if ( isset( $entry['verdict'] ) && in_array( $entry['verdict'], array( 'host_capped', 'host_required', 'cannot_attempt' ), true ) && null !== $entry['target'] ) {
				$manual_targets[ $key ] = $entry['target'];
			}
		}
		if ( ! empty( $manual_targets ) ) {
			$result['manual_snippet'] = $this->build_manual_php_snippets( $manual_targets, $user_ini_supported );
		}

		return $result;
	}

	/**
	 * Logs a performance operation (applied/removed) for debugging.
	 *
	 * @param string               $action   Operation: updated, inserted, removed.
	 * @param array<string, mixed> $settings Settings involved.
	 */
	private function log_operation( $action, $settings ) {
		// Reserved for future logging if needed (e.g. WP debug log).
	}
}
