<?php
/**
 * LicenseKit Client SDK — minimal theme integration.
 *
 * Add this to your theme's `functions.php`. Render the license field via
 * `do_action( 'mytheme_render_license_field' )` from a Customizer panel,
 * a theme options page, or wherever you want.
 */

defined( 'ABSPATH' ) || exit;

require_once get_stylesheet_directory() . '/lib/licensekit-client.php';

\LicenseKit_Client_v1::boot(
	[
		'product_slug'       => 'my-theme',
		'stylesheet'         => get_stylesheet(),
		'item_type'          => 'theme',
		'server_url'         => 'https://updates.example.com',
		'version'            => wp_get_theme()->get( 'Version' ),
		'license_option_key' => 'mytheme_license',
		'text_domain'        => 'my-theme',
		'render_field_hook'  => 'mytheme_render_license_field',
		'public_key'         => 'paste-base64-Ed25519-public-key-from-LicenseKit-Settings',
	]
);
