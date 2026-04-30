<?php
/**
 * Product repository.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Repositories;

use LicenseKit\Models\Product;
use LicenseKit\Schema\Tables\Products;

defined( 'ABSPATH' ) || exit;

class ProductRepository extends Repository {

	protected function table_name_unprefixed(): string {
		return Products::name();
	}

	protected function model_class(): string {
		return Product::class;
	}

	public function find_by_slug( string $slug ): ?Product {
		/** @var Product|null */
		return $this->find_one_where( 'slug = %s', [ $slug ] );
	}

	public function find_by_edd_download_id( int $download_id ): ?Product {
		/** @var Product|null */
		return $this->find_one_where( 'edd_download_id = %d', [ $download_id ] );
	}

	public function find_by_wc_product_id( int $wc_product_id ): ?Product {
		/** @var Product|null */
		return $this->find_one_where( 'wc_product_id = %d', [ $wc_product_id ] );
	}

	/**
	 * @return Product[]
	 */
	public function find_by_type( string $type, int $limit = 100, int $offset = 0 ): array {
		return $this->find_many_where( 'type = %s', [ $type ], 'name ASC', $limit, $offset );
	}

	/**
	 * @return Product[]
	 */
	public function find_all( int $limit = 100, int $offset = 0 ): array {
		return $this->find_many_where( '1 = %d', [ 1 ], 'name ASC', $limit, $offset );
	}
}
