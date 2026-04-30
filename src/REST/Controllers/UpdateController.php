<?php
/**
 * Update metadata endpoints — what the SDK polls every 12 hours.
 *
 *   GET /wp-json/licensekit/v1/update/plugin/{slug}?license_key=&site_url=&installed_version=&channel=
 *   GET /wp-json/licensekit/v1/update/theme/{slug}? ...
 *
 * Returns a `plugins_api()`-shaped (or `themes_api()`-shaped) JSON envelope
 * that the SDK reshapes into the WP transient + plugins_api filter results.
 *
 * License validation is required: the response carries a signed download token
 * embedded in `package`. Tokens are 5-minute TTL, generated only for currently-
 * active (or in-grace-but-not-yet-expired) licenses.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers;

use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Models\Release;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\REST\RouteRegistrar;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\LicenseService;
use LicenseKit\Services\RateLimiter;
use LicenseKit\Services\Signer;
use LicenseKit\Support\HttpsGuard;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class UpdateController {

	private const RATE_PER_IP_MAX    = 60;
	private const RATE_PER_IP_WINDOW = 60;

	private LicenseService $license_svc;
	private LicenseRepository $licenses;
	private ProductRepository $products;
	private ReleaseRepository $releases;

	public function __construct(
		LicenseService $license_svc,
		LicenseRepository $licenses,
		ProductRepository $products,
		ReleaseRepository $releases
	) {
		$this->license_svc = $license_svc;
		$this->licenses    = $licenses;
		$this->products    = $products;
		$this->releases    = $releases;
	}

	public static function make(): self {
		$audit       = new AuditLogger( new LogRepository() );
		$licenses    = new LicenseRepository();
		$products    = new ProductRepository();
		$releases    = new ReleaseRepository();
		$activations = new ActivationRepository();
		return new self(
			new LicenseService( $licenses, $products, $activations, $audit ),
			$licenses,
			$products,
			$releases
		);
	}

	public function register_routes( string $namespace ): void {
		$base = [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
		];

		register_rest_route(
			$namespace,
			'/update/plugin/(?P<slug>[a-z0-9_-]+)',
			array_merge( $base, [ 'callback' => [ $this, 'plugin' ] ] )
		);
		register_rest_route(
			$namespace,
			'/update/theme/(?P<slug>[a-z0-9_-]+)',
			array_merge( $base, [ 'callback' => [ $this, 'theme' ] ] )
		);
	}

	public function plugin( WP_REST_Request $req ): WP_REST_Response {
		return $this->handle( $req, 'plugin' );
	}

	public function theme( WP_REST_Request $req ): WP_REST_Response {
		return $this->handle( $req, 'theme' );
	}

	private function handle( WP_REST_Request $req, string $expected_type ): WP_REST_Response {
		if ( null !== ( $blocked = HttpsGuard::require_https() ) ) {
			return $blocked;
		}
		if ( ! RateLimiter::attempt( 'lk_ip:' . $this->client_ip(), self::RATE_PER_IP_MAX, self::RATE_PER_IP_WINDOW ) ) {
			return $this->fail( 'rate_limited', __( 'Too many requests.', 'licensekit' ), 429 );
		}

		$slug        = (string) $req->get_param( 'slug' );
		$license_key = (string) $req->get_param( 'license_key' );
		$site_url    = (string) $req->get_param( 'site_url' );
		$channel     = (string) ( $req->get_param( 'channel' ) ?? 'stable' );

		if ( '' === $slug || '' === $license_key || '' === $site_url ) {
			return $this->fail( 'missing_field', __( 'license_key, site_url, and slug are required.', 'licensekit' ), 400 );
		}

		// Reuse the validate path — same status/expiry/match checks as the SDK's heartbeat.
		$check = $this->license_svc->validate( $license_key, $slug, $site_url );
		if ( ! $check['success'] ) {
			return $this->fail(
				$check['error'] ?? 'validate_failed',
				$check['message'] ?? __( 'License validation failed.', 'licensekit' ),
				$this->status_for_error( $check['error'] ?? '' )
			);
		}

		$product = $this->products->find_by_slug( $slug );
		if ( ! $product instanceof Product || $product->type !== $expected_type ) {
			return $this->fail( 'product_not_found', __( 'Product not found.', 'licensekit' ), 404 );
		}

		$latest = $this->releases->find_latest_for_product( (int) $product->id, $channel );
		if ( ! $latest instanceof Release ) {
			return $this->fail( 'no_release', __( 'No release available on this channel.', 'licensekit' ), 404 );
		}

		// Look up the (verified) license for download-token minting + grace handling.
		$license = $this->licenses->find_by_key_hash( \LicenseKit\Services\Hasher::hash_license_key( $license_key ) );
		if ( ! $license instanceof License ) {
			// The validate() above succeeded, so this should not happen — but be defensive.
			return $this->fail( 'invalid_key', __( 'License lookup failed.', 'licensekit' ), 401 );
		}

		// Grace period: if the license is past expires_at (but still validating because of grace),
		// return metadata WITHOUT a download URL — customer must renew to grab updates.
		$package_url = null;
		if ( $this->license_can_download( $license ) ) {
			$package_url = $this->build_package_url( $latest, $license );
		}

		$payload = $this->build_metadata( $product, $latest, $package_url, $expected_type );

		return new WP_REST_Response( Signer::sign_envelope( $payload ), 200 );
	}

	private function license_can_download( License $license ): bool {
		if ( $license->is_lifetime() ) {
			return true;
		}
		return null !== $license->expires_at
			&& strtotime( $license->expires_at . ' UTC' ) > time();
	}

	private function build_package_url( Release $release, License $license ): string {
		$token = Signer::mint_download_token(
			(int) $release->id,
			(int) $license->id,
			(string) $release->signing_salt,
			Signer::DEFAULT_DOWNLOAD_TTL
		);
		return rest_url( RouteRegistrar::API_NAMESPACE . '/update/download' ) . '?token=' . rawurlencode( $token );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_metadata( Product $product, Release $release, ?string $package_url, string $type ): array {
		$meta            = $product->meta;
		$default_section = static fn( string $key ) => isset( $meta[ $key ] ) && is_string( $meta[ $key ] )
			? (string) $meta[ $key ]
			: '';

		$payload = [
			'success'       => true,
			'name'          => $product->name,
			'slug'          => $product->slug,
			'type'          => $type,
			'version'       => $release->version,
			'new_version'   => $release->version,
			'author'        => (string) ( $product->author ?? '' ),
			'homepage'      => (string) ( $product->homepage_url ?? '' ),
			'requires'      => $release->requires_wp,
			'requires_php'  => $release->requires_php,
			'tested'        => $release->tested_up_to,
			'last_updated'  => $release->released_at,
			'package'       => $package_url,
			'download_link' => $package_url,
			'sections'      => [
				'description'  => $default_section( 'description' ),
				'installation' => $default_section( 'installation' ),
				'changelog'    => (string) ( $release->changelog_md ?? '' ),
				'faq'          => $default_section( 'faq' ),
			],
			'icons'         => isset( $meta['icons'] ) && is_array( $meta['icons'] ) ? $meta['icons'] : (object) [],
			'banners'       => isset( $meta['banners'] ) && is_array( $meta['banners'] ) ? $meta['banners'] : (object) [],
		];

		return $payload;
	}

	private function fail( string $error, string $message, int $http_status ): WP_REST_Response {
		return new WP_REST_Response(
			Signer::sign_envelope(
				[
					'success' => false,
					'error'   => $error,
					'message' => $message,
				]
			),
			$http_status
		);
	}

	private function status_for_error( string $error ): int {
		switch ( $error ) {
			case LicenseService::ERR_INVALID_KEY:
				return 401;
			case LicenseService::ERR_EXPIRED:
			case LicenseService::ERR_DISABLED:
			case LicenseService::ERR_REVOKED:
			case LicenseService::ERR_PENDING:
			case LicenseService::ERR_PRODUCT_MISMATCH:
				return 403;
			case LicenseService::ERR_PRODUCT_NOT_FOUND:
				return 404;
			default:
				return 400;
		}
	}

	private function client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '0.0.0.0';
		}
		$ip = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore WordPress.Security
		return '' !== $ip ? $ip : '0.0.0.0';
	}
}
