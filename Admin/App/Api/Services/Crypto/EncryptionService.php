<?php
/**
 * Encryption Service
 *
 * Provides AES-256-GCM authenticated encryption/decryption for sensitive data
 * (SMTP passwords, OAuth tokens, 2FA secrets, backup codes).
 *
 * Format: base64(iv):base64(tag):base64(ciphertext). GCM is authenticated (AEAD):
 * the 16-byte tag is produced by openssl_encrypt() and verified by openssl_decrypt(),
 * so any tampering with the stored ciphertext makes decrypt() return false rather
 * than returning altered plaintext.
 *
 * All consumers across the free and pro plugins call this single service. The key is
 * derived from WPTW_ENCRYPTION_KEY (wp-config.php or wp_options fallback, generated on
 * activation).
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
	 * @return string Base64-encoded "iv:tag:ciphertext" string. Returns the original
	 *                value unchanged only if the encryption key is unavailable.
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
			return $data;
		}

		// Format: base64(iv):base64(tag):base64(ciphertext).
		return base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $ciphertext );
	}

	/**
	 * Decrypt data encrypted by encrypt().
	 *
	 * @param string $data Encrypted string in "base64(iv):base64(tag):base64(ciphertext)" format.
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

		$key = self::get_key();
		if ( ! $key ) {
			return false;
		}

		$iv  = base64_decode( $parts[0], true );
		$tag = base64_decode( $parts[1], true );
		$ct  = base64_decode( $parts[2], true );

		if ( false === $iv || false === $tag || false === $ct
			|| strlen( $iv ) !== self::IV_LENGTH || strlen( $tag ) !== self::TAG_LENGTH ) {
			return false;
		}

		// openssl_decrypt() verifies $tag; returns false if the ciphertext was tampered with.
		$plaintext = openssl_decrypt( $ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		return ( false !== $plaintext ) ? $plaintext : false;
	}

	/**
	 * Check whether a string is in this service's encrypted (GCM) format.
	 *
	 * @param string $data Value to test.
	 * @return bool True if the value matches the "base64(iv):base64(tag):base64(ct)" format.
	 */
	public static function is_encrypted( $data ) {
		if ( empty( $data ) || ! is_string( $data ) ) {
			return false;
		}
		$parts = explode( ':', $data );
		if ( count( $parts ) !== 3 ) {
			return false;
		}
		$iv  = base64_decode( $parts[0], true );
		$tag = base64_decode( $parts[1], true );
		return ( false !== $iv && false !== $tag
			&& strlen( $iv ) === self::IV_LENGTH && strlen( $tag ) === self::TAG_LENGTH );
	}

	/**
	 * Get the encryption key, derived to 32 bytes via SHA-256.
	 *
	 * @return string|false 32-byte binary key or false if unavailable.
	 */
	private static function get_key() {
		if ( ! defined( 'WPTW_ENCRYPTION_KEY' ) || empty( WPTW_ENCRYPTION_KEY ) ) {
			return false;
		}
		return hash( 'sha256', WPTW_ENCRYPTION_KEY, true );
	}
}
