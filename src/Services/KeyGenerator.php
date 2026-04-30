<?php
/**
 * License key generator. Format: `LK1-XXXX-XXXX-XXXX-XXXX-XXXX`.
 *
 * 5 segments of 4 base32-Crockford characters (~100 bits entropy from random_int,
 * which is CSPRNG-backed). The version prefix `LK1` lets us rotate the format later
 * without ambiguity. Excludes I/L/O/U from the alphabet to prevent transcription errors.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

defined( 'ABSPATH' ) || exit;

final class KeyGenerator {

	public const VERSION_PREFIX = 'LK1';

	/** Crockford base32 alphabet (no I, L, O, U). */
	private const ALPHABET     = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	private const ALPHABET_LEN = 32;

	private const SEGMENT_LEN          = 4;
	private const NUM_RANDOM_SEGMENTS  = 5;
	private const PREFIX_DISPLAY_CHARS = 8; // "LK1-XXXX"

	public static function generate(): string {
		$segments = [ self::VERSION_PREFIX ];
		for ( $i = 0; $i < self::NUM_RANDOM_SEGMENTS; $i++ ) {
			$segments[] = self::random_segment();
		}
		return implode( '-', $segments );
	}

	/**
	 * Public-display prefix stored in `dlm_licenses.key_prefix` for admin search/UI.
	 * Format: `LK1-XXXX` (8 chars). Has 4 chars of random entropy (~20 bits).
	 */
	public static function prefix_of( string $key ): string {
		$key = self::normalize( $key );
		return strlen( $key ) >= self::PREFIX_DISPLAY_CHARS
			? substr( $key, 0, self::PREFIX_DISPLAY_CHARS )
			: $key;
	}

	public static function normalize( string $key ): string {
		return strtoupper( trim( $key ) );
	}

	public static function is_valid_format( string $key ): bool {
		$key     = self::normalize( $key );
		$pattern = sprintf(
			'/^%s(-[%s]{%d}){%d}$/',
			preg_quote( self::VERSION_PREFIX, '/' ),
			preg_quote( self::ALPHABET, '/' ),
			self::SEGMENT_LEN,
			self::NUM_RANDOM_SEGMENTS
		);
		return (bool) preg_match( $pattern, $key );
	}

	private static function random_segment(): string {
		$segment = '';
		for ( $i = 0; $i < self::SEGMENT_LEN; $i++ ) {
			$segment .= self::ALPHABET[ random_int( 0, self::ALPHABET_LEN - 1 ) ];
		}
		return $segment;
	}
}
