<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase {

	public function test_subscribes_to(): void {
		$w = Webhook::from_row( [
			'id'           => 1,
			'endpoint_url' => 'https://example.com/hook',
			'secret'       => 's',
			'events'       => '["license.issued","license.revoked"]',
		] );
		$this->assertTrue( $w->subscribes_to( 'license.issued' ) );
		$this->assertTrue( $w->subscribes_to( 'license.revoked' ) );
		$this->assertFalse( $w->subscribes_to( 'release.created' ) );
	}

	public function test_round_trip(): void {
		$row = [
			'id'                 => '5',
			'endpoint_url'       => 'https://x.test',
			'secret'             => 'shhh',
			'events'             => '["a","b","c"]',
			'status'             => 'paused',
			'last_response_code' => '500',
			'failure_count'      => '3',
		];
		$w = Webhook::from_row( $row );
		$this->assertSame( 5, $w->id );
		$this->assertSame( [ 'a', 'b', 'c' ], $w->events );
		$this->assertSame( Webhook::STATUS_PAUSED, $w->status );
		$this->assertSame( 500, $w->last_response_code );
		$this->assertSame( 3, $w->failure_count );
	}

	public function test_default_status_is_active(): void {
		$w = Webhook::from_row( [ 'id' => 1, 'endpoint_url' => 'https://x', 'secret' => 's' ] );
		$this->assertSame( Webhook::STATUS_ACTIVE, $w->status );
	}
}
