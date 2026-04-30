<?php
/**
 * Products admin page — list, create, edit, delete.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\Product;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Support\Capabilities;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Products extends AbstractPage {

	private ProductRepository $repo;

	public function __construct( ProductRepository $repo ) {
		$this->repo = $repo;
	}

	public static function make(): self {
		return new self( new ProductRepository() );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE_PRODUCTS );

		// Routing inside the page.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
				$this->handle_save();
			}
			$this->render_form();
			return;
		}

		if ( 'delete' === $action ) {
			$this->handle_delete();
		}

		$this->render_list();
	}

	private function render_list(): void {
		$this->open( __( 'Products', 'licensekit' ) );
		echo '<a href="' . esc_url( $this->admin_url( 'licensekit-products', [ 'action' => 'new' ] ) ) . '" class="page-title-action">'
			. esc_html__( 'Add New', 'licensekit' ) . '</a>';

		$products = $this->repo->find_all( 100, 0 );
		if ( empty( $products ) ) {
			echo '<p>' . esc_html__( 'No products yet. Add one or buy a licensed EDD download to auto-create.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Slug', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Current Version', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'EDD Download', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'licensekit' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $products as $p ) {
				$edit_url = $this->admin_url( 'licensekit-products', [ 'action' => 'edit', 'id' => $p->id ] );
				$rel_url  = $this->admin_url( 'licensekit-releases', [ 'product_id' => $p->id ] );
				$del_url  = wp_nonce_url(
					$this->admin_url( 'licensekit-products', [ 'action' => 'delete', 'id' => $p->id ] ),
					'licensekit_delete_product_' . $p->id
				);
				echo '<tr>';
				echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $p->name ) . '</a></strong></td>';
				echo '<td class="lk-mono">' . esc_html( $p->slug ) . '</td>';
				echo '<td>' . esc_html( $p->type ) . '</td>';
				echo '<td>' . esc_html( $p->current_version ?? '—' ) . '</td>';
				echo '<td>' . ( $p->edd_download_id ? '<a href="' . esc_url( get_edit_post_link( $p->edd_download_id ) ) . '">#' . esc_html( (string) $p->edd_download_id ) . '</a>' : '—' ) . '</td>';
				echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'licensekit' ) . '</a> | '
					. '<a href="' . esc_url( $rel_url ) . '">' . esc_html__( 'Releases', 'licensekit' ) . '</a> | '
					. '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this product?', 'licensekit' ) ) . '\')">'
					. esc_html__( 'Delete', 'licensekit' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		$this->close();
	}

	private function render_form(): void {
		$id      = (int) ( $_REQUEST['id'] ?? 0 );
		$product = $id > 0 ? $this->repo->find( $id ) : null;
		$is_edit = $product instanceof Product;

		$this->open( $is_edit ? __( 'Edit Product', 'licensekit' ) : __( 'Add Product', 'licensekit' ) );

		?>
		<form method="post">
			<?php wp_nonce_field( 'licensekit_save_product' ); ?>
			<input type="hidden" name="action" value="<?php echo $is_edit ? 'edit' : 'new'; ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $id ?: 0 ) ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="name"><?php esc_html_e( 'Name', 'licensekit' ); ?></label></th>
					<td><input name="name" id="name" type="text" class="regular-text" required value="<?php echo esc_attr( $product->name ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="slug"><?php esc_html_e( 'Slug', 'licensekit' ); ?></label></th>
					<td>
						<input name="slug" id="slug" type="text" class="regular-text lk-mono" required
							value="<?php echo esc_attr( $product->slug ?? '' ); ?>"
							<?php echo $is_edit ? 'readonly' : ''; ?>>
						<p class="description"><?php esc_html_e( 'Used in URLs and as the product identifier in the SDK config.', 'licensekit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="type"><?php esc_html_e( 'Type', 'licensekit' ); ?></label></th>
					<td>
						<select name="type" id="type">
							<option value="plugin" <?php selected( ( $product->type ?? 'plugin' ), 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'licensekit' ); ?></option>
							<option value="theme" <?php selected( ( $product->type ?? 'plugin' ), 'theme' ); ?>><?php esc_html_e( 'Theme', 'licensekit' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="edd_download_id"><?php esc_html_e( 'EDD Download ID', 'licensekit' ); ?></label></th>
					<td>
						<input name="edd_download_id" id="edd_download_id" type="number" min="0"
							value="<?php echo esc_attr( (string) ( $product->edd_download_id ?? '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Optional — link to an EDD download to issue licenses on purchase.', 'licensekit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="author"><?php esc_html_e( 'Author', 'licensekit' ); ?></label></th>
					<td><input name="author" id="author" type="text" class="regular-text" value="<?php echo esc_attr( $product->author ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="homepage_url"><?php esc_html_e( 'Homepage URL', 'licensekit' ); ?></label></th>
					<td><input name="homepage_url" id="homepage_url" type="url" class="regular-text" value="<?php echo esc_attr( $product->homepage_url ?? '' ); ?>"></td>
				</tr>
			</table>
			<p>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Save Product', 'licensekit' ); ?></button>
				<a href="<?php echo esc_url( $this->admin_url( 'licensekit-products' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'licensekit' ); ?></a>
			</p>
		</form>
		<?php
		$this->close();
	}

	private function handle_save(): void {
		$this->require_nonce( 'licensekit_save_product' );

		$id   = (int) ( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
		$slug = sanitize_title( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) );
		$type = in_array( ( $_POST['type'] ?? '' ), [ 'plugin', 'theme' ], true ) ? (string) $_POST['type'] : 'plugin';

		if ( '' === $name || '' === $slug ) {
			$this->set_flash( 'error', __( 'Name and slug are required.', 'licensekit' ) );
			wp_safe_redirect( $this->admin_url( 'licensekit-products', [ 'action' => $id > 0 ? 'edit' : 'new', 'id' => $id ] ) );
			exit;
		}

		$existing_by_slug = $this->repo->find_by_slug( $slug );
		if ( 0 === $id && $existing_by_slug instanceof Product ) {
			$this->set_flash( 'error', __( 'A product with that slug already exists.', 'licensekit' ) );
			wp_safe_redirect( $this->admin_url( 'licensekit-products', [ 'action' => 'new' ] ) );
			exit;
		}

		$now = Helpers::now_utc();
		if ( $id > 0 ) {
			$this->repo->update(
				$id,
				[
					'name'            => $name,
					'type'            => $type,
					'edd_download_id' => isset( $_POST['edd_download_id'] ) && '' !== $_POST['edd_download_id'] ? (int) $_POST['edd_download_id'] : null,
					'author'          => sanitize_text_field( wp_unslash( (string) ( $_POST['author'] ?? '' ) ) ),
					'homepage_url'    => esc_url_raw( wp_unslash( (string) ( $_POST['homepage_url'] ?? '' ) ) ),
					'updated_at'      => $now,
				]
			);
		} else {
			$p                  = new Product();
			$p->slug            = $slug;
			$p->name            = $name;
			$p->type            = $type;
			$p->edd_download_id = isset( $_POST['edd_download_id'] ) && '' !== $_POST['edd_download_id'] ? (int) $_POST['edd_download_id'] : null;
			$p->author          = sanitize_text_field( wp_unslash( (string) ( $_POST['author'] ?? '' ) ) );
			$p->homepage_url    = esc_url_raw( wp_unslash( (string) ( $_POST['homepage_url'] ?? '' ) ) );
			$p->meta            = [];
			$p->created_at      = $now;
			$p->updated_at      = $now;
			$id                 = $this->repo->insert( $p );
		}

		$this->set_flash( 'success', __( 'Product saved.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-products' ) );
		exit;
	}

	private function handle_delete(): void {
		$id = (int) ( $_REQUEST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_delete_product_' . $id );

		if ( $id > 0 ) {
			$this->repo->delete( $id );
			$this->set_flash( 'success', __( 'Product deleted.', 'licensekit' ) );
		}
		wp_safe_redirect( $this->admin_url( 'licensekit-products' ) );
		exit;
	}
}
