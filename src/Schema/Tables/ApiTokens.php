<?php
/**
 * Vendor admin API tokens table.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class ApiTokens {

	public static function name(): string {
		return 'licensekit_api_tokens';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			token_prefix char(8) NOT NULL,
			name varchar(120) NOT NULL,
			abilities longtext NULL,
			last_used_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			revoked_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id_idx (user_id)
		) {$charset};";
	}
}
