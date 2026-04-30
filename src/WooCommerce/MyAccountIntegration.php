<?php
/**
 * Adds a "Licenses" endpoint to the WooCommerce My Account area.
 *
 *   /my-account/licenses/   — lists the customer's licenses with click-to-copy
 *                             keys and a self-rotate button
 *
 * Reuses the same `[licensekit_customer_licenses]` rendering as the EDD-side
 * dashboard, so behavior is identical across both e-commerce backends.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class MyAccountIntegration {

	public const ENDPOINT       = 'licenses';
	public const FLUSH_FLAG_OPT = 'licensekit_wc_endpoint_flushed';

	public function register(): void {
		add_action( 'init', [ $this, 'add_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ], 0 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ $this, 'render_endpoint' ] );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		// One-time flush so the endpoint URL works without manually visiting
		// Settings → Permalinks. Use an option flag so we only flush once.
		if ( ! get_option( self::FLUSH_FLAG_OPT ) ) {
			flush_rewrite_rules( false );
			update_option( self::FLUSH_FLAG_OPT, time(), true );
		}
	}

	public function register_query_var( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function add_menu_item( array $items ): array {
		// Insert "Licenses" before "Logout" if present, else append.
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		if ( null !== $logout ) {
			unset( $items['customer-logout'] );
		}
		$items[ self::ENDPOINT ] = __( 'Licenses', 'licensekit' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	public function render_endpoint(): void {
		// The shortcode handles ownership + rotate UX; reuse it.
		echo do_shortcode( '[licensekit_customer_licenses]' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
