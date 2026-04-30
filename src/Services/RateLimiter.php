<?php
/**
 * Sliding-window rate limiter using transients.
 *
 * Sufficient for our scale (e.g. 10 activations/min/key, 30/min/IP). At higher
 * concurrency the read-modify-write race lets a few extra requests through —
 * acceptable for license validation flows; not appropriate for billing-critical
 * counters. Plus a hard `lockout()` for heavy abuse (e.g. lock a key after 5
 * failed activations for 15 minutes).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Sliding-window check + increment. Returns true if the action is allowed,
	 * false if the cap is hit. Records the attempt in either case (sliding window).
	 */
	public static function attempt( string $bucket, int $max, int $window_seconds ): bool {
		$key    = self::cache_key( $bucket );
		$now    = time();
		$stamps = get_transient( $key );
		$stamps = is_array( $stamps ) ? $stamps : [];

		// Drop stamps that have aged out of the window.
		$stamps = array_values(
			array_filter(
				$stamps,
				static fn( $t ) => is_int( $t ) && $t > ( $now - $window_seconds )
			)
		);

		if ( count( $stamps ) >= $max ) {
			set_transient( $key, $stamps, $window_seconds );
			return false;
		}

		$stamps[] = $now;
		set_transient( $key, $stamps, $window_seconds );
		return true;
	}

	public static function reset( string $bucket ): void {
		delete_transient( self::cache_key( $bucket ) );
	}

	/**
	 * Hard lockout — blocks until the duration elapses regardless of attempt count.
	 */
	public static function lockout( string $bucket, int $duration_seconds ): void {
		set_transient( self::lockout_key( $bucket ), 1, $duration_seconds );
	}

	public static function is_locked( string $bucket ): bool {
		return (bool) get_transient( self::lockout_key( $bucket ) );
	}

	public static function clear_lockout( string $bucket ): void {
		delete_transient( self::lockout_key( $bucket ) );
	}

	/**
	 * Increment a failure counter (separate from the rate-limit bucket so
	 * legitimate retries within the rate limit don't poison the lockout signal)
	 * and return the new count within the window.
	 */
	public static function record_failure( string $bucket, int $window_seconds ): int {
		$key    = self::failure_key( $bucket );
		$now    = time();
		$stamps = get_transient( $key );
		$stamps = is_array( $stamps ) ? $stamps : [];
		$stamps = array_values(
			array_filter(
				$stamps,
				static fn( $t ) => is_int( $t ) && $t > ( $now - $window_seconds )
			)
		);
		$stamps[] = $now;
		set_transient( $key, $stamps, $window_seconds );
		return count( $stamps );
	}

	public static function clear_failures( string $bucket ): void {
		delete_transient( self::failure_key( $bucket ) );
	}

	private static function cache_key( string $bucket ): string {
		return 'lk_rate_' . substr( md5( $bucket ), 0, 24 );
	}

	private static function failure_key( string $bucket ): string {
		return 'lk_fail_' . substr( md5( $bucket ), 0, 24 );
	}

	private static function lockout_key( string $bucket ): string {
		return 'lk_lock_' . substr( md5( $bucket ), 0, 24 );
	}
}
