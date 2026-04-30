<?php
/**
 * Auto-appends a "Your License Keys" block to the WooCommerce customer
 * "Order completed" email. Vendors don't need to edit any email template.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\WooCommerce;

use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\EncryptedKey;

defined( 'ABSPATH' ) || exit;

final class EmailIntegration {

	private Bridge $bridge;
	private LicenseRepository $licenses;
	private ProductRepository $products;

	public function __construct(
		Bridge $bridge,
		?LicenseRepository $licenses = null,
		?ProductRepository $products = null
	) {
		$this->bridge   = $bridge;
		$this->licenses = $licenses ?? new LicenseRepository();
		$this->products = $products ?? new ProductRepository();
	}

	public function register(): void {
		// Email path — runs inside `templates/emails/email-order-details.php`.
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_in_email' ], 20, 4 );

		// Front-end path — runs inside `templates/order/order-details.php`, which
		// is what both the post-checkout "Order received" / Thank You page AND the
		// `/my-account/view-order/{id}/` page render. Hooking it here means a
		// returning customer can come back days later and still see their keys.
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_on_order_details' ], 20, 1 );
	}

	/**
	 * @param object $order        WC_Order
	 * @param bool   $sent_to_admin
	 * @param bool   $plain_text
	 * @param object $email        WC_Email
	 */
	public function render_in_email( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! is_object( $order ) ) {
			return;
		}

		$keys = $this->bridge->get_raw_keys_for_order( (int) $order->get_id() );
		if ( empty( $keys ) ) {
			return;
		}

		echo $plain_text
			? $this->build_text_block( $keys ) // phpcs:ignore WordPress.Security.EscapeOutput
			: $this->build_html_block( $keys ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * @param object $order WC_Order
	 */
	public function render_on_order_details( $order ): void {
		if ( ! is_object( $order ) ) {
			return;
		}
		$order_id = (int) $order->get_id();

		// Path 1 — fresh keys captured in the issue-time transient. Works for
		// both logged-in customers and the guest-checkout flow because the
		// transient is gated by the order id only.
		$keys = $this->bridge->get_raw_keys_for_order( $order_id );

		// Path 2 — transient has aged out. The customer is revisiting their
		// order page later. Decrypt persisted keys, but ONLY for a logged-in
		// user who actually owns this order. Guests are never shown decrypted
		// keys via this path; for them the receipt email and the in-window
		// thank-you page were the disclosure surface.
		if ( empty( $keys ) && $this->current_user_owns_order( $order ) ) {
			$keys = $this->build_keys_from_db( $order );
		}

		if ( empty( $keys ) ) {
			return;
		}

		echo $this->build_html_block( $keys ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function current_user_owns_order( $order ): bool {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}
		$current_id = (int) get_current_user_id();
		if ( $current_id <= 0 ) {
			return false;
		}
		// WC stores the buyer wp_user_id on the order; matches non-guest checkouts.
		if ( method_exists( $order, 'get_user_id' ) && (int) $order->get_user_id() === $current_id ) {
			return true;
		}
		// Shop manager / admin viewing on behalf of customer.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Rebuild the per-key view-model from line-item meta + the encrypted key
	 * stored on the license row. Returns the same shape as the transient.
	 *
	 * @return array<int, array{license_id:int, key:string, product_name:string, product_slug:string, expires_at:?string}>
	 */
	private function build_keys_from_db( $order ): array {
		$out = [];
		foreach ( $order->get_items() as $item ) {
			$ids = $item->get_meta( '_licensekit_license_ids', true );
			if ( ! is_array( $ids ) || empty( $ids ) ) {
				continue;
			}
			foreach ( $ids as $lid ) {
				$lid = (int) $lid;
				if ( $lid <= 0 ) {
					continue;
				}
				$license = $this->licenses->find( $lid );
				if ( null === $license ) {
					continue;
				}
				$plain = EncryptedKey::decrypt( $license->key_encrypted );
				if ( null === $plain || '' === $plain ) {
					// Key was issued before encryption was available, or libsodium
					// is missing on this host. Surface the prefix so the customer
					// can correlate to their dashboard, without leaking secrets.
					$plain = $license->key_prefix . '…';
				}
				$product      = $this->products->find( (int) $license->product_id );
				$product_name = $product ? (string) $product->name : '';
				$product_slug = $product ? (string) $product->slug : '';
				$out[] = [
					'license_id'   => $lid,
					'key'          => $plain,
					'product_name' => $product_name,
					'product_slug' => $product_slug,
					'expires_at'   => $license->expires_at,
				];
			}
		}
		return $out;
	}

	private function build_html_block( array $keys ): string {
		ob_start();
		?>
		<!-- LicenseKit-License-Block -->
		<h2 style="margin-top:24px;color:#2a5db0;"><?php esc_html_e( 'Your License Keys', 'licensekit' ); ?></h2>
		<p style="margin:0 0 8px 0;color:#555;font-size:14px;">
			<?php esc_html_e( 'Save these keys somewhere safe — they are also in your receipt email and will not be shown again here.', 'licensekit' ); ?>
		</p>
		<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;">
			<thead>
				<tr style="background:#f7f7f7;">
					<th align="left" style="border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Product', 'licensekit' ); ?></th>
					<th align="left" style="border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'License Key', 'licensekit' ); ?></th>
					<th align="left" style="border-bottom:1px solid #e5e5e5;"><?php esc_html_e( 'Expires', 'licensekit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $keys as $entry ) : ?>
					<tr>
						<td style="border-bottom:1px solid #eee;"><?php echo esc_html( $entry['product_name'] ); ?></td>
						<td style="border-bottom:1px solid #eee;font-family:monospace;font-size:13px;"><?php echo esc_html( $entry['key'] ); ?></td>
						<td style="border-bottom:1px solid #eee;">
							<?php
							echo $entry['expires_at']
								? esc_html( $entry['expires_at'] )
								: esc_html__( 'Never', 'licensekit' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<!-- /LicenseKit-License-Block -->
		<?php
		return (string) ob_get_clean();
	}

	private function build_text_block( array $keys ): string {
		$lines = [
			'',
			'== ' . __( 'Your License Keys', 'licensekit' ) . ' ==',
		];
		foreach ( $keys as $entry ) {
			$line = $entry['product_name'] . ': ' . $entry['key'];
			if ( ! empty( $entry['expires_at'] ) ) {
				$line .= ' (expires ' . $entry['expires_at'] . ')';
			}
			$lines[] = $line;
		}
		$lines[] = '';
		return implode( "\n", $lines );
	}
}
