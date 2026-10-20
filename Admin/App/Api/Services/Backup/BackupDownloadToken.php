<?php
/**
 * Tailwatch - Backup download tokens
 *
 * @package    Tailwatch
 * @subpackage Admin\App\Api\Services\Backup
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.4
 */

namespace Tailwatch\Admin\App\Api\Services\Backup;

use Tailwatch\Admin\App\Api\Logging\Log;
use Tailwatch\Admin\App\Api\Services\Crypto\AppSecretService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-lived, single-use capabilities to read one backup file.
 *
 * A backup archive is the most sensitive object on a WordPress site: it is the whole
 * filesystem plus the whole database, including every credential the site stores. Serving
 * one therefore cannot work the way the rest of the API works.
 *
 * The management plane authenticates with a JWT in a request header, which a browser
 * download and a `wp_remote_get()` on a receiving site can both be made to send — but a
 * download is also the one operation that gets handed around: pasted into a terminal,
 * retried by a download manager, resumed hours later. A long-lived bearer credential in a
 * URL is a secret in a GET query string, logged and shared wherever the URL goes. So this
 * issues something deliberately weaker than a session:
 *
 * - bound to ONE file, so it cannot be re-pointed at another backup
 * - valid for {@see self::TTL} seconds, matching the freshness window the migration
 *   handshake uses
 * - single-use: the first successful read consumes it
 * - signed with a key derived from the plugin's own master secret rather than wp_salt(),
 *   so the salt-rotation feature does not silently invalidate a transfer in flight
 *
 * Minting requires the capability. Redeeming does not — that is the entire point — which
 * is why every one of those properties has to hold.
 */
class BackupDownloadToken {

	/**
	 * Seconds a freshly minted token remains valid.
	 *
	 * Matches the 300s freshness the migration handshake uses. Long enough for a receiving
	 * site to start a transfer, short enough that a leaked URL is worthless by the time it
	 * reaches anyone.
	 */
	const TTL = 300;

	/**
	 * Transient prefix for the single-use record.
	 */
	const RECORD_PREFIX = 'tailwatch_dl_';

	/**
	 * Mint a token for one file inside the backup storage tree.
	 *
	 * @param string $absolute_path Absolute path to the file to be served.
	 * @return array{token:string,expires:int}|null Null when the path is not servable.
	 */
	public static function issue( $absolute_path ) {
		$resolved = self::resolve_inside_storage( $absolute_path );
		if ( null === $resolved ) {
			return null;
		}

		$key = self::signing_key();
		if ( '' === $key ) {
			Log::error(
				'Cannot issue a backup download token: no key material is available.',
				array(
					'feature' => 'backup',
					'action'  => 'download_token_no_key',
					'origin'  => 'system',
				)
			);
			return null;
		}

		try {
			$nonce = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// random_bytes only throws when the platform has no CSPRNG at all. A weaker
			// fallback would defeat the point of the token, so refuse.
			return null;
		}

		$expires   = time() + self::TTL;
		$signature = self::sign( $nonce, $resolved, $expires, $key );

		// The record is what makes the token single-use, and it holds the path so the
		// redeeming request never has to name a file at all.
		set_transient(
			self::RECORD_PREFIX . $nonce,
			array(
				'path'    => $resolved,
				'expires' => $expires,
			),
			self::TTL
		);

		return array(
			'token'   => $nonce . '.' . $signature,
			'expires' => $expires,
		);
	}

	/**
	 * Check a token without spending it.
	 *
	 * Runs every check {@see redeem()} runs but leaves the record in place, so a request
	 * refused on the strength of this cannot burn a grant its holder still needs.
	 *
	 * @param string $token Token as issued.
	 * @return string|null Absolute path, or null when the token is not valid.
	 */
	public static function validate( $token ) {
		$parts = self::split( $token );
		if ( null === $parts ) {
			return null;
		}

		return self::verified_path( $parts[0], $parts[1], get_transient( self::RECORD_PREFIX . $parts[0] ) );
	}

	/**
	 * Redeem a token, returning the file it authorises.
	 *
	 * Consumes the token before returning, so a replayed request finds nothing — the
	 * check and the consumption are not separated by any work that could fail in between.
	 *
	 * @param string $token Token as issued.
	 * @return string|null Absolute path, or null when the token is not valid.
	 */
	public static function redeem( $token ) {
		$parts = self::split( $token );
		if ( null === $parts ) {
			return null;
		}

		list( $nonce, $signature ) = $parts;

		$record = get_transient( self::RECORD_PREFIX . $nonce );
		if ( ! is_array( $record ) || empty( $record['path'] ) || empty( $record['expires'] ) ) {
			return null;
		}

		// Consume FIRST. Everything after this point is verification, and a token that
		// fails verification is a token that should not survive to be tried again.
		// The delete is also the claim: two requests can read the same record, but only
		// the one whose delete removed it goes on.
		if ( ! delete_transient( self::RECORD_PREFIX . $nonce ) ) {
			return null;
		}

		return self::verified_path( $nonce, $signature, $record );
	}

	/**
	 * Split a token into its nonce and signature.
	 *
	 * Shape-checked before any lookup, so a malformed token cannot reach the store.
	 *
	 * @param mixed $token Token as presented.
	 * @return array{0:string,1:string}|null
	 */
	private static function split( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return null;
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) {
			return null;
		}

		return $parts;
	}

	/**
	 * The file a stored record authorises, when it is live and the signature is its own.
	 *
	 * @param string $nonce     Token nonce.
	 * @param string $signature Signature presented with it.
	 * @param mixed  $record    Stored record.
	 * @return string|null Absolute path, or null.
	 */
	private static function verified_path( $nonce, $signature, $record ) {
		if ( ! is_array( $record ) || empty( $record['path'] ) || empty( $record['expires'] ) ) {
			return null;
		}

		if ( time() > (int) $record['expires'] ) {
			return null;
		}

		$key = self::signing_key();
		if ( '' === $key ) {
			return null;
		}

		$expected = self::sign( $nonce, (string) $record['path'], (int) $record['expires'], $key );
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		// The stored path was validated at issue time, but storage is not a trust
		// boundary we get to assume — re-pin it against the storage root.
		return self::resolve_inside_storage( (string) $record['path'] );
	}

	/**
	 * Resolve a path and require it to sit inside the backup storage tree.
	 *
	 * `realpath()` on both sides, and the separator is required in the comparison, so a
	 * sibling directory whose name merely begins with the storage directory's name
	 * (`tailwatch-backup-old`) cannot match.
	 *
	 * @param string $path Candidate path.
	 * @return string|null Canonical path, or null.
	 */
	public static function resolve_inside_storage( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! defined( 'TAILWATCH_BACKUP_DIR' ) ) {
			return null;
		}

		$root = realpath( TAILWATCH_BACKUP_DIR );
		$real = realpath( $path );

		if ( false === $root || false === $real ) {
			return null;
		}
		if ( ! is_file( $real ) ) {
			return null;
		}
		if ( 0 !== strpos( $real, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Signature over the whole grant.
	 *
	 * Every field that constrains the token is inside the signed string, separated by a
	 * character that cannot occur in any of them — otherwise a path ending in digits and
	 * an expiry could be re-partitioned into a different, equally valid grant.
	 *
	 * @param string $nonce   Token nonce.
	 * @param string $path    Canonical file path.
	 * @param int    $expires Expiry timestamp.
	 * @param string $key     Signing key.
	 * @return string
	 */
	private static function sign( $nonce, $path, $expires, $key ) {
		return hash_hmac( 'sha256', $nonce . "\0" . $path . "\0" . (int) $expires, $key );
	}

	/**
	 * Signing key, domain-separated from every other use of the master secret.
	 *
	 * Not derived from wp_salt(): this plugin ships a security-key rotation feature, and a
	 * salt-derived key would invalidate a transfer that happened to be in flight.
	 *
	 * @return string
	 */
	private static function signing_key() {
		$secret = AppSecretService::get_secret();
		if ( ! is_string( $secret ) || '' === $secret ) {
			return '';
		}

		return hash( 'sha256', 'tailwatch-backup-download|' . $secret );
	}
}
