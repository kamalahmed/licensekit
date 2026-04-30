<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit;

use LicenseKit\Services\Hasher;
use PHPUnit\Framework\TestCase;

final class HasherTest extends TestCase {

	public function test_same_key_produces_same_hash(): void {
		$h1 = Hasher::hash_license_key( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JK' );
		$h2 = Hasher::hash_license_key( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JK' );
		$this->assertSame( $h1, $h2 );
	}

	public function test_normalization_unifies_variants(): void {
		$h1 = Hasher::hash_license_key( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JK' );
		$h2 = Hasher::hash_license_key( '  lk1-a7f2-b3c4-d5e6-f7g8-h9jk  ' );
		$this->assertSame( $h1, $h2 );
	}

	public function test_different_keys_diverge(): void {
		$h1 = Hasher::hash_license_key( 'LK1-A7F2-B3C4-D5E6-F7G8-H9JK' );
		$h2 = Hasher::hash_license_key( 'LK1-DIFFERENT-KEY-HERE-XXXX-YYYY' );
		$this->assertNotSame( $h1, $h2 );
	}

	public function test_context_separation(): void {
		// Same input under different contexts must produce different hashes.
		$as_license = Hasher::hash_license_key( 'shared-input' );
		$as_token   = Hasher::hash_token( 'shared-input' );
		$as_site    = Hasher::hash_site_url( 'shared-input' );
		$this->assertNotSame( $as_license, $as_token );
		$this->assertNotSame( $as_license, $as_site );
		$this->assertNotSame( $as_token, $as_site );
	}

	public function test_hex_output_length(): void {
		$this->assertSame( 64, strlen( Hasher::hash_license_key( 'x' ) ) );
	}

	public function test_equals_constant_time(): void {
		$this->assertTrue( Hasher::equals( 'abc', 'abc' ) );
		$this->assertFalse( Hasher::equals( 'abc', 'abd' ) );
	}
}
