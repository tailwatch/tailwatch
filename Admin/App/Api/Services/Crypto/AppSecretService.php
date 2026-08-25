<?php
/**
 * App Secret Service
 *
 * Provides the plugin's master secret — the key material behind the Connect JWT
 * signing key and the data-at-rest encryption key. The secret is DELIBERATELY
 * independent of WordPress's own salts (wp_salt), because the plugin ships a
 * security-key rotation feature: deriving these keys from wp_salt would let that
 * rotation invalidate every issued token and make stored ciphertext (SMTP
 * passwords, etc.) permanently undecryptable. A dedicated secret is the pattern
 * used by security plugins that rotate salts (e.g. Wordfence keeps its signing /
 * encryption keys in its own storage, independent of the salts).
 *
 * Resolution order:
 *   1. An optional TAILWATCH_APP_SECRET_KEY constant (read-only; for operators who
 *      prefer the secret kept out of the database). The plugin never writes it.
 *   2. Otherwise a 256-bit secret generated once and stored in the options table
 *      (autoload off). It survives salt rotation (independent of wp-config salts)
 *      and site migration (the database is copied), and is never written to disk.
 *
 * @package    Tailwatch
 * @subpackage Services/Crypto
 * @author     WP Tailwatch
 * @copyright  2025-2026 WP TAILWATCH LLC
 * @license    GPL-2.0+
 * @since      1.0.0
 */

namespace Tailwatch\Admin\App\Api\Services\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AppSecretService {

	/**
	 * Option name for the stored master secret.
	 */
	const OPTION_NAME = 'tailwatch_app_secret_key';

	/**
	 * Get the master secret.
	 *
	 * Consumers must NOT use this value directly as a key; they derive a
	 * purpose-specific key from it with a domain-separation prefix (see
	 * JwtService and EncryptionService), so the JWT and encryption keys are
	 * cryptographically independent of each other.
	 *
	 * @return string The master secret, or an empty string only if generation and
	 *                storage are both unavailable.
	 */
	public static function get_secret() {
		if ( defined( 'TAILWATCH_APP_SECRET_KEY' ) && is_string( TAILWATCH_APP_SECRET_KEY ) && '' !== TAILWATCH_APP_SECRET_KEY ) {
			return TAILWATCH_APP_SECRET_KEY;
		}

		$secret = get_option( self::OPTION_NAME );
		if ( is_string( $secret ) && '' !== $secret ) {
			return $secret;
		}

		return self::generate_and_store();
	}

	/**
	 * Generate the master secret and persist it once.
	 *
	 * Called at activation and, as a safety net, lazily on first use for sites
	 * upgraded without a fresh activation. Uses add_option (which fails if the row
	 * already exists) and re-reads afterwards, so two concurrent requests converge
	 * on the same stored value instead of racing to overwrite it.
	 *
	 * @return string The stored secret.
	 */
	public static function generate_and_store() {
		$existing = get_option( self::OPTION_NAME );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}

		$secret = bin2hex( random_bytes( 32 ) );

		// autoload 'no' — read only on the API/encryption paths, never on every page load.
		add_option( self::OPTION_NAME, $secret, '', 'no' );

		// Re-read so a concurrent request that won the insert is honoured.
		$stored = get_option( self::OPTION_NAME );
		return ( is_string( $stored ) && '' !== $stored ) ? $stored : $secret;
	}
}
