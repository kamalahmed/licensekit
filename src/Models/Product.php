<?php
/**
 * Product DTO.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Models;

use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Product {

	public ?int $id                 = null;
	public ?int $edd_download_id    = null;
	public ?int $wc_product_id      = null;
	public string $slug             = '';
	public string $name             = '';
	public string $type             = 'plugin';
	public ?string $current_version = null;
	public ?int $current_release_id = null;
	public ?string $homepage_url    = null;
	public ?string $author          = null;
	public array $meta              = [];
	public ?string $created_at      = null;
	public ?string $updated_at      = null;

	public static function from_row( array $row ): self {
		$p                     = new self();
		$p->id                 = isset( $row['id'] ) ? (int) $row['id'] : null;
		$p->edd_download_id    = isset( $row['edd_download_id'] ) ? (int) $row['edd_download_id'] : null;
		$p->wc_product_id      = isset( $row['wc_product_id'] ) ? (int) $row['wc_product_id'] : null;
		$p->slug               = (string) ( $row['slug'] ?? '' );
		$p->name               = (string) ( $row['name'] ?? '' );
		$p->type               = (string) ( $row['type'] ?? 'plugin' );
		$p->current_version    = isset( $row['current_version'] ) ? (string) $row['current_version'] : null;
		$p->current_release_id = isset( $row['current_release_id'] ) ? (int) $row['current_release_id'] : null;
		$p->homepage_url       = isset( $row['homepage_url'] ) ? (string) $row['homepage_url'] : null;
		$p->author             = isset( $row['author'] ) ? (string) $row['author'] : null;
		$p->meta               = Helpers::decode_json_column( $row['meta'] ?? null );
		$p->created_at         = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
		$p->updated_at         = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null;
		return $p;
	}

	public function to_array(): array {
		return [
			'id'                 => $this->id,
			'edd_download_id'    => $this->edd_download_id,
			'wc_product_id'      => $this->wc_product_id,
			'slug'               => $this->slug,
			'name'               => $this->name,
			'type'               => $this->type,
			'current_version'    => $this->current_version,
			'current_release_id' => $this->current_release_id,
			'homepage_url'       => $this->homepage_url,
			'author'             => $this->author,
			'meta'               => Helpers::encode_json_column( $this->meta ),
			'created_at'         => $this->created_at,
			'updated_at'         => $this->updated_at,
		];
	}
}
