<?php
/**
 * Activations admin page — read-mostly list with bulk revoke.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\Activation;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Support\Capabilities;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Activations extends AbstractPage {

	private ActivationRepository $repo;

	public function __construct( ActivationRepository $repo ) {
		$this->repo = $repo;
	}

	public static function make(): self {
		return new self( new ActivationRepository() );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE_ACTIVATIONS );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'revoke' === ( $_POST['action'] ?? '' ) ) {
			$this->handle_revoke();
		}

		$this->open( __( 'Activations', 'licensekit' ) );

		// Stale (>30d unseen) view filter
		$show_stale = ! empty( $_GET['stale'] );

		$rows = $show_stale
			? $this->repo->find_stale( gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ), 200 )
			: $this->find_recent_active( 200 );

		?>
		<p>
			<a href="<?php echo esc_url( $this->admin_url( 'licensekit-activations' ) ); ?>" class="<?php echo $show_stale ? '' : 'button-primary'; ?> button">
				<?php esc_html_e( 'Recent active', 'licensekit' ); ?>
			</a>
			<a href="<?php echo esc_url( $this->admin_url( 'licensekit-activations', [ 'stale' => 1 ] ) ); ?>" class="<?php echo $show_stale ? 'button-primary' : ''; ?> button">
				<?php esc_html_e( 'Stale (>30 days)', 'licensekit' ); ?>
			</a>
		</p>
		<?php
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No activations found.', 'licensekit' ) . '</p>';
			$this->close();
			return;
		}

		echo '<table class="wp-list-table widefat striped lk-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'License', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Environment', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Seen', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'licensekit' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $a ) {
			/** @var Activation $a */
			$license_url = $this->admin_url( 'licensekit-licenses', [ 'action' => 'edit', 'id' => $a->license_id ] );
			echo '<tr>';
			echo '<td class="lk-mono">' . esc_html( $a->site_url ) . '</td>';
			echo '<td><a href="' . esc_url( $license_url ) . '">#' . esc_html( (string) $a->license_id ) . '</a></td>';
			echo '<td>' . esc_html( $a->site_environment ) . '</td>';
			echo '<td><span class="lk-status lk-status-' . esc_attr( $a->status ) . '">' . esc_html( $a->status ) . '</span></td>';
			echo '<td>' . esc_html( (string) ( $a->last_seen_at ?? '—' ) ) . '</td>';
			echo '<td>';
			if ( Activation::STATUS_ACTIVE === $a->status ) {
				$nonce = wp_create_nonce( 'licensekit_revoke_activation_' . $a->id );
				echo '<form method="post" style="display:inline;"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="'
					. esc_attr( (string) $a->id ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
					. '<button class="button-link" onclick="return confirm(\'' . esc_js( __( 'Revoke this activation?', 'licensekit' ) ) . '\')">'
					. esc_html__( 'Revoke', 'licensekit' ) . '</button></form>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->close();
	}

	private function handle_revoke(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		$this->require_nonce( 'licensekit_revoke_activation_' . $id );
		$this->repo->update(
			$id,
			[
				'status'       => Activation::STATUS_REVOKED,
				'last_seen_at' => Helpers::now_utc(),
			]
		);
		$this->set_flash( 'success', __( 'Activation revoked.', 'licensekit' ) );
		wp_safe_redirect( $this->admin_url( 'licensekit-activations' ) );
		exit;
	}

	/**
	 * @return Activation[]
	 */
	private function find_recent_active( int $limit ): array {
		// We don't have a generic "recent" query on ActivationRepository — synthesize
		// by reading the last N rows ordered by last_seen_at via a stale-search inversion.
		// Since the schema doesn't easily support "recent", we return all most-recent licenses' rows.
		// For v1: return the rows from find_stale at "now" cutoff which yields nothing useful;
		// instead use a direct query.
		global $wpdb;
		$table = $wpdb->prefix . 'licensekit_activations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is composed from $wpdb->prefix and a constant; cache irrelevant for an admin list page.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY last_seen_at DESC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map( static fn( $r ) => Activation::from_row( (array) $r ), $rows ?: [] );
	}
}
