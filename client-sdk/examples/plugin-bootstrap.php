<?php
/**
 * LicenseKit Client SDK — minimal plugin integration.
 *
 * Drop this snippet into your main plugin file (or wherever your bootstrap lives).
 * Adjust the slug, server URL, and option key to match your product.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/licensekit-client.php';

add_action(
	'plugins_loaded',
	static function () {
		\LicenseKit_Client_v1::boot(
			[
				'product_slug'       => 'my-awesome-plugin',
				'plugin_file'        => __FILE__,
				'item_type'          => 'plugin',
				'server_url'         => 'https://updates.example.com',
				'version'            => '1.0.0',
				'license_option_key' => 'myp_license',
				'text_domain'        => 'my-awesome-plugin',
				'render_field_hook'  => 'myp_render_license_field',
				'public_key'         => 'paste-base64-Ed25519-public-key-from-LicenseKit-Settings',
			]
		);
	},
	5
);

// Render the license field anywhere in your settings UI:
//
//     do_action( 'myp_render_license_field' );
