<?php
/**
 * Webhook delivery repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\WebhookDelivery;
use LicenseKit\Schema\Tables\WebhookDeliveries;

defined( 'ABSPATH' ) || exit;

class WebhookDeliveryRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return WebhookDeliveries::name();
	}

	protected function model_class(): string {
		return WebhookDelivery::class;
	}

	/**
	 * @return WebhookDelivery[]
	 */
	public function find_for_webhook( int $webhook_id, int $limit = 50 ): array {
		return $this->find_many_where(
			'webhook_id = %d',
			[ $webhook_id ],
			'created_at DESC, id DESC',
			$limit
		);
	}

	/**
	 * @return WebhookDelivery[]
	 */
	public function find_pending_retries( string $now_utc, int $limit = 50 ): array {
		return $this->find_many_where(
			'delivered_at IS NULL AND next_retry_at IS NOT NULL AND next_retry_at <= %s',
			[ $now_utc ],
			'next_retry_at ASC',
			$limit
		);
	}
}
