<?php
/**
 * EDD Recurring (subscriptions) integration. Optional — guarded with
 * `function_exists` so LicenseKit works against bare EDD too.
 *
 *   - On subscription renewal, extend the linked license's `expires_at` by the
 *     stored renewal_period and clear `grace_until`.
 *   - On cancellation/expiration, set `status='expired'` and start a 14-day
 *     grace window (filterable via `licensekit_grace_period_days`).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\EDD;

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
		// Bail early if EDD Recurring isn't installed.
		if ( ! class_exists( 'EDD_Recurring' ) && ! function_exists( 'edd_recurring' ) ) {
			return;
		}

		add_action( 'edd_subscription_post_renew', [ $this, 'on_renewed' ], 20, 4 );
		add_action( 'edd_subscription_cancelled', [ $this, 'on_cancelled' ], 20, 2 );
		add_action( 'edd_subscription_expired', [ $this, 'on_expired' ], 20, 2 );
	}

	public function on_renewed( $subscription_id, $expiration, $subscription, $payment_id ): void {
		$license = $this->find_license_for_subscription( (int) $subscription_id, (int) $payment_id );
		if ( ! $license instanceof License ) {
			return;
		}

		$period = $license->renewal_period ?: '1y';
		$this->license_svc->extend( (int) $license->id, $period );
	}

	public function on_cancelled( $subscription_id, $subscription = null ): void {
		$this->expire_with_grace( (int) $subscription_id );
	}

	public function on_expired( $subscription_id, $subscription = null ): void {
		$this->expire_with_grace( (int) $subscription_id );
	}

	private function expire_with_grace( int $subscription_id ): void {
		$license = $this->find_license_for_subscription( $subscription_id, 0 );
		if ( ! $license instanceof License ) {
			return;
		}

		$grace_days = (int) apply_filters( 'licensekit_grace_period_days', 14 );
		$grace_until = Helpers::add_period_to_datetime( Helpers::now_utc(), $grace_days . 'd' );

		$this->license_svc->set_status( (int) $license->id, License::STATUS_EXPIRED, $grace_until );
	}

	/**
	 * Look up a license by the subscription's parent payment id (which lives in `edd_order_id` on our license rows).
	 * EDD Recurring stores the parent payment on the subscription as `parent_payment_id`.
	 */
	private function find_license_for_subscription( int $subscription_id, int $hint_payment_id ): ?License {
		$payment_id = $hint_payment_id;

		if ( $payment_id <= 0 && function_exists( 'edd_recurring' ) ) {
			$sub = $this->load_subscription( $subscription_id );
			$payment_id = is_object( $sub ) && isset( $sub->parent_payment_id ) ? (int) $sub->parent_payment_id : 0;
		}

		if ( $payment_id <= 0 ) {
			return null;
		}

		$licenses = $this->licenses->find_by_edd_order_id( $payment_id );
		// Multiple licenses can come from the same order; for v1 we take the first.
		// Future: store the subscription_id on the license meta to disambiguate.
		return ! empty( $licenses ) ? $licenses[0] : null;
	}

	private function load_subscription( int $subscription_id ) {
		if ( ! class_exists( 'EDD_Subscription' ) ) {
			return null;
		}
		return new \EDD_Subscription( $subscription_id );
	}
}
