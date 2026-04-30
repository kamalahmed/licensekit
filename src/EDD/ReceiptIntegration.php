<?php
/**
 * Receipt + email integration.
 *
 * Three delivery surfaces for the raw license key (the "show once" disclosure):
 *   1. The post-checkout receipt page — table appended after the line items.
 *   2. The EDD purchase receipt email — auto-appended via the email body filter
 *      so vendors don't have to hand-edit their EDD email template.
 *   3. The `{licensekit_licenses}` email tag — registered for vendors who DO
 *      want to embed it explicitly somewhere specific in their template.
 *
 * After the per-order transient TTL expires the raw key is unrecoverable from
 * `Bridge`; the customer dashboard's `reveal_raw_key()` (sodium-decrypted from
 * the encrypted-at-rest column) becomes the canonical access path.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\EDD;

defined( 'ABSPATH' ) || exit;

final class ReceiptIntegration {

	private Bridge $bridge;

	public function __construct( Bridge $bridge ) {
		$this->bridge = $bridge;
	}

	public function register(): void {
		add_action( 'edd_add_email_tags', [ $this, 'register_tag' ] );

		// On-page receipt section after the line items.
		add_action( 'edd_payment_receipt_after', [ $this, 'render_receipt_section' ], 10, 2 );

		// Auto-append to the purchase receipt email body.
		add_filter( 'edd_email_message', [ $this, 'append_to_email' ], 10, 2 );
		add_filter( 'edd_email_html', [ $this, 'append_to_email' ], 10, 2 );
	}

	public function register_tag(): void {
		if ( ! function_exists( 'edd_add_email_tag' ) ) {
			return;
		}
		edd_add_email_tag(
			'licensekit_licenses',
			__( 'List of license keys issued for this order.', 'licensekit' ),
			[ $this, 'render_email_tag' ]
		);
	}

	public function render_email_tag( $payment_id ): string {
		return $this->build_text_block( $this->resolve_order_id( $payment_id ) );
	}

	/**
	 * Append a license-key block to the EDD purchase receipt email body when
	 * the email is for an order that has issued licenses. Idempotent — if the
	 * user already added `{licensekit_licenses}` to their template, the tag's
	 * own callback handles that and we skip the append.
	 */
	public function append_to_email( $message, $payment_id = 0 ) {
		$order_id = $this->resolve_order_id( $payment_id );
		if ( $order_id <= 0 ) {
			return $message;
		}
		// Skip if the user already placed the tag explicitly — `{licensekit_licenses}`
		// in their template gets replaced before this filter runs, and the produced
		// text starts with the same heading we'd output.
		if ( false !== strpos( (string) $message, 'LicenseKit-License-Block' ) ) {
			return $message;
		}
		$keys = $this->bridge->get_raw_keys_for_order( $order_id );
		if ( empty( $keys ) ) {
			return $message;
		}

		return $message . "\n\n" . $this->build_html_block( $keys );
	}

	public function render_receipt_section( $payment, $args ): void {
		$order_id = $this->resolve_order_id( $payment );
		if ( $order_id <= 0 ) {
			return;
		}
		$keys = $this->bridge->get_raw_keys_for_order( $order_id );
		if ( empty( $keys ) ) {
			return;
		}
		echo $this->build_html_block( $keys ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * EDD 3.x receipts may pass an Order object (`->id`), a legacy Payment
	 * (`->ID`), or a raw int. Handle all three.
	 *
	 * @param mixed $candidate
	 */
	private function resolve_order_id( $candidate ): int {
		if ( is_numeric( $candidate ) ) {
			return (int) $candidate;
		}
		if ( is_object( $candidate ) ) {
			if ( isset( $candidate->id ) ) {
				return (int) $candidate->id;
			}
			if ( isset( $candidate->ID ) ) {
				return (int) $candidate->ID;
			}
			if ( isset( $candidate->order_id ) ) {
				return (int) $candidate->order_id;
			}
		}
		return 0;
	}

	/**
	 * @param array<int, array{license_id:int, key:string, product_name:string, product_slug:string, expires_at:?string}> $keys
	 */
	private function build_html_block( array $keys ): string {
		ob_start();
		?>
		<!-- LicenseKit-License-Block -->
		<h3 style="margin-top:24px;"><?php esc_html_e( 'Your License Keys', 'licensekit' ); ?></h3>
		<p style="margin:0 0 8px 0;color:#555;font-size:14px;">
			<?php esc_html_e( 'Save these keys somewhere safe — they are also in your receipt email and will not be shown again on the receipt page.', 'licensekit' ); ?>
		</p>
		<table style="width:100%;border-collapse:collapse;border:1px solid #ddd;">
			<thead>
				<tr style="background:#f7f7f7;">
					<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Product', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'License Key', 'licensekit' ); ?></th>
					<th style="text-align:left;padding:8px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Expires', 'licensekit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $keys as $entry ) : ?>
					<tr>
						<td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $entry['product_name'] ); ?></td>
						<td style="padding:8px;border-bottom:1px solid #eee;font-family:monospace;font-size:13px;"><?php echo esc_html( $entry['key'] ); ?></td>
						<td style="padding:8px;border-bottom:1px solid #eee;">
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

	private function build_text_block( int $order_id ): string {
		$keys = $this->bridge->get_raw_keys_for_order( $order_id );
		if ( empty( $keys ) ) {
			return '';
		}
		$lines = [ '<!-- LicenseKit-License-Block -->' ];
		foreach ( $keys as $entry ) {
			$line = $entry['product_name'] . ': ' . $entry['key'];
			if ( ! empty( $entry['expires_at'] ) ) {
				$line .= ' (expires ' . $entry['expires_at'] . ')';
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}
}
