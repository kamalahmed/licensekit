<?php
/**
 * Webhooks table — outbound event subscriptions.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class Webhooks {

	public static function name(): string {
		return 'licensekit_webhooks';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			endpoint_url varchar(255) NOT NULL,
			secret varchar(64) NOT NULL,
			events longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			last_response_code int(11) NULL,
			failure_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status)
		) {$charset};";
	}
}
