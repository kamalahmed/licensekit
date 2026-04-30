<?php
/**
 * Webhooks admin page — list, create, delete.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\Webhook;
use LicenseKit\Repositories\WebhookRepository;
use LicenseKit\Support\Capabilities;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Webhooks extends AbstractPage {

	private WebhookRepository $repo;

	public function __construct( WebhookRepository $repo ) {
		$this->repo = $repo;
	}

	public static function make(): self {
		return new self( new WebhookRepository() );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE_WEBHOOKS );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$action = sanitize_key( wp_unslash( (string) ( $_POST['action'] ?? '' ) ) );
			if ( 'create' === $action ) {
				$this->handle_create();
			} elseif ( 'delete' === $action ) {
				$this->handle_delete();
			} elseif ( 'test' === $action ) {
				$this->handle_test();
			}
		}

		$this->open( __( 'Webhooks', 'licensekit' ) );

		?>
		<h2><?php esc_html_e( 'Add a webhook', 'licensekit' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'licensekit_create_webhook' ); ?>
			<input type="hidden" name="action" value="create">
			<table class="form-table">
				<tr>
					<th><label for="endpoint_url"><?php esc_html_e( 'Endpoint URL', 'licensekit' ); ?></label></th>
					<td><input type="url" name="endpoint_url" id="endpoint_url" class="regular-text" required></td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Events', 'licensekit' ); ?></label></th>
					<td>
						<?php foreach ( $this->all_events() as $event ) : ?>
							<label style="display:block;">
								<input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>">
								<code><?php echo esc_html( $event ); ?></code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<p><button class="button button-primary"><?php esc_html_e( 'Add Webhook', 'licensekit' ); ?></button></p>
		</form>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Existing webhooks', 'licensekit' ); ?></h2>
		<?php
		$rows = $this->repo->find_active();
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No webhooks yet.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'URL', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Events', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Last Code', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Failures', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'licensekit' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $w ) {
				/** @var Webhook $w */
				echo '<tr>';
				echo '<td class="lk-mono" style="font-size:12px;">' . esc_html( $w->endpoint_url ) . '</td>';
				echo '<td><code>' . esc_html( implode( ', ', $w->events ) ) . '</code></td>';
				echo '<td><span class="lk-status lk-status-' . esc_attr( $w->status ) . '">' . esc_html( $w->status ) . '</span></td>';
				echo '<td>' . esc_html( (string) ( $w->last_response_code ?? '—' ) ) . '</td>';
				echo '<td>' . esc_html( (string) $w->failure_count ) . '</td>';
				echo '<td>';
				$test_nonce = wp_create_nonce( 'licensekit_test_webhook_' . $w->id );
				$del_nonce  = wp_create_nonce( 'licensekit_delete_webhook_' . $w->id );
				echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="' . esc_attr( (string) $w->id ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $test_nonce ) . '"><button class="button-link">' . esc_html__( 'Test', 'licensekit' ) . '</button></form> | ';
				echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . esc_attr( (string) $w->id ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $del_nonce ) . '"><button class="button-link" onclick="return confirm(\'' . esc_js( __( 'Delete this webhook?', 'licensekit' ) ) . '\')">' . esc_html__( 'Delete', 'licensekit' ) . '</button></form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->close();
	}

	private function handle_create(): void {
		$this->require_nonce( 'licensekit_create_webhook' );

		$url    = esc_url_raw( wp_unslash( (string) ( $_POST['endpoint_url'] ?? '' ) ) );
		$events = isset( $_POST['events'] ) && is_array( $_POST['events'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['events'] ) ) // phpcs:ignore WordPress.Security
			: [];

		if ( '' === $url || empty( $events ) ) {
			$this->set_flash( 'error', __( 'URL and at least one event are required.', 'licensekit' ) );
			wp_safe_redirect( $this->admin_url( 'licensekit-webhooks' ) );
			exit;
		}

		$webhook               = new Webhook();
		$webhook->endpoint_url = $url;
		$webhook->secret       = bin2hex( random_bytes( 16 ) );
		$webhook->events       = $events;
		$webhook->status       = Webhook::STATUS_ACTIVE;
		$webhook->created_at   = Helpers::now_utc();
		$webhook->updated_at   = Helpers::now_utc();

		$this->repo->insert( $webhook );
		$this->set_flash( 'success', __( 'Webhook created.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-webhooks' ) );
		exit;
	}

	private function handle_delete(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_delete_webhook_' . $id );
		$this->repo->delete( $id );
		$this->set_flash( 'success', __( 'Webhook deleted.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-webhooks' ) );
		exit;
	}

	private function handle_test(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_test_webhook_' . $id );
		$webhook = $this->repo->find( $id );
		if ( $webhook instanceof Webhook ) {
			do_action( 'licensekit_webhook_test', $webhook );
			$this->set_flash( 'success', __( 'Test event queued.', 'licensekit' ) );
		}
		wp_safe_redirect( $this->admin_url( 'licensekit-webhooks' ) );
		exit;
	}

	private function all_events(): array {
		return [
			'license.issued',
			'license.activated',
			'license.deactivated',
			'license.rotated',
			'license.extended',
			'license.status_changed',
			'license.suspicious_activity',
			'release.created',
			'release.deleted',
			'release.downloaded',
		];
	}
}
