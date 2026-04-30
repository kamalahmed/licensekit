<?php
/**
 * "LicenseKit" panel on the WooCommerce product editor.
 *
 * Adds a dedicated tab on the Product Data box (rather than tucking the fields
 * into the General tab) so vendors can find licensing settings predictably.
 *
 * Stores the same `_licensekit_*` post-meta keys as the EDD download meta box
 * so the static accessor methods can be reused by both bridges.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\WooCommerce;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WC handles its own nonce + auth on the product save flow; these reads happen inside `save()` and `save_variation_fields()` which WC only invokes after its own checks.

final class ProductSettings {

	public const META_ENABLED          = '_licensekit_enabled';
	public const META_TIER             = '_licensekit_tier';
	public const META_ACTIVATION_LIMIT = '_licensekit_activation_limit';
	public const META_EXPIRY_PERIOD    = '_licensekit_expiry_period';
	/** Per-variation flag: when "yes", the variation's own meta is used in place of the parent's defaults. */
	public const META_OVERRIDE         = '_licensekit_override';

	public function register(): void {
		// Parent (simple or variable parent).
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'register_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save' ], 10, 1 );

		// Per-variation override fields, rendered inside each variation row.
		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_fields' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_fields' ], 10, 2 );
	}

	public function register_tab( array $tabs ): array {
		$tabs['licensekit'] = [
			'label'    => __( 'LicenseKit', 'licensekit' ),
			'target'   => 'licensekit_product_data',
			'class'    => [],
			'priority' => 70,
		];
		return $tabs;
	}

	public function render_panel(): void {
		global $post;
		$product_id = isset( $post->ID ) ? (int) $post->ID : 0;

		$enabled = self::is_licensing_enabled( $product_id );
		$tier    = self::get_tier( $product_id );
		$limit   = self::get_activation_limit( $product_id );
		$period  = self::get_expiry_period( $product_id );
		?>
		<div id="licensekit_product_data" class="panel woocommerce_options_panel hidden">
			<?php
			woocommerce_wp_checkbox(
				[
					'id'          => self::META_ENABLED,
					'value'       => $enabled ? 'yes' : 'no',
					'cbvalue'     => 'yes',
					'label'       => __( 'Enable licensing', 'licensekit' ),
					'description' => __( 'Issue a license key when this product is purchased.', 'licensekit' ),
				]
			);

			woocommerce_wp_select(
				[
					'id'      => self::META_TIER,
					'value'   => $tier,
					'label'   => __( 'Tier', 'licensekit' ),
					'options' => self::tier_options(),
				]
			);

			woocommerce_wp_text_input(
				[
					'id'                => self::META_ACTIVATION_LIMIT,
					'value'             => (string) $limit,
					'label'             => __( 'Sites per license', 'licensekit' ),
					'description'       => __( '0 = unlimited', 'licensekit' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
				]
			);

			woocommerce_wp_select(
				[
					'id'      => self::META_EXPIRY_PERIOD,
					'value'   => $period,
					'label'   => __( 'Expiry', 'licensekit' ),
					'options' => self::expiry_options(),
				]
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render override fields per variation. WC includes/expands these inside
	 * each variation row in the Variations tab.
	 *
	 * @param int     $loop           Position in the variations loop.
	 * @param array   $variation_data Current variation meta.
	 * @param \WP_Post $variation     The variation post.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		$variation_id = (int) $variation->ID;
		$override     = self::variation_overrides( $variation_id );
		$tier         = self::get_tier( $variation_id );
		$limit        = self::get_activation_limit( $variation_id );
		$period       = self::get_expiry_period( $variation_id );
		?>
		<div class="lk-variation-fields" style="border-top:1px solid #eee;padding-top:8px;margin-top:8px;">
			<p class="form-row form-row-full">
				<label>
					<input type="checkbox"
						name="variable_licensekit_override[<?php echo esc_attr( (string) $loop ); ?>]"
						value="yes"
						<?php checked( $override ); ?>
					>
					<?php esc_html_e( 'Override LicenseKit settings for this variation', 'licensekit' ); ?>
				</label>
			</p>
			<p class="form-row form-row-first">
				<label><?php esc_html_e( 'Tier', 'licensekit' ); ?></label>
				<select name="variable_licensekit_tier[<?php echo esc_attr( (string) $loop ); ?>]">
					<?php foreach ( self::tier_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $tier, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-last">
				<label><?php esc_html_e( 'Sites per license', 'licensekit' ); ?></label>
				<input type="number" min="0" step="1"
					name="variable_licensekit_activation_limit[<?php echo esc_attr( (string) $loop ); ?>]"
					value="<?php echo esc_attr( (string) $limit ); ?>">
			</p>
			<p class="form-row form-row-full">
				<label><?php esc_html_e( 'Expiry', 'licensekit' ); ?></label>
				<select name="variable_licensekit_expiry_period[<?php echo esc_attr( (string) $loop ); ?>]">
					<?php foreach ( self::expiry_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $period, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the per-variation override fields. Called once per variation by WC.
	 *
	 * @param int $variation_id
	 * @param int $i             Index in the WC variation form arrays.
	 */
	public function save_variation_fields( int $variation_id, int $i ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing — WC handles its own nonce on the variation save.
		$override = isset( $_POST['variable_licensekit_override'][ $i ] ) && 'yes' === $_POST['variable_licensekit_override'][ $i ];
		update_post_meta( $variation_id, self::META_OVERRIDE, $override ? 'yes' : 'no' );

		$tier_input = isset( $_POST['variable_licensekit_tier'][ $i ] )
			? sanitize_key( wp_unslash( (string) $_POST['variable_licensekit_tier'][ $i ] ) )
			: 'single';
		$tier = array_key_exists( $tier_input, self::tier_options() ) ? $tier_input : 'single';
		update_post_meta( $variation_id, self::META_TIER, $tier );

		$raw_limit = isset( $_POST['variable_licensekit_activation_limit'][ $i ] )
			? (int) $_POST['variable_licensekit_activation_limit'][ $i ]
			: 1;
		update_post_meta( $variation_id, self::META_ACTIVATION_LIMIT, max( 0, $raw_limit ) );

		$period_input = isset( $_POST['variable_licensekit_expiry_period'][ $i ] )
			? sanitize_key( wp_unslash( (string) $_POST['variable_licensekit_expiry_period'][ $i ] ) )
			: '1y';
		$period = array_key_exists( $period_input, self::expiry_options() ) ? $period_input : '1y';
		update_post_meta( $variation_id, self::META_EXPIRY_PERIOD, $period );
		// phpcs:enable
	}

	public function save( int $post_id ): void {
		// Capability + nonce are handled by WC's own product save flow.
		$enabled = isset( $_POST[ self::META_ENABLED ] ) && 'yes' === $_POST[ self::META_ENABLED ] ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, self::META_ENABLED, $enabled );

		$tier_input = isset( $_POST[ self::META_TIER ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_TIER ] ) ) : 'single'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tier       = array_key_exists( $tier_input, self::tier_options() ) ? $tier_input : 'single';
		update_post_meta( $post_id, self::META_TIER, $tier );

		$raw_limit = isset( $_POST[ self::META_ACTIVATION_LIMIT ] ) ? (int) $_POST[ self::META_ACTIVATION_LIMIT ] : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, self::META_ACTIVATION_LIMIT, max( 0, $raw_limit ) );

		$period_input = isset( $_POST[ self::META_EXPIRY_PERIOD ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_EXPIRY_PERIOD ] ) ) : '1y'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$period       = array_key_exists( $period_input, self::expiry_options() ) ? $period_input : '1y';
		update_post_meta( $post_id, self::META_EXPIRY_PERIOD, $period );
	}

	// -----------------------------------------------------------------
	// Static accessors (mirror EDD\DownloadMetaBox so bridges can share)
	// -----------------------------------------------------------------

	public static function is_licensing_enabled( int $product_id ): bool {
		return 'yes' === (string) get_post_meta( $product_id, self::META_ENABLED, true );
	}

	public static function get_tier( int $product_id ): string {
		$tier = (string) get_post_meta( $product_id, self::META_TIER, true );
		return '' !== $tier ? $tier : 'single';
	}

	public static function get_activation_limit( int $product_id ): int {
		$value = get_post_meta( $product_id, self::META_ACTIVATION_LIMIT, true );
		if ( '' === $value || null === $value ) {
			return 1;
		}
		return max( 0, (int) $value );
	}

	public static function get_expiry_period( int $product_id ): string {
		$period = (string) get_post_meta( $product_id, self::META_EXPIRY_PERIOD, true );
		return '' !== $period ? $period : '1y';
	}

	/**
	 * Whether a variation has its own override flag set. When false, the parent
	 * product's settings should be used.
	 */
	public static function variation_overrides( int $variation_id ): bool {
		return 'yes' === (string) get_post_meta( $variation_id, self::META_OVERRIDE, true );
	}

	/**
	 * Resolve effective licensing settings for a (parent, variation) pair.
	 *
	 * Variable products: if `$variation_id > 0` AND the variation has the
	 * override flag on, the variation's own meta wins. Otherwise the parent's
	 * defaults apply. Simple products: pass `0` for `$variation_id`.
	 *
	 * @return array{tier:string, activation_limit:int, expiry_period:string}
	 */
	public static function effective_settings( int $variation_id, int $parent_product_id ): array {
		if ( $variation_id > 0 && self::variation_overrides( $variation_id ) ) {
			return [
				'tier'             => self::get_tier( $variation_id ),
				'activation_limit' => self::get_activation_limit( $variation_id ),
				'expiry_period'    => self::get_expiry_period( $variation_id ),
			];
		}
		return [
			'tier'             => self::get_tier( $parent_product_id ),
			'activation_limit' => self::get_activation_limit( $parent_product_id ),
			'expiry_period'    => self::get_expiry_period( $parent_product_id ),
		];
	}

	/**
	 * @return array<string, string>
	 *
	 * Vendors can add custom labels via the `licensekit_tier_options` filter:
	 *
	 *     add_filter( 'licensekit_tier_options', function ( $tiers ) {
	 *         $tiers['personal']   = __( 'Personal (1 site)' );
	 *         $tiers['agency']     = __( 'Agency (50 sites)' );
	 *         return $tiers;
	 *     } );
	 *
	 * The label is purely cosmetic — the actual enforcement uses
	 * `activation_limit`. Set both consistently in your product settings.
	 */
	public static function tier_options(): array {
		$tiers = [
			'single'    => __( 'Single site', 'licensekit' ),
			'five'      => __( 'Five sites', 'licensekit' ),
			'unlimited' => __( 'Unlimited sites', 'licensekit' ),
			'custom'    => __( 'Custom (use limit below)', 'licensekit' ),
		];
		return (array) apply_filters( 'licensekit_tier_options', $tiers );
	}

	/** @return array<string, string> */
	public static function expiry_options(): array {
		$periods = [
			'1y'       => __( '1 year', 'licensekit' ),
			'6m'       => __( '6 months', 'licensekit' ),
			'1m'       => __( '1 month', 'licensekit' ),
			'lifetime' => __( 'Lifetime (no expiry)', 'licensekit' ),
		];
		return (array) apply_filters( 'licensekit_expiry_options', $periods );
	}
}
