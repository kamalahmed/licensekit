<?php
/**
 * License repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\License;
use LicenseKit\Schema\Tables\Licenses;

defined( 'ABSPATH' ) || exit;

class LicenseRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return Licenses::name();
	}

	protected function model_class(): string {
		return License::class;
	}

	public function find_by_key_hash( string $key_hash ): ?License {
		/** @var License|null */
		return $this->find_one_where( 'key_hash = %s', [ $key_hash ] );
	}

	/**
	 * @return License[]
	 */
	public function find_by_customer_id( int $customer_id ): array {
		return $this->find_many_where(
			'customer_id = %d',
			[ $customer_id ],
			'created_at DESC, id DESC'
		);
	}

	/**
	 * @return License[]
	 */
	public function find_by_customer_email( string $email ): array {
		return $this->find_many_where(
			'customer_email = %s',
			[ $email ],
			'created_at DESC, id DESC'
		);
	}

	/**
	 * @return License[]
	 */
	public function find_by_edd_order_id( int $order_id ): array {
		return $this->find_many_where(
			'edd_order_id = %d',
			[ $order_id ],
			'id ASC'
		);
	}

	/**
	 * Licenses that expire before the given UTC datetime and are still active.
	 *
	 * @return License[]
	 */
	public function find_expiring_before( string $datetime_utc, int $limit = 100 ): array {
		return $this->find_many_where(
			'status = %s AND expires_at IS NOT NULL AND expires_at < %s',
			[ License::STATUS_ACTIVE, $datetime_utc ],
			'expires_at ASC',
			$limit
		);
	}

	/**
	 * @return License[]
	 */
	public function find_for_product( int $product_id, ?string $status = null, int $limit = 100, int $offset = 0 ): array {
		if ( null === $status ) {
			return $this->find_many_where(
				'product_id = %d',
				[ $product_id ],
				'created_at DESC, id DESC',
				$limit,
				$offset
			);
		}
		return $this->find_many_where(
			'product_id = %d AND status = %s',
			[ $product_id, $status ],
			'created_at DESC, id DESC',
			$limit,
			$offset
		);
	}
}
