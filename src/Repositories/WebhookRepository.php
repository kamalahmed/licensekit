<?php
/**
 * Webhook repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\Webhook;
use LicenseKit\Schema\Tables\Webhooks;

defined( 'ABSPATH' ) || exit;

class WebhookRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return Webhooks::name();
	}

	protected function model_class(): string {
		return Webhook::class;
	}

	/**
	 * @return Webhook[]
	 */
	public function find_active(): array {
		return $this->find_many_where(
			'status = %s',
			[ Webhook::STATUS_ACTIVE ],
			'id ASC'
		);
	}

	/**
	 * Find all active webhooks subscribed to the given event. Filters in PHP because
	 * the events column is JSON; expected cardinality is small (operators rarely have >50 webhooks).
	 *
	 * @return Webhook[]
	 */
	public function find_subscribers_to( string $event ): array {
		return array_values(
			array_filter(
				$this->find_active(),
				static fn( Webhook $w ) => $w->subscribes_to( $event )
			)
		);
	}

	public function record_delivery_result( int $id, ?int $response_code, bool $success ): bool {
		$row = $this->find( $id );
		if ( ! $row instanceof Webhook ) {
			return false;
		}
		return $this->update(
			$id,
			[
				'last_response_code' => $response_code,
				'failure_count'      => $success ? 0 : $row->failure_count + 1,
			]
		);
	}
}
