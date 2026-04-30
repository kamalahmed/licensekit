<?php
/**
 * Webhook dispatcher — listens for license/release domain events, fans them
 * out to subscribed webhooks, and (when Action Scheduler is present) handles
 * async retries with exponential backoff.
 *
 * Action Scheduler ships with EDD and WooCommerce, so it's available on every
 * site that runs LicenseKit's primary integration target. Without it we fall
 * back to synchronous `wp_remote_post` — slower and no retry, but works.
 *
 * Outbound payload is HMAC-SHA256 signed using the per-webhook `secret` and
 * sent in `X-LicenseKit-Signature: sha256=…` header for receivers to verify.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Services;

use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Models\Release;
use LicenseKit\Models\Webhook;
use LicenseKit\Models\WebhookDelivery;
use LicenseKit\Repositories\WebhookDeliveryRepository;
use LicenseKit\Repositories\WebhookRepository;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class WebhookDispatcher {

	public const DELIVER_HOOK = 'licensekit_webhook_deliver';
	public const MAX_ATTEMPTS = 5;

	private WebhookRepository $webhooks;
	private WebhookDeliveryRepository $deliveries;

	public function __construct( WebhookRepository $webhooks, WebhookDeliveryRepository $deliveries ) {
		$this->webhooks   = $webhooks;
		$this->deliveries = $deliveries;
	}

	public static function make(): self {
		return new self( new WebhookRepository(), new WebhookDeliveryRepository() );
	}

	public function register(): void {
		// Fan-out: domain events → enqueue per subscriber.
		add_action( 'licensekit_license_issued', [ $this, 'on_license_issued' ], 10, 3 );
		add_action( 'licensekit_license_activated', [ $this, 'on_license_activated' ], 10, 4 );
		add_action( 'licensekit_license_deactivated', [ $this, 'on_license_deactivated' ], 10, 2 );
		add_action( 'licensekit_license_rotated', [ $this, 'on_license_rotated' ], 10, 2 );
		add_action( 'licensekit_license_extended', [ $this, 'on_license_extended' ], 10, 2 );
		add_action( 'licensekit_license_status_changed', [ $this, 'on_license_status_changed' ], 10, 3 );
		add_action( 'licensekit_release_created', [ $this, 'on_release_created' ], 10, 2 );
		add_action( 'licensekit_release_deleted', [ $this, 'on_release_deleted' ], 10 );
		add_action( 'licensekit_webhook_test', [ $this, 'on_webhook_test' ] );

		// Worker: actually POST.
		add_action( self::DELIVER_HOOK, [ $this, 'deliver' ], 10, 3 );
	}

	// ---------------------------------------------------------------
	// Event handlers — convert domain events to webhook payloads
	// ---------------------------------------------------------------

	public function on_license_issued( License $license, Product $product, string $raw_key ): void {
		// Note: raw_key is intentionally NOT included in the webhook payload — receivers
		// are remote and shouldn't get the plaintext key. They can fetch via REST if needed.
		$this->fan_out( 'license.issued', $this->summarize_license( $license, $product ) );
	}

	public function on_license_activated( License $license, Product $product, string $site_url, string $environment ): void {
		$payload                = $this->summarize_license( $license, $product );
		$payload['site_url']    = $site_url;
		$payload['environment'] = $environment;
		$this->fan_out( 'license.activated', $payload );
	}

	public function on_license_deactivated( License $license, string $site_url ): void {
		$this->fan_out( 'license.deactivated', [
			'license_id' => (int) $license->id,
			'key_prefix' => $license->key_prefix,
			'site_url'   => $site_url,
		] );
	}

	public function on_license_rotated( License $license, string $new_raw_key ): void {
		$this->fan_out( 'license.rotated', [
			'license_id' => (int) $license->id,
			'key_prefix' => $license->key_prefix, // pre-rotation prefix
		] );
	}

	public function on_license_extended( int $license_id, string $new_expires_at ): void {
		$this->fan_out( 'license.extended', [
			'license_id' => $license_id,
			'expires_at' => $new_expires_at,
		] );
	}

	public function on_license_status_changed( int $license_id, string $to, string $from ): void {
		$this->fan_out( 'license.status_changed', [
			'license_id' => $license_id,
			'from'       => $from,
			'to'         => $to,
		] );
	}

	public function on_release_created( Release $release, Product $product ): void {
		$this->fan_out( 'release.created', $this->summarize_release( $release, $product ) );
	}

	public function on_release_deleted( Release $release ): void {
		$this->fan_out( 'release.deleted', [
			'release_id' => (int) $release->id,
			'product_id' => (int) $release->product_id,
			'version'    => $release->version,
		] );
	}

	public function on_webhook_test( Webhook $webhook ): void {
		$this->enqueue_delivery(
			$webhook,
			'webhook.test',
			[ 'message' => 'LicenseKit webhook test event', 'timestamp' => Helpers::now_utc() ]
		);
	}

	// ---------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------

	private function fan_out( string $event, array $payload ): void {
		$subscribers = $this->webhooks->find_subscribers_to( $event );
		foreach ( $subscribers as $webhook ) {
			$this->enqueue_delivery( $webhook, $event, $payload );
		}
	}

	private function enqueue_delivery( Webhook $webhook, string $event, array $payload ): void {
		$delivery               = new WebhookDelivery();
		$delivery->webhook_id   = (int) $webhook->id;
		$delivery->event        = $event;
		$delivery->payload      = (string) wp_json_encode( $payload );
		$delivery->attempt      = 1;
		$delivery->created_at   = Helpers::now_utc();
		$delivery_id            = $this->deliveries->insert( $delivery );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::DELIVER_HOOK,
				[ (int) $webhook->id, $delivery_id, $event ],
				'licensekit'
			);
			return;
		}

		// Synchronous fallback.
		$this->deliver( (int) $webhook->id, $delivery_id, $event );
	}

	/**
	 * Action Scheduler worker callback. Idempotent on retry — schedules its own
	 * next attempt with exponential backoff up to MAX_ATTEMPTS.
	 */
	public function deliver( int $webhook_id, int $delivery_id, string $event ): void {
		$webhook = $this->webhooks->find( $webhook_id );
		if ( ! $webhook instanceof Webhook || Webhook::STATUS_ACTIVE !== $webhook->status ) {
			return;
		}

		$delivery = $this->deliveries->find( $delivery_id );
		if ( ! $delivery instanceof WebhookDelivery || null !== $delivery->delivered_at ) {
			return;
		}

		$body      = (string) ( $delivery->payload ?? '{}' );
		$signature = 'sha256=' . hash_hmac( 'sha256', $body, $webhook->secret );

		$response = wp_remote_post(
			$webhook->endpoint_url,
			[
				'timeout' => 10,
				'headers' => [
					'Content-Type'             => 'application/json',
					'X-LicenseKit-Signature'   => $signature,
					'X-LicenseKit-Event'       => $event,
					'X-LicenseKit-Delivery'    => (string) $delivery_id,
					'User-Agent'               => 'LicenseKit/' . ( defined( 'LICENSEKIT_VERSION' ) ? LICENSEKIT_VERSION : '0.1.0' ),
				],
				'body'    => $body,
			]
		);

		$success = false;
		$code    = null;
		$resp_body = '';

		if ( is_wp_error( $response ) ) {
			$resp_body = $response->get_error_message();
		} else {
			$code      = (int) wp_remote_retrieve_response_code( $response );
			$resp_body = (string) wp_remote_retrieve_body( $response );
			$success   = $code >= 200 && $code < 300;
		}

		$this->webhooks->record_delivery_result( $webhook_id, $code, $success );
		$this->deliveries->update(
			$delivery_id,
			[
				'response_code' => $code,
				'response_body' => substr( $resp_body, 0, 5000 ),
				'delivered_at'  => $success ? Helpers::now_utc() : null,
			]
		);

		if ( $success || $delivery->attempt >= self::MAX_ATTEMPTS ) {
			return;
		}

		// Retry with exponential backoff: 1m, 5m, 15m, 60m.
		$backoff_seconds = [ 60, 300, 900, 3600, 7200 ][ min( $delivery->attempt - 1, 4 ) ];
		$next_attempt    = (int) $delivery->attempt + 1;
		$next_retry      = gmdate( 'Y-m-d H:i:s', time() + $backoff_seconds );

		$retry               = new WebhookDelivery();
		$retry->webhook_id   = $webhook_id;
		$retry->event        = $event;
		$retry->payload      = $body;
		$retry->attempt      = $next_attempt;
		$retry->next_retry_at = $next_retry;
		$retry->created_at   = Helpers::now_utc();
		$retry_id            = $this->deliveries->insert( $retry );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $backoff_seconds,
				self::DELIVER_HOOK,
				[ $webhook_id, $retry_id, $event ],
				'licensekit'
			);
		}
	}

	private function summarize_license( License $license, Product $product ): array {
		return [
			'license_id'       => (int) $license->id,
			'key_prefix'       => $license->key_prefix,
			'product_slug'     => $product->slug,
			'customer_email'   => $license->customer_email,
			'tier'             => $license->tier,
			'activation_limit' => $license->activation_limit,
			'status'           => $license->status,
			'expires_at'       => $license->expires_at,
		];
	}

	private function summarize_release( Release $release, Product $product ): array {
		return [
			'release_id'    => (int) $release->id,
			'product_id'    => (int) $product->id,
			'product_slug'  => $product->slug,
			'version'       => $release->version,
			'channel'       => $release->channel,
			'file_size'     => $release->file_size,
			'sha256'        => $release->file_hash,
			'released_at'   => $release->released_at,
		];
	}
}
