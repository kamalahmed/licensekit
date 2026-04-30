<?php
/**
 * Webhook DTO — outbound event subscription.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Webhook {

	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	public ?int $id                = null;
	public string $endpoint_url    = '';
	public string $secret          = '';
	public array $events           = [];
	public string $status          = self::STATUS_ACTIVE;
	public ?int $last_response_code = null;
	public int $failure_count      = 0;
	public ?string $created_at     = null;
	public ?string $updated_at     = null;

	public static function from_row( array $row ): self {
		$w                     = new self();
		$w->id                 = isset( $row['id'] ) ? (int) $row['id'] : null;
		$w->endpoint_url       = (string) ( $row['endpoint_url'] ?? '' );
		$w->secret             = (string) ( $row['secret'] ?? '' );
		$w->events             = Helpers::decode_json_column( $row['events'] ?? null );
		$w->status             = (string) ( $row['status'] ?? self::STATUS_ACTIVE );
		$w->last_response_code = isset( $row['last_response_code'] ) ? (int) $row['last_response_code'] : null;
		$w->failure_count      = (int) ( $row['failure_count'] ?? 0 );
		$w->created_at         = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		$w->updated_at         = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null;
		return $w;
	}

	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'endpoint_url'       => $this->endpoint_url,
			'secret'             => $this->secret,
			'events'             => Helpers::encode_json_column( $this->events ),
			'status'             => $this->status,
			'last_response_code' => $this->last_response_code,
			'failure_count'      => $this->failure_count,
			'created_at'         => $this->created_at,
			'updated_at'         => $this->updated_at,
		];
	}

	public function subscribes_to( string $event ): bool {
		return in_array( $event, $this->events, true );
	}
}
