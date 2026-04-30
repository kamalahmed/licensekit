<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\ApiToken;
use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase {

	public function test_is_active_with_no_expiry_or_revocation(): void {
		$t = ApiToken::from_row( [ 'id' => 1, 'user_id' => 1, 'token_hash' => 'h', 'token_prefix' => 'p', 'name' => 't' ] );
		$this->assertTrue( $t->is_active() );
	}

	public function test_revoked_token_is_not_active(): void {
		$t = ApiToken::from_row( [
			'id'         => 2,
			'user_id'    => 1,
			'token_hash' => 'h',
			'token_prefix' => 'p',
			'name'       => 't',
			'revoked_at' => '2025-01-01 00:00:00',
		] );
		$this->assertFalse( $t->is_active() );
	}

	public function test_expired_token_is_not_active(): void {
		$t = ApiToken::from_row( [
			'id'         => 3,
			'user_id'    => 1,
			'token_hash' => 'h',
			'token_prefix' => 'p',
			'name'       => 't',
			'expires_at' => '2020-01-01 00:00:00',
		] );
		$this->assertFalse( $t->is_active() );
	}

	public function test_future_expiry_is_active(): void {
		$t = ApiToken::from_row( [
			'id'         => 4,
			'user_id'    => 1,
			'token_hash' => 'h',
			'token_prefix' => 'p',
			'name'       => 't',
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
		] );
		$this->assertTrue( $t->is_active() );
	}

	public function test_abilities_round_trip(): void {
		$t = ApiToken::from_row( [
			'id'        => 1,
			'user_id'   => 1,
			'token_hash' => 'h',
			'token_prefix' => 'p',
			'name'      => 't',
			'abilities' => '["licenses.read","licenses.write"]',
		] );
		$this->assertSame( [ 'licenses.read', 'licenses.write' ], $t->abilities );

		$arr = $t->to_array();
		$this->assertSame( '["licenses.read","licenses.write"]', $arr['abilities'] );
	}
}
