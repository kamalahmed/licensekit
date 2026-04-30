<?php
/**
 * Vendor admin: webhooks CRUD + test fire.
 *
 *   GET    /admin/webhooks
 *   POST   /admin/webhooks
 *   PUT    /admin/webhooks/{id}
 *   DELETE /admin/webhooks/{id}
 *   POST   /admin/webhooks/{id}/test
 *
 * Actual delivery (with retries) lives in WebhookDispatcher (Step 12). This
 * controller is just CRUD + a stub `test` that emits the `licensekit_webhook_test`
 * action so dispatcher can pick it up.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use LicenseKit\Models\Webhook;
use LicenseKit\Repositories\WebhookRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\Support\Helpers;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class WebhooksController extends AdminController {

	private WebhookRepository $repo;
	private BearerTokenAuth $auth;

	public function __construct( WebhookRepository $repo, BearerTokenAuth $auth ) {
		$this->repo = $repo;
		$this->auth = $auth;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/admin/webhooks',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'index' ],
					'permission_callback' => $this->auth->permission( 'webhooks.read' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => $this->auth->permission( 'webhooks.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/webhooks/(?P<id>\d+)',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => $this->auth->permission( 'webhooks.write' ),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'destroy' ],
					'permission_callback' => $this->auth->permission( 'webhooks.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/webhooks/(?P<id>\d+)/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test' ],
				'permission_callback' => $this->auth->permission( 'webhooks.write' ),
			]
		);
	}

	public function index(): WP_REST_Response {
		return $this->ok( array_map( [ $this, 'serialize' ], $this->repo->find_active() ) );
	}

	public function create( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params() ?: $req->get_params();

		$url    = esc_url_raw( (string) ( $body['endpoint_url'] ?? '' ) );
		$events = isset( $body['events'] ) && is_array( $body['events'] )
			? array_values( array_map( 'sanitize_key', $body['events'] ) )
			: [];

		if ( '' === $url || empty( $events ) ) {
			return $this->error( 'lk_validation', __( 'endpoint_url and at least one event are required.', 'licensekit' ), 400 );
		}

		$webhook               = new Webhook();
		$webhook->endpoint_url = $url;
		$webhook->secret       = ! empty( $body['secret'] ) ? (string) $body['secret'] : bin2hex( random_bytes( 16 ) );
		$webhook->events       = $events;
		$webhook->status       = Webhook::STATUS_ACTIVE;
		$webhook->created_at   = Helpers::now_utc();
		$webhook->updated_at   = Helpers::now_utc();

		$id = $this->repo->insert( $webhook );
		if ( $id <= 0 ) {
			return $this->error( 'lk_db_error', __( 'Could not create webhook.', 'licensekit' ), 500 );
		}
		$webhook->id = $id;

		return $this->created( $this->serialize( $webhook ) );
	}

	public function update( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$webhook = $this->repo->find( $id );
		if ( ! $webhook instanceof Webhook ) {
			return $this->error( 'lk_not_found', __( 'Webhook not found.', 'licensekit' ), 404 );
		}

		$body    = $req->get_json_params() ?: $req->get_params();
		$changes = [ 'updated_at' => Helpers::now_utc() ];

		if ( isset( $body['endpoint_url'] ) ) {
			$changes['endpoint_url'] = esc_url_raw( (string) $body['endpoint_url'] );
		}
		if ( isset( $body['events'] ) && is_array( $body['events'] ) ) {
			$changes['events'] = Helpers::encode_json_column( array_values( array_map( 'sanitize_key', $body['events'] ) ) );
		}
		if ( isset( $body['status'] ) ) {
			$status = (string) $body['status'];
			if ( in_array( $status, [ Webhook::STATUS_ACTIVE, Webhook::STATUS_PAUSED ], true ) ) {
				$changes['status'] = $status;
			}
		}

		$this->repo->update( $id, $changes );
		return $this->ok( $this->serialize( $this->repo->find( $id ) ) );
	}

	public function destroy( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! $this->repo->exists( $id ) ) {
			return $this->error( 'lk_not_found', __( 'Webhook not found.', 'licensekit' ), 404 );
		}
		$this->repo->delete( $id );
		return $this->no_content();
	}

	public function test( WP_REST_Request $req ): WP_REST_Response {
		$id      = (int) $req->get_param( 'id' );
		$webhook = $this->repo->find( $id );
		if ( ! $webhook instanceof Webhook ) {
			return $this->error( 'lk_not_found', __( 'Webhook not found.', 'licensekit' ), 404 );
		}

		// Dispatcher (Step 12) listens for this and enqueues a test delivery.
		do_action( 'licensekit_webhook_test', $webhook );

		return $this->ok( [ 'status' => 'queued' ] );
	}

	private function serialize( ?Webhook $w ): array {
		if ( ! $w instanceof Webhook ) {
			return [];
		}
		return [
			'id'                 => (int) $w->id,
			'endpoint_url'       => $w->endpoint_url,
			'events'             => $w->events,
			'status'             => $w->status,
			'last_response_code' => $w->last_response_code,
			'failure_count'      => $w->failure_count,
			'created_at'         => $w->created_at,
			'updated_at'         => $w->updated_at,
			// Note: `secret` is intentionally omitted from list responses.
		];
	}
}
