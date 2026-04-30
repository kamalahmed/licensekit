<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\Release;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase {

	public function test_round_trip(): void {
		$row = [
			'id'           => '10',
			'product_id'   => '5',
			'version'      => '1.2.3',
			'channel'      => 'beta',
			'file_path'    => 'acme/acme-1.2.3.zip',
			'file_size'    => '102400',
			'file_hash'    => 'deadbeef' . str_repeat( '0', 56 ),
			'signing_salt' => 'salt',
			'changelog_md' => '* fixed it',
			'requires_wp'  => '6.0',
			'requires_php' => '7.4',
			'tested_up_to' => '6.4',
		];
		$r = Release::from_row( $row );
		$this->assertSame( 10, $r->id );
		$this->assertSame( 5, $r->product_id );
		$this->assertSame( 102400, $r->file_size );
		$this->assertSame( '1.2.3', $r->version );
		$this->assertSame( 'beta', $r->channel );

		$arr = $r->to_array();
		$this->assertSame( 102400, $arr['file_size'] );
		$this->assertSame( '1.2.3', $arr['version'] );
	}

	public function test_defaults(): void {
		$r = Release::from_row( [ 'id' => 1, 'product_id' => 1, 'version' => '1.0.0', 'signing_salt' => 's' ] );
		$this->assertSame( 'stable', $r->channel );
		$this->assertNull( $r->file_path );
	}
}
