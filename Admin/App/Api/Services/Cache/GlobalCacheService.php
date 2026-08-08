<?php
/**
 * Tailwatch - Global Cache Service
 *
 * Handles caching operations for the plugin.
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Cache
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Services\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GlobalCacheService {

	private static $group_config = array(
		'features' => 3600,
	);

	/**
	 * Request-level cache for same page load
	 *
	 * @var array
	 */
	private static $request_cache = array();

	/**
	 * Cache key prefix to avoid conflicts with other plugins
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'tailwatch_cache_';

	/**
	 * Cache statistics
	 *
	 * @var array
	 */
	private static $stats = array(
		'hits'          => 0,
		'misses'        => 0,
		'sets'          => 0,
		'invalidations' => 0,
	);

	/**
	 * Enable request-level caching (in-memory)
	 *
	 * @var bool
	 */
	private static $enable_request_cache = true;

	/**
	 * Enable debug logging
	 *
	 * @var bool
	 */
	private static $debug = false;

	/**
	 * Get cached data
	 *
	 * @param string $group Cache group (e.g., 'features', 'ip_activity')
	 * @param string $key Cache key (e.g., 'default_malware_scan', '192.168.1.1')
	 * @return mixed|false Cached data or false if not found
	 */
	public static function get( $group, $key ) {
		$request_key = self::generate_request_key( $group, $key );
		$cache_key   = self::generate_cache_key( $group, $key );

		// Level 1: Check request cache (fastest)
		if ( self::$enable_request_cache && isset( self::$request_cache[ $request_key ] ) ) {
			++self::$stats['hits'];
			self::log( "Request cache HIT: {$group}/{$key}" );
			return self::$request_cache[ $request_key ];
		}

		// Level 2: Check transient cache
		$cached = get_transient( $cache_key );

		if ( $cached !== false ) {
			++self::$stats['hits'];
			self::log( "Transient cache HIT: {$group}/{$key}" );

			// Store in request cache for subsequent calls
			if ( self::$enable_request_cache ) {
				self::$request_cache[ $request_key ] = isset( $cached['data'] ) ? $cached['data'] : $cached;
			}

			return isset( $cached['data'] ) ? $cached['data'] : $cached;
		}

		++self::$stats['misses'];
		self::log( "Cache MISS: {$group}/{$key}" );
		return false;
	}

	/**
	 * Set cache data
	 *
	 * @param string   $group Cache group
	 * @param string   $key Cache key
	 * @param mixed    $data Data to cache
	 * @param int|null $ttl Time to live in seconds (null = use group default)
	 * @return bool True on success, false on failure
	 */
	public static function set( $group, $key, $data, $ttl = null ) {
		$request_key = self::generate_request_key( $group, $key );
		$cache_key   = self::generate_cache_key( $group, $key );

		// Use group default TTL if not specified
		if ( $ttl === null ) {
			$ttl = self::get_group_ttl( $group );
		}

		// Wrap data to differentiate between "no cache" and "cached empty"
		$cache_data = array(
			'data'      => $data,
			'cached_at' => current_time( 'mysql' ),
			'group'     => $group,
		);

		// Store in transient cache
		$result = set_transient( $cache_key, $cache_data, $ttl );

		if ( $result === false ) {
			self::log( "Cache SET FAILED: {$group}/{$key}", 'error' );
			return false;
		}

		++self::$stats['sets'];
		$data_size = is_array( $data ) ? count( $data ) : ( is_string( $data ) ? strlen( $data ) : 0 );
		self::log( "Cache SET SUCCESS: {$group}/{$key}, size: {$data_size}, TTL: {$ttl}s" );

		// Store in request cache too
		if ( self::$enable_request_cache ) {
			self::$request_cache[ $request_key ] = $data;
		}

		return true;
	}

	/**
	 * Get cached data or set it using a callback (lazy loading)
	 *
	 * @param string   $group Cache group
	 * @param string   $key Cache key
	 * @param callable $callback Function to generate data if cache miss
	 * @param int|null $ttl Time to live in seconds
	 * @return mixed Cached or freshly generated data
	 */
	public static function get_or_set( $group, $key, callable $callback, $ttl = null ) {
		$cached = self::get( $group, $key );

		if ( $cached !== false ) {
			return $cached;
		}

		// Cache miss - generate data
		$data = $callback();

		// Store in cache
		self::set( $group, $key, $data, $ttl );

		return $data;
	}

	/**
	 * Invalidate a single cache entry
	 *
	 * @param string $group Cache group
	 * @param string $key Cache key
	 * @return bool True on success
	 */
	public static function invalidate( $group, $key ) {
		$request_key = self::generate_request_key( $group, $key );
		$cache_key   = self::generate_cache_key( $group, $key );

		// Remove from request cache
		if ( isset( self::$request_cache[ $request_key ] ) ) {
			unset( self::$request_cache[ $request_key ] );
		}

		// Remove from transient cache
		$result = delete_transient( $cache_key );

		++self::$stats['invalidations'];
		self::log( "Cache INVALIDATED: {$group}/{$key}" );

		return $result;
	}

	/**
	 * Invalidate all cache entries in a group
	 *
	 * @param string $group Cache group to invalidate
	 * @return int Number of cache entries deleted
	 */
	public static function invalidate_group( $group ) {
		global $wpdb;

		// Sanitize group name and escape for LIKE pattern
		$pattern = self::CACHE_PREFIX . $wpdb->esc_like( $group ) . '_%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; bulk transient invalidation by group.
		$count = $wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			'_transient_' . $pattern,
			'_transient_timeout_' . $pattern
		) );

		// Clear request cache for this group
		foreach ( self::$request_cache as $request_key => $value ) {
			if ( strpos( $request_key, "{$group}_" ) === 0 ) {
				unset( self::$request_cache[ $request_key ] );
			}
		}

		self::$stats['invalidations'] += $count;
		self::log( "Cache group INVALIDATED: {$group}, {$count} entries removed" );

		return $count;
	}

	/**
	 * Invalidate cache entries matching a pattern
	 *
	 * @param string $pattern Pattern to match (e.g., 'ip_activity_192.168.*')
	 * @return int Number of cache entries deleted
	 */
	public static function invalidate_pattern( $pattern ) {
		global $wpdb;

		// Escape LIKE special characters and replace wildcards
		$escaped_pattern = $wpdb->esc_like( $pattern );
		$cache_pattern   = self::CACHE_PREFIX . str_replace( '*', '%', $escaped_pattern );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; pattern-based cache invalidation.
		$count = $wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			'_transient_' . $cache_pattern,
			'_transient_timeout_' . $cache_pattern
		) );

		// Clear matching entries from request cache
		// Escape regex special characters to prevent regex injection
		$escaped_pattern = preg_quote( $pattern, '/' );
		$request_pattern = str_replace( '\*', '.*', $escaped_pattern );
		foreach ( self::$request_cache as $request_key => $value ) {
			if ( preg_match( '/' . $request_pattern . '/', $request_key ) ) {
				unset( self::$request_cache[ $request_key ] );
			}
		}

		self::$stats['invalidations'] += $count;
		self::log( "Cache pattern INVALIDATED: {$pattern}, {$count} entries removed" );

		return $count;
	}

	/**
	 * Check if cache entry exists
	 *
	 * @param string $group Cache group
	 * @param string $key Cache key
	 * @return bool True if exists
	 */
	public static function exists( $group, $key ) {
		return self::get( $group, $key ) !== false;
	}

	/**
	 * Flush all plugin caches (nuclear option)
	 *
	 * @return int Number of cache entries deleted
	 */
	public static function flush_all() {
		global $wpdb;

		$cache_prefix = self::CACHE_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core options table; nuclear cache flush.
		$count = $wpdb->query( $wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_' . $cache_prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $cache_prefix ) . '%'
		) );

		// Clear request cache
		self::$request_cache = array();

		self::$stats['invalidations'] += $count;
		self::log( "ALL CACHES FLUSHED: {$count} entries removed", 'warning' );

		return $count;
	}

	/**
	 * Get cache statistics
	 *
	 * @return array Cache statistics
	 */
	public static function get_stats() {
		$total_requests = self::$stats['hits'] + self::$stats['misses'];
		$hit_rate       = $total_requests > 0 ? round( ( self::$stats['hits'] / $total_requests ) * 100, 2 ) : 0;

		return array_merge(
			self::$stats,
			array(
				'hit_rate'           => $hit_rate . '%',
				'total_requests'     => $total_requests,
				'request_cache_size' => count( self::$request_cache ),
			)
		);
	}

	/**
	 * Reset cache statistics
	 */
	public static function reset_stats() {
		self::$stats = array(
			'hits'          => 0,
			'misses'        => 0,
			'sets'          => 0,
			'invalidations' => 0,
		);
	}

	/**
	 * Enable or disable request-level caching
	 *
	 * @param bool $enabled
	 */
	public static function set_request_cache( $enabled ) {
		self::$enable_request_cache = $enabled;
	}

	/**
	 * Enable or disable debug logging
	 *
	 * @param bool $enabled
	 */
	public static function set_debug( $enabled ) {
		self::$debug = $enabled;
	}

	/**
	 * Get cache group TTL
	 *
	 * @param string $group Cache group
	 * @return int TTL in seconds
	 */
	private static function get_group_ttl( $group ) {
		return self::$group_config[ $group ] ?? self::$group_config['default'];
	}

	/**
	 * Generate cache key for transient
	 *
	 * @param string $group Cache group
	 * @param string $key Cache key
	 * @return string Full cache key
	 */
	private static function generate_cache_key( $group, $key ) {
		return self::CACHE_PREFIX . $group . '_' . md5( $key );
	}

	/**
	 * Generate request cache key
	 *
	 * @param string $group Cache group
	 * @param string $key Cache key
	 * @return string Request cache key
	 */
	private static function generate_request_key( $group, $key ) {
		return $group . '_' . $key;
	}

	/**
	 * Log cache operations
	 *
	 * @param string $message Log message
	 * @param string $level Log level (info, error, warning)
	 */
	private static function log( $message, $level = 'info' ) {
		if ( ! self::$debug ) {
			return;
		}
		// Belt-and-suspenders: even when the cache-debug flag is on, only
		// emit to error_log when WordPress's debug-log mode is enabled.
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$prefix = strtoupper( $level );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated by self::$debug and WP_DEBUG_LOG; production-silent.
		error_log( "[GlobalCacheService] [{$prefix}] {$message}" );
	}
}
