<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Services;

use LicenseKit\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		lk_test_reset_state();
	}

	public function test_attempt_allows_up_to_max(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( RateLimiter::attempt( 'b', 5, 60 ), "attempt {$i} should pass" );
		}
		$this->assertFalse( RateLimiter::attempt( 'b', 5, 60 ), '6th attempt should fail' );
	}

	public function test_separate_buckets_isolated(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::attempt( 'b1', 5, 60 );
		}
		$this->assertFalse( RateLimiter::attempt( 'b1', 5, 60 ) );
		$this->assertTrue( RateLimiter::attempt( 'b2', 5, 60 ), 'separate bucket should still have capacity' );
	}

	public function test_reset_clears_bucket(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimiter::attempt( 'b', 5, 60 );
		}
		$this->assertFalse( RateLimiter::attempt( 'b', 5, 60 ) );
		RateLimiter::reset( 'b' );
		$this->assertTrue( RateLimiter::attempt( 'b', 5, 60 ) );
	}

	public function test_lockout(): void {
		$this->assertFalse( RateLimiter::is_locked( 'k' ) );
		RateLimiter::lockout( 'k', 60 );
		$this->assertTrue( RateLimiter::is_locked( 'k' ) );
		RateLimiter::clear_lockout( 'k' );
		$this->assertFalse( RateLimiter::is_locked( 'k' ) );
	}

	public function test_record_failure_returns_increasing_count(): void {
		$this->assertSame( 1, RateLimiter::record_failure( 'k', 900 ) );
		$this->assertSame( 2, RateLimiter::record_failure( 'k', 900 ) );
		$this->assertSame( 3, RateLimiter::record_failure( 'k', 900 ) );
	}

	public function test_clear_failures_resets_counter(): void {
		RateLimiter::record_failure( 'k', 900 );
		RateLimiter::record_failure( 'k', 900 );
		RateLimiter::clear_failures( 'k' );
		$this->assertSame( 1, RateLimiter::record_failure( 'k', 900 ), 'after clear, count starts over' );
	}

	public function test_failure_buckets_are_distinct_from_attempt_buckets(): void {
		// Filling the failure bucket should NOT consume the attempt bucket.
		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::record_failure( 'b', 900 );
		}
		$this->assertTrue( RateLimiter::attempt( 'b', 5, 60 ), 'attempt bucket should be untouched by failure tracking' );
	}
}
