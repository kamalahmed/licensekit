<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Models;

use LicenseKit\Models\Product;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase {

	public function test_round_trip(): void {
		$row = [
			'id'              => '1',
			'edd_download_id' => '42',
			'slug'            => 'acme',
			'name'            => 'Acme',
			'type'            => 'plugin',
			'meta'            => '{"banners":{"low":"a.png"}}',
		];
		$p = Product::from_row( $row );
		$this->assertSame( 1, $p->id );
		$this->assertSame( 42, $p->edd_download_id );
		$this->assertSame( 'acme', $p->slug );
		$this->assertSame( [ 'banners' => [ 'low' => 'a.png' ] ], $p->meta );

		$arr = $p->to_array();
		$this->assertSame( '{"banners":{"low":"a.png"}}', $arr['meta'] );
		$this->assertSame( 'acme', $arr['slug'] );
	}

	public function test_default_type_is_plugin(): void {
		$p = Product::from_row( [ 'id' => 1, 'slug' => 'x', 'name' => 'X' ] );
		$this->assertSame( 'plugin', $p->type );
	}

	public function test_empty_meta_serializes_to_null(): void {
		$p       = Product::from_row( [ 'id' => 1, 'slug' => 'x', 'name' => 'X' ] );
		$this->assertNull( $p->to_array()['meta'] );
	}

	public function test_optional_int_columns_become_null(): void {
		$p = Product::from_row( [ 'id' => 1, 'slug' => 'x', 'name' => 'X' ] );
		$this->assertNull( $p->edd_download_id );
		$this->assertNull( $p->current_release_id );
	}
}
