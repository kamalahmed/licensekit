<?php
/**
 * Auto-appends a "Your License Keys" block to the WooCommerce customer
 * "Order completed" email. Vendors don't need to edit any email template.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class EmailIntegration {

	private Bridge $bridge;

	public function __construct( Bridge $bridge ) {
		$this->bridge = $bridge;
	}

	public function register(): void {
		// Renders inside the email after the order details table.
		add_action( 'woocommerce_email_after_order_table', [ $this, 'render_in_email' ], 20, 4 );
		// Also renders on the post-checkout "Order received" / Thank You page.
		add_action( 'woocommerce_thankyou', [ $this, 'render_on_thankyou' ], 20, 1 );
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

	public function render_on_thankyou( int $order_id ): void {
		$keys = $this->bridge->get_raw_keys_for_order( $order_id );
		if ( empty( $keys ) ) {
			return;
		}
		echo $this->build_html_block( $keys ); // phpcs:ignore WordPress.Security.EscapeOutput
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
