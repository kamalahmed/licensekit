<?php
/**
 * Audit log — license/release/admin actions.
 *
 * Pruned daily by `licensekit_daily_cron`; default retention 90 days, filterable.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class Logs {

	public static function name(): string {
		return 'licensekit_logs';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			actor_type varchar(20) NOT NULL DEFAULT 'system',
			actor_id bigint(20) unsigned NULL,
			action varchar(64) NOT NULL,
			subject_type varchar(40) NULL,
			subject_id bigint(20) unsigned NULL,
			ip varbinary(16) NULL,
			context longtext NULL,
			created_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY subject (subject_type,subject_id),
			KEY action_idx (action),
			KEY created_at_idx (created_at)
		) {$charset};";
	}
}
