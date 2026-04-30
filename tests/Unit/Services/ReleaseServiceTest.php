<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Services;

use LicenseKit\Models\Product;
use LicenseKit\Models\Release;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\ReleaseService;
use LicenseKit\Storage\ReleaseFileStore;
use PHPUnit\Framework\TestCase;

final class ReleaseServiceTest extends TestCase {

	private $releases;
	private $products;
	private $files;
	private $audit;
	private ReleaseService $svc;
	private string $tmp_zip;

	protected function setUp(): void {
		lk_test_reset_state();

		$this->releases = $this->createMock( ReleaseRepository::class );
		$this->products = $this->createMock( ProductRepository::class );
		$this->files    = $this->createMock( ReleaseFileStore::class );
		$this->audit    = $this->createMock( AuditLogger::class );

		$this->svc = new ReleaseService( $this->releases, $this->products, $this->files, $this->audit );

		$this->tmp_zip = sys_get_temp_dir() . '/lk-release-test.zip';
		file_put_contents( $this->tmp_zip, "PK\x03\x04" . str_repeat( "\x00", 100 ) );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->tmp_zip ) ) {
			unlink( $this->tmp_zip );
		}
	}

	public function test_create_rejects_missing_product(): void {
		$this->products->method( 'find' )->willReturn( null );

		$result = $this->svc->create( [
			'product_id'  => 99,
			'version'     => '1.0.0',
			'source_path' => $this->tmp_zip,
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ReleaseService::ERR_PRODUCT_NOT_FOUND, $result['error'] );
	}

	public function test_create_rejects_invalid_version(): void {
		$product = $this->build_product();
		$this->products->method( 'find' )->willReturn( $product );

		$result = $this->svc->create( [
			'product_id'  => 1,
			'version'     => 'banana',
			'source_path' => $this->tmp_zip,
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ReleaseService::ERR_INVALID_VERSION, $result['error'] );
	}

	public function test_create_rejects_invalid_channel(): void {
		$this->products->method( 'find' )->willReturn( $this->build_product() );

		$result = $this->svc->create( [
			'product_id'  => 1,
			'version'     => '1.0.0',
			'channel'     => 'imaginary',
			'source_path' => $this->tmp_zip,
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ReleaseService::ERR_INVALID_CHANNEL, $result['error'] );
	}

	public function test_create_rejects_duplicate_version(): void {
		$this->products->method( 'find' )->willReturn( $this->build_product() );
		$existing = new Release();
		$this->releases->method( 'find_by_product_and_version' )->willReturn( $existing );

		$result = $this->svc->create( [
			'product_id'  => 1,
			'version'     => '1.0.0',
			'source_path' => $this->tmp_zip,
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ReleaseService::ERR_DUPLICATE_VERSION, $result['error'] );
	}

	public function test_create_rejects_non_zip_source(): void {
		$this->products->method( 'find' )->willReturn( $this->build_product() );
		$this->releases->method( 'find_by_product_and_version' )->willReturn( null );

		$bad = sys_get_temp_dir() . '/lk-not-a-zip.txt';
		file_put_contents( $bad, "this is plain text, not a zip" );

		$result = $this->svc->create( [
			'product_id'  => 1,
			'version'     => '1.0.0',
			'source_path' => $bad,
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( ReleaseService::ERR_INVALID_FILE, $result['error'] );

		unlink( $bad );
	}

	public function test_create_succeeds_and_promotes_when_stable(): void {
		$product = $this->build_product();
		$product->current_version = '0.9.0';
		$this->products->method( 'find' )->willReturn( $product );
		$this->releases->method( 'find_by_product_and_version' )->willReturn( null );

		$this->files->method( 'store' )->willReturn( [
			'success'       => true,
			'relative_path' => 'acme/acme-1.0.0.zip',
			'absolute_path' => '/abs/acme-1.0.0.zip',
			'size'          => 100,
			'sha256'        => str_repeat( 'a', 64 ),
		] );

		$this->releases->expects( $this->once() )->method( 'insert' )->willReturn( 7 );

		// Newer version should trigger product update (promotion).
		$this->products->expects( $this->once() )->method( 'update' );

		$result = $this->svc->create( [
			'product_id'  => 1,
			'version'     => '1.0.0',
			'channel'     => 'stable',
			'source_path' => $this->tmp_zip,
			'changelog_md' => '* fixed it',
		] );

		$this->assertTrue( $result['success'] );
		$this->assertInstanceOf( Release::class, $result['release'] );
		$this->assertSame( 7, $result['release']->id );
	}

	public function test_create_does_not_promote_for_beta(): void {
		$product = $this->build_product();
		$product->current_version = '0.9.0';
		$this->products->method( 'find' )->willReturn( $product );
		$this->releases->method( 'find_by_product_and_version' )->willReturn( null );

		$this->files->method( 'store' )->willReturn( [
			'success'       => true,
			'relative_path' => 'acme/acme-1.0.0-beta.zip',
			'absolute_path' => '/abs',
			'size'          => 100,
			'sha256'        => str_repeat( 'b', 64 ),
		] );
		$this->releases->method( 'insert' )->willReturn( 8 );

		// Beta — product update should NOT fire.
		$this->products->expects( $this->never() )->method( 'update' );

		$this->svc->create( [
			'product_id'  => 1,
			'version'     => '1.0.0-beta',
			'channel'     => 'beta',
			'source_path' => $this->tmp_zip,
		] );
	}

	public function test_create_cleans_up_file_when_db_insert_fails(): void {
		$this->products->method( 'find' )->willReturn( $this->build_product() );
		$this->releases->method( 'find_by_product_and_version' )->willReturn( null );

		$this->files->method( 'store' )->willReturn( [
			'success'       => true,
			'relative_path' => 'acme/acme-1.0.0.zip',
			'absolute_path' => '/abs',
			'size'          => 100,
			'sha256'        => str_repeat( 'c', 64 ),
		] );
		$this->releases->method( 'insert' )->willReturn( 0 );

		// On insert failure, the orphaned file must be deleted.
		$this->files->expects( $this->once() )->method( 'delete' )->with( 'acme/acme-1.0.0.zip' );

		$result = $this->svc->create( [
			'product_id'  => 1,
			'version'     => '1.0.0',
			'source_path' => $this->tmp_zip,
		] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'db_error', $result['error'] );
	}

	public function test_set_channel_rejects_invalid(): void {
		$result = $this->svc->set_channel( 1, 'invalid' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( ReleaseService::ERR_INVALID_CHANNEL, $result['error'] );
	}

	private function build_product(): Product {
		$p       = new Product();
		$p->id   = 1;
		$p->slug = 'acme';
		$p->name = 'Acme';
		$p->type = 'plugin';
		return $p;
	}
}
