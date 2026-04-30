<?php
/**
 * Releases admin page — per-product release list + zip upload.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\Product;
use LicenseKit\Models\Release;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Repositories\ReleaseRepository;
use LicenseKit\Services\AuditLogger;
use LicenseKit\Services\ReleaseService;
use LicenseKit\Storage\ReleaseFileStore;
use LicenseKit\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Releases extends AbstractPage {

	private ReleaseService $svc;
	private ReleaseRepository $repo;
	private ProductRepository $products;

	public function __construct( ReleaseService $svc, ReleaseRepository $repo, ProductRepository $products ) {
		$this->svc      = $svc;
		$this->repo     = $repo;
		$this->products = $products;
	}

	public static function make(): self {
		$audit = new AuditLogger( new LogRepository() );
		$repo  = new ReleaseRepository();
		$prods = new ProductRepository();
		return new self( new ReleaseService( $repo, $prods, new ReleaseFileStore(), $audit ), $repo, $prods );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE_RELEASES );

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'create' === $action ) {
			$this->handle_create();
		}
		if ( 'delete' === $action ) {
			$this->handle_delete();
		}
		if ( 'set_channel' === $action ) {
			$this->handle_set_channel();
		}

		$product_id = (int) ( $_REQUEST['product_id'] ?? 0 );
		$product    = $product_id > 0 ? $this->products->find( $product_id ) : null;
		if ( ! $product instanceof Product ) {
			$this->render_product_picker();
			return;
		}

		$this->render_for_product( $product );
	}

	private function render_product_picker(): void {
		$this->open( __( 'Releases', 'licensekit' ) );
		echo '<p>' . esc_html__( 'Select a product to manage its releases:', 'licensekit' ) . '</p>';
		$products = $this->products->find_all( 100 );
		if ( empty( $products ) ) {
			echo '<p>' . esc_html__( 'No products yet — create one first.', 'licensekit' ) . '</p>';
			$this->close();
			return;
		}
		echo '<ul class="ul-disc">';
		foreach ( $products as $p ) {
			$url = $this->admin_url( 'licensekit-releases', [ 'product_id' => $p->id ] );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $p->name ) . '</a></li>';
		}
		echo '</ul>';
		$this->close();
	}

	private function render_for_product( Product $product ): void {
		$this->open(
			sprintf(
				/* translators: %s: product name */
				__( 'Releases — %s', 'licensekit' ),
				$product->name
			)
		);

		$releases = $this->repo->find_for_product( (int) $product->id );

		?>
		<h2><?php esc_html_e( 'Add a release', 'licensekit' ); ?></h2>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'licensekit_create_release' ); ?>
			<input type="hidden" name="action" value="create">
			<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product->id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="version"><?php esc_html_e( 'Version', 'licensekit' ); ?></label></th>
					<td><input type="text" name="version" id="version" class="regular-text lk-mono" placeholder="1.2.3" required></td>
				</tr>
				<tr>
					<th><label for="channel"><?php esc_html_e( 'Channel', 'licensekit' ); ?></label></th>
					<td>
						<select name="channel" id="channel">
							<option value="stable">stable</option>
							<option value="beta">beta</option>
							<option value="rc">rc</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="file"><?php esc_html_e( 'Zip file', 'licensekit' ); ?></label></th>
					<td><input type="file" name="file" id="file" accept=".zip" required></td>
				</tr>
				<tr>
					<th><label for="changelog_md"><?php esc_html_e( 'Changelog (Markdown)', 'licensekit' ); ?></label></th>
					<td><textarea name="changelog_md" id="changelog_md" rows="6" class="large-text lk-mono"></textarea></td>
				</tr>
				<tr>
					<th><label for="requires_wp"><?php esc_html_e( 'Requires WP', 'licensekit' ); ?></label></th>
					<td><input type="text" name="requires_wp" id="requires_wp" placeholder="6.0"></td>
				</tr>
				<tr>
					<th><label for="requires_php"><?php esc_html_e( 'Requires PHP', 'licensekit' ); ?></label></th>
					<td><input type="text" name="requires_php" id="requires_php" placeholder="7.4"></td>
				</tr>
				<tr>
					<th><label for="tested_up_to"><?php esc_html_e( 'Tested up to', 'licensekit' ); ?></label></th>
					<td><input type="text" name="tested_up_to" id="tested_up_to" placeholder="6.4"></td>
				</tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Upload Release', 'licensekit' ); ?></button></p>
		</form>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Existing releases', 'licensekit' ); ?></h2>
		<?php
		if ( empty( $releases ) ) {
			echo '<p>' . esc_html__( 'No releases yet.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Version', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Channel', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Size', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Released', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'SHA-256', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'licensekit' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $releases as $r ) {
				$del_url = wp_nonce_url(
					$this->admin_url( 'licensekit-releases', [ 'action' => 'delete', 'id' => $r->id, 'product_id' => $product->id ] ),
					'licensekit_delete_release_' . $r->id
				);
				echo '<tr>';
				echo '<td class="lk-mono"><strong>' . esc_html( $r->version ) . '</strong></td>';
				echo '<td>' . esc_html( $r->channel ) . '</td>';
				echo '<td>' . esc_html( size_format( (int) ( $r->file_size ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) $r->released_at ) . '</td>';
				echo '<td class="lk-mono" style="font-size:11px;">' . esc_html( substr( (string) ( $r->file_hash ?? '' ), 0, 16 ) ) . '…</td>';
				echo '<td><a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this release?', 'licensekit' ) ) . '\')">'
					. esc_html__( 'Delete', 'licensekit' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->close();
	}

	private function handle_create(): void {
		$this->require_nonce( 'licensekit_create_release' );

		$result = $this->svc->create(
			[
				'product_id'   => (int) ( $_POST['product_id'] ?? 0 ),
				'version'      => sanitize_text_field( wp_unslash( (string) ( $_POST['version'] ?? '' ) ) ),
				'channel'      => sanitize_key( wp_unslash( (string) ( $_POST['channel'] ?? 'stable' ) ) ),
				'changelog_md' => wp_kses_post( wp_unslash( (string) ( $_POST['changelog_md'] ?? '' ) ) ),
				'requires_wp'  => sanitize_text_field( wp_unslash( (string) ( $_POST['requires_wp'] ?? '' ) ) ),
				'requires_php' => sanitize_text_field( wp_unslash( (string) ( $_POST['requires_php'] ?? '' ) ) ),
				'tested_up_to' => sanitize_text_field( wp_unslash( (string) ( $_POST['tested_up_to'] ?? '' ) ) ),
				'source_path'  => isset( $_FILES['file']['tmp_name'] ) ? (string) $_FILES['file']['tmp_name'] : '',
				'created_by'   => get_current_user_id(),
			]
		);

		if ( ! empty( $result['success'] ) ) {
			$this->set_flash( 'success', __( 'Release created.', 'licensekit' ) );
		} else {
			$this->set_flash( 'error', $result['message'] ?? __( 'Could not create release.', 'licensekit' ) );
		}

		wp_safe_redirect( $this->admin_url( 'licensekit-releases', [ 'product_id' => (int) $_POST['product_id'] ] ) );
		exit;
	}

	private function handle_delete(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_delete_release_' . $id );
		if ( $id > 0 ) {
			$this->svc->delete_release( $id );
			$this->set_flash( 'success', __( 'Release deleted.', 'licensekit' ) );
		}
		wp_safe_redirect( $this->admin_url( 'licensekit-releases', [ 'product_id' => (int) ( $_REQUEST['product_id'] ?? 0 ) ] ) );
		exit;
	}

	private function handle_set_channel(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_release_channel_' . $id );
		$channel = sanitize_key( wp_unslash( (string) ( $_REQUEST['channel'] ?? 'stable' ) ) );
		$this->svc->set_channel( $id, $channel );
		$this->set_flash( 'success', __( 'Channel updated.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-releases', [ 'product_id' => (int) ( $_REQUEST['product_id'] ?? 0 ) ] ) );
		exit;
	}
}
