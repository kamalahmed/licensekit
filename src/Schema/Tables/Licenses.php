<?php
/**
 * Licenses table — issued license keys.
 *
 * `key_hash` is the lookup column (peppered SHA-256). `key_encrypted` is a
 * sodium_secretbox-encrypted copy of the raw key, keyed by the pepper, so the
 * customer dashboard can reveal it on demand. Both columns can be NULL on
 * legacy rows from before the 1.0.2 migration.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class Licenses {

	public static function name(): string {
		return 'licensekit_licenses';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			key_hash char(64) NOT NULL,
			key_prefix char(8) NOT NULL,
			customer_id bigint(20) unsigned NULL,
			customer_email varchar(190) NULL,
			product_id bigint(20) unsigned NOT NULL,
			edd_order_id bigint(20) unsigned NULL,
			edd_price_id int(11) NULL,
			tier varchar(32) NOT NULL DEFAULT 'single',
			activation_limit int(10) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			issued_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			grace_until datetime NULL DEFAULT NULL,
			renewal_period varchar(16) NULL,
			parent_license_id bigint(20) unsigned NULL,
			meta longtext NULL,
			key_encrypted longtext NULL,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			KEY customer_id_idx (customer_id),
			KEY customer_email_idx (customer_email),
			KEY product_status (product_id,status),
			KEY expires_at_idx (expires_at),
			KEY edd_order_id_idx (edd_order_id),
			KEY parent_license_id_idx (parent_license_id)
		) {$charset};";
	}
}
