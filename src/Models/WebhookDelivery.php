<?php
/**
 * Webhook delivery DTO — Action Scheduler-driven retry log.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

defined( 'ABSPATH' ) || exit;

final class WebhookDelivery {

	public ?int $id              = null;
	public int $webhook_id       = 0;
	public string $event         = '';
	public ?string $payload      = null;
	public ?int $response_code   = null;
	public ?string $response_body = null;
	public int $attempt          = 1;
	public ?string $next_retry_at = null;
	public ?string $delivered_at = null;
	public ?string $created_at   = null;

	public static function from_row( array $row ): self {
		$d                = new self();
		$d->id            = isset( $row['id'] ) ? (int) $row['id'] : null;
		$d->webhook_id    = (int) ( $row['webhook_id'] ?? 0 );
		$d->event         = (string) ( $row['event'] ?? '' );
		$d->payload       = isset( $row['payload'] ) ? (string) $row['payload'] : null;
		$d->response_code = isset( $row['response_code'] ) ? (int) $row['response_code'] : null;
		$d->response_body = isset( $row['response_body'] ) ? (string) $row['response_body'] : null;
		$d->attempt       = (int) ( $row['attempt'] ?? 1 );
		$d->next_retry_at = isset( $row['next_retry_at'] ) ? (string) $row['next_retry_at'] : null;
		$d->delivered_at  = isset( $row['delivered_at'] ) ? (string) $row['delivered_at'] : null;
		$d->created_at    = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		return $d;
	}

	public function to_array(): array {
		return [
			'id'            => $this->id,
			'webhook_id'    => $this->webhook_id,
			'event'         => $this->event,
			'payload'       => $this->payload,
			'response_code' => $this->response_code,
			'response_body' => $this->response_body,
			'attempt'       => $this->attempt,
			'next_retry_at' => $this->next_retry_at,
			'delivered_at'  => $this->delivered_at,
			'created_at'    => $this->created_at,
		];
	}
}
