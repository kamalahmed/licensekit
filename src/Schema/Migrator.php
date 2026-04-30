<?php
/**
 * Schema migrator. Tracks `licensekit_db_version` and runs idempotent installs +
 * forward-only migrations. Called on activation and on every page load via
 * `Plugin::boot()` (early-return when versions match — autoloaded option, no DB hit).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Schema;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	public const DB_VERSION = '1.0.3';

	private const TABLE_CLASSES = [
		Tables\Products::class,
		Tables\Releases::class,
		Tables\Licenses::class,
		Tables\Activations::class,
		Tables\ApiTokens::class,
		Tables\Webhooks::class,
		Tables\WebhookDeliveries::class,
		Tables\Logs::class,
	];

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::TABLE_CLASSES as $class ) {
			dbDelta( $class::ddl() );
		}

		// Ensure secrets exist (covers fresh installs + version bumps that
		// introduce new secret slots like the 1.0.2 Ed25519 keypair).
		\LicenseKit\Activator::bootstrap_secrets();

		update_option( 'licensekit_db_version', self::DB_VERSION, true );
	}

	/**
	 * Reinstall + run forward migrations on version mismatch.
	 *
	 * dbDelta is good at adding columns/indexes but unreliable at dropping them,
	 * so anything destructive runs as an explicit pre-step here before the
	 * `install()` call regenerates the rest.
	 */
	public static function maybe_upgrade(): void {
		$current = (string) get_option( 'licensekit_db_version', '0' );
		if ( $current === self::DB_VERSION ) {
			return;
		}

		if ( version_compare( $current, '1.0.1', '<' ) ) {
			// 1.0.1 — replace `license_site_status` with `license_site` (status is now app-managed, not part of the unique key).
			self::drop_index_if_exists( Tables\Activations::name(), 'license_site_status' );
		}

		self::install();
	}

	/**
	 * @return string[] List of fully-prefixed table names.
	 */
	public static function table_names(): array {
		global $wpdb;
		$names = [];
		foreach ( self::TABLE_CLASSES as $class ) {
			$names[] = $wpdb->prefix . $class::name();
		}
		return $names;
	}

	/**
	 * Drop an index by name. The table name is composed from `$wpdb->prefix`
	 * and one of our own constants (the table-class `name()` return) — neither
	 * is user input, so the schema-level interpolation is safe.
	 */
	private static function drop_index_if_exists( string $unprefixed_table, string $index_name ): void {
		global $wpdb;
		$table = $wpdb->prefix . $unprefixed_table;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL on our own prefixed table; cache layer is irrelevant for one-shot migrations.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table} WHERE Key_name = %s",
				$index_name
			)
		);
		if ( $exists ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$index_name}" );
		}
		// phpcs:enable
	}
}
