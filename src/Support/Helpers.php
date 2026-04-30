<?php
/**
 * Generic helpers.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Support;

defined( 'ABSPATH' ) || exit;

final class Helpers {

	/**
	 * Resolve a secret value: prefer the wp-config constant, fall back to options.
	 */
	public static function secret( string $key ): string {
		$constant_map = [
			'hash_pepper'     => 'LICENSEKIT_HASH_PEPPER',
			'download_secret' => 'LICENSEKIT_DOWNLOAD_SECRET',
			'hmac_secret'     => 'LICENSEKIT_HMAC_SECRET',
			'sign_secret'     => 'LICENSEKIT_SIGN_SECRET',
			'sign_public'     => 'LICENSEKIT_SIGN_PUBLIC',
		];

		if ( isset( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) ) {
			return (string) constant( $constant_map[ $key ] );
		}

		$secrets = (array) get_option( 'licensekit_secrets', [] );
		return (string) ( $secrets[ $key ] ?? '' );
	}

	public static function asset_url( string $relative ): string {
		return LICENSEKIT_URL . 'assets/' . ltrim( $relative, '/' );
	}

	public static function template_path( string $relative ): string {
		return LICENSEKIT_PATH . 'templates/' . ltrim( $relative, '/' );
	}

	/**
	 * Constant-time string comparison.
	 */
	public static function hash_equals( string $known, string $user ): bool {
		return hash_equals( $known, $user );
	}

	/**
	 * Decode a JSON column value to an array. Returns [] for null/empty/invalid.
	 *
	 * @param mixed $val
	 */
	public static function decode_json_column( $val ): array {
		if ( ! is_string( $val ) || '' === $val ) {
			return [];
		}
		$decoded = json_decode( $val, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Encode an array for a JSON column. Empty arrays serialize to null so MySQL stores NULL.
	 */
	public static function encode_json_column( array $val ): ?string {
		return $val ? wp_json_encode( $val ) : null;
	}

	/**
	 * Current UTC timestamp in MySQL DATETIME format.
	 */
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Add a relative period (e.g. `1y`, `6m`, `14d`, `2h`) to a UTC datetime.
	 * Returns a new UTC datetime string. Supported units: y, m, d, h.
	 *
	 * @param string $base_datetime UTC datetime in MySQL DATETIME format, or empty for now.
	 * @return string|null Null if the period is unparseable.
	 */
	public static function add_period_to_datetime( string $base_datetime, string $period ): ?string {
		if ( ! preg_match( '/^(\d+)([ymdh])$/i', $period, $m ) ) {
			return null;
		}

		$n         = (int) $m[1];
		$unit_map  = [
			'y' => 'years',
			'm' => 'months',
			'd' => 'days',
			'h' => 'hours',
		];
		$unit_word = $unit_map[ strtolower( $m[2] ) ];

		$base_ts = '' === $base_datetime ? time() : (int) strtotime( $base_datetime . ' UTC' );
		if ( $base_ts <= 0 ) {
			$base_ts = time();
		}

		$new_ts = strtotime( "+{$n} {$unit_word}", $base_ts );
		if ( false === $new_ts ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $new_ts );
	}
}
