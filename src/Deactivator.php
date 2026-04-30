<?php
/**
 * Deactivation handler — clear scheduled events and transients.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function run(): void {
		wp_clear_scheduled_hook( 'licensekit_daily_cron' );
	}
}
