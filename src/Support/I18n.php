<?php
/**
 * Text-domain loader.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Support;

defined( 'ABSPATH' ) || exit;

final class I18n {

	public const TEXT_DOMAIN = 'licensekit';

	/**
	 * No-op since WordPress 6.7: plugins distributed via wp.org get their
	 * textdomain auto-loaded, and the `__()` calls scan `/languages/`
	 * directly. Self-hosted installs work the same way.
	 *
	 * Kept as a hookable shell so plugins/themes that want to customize
	 * translation behavior have a stable extension point.
	 */
	public static function register(): void {
		do_action( 'licensekit_i18n_loaded' );
	}
}
