<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\License;
use LicenseKit\Services\EncryptedKey;
use PHPUnit\Framework\TestCase;

final class LicenseTest extends TestCase {

	protected function setUp(): void {
		lk_test_reset_state();
	}

	public function test_hydration_casts_types(): void {
		$row = [
			'id'                => '42',
			'key_hash'          => 'h',
			'key_prefix'        => 'LK1-A7F2',
			'customer_id'       => '7',
			'customer_email'    => 'test@example.com',
			'product_id'        => '3',
			'edd_order_id'      => '99',
			'edd_price_id'      => '1',
			'tier'              => 'single',
			'activation_limit'  => '5',
			'status'            => 'active',
			'meta'              => '{"foo":"bar"}',
		];
		$l = License::from_row( $row );
		$this->assertSame( 42, $l->id );
		$this->assertSame( 7, $l->customer_id );
		$this->assertSame( 3, $l->product_id );
		$this->assertSame( 99, $l->edd_order_id );
		$this->assertSame( 1, $l->edd_price_id );
		$this->assertSame( 5, $l->activation_limit );
		$this->assertSame( [ 'foo' => 'bar' ], $l->meta );
	}

	public function test_round_trip_through_to_array(): void {
		$row = [ 'id' => 1, 'key_hash' => 'h', 'key_prefix' => 'p', 'product_id' => 1, 'tier' => 'five', 'activation_limit' => 5, 'status' => 'active', 'meta' => '{"k":"v"}' ];
		$l   = License::from_row( $row );
		$arr = $l->to_array();
		$l2  = License::from_row( $arr );
		$this->assertSame( $l->meta, $l2->meta );
		$this->assertSame( $l->status, $l2->status );
		$this->assertSame( $l->activation_limit, $l2->activation_limit );
	}

	public function test_is_lifetime_when_expires_at_null(): void {
		$l = new License();
		$l->expires_at = null;
		$this->assertTrue( $l->is_lifetime() );

		$l->expires_at = '2030-01-01 00:00:00';
		$this->assertFalse( $l->is_lifetime() );
	}

	public function test_is_unlimited_when_limit_zero(): void {
		$l = new License();
		$l->activation_limit = 0;
		$this->assertTrue( $l->is_unlimited() );

		$l->activation_limit = 1;
		$this->assertFalse( $l->is_unlimited() );
	}

	public function test_reveal_raw_key_returns_decrypted_value(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$raw = 'LK1-A7F2-B3C4-D5E6-F7G8-H9JK';

		$l                = new License();
		$l->key_encrypted = EncryptedKey::encrypt( $raw );

		$this->assertSame( $raw, $l->reveal_raw_key() );
	}

	public function test_reveal_raw_key_returns_null_when_no_blob(): void {
		$l = new License();
		$l->key_encrypted = null;
		$this->assertNull( $l->reveal_raw_key() );
	}
}
