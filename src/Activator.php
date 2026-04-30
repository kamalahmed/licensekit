<?php
/**
 * Activation handler — schema, capabilities, secrets, cron.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit;

use LicenseKit\Schema\Migrator;
use LicenseKit\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function run(): void {
		Migrator::install();
		Capabilities::add();
		self::bootstrap_secrets();
		self::schedule_cron();
	}

	/**
	 * Generate option-stored fallbacks for secrets that aren't defined in
	 * wp-config.php. The Ed25519 keypair is the primary signing material in
	 * production; HMAC stays as a fallback for sodium-less hosts. Both are
	 * provisioned so neither verification path can fail.
	 *
	 * Public + idempotent so the migrator can call it on version-bump page
	 * loads (existing installs that pre-date 1.0.2 don't have the keypair yet).
	 */
	public static function bootstrap_secrets(): void {
		$secrets = (array) get_option( 'licensekit_secrets', [] );

		foreach ( [ 'hash_pepper', 'download_secret', 'hmac_secret' ] as $key ) {
			if ( empty( $secrets[ $key ] ) ) {
				$secrets[ $key ] = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		// Ed25519 keypair for SDK response signing. Vendor distributes
		// `sign_public` in their plugin source; `sign_secret` stays server-side.
		if ( empty( $secrets['sign_secret'] ) && function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$keypair                = sodium_crypto_sign_keypair();
			$secrets['sign_secret'] = self::b64url( sodium_crypto_sign_secretkey( $keypair ) );
			$secrets['sign_public'] = self::b64url( sodium_crypto_sign_publickey( $keypair ) );
		}

		update_option( 'licensekit_secrets', $secrets, true );
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'licensekit_daily_cron' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'licensekit_daily_cron' );
		}
	}
}
