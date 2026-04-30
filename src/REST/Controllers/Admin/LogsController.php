<?php
/**
 * Vendor admin: read-only audit log access.
 *
 *   GET /admin/logs                          — paginated, newest first
 *   GET /admin/logs?subject_type=&subject_id — filter to one subject
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers\Admin;

use LicenseKit\Models\Log;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class LogsController extends AdminController {

	private LogRepository $repo;
	private BearerTokenAuth $auth;

	public function __construct( LogRepository $repo, BearerTokenAuth $auth ) {
		$this->repo = $repo;
		$this->auth = $auth;
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/admin/logs',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'index' ],
				'permission_callback' => $this->auth->permission( 'logs.read' ),
			]
		);
	}

	public function index( WP_REST_Request $req ): WP_REST_Response {
		$page         = $this->pagination( $req );
		$subject_type = (string) ( $req->get_param( 'subject_type' ) ?? '' );
		$subject_id   = (int) ( $req->get_param( 'subject_id' ) ?? 0 );

		if ( '' !== $subject_type && $subject_id > 0 ) {
			$rows = $this->repo->find_for_subject( $subject_type, $subject_id, $page['per_page'] );
		} else {
			$rows = $this->repo->find_recent( $page['per_page'] );
		}

		return $this->ok( array_map( [ $this, 'serialize' ], $rows ) );
	}

	private function serialize( ?Log $l ): array {
		if ( ! $l instanceof Log ) {
			return [];
		}
		return [
			'id'           => (int) $l->id,
			'actor_type'   => $l->actor_type,
			'actor_id'     => $l->actor_id,
			'action'       => $l->action,
			'subject_type' => $l->subject_type,
			'subject_id'   => $l->subject_id,
			'context'      => $l->context,
			'created_at'   => $l->created_at,
		];
	}
}
