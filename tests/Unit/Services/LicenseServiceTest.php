<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Services;

use LicenseKit\Models\Activation;
use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\Hasher;
use LicenseKit\Services\KeyGenerator;
use LicenseKit\Services\LicenseService;
use PHPUnit\Framework\TestCase;

final class LicenseServiceTest extends TestCase {

	private $licenses;
	private $products;
	private $activations;
	private $audit;
	private LicenseService $svc;

	protected function setUp(): void {
		lk_test_reset_state();

		$this->licenses    = $this->createMock( LicenseRepository::class );
		$this->products    = $this->createMock( ProductRepository::class );
		$this->activations = $this->createMock( ActivationRepository::class );
		$this->audit       = $this->createMock( AuditLogger::class );

		$this->svc = new LicenseService( $this->licenses, $this->products, $this->activations, $this->audit );
	}

	public function test_issue_returns_raw_key_and_persists_license(): void {
		$product       = new Product();
		$product->id   = 5;
		$product->slug = 'acme';
		$product->name = 'Acme';

		$this->products->method( 'find' )->with( 5 )->willReturn( $product );

		$inserted = null;
		$this->licenses
			->expects( $this->once() )
			->method( 'insert' )
			->willReturnCallback(
				function ( License $license ) use ( &$inserted ): int {
					$inserted = $license;
					return 99;
				}
			);

		$result = $this->svc->issue( [
			'product_id'       => 5,
			'tier'             => 'single',
			'activation_limit' => 1,
			'customer_email'   => 'buyer@example.com',
			'expires_at'       => '2027-01-01 00:00:00',
		] );

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['raw_key'] );
		$this->assertTrue( KeyGenerator::is_valid_format( $result['raw_key'] ) );

		$this->assertInstanceOf( License::class, $inserted );
		$this->assertSame( Hasher::hash_license_key( $result['raw_key'] ), $inserted->key_hash );
		$this->assertSame( 5, $inserted->product_id );
		$this->assertSame( 'buyer@example.com', $inserted->customer_email );
	}

	public function test_issue_fails_when_product_missing(): void {
		$this->products->method( 'find' )->willReturn( null );

		$result = $this->svc->issue( [ 'product_id' => 1, 'tier' => 'single', 'activation_limit' => 1 ] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( LicenseService::ERR_PRODUCT_NOT_FOUND, $result['error'] );
	}

	public function test_activate_returns_invalid_key_for_unknown(): void {
		$this->licenses->method( 'find_by_key_hash' )->willReturn( null );

		$result = $this->svc->activate( 'LK1-AAAA-BBBB-CCCC-DDDD-EEEE', 'slug', 'https://example.com', 'production' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( LicenseService::ERR_INVALID_KEY, $result['error'] );
	}

	public function test_activate_fails_when_product_mismatches(): void {
		$license = $this->build_license( 1, 5 );
		$product = $this->build_product( 5, 'expected-slug' );

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->products->method( 'find' )->willReturn( $product );

		$result = $this->svc->activate( 'LK1-X-X-X-X-X', 'wrong-slug', 'https://example.com', 'production' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( LicenseService::ERR_PRODUCT_MISMATCH, $result['error'] );
	}

	public function test_activate_succeeds_idempotent_when_already_active(): void {
		$license = $this->build_license( 1, 5 );
		$product = $this->build_product( 5, 'acme' );
		$existing                = new Activation();
		$existing->id            = 7;
		$existing->license_id    = 1;
		$existing->status        = Activation::STATUS_ACTIVE;

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->products->method( 'find' )->willReturn( $product );
		$this->activations->method( 'find_by_license_and_site' )
			->with( 1, $this->isType( 'string' ), Activation::STATUS_ACTIVE )
			->willReturn( $existing );

		$this->activations->expects( $this->once() )->method( 'update' );
		$this->activations->method( 'count_billable_active_for_license' )->willReturn( 1 );

		$result = $this->svc->activate( 'LK1-X-X-X-X-X', 'acme', 'https://example.com', 'production' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'active', $result['license']['status'] );
	}

	public function test_activate_blocks_at_activation_limit(): void {
		$license                   = $this->build_license( 1, 5 );
		$license->activation_limit = 2;
		$product                   = $this->build_product( 5, 'acme' );

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->products->method( 'find' )->willReturn( $product );
		$this->activations->method( 'find_by_license_and_site' )->willReturn( null );
		$this->activations->method( 'count_billable_active_for_license' )->willReturn( 2 );

		$result = $this->svc->activate( 'LK1-X-X-X-X-X', 'acme', 'https://example.com', 'production' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( LicenseService::ERR_ACTIVATION_LIMIT, $result['error'] );
	}

	public function test_activate_local_environment_exempt_from_limit(): void {
		$license                   = $this->build_license( 1, 5 );
		$license->activation_limit = 2;
		$product                   = $this->build_product( 5, 'acme' );

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->products->method( 'find' )->willReturn( $product );
		$this->activations->method( 'find_by_license_and_site' )->willReturn( null );
		$this->activations->method( 'count_billable_active_for_license' )->willReturn( 2 ); // already at cap, but local exempt

		$this->activations->expects( $this->once() )->method( 'insert' )->willReturn( 1 );

		$result = $this->svc->activate( 'LK1-X-X-X-X-X', 'acme', 'https://localhost', 'local' );

		$this->assertTrue( $result['success'] );
	}

	public function test_activate_rejects_expired_license_past_grace(): void {
		$license            = $this->build_license( 1, 5 );
		$license->expires_at = '2020-01-01 00:00:00';
		$license->grace_until = null;
		$product            = $this->build_product( 5, 'acme' );

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->products->method( 'find' )->willReturn( $product );

		$result = $this->svc->activate( 'LK1-X-X-X-X-X', 'acme', 'https://example.com', 'production' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( LicenseService::ERR_EXPIRED, $result['error'] );
	}

	public function test_activate_allows_within_grace_period(): void {
		$license             = $this->build_license( 1, 5 );
		$license->expires_at = '2020-01-01 00:00:00';
		$license->grace_until = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$product             = $this->build_product( 5, 'acme' );

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->products->method( 'find' )->willReturn( $product );
		$this->activations->method( 'find_by_license_and_site' )->willReturn( null );
		$this->activations->method( 'count_billable_active_for_license' )->willReturn( 0 );
		$this->activations->expects( $this->once() )->method( 'insert' )->willReturn( 1 );

		$result = $this->svc->activate( 'LK1-X-X-X-X-X', 'acme', 'https://example.com', 'production' );

		$this->assertTrue( $result['success'] );
	}

	public function test_deactivate_is_idempotent_when_not_active(): void {
		$license = $this->build_license( 1, 5 );

		$this->licenses->method( 'find_by_key_hash' )->willReturn( $license );
		$this->activations->method( 'find_by_license_and_site' )->willReturn( null );

		$result = $this->svc->deactivate( 'LK1-X-X-X-X-X', 'https://example.com' );

		$this->assertTrue( $result['success'] );
	}

	public function test_rotate_key_preserves_expires_at_and_activations(): void {
		$license             = $this->build_license( 42, 5 );
		$license->expires_at = '2027-01-01 00:00:00';

		$this->licenses->method( 'find' )->with( 42 )->willReturn( $license );

		$captured_changes = null;
		$this->licenses
			->expects( $this->once() )
			->method( 'update' )
			->willReturnCallback(
				function ( $id, $changes ) use ( &$captured_changes ) {
					$captured_changes = $changes;
					return true;
				}
			);

		$result = $this->svc->rotate_key( 42 );

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['raw_key'] );

		// Critically: rotate must NOT touch expires_at or activation_limit.
		$this->assertArrayNotHasKey( 'expires_at', $captured_changes );
		$this->assertArrayNotHasKey( 'activation_limit', $captured_changes );
		$this->assertArrayHasKey( 'key_hash', $captured_changes );
		$this->assertArrayHasKey( 'key_prefix', $captured_changes );
		$this->assertArrayHasKey( 'key_encrypted', $captured_changes );
	}

	public function test_extend_adds_period_from_current_expiry(): void {
		$license             = $this->build_license( 1, 5 );
		$license->expires_at = gmdate( 'Y-m-d H:i:s', time() + 60 );
		$license->status     = License::STATUS_ACTIVE;

		$this->licenses->method( 'find' )->willReturn( $license );

		$captured = null;
		$this->licenses->method( 'update' )->willReturnCallback(
			function ( $id, $changes ) use ( &$captured ) {
				$captured = $changes;
				return true;
			}
		);

		$result = $this->svc->extend( 1, '1y' );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'expires_at', $captured );
		// The new expiry should be roughly 1 year out, not 1 year + the original 60s.
		$diff = strtotime( $captured['expires_at'] . ' UTC' ) - time();
		$this->assertGreaterThan( 365 * 86400 - 120, $diff );
		$this->assertLessThan( 365 * 86400 + 120, $diff );
	}

	public function test_extend_lifetime_is_no_op(): void {
		$license             = $this->build_license( 1, 5 );
		$license->expires_at = null;
		$this->licenses->method( 'find' )->willReturn( $license );
		$this->licenses->expects( $this->never() )->method( 'update' );

		$result = $this->svc->extend( 1, '1y' );

		$this->assertTrue( $result['success'] );
	}

	public function test_set_status_rejects_unknown_status(): void {
		$license = $this->build_license( 1, 5 );
		$this->licenses->method( 'find' )->willReturn( $license );

		$result = $this->svc->set_status( 1, 'imaginary' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_status', $result['error'] );
	}

	public function test_set_status_writes_for_valid_status(): void {
		$license = $this->build_license( 1, 5 );
		$this->licenses->method( 'find' )->willReturn( $license );

		$captured = null;
		$this->licenses->method( 'update' )->willReturnCallback(
			function ( $id, $changes ) use ( &$captured ) {
				$captured = $changes;
				return true;
			}
		);

		$result = $this->svc->set_status( 1, License::STATUS_REVOKED );

		$this->assertTrue( $result['success'] );
		$this->assertSame( License::STATUS_REVOKED, $captured['status'] );
	}

	private function build_license( int $id, int $product_id ): License {
		$l                   = new License();
		$l->id               = $id;
		$l->product_id       = $product_id;
		$l->key_hash         = 'h';
		$l->key_prefix       = 'p';
		$l->tier             = 'single';
		$l->activation_limit = 1;
		$l->status           = License::STATUS_ACTIVE;
		$l->expires_at       = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		return $l;
	}

	private function build_product( int $id, string $slug ): Product {
		$p       = new Product();
		$p->id   = $id;
		$p->slug = $slug;
		$p->name = ucfirst( $slug );
		$p->type = 'plugin';
		return $p;
	}
}
