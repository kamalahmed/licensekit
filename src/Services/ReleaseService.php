<?php
/**
 * Release management — upload zips, create release rows, promote/demote channels,
 * and keep `dlm_products.current_version` / `current_release_id` pointing at the
 * latest stable release.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Models\Product;
use LicenseKit\Models\Release;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\Storage\ReleaseFileStore;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class ReleaseService {

	public const CHANNEL_STABLE = 'stable';
	public const CHANNEL_BETA   = 'beta';
	public const CHANNEL_RC     = 'rc';

	public const ERR_PRODUCT_NOT_FOUND = 'product_not_found';
	public const ERR_INVALID_VERSION   = 'invalid_version';
	public const ERR_DUPLICATE_VERSION = 'duplicate_version';
	public const ERR_INVALID_FILE      = 'invalid_file';
	public const ERR_STORAGE_FAILED    = 'storage_failed';
	public const ERR_INVALID_CHANNEL   = 'invalid_channel';

	private ReleaseRepository $releases;
	private ProductRepository $products;
	private ReleaseFileStore $files;
	private AuditLogger $audit;

	public function __construct(
		ReleaseRepository $releases,
		ProductRepository $products,
		ReleaseFileStore $files,
		AuditLogger $audit
	) {
		$this->releases = $releases;
		$this->products = $products;
		$this->files    = $files;
		$this->audit    = $audit;
	}

	/**
	 * Create a new release and ingest its zip.
	 *
	 * @param array $args {
	 *   @type int    $product_id      Required.
	 *   @type string $version         Required (e.g. `1.2.3`).
	 *   @type string $source_path     Required (filesystem path to zip).
	 *   @type string $channel         Default 'stable'.
	 *   @type string $changelog_md    Default ''.
	 *   @type string $requires_wp     Optional.
	 *   @type string $requires_php    Optional.
	 *   @type string $tested_up_to    Optional.
	 *   @type ?int   $created_by      WP user id, optional.
	 * }
	 * @return array{success:bool, release?:Release, error?:string, message?:string}
	 */
	public function create( array $args ): array {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		$product    = $product_id > 0 ? $this->products->find( $product_id ) : null;
		if ( ! $product instanceof Product ) {
			return $this->fail( self::ERR_PRODUCT_NOT_FOUND, __( 'Product not found.', 'licensekit' ) );
		}

		$version = trim( (string) ( $args['version'] ?? '' ) );
		if ( '' === $version || ! $this->is_valid_version( $version ) ) {
			return $this->fail( self::ERR_INVALID_VERSION, __( 'Version must look like 1.2.3 (semver).', 'licensekit' ) );
		}

		$channel = (string) ( $args['channel'] ?? self::CHANNEL_STABLE );
		if ( ! in_array( $channel, [ self::CHANNEL_STABLE, self::CHANNEL_BETA, self::CHANNEL_RC ], true ) ) {
			return $this->fail( self::ERR_INVALID_CHANNEL, __( 'Unknown release channel.', 'licensekit' ) );
		}

		// Reject duplicate versions for this product (DB enforces too via UNIQUE, but fail fast with a friendly error).
		if ( $this->releases->find_by_product_and_version( $product_id, $version ) instanceof Release ) {
			return $this->fail( self::ERR_DUPLICATE_VERSION, __( 'A release with this version already exists for this product.', 'licensekit' ) );
		}

		$source_path = (string) ( $args['source_path'] ?? '' );
		if ( '' === $source_path || ! is_readable( $source_path ) ) {
			return $this->fail( self::ERR_INVALID_FILE, __( 'Release file is missing or unreadable.', 'licensekit' ) );
		}
		if ( ! $this->looks_like_zip( $source_path ) ) {
			return $this->fail( self::ERR_INVALID_FILE, __( 'Release file is not a valid ZIP archive.', 'licensekit' ) );
		}

		$stored = $this->files->store( $product->slug, $version, $source_path );
		if ( ! $stored['success'] ) {
			return $this->fail( self::ERR_STORAGE_FAILED, __( 'Could not store release file.', 'licensekit' ) );
		}

		$now              = Helpers::now_utc();
		$release          = new Release();
		$release->product_id   = $product_id;
		$release->version      = $version;
		$release->channel      = $channel;
		$release->file_path    = $stored['relative_path'];
		$release->file_size    = $stored['size'];
		$release->file_hash    = $stored['sha256'];
		$release->signing_salt = $this->generate_salt();
		$release->changelog_md = (string) ( $args['changelog_md'] ?? '' );
		$release->requires_wp  = $this->trim_or_null( $args['requires_wp'] ?? null );
		$release->requires_php = $this->trim_or_null( $args['requires_php'] ?? null );
		$release->tested_up_to = $this->trim_or_null( $args['tested_up_to'] ?? null );
		$release->released_at  = $now;
		$release->created_at   = $now;
		$release->created_by   = isset( $args['created_by'] ) ? (int) $args['created_by'] : null;

		$id = $this->releases->insert( $release );
		if ( $id <= 0 ) {
			$this->files->delete( $stored['relative_path'] );
			return $this->fail( 'db_error', __( 'Could not create release row.', 'licensekit' ) );
		}
		$release->id = $id;

		// If this is the latest stable release, update the product's pointer.
		if ( self::CHANNEL_STABLE === $channel ) {
			$this->maybe_promote_to_current( $product, $release );
		}

		$this->audit->record(
			'release.created',
			[
				'product_slug' => $product->slug,
				'version'      => $version,
				'channel'      => $channel,
				'file_size'    => $stored['size'],
				'sha256'       => $stored['sha256'],
			],
			'release',
			$id
		);

		do_action( 'licensekit_release_created', $release, $product );

		return [ 'success' => true, 'release' => $release ];
	}

	public function delete_release( int $release_id ): array {
		$release = $this->releases->find( $release_id );
		if ( ! $release instanceof Release ) {
			return $this->fail( 'not_found', __( 'Release not found.', 'licensekit' ) );
		}

		if ( null !== $release->file_path ) {
			$this->files->delete( $release->file_path );
		}
		$this->releases->delete( $release_id );

		// If this was the product's current release, recompute pointer.
		$product = $this->products->find( $release->product_id );
		if ( $product instanceof Product && $product->current_release_id === $release_id ) {
			$latest = $this->releases->find_latest_for_product( $release->product_id, self::CHANNEL_STABLE );
			$this->products->update(
				$release->product_id,
				[
					'current_release_id' => $latest instanceof Release ? $latest->id : null,
					'current_version'    => $latest instanceof Release ? $latest->version : null,
					'updated_at'         => Helpers::now_utc(),
				]
			);
		}

		$this->audit->record( 'release.deleted', [ 'version' => $release->version ], 'release', $release_id );
		do_action( 'licensekit_release_deleted', $release );

		return [ 'success' => true ];
	}

	public function set_channel( int $release_id, string $channel ): array {
		if ( ! in_array( $channel, [ self::CHANNEL_STABLE, self::CHANNEL_BETA, self::CHANNEL_RC ], true ) ) {
			return $this->fail( self::ERR_INVALID_CHANNEL, __( 'Unknown release channel.', 'licensekit' ) );
		}
		$release = $this->releases->find( $release_id );
		if ( ! $release instanceof Release ) {
			return $this->fail( 'not_found', __( 'Release not found.', 'licensekit' ) );
		}

		$this->releases->update( $release_id, [ 'channel' => $channel ] );

		// Recalculate current_release for the product if channel just became stable / left stable.
		$product = $this->products->find( $release->product_id );
		if ( $product instanceof Product ) {
			$latest = $this->releases->find_latest_for_product( $release->product_id, self::CHANNEL_STABLE );
			$this->products->update(
				$release->product_id,
				[
					'current_release_id' => $latest instanceof Release ? $latest->id : null,
					'current_version'    => $latest instanceof Release ? $latest->version : null,
					'updated_at'         => Helpers::now_utc(),
				]
			);
		}

		$this->audit->record(
			'release.channel_changed',
			[ 'from' => $release->channel, 'to' => $channel ],
			'release',
			$release_id
		);

		return [ 'success' => true ];
	}

	private function maybe_promote_to_current( Product $product, Release $candidate ): void {
		// Only promote if candidate is newer (semver) than the existing pointer.
		$current_version = (string) ( $product->current_version ?? '' );
		if ( '' === $current_version || version_compare( $candidate->version, $current_version, '>' ) ) {
			$this->products->update(
				(int) $product->id,
				[
					'current_release_id' => $candidate->id,
					'current_version'    => $candidate->version,
					'updated_at'         => Helpers::now_utc(),
				]
			);
		}
	}

	private function is_valid_version( string $version ): bool {
		// Lenient semver — allow `1.2`, `1.2.3`, `1.2.3-beta.1`, etc. Reject strings that aren't dotted-numeric-leading.
		return (bool) preg_match( '/^\d+(\.\d+){0,2}([\-+][0-9A-Za-z.\-]+)?$/', $version );
	}

	private function looks_like_zip( string $path ): bool {
		// 4-byte magic-number sniff. file_get_contents with offset+length avoids
		// loading the whole file. WP_Filesystem doesn't expose partial reads,
		// so a direct PHP call is appropriate here.
		$header = @file_get_contents( $path, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
		// PK\x03\x04 = local file header, PK\x05\x06 = empty archive end record.
		return "PK\x03\x04" === $header || "PK\x05\x06" === $header;
	}

	private function generate_salt(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function trim_or_null( $val ): ?string {
		if ( ! is_string( $val ) ) {
			return null;
		}
		$val = trim( $val );
		return '' === $val ? null : $val;
	}

	private function fail( string $error_code, string $message ): array {
		return [
			'success' => false,
			'error'   => $error_code,
			'message' => $message,
		];
	}
}
