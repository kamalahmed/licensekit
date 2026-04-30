<?php
/**
 * Vendor admin: releases CRUD with multipart zip upload.
 *
 *   POST /admin/products/{id}/releases   (multipart: file=<zip>, version, channel, ...)
 *   GET  /admin/products/{id}/releases
 *   GET  /admin/releases/{id}
 *   PUT  /admin/releases/{id}
 *   DELETE /admin/releases/{id}
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use LicenseKit\Models\Product;
use LicenseKit\Models\Release;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\Services\ReleaseService;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class ReleasesController extends AdminController {

	private ReleaseService $svc;
	private ReleaseRepository $repo;
	private ProductRepository $products;
	private BearerTokenAuth $auth;

	public function __construct(
		ReleaseService $svc,
		ReleaseRepository $repo,
		ProductRepository $products,
		BearerTokenAuth $auth
	) {
		$this->svc      = $svc;
		$this->repo     = $repo;
		$this->products = $products;
		$this->auth     = $auth;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/admin/products/(?P<product_id>\d+)/releases',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'index' ],
					'permission_callback' => $this->auth->permission( 'releases.read' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => $this->auth->permission( 'releases.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/releases/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'show' ],
					'permission_callback' => $this->auth->permission( 'releases.read' ),
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->auth->permission( 'releases.write' ),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'destroy' ],
					'permission_callback' => $this->auth->permission( 'releases.write' ),
				],
			]
		);
	}

	public function index( WP_REST_Request $req ): WP_REST_Response {
		$product_id = (int) $req->get_param( 'product_id' );
		if ( ! $this->products->exists( $product_id ) ) {
			return $this->error( 'lk_not_found', __( 'Product not found.', 'licensekit' ), 404 );
		}
		$channel = (string) ( $req->get_param( 'channel' ) ?? '' );
		$rows    = '' !== $channel
			? $this->repo->find_for_product( $product_id, $channel )
			: $this->repo->find_for_product( $product_id );

		return $this->ok( array_map( [ $this, 'serialize' ], $rows ) );
	}

	public function show( WP_REST_Request $req ): WP_REST_Response {
		$release = $this->repo->find( (int) $req->get_param( 'id' ) );
		if ( ! $release instanceof Release ) {
			return $this->error( 'lk_not_found', __( 'Release not found.', 'licensekit' ), 404 );
		}
		return $this->ok( $this->serialize( $release ) );
	}

	public function create( WP_REST_Request $req ): WP_REST_Response {
		$product_id = (int) $req->get_param( 'product_id' );
		if ( ! $this->products->find( $product_id ) instanceof Product ) {
			return $this->error( 'lk_not_found', __( 'Product not found.', 'licensekit' ), 404 );
		}

		$files = $req->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) ) {
			return $this->error( 'lk_missing_file', __( 'Send the zip in the `file` multipart field.', 'licensekit' ), 400 );
		}

		$args = [
			'product_id'   => $product_id,
			'version'      => (string) ( $req->get_param( 'version' ) ?? '' ),
			'channel'      => (string) ( $req->get_param( 'channel' ) ?? 'stable' ),
			'changelog_md' => (string) ( $req->get_param( 'changelog_md' ) ?? '' ),
			'requires_wp'  => (string) ( $req->get_param( 'requires_wp' ) ?? '' ),
			'requires_php' => (string) ( $req->get_param( 'requires_php' ) ?? '' ),
			'tested_up_to' => (string) ( $req->get_param( 'tested_up_to' ) ?? '' ),
			'source_path'  => (string) $files['file']['tmp_name'],
			'created_by'   => (int) ( $this->actor_id( $req ) ?? 0 ),
		];

		$result = $this->svc->create( $args );
		if ( empty( $result['success'] ) ) {
			$status = 'duplicate_version' === ( $result['error'] ?? '' ) ? 409 : 400;
			return $this->error( 'lk_' . ( $result['error'] ?? 'invalid' ), $result['message'] ?? '', $status );
		}

		return $this->created( $this->serialize( $result['release'] ) );
	}

	public function update( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$release = $this->repo->find( $id );
		if ( ! $release instanceof Release ) {
			return $this->error( 'lk_not_found', __( 'Release not found.', 'licensekit' ), 404 );
		}

		$body = $req->get_json_params() ?: $req->get_params();

		// Channel changes flow through the service so the product's current_release pointer is recomputed.
		if ( isset( $body['channel'] ) && $body['channel'] !== $release->channel ) {
			$result = $this->svc->set_channel( $id, (string) $body['channel'] );
			if ( empty( $result['success'] ) ) {
				return $this->error( 'lk_invalid_channel', $result['message'] ?? '', 400 );
			}
		}

		$changes = [];
		foreach ( [ 'changelog_md', 'requires_wp', 'requires_php', 'tested_up_to' ] as $f ) {
			if ( array_key_exists( $f, $body ) ) {
				$changes[ $f ] = null === $body[ $f ] ? null : (string) $body[ $f ];
			}
		}
		if ( ! empty( $changes ) ) {
			$this->repo->update( $id, $changes );
		}

		return $this->ok( $this->serialize( $this->repo->find( $id ) ) );
	}

	public function destroy( WP_REST_Request $req ): WP_REST_Response {
		$id     = (int) $req->get_param( 'id' );
		$result = $this->svc->delete_release( $id );
		if ( empty( $result['success'] ) ) {
			return $this->error( 'lk_not_found', $result['message'] ?? '', 404 );
		}
		return $this->no_content();
	}

	private function serialize( ?Release $r ): array {
		if ( ! $r instanceof Release ) {
			return [];
		}
		return [
			'id'           => (int) $r->id,
			'product_id'   => $r->product_id,
			'version'      => $r->version,
			'channel'      => $r->channel,
			'file_path'    => $r->file_path,
			'file_size'    => $r->file_size,
			'file_hash'    => $r->file_hash,
			'changelog_md' => $r->changelog_md,
			'requires_wp'  => $r->requires_wp,
			'requires_php' => $r->requires_php,
			'tested_up_to' => $r->tested_up_to,
			'released_at'  => $r->released_at,
			'created_at'   => $r->created_at,
			'created_by'   => $r->created_by,
		];
	}

	private function actor_id( WP_REST_Request $req ): ?int {
		$token = $req->get_param( '_lk_token' );
		return ( is_object( $token ) && isset( $token->user_id ) ) ? (int) $token->user_id : null;
	}
}
