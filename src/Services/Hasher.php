<?php
/**
 * Peppered HMAC-SHA256 hasher for license keys, tokens, and site URLs.
 *
 * Pepper is read from the wp-config constant `LICENSEKIT_HASH_PEPPER` if defined,
 * otherwise from the auto-generated `licensekit_secrets` option. Different value
 * categories use distinct context labels so hashes can't be cross-compared even
 * when the input happens to match (e.g. a token equal to a license key).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Hasher {

	public const CTX_LICENSE_KEY = 'license';
	public const CTX_TOKEN       = 'token';
	public const CTX_SITE_URL    = 'site';

	public static function hash_license_key( string $raw_key ): string {
		return self::hash( KeyGenerator::normalize( $raw_key ), self::CTX_LICENSE_KEY );
	}

	public static function hash_token( string $token ): string {
		return self::hash( $token, self::CTX_TOKEN );
	}

	public static function hash_site_url( string $normalized_url ): string {
		return self::hash( $normalized_url, self::CTX_SITE_URL );
	}

	public static function equals( string $known, string $candidate ): bool {
		return hash_equals( $known, $candidate );
	}

	private static function hash( string $value, string $context ): string {
		return hash_hmac( 'sha256', $value, Helpers::secret( 'hash_pepper' ) . ':' . $context );
	}
}
