<?php
/**
 * Vendor admin: API token CRUD.
 *
 *   GET    /admin/tokens
 *   POST   /admin/tokens                (returns full token ONCE)
 *   DELETE /admin/tokens/{id}           (soft revoke — sets revoked_at)
 *
 * Self-bootstrap caveat: a vendor needs a token to manage tokens. Issue the
 * first one via the Settings → Tokens admin UI (Step 11), not this endpoint.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use LicenseKit\Models\ApiToken;
use LicenseKit\Repositories\ApiTokenRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\Support\Helpers;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class TokensController extends AdminController {

	private ApiTokenRepository $repo;
	private BearerTokenAuth $auth;

	public function __construct( ApiTokenRepository $repo, BearerTokenAuth $auth ) {
		$this->repo = $repo;
		$this->auth = $auth;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/admin/tokens',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'index' ],
					'permission_callback' => $this->auth->permission( 'tokens.read' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => $this->auth->permission( 'tokens.write' ),
				],
			]
		);
		register_rest_route(
			$namespace,
			'/admin/tokens/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'destroy' ],
				'permission_callback' => $this->auth->permission( 'tokens.write' ),
			]
		);
	}

	public function index( WP_REST_Request $req ): WP_REST_Response {
		$current = $req->get_param( '_lk_token' );
		$user_id = is_object( $current ) && isset( $current->user_id ) ? (int) $current->user_id : 0;
		$rows    = $user_id > 0 ? $this->repo->find_for_user( $user_id ) : [];
		return $this->ok( array_map( [ $this, 'serialize' ], $rows ) );
	}

	public function create( WP_REST_Request $req ): WP_REST_Response {
		$body = $req->get_json_params() ?: $req->get_params();

		$name = trim( (string) ( $body['name'] ?? '' ) );
		if ( '' === $name ) {
			return $this->error( 'lk_validation', __( 'name is required.', 'licensekit' ), 400 );
		}

		$abilities = isset( $body['abilities'] ) && is_array( $body['abilities'] )
			? array_values( array_map( 'sanitize_key', $body['abilities'] ) )
			: [ '*' ];

		$current = $req->get_param( '_lk_token' );
		$user_id = is_object( $current ) && isset( $current->user_id ) ? (int) $current->user_id : 0;
		if ( $user_id <= 0 ) {
			return $this->error( 'lk_unauthenticated', __( 'Token is missing user binding.', 'licensekit' ), 401 );
		}

		$minted = BearerTokenAuth::mint();

		$token             = new ApiToken();
		$token->user_id    = $user_id;
		$token->token_hash = $minted['hash'];
		$token->token_prefix = $minted['prefix'];
		$token->name       = $name;
		$token->abilities  = $abilities;
		$token->created_at = Helpers::now_utc();
		if ( ! empty( $body['expires_at'] ) ) {
			$token->expires_at = (string) $body['expires_at'];
		}

		$id = $this->repo->insert( $token );
		if ( $id <= 0 ) {
			return $this->error( 'lk_db_error', __( 'Could not create token.', 'licensekit' ), 500 );
		}
		$token->id = $id;

		return $this->created(
			[
				'token'    => $this->serialize( $token ),
				'full_key' => $minted['full'], // SHOWN ONCE — vendor must store it.
			]
		);
	}

	public function destroy( WP_REST_Request $req ): WP_REST_Response {
		$id  = (int) $req->get_param( 'id' );
		$row = $this->repo->find( $id );
		if ( ! $row instanceof ApiToken ) {
			return $this->error( 'lk_not_found', __( 'Token not found.', 'licensekit' ), 404 );
		}
		// Soft revoke — preserves audit trail.
		$this->repo->update( $id, [ 'revoked_at' => Helpers::now_utc() ] );
		return $this->no_content();
	}

	private function serialize( ?ApiToken $t ): array {
		if ( ! $t instanceof ApiToken ) {
			return [];
		}
		return [
			'id'           => (int) $t->id,
			'name'         => $t->name,
			'token_prefix' => $t->token_prefix,
			'abilities'    => $t->abilities,
			'last_used_at' => $t->last_used_at,
			'expires_at'   => $t->expires_at,
			'revoked_at'   => $t->revoked_at,
			'created_at'   => $t->created_at,
		];
	}
}
