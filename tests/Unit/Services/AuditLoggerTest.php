<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Services;

use LicenseKit\Models\Log;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Services\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase {

	private $repo;
	private AuditLogger $audit;
	private array $captured = [];

	protected function setUp(): void {
		lk_test_reset_state();
		$captured = &$this->captured;

		$this->repo  = $this->getMockBuilder( LogRepository::class )->onlyMethods( [ 'insert' ] )->getMock();
		$this->repo->method( 'insert' )->willReturnCallback(
			static function ( $log ) use ( &$captured ) {
				$captured[] = $log;
				return count( $captured );
			}
		);
		$this->audit = new AuditLogger( $this->repo );
	}

	public function test_record_writes_with_system_actor_when_logged_out(): void {
		$id = $this->audit->record( 'license.issued', [ 'foo' => 'bar' ], 'license', 42 );

		$this->assertSame( 1, $id );
		$this->assertCount( 1, $this->captured );

		$entry = $this->captured[0];
		$this->assertInstanceOf( Log::class, $entry );
		$this->assertSame( 'license.issued', $entry->action );
		$this->assertSame( 'license', $entry->subject_type );
		$this->assertSame( 42, $entry->subject_id );
		$this->assertSame( [ 'foo' => 'bar' ], $entry->context );
		$this->assertSame( Log::ACTOR_SYSTEM, $entry->actor_type );
	}

	public function test_explicit_actor_overrides_default(): void {
		$this->audit->record( 'a', [], null, null, Log::ACTOR_LICENSE, 99 );
		$entry = $this->captured[0];
		$this->assertSame( Log::ACTOR_LICENSE, $entry->actor_type );
		$this->assertSame( 99, $entry->actor_id );
	}

	public function test_created_at_is_iso_format(): void {
		$this->audit->record( 'a' );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			(string) $this->captured[0]->created_at
		);
	}
}
