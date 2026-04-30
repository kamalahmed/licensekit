<?php
/**
 * Release DTO.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

defined( 'ABSPATH' ) || exit;

final class Release {

	public ?int $id              = null;
	public int $product_id       = 0;
	public string $version       = '';
	public string $channel       = 'stable';
	public ?string $file_path    = null;
	public ?int $file_size       = null;
	public ?string $file_hash    = null;
	public string $signing_salt  = '';
	public ?string $changelog_md = null;
	public ?string $requires_wp  = null;
	public ?string $requires_php = null;
	public ?string $tested_up_to = null;
	public ?string $released_at  = null;
	public ?string $created_at   = null;
	public ?int $created_by      = null;

	public static function from_row( array $row ): self {
		$r               = new self();
		$r->id           = isset( $row['id'] ) ? (int) $row['id'] : null;
		$r->product_id   = (int) ( $row['product_id'] ?? 0 );
		$r->version      = (string) ( $row['version'] ?? '' );
		$r->channel      = (string) ( $row['channel'] ?? 'stable' );
		$r->file_path    = isset( $row['file_path'] ) ? (string) $row['file_path'] : null;
		$r->file_size    = isset( $row['file_size'] ) ? (int) $row['file_size'] : null;
		$r->file_hash    = isset( $row['file_hash'] ) ? (string) $row['file_hash'] : null;
		$r->signing_salt = (string) ( $row['signing_salt'] ?? '' );
		$r->changelog_md = isset( $row['changelog_md'] ) ? (string) $row['changelog_md'] : null;
		$r->requires_wp  = isset( $row['requires_wp'] ) ? (string) $row['requires_wp'] : null;
		$r->requires_php = isset( $row['requires_php'] ) ? (string) $row['requires_php'] : null;
		$r->tested_up_to = isset( $row['tested_up_to'] ) ? (string) $row['tested_up_to'] : null;
		$r->released_at  = isset( $row['released_at'] ) ? (string) $row['released_at'] : null;
		$r->created_at   = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		$r->created_by   = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
		return $r;
	}

	public function to_array(): array {
		return [
			'id'           => $this->id,
			'product_id'   => $this->product_id,
			'version'      => $this->version,
			'channel'      => $this->channel,
			'file_path'    => $this->file_path,
			'file_size'    => $this->file_size,
			'file_hash'    => $this->file_hash,
			'signing_salt' => $this->signing_salt,
			'changelog_md' => $this->changelog_md,
			'requires_wp'  => $this->requires_wp,
			'requires_php' => $this->requires_php,
			'tested_up_to' => $this->tested_up_to,
			'released_at'  => $this->released_at,
			'created_at'   => $this->created_at,
			'created_by'   => $this->created_by,
		];
	}
}
