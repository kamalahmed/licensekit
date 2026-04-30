<?php
/**
 * Public license REST endpoints.
 *
 *   POST /wp-json/licensekit/v1/license/activate
 *   POST /wp-json/licensekit/v1/license/deactivate
 *   POST /wp-json/licensekit/v1/license/validate
 *   POST /wp-json/licensekit/v1/license/info
 *
 * Auth model: license key in body. No nonce — SDKs aren't browsers.
 *
 * Rate limits (sliding window via transients):
 *   - per-IP: 30 / minute across all license endpoints
 *   - per-key on activate: 10 / minute
 *   - 5 failed activations on the same key within 15 min triggers a 15-min lockout
 *
 * Every response is wrapped with `Signer::sign_envelope()` so the client SDK can
 * detect MITM tampering before trusting any field.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers;

use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\Hasher;
use LicenseKit\Services\LicenseService;
use LicenseKit\Services\RateLimiter;
use LicenseKit\Services\Signer;
use LicenseKit\Support\HttpsGuard;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class LicenseController {

	private const RATE_PER_IP_MAX        = 30;
	private const RATE_PER_IP_WINDOW     = 60;
	private const RATE_PER_KEY_MAX       = 10;
	private const RATE_PER_KEY_WINDOW    = 60;
	private const FAIL_THRESHOLD         = 5;
	private const FAIL_WINDOW_SECONDS    = 900;
	private const LOCKOUT_SECONDS        = 900;

	private LicenseService $svc;

	public function __construct( LicenseService $svc ) {
		$this->svc = $svc;
	}

	public static function make(): self {
		$audit = new AuditLogger( new LogRepository() );
		return new self(
			new LicenseService(
				new LicenseRepository(),
				new ProductRepository(),
				new ActivationRepository(),
				$audit
			)
		);
	}

	public function register_routes( string $namespace ): void {
		$base = [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
		];

		register_rest_route(
			$namespace,
			'/license/activate',
			array_merge( $base, [ 'callback' => [ $this, 'activate' ] ] )
		);
		register_rest_route(
			$namespace,
			'/license/deactivate',
			array_merge( $base, [ 'callback' => [ $this, 'deactivate' ] ] )
		);
		register_rest_route(
			$namespace,
			'/license/validate',
			array_merge( $base, [ 'callback' => [ $this, 'validate' ] ] )
		);
		register_rest_route(
			$namespace,
			'/license/info',
			array_merge( $base, [ 'callback' => [ $this, 'info' ] ] )
		);
	}

	public function activate( WP_REST_Request $req ): WP_REST_Response {
		if ( null !== ( $blocked = HttpsGuard::require_https() ) ) {
			return $blocked;
		}

		$params = $this->params( $req );

		$missing = $this->require_fields( $params, [ 'license_key', 'product_slug', 'site_url' ] );
		if ( null !== $missing ) {
			return $this->fail( 'missing_field', $missing, 400 );
		}

		$ip       = $this->client_ip();
		$key_hash = Hasher::hash_license_key( (string) $params['license_key'] );

		// Hard lockout from prior abuse?
		if ( RateLimiter::is_locked( 'lk_key:' . $key_hash ) ) {
			return $this->fail(
				'rate_limited',
				__( 'Too many failed attempts. Try again later.', 'licensekit' ),
				429
			);
		}

		if ( ! RateLimiter::attempt( 'lk_ip:' . $ip, self::RATE_PER_IP_MAX, self::RATE_PER_IP_WINDOW )
			|| ! RateLimiter::attempt( 'lk_key:' . $key_hash, self::RATE_PER_KEY_MAX, self::RATE_PER_KEY_WINDOW ) ) {
			return $this->fail( 'rate_limited', __( 'Too many requests.', 'licensekit' ), 429 );
		}

		$result = $this->svc->activate(
			(string) $params['license_key'],
			(string) $params['product_slug'],
			(string) $params['site_url'],
			(string) ( $params['environment'] ?? 'unknown' )
		);

		// Track repeated failures for lockout (key-level only — invalid keys, mismatches).
		if ( ! $result['success'] && in_array( $result['error'] ?? '', [ 'invalid_key', 'product_mismatch' ], true ) ) {
			$failures = RateLimiter::record_failure( 'lk_key:' . $key_hash, self::FAIL_WINDOW_SECONDS );
			if ( $failures >= self::FAIL_THRESHOLD ) {
				RateLimiter::lockout( 'lk_key:' . $key_hash, self::LOCKOUT_SECONDS );
				do_action( 'licensekit_suspicious_activity', $key_hash, $ip );
			}
		} elseif ( $result['success'] ) {
			RateLimiter::clear_failures( 'lk_key:' . $key_hash );
		}

		return $this->respond( $result );
	}

	public function deactivate( WP_REST_Request $req ): WP_REST_Response {
		if ( null !== ( $blocked = HttpsGuard::require_https() ) ) {
			return $blocked;
		}
		$params = $this->params( $req );

		$missing = $this->require_fields( $params, [ 'license_key', 'site_url' ] );
		if ( null !== $missing ) {
			return $this->fail( 'missing_field', $missing, 400 );
		}

		if ( ! RateLimiter::attempt( 'lk_ip:' . $this->client_ip(), self::RATE_PER_IP_MAX, self::RATE_PER_IP_WINDOW ) ) {
			return $this->fail( 'rate_limited', __( 'Too many requests.', 'licensekit' ), 429 );
		}

		$result = $this->svc->deactivate( (string) $params['license_key'], (string) $params['site_url'] );
		return $this->respond( $result );
	}

	public function validate( WP_REST_Request $req ): WP_REST_Response {
		if ( null !== ( $blocked = HttpsGuard::require_https() ) ) {
			return $blocked;
		}
		$params = $this->params( $req );

		$missing = $this->require_fields( $params, [ 'license_key', 'site_url' ] );
		if ( null !== $missing ) {
			return $this->fail( 'missing_field', $missing, 400 );
		}

		if ( ! RateLimiter::attempt( 'lk_ip:' . $this->client_ip(), self::RATE_PER_IP_MAX, self::RATE_PER_IP_WINDOW ) ) {
			return $this->fail( 'rate_limited', __( 'Too many requests.', 'licensekit' ), 429 );
		}

		$result = $this->svc->validate(
			(string) $params['license_key'],
			isset( $params['product_slug'] ) ? (string) $params['product_slug'] : null,
			(string) $params['site_url']
		);
		return $this->respond( $result );
	}

	public function info( WP_REST_Request $req ): WP_REST_Response {
		if ( null !== ( $blocked = HttpsGuard::require_https() ) ) {
			return $blocked;
		}
		$params = $this->params( $req );

		$missing = $this->require_fields( $params, [ 'license_key' ] );
		if ( null !== $missing ) {
			return $this->fail( 'missing_field', $missing, 400 );
		}

		if ( ! RateLimiter::attempt( 'lk_ip:' . $this->client_ip(), self::RATE_PER_IP_MAX, self::RATE_PER_IP_WINDOW ) ) {
			return $this->fail( 'rate_limited', __( 'Too many requests.', 'licensekit' ), 429 );
		}

		return $this->respond( $this->svc->info( (string) $params['license_key'] ) );
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Read either a JSON body or query/form params — whichever the SDK sent.
	 *
	 * @return array<string, mixed>
	 */
	private function params( WP_REST_Request $req ): array {
		$json = $req->get_json_params();
		if ( is_array( $json ) && ! empty( $json ) ) {
			return $json;
		}
		return (array) $req->get_params();
	}

	private function require_fields( array $params, array $fields ): ?string {
		foreach ( $fields as $f ) {
			if ( ! isset( $params[ $f ] ) || '' === trim( (string) $params[ $f ] ) ) {
				return sprintf(
					/* translators: %s: missing field name */
					__( 'Missing required field: %s', 'licensekit' ),
					$f
				);
			}
		}
		return null;
	}

	private function respond( array $result ): WP_REST_Response {
		$status = $this->status_for( $result );
		$body   = Signer::sign_envelope( $result );
		return new WP_REST_Response( $body, $status );
	}

	private function fail( string $error, string $message, int $http_status ): WP_REST_Response {
		$body = Signer::sign_envelope(
			[
				'success' => false,
				'error'   => $error,
				'message' => $message,
			]
		);
		return new WP_REST_Response( $body, $http_status );
	}

	private function status_for( array $result ): int {
		if ( ! empty( $result['success'] ) ) {
			return 200;
		}
		switch ( $result['error'] ?? '' ) {
			case LicenseService::ERR_INVALID_KEY:
				return 401;
			case LicenseService::ERR_EXPIRED:
			case LicenseService::ERR_DISABLED:
			case LicenseService::ERR_REVOKED:
			case LicenseService::ERR_PENDING:
			case LicenseService::ERR_PRODUCT_MISMATCH:
			case LicenseService::ERR_ACTIVATION_LIMIT:
				return 403;
			case LicenseService::ERR_PRODUCT_NOT_FOUND:
				return 404;
			case LicenseService::ERR_INVALID_SITE:
				return 400;
			default:
				return 200; // generic non-success but no specific error code.
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
