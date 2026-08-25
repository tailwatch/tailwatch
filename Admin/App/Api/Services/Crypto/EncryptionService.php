<?php
/**
 * Encryption Service
 *
 * Provides AES-256-GCM authenticated encryption/decryption for sensitive
 * credentials such as SMTP passwords.
 *
 * Format: hex(iv):hex(tag):hex(ciphertext). GCM is authenticated (AEAD):
 * the 16-byte tag is produced by openssl_encrypt() and verified by openssl_decrypt(),
 * so any tampering with the stored ciphertext makes decrypt() return false rather
 * than returning altered plaintext.
 *
 * All consumers across the free and pro plugins call this single service. The key is
 * derived from the plugin's master secret (AppSecretService), which is stored
 * independently of WordPress's salts. It is deliberately NOT derived from wp_salt():
 * the plugin ships a security-key rotation feature, and a salt-derived key would make
 * every stored secret permanently undecryptable each time the salts rotate. Values
 * encrypted by an earlier version (which did derive the key from wp_salt) still
 * decrypt via a legacy fallback in decrypt() and are re-encrypted with the current
 * key the next time they are saved.
 *
 * @package    Tailwatch
 * @subpackage Services/Crypto
 */

namespace Tailwatch\Admin\App\Api\Services\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EncryptionService {

	const CIPHER     = 'aes-256-gcm';
	const IV_LENGTH  = 12; // 96-bit nonce: the recommended IV size for GCM.
	const TAG_LENGTH = 16; // 128-bit authentication tag.

	/**
	 * Encrypt sensitive data using AES-256-GCM (authenticated).
	 *
	 * @param string $data Plaintext data to encrypt.
	 * @return string|false Hex-encoded "iv:tag:ciphertext" string; the original value
	 *                unchanged if the encryption key is unavailable; or false if the
	 *                cipher itself fails (so a caller never silently persists plaintext).
	 */
	public static function encrypt( $data ) {
		if ( empty( $data ) ) {
			return $data;
		}

		$key = self::get_key();
		if ( ! $key ) {
			return $data;
		}

		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';
		// $tag is passed by reference: openssl_encrypt() writes the GCM auth tag into it.
		$ciphertext = openssl_encrypt( $data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );

		if ( false === $ciphertext ) {
			return false;
		}

		// Format: hex(iv):hex(tag):hex(ciphertext).
		return bin2hex( $iv ) . ':' . bin2hex( $tag ) . ':' . bin2hex( $ciphertext );
	}

	/**
	 * Decrypt data encrypted by encrypt().
	 *
	 * @param string $data Encrypted string in "hex(iv):hex(tag):hex(ciphertext)" format.
	 * @return string|false Decrypted plaintext, or false if the value is not in GCM
	 *                      format, the tag fails verification, or decryption fails.
	 */
	public static function decrypt( $data ) {
		if ( empty( $data ) || ! is_string( $data ) ) {
			return false;
		}

		$parts = explode( ':', $data );
		if ( count( $parts ) !== 3 ) {
			return false; // Not GCM format.
		}

		// Validate the hex before hex2bin(), which emits a warning on malformed input.
		if ( ! self::is_hex_bytes( $parts[0], self::IV_LENGTH )
			|| ! self::is_hex_bytes( $parts[1], self::TAG_LENGTH )
			|| ! ctype_xdigit( $parts[2] ) || 0 !== strlen( $parts[2] ) % 2 ) {
			return false;
		}

		$iv  = hex2bin( $parts[0] );
		$tag = hex2bin( $parts[1] );
		$ct  = hex2bin( $parts[2] );

		// Try the current key first. GCM is authenticated, so an incorrect key makes
		// openssl_decrypt() return false (tag mismatch) rather than altered plaintext.
		// That lets us safely fall back to the legacy wp_salt-derived key for values
		// encrypted by an earlier version; those are re-encrypted with the current key
		// on the next save.
		$key = self::get_key();
		if ( $key ) {
			$plaintext = openssl_decrypt( $ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		$legacy_key = self::get_legacy_key();
		if ( $legacy_key ) {
			$plaintext = openssl_decrypt( $ct, self::CIPHER, $legacy_key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return false;
	}

	/**
	 * Check whether a string is in this service's encrypted (GCM) format.
	 *
	 * @param string $data Value to test.
	 * @return bool True if the value matches the "hex(iv):hex(tag):hex(ct)" format.
	 */
	public static function is_encrypted( $data ) {
		if ( empty( $data ) || ! is_string( $data ) ) {
			return false;
		}
		$parts = explode( ':', $data );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		return self::is_hex_bytes( $parts[0], self::IV_LENGTH )
			&& self::is_hex_bytes( $parts[1], self::TAG_LENGTH );
	}

	/**
	 * Whether a value is exactly $byte_len bytes encoded as hex.
	 *
	 * @param mixed $hex      Value to test.
	 * @param int   $byte_len Expected decoded length in bytes.
	 * @return bool
	 */
	private static function is_hex_bytes( $hex, $byte_len ) {
		return is_string( $hex ) && strlen( $hex ) === $byte_len * 2 && ctype_xdigit( $hex );
	}

	/**
	 * Get the encryption key, derived to 32 bytes via SHA-256.
	 *
	 * Order of precedence:
	 *   1. An optional TAILWATCH_ENCRYPTION_KEY constant (read-only).
	 *   2. Otherwise a key derived from the plugin's master secret (AppSecretService),
	 *      stored independently of the WordPress salts so the security-key rotation
	 *      feature cannot render stored ciphertext undecryptable. A distinct
	 *      domain-separation prefix keeps this key independent of the JWT signing key
	 *      derived from the same master secret.
	 *
	 * @return string|false 32-byte binary key or false if unavailable.
	 */
	private static function get_key() {
		if ( defined( 'TAILWATCH_ENCRYPTION_KEY' ) && is_string( TAILWATCH_ENCRYPTION_KEY ) && '' !== TAILWATCH_ENCRYPTION_KEY ) {
			return hash( 'sha256', 'tailwatch-encryption|' . TAILWATCH_ENCRYPTION_KEY, true );
		}
		$secret = AppSecretService::get_secret();
		if ( '' === $secret ) {
			return false;
		}
		return hash( 'sha256', 'tailwatch-encryption|' . $secret, true );
	}

	/**
	 * Legacy encryption key derivation (wp_salt-based), used by versions before the
	 * master secret was introduced. Retained only so values encrypted then keep
	 * decrypting; decrypt() falls back to it and re-encryption with the current key
	 * happens on the next save.
	 *
	 * @return string|false 32-byte binary key or false if unavailable.
	 */
	private static function get_legacy_key() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return false;
		}
		$salt = wp_salt( 'secure_auth' );
		if ( empty( $salt ) ) {
			return false;
		}
		return hash( 'sha256', 'tailwatch-encryption|' . $salt, true );
	}
}
