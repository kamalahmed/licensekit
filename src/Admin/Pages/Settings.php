<?php
/**
 * Settings page — operator-facing toggles + read-only secret display.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Support\Capabilities;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Settings extends AbstractPage {

	public static function make(): self {
		return new self();
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$action = sanitize_key( wp_unslash( (string) ( $_POST['action'] ?? '' ) ) );
			if ( 'save_general' === $action ) {
				$this->handle_save_general();
			} elseif ( 'rotate_secrets' === $action ) {
				$this->handle_rotate_secrets();
			}
		}

		$this->open( __( 'Settings', 'licensekit' ) );

		$settings = (array) get_option( 'licensekit_settings', [] );
		$delete   = ! empty( $settings['delete_data_on_uninstall'] );
		$grace    = (int) ( $settings['grace_days'] ?? 14 );

		?>
		<h2><?php esc_html_e( 'General', 'licensekit' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'licensekit_save_general' ); ?>
			<input type="hidden" name="action" value="save_general">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Grace period (days)', 'licensekit' ); ?></th>
					<td>
						<input type="number" name="grace_days" min="0" max="365" value="<?php echo esc_attr( (string) $grace ); ?>">
						<p class="description"><?php esc_html_e( 'Days after expiry that licenses still validate. Downloads are denied during grace.', 'licensekit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Delete data on uninstall', 'licensekit' ); ?></th>
					<td>
						<label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete ); ?>>
							<?php esc_html_e( 'Drop all LicenseKit tables and options when the plugin is uninstalled.', 'licensekit' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'OFF by default — data is preserved through deactivation/reactivation.', 'licensekit' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Save', 'licensekit' ); ?></button></p>
		</form>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Security & SDK signing', 'licensekit' ); ?></h2>
		<p class="description">
			<strong><?php esc_html_e( 'Copy the public key below into the `public_key` config of every Client SDK you ship.', 'licensekit' ); ?></strong>
			<?php esc_html_e( 'It is safe to publish — possessing it lets the SDK verify signatures but not forge them.', 'licensekit' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Ed25519 public key', 'licensekit' ); ?></th>
				<td>
					<?php $public = $this->display_secret( 'sign_public' ); ?>
					<?php if ( '' === $public || str_starts_with( $public, '(' ) ) : ?>
						<code class="lk-mono"><?php echo esc_html( $public ); ?></code>
					<?php else : ?>
						<input type="text" readonly value="<?php echo esc_attr( $public ); ?>"
							onclick="this.select();document.execCommand && document.execCommand('copy');"
							class="regular-text lk-mono" style="width:100%;max-width:520px;background:#fff;">
					<?php endif; ?>
					<?php $this->render_constant_status( 'LICENSEKIT_SIGN_PUBLIC' ); ?>
					<p class="description">
						<?php esc_html_e( 'Click to copy. Paste into your plugin/theme SDK boot code as `public_key`.', 'licensekit' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<h3 style="margin-top:32px;"><?php esc_html_e( 'Server-only secrets', 'licensekit' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'These never leave the server. Move them to wp-config.php for production deployments.', 'licensekit' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'HMAC secret (sodium-less fallback)', 'licensekit' ); ?></th>
				<td>
					<code class="lk-mono" style="user-select:all;"><?php echo esc_html( $this->display_secret( 'hmac_secret' ) ); ?></code>
					<?php $this->render_constant_status( 'LICENSEKIT_HMAC_SECRET' ); ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Hash pepper', 'licensekit' ); ?></th>
				<td>
					<code class="lk-mono" style="user-select:all;"><?php echo esc_html( $this->display_secret( 'hash_pepper' ) ); ?></code>
					<?php $this->render_constant_status( 'LICENSEKIT_HASH_PEPPER' ); ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Download secret', 'licensekit' ); ?></th>
				<td>
					<code class="lk-mono" style="user-select:all;"><?php echo esc_html( $this->display_secret( 'download_secret' ) ); ?></code>
					<?php $this->render_constant_status( 'LICENSEKIT_DOWNLOAD_SECRET' ); ?>
				</td>
			</tr>
		</table>
		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Rotate all secrets? Existing SDK signing_keys will need updating, and in-flight download tokens will be invalidated.', 'licensekit' ) ); ?>')">
			<?php wp_nonce_field( 'licensekit_rotate_secrets' ); ?>
			<input type="hidden" name="action" value="rotate_secrets">
			<p><button class="button"><?php esc_html_e( 'Rotate Secrets', 'licensekit' ); ?></button></p>
		</form>
		<?php

		$this->close();
	}

	private function display_secret( string $key ): string {
		$constant_map = [
			'hash_pepper'     => 'LICENSEKIT_HASH_PEPPER',
			'hmac_secret'     => 'LICENSEKIT_HMAC_SECRET',
			'download_secret' => 'LICENSEKIT_DOWNLOAD_SECRET',
			'sign_secret'     => 'LICENSEKIT_SIGN_SECRET',
			'sign_public'     => 'LICENSEKIT_SIGN_PUBLIC',
		];
		if ( isset( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) ) {
			return __( '(set via wp-config.php)', 'licensekit' );
		}
		return Helpers::secret( $key );
	}

	private function render_constant_status( string $constant ): void {
		if ( defined( $constant ) ) {
			echo ' <span class="lk-status lk-status-active">' . esc_html__( 'wp-config.php', 'licensekit' ) . '</span>';
		} else {
			echo ' <span class="lk-status lk-status-paused">' . esc_html__( 'option fallback', 'licensekit' ) . '</span>';
		}
	}

	private function handle_save_general(): void {
		$this->require_nonce( 'licensekit_save_general' );

		$settings = (array) get_option( 'licensekit_settings', [] );
		$settings['grace_days']               = max( 0, min( 365, (int) ( $_POST['grace_days'] ?? 14 ) ) );
		$settings['delete_data_on_uninstall'] = ! empty( $_POST['delete_data_on_uninstall'] );

		update_option( 'licensekit_settings', $settings, true );
		update_option( 'licensekit_delete_data_on_uninstall', $settings['delete_data_on_uninstall'], true );

		$this->set_flash( 'success', __( 'Settings saved.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-settings' ) );
		exit;
	}

	private function handle_rotate_secrets(): void {
		$this->require_nonce( 'licensekit_rotate_secrets' );

		$secrets = (array) get_option( 'licensekit_secrets', [] );
		foreach ( [ 'hash_pepper', 'hmac_secret', 'download_secret' ] as $key ) {
			$secrets[ $key ] = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		if ( function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$keypair = sodium_crypto_sign_keypair();
			$secrets['sign_secret'] = rtrim( strtr( base64_encode( sodium_crypto_sign_secretkey( $keypair ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$secrets['sign_public'] = rtrim( strtr( base64_encode( sodium_crypto_sign_publickey( $keypair ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		update_option( 'licensekit_secrets', $secrets, true );

		$this->set_flash( 'success', __( 'Secrets rotated. Update SDK public_key in shipped plugins to match.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-settings' ) );
		exit;
	}
}
