<?php
/**
 * Uninstall handler.
 *
 * Drops LicenseKit tables and options ONLY when the operator opted in via the
 * `licensekit_delete_data_on_uninstall` setting. Default is to preserve data.
 *
 * @package LicenseKit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'licensekit_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$licensekit_tables = [
	'licensekit_logs',
	'licensekit_webhook_deliveries',
	'licensekit_webhooks',
	'licensekit_api_tokens',
	'licensekit_activations',
	'licensekit_licenses',
	'licensekit_releases',
	'licensekit_products',
];

foreach ( $licensekit_tables as $licensekit_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$licensekit_table}" ); // phpcs:ignore WordPress.DB
}

$licensekit_options = [
	'licensekit_db_version',
	'licensekit_settings',
	'licensekit_secrets',
	'licensekit_delete_data_on_uninstall',
];

foreach ( $licensekit_options as $licensekit_option ) {
	delete_option( $licensekit_option );
}

wp_clear_scheduled_hook( 'licensekit_daily_cron' );
