<?php
/**
 * Capability registration.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const MANAGE             = 'manage_licensekit';
	public const MANAGE_PRODUCTS    = 'manage_licensekit_products';
	public const MANAGE_RELEASES    = 'manage_licensekit_releases';
	public const MANAGE_LICENSES    = 'manage_licensekit_licenses';
	public const MANAGE_ACTIVATIONS = 'manage_licensekit_activations';
	public const MANAGE_TOKENS      = 'manage_licensekit_tokens';
	public const MANAGE_WEBHOOKS    = 'manage_licensekit_webhooks';
	public const VIEW_LOGS          = 'view_licensekit_logs';

	public static function all(): array {
		return [
			self::MANAGE,
			self::MANAGE_PRODUCTS,
			self::MANAGE_RELEASES,
			self::MANAGE_LICENSES,
			self::MANAGE_ACTIVATIONS,
			self::MANAGE_TOKENS,
			self::MANAGE_WEBHOOKS,
			self::VIEW_LOGS,
		];
	}

	public static function add(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// shop_manager (EDD-installed sites also have this) — license operations only.
		$shop = get_role( 'shop_manager' );
		if ( $shop ) {
			$shop->add_cap( self::MANAGE_LICENSES );
			$shop->add_cap( self::MANAGE_ACTIVATIONS );
		}
	}

	public static function remove(): void {
		foreach ( wp_roles()->roles as $slug => $info ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
