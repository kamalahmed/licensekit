<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\Storage;

use LicenseKit\Storage\ReleaseFileStore;
use PHPUnit\Framework\TestCase;

final class ReleaseFileStoreTest extends TestCase {

	private string $root;
	private ReleaseFileStore $store;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/lk-tests';
		if ( is_dir( $this->root ) ) {
			$this->rrmdir( $this->root );
		}
		mkdir( $this->root . '/uploads', 0755, true );
		$this->store = new ReleaseFileStore();
	}

	protected function tearDown(): void {
		if ( is_dir( $this->root ) ) {
			$this->rrmdir( $this->root );
		}
	}

	public function test_ensure_directory_writes_guards(): void {
		$dir = $this->store->ensure_directory();
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/web.config' );
		$this->assertFileExists( $dir . '/index.php' );

		$htaccess = file_get_contents( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );
	}

	public function test_traversal_rejection(): void {
		$this->assertNull( $this->store->absolute_path( '' ) );
		$this->assertNull( $this->store->absolute_path( '../../etc/passwd' ) );
		$this->assertNull( $this->store->absolute_path( '/absolute/path' ) );
	}

	public function test_valid_path_resolves_under_base(): void {
		$this->store->ensure_directory();
		$resolved = $this->store->absolute_path( 'myslug/myslug-1.0.0.zip' );
		$this->assertNotNull( $resolved );
		$real_base = realpath( $this->store->base_dir() );
		$this->assertNotFalse( $real_base );
		$this->assertStringStartsWith( (string) $real_base, $resolved );
	}

	public function test_store_a_zip(): void {
		$source = sys_get_temp_dir() . '/lk-fake.zip';
		file_put_contents( $source, "PK\x03\x04" . str_repeat( "\x00", 100 ) );

		$result = $this->store->store( 'my-slug', '1.2.3', $source );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'my-slug/my-slug-1.2.3.zip', $result['relative_path'] );
		$this->assertSame( 64, strlen( $result['sha256'] ) );
		$this->assertGreaterThan( 0, $result['size'] );
		$this->assertFileExists( $result['absolute_path'] );

		unlink( $source );
	}

	public function test_evil_slug_sanitized(): void {
		$source = sys_get_temp_dir() . '/lk-fake.zip';
		file_put_contents( $source, "PK\x03\x04" . str_repeat( "\x00", 100 ) );

		$result = $this->store->store( '../evil/slug', '1.0', $source );

		$this->assertTrue( $result['success'] );
		$this->assertStringNotContainsString( '..', $result['relative_path'] );

		unlink( $source );
	}

	public function test_pure_traversal_slug_rejected(): void {
		$source = sys_get_temp_dir() . '/lk-fake.zip';
		file_put_contents( $source, "PK\x03\x04" . str_repeat( "\x00", 100 ) );

		$result = $this->store->store( '../', '1.0', $source );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'invalid_slug_or_version', $result['error'] );

		unlink( $source );
	}

	public function test_unreadable_source(): void {
		$result = $this->store->store( 's', '1.0', '/nonexistent/file.zip' );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'source_unreadable', $result['error'] );
	}

	public function test_delete(): void {
		$source = sys_get_temp_dir() . '/lk-fake.zip';
		file_put_contents( $source, "PK\x03\x04" );
		$result = $this->store->store( 'sl', '1.0', $source );
		$this->assertTrue( $this->store->delete( $result['relative_path'] ) );
		$this->assertFileDoesNotExist( $result['absolute_path'] );

		// Delete-noop on missing path returns true.
		$this->assertTrue( $this->store->delete( 'sl/nonexistent.zip' ) );
		unlink( $source );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
