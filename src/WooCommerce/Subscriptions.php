<?php
/**
 * WooCommerce Subscriptions integration. Optional — guarded with
 * `class_exists` so LicenseKit works against bare WooCommerce too.
 *
 *   - Renewal payment completes → extend linked license's `expires_at`.
 *   - Subscription cancelled / expired → set `status='expired'` + 14-day grace.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\WooCommerce;

use LicenseKit\Models\License;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Services\LicenseService;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Subscriptions {

	private LicenseService $license_svc;
	private LicenseRepository $licenses;

	public function __construct( LicenseService $license_svc, LicenseRepository $licenses ) {
		$this->license_svc = $license_svc;
		$this->licenses    = $licenses;
	}

	public function register(): void {
		// Bail unless WooCommerce Subscriptions is active.
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}

		add_action( 'woocommerce_subscription_renewal_payment_complete', [ $this, 'on_renewal' ], 20, 1 );
		add_action( 'woocommerce_subscription_status_cancelled', [ $this, 'on_cancelled' ], 20, 1 );
		add_action( 'woocommerce_subscription_status_expired', [ $this, 'on_expired' ], 20, 1 );
	}

	public function on_renewal( $subscription ): void {
		$license = $this->find_license_for_subscription( $subscription );
		if ( ! $license instanceof License ) {
			return;
		}
		$period = $license->renewal_period ?: '1y';
		$this->license_svc->extend( (int) $license->id, $period );
	}

	public function on_cancelled( $subscription ): void {
		$this->expire_with_grace( $subscription );
	}

	public function on_expired( $subscription ): void {
		$this->expire_with_grace( $subscription );
	}

	private function expire_with_grace( $subscription ): void {
		$license = $this->find_license_for_subscription( $subscription );
		if ( ! $license instanceof License ) {
			return;
		}
		$grace_days  = (int) apply_filters( 'licensekit_grace_period_days', 14 );
		$grace_until = Helpers::add_period_to_datetime( Helpers::now_utc(), $grace_days . 'd' );
		$this->license_svc->set_status( (int) $license->id, License::STATUS_EXPIRED, $grace_until );
	}

	/**
	 * Look up the license linked to this subscription via the parent order id.
	 * Each License row stores the WC order id in `meta.wc_order_id`.
	 */
	private function find_license_for_subscription( $subscription ): ?License {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_parent_id' ) ) {
			return null;
		}
		$parent_id = (int) $subscription->get_parent_id();
		if ( $parent_id <= 0 ) {
			return null;
		}

		// Find candidate licenses by customer email and pick the one whose meta
		// references this order id. (We don't index by wc_order_id at the SQL
		// layer for v1 — small fan-in, acceptable.)
		$customer_email = method_exists( $subscription, 'get_billing_email' )
			? (string) $subscription->get_billing_email()
			: '';
		if ( '' === $customer_email ) {
			return null;
		}

		foreach ( $this->licenses->find_by_customer_email( $customer_email ) as $license ) {
			if ( (int) ( $license->meta['wc_order_id'] ?? 0 ) === $parent_id ) {
				return $license;
			}
		}
		return null;
	}
}
