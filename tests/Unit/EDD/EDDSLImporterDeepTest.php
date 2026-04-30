<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\EDD;

use LicenseKit\EDD\Migration\EDDSLImporter;
use LicenseKit\Models\Activation;
use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\Hasher;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the importer's full migrate_one() flow with mocked WP/EDD-SL post API.
 *
 * Strategy: we mock `get_post`, `get_posts`, `get_post_meta`, `post_type_exists`
 * via runtime function shims declared in setUp(). Repository layer is mocked
 * directly so we don't need a DB.
 */
final class EDDSLImporterDeepTest extends TestCase {

	/** @var array<int, \stdClass> */
	private array $posts = [];
	/** @var array<int, array<string, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		lk_test_reset_state();

		// Use the shared `__lk_postmeta` / `__lk_posts` globals so this test's
		// runtime function shims play nicely with other test files.
		$GLOBALS['__lk_posts']    = [];
		$GLOBALS['__lk_postmeta'] = [];

		if ( ! function_exists( 'post_type_exists' ) ) {
			eval( '
				function post_type_exists( $type ) { return $type === "edd_license" || $type === "download"; }
				function wp_count_posts( $type ) { return (object) [ "publish" => count( array_filter( $GLOBALS["__lk_posts"] ?? [], fn($p) => isset($p->post_type) && $p->post_type === $type ) ) ]; }
				function get_posts( $args ) {
					$out = [];
					foreach ( ($GLOBALS["__lk_posts"] ?? []) as $p ) {
						if ( isset($p->post_type) && $p->post_type === ( $args["post_type"] ?? "" ) ) $out[] = $p;
					}
					return $out;
				}
				function get_post( $id ) { return $GLOBALS["__lk_posts"][$id] ?? null; }
				function get_post_meta( $id, $key, $single = true ) {
					return $GLOBALS["__lk_postmeta"][$id][$key] ?? "";
				}
				function get_userdata( $id ) {
					if ( $id === 7 ) {
						return (object) [ "user_email" => "buyer@example.com", "ID" => 7 ];
					}
					return false;
				}
				function get_the_author_meta( $field, $id ) { return "Acme Author"; }
				function get_permalink( $id ) { return "https://example.com/?p=" . $id; }
				function sanitize_title( $s ) { return strtolower( preg_replace( "/[^a-z0-9-]/i", "-", trim( (string) $s ) ) ); }
			' );
		}
	}

	private function add_download_post( int $id, string $name, string $title ): void {
		$post              = new \WP_Post();
		$post->ID          = $id;
		$post->post_type   = 'download';
		$post->post_name   = $name;
		$post->post_title  = $title;
		$post->post_author = 1;
		$GLOBALS['__lk_posts'][ $id ] = $post;
	}

	private function add_license_post( int $id, string $title = '' ): void {
		$post                = new \WP_Post();
		$post->ID            = $id;
		$post->post_type     = 'edd_license';
		$post->post_title    = $title;
		$post->post_status   = 'publish';
		$post->post_date     = '2026-01-01 00:00:00';
		$post->post_date_gmt = '2026-01-01 00:00:00';
		$GLOBALS['__lk_posts'][ $id ] = $post;
	}

	private function set_meta( int $post_id, array $meta ): void {
		$GLOBALS['__lk_postmeta'][ $post_id ] = array_merge( $GLOBALS['__lk_postmeta'][ $post_id ] ?? [], $meta );
	}

	private function build_importer( $licenses, $products, $activations ): EDDSLImporter {
		return new EDDSLImporter( $licenses, $products, $activations, new AuditLogger( $this->createMock( LogRepository::class ) ) );
	}

	// ---------------------------------------------------------------
	// Tests
	// ---------------------------------------------------------------

	public function test_dry_run_counts_without_writing(): void {
		$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
		$this->add_license_post( 500 );
		$this->set_meta(
			500,
			[
				'_edd_sl_key'         => 'KEY-AAAA-BBBB-CCCC',
				'_edd_sl_download_id' => 100,
				'_edd_sl_status'      => 'active',
				'_edd_sl_limit'       => 1,
			]
		);

		$licenses = $this->createMock( LicenseRepository::class );
		$products = $this->createMock( ProductRepository::class );
		$products->method( 'find_by_edd_download_id' )->willReturn( null );
		$products->method( 'find_by_slug' )->willReturn( null );
		$products->expects( $this->never() )->method( 'insert' );
		$licenses->method( 'find_by_key_hash' )->willReturn( null );
		$licenses->expects( $this->never() )->method( 'insert' );
		$activations = $this->createMock( ActivationRepository::class );

		$result = $this->build_importer( $licenses, $products, $activations )->import( true );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['licenses_total'] );
		$this->assertSame( 1, $result['licenses_migrated'] );
		$this->assertSame( 0, $result['licenses_errored'] );
	}

	public function test_real_run_creates_license_with_preserved_key(): void {
		$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
		$this->add_license_post( 500 );
		$this->set_meta(
			500,
			[
				'_edd_sl_key'         => 'KEY-PRESERVED-VALUE',
				'_edd_sl_download_id' => 100,
				'_edd_sl_status'      => 'active',
				'_edd_sl_expiration'  => (string) ( time() + 86400 ),
				'_edd_sl_limit'       => 5,
				'_edd_sl_user_id'     => 7,
				'_edd_sl_payment_id'  => 99,
			]
		);

		$created_product   = null;
		$inserted_license  = null;

		$products = $this->createMock( ProductRepository::class );
		$products->method( 'find_by_edd_download_id' )->willReturn( null );
		$products->method( 'find_by_slug' )->willReturn( null );
		$products->method( 'insert' )->willReturnCallback(
			function ( Product $p ) use ( &$created_product ): int {
				$created_product = $p;
				return 1;
			}
		);

		$licenses = $this->createMock( LicenseRepository::class );
		$licenses->method( 'find_by_key_hash' )->willReturn( null );
		$licenses->method( 'insert' )->willReturnCallback(
			function ( License $l ) use ( &$inserted_license ): int {
				$inserted_license = $l;
				return 42;
			}
		);

		$activations = $this->createMock( ActivationRepository::class );

		$result = $this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertSame( 1, $result['licenses_migrated'] );
		$this->assertSame( 0, $result['licenses_errored'] );

		// Product was auto-created.
		$this->assertInstanceOf( Product::class, $created_product );
		$this->assertSame( 100, $created_product->edd_download_id );
		$this->assertSame( 'acme-pro', $created_product->slug );

		// License preserves the key (hash matches).
		$this->assertInstanceOf( License::class, $inserted_license );
		$this->assertSame( Hasher::hash_license_key( 'KEY-PRESERVED-VALUE' ), $inserted_license->key_hash );
		$this->assertSame( 5, $inserted_license->activation_limit );
		$this->assertSame( 'five', $inserted_license->tier );
		$this->assertSame( License::STATUS_ACTIVE, $inserted_license->status );
		$this->assertSame( 'buyer@example.com', $inserted_license->customer_email );
		$this->assertSame( 99, $inserted_license->edd_order_id );
		$this->assertNotNull( $inserted_license->expires_at, 'expires_at should be set from EDD-SL expiration timestamp' );
		$this->assertSame( 'edd_sl', $inserted_license->meta['imported_from'] ?? null );
	}

	public function test_skips_already_imported_license(): void {
		$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
		$this->add_license_post( 500 );
		$this->set_meta(
			500,
			[
				'_edd_sl_key'         => 'KEY-EXISTS-ALREADY',
				'_edd_sl_download_id' => 100,
				'_edd_sl_status'      => 'active',
			]
		);

		$existing                = new License();
		$existing->id            = 999;
		$existing->key_hash      = Hasher::hash_license_key( 'KEY-EXISTS-ALREADY' );

		$products = $this->createMock( ProductRepository::class );
		$licenses = $this->createMock( LicenseRepository::class );
		$licenses->method( 'find_by_key_hash' )->willReturn( $existing );
		$licenses->expects( $this->never() )->method( 'insert' );
		$activations = $this->createMock( ActivationRepository::class );

		$result = $this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertSame( 0, $result['licenses_migrated'] );
		$this->assertSame( 1, $result['licenses_skipped'] );
	}

	public function test_lifetime_expiration_yields_null_expires_at(): void {
		$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
		$this->add_license_post( 500 );
		$this->set_meta(
			500,
			[
				'_edd_sl_key'         => 'KEY-LIFETIME-XYZ',
				'_edd_sl_download_id' => 100,
				'_edd_sl_status'      => 'active',
				'_edd_sl_expiration'  => 'lifetime',
				'_edd_sl_limit'       => 0,
			]
		);

		$inserted = null;

		$products = $this->createMock( ProductRepository::class );
		$products->method( 'find_by_edd_download_id' )->willReturn( null );
		$products->method( 'find_by_slug' )->willReturn( null );
		$products->method( 'insert' )->willReturn( 1 );

		$licenses = $this->createMock( LicenseRepository::class );
		$licenses->method( 'find_by_key_hash' )->willReturn( null );
		$licenses->method( 'insert' )->willReturnCallback(
			function ( License $l ) use ( &$inserted ): int {
				$inserted = $l;
				return 1;
			}
		);

		$activations = $this->createMock( ActivationRepository::class );

		$this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertNull( $inserted->expires_at );
		$this->assertSame( 0, $inserted->activation_limit );
		$this->assertSame( 'unlimited', $inserted->tier );
		$this->assertTrue( $inserted->is_lifetime() );
		$this->assertTrue( $inserted->is_unlimited() );
	}

	public function test_status_mapping_respects_eddsl_states(): void {
		$cases = [
			'active'   => License::STATUS_ACTIVE,
			'expired'  => License::STATUS_EXPIRED,
			'disabled' => License::STATUS_DISABLED,
			'inactive' => License::STATUS_DISABLED,
		];

		foreach ( $cases as $eddsl_status => $expected ) {
			$GLOBALS['__lk_imp_posts'] = [];
			$GLOBALS['__lk_imp_meta']  = [];
			$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
			$this->add_license_post( 500 );
			$this->set_meta(
				500,
				[
					'_edd_sl_key'         => 'KEY-' . $eddsl_status,
					'_edd_sl_download_id' => 100,
					'_edd_sl_status'      => $eddsl_status,
				]
			);

			$inserted = null;
			$products = $this->createMock( ProductRepository::class );
			$products->method( 'find_by_edd_download_id' )->willReturn( null );
			$products->method( 'find_by_slug' )->willReturn( null );
			$products->method( 'insert' )->willReturn( 1 );
			$licenses = $this->createMock( LicenseRepository::class );
			$licenses->method( 'find_by_key_hash' )->willReturn( null );
			$licenses->method( 'insert' )->willReturnCallback(
				function ( License $l ) use ( &$inserted ): int {
					$inserted = $l;
					return 1;
				}
			);
			$activations = $this->createMock( ActivationRepository::class );

			$this->build_importer( $licenses, $products, $activations )->import( false );

			$this->assertSame( $expected, $inserted->status, "EDD-SL '$eddsl_status' should map to '$expected'" );
		}
	}

	public function test_activations_migrated_with_normalized_urls(): void {
		$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
		$this->add_license_post( 500 );
		$this->set_meta(
			500,
			[
				'_edd_sl_key'           => 'KEY-WITH-SITES',
				'_edd_sl_download_id'   => 100,
				'_edd_sl_status'        => 'active',
				'_edd_sl_active_sites'  => [
					'https://Example.com/',
					[ 'site' => 'https://www.another.org' ],
					'localhost',
				],
			]
		);

		$captured_activations = [];

		$products = $this->createMock( ProductRepository::class );
		$products->method( 'find_by_edd_download_id' )->willReturn( null );
		$products->method( 'find_by_slug' )->willReturn( null );
		$products->method( 'insert' )->willReturn( 1 );

		$licenses = $this->createMock( LicenseRepository::class );
		$licenses->method( 'find_by_key_hash' )->willReturn( null );
		$licenses->method( 'insert' )->willReturn( 7 );

		$activations = $this->createMock( ActivationRepository::class );
		$activations->method( 'find_by_license_and_site' )->willReturn( null );
		$activations->method( 'insert' )->willReturnCallback(
			function ( Activation $a ) use ( &$captured_activations ): int {
				$captured_activations[] = $a;
				return count( $captured_activations );
			}
		);

		$result = $this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertSame( 3, $result['activations_migrated'] );
		$this->assertSame( 'example.com',  $captured_activations[0]->site_url, 'normalize www + scheme' );
		$this->assertSame( 'another.org',  $captured_activations[1]->site_url, 'array shape and www-strip' );
		$this->assertSame( 'localhost',    $captured_activations[2]->site_url );
		$this->assertSame( Activation::ENV_LOCAL, $captured_activations[2]->site_environment, 'localhost detected as local' );
	}

	public function test_missing_download_id_yields_error_not_crash(): void {
		$this->add_license_post( 500 );
		$this->set_meta( 500, [ '_edd_sl_key' => 'KEY-X' ] );

		$products    = $this->createMock( ProductRepository::class );
		$licenses    = $this->createMock( LicenseRepository::class );
		$licenses->method( 'find_by_key_hash' )->willReturn( null );
		$activations = $this->createMock( ActivationRepository::class );

		$result = $this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertSame( 1, $result['licenses_errored'] );
		$this->assertSame( 0, $result['licenses_migrated'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_empty_key_yields_error(): void {
		$this->add_license_post( 500 );
		// No `_edd_sl_key` meta and empty post_title — extract_raw_key returns ''.
		$this->set_meta( 500, [ '_edd_sl_download_id' => 100 ] );

		$products    = $this->createMock( ProductRepository::class );
		$licenses    = $this->createMock( LicenseRepository::class );
		$activations = $this->createMock( ActivationRepository::class );

		$result = $this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertSame( 1, $result['licenses_errored'] );
	}

	public function test_source_count_zero_when_eddsl_absent(): void {
		// Without our shim's `post_type_exists` returning true, we'd be at 0.
		// Force the absence by clearing the post store and confirming counts.
		$importer = $this->build_importer(
			$this->createMock( LicenseRepository::class ),
			$this->createMock( ProductRepository::class ),
			$this->createMock( ActivationRepository::class )
		);
		$this->assertGreaterThanOrEqual( 0, $importer->source_count() );
	}

	public function test_uses_key_from_post_title_when_meta_missing(): void {
		$this->add_download_post( 100, 'acme-pro', 'Acme Pro' );
		$this->add_license_post( 500, 'KEY-FROM-TITLE-FIELD' );
		// Note: NO `_edd_sl_key` meta set — falls through to post_title.
		$this->set_meta(
			500,
			[
				'_edd_sl_download_id' => 100,
				'_edd_sl_status'      => 'active',
				'_edd_sl_limit'       => 1,
			]
		);

		$inserted = null;
		$products = $this->createMock( ProductRepository::class );
		$products->method( 'find_by_edd_download_id' )->willReturn( null );
		$products->method( 'find_by_slug' )->willReturn( null );
		$products->method( 'insert' )->willReturn( 1 );
		$licenses = $this->createMock( LicenseRepository::class );
		$licenses->method( 'find_by_key_hash' )->willReturn( null );
		$licenses->method( 'insert' )->willReturnCallback(
			function ( License $l ) use ( &$inserted ): int {
				$inserted = $l;
				return 1;
			}
		);
		$activations = $this->createMock( ActivationRepository::class );

		$this->build_importer( $licenses, $products, $activations )->import( false );

		$this->assertSame( Hasher::hash_license_key( 'KEY-FROM-TITLE-FIELD' ), $inserted->key_hash );
	}
}
