<?php
/**
 * HTTPS enforcement for public REST endpoints.
 *
 * Refuses non-HTTPS requests unless the operator explicitly allows them via
 * `LICENSEKIT_ALLOW_HTTP` (intended for local dev only). Returns a signed
 * envelope so SDKs handle the failure consistently with other rejections.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Support;

use LicenseKit\Services\Signer;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class HttpsGuard {

	public static function require_https(): ?WP_REST_Response {
		if ( defined( 'LICENSEKIT_ALLOW_HTTP' ) && LICENSEKIT_ALLOW_HTTP ) {
			return null;
		}
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return null;
		}

		return new WP_REST_Response(
			Signer::sign_envelope(
				[
					'success' => false,
					'error'   => 'http_required',
					'message' => __( 'HTTPS is required for license endpoints.', 'licensekit' ),
				]
			),
			403
		);
	}
}
