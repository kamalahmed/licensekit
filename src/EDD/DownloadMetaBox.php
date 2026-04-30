<?php
/**
 * Per-download "LicenseKit Settings" meta box on the EDD download editor.
 *
 * Stores four post-meta values:
 *   _licensekit_enabled            (bool)   — flip licensing on for this download
 *   _licensekit_tier               (string) — display label (single / five / unlimited / custom)
 *   _licensekit_activation_limit   (int)    — 0 = unlimited
 *   _licensekit_expiry_period      (string) — e.g. `1y`, `6m`, or `lifetime`
 *
 * Static accessor methods are used by `Bridge` and `CustomerDashboard` so we
 * don't sprinkle `get_post_meta()` calls across the codebase.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\EDD;

defined( 'ABSPATH' ) || exit;

final class DownloadMetaBox {

	public const META_ENABLED          = '_licensekit_enabled';
	public const META_TIER             = '_licensekit_tier';
	public const META_ACTIVATION_LIMIT = '_licensekit_activation_limit';
	public const META_EXPIRY_PERIOD    = '_licensekit_expiry_period';

	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_download', [ $this, 'save_meta' ], 10, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'licensekit_settings',
			__( 'LicenseKit Settings', 'licensekit' ),
			[ $this, 'render' ],
			'download',
			'side',
			'default'
		);
	}

	public function render( \WP_Post $post ): void {
		$enabled = self::is_licensing_enabled( $post->ID );
		$tier    = self::get_tier( $post->ID );
		$limit   = self::get_activation_limit( $post->ID );
		$period  = self::get_expiry_period( $post->ID );

		wp_nonce_field( 'licensekit_save_download_meta', 'licensekit_meta_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="licensekit_enabled" value="1" <?php checked( $enabled ); ?>>
				<?php esc_html_e( 'Issue a license key on purchase', 'licensekit' ); ?>
			</label>
		</p>
		<p>
			<label for="licensekit_tier">
				<strong><?php esc_html_e( 'Tier', 'licensekit' ); ?></strong>
			</label>
			<select id="licensekit_tier" name="licensekit_tier" style="width:100%;">
				<?php foreach ( self::tier_options() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $tier, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="licensekit_activation_limit">
				<strong><?php esc_html_e( 'Sites per license', 'licensekit' ); ?></strong>
			</label>
			<input
				type="number"
				id="licensekit_activation_limit"
				name="licensekit_activation_limit"
				min="0"
				step="1"
				value="<?php echo esc_attr( (string) $limit ); ?>"
				style="width:100%;"
			>
			<span class="description"><?php esc_html_e( '0 = unlimited', 'licensekit' ); ?></span>
		</p>
		<p>
			<label for="licensekit_expiry_period">
				<strong><?php esc_html_e( 'Expiry', 'licensekit' ); ?></strong>
			</label>
			<select id="licensekit_expiry_period" name="licensekit_expiry_period" style="width:100%;">
				<?php foreach ( self::expiry_options() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $period, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function save_meta( int $post_id, \WP_Post $post ): void {
		// Standard guard rails — autosave, nonce, capability.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['licensekit_meta_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['licensekit_meta_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'licensekit_save_download_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = ! empty( $_POST['licensekit_enabled'] );
		update_post_meta( $post_id, self::META_ENABLED, $enabled ? '1' : '0' );

		$tier_input = isset( $_POST['licensekit_tier'] ) ? sanitize_key( wp_unslash( (string) $_POST['licensekit_tier'] ) ) : 'single';
		$tier       = array_key_exists( $tier_input, self::tier_options() ) ? $tier_input : 'single';
		update_post_meta( $post_id, self::META_TIER, $tier );

		$raw_limit = isset( $_POST['licensekit_activation_limit'] ) ? (int) $_POST['licensekit_activation_limit'] : 1;
		$limit     = max( 0, $raw_limit );
		update_post_meta( $post_id, self::META_ACTIVATION_LIMIT, $limit );

		$period_input = isset( $_POST['licensekit_expiry_period'] ) ? sanitize_key( wp_unslash( (string) $_POST['licensekit_expiry_period'] ) ) : '1y';
		$period       = array_key_exists( $period_input, self::expiry_options() ) ? $period_input : '1y';
		update_post_meta( $post_id, self::META_EXPIRY_PERIOD, $period );
	}

	// ---------------------------------------------------------------
	// Static accessors used by Bridge / dashboard
	// ---------------------------------------------------------------

	public static function is_licensing_enabled( int $download_id ): bool {
		return '1' === (string) get_post_meta( $download_id, self::META_ENABLED, true );
	}

	public static function get_tier( int $download_id ): string {
		$tier = (string) get_post_meta( $download_id, self::META_TIER, true );
		return '' !== $tier ? $tier : 'single';
	}

	public static function get_activation_limit( int $download_id ): int {
		$value = get_post_meta( $download_id, self::META_ACTIVATION_LIMIT, true );
		if ( '' === $value || null === $value ) {
			return 1;
		}
		return max( 0, (int) $value );
	}

	public static function get_expiry_period( int $download_id ): string {
		$period = (string) get_post_meta( $download_id, self::META_EXPIRY_PERIOD, true );
		return '' !== $period ? $period : '1y';
	}

	/**
	 * Tier labels — cosmetic only; the real enforcement is `activation_limit`.
	 * Filterable via `licensekit_tier_options` so vendors can use their own
	 * marketing labels (Personal / Business / Agency, etc.).
	 *
	 * @return array<string, string>
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
