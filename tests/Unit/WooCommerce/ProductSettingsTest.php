<?php

declare( strict_types=1 );

namespace LicenseKit\Tests\Unit\WooCommerce;

use LicenseKit\WooCommerce\ProductSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the static accessors used by the WC bridge. Save logic and panel
 * rendering depend on WooCommerce form helpers and need an integration env;
 * not testable here.
 */
final class ProductSettingsTest extends TestCase {

	protected function setUp(): void {
		// Override get_post_meta with our shim for this test class.
		$GLOBALS['__lk_postmeta'] = [];
		if ( ! function_exists( 'get_post_meta' ) ) {
			eval( 'function get_post_meta($id, $key, $single = false) { return $GLOBALS["__lk_postmeta"][$id][$key] ?? ""; }' );
		}
	}

	public function test_is_licensing_enabled_default_off(): void {
		$this->assertFalse( ProductSettings::is_licensing_enabled( 99 ) );
	}

	public function test_is_licensing_enabled_when_yes(): void {
		$GLOBALS['__lk_postmeta'][99][ ProductSettings::META_ENABLED ] = 'yes';
		$this->assertTrue( ProductSettings::is_licensing_enabled( 99 ) );
	}

	public function test_get_tier_defaults_to_single(): void {
		$this->assertSame( 'single', ProductSettings::get_tier( 1 ) );
		$GLOBALS['__lk_postmeta'][1][ ProductSettings::META_TIER ] = 'unlimited';
		$this->assertSame( 'unlimited', ProductSettings::get_tier( 1 ) );
	}

	public function test_get_activation_limit_defaults_to_one(): void {
		$this->assertSame( 1, ProductSettings::get_activation_limit( 1 ) );
		$GLOBALS['__lk_postmeta'][1][ ProductSettings::META_ACTIVATION_LIMIT ] = '5';
		$this->assertSame( 5, ProductSettings::get_activation_limit( 1 ) );
	}

	public function test_get_activation_limit_clamps_negative(): void {
		$GLOBALS['__lk_postmeta'][1][ ProductSettings::META_ACTIVATION_LIMIT ] = '-3';
		$this->assertSame( 0, ProductSettings::get_activation_limit( 1 ) );
	}

	public function test_get_expiry_period_defaults_to_1y(): void {
		$this->assertSame( '1y', ProductSettings::get_expiry_period( 1 ) );
		$GLOBALS['__lk_postmeta'][1][ ProductSettings::META_EXPIRY_PERIOD ] = '6m';
		$this->assertSame( '6m', ProductSettings::get_expiry_period( 1 ) );
	}

	public function test_tier_options_includes_standard_tiers(): void {
		$opts = ProductSettings::tier_options();
		$this->assertArrayHasKey( 'single', $opts );
		$this->assertArrayHasKey( 'five', $opts );
		$this->assertArrayHasKey( 'unlimited', $opts );
		$this->assertArrayHasKey( 'custom', $opts );
	}

	public function test_expiry_options_includes_lifetime(): void {
		$this->assertArrayHasKey( 'lifetime', ProductSettings::expiry_options() );
	}

	public function test_variation_overrides_default_false(): void {
		$this->assertFalse( ProductSettings::variation_overrides( 200 ) );
	}

	public function test_variation_overrides_true_when_meta_yes(): void {
		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_OVERRIDE ] = 'yes';
		$this->assertTrue( ProductSettings::variation_overrides( 200 ) );
	}

	public function test_effective_settings_falls_back_to_parent_when_no_override(): void {
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_TIER ]             = 'unlimited';
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_ACTIVATION_LIMIT ] = '0';
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_EXPIRY_PERIOD ]    = '1y';

		// Variation 200 has settings but no override flag → parent wins.
		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_TIER ]             = 'single';
		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_ACTIVATION_LIMIT ] = '1';

		$result = ProductSettings::effective_settings( 200, 100 );
		$this->assertSame( 'unlimited', $result['tier'] );
		$this->assertSame( 0, $result['activation_limit'] );
		$this->assertSame( '1y', $result['expiry_period'] );
	}

	public function test_effective_settings_uses_variation_when_override_set(): void {
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_TIER ]             = 'unlimited';
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_ACTIVATION_LIMIT ] = '0';
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_EXPIRY_PERIOD ]    = 'lifetime';

		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_OVERRIDE ]         = 'yes';
		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_TIER ]             = 'five';
		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_ACTIVATION_LIMIT ] = '5';
		$GLOBALS['__lk_postmeta'][200][ ProductSettings::META_EXPIRY_PERIOD ]    = '6m';

		$result = ProductSettings::effective_settings( 200, 100 );
		$this->assertSame( 'five', $result['tier'] );
		$this->assertSame( 5, $result['activation_limit'] );
		$this->assertSame( '6m', $result['expiry_period'] );
	}

	public function test_effective_settings_uses_parent_when_variation_id_zero(): void {
		// Simple product (no variation) — variation_id = 0, parent always wins.
		$GLOBALS['__lk_postmeta'][100][ ProductSettings::META_TIER ] = 'five';
		$result = ProductSettings::effective_settings( 0, 100 );
		$this->assertSame( 'five', $result['tier'] );
	}
}
