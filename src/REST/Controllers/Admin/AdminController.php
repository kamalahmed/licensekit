<?php
/**
 * Base class for vendor admin REST controllers — shared response helpers,
 * pagination, and standardized error shapes.
 *
 * Admin responses are NOT signed: clients are authenticated with bearer
 * tokens and HTTPS provides transport security. The signed-envelope pattern
 * is reserved for SDK-facing public endpoints.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

abstract class AdminController {

	protected function ok( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	protected function created( $data ): WP_REST_Response {
		return $this->ok( $data, 201 );
	}

	protected function no_content(): WP_REST_Response {
		return new WP_REST_Response( null, 204 );
	}

	protected function error( string $code, string $message, int $status = 400, array $data = [] ): WP_REST_Response {
		return new WP_REST_Response(
			array_merge(
				[
					'code'    => $code,
					'message' => $message,
				],
				$data
			),
			$status
		);
	}

	/**
	 * Read pagination params with sane defaults: page>=1, per_page in [1,100].
	 *
	 * @return array{page:int, per_page:int, offset:int}
	 */
	protected function pagination( WP_REST_Request $req ): array {
		$page     = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );
		$per_page = (int) ( $req->get_param( 'per_page' ) ?? 25 );
		$per_page = max( 1, min( 100, $per_page ) );
		return [
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		];
	}

	protected function paged_response( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = $this->ok( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}
}
