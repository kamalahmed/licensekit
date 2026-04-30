<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\Activation;
use PHPUnit\Framework\TestCase;

final class ActivationTest extends TestCase {

	public function test_defaults(): void {
		$a = Activation::from_row( [ 'id' => 1, 'license_id' => 5, 'site_url' => 'ex.com', 'site_url_hash' => 'h' ] );
		$this->assertSame( Activation::ENV_UNKNOWN, $a->site_environment );
		$this->assertSame( Activation::STATUS_ACTIVE, $a->status );
	}

	public function test_round_trip(): void {
		$row = [
			'id'               => '7',
			'license_id'       => '12',
			'site_url'         => 'example.com',
			'site_url_hash'    => 'abcdef',
			'site_environment' => 'staging',
			'status'           => 'deactivated',
			'meta'             => '{"php":"8.1"}',
		];
		$a = Activation::from_row( $row );
		$this->assertSame( 7, $a->id );
		$this->assertSame( 12, $a->license_id );
		$this->assertSame( 'staging', $a->site_environment );
		$this->assertSame( 'deactivated', $a->status );
		$this->assertSame( [ 'php' => '8.1' ], $a->meta );

		$arr = $a->to_array();
		$this->assertSame( '{"php":"8.1"}', $arr['meta'] );
		$this->assertSame( 'staging', $arr['site_environment'] );
	}
}
