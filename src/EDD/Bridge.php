<?php
/**
 * EDD bridge — hooks into purchase / refund / subscription events and turns them
 * into LicenseService calls. Issues one license per quantity unit per licensed
 * line item; raw keys are captured in a per-order transient for receipt rendering
 * (the "show once" disclosure).
 *
 * Wires only when EDD is active. Subscription hooks are guarded with
 * `function_exists` so this file works against bare EDD without Recurring.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\EDD;

use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\LicenseService;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Bridge {

	/** Transient key for capturing raw keys per order for receipt-render-once disclosure. */
	public const RAW_KEYS_TRANSIENT_PREFIX = 'lk_raw_keys_';
	public const RAW_KEYS_TTL              = HOUR_IN_SECONDS;

	private LicenseService $license_svc;
	private LicenseRepository $licenses;
	private ProductRepository $products;
	private AuditLogger $audit;

	public function __construct(
		LicenseService $license_svc,
		LicenseRepository $licenses,
		ProductRepository $products,
		AuditLogger $audit
	) {
		$this->license_svc = $license_svc;
		$this->licenses    = $licenses;
		$this->products    = $products;
		$this->audit       = $audit;
	}

	public static function make(): self {
		$audit       = new AuditLogger( new LogRepository() );
		$licenses    = new LicenseRepository();
		$products    = new ProductRepository();
		$activations = new ActivationRepository();
		return new self(
			new LicenseService( $licenses, $products, $activations, $audit ),
			$licenses,
			$products,
			$audit
		);
	}

	public function register(): void {
		// `edd_complete_purchase` covers both standard checkouts and admin
		// orders that get marked complete. `edd_built_order` is intentionally
		// NOT hooked: in EDD 3.x both fire on a normal purchase, which caused
		// double-issuance. Admin-created drafts that never complete don't
		// need licenses anyway.
		add_action( 'edd_complete_purchase', [ $this, 'on_purchase_complete' ], 20 );
		add_action( 'edd_refund_order', [ $this, 'on_refund' ], 20 );
	}

	public function on_purchase_complete( int $order_id ): void {
		// Idempotency guard via per-order option — survives across requests
		// and works even if EDD fires the hook twice in different contexts.
		$marker = 'licensekit_processed_order_' . $order_id;
		if ( get_option( $marker ) ) {
			return;
		}
		$this->issue_for_order( $order_id );
		update_option( $marker, time(), false );
	}

	public function on_refund( int $order_id ): void {
		if ( ! function_exists( 'edd_get_order_items' ) ) {
			return;
		}
		$items = edd_get_order_items( [ 'order_id' => $order_id, 'number' => 200 ] );
		if ( empty( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			$license_ids = $this->order_item_license_ids( (int) $item->id );
			foreach ( $license_ids as $lid ) {
				$this->license_svc->set_status( $lid, License::STATUS_REVOKED );
				do_action( 'licensekit_license_revoked_by_refund', $lid, $order_id );
			}
		}
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	private function issue_for_order( int $order_id ): void {
		if ( ! function_exists( 'edd_get_order' ) || ! function_exists( 'edd_get_order_items' ) ) {
			return;
		}

		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$items = edd_get_order_items( [ 'order_id' => $order_id, 'number' => 200 ] );
		if ( empty( $items ) ) {
			return;
		}

		$raw_keys = [];

		foreach ( $items as $item ) {
			$download_id = (int) $item->product_id;
			if ( ! DownloadMetaBox::is_licensing_enabled( $download_id ) ) {
				continue;
			}

			$product = $this->resolve_or_create_product( $download_id );
			if ( ! $product instanceof Product ) {
				continue;
			}

			$tier             = DownloadMetaBox::get_tier( $download_id );
			$activation_limit = DownloadMetaBox::get_activation_limit( $download_id );
			$expiry_period    = DownloadMetaBox::get_expiry_period( $download_id );

			$expires_at = $this->compute_expiry( $expiry_period );

			$quantity = max( 1, (int) ( $item->quantity ?? 1 ) );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$result = $this->license_svc->issue(
					[
						'product_id'       => (int) $product->id,
						'tier'             => $tier,
						'activation_limit' => $activation_limit,
						'customer_id'      => isset( $order->customer_id ) ? (int) $order->customer_id : null,
						'customer_email'   => isset( $order->email ) ? (string) $order->email : null,
						'edd_order_id'     => $order_id,
						'edd_price_id'     => isset( $item->price_id ) ? (int) $item->price_id : null,
						'expires_at'       => $expires_at,
						'renewal_period'   => 'lifetime' === $expiry_period ? null : $expiry_period,
					]
				);

				if ( ! empty( $result['success'] ) && isset( $result['license'], $result['raw_key'] ) ) {
					/** @var License $license */
					$license = $result['license'];

					if ( function_exists( 'edd_add_order_item_meta' ) ) {
						edd_add_order_item_meta(
							(int) $item->id,
							'_licensekit_license_id',
							(int) $license->id
						);
					}

					$raw_keys[] = [
						'license_id'   => (int) $license->id,
						'key'          => (string) $result['raw_key'],
						'product_name' => (string) $product->name,
						'product_slug' => (string) $product->slug,
						'expires_at'   => $expires_at,
					];
				}
			}
		}

		if ( ! empty( $raw_keys ) ) {
			set_transient( $this->raw_keys_key( $order_id ), $raw_keys, self::RAW_KEYS_TTL );
		}
	}

	/**
	 * Look up the DLM product for an EDD download. If the download is licensed
	 * but no DLM product exists yet, auto-create one keyed off the download's
	 * post fields.
	 */
	private function resolve_or_create_product( int $download_id ): ?Product {
		$existing = $this->products->find_by_edd_download_id( $download_id );
		if ( $existing instanceof Product ) {
			return $existing;
		}

		$post = get_post( $download_id );
		if ( ! $post ) {
			return null;
		}

		$product                  = new Product();
		$product->edd_download_id = $download_id;
		$product->slug            = (string) $post->post_name;
		$product->name            = (string) $post->post_title;
		$product->type            = 'plugin';
		$product->author          = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		$product->homepage_url    = (string) get_permalink( $download_id );
		$product->meta            = [];
		$product->created_at      = Helpers::now_utc();
		$product->updated_at      = Helpers::now_utc();

		$id = $this->products->insert( $product );
		if ( $id <= 0 ) {
			return null;
		}
		$product->id = $id;

		$this->audit->record(
			'product.auto_created',
			[
				'edd_download_id' => $download_id,
				'slug'            => $product->slug,
			],
			'product',
			$id
		);

		return $product;
	}

	private function compute_expiry( string $period ): ?string {
		if ( 'lifetime' === $period || '' === $period ) {
			return null;
		}
		return Helpers::add_period_to_datetime( Helpers::now_utc(), $period );
	}

	private function order_already_issued( int $order_id ): bool {
		if ( ! function_exists( 'edd_get_order_items' ) ) {
			return false;
		}
		$items = edd_get_order_items( [ 'order_id' => $order_id, 'number' => 1 ] );
		if ( empty( $items ) ) {
			return false;
		}
		return ! empty( $this->order_item_license_ids( (int) $items[0]->id ) );
	}

	/**
	 * @return int[]
	 */
	private function order_item_license_ids( int $order_item_id ): array {
		if ( ! function_exists( 'edd_get_order_item_meta' ) ) {
			return [];
		}
		$ids = edd_get_order_item_meta( $order_item_id, '_licensekit_license_id', false );
		if ( ! is_array( $ids ) ) {
			return [];
		}
		return array_map( 'intval', $ids );
	}

	public function raw_keys_key( int $order_id ): string {
		return self::RAW_KEYS_TRANSIENT_PREFIX . $order_id;
	}

	/**
	 * Public accessor for the receipt integration to pull raw keys for rendering.
	 *
	 * @return array<int, array{license_id:int, key:string, product_name:string, product_slug:string, expires_at:?string}>
	 */
	public function get_raw_keys_for_order( int $order_id ): array {
		$keys = get_transient( $this->raw_keys_key( $order_id ) );
		return is_array( $keys ) ? $keys : [];
	}
}
