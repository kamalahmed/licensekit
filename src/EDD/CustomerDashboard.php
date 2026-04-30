<?php
/**
 * Customer-facing license listing.
 *
 * Provides `[licensekit_customer_licenses]` for use on any page (typically the
 * EDD account/dashboard page). Shows licenses for the currently logged-in
 * customer with a click-to-copy key field and a self-rotate button.
 *
 * Self-rotate verifies ownership (current user's EDD customer matches the
 * license OR the email matches), is rate-limited (3 rotations / day / license),
 * and goes through `LicenseService::rotate_key()` so the audit log + webhook
 * fire normally and remaining validity is preserved.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\EDD;

use LicenseKit\Models\License;
use LicenseKit\Models\Log;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\LicenseService;
use LicenseKit\Services\RateLimiter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class CustomerDashboard {

	public const ROTATE_ACTION       = 'licensekit_customer_rotate';
	public const ROTATE_LIMIT_PER_DAY = 3;

	private LicenseRepository $licenses;
	private ProductRepository $products;
	private ActivationRepository $activations;
	private LicenseService $license_svc;

	public function __construct(
		LicenseRepository $licenses,
		ProductRepository $products,
		ActivationRepository $activations
	) {
		$this->licenses    = $licenses;
		$this->products    = $products;
		$this->activations = $activations;
		$this->license_svc = new LicenseService(
			$licenses,
			$products,
			$activations,
			new AuditLogger( new LogRepository() )
		);
	}

	public function register(): void {
		add_shortcode( 'licensekit_customer_licenses', [ $this, 'render_shortcode' ] );
		add_action( 'admin_post_' . self::ROTATE_ACTION, [ $this, 'handle_rotate' ] );
		add_action( 'admin_post_nopriv_' . self::ROTATE_ACTION, [ $this, 'handle_rotate' ] ); // logged-out hits 401 inside the handler.
	}

	public function render_shortcode( $atts = [], $content = '' ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your licenses.', 'licensekit' ) . '</p>';
		}

		$current = wp_get_current_user();
		$email   = is_object( $current ) ? (string) $current->user_email : '';

		$licenses = $this->licenses_for_user( (int) $current->ID, $email );
		if ( empty( $licenses ) ) {
			return '<p>' . esc_html__( 'No licenses found.', 'licensekit' ) . '</p>';
		}

		$rows = '';
		foreach ( $licenses as $license ) {
			$product   = $this->products->find( (int) $license->product_id );
			$activated = $this->activations->count_billable_active_for_license( (int) $license->id );
			$rows     .= $this->render_row( $license, $product, $activated );
		}

		ob_start();
		?>
		<?php $this->maybe_render_flash(); ?>
		<table class="lk-customer-licenses" style="width:100%;border-collapse:collapse;">
			<thead>
				<tr>
					<th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Product', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Key', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Status', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Sites', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Expires', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Actions', 'licensekit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	public function handle_rotate(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to rotate a license key.', 'licensekit' ), 401 );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		$id    = (int) ( $_POST['license_id'] ?? 0 );
		if ( ! wp_verify_nonce( $nonce, self::ROTATE_ACTION . '_' . $id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'licensekit' ), 403 );
		}

		$license = $this->licenses->find( $id );
		if ( ! $license instanceof License ) {
			wp_die( esc_html__( 'License not found.', 'licensekit' ), 404 );
		}
		if ( ! $this->user_owns_license( $license ) ) {
			wp_die( esc_html__( 'You do not own this license.', 'licensekit' ), 403 );
		}

		// Per-license rate limit on rotations.
		$bucket = 'lk_rotate:' . $id;
		if ( ! RateLimiter::attempt( $bucket, self::ROTATE_LIMIT_PER_DAY, DAY_IN_SECONDS ) ) {
			$this->set_flash( 'error', __( 'Rotation limit reached. Try again tomorrow.', 'licensekit' ) );
			$this->redirect_back();
			return;
		}

		$result = $this->license_svc->rotate_key( $id );
		if ( ! empty( $result['success'] ) ) {
			$this->set_flash( 'success', __( 'Key rotated. Update your installed sites with the new key.', 'licensekit' ) );
		} else {
			$this->set_flash( 'error', $result['message'] ?? __( 'Rotation failed.', 'licensekit' ) );
		}

		$this->redirect_back();
	}

	// ---------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------

	/** @return License[] */
	private function licenses_for_user( int $user_id, string $email ): array {
		$licenses = [];

		// Path 1 — EDD customer record linked to this WP user.
		if ( function_exists( 'edd_get_customer_by' ) && $user_id > 0 ) {
			$customer = edd_get_customer_by( 'user_id', $user_id );
			if ( $customer && ! empty( $customer->id ) ) {
				$licenses = $this->licenses->find_by_customer_id( (int) $customer->id );
			}
		}

		// Path 2 — email match (works for both EDD and WC customers).
		if ( empty( $licenses ) && '' !== $email ) {
			$licenses = $this->licenses->find_by_customer_email( $email );
		}

		return $licenses;
	}

	private function user_owns_license( License $license ): bool {
		$current = wp_get_current_user();
		if ( ! is_object( $current ) || empty( $current->ID ) ) {
			return false;
		}

		// EDD customer-id match.
		if ( function_exists( 'edd_get_customer_by' ) && null !== $license->customer_id ) {
			$customer = edd_get_customer_by( 'user_id', $current->ID );
			if ( $customer && (int) $customer->id === (int) $license->customer_id ) {
				return true;
			}
		}

		// WooCommerce wp-user-id match (stored on license meta by the WC bridge).
		$wc_user_id = (int) ( $license->meta['wc_user_id'] ?? 0 );
		if ( $wc_user_id > 0 && (int) $current->ID === $wc_user_id ) {
			return true;
		}

		// Email match (case-insensitive) — last-resort.
		if ( null !== $license->customer_email && '' !== $license->customer_email ) {
			return strcasecmp( (string) $current->user_email, (string) $license->customer_email ) === 0;
		}

		return false;
	}

	private function render_row( License $license, ?Product $product, int $activated ): string {
		$product_name = $product ? $product->name : __( '(unknown product)', 'licensekit' );
		$limit        = $license->is_unlimited()
			? __( 'unlimited', 'licensekit' )
			: (string) $license->activation_limit;
		$expires      = $license->is_lifetime()
			? __( 'Never', 'licensekit' )
			: (string) $license->expires_at;
		$raw_key      = $license->reveal_raw_key();
		$rotate_nonce = wp_create_nonce( self::ROTATE_ACTION . '_' . $license->id );

		ob_start();
		?>
		<tr>
			<td style="padding:6px;"><?php echo esc_html( $product_name ); ?></td>
			<td style="padding:6px;font-family:monospace;font-size:13px;">
				<?php if ( null !== $raw_key ) : ?>
					<input type="text" value="<?php echo esc_attr( $raw_key ); ?>" readonly
						onclick="this.select();document.execCommand && document.execCommand('copy');"
						title="<?php esc_attr_e( 'Click to copy', 'licensekit' ); ?>"
						style="width:100%;border:1px solid #ddd;padding:4px 6px;background:#f9f9f9;font-family:monospace;">
				<?php else : ?>
					<code><?php echo esc_html( $license->key_prefix ); ?>…</code>
					<br>
					<small><?php esc_html_e( '(legacy license — rotate to enable display)', 'licensekit' ); ?></small>
				<?php endif; ?>
			</td>
			<td style="padding:6px;">
				<span class="lk-status lk-status-<?php echo esc_attr( $license->status ); ?>">
					<?php echo esc_html( $this->status_label( $license->status ) ); ?>
				</span>
			</td>
			<td style="padding:6px;">
				<?php echo esc_html( $activated . ' / ' . $limit ); ?>
			</td>
			<td style="padding:6px;"><?php echo esc_html( $expires ); ?></td>
			<td style="padding:6px;">
				<?php if ( in_array( $license->status, [ License::STATUS_ACTIVE, License::STATUS_EXPIRED ], true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						style="margin:0;display:inline;"
						onsubmit="return confirm('<?php echo esc_js( __( 'Rotate the key? Your current key will stop working immediately. The new key keeps the existing expiry — no extra validity is added.', 'licensekit' ) ); ?>');">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ROTATE_ACTION ); ?>">
						<input type="hidden" name="license_id" value="<?php echo esc_attr( (string) $license->id ); ?>">
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $rotate_nonce ); ?>">
						<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $this->current_url() ); ?>">
						<button type="submit" class="button" style="font-size:12px;padding:2px 8px;">
							<?php esc_html_e( 'Rotate Key', 'licensekit' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	private function status_label( string $status ): string {
		$map = [
			License::STATUS_ACTIVE   => __( 'Active', 'licensekit' ),
			License::STATUS_EXPIRED  => __( 'Expired', 'licensekit' ),
			License::STATUS_DISABLED => __( 'Disabled', 'licensekit' ),
			License::STATUS_REVOKED  => __( 'Revoked', 'licensekit' ),
			License::STATUS_PENDING  => __( 'Pending', 'licensekit' ),
		];
		return $map[ $status ] ?? $status;
	}

	private function set_flash( string $type, string $message ): void {
		set_transient( $this->flash_key(), [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private function maybe_render_flash(): void {
		$flash = get_transient( $this->flash_key() );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $this->flash_key() );
		printf(
			'<div class="lk-customer-flash lk-customer-flash-%s" style="padding:8px 12px;border-radius:3px;margin-bottom:12px;background:%s;color:%s;">%s</div>',
			esc_attr( (string) $flash['type'] ),
			'success' === $flash['type'] ? '#d4edda' : '#f8d7da',
			'success' === $flash['type'] ? '#155724' : '#721c24',
			esc_html( (string) $flash['message'] )
		);
	}

	private function flash_key(): string {
		return 'lk_customer_flash_' . get_current_user_id();
	}

	private function current_url(): string {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security
		if ( '' !== $ref ) {
			return $ref;
		}
		// Best-effort current URL when JS isn't available.
		return home_url( $_SERVER['REQUEST_URI'] ?? '/' );
	}

	private function redirect_back(): void {
		$ref = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_wp_http_referer'] ) ) : home_url();
		wp_safe_redirect( $ref );
		exit;
	}
}
