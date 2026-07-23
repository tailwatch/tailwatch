<?php
/**
 * Options Controller
 *
 * Handles retrieval and processing of feature options with three-tier caching:
 * 1. Request-level cache (in-memory array)
 * 2. Transient cache (persistent)
 * 3. Database (source of truth)
 *
 * @package    Tailwatch
 * @subpackage Controllers/Features
 */

namespace Tailwatch\Admin\App\Api\Controllers\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Models\DBModel;
use Tailwatch\Admin\App\Api\Controllers\Features\FeatureCacheController;

/**
 * Class OptionsController
 *
 * Provides feature options retrieval with optimized caching strategy.
 *
 */
class OptionsController {

	/**
	 * Feature cache controller instance.
	 *
	 * @var FeatureCacheController
	 */
	private $cache;

	/**
	 * Request-level cache for same page load.
	 * Static so ALL OptionsController instances share the same in-memory cache
	 * within a single request — avoids redundant transient lookups when
	 * multiple controllers each create their own OptionsController instance.
	 *
	 * @var array
	 */
	private static $request_cache = array();

	/**
	 * Constructor.
	 *
	 */
	public function __construct() {
		$this->cache = new FeatureCacheController();
	}

	/**
	 * Invalidate the static request-level cache.
	 *
	 * Call this AFTER persisting changes to a feature option (e.g., after
	 * FeaturesController saves settings) so any subsequent calls in the same
	 * request read the fresh DB data instead of the pre-save cached value.
	 *
	 * @param string|null $key       Feature key, or null to clear ALL.
	 * @param string|null $option    Feature option name, or null to clear ALL.
	 * @param mixed|null  $is_active Active state, or null to clear ALL variants.
	 *
	 * @return void
	 */
	public static function invalidate_request_cache( $key = null, $option = null, $is_active = null ) {
		if ( $key === null || $option === null ) {
			self::$request_cache = array();
			return;
		}

		// Clear specific variants (true/false/1/0) for the given key+option
		if ( $is_active === null ) {
			foreach ( array( true, false, 1, 0 ) as $variant ) {
				unset( self::$request_cache[ "{$key}_{$option}_{$variant}" ] );
			}
			return;
		}

		unset( self::$request_cache[ "{$key}_{$option}_{$is_active}" ] );
	}

	/**
	 * Get feature options with three-tier caching.
	 *
	 * Cache levels:
	 * 1. Request cache (fastest - in-memory array)
	 * 2. Transient cache (persistent across requests)
	 * 3. Database query (source of truth)
	 *
	 * @param string $key       Feature key.
	 * @param string $option    Feature option name.
	 * @param mixed  $is_active Active state (1/0 or true/false).
	 *
	 * @return array Extracted feature options.
	 */
	public function get_features_options( $key, $option, $is_active ) {
		// Defensive: if the constant isn't defined yet (e.g. pro plugin loads before
		// free plugin's Constants::init() runs), fall back to the literal option name.
		$option_name = defined( 'WPTW_VISIT_DATA' ) ? WPTW_VISIT_DATA : 'wptw_visit_data';
		$visit_data  = json_decode( get_option( $option_name ), true );

		if ( ! ( $visit_data['features_implemented']['is_completed'] ?? false ) ) {
			return array();
		}

		// Level 1: Check request cache first (fastest - in-memory array).
		$request_key = "{$key}_{$option}_{$is_active}";
		if ( isset( self::$request_cache[ $request_key ] ) ) {
			return self::$request_cache[ $request_key ];
		}

		// Level 2: Check persistent transient cache.
		$cached_data = $this->cache->get_cache( $key, $option, $is_active );
		if ( false !== $cached_data ) {
			// Store in request cache for subsequent calls in same page load.
			self::$request_cache[ $request_key ] = $cached_data;
			return $cached_data;
		}

		// Level 3: Query database.
		global $wpdb;
		$table_name = $wpdb->prefix . WPTW_DB_TABLE_NAME;

		$query = $wpdb->prepare(
			'SELECT value FROM %i WHERE `key` = %s AND `option` = %s AND `is_active` = %s',
			$table_name,
			$key,
			$option,
			$is_active
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; $query built via $wpdb->prepare() above.
		$result = $wpdb->get_var( $query );

		$extracted_data = array();

		// Check if the result is null (feature disabled or not configured).
		if ( null === $result ) {
			// Cache the empty result to prevent repeated DB queries.
			$this->cache->set_cache( $key, $option, $is_active, $extracted_data, 3600 );
			// Store in request cache too.
			self::$request_cache[ $request_key ] = $extracted_data;
			return $extracted_data;
		}

		// Decode the JSON result.
		$data = json_decode( $result, true );

		// Check if data exists and 'files' or 'options' index exists.
		if ( ! empty( $data ) ) {
			$fields         = isset( $data['files'] ) ? $data['files'] : ( isset( $data['options'] ) ? $data['options'] : array() );
			$extracted_data = $this->process_fields( $fields );
		}

		// Store in both caches.
		self::$request_cache[ $request_key ] = $extracted_data;
		$this->cache->set_cache( $key, $option, $is_active, $extracted_data, 3600 );

		return $extracted_data;
	}

	/**
	 * Process fields recursively to extract options.
	 *
	 * @param array $fields Array of field data to process.
	 *
	 * @return array Processed and extracted field options.
	 */
	public function process_fields( $fields ) {
		$extracted = array();

		foreach ( $fields as $field_key => $field_data ) {
			if ( isset( $field_data['key'], $field_data['type'] ) ) {
				// Skip orphan dynamic cards written by an add-on
				// that's no longer present. A field with
				// `pro_managed: true` is a marker that some
				// companion plugin owns this entry's lifecycle.
				// If no listener is currently hooked to the
				// `wptw_options_processed_field` filter, the
				// owning plugin is gone (deactivated, uninstalled,
				// force-deleted) and the entry is stale — render
				// nothing rather than the stale data.
				if ( ! empty( $field_data['pro_managed'] ) && ! has_filter( 'wptw_options_processed_field' ) ) {
					continue;
				}

				$key  = $field_data['key'];
				$type = $field_data['type'];

				$options = array();

				if ( isset( $field_data['values'] ) && is_array( $field_data['values'] ) ) {
					foreach ( $field_data['values'] as $value_key => $value_data ) {
						if ( isset( $value_data['value'], $value_data['selected'] ) ) {
							$options[ $value_key ] = array(
								'value'    => $value_data['value'],
								'selected' => $value_data['selected'],
							);

							// Recursively process sub-options if they exist.
							if ( isset( $value_data['sub_options'] ) ) {
								$options[ $value_key ]['sub_options'] = $this->process_fields( $value_data['sub_options'] );
							}
						}
					}
				}

				// Add to the extracted data array.
				//
				// `push_notification` is preserved here so consumers of the
				// transformed shape (e.g. MonitoringLogController) can read
				// the per-field push toggle without a separate raw-blob
				// fetch.
				//
				// Most stored fields don't carry a `push_notification` key
				// (only checkbox-type feature toggles do — sub_options like
				// select/radio interval/retention fields skip it). The
				// explicit isset check makes the missing-key path obvious
				// and guarantees a clean boolean on the output regardless
				// of whether the source had the key, what type it was, or
				// what PHP version is in play. Without this, downstream
				// readers would see `null` for non-checkbox fields and
				// have to defend against it themselves.
				$push_notification_enabled = isset( $field_data['push_notification'] )
					? (bool) $field_data['push_notification']
					: false;

				$extracted[ $field_key ] = array(
					'key'               => $key,
					'type'              => $type,
					'push_notification' => $push_notification_enabled,
					'options'           => $options,
				);

				// Recursively process sub-options if they exist.
				if ( isset( $field_data['sub_options'] ) ) {
					$extracted[ $field_key ]['sub_options'] = $this->process_fields( $field_data['sub_options'] );
				}

				/**
				 * Extension point — lets a separately-shipped add-on
				 * merge additional metadata into the processed shape
				 * (for example, lock state derived from license tier).
				 *
				 * The free plugin intentionally outputs the minimal
				 * shape above. Any add-on that needs to augment a
				 * field hooks this filter and returns the same array
				 * with extra keys added. With no listener attached,
				 * the filter is a no-op and the output is unchanged.
				 *
				 * @since 1.0.1
				 *
				 * @param array  $processed_field Shape currently being returned.
				 * @param array  $field_data      Raw source row from the DB blob.
				 * @param string $field_key       Map key for this field (e.g. 'field_1').
				 */
				$extracted[ $field_key ] = apply_filters(
					'wptw_options_processed_field',
					$extracted[ $field_key ],
					$field_data,
					$field_key
				);
			}
		}

		return $extracted;
	}
}
