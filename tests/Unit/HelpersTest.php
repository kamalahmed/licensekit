<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit;

use LicenseKit\Support\Helpers;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {

	protected function setUp(): void {
		lk_test_reset_state();
	}

	public function test_secret_reads_from_options(): void {
		$this->assertSame( 'phpunit-pepper', Helpers::secret( 'hash_pepper' ) );
		$this->assertSame( 'phpunit-hmac', Helpers::secret( 'hmac_secret' ) );
	}

	public function test_secret_returns_empty_for_unknown_key(): void {
		$this->assertSame( '', Helpers::secret( 'no_such_key' ) );
	}

	public function test_now_utc_format(): void {
		$now = Helpers::now_utc();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now );
	}

	/**
	 * @dataProvider period_cases
	 */
	public function test_add_period_to_datetime( string $base, string $period, string $expected ): void {
		$this->assertSame( $expected, Helpers::add_period_to_datetime( $base, $period ) );
	}

	/** @return array<string, array{0:string, 1:string, 2:string}> */
	public function period_cases(): array {
		return [
			'1 year'    => [ '2026-01-15 12:00:00', '1y', '2027-01-15 12:00:00' ],
			'6 months'  => [ '2026-01-15 12:00:00', '6m', '2026-07-15 12:00:00' ],
			'14 days'   => [ '2026-01-15 12:00:00', '14d', '2026-01-29 12:00:00' ],
			'12 hours'  => [ '2026-01-15 12:00:00', '12h', '2026-01-16 00:00:00' ],
			'2 years'   => [ '2026-01-15 12:00:00', '2y', '2028-01-15 12:00:00' ],
			'30 days'   => [ '2026-01-15 12:00:00', '30d', '2026-02-14 12:00:00' ],
		];
	}

	public function test_add_period_returns_null_for_garbage(): void {
		$this->assertNull( Helpers::add_period_to_datetime( '2026-01-01 00:00:00', 'invalid' ) );
		$this->assertNull( Helpers::add_period_to_datetime( '2026-01-01 00:00:00', '' ) );
		$this->assertNull( Helpers::add_period_to_datetime( '2026-01-01 00:00:00', '1x' ) );
	}

	public function test_add_period_uses_now_when_base_is_empty(): void {
		$result = Helpers::add_period_to_datetime( '', '1y' );
		$this->assertNotNull( $result );
		// Result should be roughly a year from now (within 60s tolerance).
		$diff = strtotime( $result . ' UTC' ) - time();
		$this->assertGreaterThan( 365 * 86400 - 60, $diff );
		$this->assertLessThan( 365 * 86400 + 60, $diff );
	}

	public function test_decode_json_column_handles_nulls_and_garbage(): void {
		$this->assertSame( [], Helpers::decode_json_column( null ) );
		$this->assertSame( [], Helpers::decode_json_column( '' ) );
		$this->assertSame( [], Helpers::decode_json_column( 'not-json' ) );
		$this->assertSame( [ 'a' => 1 ], Helpers::decode_json_column( '{"a":1}' ) );
	}

	public function test_decode_json_column_rejects_non_array_root(): void {
		// JSON `null` and `42` decode to non-arrays — return [].
		$this->assertSame( [], Helpers::decode_json_column( 'null' ) );
		$this->assertSame( [], Helpers::decode_json_column( '42' ) );
	}

	public function test_encode_json_column(): void {
		$this->assertNull( Helpers::encode_json_column( [] ) );
		$this->assertSame( '{"a":1}', Helpers::encode_json_column( [ 'a' => 1 ] ) );
	}

	public function test_hash_equals_constant_time(): void {
		$this->assertTrue( Helpers::hash_equals( 'abc', 'abc' ) );
		$this->assertFalse( Helpers::hash_equals( 'abc', 'abd' ) );
	}
}
