<?php
/**
 * At-rest encryption for raw license keys, using libsodium's authenticated
 * symmetric secretbox. The encryption key is SHA-256 of the pepper, so the
 * pepper alone is sufficient to recover keys — DB access without `wp-config.php`
 * (where the pepper lives in production) is not.
 *
 * Returns a base64url-encoded blob (`nonce | ciphertext`) for storage; the
 * `key_encrypted` column on `dlm_licenses` accepts plain text.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class EncryptedKey {

	/**
	 * Returns a base64url-encoded ciphertext, or null if libsodium is missing
	 * (in which case the caller should leave `key_encrypted` NULL — the rest of
	 * the system still works, the customer dashboard just can't reveal the key).
	 */
	public static function encrypt( string $plaintext ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		$key   = self::derive_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return self::b64url( $nonce . $cipher );
	}

	/**
	 * Returns the original plaintext, or null if the blob is malformed,
	 * tampered, or libsodium is missing.
	 */
	public static function decrypt( ?string $encoded ): ?string {
		if ( null === $encoded || '' === $encoded ) {
			return null;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$decoded = self::b64url_decode( $encoded );
		if ( null === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = self::derive_key();
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return false === $plain ? null : $plain;
	}

	private static function derive_key(): string {
		// 32-byte key derived from the pepper. Different from the bare pepper
		// so that even a logic mistake elsewhere can't accidentally compare.
		return hash( 'sha256', 'lk_encrypt:' . Helpers::secret( 'hash_pepper' ), true );
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function b64url_decode( string $token ): ?string {
		$padded  = strtr( $token, '-_', '+/' );
		$mod     = strlen( $padded ) % 4;
		$padded .= 0 === $mod ? '' : str_repeat( '=', 4 - $mod );
		$out     = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return false === $out ? null : $out;
	}
}
