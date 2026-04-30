<?php
/**
 * Releases table — versioned releases per product.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class Releases {

	public static function name(): string {
		return 'licensekit_releases';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			product_id bigint(20) unsigned NOT NULL,
			version varchar(32) NOT NULL,
			channel varchar(16) NOT NULL DEFAULT 'stable',
			file_path varchar(255) NULL,
			file_size bigint(20) unsigned NULL,
			file_hash char(64) NULL,
			signing_salt char(32) NOT NULL,
			changelog_md longtext NULL,
			requires_wp varchar(16) NULL,
			requires_php varchar(16) NULL,
			tested_up_to varchar(16) NULL,
			released_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			created_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_version (product_id,version),
			KEY product_channel_released (product_id,channel,released_at)
		) {$charset};";
	}
}
