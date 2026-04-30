<?php
/**
 * License DTO. Note: raw key is never stored — `key_hash` is the lookup column.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

use LicenseKit\Services\EncryptedKey;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class License {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_DISABLED = 'disabled';
	public const STATUS_REVOKED  = 'revoked';
	public const STATUS_PENDING  = 'pending';

	public ?int $id                 = null;
	public string $key_hash         = '';
	public string $key_prefix       = '';
	public ?int $customer_id        = null;
	public ?string $customer_email  = null;
	public int $product_id          = 0;
	public ?int $edd_order_id       = null;
	public ?int $edd_price_id       = null;
	public string $tier             = 'single';
	public int $activation_limit    = 1;
	public string $status           = self::STATUS_ACTIVE;
	public ?string $issued_at       = null;
	public ?string $expires_at      = null;
	public ?string $grace_until     = null;
	public ?string $renewal_period  = null;
	public ?int $parent_license_id  = null;
	public array $meta              = [];
	public ?string $key_encrypted   = null;
	public ?string $created_at      = null;
	public ?string $updated_at      = null;

	public static function from_row( array $row ): self {
		$l                    = new self();
		$l->id                = isset( $row['id'] ) ? (int) $row['id'] : null;
		$l->key_hash          = (string) ( $row['key_hash'] ?? '' );
		$l->key_prefix        = (string) ( $row['key_prefix'] ?? '' );
		$l->customer_id       = isset( $row['customer_id'] ) ? (int) $row['customer_id'] : null;
		$l->customer_email    = isset( $row['customer_email'] ) ? (string) $row['customer_email'] : null;
		$l->product_id        = (int) ( $row['product_id'] ?? 0 );
		$l->edd_order_id      = isset( $row['edd_order_id'] ) ? (int) $row['edd_order_id'] : null;
		$l->edd_price_id      = isset( $row['edd_price_id'] ) ? (int) $row['edd_price_id'] : null;
		$l->tier              = (string) ( $row['tier'] ?? 'single' );
		$l->activation_limit  = (int) ( $row['activation_limit'] ?? 1 );
		$l->status            = (string) ( $row['status'] ?? self::STATUS_ACTIVE );
		$l->issued_at         = isset( $row['issued_at'] ) ? (string) $row['issued_at'] : null;
		$l->expires_at        = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null;
		$l->grace_until       = isset( $row['grace_until'] ) ? (string) $row['grace_until'] : null;
		$l->renewal_period    = isset( $row['renewal_period'] ) ? (string) $row['renewal_period'] : null;
		$l->parent_license_id = isset( $row['parent_license_id'] ) ? (int) $row['parent_license_id'] : null;
		$l->meta              = Helpers::decode_json_column( $row['meta'] ?? null );
		$l->key_encrypted     = isset( $row['key_encrypted'] ) ? (string) $row['key_encrypted'] : null;
		$l->created_at        = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		$l->updated_at        = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null;
		return $l;
	}

	public function to_array(): array {
		return [
			'id'                => $this->id,
			'key_hash'          => $this->key_hash,
			'key_prefix'        => $this->key_prefix,
			'customer_id'       => $this->customer_id,
			'customer_email'    => $this->customer_email,
			'product_id'        => $this->product_id,
			'edd_order_id'      => $this->edd_order_id,
			'edd_price_id'      => $this->edd_price_id,
			'tier'              => $this->tier,
			'activation_limit'  => $this->activation_limit,
			'status'            => $this->status,
			'issued_at'         => $this->issued_at,
			'expires_at'        => $this->expires_at,
			'grace_until'       => $this->grace_until,
			'renewal_period'    => $this->renewal_period,
			'parent_license_id' => $this->parent_license_id,
			'meta'              => Helpers::encode_json_column( $this->meta ),
			'key_encrypted'     => $this->key_encrypted,
			'created_at'        => $this->created_at,
			'updated_at'        => $this->updated_at,
		];
	}

	public function is_lifetime(): bool {
		return null === $this->expires_at;
	}

	public function is_unlimited(): bool {
		return 0 === $this->activation_limit;
	}

	/**
	 * Reveal the raw license key. Returns null for legacy rows that pre-date the
	 * 1.0.2 migration, or when libsodium is missing on the host.
	 */
	public function reveal_raw_key(): ?string {
		return EncryptedKey::decrypt( $this->key_encrypted );
	}
}
