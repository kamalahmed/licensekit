<?php
/**
 * Top-level "License Kit" admin menu + submenus.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin;

use LicenseKit\Admin\Pages\Activations;
use LicenseKit\Admin\Pages\Dashboard;
use LicenseKit\Admin\Pages\Licenses;
use LicenseKit\Admin\Pages\Logs;
use LicenseKit\Admin\Pages\Products;
use LicenseKit\Admin\Pages\Releases;
use LicenseKit\Admin\Pages\Settings;
use LicenseKit\Admin\Pages\Tokens;
use LicenseKit\Admin\Pages\Tools;
use LicenseKit\Admin\Pages\Webhooks;
use LicenseKit\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const ROOT_SLUG = 'licensekit';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'License Kit', 'licensekit' ),
			__( 'License Kit', 'licensekit' ),
			Capabilities::MANAGE,
			self::ROOT_SLUG,
			[ Dashboard::class, 'render_static' ],
			'dashicons-admin-network',
			58
		);

		add_submenu_page( self::ROOT_SLUG, __( 'Dashboard', 'licensekit' ), __( 'Dashboard', 'licensekit' ),
			Capabilities::MANAGE, self::ROOT_SLUG, [ Dashboard::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Products', 'licensekit' ), __( 'Products', 'licensekit' ),
			Capabilities::MANAGE_PRODUCTS, 'licensekit-products', [ Products::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Releases', 'licensekit' ), __( 'Releases', 'licensekit' ),
			Capabilities::MANAGE_RELEASES, 'licensekit-releases', [ Releases::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Licenses', 'licensekit' ), __( 'Licenses', 'licensekit' ),
			Capabilities::MANAGE_LICENSES, 'licensekit-licenses', [ Licenses::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Activations', 'licensekit' ), __( 'Activations', 'licensekit' ),
			Capabilities::MANAGE_ACTIVATIONS, 'licensekit-activations', [ Activations::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'API Tokens', 'licensekit' ), __( 'API Tokens', 'licensekit' ),
			Capabilities::MANAGE_TOKENS, 'licensekit-tokens', [ Tokens::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Webhooks', 'licensekit' ), __( 'Webhooks', 'licensekit' ),
			Capabilities::MANAGE_WEBHOOKS, 'licensekit-webhooks', [ Webhooks::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Logs', 'licensekit' ), __( 'Logs', 'licensekit' ),
			Capabilities::VIEW_LOGS, 'licensekit-logs', [ Logs::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Tools', 'licensekit' ), __( 'Tools', 'licensekit' ),
			Capabilities::MANAGE, 'licensekit-tools', [ Tools::class, 'render_static' ] );

		add_submenu_page( self::ROOT_SLUG, __( 'Settings', 'licensekit' ), __( 'Settings', 'licensekit' ),
			Capabilities::MANAGE, 'licensekit-settings', [ Settings::class, 'render_static' ] );
	}

	public function enqueue_styles( $hook ): void {
		if ( false === strpos( (string) $hook, 'licensekit' ) ) {
			return;
		}
		// Inline styles — no separate asset request.
		wp_register_style( 'licensekit-admin', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
		wp_enqueue_style( 'licensekit-admin' );
		wp_add_inline_style( 'licensekit-admin', $this->inline_css() );
	}

	private function inline_css(): string {
		return '.lk-page { max-width: 1200px; }'
			. '.lk-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-top: 16px; }'
			. '.lk-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; }'
			. '.lk-card .num { font-size: 28px; font-weight: 600; line-height: 1.2; }'
			. '.lk-card .label { color: #50575e; margin-top: 4px; }'
			. '.lk-status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 500; }'
			. '.lk-status-active { background: #d4edda; color: #155724; }'
			. '.lk-status-expired { background: #fff3cd; color: #856404; }'
			. '.lk-status-disabled { background: #e2e3e5; color: #383d41; }'
			. '.lk-status-revoked { background: #f8d7da; color: #721c24; }'
			. '.lk-status-pending { background: #cce5ff; color: #004085; }'
			. '.lk-status-deactivated { background: #e2e3e5; color: #383d41; }'
			. '.lk-status-paused { background: #fff3cd; color: #856404; }'
			. '.lk-key-once { background: #fff3cd; border: 1px solid #ffeaa7; padding: 12px; border-radius: 4px; margin: 12px 0; }'
			. '.lk-key-once code { background: #fff; padding: 4px 8px; border-radius: 2px; user-select: all; font-size: 14px; }'
			. '.lk-filters { background: #fff; padding: 12px; border: 1px solid #c3c4c7; border-radius: 4px; margin-bottom: 12px; }'
			. '.lk-filters input, .lk-filters select { margin-right: 8px; }'
			. 'table.lk-table { background: #fff; }'
			. '.lk-mono { font-family: monospace; }';
	}
}
