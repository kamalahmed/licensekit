<?php
/**
 * Activations table — one row per (license, site) pair.
 *
 * The status column flips between 'active' / 'deactivated' / 'revoked' across
 * lifecycle events; the row itself is never deleted. Multi-cycle history
 * (activate → deactivate → reactivate) lives in `dlm_logs`, not as duplicate
 * rows here.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class Activations {

	public static function name(): string {
		return 'licensekit_activations';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			license_id bigint(20) unsigned NOT NULL,
			site_url varchar(255) NOT NULL,
			site_url_hash char(64) NOT NULL,
			site_environment varchar(20) NOT NULL DEFAULT 'unknown',
			activated_at datetime NULL DEFAULT NULL,
			last_seen_at datetime NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			client_ip varbinary(16) NULL,
			user_agent varchar(255) NULL,
			meta longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY license_site (license_id,site_url_hash),
			KEY license_status (license_id,status),
			KEY last_seen_at_idx (last_seen_at)
		) {$charset};";
	}
}
