<?php
/**
 * WooCommerce bridge — issues licenses on order completion, revokes on refund,
 * and exposes raw keys to the receipt + email integration.
 *
 * Mirrors the EDD bridge's contract: same per-order idempotency marker, same
 * `Bridge::get_raw_keys_for_order()` API used by `EmailIntegration` and
 * `MyAccountIntegration`.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\WooCommerce;

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

	public const RAW_KEYS_TRANSIENT_PREFIX = 'lk_wc_raw_keys_';
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
		// Issue licenses as soon as the order is paid. WC has three transitions
		// that mean "payment recognized" depending on gateway and item mix, so we
		// listen to all three. The per-order idempotency marker guarantees that
		// firing on more than one of them only issues licenses once.
		//
		//  - `woocommerce_payment_complete`     fires inside WC_Order::payment_complete()
		//                                        for gateways like Stripe/PayPal.
		//  - `woocommerce_order_status_processing` catches manual gateways
		//                                        (Cheque/BACS/COD) once the merchant
		//                                        moves the order off `on-hold`.
		//  - `woocommerce_order_status_completed`  catches free orders, admin-
		//                                        completed orders, and any path
		//                                        that skips `processing`.
		add_action( 'woocommerce_payment_complete', [ $this, 'on_order_paid' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'on_order_paid' ], 20, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_paid' ], 20, 1 );

		// Refund handling — flip licenses to `revoked`.
		add_action( 'woocommerce_order_status_refunded', [ $this, 'on_refund' ], 20, 1 );
		add_action( 'woocommerce_order_refunded', [ $this, 'on_partial_refund' ], 20, 2 );
	}

	public function on_order_paid( int $order_id ): void {
		$marker = 'licensekit_processed_wc_order_' . $order_id;
		if ( get_option( $marker ) ) {
			return;
		}
		// Vendors can short-circuit issuance (e.g. require manual approval on
		// COD orders) by returning false from this filter.
		if ( ! (bool) apply_filters( 'licensekit_wc_should_issue_for_order', true, $order_id ) ) {
			return;
		}
		$this->issue_for_order( $order_id );
		update_option( $marker, time(), false );
	}

	public function on_refund( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$ids = (array) $item->get_meta( '_licensekit_license_ids', true );
			foreach ( $ids as $lid ) {
				$lid = (int) $lid;
				if ( $lid <= 0 ) {
					continue;
				}
				$this->license_svc->set_status( $lid, License::STATUS_REVOKED );
				do_action( 'licensekit_license_revoked_by_refund', $lid, $order_id );
			}
		}
	}

	/**
	 * WooCommerce fires `woocommerce_order_refunded` for partial refunds.
	 * For v1 we treat any partial refund the same as a full refund. Operators
	 * who want finer-grained behavior can `remove_action` and substitute their
	 * own handler.
	 */
	public function on_partial_refund( int $order_id, int $refund_id ): void {
		$this->on_refund( $order_id );
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	private function issue_for_order( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$customer_email = (string) $order->get_billing_email();
		$wp_user_id     = (int) $order->get_customer_id();

		$raw_keys = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_obj = $item->get_product();
			if ( ! $product_obj ) {
				continue;
			}

			// For variable products, get_product_id() returns the parent and
			// get_variation_id() returns the variation id (or 0 for simple).
			$parent_id    = (int) $item->get_product_id();
			$variation_id = (int) ( method_exists( $item, 'get_variation_id' ) ? $item->get_variation_id() : 0 );

			// Licensing toggle lives on the parent product. Variations only
			// override the *settings* (tier / limit / expiry) — they don't
			// independently enable or disable licensing.
			if ( ! ProductSettings::is_licensing_enabled( $parent_id ) ) {
				continue;
			}

			$dlm_product = $this->resolve_or_create_product( $parent_id, wc_get_product( $parent_id ) ?: $product_obj );
			if ( ! $dlm_product instanceof Product ) {
				continue;
			}

			$settings         = ProductSettings::effective_settings( $variation_id, $parent_id );
			$tier             = $settings['tier'];
			$activation_limit = $settings['activation_limit'];
			$expiry_period    = $settings['expiry_period'];
			$expires_at       = $this->compute_expiry( $expiry_period );

			$quantity = max( 1, (int) $item->get_quantity() );
			$created_license_ids = [];

			for ( $i = 0; $i < $quantity; $i++ ) {
				$result = $this->license_svc->issue(
					[
						'product_id'       => (int) $dlm_product->id,
						'tier'             => $tier,
						'activation_limit' => $activation_limit,
						'customer_email'   => $customer_email,
						'expires_at'       => $expires_at,
						'renewal_period'   => 'lifetime' === $expiry_period ? null : $expiry_period,
						'meta'             => [
							'source'          => 'woocommerce',
							'wc_order_id'     => $order_id,
							'wc_user_id'      => $wp_user_id,
							'wc_product_id'   => $parent_id,
							'wc_variation_id' => $variation_id > 0 ? $variation_id : null,
						],
					]
				);

				if ( ! empty( $result['success'] ) && isset( $result['license'], $result['raw_key'] ) ) {
					/** @var License $license */
					$license = $result['license'];
					$created_license_ids[] = (int) $license->id;

					$raw_keys[] = [
						'license_id'   => (int) $license->id,
						'key'          => (string) $result['raw_key'],
						'product_name' => (string) $dlm_product->name,
						'product_slug' => (string) $dlm_product->slug,
						'expires_at'   => $expires_at,
					];
				}
			}


			if ( ! empty( $created_license_ids ) ) {
				$item->add_meta_data( '_licensekit_license_ids', $created_license_ids, true );
				$item->save();
			}
		}

		if ( ! empty( $raw_keys ) ) {
			set_transient( $this->raw_keys_key( $order_id ), $raw_keys, self::RAW_KEYS_TTL );
		}
	}

	/**
	 * Look up the LicenseKit product for a WooCommerce product id, auto-creating
	 * one keyed off the WC product if licensing is enabled but no DLM product exists.
	 *
	 * @param int    $wc_product_id
	 * @param object $wc_product    WC_Product instance.
	 */
	private function resolve_or_create_product( int $wc_product_id, $wc_product ): ?Product {
		$existing = $this->products->find_by_wc_product_id( $wc_product_id );
		if ( $existing instanceof Product ) {
			return $existing;
		}

		$slug_source = method_exists( $wc_product, 'get_slug' ) ? (string) $wc_product->get_slug() : '';
		$name        = method_exists( $wc_product, 'get_name' ) ? (string) $wc_product->get_name() : 'WC Product ' . $wc_product_id;
		$permalink   = method_exists( $wc_product, 'get_permalink' ) ? (string) $wc_product->get_permalink() : '';

		// Make sure the slug is unique against existing dlm_products.slug.
		$slug = $slug_source !== '' ? $slug_source : sanitize_title( $name );
		if ( $this->products->find_by_slug( $slug ) instanceof Product ) {
			$slug .= '-wc-' . $wc_product_id;
		}

		$product                = new Product();
		$product->wc_product_id = $wc_product_id;
		$product->slug          = $slug;
		$product->name          = $name;
		$product->type          = 'plugin';
		$product->homepage_url  = $permalink;
		$product->meta          = [];
		$product->created_at    = Helpers::now_utc();
		$product->updated_at    = Helpers::now_utc();

		$id = $this->products->insert( $product );
		if ( $id <= 0 ) {
			return null;
		}
		$product->id = $id;

		$this->audit->record(
			'product.auto_created',
			[
				'wc_product_id' => $wc_product_id,
				'slug'          => $product->slug,
				'source'        => 'woocommerce',
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

	public function raw_keys_key( int $order_id ): string {
		return self::RAW_KEYS_TRANSIENT_PREFIX . $order_id;
	}

	/**
	 * @return array<int, array{license_id:int, key:string, product_name:string, product_slug:string, expires_at:?string}>
	 */
	public function get_raw_keys_for_order( int $order_id ): array {
		$keys = get_transient( $this->raw_keys_key( $order_id ) );
		return is_array( $keys ) ? $keys : [];
	}
}
