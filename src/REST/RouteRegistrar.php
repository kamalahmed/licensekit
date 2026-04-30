<?php
/**
 * Wires every LicenseKit REST controller into WordPress's `rest_api_init` action.
 *
 * Public endpoints under `/wp-json/licensekit/v1/license/*` and
 * `/wp-json/licensekit/v1/update/*`. Admin endpoints under
 * `/wp-json/licensekit/v1/admin/*` and gated by Bearer tokens.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST;

use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\ApiTokenRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\Repositories\WebhookRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\REST\Controllers\Admin\LicensesController;
use LicenseKit\REST\Controllers\Admin\LogsController;
use LicenseKit\REST\Controllers\Admin\ProductsController;
use LicenseKit\REST\Controllers\Admin\ReleasesController;
use LicenseKit\REST\Controllers\Admin\TokensController;
use LicenseKit\REST\Controllers\Admin\WebhooksController;
use LicenseKit\REST\Controllers\DownloadController;
use LicenseKit\REST\Controllers\LicenseController;
use LicenseKit\REST\Controllers\UpdateController;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\LicenseService;
use LicenseKit\Services\ReleaseService;
use LicenseKit\Storage\ReleaseFileStore;

defined( 'ABSPATH' ) || exit;

final class RouteRegistrar {

	public const API_NAMESPACE = 'licensekit/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// --- Public ---
		LicenseController::make()->register_routes( self::API_NAMESPACE );
		UpdateController::make()->register_routes( self::API_NAMESPACE );
		DownloadController::make()->register_routes( self::API_NAMESPACE );

		// --- Admin (Bearer-token-gated) ---
		$tokens   = new ApiTokenRepository();
		$auth     = new BearerTokenAuth( $tokens );
		$products = new ProductRepository();
		$releases = new ReleaseRepository();
		$licenses = new LicenseRepository();
		$activs   = new ActivationRepository();
		$webhooks = new WebhookRepository();
		$logs     = new LogRepository();
		$audit    = new AuditLogger( $logs );
		$files    = new ReleaseFileStore();

		( new ProductsController( $products, $auth ) )->register_routes( self::API_NAMESPACE );
		( new ReleasesController(
			new ReleaseService( $releases, $products, $files, $audit ),
			$releases,
			$products,
			$auth
		) )->register_routes( self::API_NAMESPACE );
		( new LicensesController(
			new LicenseService( $licenses, $products, $activs, $audit ),
			$licenses,
			$activs,
			$auth
		) )->register_routes( self::API_NAMESPACE );
		( new TokensController( $tokens, $auth ) )->register_routes( self::API_NAMESPACE );
		( new WebhooksController( $webhooks, $auth ) )->register_routes( self::API_NAMESPACE );
		( new LogsController( $logs, $auth ) )->register_routes( self::API_NAMESPACE );
	}
}
