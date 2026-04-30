<?php
/**
 * Plugin Name:       LicenseKit
 * Plugin URI:        https://kamalahmed.me/licensekit
 * Description:       Self-hosted license issuance, validation, and update server for WordPress plugins and themes. Sells via Easy Digital Downloads. Includes a single-file client SDK for plugin/theme authors.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kamal Ahmed
 * Author URI:        https://kamalahmed.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       licensekit
 * Domain Path:       /languages
 *
 * @package LicenseKit
 */

defined( 'ABSPATH' ) || exit;

define( 'LICENSEKIT_VERSION', '0.1.0' );
define( 'LICENSEKIT_FILE', __FILE__ );
define( 'LICENSEKIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'LICENSEKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'LICENSEKIT_BASENAME', plugin_basename( __FILE__ ) );
define( 'LICENSEKIT_MIN_PHP', '7.4' );
define( 'LICENSEKIT_MIN_WP', '6.0' );

$licensekit_autoloader = LICENSEKIT_PATH . 'vendor/autoload.php';
if ( is_readable( $licensekit_autoloader ) ) {
	require_once $licensekit_autoloader;
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'LicenseKit\\';
			if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = LICENSEKIT_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( LICENSEKIT_FILE, [ \LicenseKit\Activator::class, 'run' ] );
register_deactivation_hook( LICENSEKIT_FILE, [ \LicenseKit\Deactivator::class, 'run' ] );

add_action(
	'plugins_loaded',
	static function () {
		\LicenseKit\Plugin::instance()->boot();
	},
	5
);
