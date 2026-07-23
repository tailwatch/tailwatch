<?php
/**
 * Feature Cache Controller
 *
 * Dedicated cache controller for feature-specific caching operations.
 * Wraps GlobalCacheService with feature-optimized methods.
 *
 * This controller maintains backward compatibility while delegating
 * to the global cache service for consistency and performance.
 *
 * @package    Tailwatch
 * @subpackage Controllers/Features
 */

namespace Tailwatch\Admin\App\Api\Controllers\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Admin\App\Api\Services\Cache\GlobalCacheService;

/**
 * Class FeatureCacheController
 *
 * Provides feature-specific caching wrapper around GlobalCacheService.
 *
 */
class FeatureCacheController {
	/**
	 * Cache group for features.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'features';

	/**
	 * Generate cache key from parameters.
	 *
	 * @param string $key       Feature key.
	 * @param string $option    Feature option.
	 * @param mixed  $is_active Active state.
	 *
	 * @return string Cache key.
	 */
	public function generate_cache_key( $key, $option, $is_active ) {
		return "{$key}_{$option}_{$is_active}";
	}

	/**
	 * Get cached data.
	 *
	 * Returns the actual data or false if no cache exists.
	 *
	 * @param string $key       Feature key.
	 * @param string $option    Feature option.
	 * @param mixed  $is_active Active state.
	 *
	 * @return mixed|false Cached data or false.
	 */
	public function get_cache( $key, $option, $is_active ) {
		$cache_key = $this->generate_cache_key( $key, $option, $is_active );
		return GlobalCacheService::get( self::CACHE_GROUP, $cache_key );
	}

	/**
	 * Set cache data.
	 *
	 * Caches empty arrays too to prevent repeated DB queries for disabled features.
	 *
	 * @param string $key        Feature key.
	 * @param string $option     Feature option.
	 * @param mixed  $is_active  Active state.
	 * @param mixed  $data       Data to cache.
	 * @param int    $expiration TTL in seconds.
	 *
	 * @return bool True on success.
	 */
	public function set_cache( $key, $option, $is_active, $data, $expiration = 3600 ) {
		$cache_key = $this->generate_cache_key( $key, $option, $is_active );
		return GlobalCacheService::set( self::CACHE_GROUP, $cache_key, $data, $expiration );
	}

	/**
	 * Invalidate cache for specific feature option.
	 *
	 * Handles all variants (true/false and 1/0) in one call.
	 *
	 * @param string $key    Feature key.
	 * @param string $option Feature option.
	 *
	 * @return void
	 */
	public function invalidate_feature_cache( $key, $option ) {
		$variants = array( true, false, 1, 0 );
		foreach ( $variants as $is_active ) {
			$cache_key = $this->generate_cache_key( $key, $option, $is_active );
			GlobalCacheService::invalidate( self::CACHE_GROUP, $cache_key );
		}
	}

	/**
	 * Invalidate all feature caches.
	 *
	 * @return void
	 */
	public function invalidate_all_caches() {
		GlobalCacheService::invalidate_group( self::CACHE_GROUP );
	}

	/**
	 * Check if cache exists.
	 *
	 * @param string $key       Feature key.
	 * @param string $option    Feature option.
	 * @param mixed  $is_active Active state.
	 *
	 * @return bool True if exists.
	 */
	public function cache_exists( $key, $option, $is_active ) {
		$cache_key = $this->generate_cache_key( $key, $option, $is_active );
		return GlobalCacheService::exists( self::CACHE_GROUP, $cache_key );
	}
}
