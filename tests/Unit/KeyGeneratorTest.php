<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit;

use LicenseKit\Services\KeyGenerator;
use PHPUnit\Framework\TestCase;

final class KeyGeneratorTest extends TestCase {

	public function test_generated_key_matches_expected_format(): void {
		$key = KeyGenerator::generate();
		$this->assertSame( 28, strlen( $key ) );
		$this->assertStringStartsWith( 'LK1-', $key );
		$this->assertTrue( KeyGenerator::is_valid_format( $key ) );
	}

	public function test_two_generations_are_distinct(): void {
		$this->assertNotSame( KeyGenerator::generate(), KeyGenerator::generate() );
	}

	public function test_normalize_uppercases_and_trims(): void {
		$this->assertSame( 'LK1-A7F2-XXXX-YYYY-ZZZZ-WWWW', KeyGenerator::normalize( '  lk1-a7f2-xxxx-yyyy-zzzz-wwww  ' ) );
	}

	public function test_is_valid_format_rejects_excluded_letters(): void {
		// Crockford base32 excludes I, L, O, U.
		$this->assertFalse( KeyGenerator::is_valid_format( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JI' ) );
		$this->assertFalse( KeyGenerator::is_valid_format( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JL' ) );
		$this->assertFalse( KeyGenerator::is_valid_format( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JO' ) );
		$this->assertFalse( KeyGenerator::is_valid_format( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JU' ) );
	}

	public function test_is_valid_format_rejects_wrong_version_prefix(): void {
		$this->assertFalse( KeyGenerator::is_valid_format( 'LK2-A7F2-B3C4-D5E6-F7G8-H9JK' ) );
	}

	public function test_is_valid_format_rejects_garbage(): void {
		$this->assertFalse( KeyGenerator::is_valid_format( 'not-a-key' ) );
		$this->assertFalse( KeyGenerator::is_valid_format( '' ) );
	}

	public function test_prefix_returns_first_eight_chars(): void {
		$this->assertSame( 'LK1-A7F2', KeyGenerator::prefix_of( 'LK1-A7F2-XXXX-YYYY-ZZZZ-WWWW' ) );
	}

	public function test_prefix_handles_short_input(): void {
		$this->assertSame( 'SHORT', KeyGenerator::prefix_of( 'short' ) );
	}
}
