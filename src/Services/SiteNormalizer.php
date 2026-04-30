<?php
/**
 * Site URL canonicalizer.
 *
 * Server- and SDK-side must apply identical rules so the resulting `site_url_hash`
 * matches across requests. Rules:
 *   1. Trim whitespace; lowercase host.
 *   2. Strip scheme, path, query, fragment, trailing slash.
 *   3. Strip leading `www.`.
 *   4. Strip default ports (`:80`, `:443`); preserve non-default ports.
 *   5. IDN → punycode via `idn_to_ascii` when intl is available.
 *
 * Returns host (+ optional non-default port). Never includes scheme.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Models\Activation;

defined( 'ABSPATH' ) || exit;

final class SiteNormalizer {

	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// parse_url needs a scheme; tolerate bare hostnames.
		if ( false === strpos( $url, '://' ) ) {
			$url = 'https://' . $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return strtolower( $url );
		}

		$host = strtolower( (string) $parts['host'] );

		if ( function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
			$idn = @idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $idn && '' !== $idn ) {
				$host = $idn;
			}
		}

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( 80 === $port || 443 === $port ) {
			$port = 0;
		}

		return $port > 0 ? $host . ':' . $port : $host;
	}

	/**
	 * Detect environment from the URL alone (best-effort fallback when the SDK
	 * doesn't provide one explicitly). Local hosts and private-IP ranges count
	 * as `local`; everything else as `production`. Staging detection is the
	 * SDK's responsibility — it has WP_ENVIRONMENT_TYPE on the customer's site.
	 */
	public static function detect_environment( string $normalized_url ): string {
		$host = explode( ':', $normalized_url )[0];

		if ( '' === $host ) {
			return Activation::ENV_UNKNOWN;
		}

		if ( 'localhost' === $host || '127.0.0.1' === $host || '::1' === $host ) {
			return Activation::ENV_LOCAL;
		}

		if ( preg_match( '/\.(local|test|localhost|invalid|example)$/', $host ) ) {
			return Activation::ENV_LOCAL;
		}

		// Private / reserved IPs.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$is_public = filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( ! $is_public ) {
				return Activation::ENV_LOCAL;
			}
		}

		return Activation::ENV_PRODUCTION;
	}

	/**
	 * Convenience wrapper for the (normalize → hash) pair.
	 */
	public static function hash( string $url ): string {
		return Hasher::hash_site_url( self::normalize( $url ) );
	}
}
