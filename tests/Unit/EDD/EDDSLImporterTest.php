<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\EDD;

use LicenseKit\EDD\Migration\EDDSLImporter;
use LicenseKit\Models\License;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests cover the pure mapping logic. Full integration (post fetching,
 * activation migration) requires a live WordPress + EDD-SL stack and is
 * exercised in manual end-to-end testing.
 */
final class EDDSLImporterTest extends TestCase {

	public function test_status_map_covers_known_eddsl_states(): void {
		$this->assertSame( License::STATUS_ACTIVE, EDDSLImporter::STATUS_MAP['active'] );
		$this->assertSame( License::STATUS_EXPIRED, EDDSLImporter::STATUS_MAP['expired'] );
		$this->assertSame( License::STATUS_DISABLED, EDDSLImporter::STATUS_MAP['disabled'] );
		$this->assertSame( License::STATUS_DISABLED, EDDSLImporter::STATUS_MAP['inactive'] );
	}

	public function test_unknown_status_falls_through_in_map_default(): void {
		$this->assertArrayNotHasKey( 'something_weird', EDDSLImporter::STATUS_MAP );
	}

	/**
	 * Tier inference is private but pure — exercise via reflection.
	 *
	 * @dataProvider tier_cases
	 */
	public function test_infer_tier( int $limit, string $expected ): void {
		$reflection = new ReflectionClass( EDDSLImporter::class );
		$method     = $reflection->getMethod( 'infer_tier' );
		$method->setAccessible( true );
		$importer   = $reflection->newInstanceWithoutConstructor();
		$this->assertSame( $expected, $method->invoke( $importer, $limit ) );
	}

	/** @return array<string, array{0:int, 1:string}> */
	public function tier_cases(): array {
		return [
			'unlimited (0)' => [ 0, 'unlimited' ],
			'single (1)'    => [ 1, 'single' ],
			'five (5)'      => [ 5, 'five' ],
			'three (3)'     => [ 3, 'custom' ],
			'ten (10)'      => [ 10, 'custom' ],
		];
	}
}
