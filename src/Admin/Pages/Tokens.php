<?php
/**
 * API tokens page — list, create (returns full token once), revoke.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\ApiToken;
use LicenseKit\Repositories\ApiTokenRepository;
use LicenseKit\REST\Auth\BearerTokenAuth;
use LicenseKit\Support\Capabilities;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Tokens extends AbstractPage {

	private ApiTokenRepository $repo;

	public function __construct( ApiTokenRepository $repo ) {
		$this->repo = $repo;
	}

	public static function make(): self {
		return new self( new ApiTokenRepository() );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE_TOKENS );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$action = sanitize_key( wp_unslash( (string) ( $_POST['action'] ?? '' ) ) );
			if ( 'create' === $action ) {
				$this->handle_create();
			} elseif ( 'revoke' === $action ) {
				$this->handle_revoke();
			}
		}

		$this->open( __( 'API Tokens', 'licensekit' ) );

		$flash = get_transient( 'lk_full_token_' . get_current_user_id() );
		if ( is_string( $flash ) && '' !== $flash ) {
			delete_transient( 'lk_full_token_' . get_current_user_id() );
			?>
			<div class="lk-key-once">
				<strong><?php esc_html_e( 'New token (shown once):', 'licensekit' ); ?></strong>
				<code><?php echo esc_html( $flash ); ?></code>
				<p class="description"><?php esc_html_e( 'Store this safely now. Only the hash is kept.', 'licensekit' ); ?></p>
			</div>
			<?php
		}
		?>
		<h2><?php esc_html_e( 'Create a token', 'licensekit' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'licensekit_create_token' ); ?>
			<input type="hidden" name="action" value="create">
			<table class="form-table">
				<tr>
					<th><label for="name"><?php esc_html_e( 'Name', 'licensekit' ); ?></label></th>
					<td><input type="text" name="name" id="name" class="regular-text" placeholder="CI deploy token" required></td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Abilities', 'licensekit' ); ?></label></th>
					<td>
						<?php $abilities = $this->all_abilities(); ?>
						<label><input type="checkbox" name="abilities[]" value="*" checked> <strong><?php esc_html_e( 'All (*)', 'licensekit' ); ?></strong></label><br>
						<?php foreach ( $abilities as $a ) : ?>
							<label><input type="checkbox" name="abilities[]" value="<?php echo esc_attr( $a ); ?>"> <?php echo esc_html( $a ); ?></label><br>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Create Token', 'licensekit' ); ?></button></p>
		</form>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Existing tokens', 'licensekit' ); ?></h2>
		<?php
		$rows = $this->repo->find_for_user( get_current_user_id() );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No tokens yet.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Prefix', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Abilities', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Last Used', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'licensekit' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $t ) {
				/** @var ApiToken $t */
				$is_revoked = null !== $t->revoked_at;
				echo '<tr>';
				echo '<td>' . esc_html( $t->name ) . '</td>';
				echo '<td class="lk-mono">' . esc_html( $t->token_prefix ) . '…</td>';
				echo '<td class="lk-mono" style="font-size:11px;">' . esc_html( implode( ', ', $t->abilities ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $t->last_used_at ?? '—' ) ) . '</td>';
				echo '<td>' . ( $is_revoked ? '<span class="lk-status lk-status-revoked">' . esc_html__( 'revoked', 'licensekit' ) . '</span>' : '<span class="lk-status lk-status-active">active</span>' ) . '</td>';
				echo '<td>';
				if ( ! $is_revoked ) {
					$nonce = wp_create_nonce( 'licensekit_revoke_token_' . $t->id );
					echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="' . esc_attr( (string) $t->id ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
					echo '<button class="button-link" onclick="return confirm(\'' . esc_js( __( 'Revoke this token?', 'licensekit' ) ) . '\')">' . esc_html__( 'Revoke', 'licensekit' ) . '</button>';
					echo '</form>';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->close();
	}

	private function handle_create(): void {
		$this->require_nonce( 'licensekit_create_token' );

		$name = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
		if ( '' === $name ) {
			$this->set_flash( 'error', __( 'Token name is required.', 'licensekit' ) );
			wp_safe_redirect( $this->admin_url( 'licensekit-tokens' ) );
			exit;
		}

		$abilities = isset( $_POST['abilities'] ) && is_array( $_POST['abilities'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['abilities'] ) ) // phpcs:ignore WordPress.Security
			: [ '*' ];

		$minted = BearerTokenAuth::mint();

		$token             = new ApiToken();
		$token->user_id    = get_current_user_id();
		$token->token_hash = $minted['hash'];
		$token->token_prefix = $minted['prefix'];
		$token->name       = $name;
		$token->abilities  = $abilities;
		$token->created_at = Helpers::now_utc();

		$id = $this->repo->insert( $token );
		if ( $id <= 0 ) {
			$this->set_flash( 'error', __( 'Could not save token.', 'licensekit' ) );
		} else {
			set_transient( 'lk_full_token_' . get_current_user_id(), $minted['full'], 60 );
			$this->set_flash( 'success', __( 'Token created.', 'licensekit' ) );
		}

		wp_safe_redirect( $this->admin_url( 'licensekit-tokens' ) );
		exit;
	}

	private function handle_revoke(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_revoke_token_' . $id );
		$this->repo->update( $id, [ 'revoked_at' => Helpers::now_utc() ] );
		$this->set_flash( 'success', __( 'Token revoked.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-tokens' ) );
		exit;
	}

	private function all_abilities(): array {
		return [
			'products.read', 'products.write',
			'releases.read', 'releases.write',
			'licenses.read', 'licenses.write',
			'webhooks.read', 'webhooks.write',
			'tokens.read', 'tokens.write',
			'logs.read',
		];
	}
}
