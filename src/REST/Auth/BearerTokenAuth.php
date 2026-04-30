<?php
/**
 * Bearer-token authentication for the vendor admin REST API.
 *
 * Token format: `lk_<prefix-8>_<secret-32>` — the full token is shown once on
 * creation, only the peppered hash is stored. Capabilities are encoded in the
 * token's `abilities` JSON column; `*` grants all.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\REST\Auth;

use LicenseKit\Models\ApiToken;
use LicenseKit\Repositories\ApiTokenRepository;
use LicenseKit\Services\Hasher;
use LicenseKit\Support\Helpers;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class BearerTokenAuth {

	private ApiTokenRepository $tokens;

	public function __construct( ApiTokenRepository $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Returns the authenticated ApiToken or null if the bearer is absent / invalid.
	 */
	public function authenticate( WP_REST_Request $req ): ?ApiToken {
		$header = (string) $req->get_header( 'authorization' );
		if ( '' === $header || ! preg_match( '/^Bearer\s+(.+)$/i', $header, $m ) ) {
			return null;
		}

		$presented = trim( $m[1] );
		if ( '' === $presented ) {
			return null;
		}

		$hash      = Hasher::hash_token( $presented );
		$api_token = $this->tokens->find_by_token_hash( $hash );
		if ( ! $api_token instanceof ApiToken || ! $api_token->is_active() ) {
			return null;
		}

		// Touch (best-effort; failures shouldn't block the request).
		$this->tokens->touch_last_used( (int) $api_token->id, Helpers::now_utc() );

		return $api_token;
	}

	/**
	 * Permission callback adapter — returns true on success, WP_Error otherwise.
	 * Caches the resolved token on the request for later access by the controller.
	 *
	 * @param string $required_ability '*' grants any token; specific name requires explicit grant.
	 */
	public function permission( string $required_ability ): callable {
		return function ( WP_REST_Request $req ) use ( $required_ability ) {
			$token = $this->authenticate( $req );
			if ( null === $token ) {
				return new WP_Error(
					'lk_unauthenticated',
					__( 'A valid bearer token is required.', 'licensekit' ),
					[ 'status' => 401 ]
				);
			}
			if ( ! $this->has_ability( $token, $required_ability ) ) {
				return new WP_Error(
					'lk_forbidden',
					__( 'Token lacks the required ability.', 'licensekit' ),
					[ 'status' => 403 ]
				);
			}
			$req->set_param( '_lk_token', $token );
			return true;
		};
	}

	public function has_ability( ApiToken $token, string $ability ): bool {
		$abilities = $token->abilities;
		if ( in_array( '*', $abilities, true ) ) {
			return true;
		}
		return in_array( $ability, $abilities, true );
	}

	/**
	 * Generate a new full token + its hash. Caller persists hash + prefix; the
	 * full token is returned to the user once and never recoverable.
	 *
	 * @return array{full:string, hash:string, prefix:string}
	 */
	public static function mint(): array {
		$prefix = bin2hex( random_bytes( 4 ) );
		$secret = bin2hex( random_bytes( 16 ) );
		$full   = 'lk_' . $prefix . '_' . $secret;
		return [
			'full'   => $full,
			'hash'   => Hasher::hash_token( $full ),
			'prefix' => 'lk_' . $prefix,
		];
	}
}
