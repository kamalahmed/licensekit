<?php
/**
 * Release repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\Release;
use LicenseKit\Schema\Tables\Releases;

defined( 'ABSPATH' ) || exit;

class ReleaseRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return Releases::name();
	}

	protected function model_class(): string {
		return Release::class;
	}

	public function find_by_product_and_version( int $product_id, string $version ): ?Release {
		/** @var Release|null */
		return $this->find_one_where(
			'product_id = %d AND version = %s',
			[ $product_id, $version ]
		);
	}

	/**
	 * @return Release[]
	 */
	public function find_for_product( int $product_id, ?string $channel = null ): array {
		if ( null === $channel ) {
			return $this->find_many_where(
				'product_id = %d',
				[ $product_id ],
				'released_at DESC, id DESC'
			);
		}
		return $this->find_many_where(
			'product_id = %d AND channel = %s',
			[ $product_id, $channel ],
			'released_at DESC, id DESC'
		);
	}

	public function find_latest_for_product( int $product_id, string $channel = 'stable' ): ?Release {
		/** @var Release|null */
		return $this->find_one_where(
			'product_id = %d AND channel = %s',
			[ $product_id, $channel ],
			'released_at DESC, id DESC'
		);
	}
}
