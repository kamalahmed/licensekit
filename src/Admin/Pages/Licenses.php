<?php
/**
 * Licenses admin page — list, manual issue, edit, rotate, extend.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\License;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\LicenseService;
use LicenseKit\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Licenses extends AbstractPage {

	private LicenseService $svc;
	private LicenseRepository $licenses;
	private ProductRepository $products;
	private ActivationRepository $activations;

	public function __construct( LicenseService $svc, LicenseRepository $licenses, ProductRepository $products, ActivationRepository $activations ) {
		$this->svc         = $svc;
		$this->licenses    = $licenses;
		$this->products    = $products;
		$this->activations = $activations;
	}

	public static function make(): self {
		$audit       = new AuditLogger( new LogRepository() );
		$licenses    = new LicenseRepository();
		$products    = new ProductRepository();
		$activations = new ActivationRepository();
		return new self( new LicenseService( $licenses, $products, $activations, $audit ), $licenses, $products, $activations );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE_LICENSES );

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( 'issue' === $action ) {
				$this->handle_issue();
			} elseif ( 'rotate' === $action ) {
				$this->handle_rotate();
			} elseif ( 'extend' === $action ) {
				$this->handle_extend();
			} elseif ( 'set_status' === $action ) {
				$this->handle_set_status();
			}
		}

		if ( 'new' === $action ) {
			$this->render_issue_form();
			return;
		}
		if ( 'edit' === $action ) {
			$this->render_edit();
			return;
		}

		$this->render_list();
	}

	private function render_list(): void {
		$this->open( __( 'Licenses', 'licensekit' ) );
		echo '<a href="' . esc_url( $this->admin_url( 'licensekit-licenses', [ 'action' => 'new' ] ) ) . '" class="page-title-action">'
			. esc_html__( 'Issue License', 'licensekit' ) . '</a>';

		// Filters
		$product_filter = (int) ( $_REQUEST['product_id'] ?? 0 );
		$status_filter  = sanitize_key( wp_unslash( (string) ( $_REQUEST['status'] ?? '' ) ) );
		$email_filter   = sanitize_email( wp_unslash( (string) ( $_REQUEST['customer_email'] ?? '' ) ) );

		?>
		<form method="get" class="lk-filters">
			<input type="hidden" name="page" value="licensekit-licenses">
			<select name="product_id">
				<option value="0"><?php esc_html_e( 'Any product', 'licensekit' ); ?></option>
				<?php foreach ( $this->products->find_all( 100 ) as $p ) : ?>
					<option value="<?php echo esc_attr( (string) $p->id ); ?>" <?php selected( $product_filter, (int) $p->id ); ?>>
						<?php echo esc_html( $p->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value=""><?php esc_html_e( 'Any status', 'licensekit' ); ?></option>
				<?php foreach ( [ 'active', 'expired', 'disabled', 'revoked', 'pending' ] as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="email" name="customer_email" placeholder="<?php esc_attr_e( 'Customer email', 'licensekit' ); ?>" value="<?php echo esc_attr( $email_filter ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'licensekit' ); ?></button>
		</form>
		<?php

		if ( '' !== $email_filter ) {
			$rows = $this->licenses->find_by_customer_email( $email_filter );
		} elseif ( $product_filter > 0 ) {
			$rows = $this->licenses->find_for_product( $product_filter, '' !== $status_filter ? $status_filter : null, 100 );
		} else {
			// No specific filter — most recent overall (we don't have a generic "find recent" so look at any product).
			$all_products = $this->products->find_all( 100 );
			$rows         = [];
			foreach ( $all_products as $p ) {
				$rows = array_merge( $rows, $this->licenses->find_for_product( (int) $p->id, '' !== $status_filter ? $status_filter : null, 50 ) );
			}
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No licenses match those filters.', 'licensekit' ) . '</p>';
			$this->close();
			return;
		}

		echo '<table class="wp-list-table widefat striped lk-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Key Prefix', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Product', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Tier', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Activations', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Expires', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'licensekit' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $l ) {
			/** @var License $l */
			$product   = $this->products->find( (int) $l->product_id );
			$activated = $this->activations->count_billable_active_for_license( (int) $l->id );
			$limit_str = $l->is_unlimited() ? '∞' : (string) $l->activation_limit;
			$edit_url  = $this->admin_url( 'licensekit-licenses', [ 'action' => 'edit', 'id' => $l->id ] );
			echo '<tr>';
			echo '<td class="lk-mono"><a href="' . esc_url( $edit_url ) . '">' . esc_html( $l->key_prefix ) . '…</a></td>';
			echo '<td>' . esc_html( $product ? $product->name : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $l->customer_email ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( $l->tier ) . '</td>';
			echo '<td><span class="lk-status lk-status-' . esc_attr( $l->status ) . '">' . esc_html( $l->status ) . '</span></td>';
			echo '<td>' . esc_html( $activated . ' / ' . $limit_str ) . '</td>';
			echo '<td>' . esc_html( $l->is_lifetime() ? __( 'Never', 'licensekit' ) : (string) $l->expires_at ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'licensekit' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->close();
	}

	private function render_issue_form(): void {
		$this->open( __( 'Issue License', 'licensekit' ) );

		// Display raw key flash if it's set (post-creation)
		$raw_flash = get_transient( 'lk_raw_key_' . get_current_user_id() );
		if ( is_string( $raw_flash ) && '' !== $raw_flash ) {
			delete_transient( 'lk_raw_key_' . get_current_user_id() );
			?>
			<div class="lk-key-once">
				<strong><?php esc_html_e( 'New license key (shown once):', 'licensekit' ); ?></strong>
				<code><?php echo esc_html( $raw_flash ); ?></code>
				<p class="description"><?php esc_html_e( 'Copy this now — only the hash is stored. Use Rotate to generate a new key if lost.', 'licensekit' ); ?></p>
			</div>
			<?php
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'licensekit_issue_license' ); ?>
			<input type="hidden" name="action" value="issue">
			<table class="form-table">
				<tr>
					<th><label for="product_id"><?php esc_html_e( 'Product', 'licensekit' ); ?></label></th>
					<td>
						<select name="product_id" id="product_id" required>
							<option value=""><?php esc_html_e( '— select —', 'licensekit' ); ?></option>
							<?php foreach ( $this->products->find_all( 100 ) as $p ) : ?>
								<option value="<?php echo esc_attr( (string) $p->id ); ?>"><?php echo esc_html( $p->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="customer_email"><?php esc_html_e( 'Customer Email', 'licensekit' ); ?></label></th>
					<td><input type="email" name="customer_email" id="customer_email" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label for="tier"><?php esc_html_e( 'Tier', 'licensekit' ); ?></label></th>
					<td>
						<select name="tier" id="tier">
							<option value="single">single</option>
							<option value="five">five</option>
							<option value="unlimited">unlimited</option>
							<option value="custom">custom</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="activation_limit"><?php esc_html_e( 'Activation Limit', 'licensekit' ); ?></label></th>
					<td><input type="number" name="activation_limit" id="activation_limit" min="0" value="1"> <span class="description"><?php esc_html_e( '0 = unlimited', 'licensekit' ); ?></span></td>
				</tr>
				<tr>
					<th><label for="expiry_period"><?php esc_html_e( 'Expiry', 'licensekit' ); ?></label></th>
					<td>
						<select name="expiry_period" id="expiry_period">
							<option value="1y">1 year</option>
							<option value="6m">6 months</option>
							<option value="1m">1 month</option>
							<option value="lifetime">lifetime</option>
						</select>
					</td>
				</tr>
			</table>
			<p>
				<button class="button button-primary"><?php esc_html_e( 'Issue License', 'licensekit' ); ?></button>
				<a href="<?php echo esc_url( $this->admin_url( 'licensekit-licenses' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'licensekit' ); ?></a>
			</p>
		</form>
		<?php
		$this->close();
	}

	private function render_edit(): void {
		$id      = (int) ( $_REQUEST['id'] ?? 0 );
		$license = $this->licenses->find( $id );
		if ( ! $license instanceof License ) {
			wp_safe_redirect( $this->admin_url( 'licensekit-licenses' ) );
			exit;
		}
		$product = $this->products->find( (int) $license->product_id );

		$this->open( __( 'License Detail', 'licensekit' ) );

		// Show rotated key flash
		$raw_flash = get_transient( 'lk_raw_key_' . get_current_user_id() );
		if ( is_string( $raw_flash ) && '' !== $raw_flash ) {
			delete_transient( 'lk_raw_key_' . get_current_user_id() );
			echo '<div class="lk-key-once"><strong>' . esc_html__( 'New rotated key:', 'licensekit' ) . '</strong> <code>'
				. esc_html( $raw_flash ) . '</code></div>';
		}
		?>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Key Prefix', 'licensekit' ); ?></th><td class="lk-mono"><?php echo esc_html( $license->key_prefix ); ?>…</td></tr>
			<tr><th><?php esc_html_e( 'Product', 'licensekit' ); ?></th><td><?php echo esc_html( $product ? $product->name : '—' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Customer', 'licensekit' ); ?></th><td><?php echo esc_html( (string) ( $license->customer_email ?? '—' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Status', 'licensekit' ); ?></th><td><span class="lk-status lk-status-<?php echo esc_attr( $license->status ); ?>"><?php echo esc_html( $license->status ); ?></span></td></tr>
			<tr><th><?php esc_html_e( 'Tier', 'licensekit' ); ?></th><td><?php echo esc_html( $license->tier . ' (' . $license->activation_limit . ')' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Issued', 'licensekit' ); ?></th><td><?php echo esc_html( (string) $license->issued_at ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Expires', 'licensekit' ); ?></th><td><?php echo esc_html( $license->is_lifetime() ? __( 'Never', 'licensekit' ) : (string) $license->expires_at ); ?></td></tr>
			<tr><th><?php esc_html_e( 'EDD Order', 'licensekit' ); ?></th><td><?php echo $license->edd_order_id ? '#' . esc_html( (string) $license->edd_order_id ) : '—'; ?></td></tr>
		</table>

		<h2><?php esc_html_e( 'Actions', 'licensekit' ); ?></h2>
		<form method="post" style="display:inline-block;margin-right:12px;">
			<?php wp_nonce_field( 'licensekit_rotate_license' ); ?>
			<input type="hidden" name="action" value="rotate">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<button class="button" onclick="return confirm('<?php echo esc_js( __( 'The current key will stop working. Continue?', 'licensekit' ) ); ?>')">
				<?php esc_html_e( 'Rotate Key', 'licensekit' ); ?>
			</button>
		</form>

		<form method="post" style="display:inline-block;margin-right:12px;">
			<?php wp_nonce_field( 'licensekit_extend_license' ); ?>
			<input type="hidden" name="action" value="extend">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<select name="period">
				<option value="1y">+ 1 year</option>
				<option value="6m">+ 6 months</option>
				<option value="1m">+ 1 month</option>
			</select>
			<button class="button"><?php esc_html_e( 'Extend Expiry', 'licensekit' ); ?></button>
		</form>

		<form method="post" style="display:inline-block;">
			<?php wp_nonce_field( 'licensekit_set_status' ); ?>
			<input type="hidden" name="action" value="set_status">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<select name="status">
				<?php foreach ( [ 'active', 'disabled', 'revoked' ] as $s ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $license->status, $s ); ?>><?php echo esc_html( $s ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Update Status', 'licensekit' ); ?></button>
		</form>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Activations', 'licensekit' ); ?></h2>
		<?php
		$activations = $this->activations->find_for_license( $id );
		if ( empty( $activations ) ) {
			echo '<p>' . esc_html__( 'No activations.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr><th>' . esc_html__( 'Site', 'licensekit' ) . '</th><th>' . esc_html__( 'Environment', 'licensekit' ) . '</th><th>' . esc_html__( 'Status', 'licensekit' ) . '</th><th>' . esc_html__( 'Last Seen', 'licensekit' ) . '</th></tr></thead><tbody>';
			foreach ( $activations as $a ) {
				echo '<tr>';
				echo '<td class="lk-mono">' . esc_html( $a->site_url ) . '</td>';
				echo '<td>' . esc_html( $a->site_environment ) . '</td>';
				echo '<td><span class="lk-status lk-status-' . esc_attr( $a->status ) . '">' . esc_html( $a->status ) . '</span></td>';
				echo '<td>' . esc_html( (string) ( $a->last_seen_at ?? '—' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->close();
	}

	private function handle_issue(): void {
		$this->require_nonce( 'licensekit_issue_license' );

		$result = $this->svc->issue(
			[
				'product_id'       => (int) ( $_POST['product_id'] ?? 0 ),
				'tier'             => sanitize_key( wp_unslash( (string) ( $_POST['tier'] ?? 'single' ) ) ),
				'activation_limit' => max( 0, (int) ( $_POST['activation_limit'] ?? 1 ) ),
				'customer_email'   => sanitize_email( wp_unslash( (string) ( $_POST['customer_email'] ?? '' ) ) ),
				'expires_at'       => $this->compute_expiry( sanitize_key( wp_unslash( (string) ( $_POST['expiry_period'] ?? '1y' ) ) ) ),
				'renewal_period'   => 'lifetime' === ( $_POST['expiry_period'] ?? '' ) ? null : (string) $_POST['expiry_period'],
			]
		);

		if ( ! empty( $result['success'] ) ) {
			set_transient( 'lk_raw_key_' . get_current_user_id(), (string) $result['raw_key'], 60 );
			$this->set_flash( 'success', __( 'License issued. Copy the key from the box above.', 'licensekit' ) );
			wp_safe_redirect( $this->admin_url( 'licensekit-licenses', [ 'action' => 'new' ] ) );
		} else {
			$this->set_flash( 'error', $result['message'] ?? __( 'Could not issue license.', 'licensekit' ) );
			wp_safe_redirect( $this->admin_url( 'licensekit-licenses', [ 'action' => 'new' ] ) );
		}
		exit;
	}

	private function handle_rotate(): void {
		$this->require_nonce( 'licensekit_rotate_license' );
		$id     = (int) ( $_POST['id'] ?? 0 );
		$result = $this->svc->rotate_key( $id );
		if ( ! empty( $result['success'] ) ) {
			set_transient( 'lk_raw_key_' . get_current_user_id(), (string) $result['raw_key'], 60 );
			$this->set_flash( 'success', __( 'Key rotated.', 'licensekit' ) );
		} else {
			$this->set_flash( 'error', $result['message'] ?? __( 'Could not rotate.', 'licensekit' ) );
		}
		wp_safe_redirect( $this->admin_url( 'licensekit-licenses', [ 'action' => 'edit', 'id' => $id ] ) );
		exit;
	}

	private function handle_extend(): void {
		$this->require_nonce( 'licensekit_extend_license' );
		$id     = (int) ( $_POST['id'] ?? 0 );
		$period = sanitize_key( wp_unslash( (string) ( $_POST['period'] ?? '1y' ) ) );
		$this->svc->extend( $id, $period );
		$this->set_flash( 'success', __( 'Expiry extended.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-licenses', [ 'action' => 'edit', 'id' => $id ] ) );
		exit;
	}

	private function handle_set_status(): void {
		$this->require_nonce( 'licensekit_set_status' );
		$id     = (int) ( $_POST['id'] ?? 0 );
		$status = sanitize_key( wp_unslash( (string) ( $_POST['status'] ?? '' ) ) );
		$this->svc->set_status( $id, $status );
		$this->set_flash( 'success', __( 'Status updated.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-licenses', [ 'action' => 'edit', 'id' => $id ] ) );
		exit;
	}

	private function compute_expiry( string $period ): ?string {
		if ( 'lifetime' === $period || '' === $period ) {
			return null;
		}
		return \LicenseKit\Support\Helpers::add_period_to_datetime( \LicenseKit\Support\Helpers::now_utc(), $period );
	}
}
