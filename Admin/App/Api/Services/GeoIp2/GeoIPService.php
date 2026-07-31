<?php
namespace Tailwatch\Admin\App\Api\Services\GeoIp2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tailwatch\Vendor\MaxMind\GeoIp2\Database\Reader;

class GeoIPService {

	const DATABASE           = 'GeoLite2-Country';
	const DATABASE_EXTENSION = '.mmdb';
	const FOLDER_NAME        = 'geoip';

	/**
	 * Shared MaxMind readers keyed by database path. Opening the .mmdb is
	 * relatively expensive (file handle + memory map) and GeoIPService is
	 * instantiated several times per request, so the reader is opened LAZILY
	 * (only when get_country() actually runs) and reused across all instances
	 * for the same path. The handle is released automatically at request end.
	 *
	 * @var array<string, \Tailwatch\Vendor\MaxMind\GeoIp2\Database\Reader|null>
	 */
	private static $readers = array();

	private $database_path;

	public function __construct( $database_path = null ) {
		$this->database_path = $database_path ?? self::wptw_geo_lite_db_file_path();
	}

	/**
	 * Absolute path to the GeoLite2-Country database inside the uploads folder.
	 * The database is supplied by the site owner; when it is absent, lookups
	 * return "Unknown" and callers treat the country as undetermined.
	 *
	 * @return string
	 */
	public static function wptw_geo_lite_db_file_path() {
		return WPTW_LOGS_DIRECTORY . '/' . self::FOLDER_NAME . '/' . self::DATABASE . self::DATABASE_EXTENSION;
	}

	/**
	 * Lazily resolve (and cache) the reader for this instance's database path.
	 * Returns null when the GeoLite DB is not present — callers treat that as
	 * "country unknown" rather than an error.
	 *
	 * @return \Tailwatch\Vendor\MaxMind\GeoIp2\Database\Reader|null
	 */
	private function get_reader() {
		$path = $this->database_path;

		if ( ! array_key_exists( $path, self::$readers ) ) {
			self::$readers[ $path ] = null;
			try {
				if ( file_exists( $path ) ) {
					self::$readers[ $path ] = new Reader( $path );
				}
			} catch ( \Exception $e ) {
				self::$readers[ $path ] = null;
			}
		}

		return self::$readers[ $path ];
	}

	public function get_country( $ip ) {
		$reader = $this->get_reader();
		if ( ! $reader || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return 'Unknown';
		}

		$cache_key = 'login_defender_geo_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		try {
			$record  = $reader->country( $ip );
			$country = $record->country->isoCode ?? 'Unknown';
			set_transient( $cache_key, $country, DAY_IN_SECONDS );
			return $country;
		} catch ( \Exception $e ) {
			return 'Unknown';
		}
	}
}
