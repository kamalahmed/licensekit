<?php
/**
 * HMAC-SHA256 signer for response payloads, download tokens, and webhook bodies.
 *
 * Two signing modes:
 *   - "Envelope" — sign a JSON-serializable payload + embed signature in `signature`
 *     key. SDK / receiver pulls the signature out, recomputes over canonical JSON,
 *     and compares constant-time.
 *   - "Download token" — opaque short-lived bearer string for one-shot file fetches.
 *     Format: base64url(release_id|license_id|exp|hmac). 5-minute default TTL.
 *     Per-release `signing_salt` mixed with the global download secret so leaking
 *     one release's secret can't sign tokens for another.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Signer {

	public const DEFAULT_DOWNLOAD_TTL = 300; // 5 minutes.

	/**
	 * Sign a payload and return it with a `signature` field added.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function sign_envelope( array $payload ): array {
		$payload['signature'] = self::sign_payload( $payload );
		return $payload;
	}

	/**
	 * Verify an envelope produced by `sign_envelope()`. Server-side helper —
	 * mostly used in tests; the real verification happens in the SDK against
	 * the public key.
	 */
	public static function verify_envelope( array $envelope ): bool {
		$signature = (string) ( $envelope['signature'] ?? '' );
		if ( '' === $signature ) {
			return false;
		}
		unset( $envelope['signature'] );
		$canonical = self::canonical_json( $envelope );

		// Try Ed25519 first (production path).
		$public = Helpers::secret( 'sign_public' );
		if ( '' !== $public && function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$pub_raw = self::b64url_decode( $public );
			$sig_raw = self::b64url_decode( $signature );
			if ( null !== $pub_raw && null !== $sig_raw ) {
				return sodium_crypto_sign_verify_detached( $sig_raw, $canonical, $pub_raw );
			}
		}

		// HMAC fallback for sodium-less hosts.
		$expected = self::b64url_encode( hash_hmac( 'sha256', $canonical, Helpers::secret( 'hmac_secret' ), true ) );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Sign a payload over its canonical JSON. The `signature` key is excluded.
	 *
	 * Uses Ed25519 (libsodium) when an `sign_secret` is provisioned, falling
	 * back to HMAC-SHA256 for hosts without sodium support.
	 */
	public static function sign_payload( array $payload ): string {
		unset( $payload['signature'] );
		$canonical = self::canonical_json( $payload );

		$private = Helpers::secret( 'sign_secret' );
		if ( '' !== $private && function_exists( 'sodium_crypto_sign_detached' ) ) {
			$priv_raw = self::b64url_decode( $private );
			if ( null !== $priv_raw ) {
				return self::b64url_encode( sodium_crypto_sign_detached( $canonical, $priv_raw ) );
			}
		}

		$mac = hash_hmac( 'sha256', $canonical, Helpers::secret( 'hmac_secret' ), true );
		return self::b64url_encode( $mac );
	}

	/**
	 * Mint a base64url download token. Verifies via `verify_download_token()`.
	 *
	 * @param string $signing_salt Per-release salt from `dlm_releases.signing_salt`.
	 */
	public static function mint_download_token(
		int $release_id,
		int $license_id,
		string $signing_salt,
		int $ttl_seconds = self::DEFAULT_DOWNLOAD_TTL
	): string {
		$exp     = time() + $ttl_seconds;
		$body    = $release_id . '|' . $license_id . '|' . $exp;
		$secret  = Helpers::secret( 'download_secret' ) . $signing_salt;
		$mac     = hash_hmac( 'sha256', $body, $secret, true );
		$payload = $body . '|' . self::b64url_encode( $mac );
		return self::b64url_encode( $payload );
	}

	/**
	 * @return array{release_id:int, license_id:int, expires_at:int}|null Null if invalid/expired.
	 */
	public static function verify_download_token( string $token, string $signing_salt ): ?array {
		$decoded = self::b64url_decode( $token );
		if ( null === $decoded ) {
			return null;
		}

		$parts = explode( '|', $decoded );
		if ( count( $parts ) !== 4 ) {
			return null;
		}

		[ $release_id_s, $license_id_s, $exp_s, $sig_b64 ] = $parts;
		$expected_body                                     = $release_id_s . '|' . $license_id_s . '|' . $exp_s;
		$secret                                            = Helpers::secret( 'download_secret' ) . $signing_salt;
		$expected_sig                                      = hash_hmac( 'sha256', $expected_body, $secret, true );

		$presented_sig = self::b64url_decode( $sig_b64 );
		if ( null === $presented_sig || ! hash_equals( $expected_sig, $presented_sig ) ) {
			return null;
		}

		$exp = (int) $exp_s;
		if ( $exp < time() ) {
			return null;
		}

		return [
			'release_id' => (int) $release_id_s,
			'license_id' => (int) $license_id_s,
			'expires_at' => $exp,
		];
	}

	/**
	 * Stable JSON: recursive ksort, no whitespace, no slash escaping, no unicode escaping.
	 */
	private static function canonical_json( array $data ): string {
		$sorted = self::recursive_ksort( $data );
		return (string) wp_json_encode( $sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function recursive_ksort( array $data ): array {
		ksort( $data );
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = self::recursive_ksort( $v );
			}
		}
		return $data;
	}

	private static function b64url_encode( string $bytes ): string {
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
