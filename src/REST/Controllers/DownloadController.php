<?php
/**
 * Signed-token download endpoint.
 *
 *   GET /wp-json/licensekit/v1/update/download?token=…
 *
 * Verifies the token (from `mint_download_token` in `UpdateController`),
 * re-checks the license is still active (in case it was revoked between
 * mint and use), and streams the zip from `ReleaseFileStore`.
 *
 * Tokens are 5-minute TTL by default — short enough to limit replay,
 * long enough that WP's plugin upgrader can fetch successfully.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Controllers;

use LicenseKit\Models\License;
use LicenseKit\Models\Release;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\Signer;
use LicenseKit\Storage\ReleaseFileStore;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class DownloadController {

	private LicenseRepository $licenses;
	private ReleaseRepository $releases;
	private ProductRepository $products;
	private ReleaseFileStore $files;
	private AuditLogger $audit;

	public function __construct(
		LicenseRepository $licenses,
		ReleaseRepository $releases,
		ProductRepository $products,
		ReleaseFileStore $files,
		AuditLogger $audit
	) {
		$this->licenses = $licenses;
		$this->releases = $releases;
		$this->products = $products;
		$this->files    = $files;
		$this->audit    = $audit;
	}

	public static function make(): self {
		return new self(
			new LicenseRepository(),
			new ReleaseRepository(),
			new ProductRepository(),
			new ReleaseFileStore(),
			new AuditLogger( new LogRepository() )
		);
	}

	public function register_routes( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/update/download',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'download' ],
			]
		);
	}

	/**
	 * Streams the zip on success. Returns a JSON envelope on error.
	 *
	 * @return WP_REST_Response|void Returns void after an exit() on success.
	 */
	public function download( WP_REST_Request $req ) {
		$token = (string) $req->get_param( 'token' );
		if ( '' === $token ) {
			return $this->fail( 'missing_token', __( 'Missing download token.', 'licensekit' ), 400 );
		}

		// Decode the token's release_id (NOT yet trusted — only used to find the salt).
		$release_id_guess = $this->decode_release_id_unverified( $token );
		if ( null === $release_id_guess ) {
			return $this->fail( 'invalid_token', __( 'Token is malformed.', 'licensekit' ), 400 );
		}

		$release = $this->releases->find( $release_id_guess );
		if ( ! $release instanceof Release ) {
			return $this->fail( 'invalid_token', __( 'Release not found.', 'licensekit' ), 404 );
		}

		$verified = Signer::verify_download_token( $token, (string) $release->signing_salt );
		if ( null === $verified ) {
			return $this->fail( 'invalid_token', __( 'Token is invalid or expired.', 'licensekit' ), 401 );
		}

		// Token's release_id must equal the verified one (defense in depth).
		if ( $verified['release_id'] !== (int) $release->id ) {
			return $this->fail( 'invalid_token', __( 'Token mismatch.', 'licensekit' ), 401 );
		}

		// Re-check license status — could have been revoked since the token was minted.
		$license = $this->licenses->find( $verified['license_id'] );
		if ( ! $license instanceof License || ! $this->license_permits_download( $license ) ) {
			return $this->fail( 'license_inactive', __( 'License is not eligible for downloads.', 'licensekit' ), 403 );
		}

		// Make sure the license was issued for the product this release belongs to.
		if ( $license->product_id !== $release->product_id ) {
			return $this->fail( 'product_mismatch', __( 'License does not match the requested product.', 'licensekit' ), 403 );
		}

		if ( null === $release->file_path ) {
			return $this->fail( 'no_file', __( 'Release has no file.', 'licensekit' ), 404 );
		}

		$abs = $this->files->absolute_path( $release->file_path );
		if ( null === $abs || ! is_readable( $abs ) ) {
			return $this->fail( 'file_unreadable', __( 'Release file is unreadable.', 'licensekit' ), 500 );
		}

		$this->audit->record(
			'release.downloaded',
			[
				'release_id' => (int) $release->id,
				'license_id' => (int) $license->id,
				'version'    => $release->version,
			],
			'release',
			(int) $release->id
		);

		// Look up product to construct a friendly filename.
		$product       = $this->products->find( $release->product_id );
		$slug          = $product ? $product->slug : 'release';
		$download_name = sprintf( '%s-%s.zip', $slug, $release->version );

		$this->stream_file( $abs, $download_name );
	}

	private function license_permits_download( License $license ): bool {
		if ( License::STATUS_ACTIVE !== $license->status ) {
			return false;
		}
		if ( null === $license->expires_at ) {
			return true; // lifetime
		}
		return strtotime( $license->expires_at . ' UTC' ) > time();
	}

	/**
	 * Pull the leading `release_id` out of a base64url-encoded download token without
	 * verifying its HMAC. Used purely to look up the per-release `signing_salt` for
	 * the proper verification step that follows. A bad value here doesn't grant access.
	 */
	private function decode_release_id_unverified( string $token ): ?int {
		$padded  = strtr( $token, '-_', '+/' );
		$mod     = strlen( $padded ) % 4;
		$padded .= 0 === $mod ? '' : str_repeat( '=', 4 - $mod );
		$decoded = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $decoded ) {
			return null;
		}
		$parts = explode( '|', $decoded, 2 );
		if ( count( $parts ) < 1 ) {
			return null;
		}
		$candidate = (int) $parts[0];
		return $candidate > 0 ? $candidate : null;
	}

	private function stream_file( string $abs, string $download_name ): void {
		$size = filesize( $abs );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		if ( false !== $size ) {
			header( 'Content-Length: ' . $size );
		}
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// Flush WP's output buffers so the file streams cleanly.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
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
}
