<?php
/**
 * API token DTO — vendor admin REST tokens.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class ApiToken {

	public ?int $id              = null;
	public int $user_id          = 0;
	public string $token_hash    = '';
	public string $token_prefix  = '';
	public string $name          = '';
	public array $abilities      = [];
	public ?string $last_used_at = null;
	public ?string $expires_at   = null;
	public ?string $revoked_at   = null;
	public ?string $created_at   = null;

	public static function from_row( array $row ): self {
		$t               = new self();
		$t->id           = isset( $row['id'] ) ? (int) $row['id'] : null;
		$t->user_id      = (int) ( $row['user_id'] ?? 0 );
		$t->token_hash   = (string) ( $row['token_hash'] ?? '' );
		$t->token_prefix = (string) ( $row['token_prefix'] ?? '' );
		$t->name         = (string) ( $row['name'] ?? '' );
		$t->abilities    = Helpers::decode_json_column( $row['abilities'] ?? null );
		$t->last_used_at = isset( $row['last_used_at'] ) ? (string) $row['last_used_at'] : null;
		$t->expires_at   = isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null;
		$t->revoked_at   = isset( $row['revoked_at'] ) ? (string) $row['revoked_at'] : null;
		$t->created_at   = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		return $t;
	}

	public function to_array(): array {
		return [
			'id'           => $this->id,
			'user_id'      => $this->user_id,
			'token_hash'   => $this->token_hash,
			'token_prefix' => $this->token_prefix,
			'name'         => $this->name,
			'abilities'    => Helpers::encode_json_column( $this->abilities ),
			'last_used_at' => $this->last_used_at,
			'expires_at'   => $this->expires_at,
			'revoked_at'   => $this->revoked_at,
			'created_at'   => $this->created_at,
		];
	}

	public function is_active(): bool {
		if ( null !== $this->revoked_at ) {
			return false;
		}
		if ( null !== $this->expires_at && strtotime( $this->expires_at ) < time() ) {
			return false;
		}
		return true;
	}
}
