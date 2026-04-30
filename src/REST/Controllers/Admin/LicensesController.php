<?php
/**
 * Vendor admin: licenses CRUD + lifecycle (rotate, extend) + per-license activations.
 *
 *   GET    /admin/licenses
 *   POST   /admin/licenses                   (manual issuance — returns raw key once)
 *   GET    /admin/licenses/{id}
 *   PUT    /admin/licenses/{id}              (status, expires_at, tier, activation_limit)
 *   DELETE /admin/licenses/{id}              (hard delete; usually use status='revoked' instead)
 *   POST   /admin/licenses/{id}/rotate-key   (returns new raw key once)
 *   POST   /admin/licenses/{id}/extend       (body: period='1y' | '6m' | ...)
 *   GET    /admin/licenses/{id}/activations
 *   DELETE /admin/activations/{id}           (revoke one activation)
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use LicenseKit\Models\Activation;
use LicenseKit\Models\License;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\Services\LicenseService;
use LicenseKit\Support\Helpers;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class LicensesController extends AdminController {

	private LicenseService $svc;
	private LicenseRepository $licenses;
	private ActivationRepository $activations;
	private BearerTokenAuth $auth;

	public function __construct(
		LicenseService $svc,
		LicenseRepository $licenses,
		ActivationRepository $activations,
		BearerTokenAuth $auth
	) {
		$this->svc         = $svc;
		$this->licenses    = $licenses;
		$this->activations = $activations;
		$this->auth        = $auth;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/admin/licenses',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'index' ],
					'permission_callback' => $this->auth->permission( 'licenses.read' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => $this->auth->permission( 'licenses.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/licenses/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'show' ],
					'permission_callback' => $this->auth->permission( 'licenses.read' ),
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->auth->permission( 'licenses.write' ),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'destroy' ],
					'permission_callback' => $this->auth->permission( 'licenses.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/licenses/(?P<id>\d+)/rotate-key',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rotate' ],
				'permission_callback' => $this->auth->permission( 'licenses.write' ),
			]
		);
		register_rest_route(
			$namespace,
			'/admin/licenses/(?P<id>\d+)/extend',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'extend' ],
				'permission_callback' => $this->auth->permission( 'licenses.write' ),
			]
		);
		register_rest_route(
			$namespace,
			'/admin/licenses/(?P<id>\d+)/activations',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'activations' ],
				'permission_callback' => $this->auth->permission( 'licenses.read' ),
			]
		);
		register_rest_route(
			$namespace,
			'/admin/activations/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'revoke_activation' ],
				'permission_callback' => $this->auth->permission( 'licenses.write' ),
			]
		);
	}

	public function index( WP_REST_Request $req ): WP_REST_Response {
		$page       = $this->pagination( $req );
		$product_id = (int) ( $req->get_param( 'product_id' ) ?? 0 );
		$status     = (string) ( $req->get_param( 'status' ) ?? '' );

		if ( $product_id > 0 ) {
			$rows = $this->licenses->find_for_product( $product_id, '' !== $status ? $status : null, $page['per_page'], $page['offset'] );
		} elseif ( $email = (string) ( $req->get_param( 'customer_email' ) ?? '' ) ) {
			$rows = $this->licenses->find_by_customer_email( $email );
		} else {
			$rows = $this->licenses->find_for_product( 0, null, $page['per_page'], $page['offset'] );
			// Without a product filter, the find_for_product(0) returns nothing — fall back to a generic count_all dump.
			if ( empty( $rows ) ) {
				$rows = []; // For v1, require at least one filter.
			}
		}

		return $this->ok( array_map( [ $this, 'serialize' ], $rows ) );
	}

	public function show( WP_REST_Request $req ): WP_REST_Response {
		$license = $this->licenses->find( (int) $req->get_param( 'id' ) );
		if ( ! $license instanceof License ) {
			return $this->error( 'lk_not_found', __( 'License not found.', 'licensekit' ), 404 );
		}
		return $this->ok( $this->serialize( $license ) );
	}

	public function create( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params() ?: $req->get_params();

		$args = [
			'product_id'       => (int) ( $body['product_id'] ?? 0 ),
			'tier'             => (string) ( $body['tier'] ?? 'single' ),
			'activation_limit' => (int) ( $body['activation_limit'] ?? 1 ),
			'customer_id'      => isset( $body['customer_id'] ) ? (int) $body['customer_id'] : null,
			'customer_email'   => isset( $body['customer_email'] ) ? (string) $body['customer_email'] : null,
			'edd_order_id'     => isset( $body['edd_order_id'] ) ? (int) $body['edd_order_id'] : null,
			'expires_at'       => $body['expires_at'] ?? null,
			'renewal_period'   => $body['renewal_period'] ?? null,
			'meta'             => isset( $body['meta'] ) && is_array( $body['meta'] ) ? $body['meta'] : [],
		];

		$result = $this->svc->issue( $args );
		if ( empty( $result['success'] ) ) {
			$status = 'product_not_found' === ( $result['error'] ?? '' ) ? 404 : 400;
			return $this->error( 'lk_' . ( $result['error'] ?? 'invalid' ), $result['message'] ?? '', $status );
		}

		// Raw key shown ONCE. Caller is responsible for surfacing it to the user.
		return $this->created(
			[
				'license' => $this->serialize( $result['license'] ),
				'raw_key' => $result['raw_key'],
			]
		);
	}

	public function update( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$license = $this->licenses->find( $id );
		if ( ! $license instanceof License ) {
			return $this->error( 'lk_not_found', __( 'License not found.', 'licensekit' ), 404 );
		}

		$body = $req->get_json_params() ?: $req->get_params();

		// Status changes flow through the service so audit logs / webhooks fire.
		if ( isset( $body['status'] ) && $body['status'] !== $license->status ) {
			$grace = isset( $body['grace_until'] ) ? (string) $body['grace_until'] : null;
			$res   = $this->svc->set_status( $id, (string) $body['status'], $grace );
			if ( empty( $res['success'] ) ) {
				return $this->error( 'lk_invalid_status', $res['message'] ?? '', 400 );
			}
		}

		$changes = [ 'updated_at' => Helpers::now_utc() ];
		foreach ( [ 'tier', 'renewal_period' ] as $f ) {
			if ( array_key_exists( $f, $body ) ) {
				$changes[ $f ] = null === $body[ $f ] ? null : (string) $body[ $f ];
			}
		}
		foreach ( [ 'activation_limit' ] as $f ) {
			if ( array_key_exists( $f, $body ) ) {
				$changes[ $f ] = max( 0, (int) $body[ $f ] );
			}
		}
		if ( array_key_exists( 'expires_at', $body ) ) {
			$changes['expires_at'] = $body['expires_at'] ? (string) $body['expires_at'] : null;
		}
		if ( array_key_exists( 'meta', $body ) && is_array( $body['meta'] ) ) {
			$changes['meta'] = Helpers::encode_json_column( $body['meta'] );
		}

		$this->licenses->update( $id, $changes );
		return $this->ok( $this->serialize( $this->licenses->find( $id ) ) );
	}

	public function destroy( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! $this->licenses->exists( $id ) ) {
			return $this->error( 'lk_not_found', __( 'License not found.', 'licensekit' ), 404 );
		}
		$this->licenses->delete( $id );
		return $this->no_content();
	}

	public function rotate( WP_REST_Request $req ): WP_REST_Response {
		$id     = (int) $req->get_param( 'id' );
		$result = $this->svc->rotate_key( $id );
		if ( empty( $result['success'] ) ) {
			return $this->error( 'lk_not_found', $result['message'] ?? '', 404 );
		}
		return $this->ok( [ 'raw_key' => $result['raw_key'] ] );
	}

	public function extend( WP_REST_Request $req ): WP_REST_Response {
		$id     = (int) $req->get_param( 'id' );
		$body   = $req->get_json_params() ?: $req->get_params();
		$period = (string) ( $body['period'] ?? '' );
		if ( '' === $period ) {
			return $this->error( 'lk_validation', __( 'period is required (e.g. "1y").', 'licensekit' ), 400 );
		}
		$result = $this->svc->extend( $id, $period );
		if ( empty( $result['success'] ) ) {
			return $this->error( 'lk_invalid_period', $result['message'] ?? '', 400 );
		}
		return $this->ok( [ 'expires_at' => $result['expires_at'] ?? null ] );
	}

	public function activations( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! $this->licenses->exists( $id ) ) {
			return $this->error( 'lk_not_found', __( 'License not found.', 'licensekit' ), 404 );
		}
		$rows = $this->activations->find_for_license( $id );
		return $this->ok( array_map( [ $this, 'serialize_activation' ], $rows ) );
	}

	public function revoke_activation( WP_REST_Request $req ): WP_REST_Response {
		$id  = (int) $req->get_param( 'id' );
		$row = $this->activations->find( $id );
		if ( ! $row instanceof Activation ) {
			return $this->error( 'lk_not_found', __( 'Activation not found.', 'licensekit' ), 404 );
		}
		$this->activations->update(
			$id,
			[
				'status'       => Activation::STATUS_REVOKED,
				'last_seen_at' => Helpers::now_utc(),
			]
		);
		return $this->no_content();
	}

	private function serialize( ?License $l ): array {
		if ( ! $l instanceof License ) {
			return [];
		}
		return [
			'id'                => (int) $l->id,
			'key_prefix'        => $l->key_prefix,
			'customer_id'       => $l->customer_id,
			'customer_email'    => $l->customer_email,
			'product_id'        => $l->product_id,
			'edd_order_id'      => $l->edd_order_id,
			'tier'              => $l->tier,
			'activation_limit'  => $l->activation_limit,
			'status'            => $l->status,
			'issued_at'         => $l->issued_at,
			'expires_at'        => $l->expires_at,
			'grace_until'       => $l->grace_until,
			'renewal_period'    => $l->renewal_period,
			'parent_license_id' => $l->parent_license_id,
			'meta'              => $l->meta,
			'created_at'        => $l->created_at,
			'updated_at'        => $l->updated_at,
		];
	}

	private function serialize_activation( ?Activation $a ): array {
		if ( ! $a instanceof Activation ) {
			return [];
		}
		return [
			'id'               => (int) $a->id,
			'license_id'       => $a->license_id,
			'site_url'         => $a->site_url,
			'site_environment' => $a->site_environment,
			'activated_at'     => $a->activated_at,
			'last_seen_at'     => $a->last_seen_at,
			'status'           => $a->status,
			'meta'             => $a->meta,
		];
	}
}
