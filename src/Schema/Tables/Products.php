<?php
/**
 * Products table — one row per licensed plugin/theme.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class Products {

	public static function name(): string {
		return 'licensekit_products';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			edd_download_id bigint(20) unsigned NULL,
			wc_product_id bigint(20) unsigned NULL,
			slug varchar(120) NOT NULL,
			name varchar(190) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'plugin',
			current_version varchar(32) NULL,
			current_release_id bigint(20) unsigned NULL,
			homepage_url varchar(255) NULL,
			author varchar(120) NULL,
			meta longtext NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY edd_download_id (edd_download_id),
			UNIQUE KEY wc_product_id (wc_product_id),
			KEY type_idx (type)
		) {$charset};";
	}
}
