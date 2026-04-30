<?php
/**
 * Activation DTO — one row per (license, site, status) triple.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public const STATUS_ACTIVE      = 'active';
	public const STATUS_DEACTIVATED = 'deactivated';
	public const STATUS_REVOKED     = 'revoked';

	public const ENV_PRODUCTION = 'production';
	public const ENV_STAGING    = 'staging';
	public const ENV_LOCAL      = 'local';
	public const ENV_UNKNOWN    = 'unknown';

	public ?int $id                = null;
	public int $license_id         = 0;
	public string $site_url        = '';
	public string $site_url_hash   = '';
	public string $site_environment = self::ENV_UNKNOWN;
	public ?string $activated_at   = null;
	public ?string $last_seen_at   = null;
	public string $status          = self::STATUS_ACTIVE;
	public ?string $client_ip      = null;
	public ?string $user_agent     = null;
	public array $meta             = [];

	public static function from_row( array $row ): self {
		$a                   = new self();
		$a->id               = isset( $row['id'] ) ? (int) $row['id'] : null;
		$a->license_id       = (int) ( $row['license_id'] ?? 0 );
		$a->site_url         = (string) ( $row['site_url'] ?? '' );
		$a->site_url_hash    = (string) ( $row['site_url_hash'] ?? '' );
		$a->site_environment = (string) ( $row['site_environment'] ?? self::ENV_UNKNOWN );
		$a->activated_at     = isset( $row['activated_at'] ) ? (string) $row['activated_at'] : null;
		$a->last_seen_at     = isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : null;
		$a->status           = (string) ( $row['status'] ?? self::STATUS_ACTIVE );
		$a->client_ip        = isset( $row['client_ip'] ) ? (string) $row['client_ip'] : null;
		$a->user_agent       = isset( $row['user_agent'] ) ? (string) $row['user_agent'] : null;
		$a->meta             = Helpers::decode_json_column( $row['meta'] ?? null );
		return $a;
	}

	public function to_array(): array {
		return [
			'id'               => $this->id,
			'license_id'       => $this->license_id,
			'site_url'         => $this->site_url,
			'site_url_hash'    => $this->site_url_hash,
			'site_environment' => $this->site_environment,
			'activated_at'     => $this->activated_at,
			'last_seen_at'     => $this->last_seen_at,
			'status'           => $this->status,
			'client_ip'        => $this->client_ip,
			'user_agent'       => $this->user_agent,
			'meta'             => Helpers::encode_json_column( $this->meta ),
		];
	}
}
