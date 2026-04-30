<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\Log;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase {

	public function test_default_actor_type(): void {
		$l = Log::from_row( [ 'id' => 1, 'action' => 'license.issued' ] );
		$this->assertSame( Log::ACTOR_SYSTEM, $l->actor_type );
	}

	public function test_round_trip_with_context(): void {
		$row = [
			'id'           => '99',
			'actor_type'   => 'user',
			'actor_id'     => '5',
			'action'       => 'license.activated',
			'subject_type' => 'license',
			'subject_id'   => '42',
			'context'      => '{"site_url":"example.com"}',
		];
		$l = Log::from_row( $row );
		$this->assertSame( 99, $l->id );
		$this->assertSame( Log::ACTOR_USER, $l->actor_type );
		$this->assertSame( 5, $l->actor_id );
		$this->assertSame( 42, $l->subject_id );
		$this->assertSame( [ 'site_url' => 'example.com' ], $l->context );

		$arr = $l->to_array();
		$this->assertSame( '{"site_url":"example.com"}', $arr['context'] );
	}
}
