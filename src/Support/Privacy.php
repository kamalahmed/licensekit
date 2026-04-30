<?php
/**
 * GDPR data exporter + eraser for licenses and activations.
 *
 * Hooks into core privacy tooling so site admins can export or erase a user's
 * LicenseKit data on request. Matching is done by email (the most reliable
 * link between WP users and EDD customer records).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Support;

use LicenseKit\Models\Activation;
use LicenseKit\Models\License;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\ProductRepository;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['licensekit'] = [
			'exporter_friendly_name' => __( 'LicenseKit Data', 'licensekit' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['licensekit'] = [
			'eraser_friendly_name' => __( 'LicenseKit Data', 'licensekit' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	public function export( string $email, int $page = 1 ): array {
		$licenses = ( new LicenseRepository() )->find_by_customer_email( $email );
		$products = new ProductRepository();
		$acts     = new ActivationRepository();

		$data = [];
		foreach ( $licenses as $license ) {
			/** @var License $license */
			$product       = $products->find( (int) $license->product_id );
			$product_name  = $product ? $product->name : '—';

			$data[] = [
				'group_id'    => 'licensekit_licenses',
				'group_label' => __( 'LicenseKit licenses', 'licensekit' ),
				'item_id'     => 'license-' . $license->id,
				'data'        => [
					[ 'name' => __( 'Product', 'licensekit' ), 'value' => $product_name ],
					[ 'name' => __( 'Key prefix', 'licensekit' ), 'value' => $license->key_prefix . '…' ],
					[ 'name' => __( 'Tier', 'licensekit' ), 'value' => $license->tier ],
					[ 'name' => __( 'Status', 'licensekit' ), 'value' => $license->status ],
					[ 'name' => __( 'Issued at', 'licensekit' ), 'value' => (string) $license->issued_at ],
					[ 'name' => __( 'Expires at', 'licensekit' ), 'value' => (string) ( $license->expires_at ?? __( 'never', 'licensekit' ) ) ],
				],
			];

			foreach ( $acts->find_for_license( (int) $license->id ) as $a ) {
				/** @var Activation $a */
				$data[] = [
					'group_id'    => 'licensekit_activations',
					'group_label' => __( 'LicenseKit activations', 'licensekit' ),
					'item_id'     => 'activation-' . $a->id,
					'data'        => [
						[ 'name' => __( 'License', 'licensekit' ), 'value' => $license->key_prefix . '…' ],
						[ 'name' => __( 'Site URL', 'licensekit' ), 'value' => $a->site_url ],
						[ 'name' => __( 'Environment', 'licensekit' ), 'value' => $a->site_environment ],
						[ 'name' => __( 'Status', 'licensekit' ), 'value' => $a->status ],
						[ 'name' => __( 'Activated at', 'licensekit' ), 'value' => (string) $a->activated_at ],
					],
				];
			}
		}

		return [
			'data' => $data,
			'done' => true,
		];
	}

	public function erase( string $email, int $page = 1 ): array {
		$licenses = ( new LicenseRepository() )->find_by_customer_email( $email );
		$lic_repo = new LicenseRepository();
		$act_repo = new ActivationRepository();

		$removed = 0;

		foreach ( $licenses as $license ) {
			/** @var License $license */
			foreach ( $act_repo->find_for_license( (int) $license->id ) as $a ) {
				$act_repo->delete( (int) $a->id );
				++$removed;
			}
			$lic_repo->delete( (int) $license->id );
			++$removed;
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => [],
			'done'           => true,
		];
	}
}
