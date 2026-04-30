<?php
/**
 * Vendor admin: products CRUD.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use LicenseKit\Models\Product;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\Support\Helpers;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class ProductsController extends AdminController {

	private ProductRepository $repo;
	private BearerTokenAuth $auth;

	public function __construct( ProductRepository $repo, BearerTokenAuth $auth ) {
		$this->repo = $repo;
		$this->auth = $auth;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/admin/products',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'index' ],
					'permission_callback' => $this->auth->permission( 'products.read' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => $this->auth->permission( 'products.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/products/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'show' ],
					'permission_callback' => $this->auth->permission( 'products.read' ),
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->auth->permission( 'products.write' ),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'destroy' ],
					'permission_callback' => $this->auth->permission( 'products.write' ),
				],
			]
		);
	}

	public function index( WP_REST_Request $req ): WP_REST_Response {
		$page = $this->pagination( $req );
		$type = (string) ( $req->get_param( 'type' ) ?? '' );

		$items = '' !== $type
			? $this->repo->find_by_type( $type, $page['per_page'], $page['offset'] )
			: $this->repo->find_all( $page['per_page'], $page['offset'] );

		// `count_all` is fine for v1; per-filter counts are added later if needed.
		$total = $this->repo->count_all();

		return $this->paged_response(
			array_map( [ $this, 'serialize' ], $items ),
			$total,
			$page['per_page']
		);
	}

	public function show( WP_REST_Request $req ): WP_REST_Response {
		$product = $this->repo->find( (int) $req->get_param( 'id' ) );
		if ( ! $product instanceof Product ) {
			return $this->error( 'lk_not_found', __( 'Product not found.', 'licensekit' ), 404 );
		}
		return $this->ok( $this->serialize( $product ) );
	}

	public function create( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params() ?: $req->get_params();

		$slug = sanitize_title( (string) ( $body['slug'] ?? '' ) );
		$name = trim( (string) ( $body['name'] ?? '' ) );
		$type = (string) ( $body['type'] ?? 'plugin' );

		if ( '' === $slug || '' === $name ) {
			return $this->error( 'lk_validation', __( 'slug and name are required.', 'licensekit' ), 400 );
		}
		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			return $this->error( 'lk_validation', __( 'type must be "plugin" or "theme".', 'licensekit' ), 400 );
		}

		if ( $this->repo->find_by_slug( $slug ) instanceof Product ) {
			return $this->error( 'lk_duplicate_slug', __( 'A product with that slug already exists.', 'licensekit' ), 409 );
		}

		$product                  = new Product();
		$product->slug            = $slug;
		$product->name            = $name;
		$product->type            = $type;
		$product->edd_download_id = isset( $body['edd_download_id'] ) ? (int) $body['edd_download_id'] : null;
		$product->author          = isset( $body['author'] ) ? (string) $body['author'] : null;
		$product->homepage_url    = isset( $body['homepage_url'] ) ? esc_url_raw( (string) $body['homepage_url'] ) : null;
		$product->meta            = isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : [];
		$product->created_at      = Helpers::now_utc();
		$product->updated_at      = Helpers::now_utc();

		$id = $this->repo->insert( $product );
		if ( $id <= 0 ) {
			return $this->error( 'lk_db_error', __( 'Could not create product.', 'licensekit' ), 500 );
		}
		$product->id = $id;

		return $this->created( $this->serialize( $product ) );
	}

	public function update( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$product = $this->repo->find( $id );
		if ( ! $product instanceof Product ) {
			return $this->error( 'lk_not_found', __( 'Product not found.', 'licensekit' ), 404 );
		}

		$body    = $req->get_json_params() ?: $req->get_params();
		$changes = [ 'updated_at' => Helpers::now_utc() ];

		foreach ( [ 'name', 'author', 'homepage_url', 'edd_download_id' ] as $f ) {
			if ( array_key_exists( $f, $body ) ) {
				$changes[ $f ] = 'edd_download_id' === $f
					? ( null === $body[ $f ] ? null : (int) $body[ $f ] )
					: ( null === $body[ $f ] ? null : (string) $body[ $f ] );
			}
		}
		if ( array_key_exists( 'meta', $body ) && is_array( $body['meta'] ) ) {
			$changes['meta'] = Helpers::encode_json_column( $body['meta'] );
		}

		$this->repo->update( $id, $changes );
		return $this->ok( $this->serialize( $this->repo->find( $id ) ) );
	}

	public function destroy( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! $this->repo->exists( $id ) ) {
			return $this->error( 'lk_not_found', __( 'Product not found.', 'licensekit' ), 404 );
		}
		$this->repo->delete( $id );
		return $this->no_content();
	}

	private function serialize( ?Product $p ): array {
		if ( ! $p instanceof Product ) {
			return [];
		}
		return [
			'id'                 => (int) $p->id,
			'slug'               => $p->slug,
			'name'               => $p->name,
			'type'               => $p->type,
			'edd_download_id'    => $p->edd_download_id,
			'current_version'    => $p->current_version,
			'current_release_id' => $p->current_release_id,
			'homepage_url'       => $p->homepage_url,
			'author'             => $p->author,
			'meta'               => $p->meta,
			'created_at'         => $p->created_at,
			'updated_at'         => $p->updated_at,
		];
	}
}
