<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit;

use LicenseKit\Models\Activation;
use LicenseKit\Services\SiteNormalizer;
use PHPUnit\Framework\TestCase;

final class SiteNormalizerTest extends TestCase {

	/**
	 * @dataProvider normalize_cases
	 */
	public function test_normalize( string $input, string $expected ): void {
		$this->assertSame( $expected, SiteNormalizer::normalize( $input ) );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public function normalize_cases(): array {
		return [
			'lowercase + strip slash'        => [ 'https://Example.com/', 'example.com' ],
			'strip www'                      => [ 'https://www.Example.com/', 'example.com' ],
			'strip default port :80'         => [ 'http://example.com:80', 'example.com' ],
			'strip default port :443'        => [ 'http://example.com:443', 'example.com' ],
			'preserve non-default port'      => [ 'http://example.com:8080/path?q=1', 'example.com:8080' ],
			'bare hostname'                  => [ 'example.com', 'example.com' ],
			'strip path entirely'            => [ 'https://Example.COM/foo/bar', 'example.com' ],
			'trim whitespace'                => [ '  https://example.com  ', 'example.com' ],
		];
	}

	public function test_detect_environment_local_hosts(): void {
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( 'localhost' ) );
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( '127.0.0.1' ) );
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( 'mysite.local' ) );
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( 'mysite.test' ) );
	}

	public function test_detect_environment_private_ranges(): void {
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( '192.168.1.1' ) );
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( '10.0.0.1' ) );
		$this->assertSame( Activation::ENV_LOCAL, SiteNormalizer::detect_environment( '172.16.0.1' ) );
	}

	public function test_detect_environment_production(): void {
		$this->assertSame( Activation::ENV_PRODUCTION, SiteNormalizer::detect_environment( 'example.com' ) );
		$this->assertSame( Activation::ENV_PRODUCTION, SiteNormalizer::detect_environment( 'staging.example.com' ) );
	}

	public function test_detect_environment_unknown_for_empty(): void {
		$this->assertSame( Activation::ENV_UNKNOWN, SiteNormalizer::detect_environment( '' ) );
	}
}
