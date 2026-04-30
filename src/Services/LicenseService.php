<?php
/**
 * License lifecycle service — issuance, activation, deactivation, validation,
 * key rotation, expiry extension, status changes.
 *
 * All operations return a structured array with `success` + either error data
 * or license/product details. Error codes are stable for client consumption.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Models\Activation;
use LicenseKit\Models\License;
use LicenseKit\Models\Log;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class LicenseService {

	public const ERR_INVALID_KEY        = 'invalid_key';
	public const ERR_EXPIRED            = 'expired';
	public const ERR_DISABLED           = 'disabled';
	public const ERR_REVOKED            = 'revoked';
	public const ERR_PENDING            = 'pending';
	public const ERR_PRODUCT_NOT_FOUND  = 'product_not_found';
	public const ERR_PRODUCT_MISMATCH   = 'product_mismatch';
	public const ERR_ACTIVATION_LIMIT   = 'activation_limit';
	public const ERR_INVALID_SITE       = 'invalid_site';
	public const ERR_NOT_ACTIVATED      = 'not_activated';

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

	/**
	 * Issue a brand-new license key. The raw key is returned ONCE in the response;
	 * only its peppered hash is persisted.
	 *
	 * @param array $args {
	 *   @type int    $product_id        Required.
	 *   @type string $tier              Required (e.g. 'single', 'five', 'unlimited').
	 *   @type int    $activation_limit  Required (0 = unlimited).
	 *   @type ?int    $customer_id      EDD customer id.
	 *   @type ?string $customer_email
	 *   @type ?int    $edd_order_id
	 *   @type ?int    $edd_price_id
	 *   @type ?string $expires_at       UTC datetime; null for lifetime.
	 *   @type ?string $renewal_period   '1y' / '6m' / etc.
	 *   @type ?int    $parent_license_id For renewal chains.
	 *   @type array   $meta             Arbitrary JSON-serializable.
	 * }
	 * @return array{success:bool, license?:License, raw_key?:string, error?:string, message?:string}
	 */
	public function issue( array $args ): array {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		$product    = $product_id > 0 ? $this->products->find( $product_id ) : null;
		if ( ! $product instanceof Product ) {
			return $this->fail( self::ERR_PRODUCT_NOT_FOUND, __( 'Product not found.', 'licensekit' ) );
		}

		$raw_key  = KeyGenerator::generate();
		$now      = Helpers::now_utc();

		$license                    = new License();
		$license->key_hash          = Hasher::hash_license_key( $raw_key );
		$license->key_prefix        = KeyGenerator::prefix_of( $raw_key );
		$license->customer_id       = isset( $args['customer_id'] ) ? (int) $args['customer_id'] : null;
		$license->customer_email    = isset( $args['customer_email'] ) ? (string) $args['customer_email'] : null;
		$license->product_id        = $product_id;
		$license->edd_order_id      = isset( $args['edd_order_id'] ) ? (int) $args['edd_order_id'] : null;
		$license->edd_price_id      = isset( $args['edd_price_id'] ) ? (int) $args['edd_price_id'] : null;
		$license->tier              = (string) ( $args['tier'] ?? 'single' );
		$license->activation_limit  = (int) ( $args['activation_limit'] ?? 1 );
		$license->status            = License::STATUS_ACTIVE;
		$license->issued_at         = $now;
		$license->expires_at        = isset( $args['expires_at'] ) ? (string) $args['expires_at'] : null;
		$license->grace_until       = null;
		$license->renewal_period    = isset( $args['renewal_period'] ) ? (string) $args['renewal_period'] : null;
		$license->parent_license_id = isset( $args['parent_license_id'] ) ? (int) $args['parent_license_id'] : null;
		$license->meta              = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : [];
		$license->key_encrypted     = EncryptedKey::encrypt( $raw_key );
		$license->created_at        = $now;
		$license->updated_at        = $now;

		$id = $this->licenses->insert( $license );
		if ( $id <= 0 ) {
			return $this->fail( 'db_error', __( 'Could not persist license.', 'licensekit' ) );
		}

		$license->id = $id;

		$this->audit->record(
			'license.issued',
			[
				'product_slug' => $product->slug,
				'tier'         => $license->tier,
				'expires_at'   => $license->expires_at,
				'order_id'     => $license->edd_order_id,
			],
			'license',
			$id
		);

		do_action( 'licensekit_license_issued', $license, $product, $raw_key );

		return [
			'success' => true,
			'license' => $license,
			'raw_key' => $raw_key,
		];
	}

	/**
	 * Activate a license against a site URL.
	 *
	 * @param string $raw_key
	 * @param string $product_slug
	 * @param string $site_url
	 * @param string $environment 'production' / 'staging' / 'local' / 'unknown' (provided by SDK).
	 */
	public function activate( string $raw_key, string $product_slug, string $site_url, string $environment ): array {
		$ctx = $this->load_context( $raw_key, $product_slug );
		if ( ! $ctx['ok'] ) {
			return $ctx['error'];
		}
		/** @var License $license */
		$license = $ctx['license'];
		/** @var Product $product */
		$product = $ctx['product'];

		$normalized_site = SiteNormalizer::normalize( $site_url );
		if ( '' === $normalized_site ) {
			return $this->fail( self::ERR_INVALID_SITE, __( 'Invalid site URL.', 'licensekit' ) );
		}

		$site_hash = Hasher::hash_site_url( $normalized_site );

		// Sanitize environment to a known value; trust the SDK but clamp.
		$environment = $this->sanitize_environment( $environment );

		// Idempotent: if already active for this (license, site), refresh and return success.
		$existing = $this->activations->find_by_license_and_site( (int) $license->id, $site_hash, Activation::STATUS_ACTIVE );
		if ( $existing instanceof Activation ) {
			$this->activations->update(
				(int) $existing->id,
				[
					'last_seen_at'     => Helpers::now_utc(),
					'site_environment' => $environment,
				]
			);
			return $this->success_payload( $license, $product );
		}

		// Activation limit (0 = unlimited; local/staging environments exempt per design D2).
		if ( $license->activation_limit > 0 && Activation::ENV_LOCAL !== $environment ) {
			$used = $this->activations->count_billable_active_for_license( (int) $license->id );
			if ( $used >= $license->activation_limit ) {
				return $this->fail(
					self::ERR_ACTIVATION_LIMIT,
					sprintf(
						/* translators: 1: used count, 2: limit */
						__( 'Activation limit reached (%1$d / %2$d).', 'licensekit' ),
						$used,
						$license->activation_limit
					)
				);
			}
		}

		// Find any existing row for (license, site) — could be deactivated/revoked from a previous cycle.
		$prior = $this->find_any_activation_for_site( (int) $license->id, $site_hash );
		$now   = Helpers::now_utc();

		if ( $prior instanceof Activation ) {
			$this->activations->update(
				(int) $prior->id,
				[
					'status'           => Activation::STATUS_ACTIVE,
					'site_url'         => $normalized_site,
					'site_environment' => $environment,
					'activated_at'     => $now,
					'last_seen_at'     => $now,
				]
			);
			$activation_id = (int) $prior->id;
		} else {
			$row = new Activation();
			$row->license_id       = (int) $license->id;
			$row->site_url         = $normalized_site;
			$row->site_url_hash    = $site_hash;
			$row->site_environment = $environment;
			$row->status           = Activation::STATUS_ACTIVE;
			$row->activated_at     = $now;
			$row->last_seen_at     = $now;
			$activation_id         = $this->activations->insert( $row );
		}

		$this->audit->record(
			'license.activated',
			[
				'product_slug' => $product->slug,
				'site_url'     => $normalized_site,
				'environment'  => $environment,
			],
			'license',
			(int) $license->id,
			Log::ACTOR_LICENSE,
			(int) $license->id
		);

		do_action( 'licensekit_license_activated', $license, $product, $normalized_site, $environment );

		return $this->success_payload( $license, $product );
	}

	public function deactivate( string $raw_key, string $site_url ): array {
		$license = $this->find_by_key( $raw_key );
		if ( ! $license instanceof License ) {
			return $this->fail( self::ERR_INVALID_KEY, __( 'License key not recognized.', 'licensekit' ) );
		}

		$normalized_site = SiteNormalizer::normalize( $site_url );
		if ( '' === $normalized_site ) {
			return $this->fail( self::ERR_INVALID_SITE, __( 'Invalid site URL.', 'licensekit' ) );
		}
		$site_hash = Hasher::hash_site_url( $normalized_site );

		$active = $this->activations->find_by_license_and_site( (int) $license->id, $site_hash, Activation::STATUS_ACTIVE );
		if ( ! $active instanceof Activation ) {
			// Idempotent: report success even if not currently active.
			return [ 'success' => true, 'message' => __( 'Site was not active.', 'licensekit' ) ];
		}

		$this->activations->update(
			(int) $active->id,
			[
				'status'       => Activation::STATUS_DEACTIVATED,
				'last_seen_at' => Helpers::now_utc(),
			]
		);

		$this->audit->record(
			'license.deactivated',
			[ 'site_url' => $normalized_site ],
			'license',
			(int) $license->id,
			Log::ACTOR_LICENSE,
			(int) $license->id
		);

		do_action( 'licensekit_license_deactivated', $license, $normalized_site );

		return [ 'success' => true ];
	}

	/**
	 * Read-only check used by the SDK on its periodic re-validate. Updates
	 * `last_seen_at` on the matching activation but does not bind/unbind.
	 */
	public function validate( string $raw_key, ?string $product_slug, string $site_url ): array {
		$ctx = $this->load_context( $raw_key, $product_slug );
		if ( ! $ctx['ok'] ) {
			return $ctx['error'];
		}
		/** @var License $license */
		$license = $ctx['license'];
		/** @var Product $product */
		$product = $ctx['product'];

		$normalized_site = SiteNormalizer::normalize( $site_url );
		if ( '' !== $normalized_site ) {
			$active = $this->activations->find_by_license_and_site(
				(int) $license->id,
				Hasher::hash_site_url( $normalized_site ),
				Activation::STATUS_ACTIVE
			);
			if ( $active instanceof Activation ) {
				$this->activations->update( (int) $active->id, [ 'last_seen_at' => Helpers::now_utc() ] );
			}
		}

		return $this->success_payload( $license, $product );
	}

	/**
	 * Look up license details without binding to a site (e.g. customer dashboard).
	 */
	public function info( string $raw_key ): array {
		$license = $this->find_by_key( $raw_key );
		if ( ! $license instanceof License ) {
			return $this->fail( self::ERR_INVALID_KEY, __( 'License key not recognized.', 'licensekit' ) );
		}
		$product = $this->products->find( $license->product_id );
		if ( ! $product instanceof Product ) {
			return $this->fail( self::ERR_PRODUCT_NOT_FOUND, __( 'Product not found.', 'licensekit' ) );
		}
		$status_check = $this->check_status( $license );
		if ( null !== $status_check ) {
			return $status_check;
		}
		return $this->success_payload( $license, $product );
	}

	/**
	 * Rotate the raw key. Stored hash is replaced; old key stops working immediately.
	 * Returns the new raw key (caller must surface it once).
	 */
	public function rotate_key( int $license_id ): array {
		$license = $this->licenses->find( $license_id );
		if ( ! $license instanceof License ) {
			return $this->fail( self::ERR_INVALID_KEY, __( 'License not found.', 'licensekit' ) );
		}

		$new_raw = KeyGenerator::generate();
		// Note: `expires_at` and activation rows are intentionally untouched —
		// rotation must NOT extend validity, only swap the user-facing key.
		$ok      = $this->licenses->update(
			$license_id,
			[
				'key_hash'      => Hasher::hash_license_key( $new_raw ),
				'key_prefix'    => KeyGenerator::prefix_of( $new_raw ),
				'key_encrypted' => EncryptedKey::encrypt( $new_raw ),
				'updated_at'    => Helpers::now_utc(),
			]
		);

		if ( ! $ok ) {
			return $this->fail( 'db_error', __( 'Could not rotate key.', 'licensekit' ) );
		}

		$this->audit->record( 'license.rotated', [], 'license', $license_id );
		do_action( 'licensekit_license_rotated', $license, $new_raw );

		return [ 'success' => true, 'raw_key' => $new_raw ];
	}

	/**
	 * Extend `expires_at` by a relative period (`1y`, `6m`, ...). Lifetime licenses
	 * remain lifetime. Re-activates an expired license to `active` if it had been
	 * expired with a grace window.
	 */
	public function extend( int $license_id, string $period ): array {
		$license = $this->licenses->find( $license_id );
		if ( ! $license instanceof License ) {
			return $this->fail( self::ERR_INVALID_KEY, __( 'License not found.', 'licensekit' ) );
		}
		if ( $license->is_lifetime() ) {
			return [ 'success' => true, 'message' => __( 'Lifetime license — no extension needed.', 'licensekit' ) ];
		}

		$base    = $license->expires_at && strtotime( $license->expires_at . ' UTC' ) > time()
			? $license->expires_at
			: Helpers::now_utc();
		$new_exp = Helpers::add_period_to_datetime( $base, $period );
		if ( null === $new_exp ) {
			return $this->fail( 'invalid_period', __( 'Invalid renewal period.', 'licensekit' ) );
		}

		$changes = [
			'expires_at' => $new_exp,
			'grace_until' => null,
			'updated_at' => Helpers::now_utc(),
		];
		if ( License::STATUS_EXPIRED === $license->status ) {
			$changes['status'] = License::STATUS_ACTIVE;
		}

		$this->licenses->update( $license_id, $changes );

		$this->audit->record(
			'license.extended',
			[ 'period' => $period, 'new_expires_at' => $new_exp ],
			'license',
			$license_id
		);
		do_action( 'licensekit_license_extended', $license_id, $new_exp );

		return [ 'success' => true, 'expires_at' => $new_exp ];
	}

	public function set_status( int $license_id, string $status, ?string $grace_until = null ): array {
		$valid = [
			License::STATUS_ACTIVE,
			License::STATUS_EXPIRED,
			License::STATUS_DISABLED,
			License::STATUS_REVOKED,
			License::STATUS_PENDING,
		];
		if ( ! in_array( $status, $valid, true ) ) {
			return $this->fail( 'invalid_status', __( 'Unknown status.', 'licensekit' ) );
		}
		$license = $this->licenses->find( $license_id );
		if ( ! $license instanceof License ) {
			return $this->fail( self::ERR_INVALID_KEY, __( 'License not found.', 'licensekit' ) );
		}

		$this->licenses->update(
			$license_id,
			[
				'status'      => $status,
				'grace_until' => $grace_until,
				'updated_at'  => Helpers::now_utc(),
			]
		);

		$this->audit->record(
			'license.status_changed',
			[ 'from' => $license->status, 'to' => $status ],
			'license',
			$license_id
		);
		do_action( 'licensekit_license_status_changed', $license_id, $status, $license->status );

		return [ 'success' => true ];
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Load license + product, run status/expiry/match checks. Returns either
	 * `['ok' => true, 'license' => …, 'product' => …]` or `['ok' => false, 'error' => …]`.
	 */
	private function load_context( string $raw_key, ?string $product_slug ): array {
		$license = $this->find_by_key( $raw_key );
		if ( ! $license instanceof License ) {
			return [ 'ok' => false, 'error' => $this->fail( self::ERR_INVALID_KEY, __( 'License key not recognized.', 'licensekit' ) ) ];
		}

		$product = $this->products->find( $license->product_id );
		if ( ! $product instanceof Product ) {
			return [ 'ok' => false, 'error' => $this->fail( self::ERR_PRODUCT_NOT_FOUND, __( 'Product not found.', 'licensekit' ) ) ];
		}

		if ( null !== $product_slug && $product->slug !== $product_slug ) {
			return [ 'ok' => false, 'error' => $this->fail( self::ERR_PRODUCT_MISMATCH, __( 'License is for a different product.', 'licensekit' ) ) ];
		}

		$status_check = $this->check_status( $license );
		if ( null !== $status_check ) {
			return [ 'ok' => false, 'error' => $status_check ];
		}

		return [ 'ok' => true, 'license' => $license, 'product' => $product ];
	}

	private function find_by_key( string $raw_key ): ?License {
		$raw_key = trim( $raw_key );
		if ( '' === $raw_key ) {
			return null;
		}
		return $this->licenses->find_by_key_hash( Hasher::hash_license_key( $raw_key ) );
	}

	/**
	 * Returns null if license is OK to use, or a fail() array if not.
	 */
	private function check_status( License $license ): ?array {
		switch ( $license->status ) {
			case License::STATUS_ACTIVE:
				if ( null !== $license->expires_at && strtotime( $license->expires_at . ' UTC' ) < time() ) {
					if ( null !== $license->grace_until && strtotime( $license->grace_until . ' UTC' ) > time() ) {
						return null; // In grace — still usable.
					}
					return $this->fail(
						self::ERR_EXPIRED,
						sprintf(
							/* translators: %s: expiry date */
							__( 'License expired on %s.', 'licensekit' ),
							$license->expires_at
						)
					);
				}
				return null;
			case License::STATUS_EXPIRED:
				return $this->fail( self::ERR_EXPIRED, __( 'License expired.', 'licensekit' ) );
			case License::STATUS_DISABLED:
				return $this->fail( self::ERR_DISABLED, __( 'License disabled.', 'licensekit' ) );
			case License::STATUS_REVOKED:
				return $this->fail( self::ERR_REVOKED, __( 'License revoked.', 'licensekit' ) );
			case License::STATUS_PENDING:
				return $this->fail( self::ERR_PENDING, __( 'License pending.', 'licensekit' ) );
			default:
				return $this->fail( 'invalid_status', __( 'Unknown license status.', 'licensekit' ) );
		}
	}

	private function find_any_activation_for_site( int $license_id, string $site_hash ): ?Activation {
		// The unique key is (license_id, site_url_hash) — at most one row per pair.
		foreach (
			[ Activation::STATUS_DEACTIVATED, Activation::STATUS_REVOKED ] as $status
		) {
			$row = $this->activations->find_by_license_and_site( $license_id, $site_hash, $status );
			if ( $row instanceof Activation ) {
				return $row;
			}
		}
		return null;
	}

	private function sanitize_environment( string $env ): string {
		$valid = [
			Activation::ENV_PRODUCTION,
			Activation::ENV_STAGING,
			Activation::ENV_LOCAL,
			Activation::ENV_UNKNOWN,
		];
		return in_array( $env, $valid, true ) ? $env : Activation::ENV_UNKNOWN;
	}

	private function success_payload( License $license, Product $product ): array {
		$used = $this->activations->count_billable_active_for_license( (int) $license->id );
		return [
			'success' => true,
			'license' => [
				'status'             => $license->status,
				'tier'               => $license->tier,
				'expires_at'         => $license->expires_at,
				'grace_until'        => $license->grace_until,
				'activations_used'   => $used,
				'activations_limit'  => $license->activation_limit,
				'key_prefix'         => $license->key_prefix,
			],
			'product' => [
				'slug'            => $product->slug,
				'name'            => $product->name,
				'type'            => $product->type,
				'current_version' => $product->current_version,
			],
		];
	}

	private function fail( string $error_code, string $message ): array {
		return [
			'success' => false,
			'error'   => $error_code,
			'message' => $message,
		];
	}
}
