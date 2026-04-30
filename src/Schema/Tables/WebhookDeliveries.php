<?php
/**
 * Webhook delivery log — Action Scheduler-driven retries.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema\Tables;

defined( 'ABSPATH' ) || exit;

final class WebhookDeliveries {

	public static function name(): string {
		return 'licensekit_webhook_deliveries';
	}

	public static function ddl(): string {
		global $wpdb;
		$table   = $wpdb->prefix . self::name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			webhook_id bigint(20) unsigned NOT NULL,
			event varchar(64) NOT NULL,
			payload longtext NULL,
			response_code int(11) NULL,
			response_body text NULL,
			attempt int(10) unsigned NOT NULL DEFAULT 1,
			next_retry_at datetime NULL DEFAULT NULL,
			delivered_at datetime NULL DEFAULT NULL,
			created_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY webhook_event (webhook_id,event),
			KEY next_retry_at_idx (next_retry_at)
		) {$charset};";
	}
}
