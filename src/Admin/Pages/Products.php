<?php
/**
 * Products admin page — list, create, edit, delete.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\EDD\DownloadMetaBox;
use LicenseKit\Models\Product;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Support\Capabilities;
use LicenseKit\Support\Helpers;
use LicenseKit\WooCommerce\ProductSettings as WcProductSettings;

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
			echo '<p>' . esc_html__( 'No products yet. Add one, or sell a licensed EDD download / WooCommerce product to auto-create.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Slug', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Current Version', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'EDD Download', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'WooCommerce Product', 'licensekit' ) . '</th>';
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
				echo '<td>' . ( $p->wc_product_id ? '<a href="' . esc_url( get_edit_post_link( $p->wc_product_id ) ) . '">#' . esc_html( (string) $p->wc_product_id ) . '</a>' : '—' ) . '</td>';
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

		$has_edd = class_exists( 'Easy_Digital_Downloads' );
		$has_wc  = class_exists( 'WooCommerce' );
		?>
		<?php if ( $has_edd || $has_wc ) : ?>
		<div class="notice notice-info inline" style="margin:12px 0 16px 0;">
			<p style="margin:0.5em 0;">
				<strong><?php esc_html_e( 'How LicenseKit licensing works', 'licensekit' ); ?></strong>
			</p>
			<p style="margin:0.5em 0;">
				<?php
				echo wp_kses_post(
					__(
						'A <em>LicenseKit Product</em> (this page) is the internal record that holds release files, the slug your client SDK uses, and is what every issued license is tied to.',
						'licensekit'
					)
				);
				?>
			</p>
			<p style="margin:0.5em 0;">
				<?php
				$names = [];
				if ( $has_edd ) {
					$names[] = __( 'EDD download editor', 'licensekit' );
				}
				if ( $has_wc ) {
					$names[] = __( 'WooCommerce product editor', 'licensekit' );
				}
				echo wp_kses_post(
					sprintf(
						/* translators: %s: list of host product editor names, e.g. "EDD download editor and WooCommerce product editor" */
						__( 'Whether a license is actually issued at purchase time is decided by the <strong>LicenseKit panel on the %s</strong>. That is also where tier, activation limit, and expiry are configured. Linking the IDs below is optional — the bridge auto-creates the LicenseKit Product on the first sale. Use the link fields when you want to pre-create the record (e.g. to upload releases before launch) or pin a specific slug.', 'licensekit' ),
						implode( ' ' . __( 'and', 'licensekit' ) . ' ', $names )
					)
				);
				?>
			</p>
		</div>
		<?php endif; ?>
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
				<?php if ( $has_edd ) : ?>
				<tr>
					<th><label for="edd_download_id"><?php esc_html_e( 'Linked EDD download', 'licensekit' ); ?></label></th>
					<td>
						<input name="edd_download_id" id="edd_download_id" type="number" min="0"
							placeholder="<?php esc_attr_e( 'Download ID', 'licensekit' ); ?>"
							value="<?php echo esc_attr( (string) ( $product->edd_download_id ?? '' ) ); ?>">
						<?php $this->render_host_link_actions( (int) ( $product->edd_download_id ?? 0 ), 'edd' ); ?>
						<p class="description">
							<?php esc_html_e( 'Optional — point this LicenseKit Product at an EDD download. Leave empty to let the bridge auto-create the link on the first sale.', 'licensekit' ); ?>
						</p>
						<?php $this->render_host_toggle_status( (int) ( $product->edd_download_id ?? 0 ), 'edd' ); ?>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( $has_wc ) : ?>
				<tr>
					<th><label for="wc_product_id"><?php esc_html_e( 'Linked WooCommerce product', 'licensekit' ); ?></label></th>
					<td>
						<input name="wc_product_id" id="wc_product_id" type="number" min="0"
							placeholder="<?php esc_attr_e( 'Product ID', 'licensekit' ); ?>"
							value="<?php echo esc_attr( (string) ( $product->wc_product_id ?? '' ) ); ?>">
						<?php $this->render_host_link_actions( (int) ( $product->wc_product_id ?? 0 ), 'wc' ); ?>
						<p class="description">
							<?php esc_html_e( 'Optional — point this LicenseKit Product at a WooCommerce product. Leave empty to let the bridge auto-create the link on the first sale.', 'licensekit' ); ?>
						</p>
						<?php $this->render_host_toggle_status( (int) ( $product->wc_product_id ?? 0 ), 'wc' ); ?>
					</td>
				</tr>
				<?php endif; ?>
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

	/**
	 * Inline "Open download/product" link rendered next to the linked-id input.
	 * No-op when the field is empty.
	 *
	 * @param int    $host_post_id  EDD download id or WC product id (0 = unlinked)
	 * @param string $host          'edd' | 'wc'
	 */
	private function render_host_link_actions( int $host_post_id, string $host ): void {
		if ( $host_post_id <= 0 ) {
			return;
		}
		$edit_link = get_edit_post_link( $host_post_id );
		if ( ! $edit_link ) {
			return;
		}
		$label = 'edd' === $host
			? __( 'Open EDD download', 'licensekit' )
			: __( 'Open WooCommerce product', 'licensekit' );
		echo ' <a href="' . esc_url( $edit_link ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . ' &rarr;</a>';
	}

	/**
	 * Live status indicator showing whether the LicenseKit toggle is currently
	 * ON for the linked host product. This is what actually decides if a
	 * license is issued at purchase time, so surfacing it here removes the
	 * "did I remember to flip the switch?" guessing.
	 *
	 * @param int    $host_post_id  0 = unlinked
	 * @param string $host          'edd' | 'wc'
	 */
	private function render_host_toggle_status( int $host_post_id, string $host ): void {
		if ( $host_post_id <= 0 ) {
			return;
		}

		$enabled = 'edd' === $host
			? DownloadMetaBox::is_licensing_enabled( $host_post_id )
			: WcProductSettings::is_licensing_enabled( $host_post_id );

		$edit_link = get_edit_post_link( $host_post_id );

		if ( $enabled ) {
			$icon  = '<span class="dashicons dashicons-yes" style="color:#46b450;vertical-align:middle;"></span>';
			$text  = 'edd' === $host
				? __( 'Licensing is enabled on the linked EDD download.', 'licensekit' )
				: __( 'Licensing is enabled on the linked WooCommerce product.', 'licensekit' );
			$style = 'color:#1d7e3a;';
			echo '<p class="description" style="' . esc_attr( $style ) . '">' . $icon . ' ' . esc_html( $text ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		$icon = '<span class="dashicons dashicons-warning" style="color:#dba617;vertical-align:middle;"></span>';
		$text = 'edd' === $host
			? __( 'Licensing is NOT enabled on the linked EDD download — purchases will not issue a license until you check "Issue a license key on purchase" in the LicenseKit Settings box on the download editor.', 'licensekit' )
			: __( 'Licensing is NOT enabled on the linked WooCommerce product — purchases will not issue a license until you check "Enable licensing" in the LicenseKit panel on the product editor.', 'licensekit' );

		$action = $edit_link
			? ' <a href="' . esc_url( $edit_link ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open editor', 'licensekit' ) . ' &rarr;</a>'
			: '';

		// phpcs:ignore WordPress.Security.EscapeOutput -- icon, link and text are all built from translated strings + esc_url above
		echo '<p class="description" style="color:#a26b00;">' . $icon . ' ' . esc_html( $text ) . $action . '</p>';
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

		$edd_download_id = isset( $_POST['edd_download_id'] ) && '' !== $_POST['edd_download_id'] ? (int) $_POST['edd_download_id'] : null;
		$wc_product_id   = isset( $_POST['wc_product_id'] ) && '' !== $_POST['wc_product_id'] ? (int) $_POST['wc_product_id'] : null;

		// The schema enforces UNIQUE on edd_download_id and wc_product_id; catch
		// the conflict before the DB call so we can show a friendly message.
		if ( null !== $edd_download_id ) {
			$conflict = $this->repo->find_by_edd_download_id( $edd_download_id );
			if ( $conflict instanceof Product && (int) $conflict->id !== $id ) {
				$this->set_flash(
					'error',
					sprintf(
						/* translators: 1: existing product name, 2: existing product slug */
						__( 'EDD download #%1$d is already linked to "%2$s". Pick a different download or unlink it first.', 'licensekit' ),
						$edd_download_id,
						(string) $conflict->name
					)
				);
				wp_safe_redirect( $this->admin_url( 'licensekit-products', [ 'action' => $id > 0 ? 'edit' : 'new', 'id' => $id ] ) );
				exit;
			}
		}
		if ( null !== $wc_product_id ) {
			$conflict = $this->repo->find_by_wc_product_id( $wc_product_id );
			if ( $conflict instanceof Product && (int) $conflict->id !== $id ) {
				$this->set_flash(
					'error',
					sprintf(
						/* translators: 1: WC product id, 2: existing product name */
						__( 'WooCommerce product #%1$d is already linked to "%2$s". Pick a different product or unlink it first.', 'licensekit' ),
						$wc_product_id,
						(string) $conflict->name
					)
				);
				wp_safe_redirect( $this->admin_url( 'licensekit-products', [ 'action' => $id > 0 ? 'edit' : 'new', 'id' => $id ] ) );
				exit;
			}
		}

		$now = Helpers::now_utc();
		if ( $id > 0 ) {
			$this->repo->update(
				$id,
				[
					'name'            => $name,
					'type'            => $type,
					'edd_download_id' => $edd_download_id,
					'wc_product_id'   => $wc_product_id,
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
			$p->edd_download_id = $edd_download_id;
			$p->wc_product_id   = $wc_product_id;
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
