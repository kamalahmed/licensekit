<?php
/**
 * One-shot importer from EDD Software Licensing → LicenseKit.
 *
 * EDD-SL stores each license as an `edd_license` custom post; this importer
 * walks every license post and creates an equivalent LicenseKit row that
 * preserves the **same raw key**, so customers never need to re-paste their
 * key into the SDK after migration.
 *
 * Idempotent: a second run skips licenses that already exist (matched by
 * peppered hash of the raw key). Supports dry-run mode for safety.
 *
 * Limitations of v1:
 *   - Variable-priced downloads with per-price-id tier overrides collapse to
 *     the parent download's tier.
 *   - Renewal-chain (`_edd_sl_parent_license`) is preserved as `parent_license_id`.
 *   - EDD-SL "site activations" stored under per-license meta are migrated as
 *     LicenseKit `dlm_activations` rows. Site URL normalization is applied so
 *     activation hashes match what the SDK will compute next time it pings.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\EDD\Migration;

use LicenseKit\Models\Activation;
use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\EncryptedKey;
use LicenseKit\Services\Hasher;
use LicenseKit\Services\KeyGenerator;
use LicenseKit\Services\SiteNormalizer;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class EDDSLImporter {

	public const STATUS_MAP = [
		'active'   => License::STATUS_ACTIVE,
		'inactive' => License::STATUS_DISABLED,
		'expired'  => License::STATUS_EXPIRED,
		'disabled' => License::STATUS_DISABLED,
		'private'  => License::STATUS_DISABLED,
		'draft'    => License::STATUS_PENDING,
	];

	private LicenseRepository $licenses;
	private ProductRepository $products;
	private ActivationRepository $activations;
	private AuditLogger $audit;

	public function __construct(
		LicenseRepository $licenses,
		ProductRepository $products,
		ActivationRepository $activations,
		AuditLogger $audit
	) {
		$this->licenses    = $licenses;
		$this->products    = $products;
		$this->activations = $activations;
		$this->audit       = $audit;
	}

	public static function make(): self {
		return new self(
			new LicenseRepository(),
			new ProductRepository(),
			new ActivationRepository(),
			new AuditLogger( new \LicenseKit\Repositories\LogRepository() )
		);
	}

	public function is_edd_sl_present(): bool {
		return post_type_exists( 'edd_license' ) || class_exists( 'EDD_Software_Licensing' );
	}

	/**
	 * Total licenses available to migrate.
	 */
	public function source_count(): int {
		if ( ! $this->is_edd_sl_present() ) {
			return 0;
		}
		$counts = wp_count_posts( 'edd_license' );
		if ( ! $counts ) {
			return 0;
		}
		return (int) array_sum( (array) $counts );
	}

	/**
	 * @return array{success:bool, licenses_total:int, licenses_migrated:int, licenses_skipped:int, licenses_errored:int, activations_migrated:int, errors:array<int,string>, dry_run:bool}
	 */
	public function import( bool $dry_run = false, int $limit = 1000 ): array {
		$result = [
			'success'              => true,
			'licenses_total'       => 0,
			'licenses_migrated'    => 0,
			'licenses_skipped'     => 0,
			'licenses_errored'     => 0,
			'activations_migrated' => 0,
			'errors'               => [],
			'dry_run'              => $dry_run,
		];

		if ( ! $this->is_edd_sl_present() ) {
			$result['success']  = false;
			$result['errors'][] = __( 'EDD Software Licensing is not installed on this site.', 'licensekit' );
			return $result;
		}

		$posts = get_posts(
			[
				'post_type'        => 'edd_license',
				'post_status'      => 'any',
				'posts_per_page'   => $limit,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			]
		);

		$result['licenses_total'] = count( $posts );

		foreach ( $posts as $post ) {
			try {
				$outcome = $this->migrate_one( $post, $dry_run );
				if ( 'migrated' === $outcome['status'] ) {
					++$result['licenses_migrated'];
					$result['activations_migrated'] += (int) $outcome['activations'];
				} elseif ( 'skipped' === $outcome['status'] ) {
					++$result['licenses_skipped'];
				}
			} catch ( \Throwable $e ) {
				++$result['licenses_errored'];
				$result['errors'][] = sprintf( '#%d: %s', $post->ID, $e->getMessage() );
			}
		}

		if ( ! $dry_run && $result['licenses_migrated'] > 0 ) {
			$this->audit->record(
				'migration.edd_sl',
				[
					'migrated'    => $result['licenses_migrated'],
					'skipped'     => $result['licenses_skipped'],
					'errored'     => $result['licenses_errored'],
					'activations' => $result['activations_migrated'],
				]
			);
		}

		return $result;
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	/**
	 * @return array{status:'migrated'|'skipped', activations:int}
	 */
	private function migrate_one( \WP_Post $post, bool $dry_run ): array {
		$raw_key = $this->extract_raw_key( $post );
		if ( '' === $raw_key ) {
			throw new \RuntimeException( 'no license key on post' );
		}

		$key_hash = Hasher::hash_license_key( $raw_key );

		// Idempotency: skip if a LicenseKit license with this key already exists.
		if ( $this->licenses->find_by_key_hash( $key_hash ) instanceof License ) {
			return [ 'status' => 'skipped', 'activations' => 0 ];
		}

		$download_id = (int) get_post_meta( $post->ID, '_edd_sl_download_id', true );
		if ( $download_id <= 0 ) {
			throw new \RuntimeException( 'no download id linked' );
		}

		// Dry run: count what we would migrate without writing anything.
		// Only confirm the source download exists; don't create the product yet.
		if ( $dry_run ) {
			$existing = $this->products->find_by_edd_download_id( $download_id );
			if ( ! $existing instanceof Product && null === get_post( $download_id ) ) {
				throw new \RuntimeException( 'download post missing' );
			}
			return [ 'status' => 'migrated', 'activations' => $this->count_edd_sl_activations( $post->ID ) ];
		}

		$product = $this->resolve_or_create_product( $download_id, $post );
		if ( ! $product instanceof Product ) {
			throw new \RuntimeException( 'product resolution failed' );
		}

		$status_raw   = (string) get_post_meta( $post->ID, '_edd_sl_status', true );
		$status       = self::STATUS_MAP[ $status_raw ] ?? License::STATUS_ACTIVE;
		$expiration   = (string) get_post_meta( $post->ID, '_edd_sl_expiration', true );
		$expires_at   = '' !== $expiration && 'lifetime' !== $expiration && is_numeric( $expiration )
			? gmdate( 'Y-m-d H:i:s', (int) $expiration )
			: null;
		$activation_limit = (int) get_post_meta( $post->ID, '_edd_sl_limit', true );
		$user_id        = (int) get_post_meta( $post->ID, '_edd_sl_user_id', true );
		$payment_id     = (int) get_post_meta( $post->ID, '_edd_sl_payment_id', true );
		$parent_post_id = (int) get_post_meta( $post->ID, '_edd_sl_parent_license', true );
		$customer_email = $this->lookup_customer_email( $user_id, $payment_id );
		$customer_id    = $this->lookup_edd_customer_id( $user_id, $payment_id );

		$license                      = new License();
		$license->key_hash            = $key_hash;
		$license->key_prefix          = KeyGenerator::prefix_of( $raw_key );
		$license->key_encrypted       = EncryptedKey::encrypt( $raw_key );
		$license->customer_id         = $customer_id;
		$license->customer_email      = $customer_email;
		$license->product_id          = (int) $product->id;
		$license->edd_order_id        = $payment_id > 0 ? $payment_id : null;
		$license->edd_price_id        = $this->lookup_price_id( $post->ID );
		$license->tier                = $this->infer_tier( $activation_limit );
		$license->activation_limit    = $activation_limit;
		$license->status              = $status;
		$license->issued_at           = $post->post_date_gmt ?: $post->post_date;
		$license->expires_at          = $expires_at;
		$license->parent_license_id   = $this->resolve_parent_license_id( $parent_post_id );
		$license->meta                = [
			'imported_from'    => 'edd_sl',
			'edd_sl_post_id'   => (int) $post->ID,
			'edd_sl_status'    => $status_raw,
		];
		$license->created_at          = Helpers::now_utc();
		$license->updated_at          = Helpers::now_utc();

		$license_id = $this->licenses->insert( $license );
		if ( $license_id <= 0 ) {
			throw new \RuntimeException( 'license insert failed' );
		}

		$activation_count = $this->migrate_activations( $post->ID, $license_id );

		return [ 'status' => 'migrated', 'activations' => $activation_count ];
	}

	/**
	 * EDD-SL stored the raw key in either `post_title` or the `_edd_sl_key` meta;
	 * different versions of EDD-SL pick different conventions. Try both.
	 */
	private function extract_raw_key( \WP_Post $post ): string {
		$from_meta = (string) get_post_meta( $post->ID, '_edd_sl_key', true );
		if ( '' !== $from_meta ) {
			return trim( $from_meta );
		}
		return trim( (string) $post->post_title );
	}

	private function resolve_or_create_product( int $edd_download_id, \WP_Post $license_post ): ?Product {
		$existing = $this->products->find_by_edd_download_id( $edd_download_id );
		if ( $existing instanceof Product ) {
			return $existing;
		}

		$dl_post = get_post( $edd_download_id );
		if ( ! $dl_post ) {
			return null;
		}

		$product                  = new Product();
		$product->edd_download_id = $edd_download_id;
		$product->slug            = (string) ( $dl_post->post_name ?: ( 'edd-' . $edd_download_id ) );
		// Avoid slug collisions if the user manually created a product with the same slug.
		if ( $this->products->find_by_slug( $product->slug ) instanceof Product ) {
			$product->slug .= '-edd-' . $edd_download_id;
		}
		$product->name           = (string) $dl_post->post_title;
		$product->type           = 'plugin';
		$product->author         = (string) get_the_author_meta( 'display_name', (int) $dl_post->post_author );
		$product->homepage_url   = (string) get_permalink( $edd_download_id );
		$product->meta           = [];
		$product->created_at     = Helpers::now_utc();
		$product->updated_at     = Helpers::now_utc();

		$id = $this->products->insert( $product );
		if ( $id <= 0 ) {
			return null;
		}
		$product->id = $id;
		return $product;
	}

	/**
	 * EDD-SL's per-license activation list is stored in post meta. Different
	 * versions used different keys — handle the common ones.
	 *
	 * @return int Activations created.
	 */
	private function migrate_activations( int $license_post_id, int $license_id ): int {
		$rows = (array) get_post_meta( $license_post_id, '_edd_sl_active_sites', true );

		// Some versions stored a flat array of URLs; others stored arrays of arrays.
		$created = 0;
		foreach ( $rows as $row ) {
			$site_url = is_array( $row ) ? (string) ( $row['site'] ?? $row['url'] ?? '' ) : (string) $row;
			if ( '' === $site_url ) {
				continue;
			}
			$normalized = SiteNormalizer::normalize( $site_url );
			if ( '' === $normalized ) {
				continue;
			}

			$site_hash = Hasher::hash_site_url( $normalized );
			if ( $this->activations->find_by_license_and_site( $license_id, $site_hash, Activation::STATUS_ACTIVE ) instanceof Activation ) {
				continue;
			}

			$activation                 = new Activation();
			$activation->license_id     = $license_id;
			$activation->site_url       = $normalized;
			$activation->site_url_hash  = $site_hash;
			$activation->site_environment = SiteNormalizer::detect_environment( $normalized );
			$activation->activated_at   = Helpers::now_utc();
			$activation->last_seen_at   = Helpers::now_utc();
			$activation->status         = Activation::STATUS_ACTIVE;
			$activation->meta           = [ 'imported_from' => 'edd_sl' ];

			if ( $this->activations->insert( $activation ) > 0 ) {
				++$created;
			}
		}
		return $created;
	}

	private function count_edd_sl_activations( int $license_post_id ): int {
		$rows = (array) get_post_meta( $license_post_id, '_edd_sl_active_sites', true );
		return count( $rows );
	}

	private function lookup_customer_email( int $user_id, int $payment_id ): ?string {
		if ( $user_id > 0 ) {
			$u = get_userdata( $user_id );
			if ( $u && ! empty( $u->user_email ) ) {
				return (string) $u->user_email;
			}
		}
		if ( $payment_id > 0 && function_exists( 'edd_get_payment_user_email' ) ) {
			$email = edd_get_payment_user_email( $payment_id );
			if ( '' !== $email ) {
				return (string) $email;
			}
		}
		return null;
	}

	private function lookup_edd_customer_id( int $user_id, int $payment_id ): ?int {
		if ( ! function_exists( 'edd_get_customer_by' ) ) {
			return null;
		}
		if ( $user_id > 0 ) {
			$customer = edd_get_customer_by( 'user_id', $user_id );
			if ( $customer && ! empty( $customer->id ) ) {
				return (int) $customer->id;
			}
		}
		if ( $payment_id > 0 && function_exists( 'edd_get_payment_customer_id' ) ) {
			$id = (int) edd_get_payment_customer_id( $payment_id );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return null;
	}

	private function lookup_price_id( int $license_post_id ): ?int {
		$pid = get_post_meta( $license_post_id, '_edd_sl_price_id', true );
		if ( '' === $pid || null === $pid ) {
			return null;
		}
		return (int) $pid;
	}

	private function infer_tier( int $limit ): string {
		switch ( $limit ) {
			case 0:
				return 'unlimited';
			case 1:
				return 'single';
			case 5:
				return 'five';
			default:
				return 'custom';
		}
	}

	/**
	 * Resolve an EDD-SL parent-license post id to a LicenseKit license id, if
	 * the parent has already been migrated. Otherwise null (renewal chain
	 * partially migrated; admin can re-run to backfill).
	 */
	private function resolve_parent_license_id( int $parent_post_id ): ?int {
		if ( $parent_post_id <= 0 ) {
			return null;
		}
		$parent_post = get_post( $parent_post_id );
		if ( ! $parent_post ) {
			return null;
		}
		$parent_key = $this->extract_raw_key( $parent_post );
		if ( '' === $parent_key ) {
			return null;
		}
		$parent = $this->licenses->find_by_key_hash( Hasher::hash_license_key( $parent_key ) );
		return $parent instanceof License ? (int) $parent->id : null;
	}
}
