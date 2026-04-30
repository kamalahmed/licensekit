<?php
/**
 * Tools page — vendor utilities, currently the EDD-SL migration importer.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\EDD\Migration\EDDSLImporter;
use LicenseKit\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Tools extends AbstractPage {

	private EDDSLImporter $eddsl_importer;

	public function __construct( EDDSLImporter $eddsl_importer ) {
		$this->eddsl_importer = $eddsl_importer;
	}

	public static function make(): self {
		return new self( EDDSLImporter::make() );
	}

	public function render(): void {
		$this->require_capability( Capabilities::MANAGE );

		$run_result = null;
		$mode       = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['action'] ) );
			if ( 'eddsl_dry_run' === $action ) {
				$this->require_nonce( 'licensekit_eddsl_dry_run' );
				$run_result = $this->eddsl_importer->import( true );
				$mode       = 'dry_run';
			} elseif ( 'eddsl_import' === $action ) {
				$this->require_nonce( 'licensekit_eddsl_import' );
				$run_result = $this->eddsl_importer->import( false );
				$mode       = 'import';
			}
		}

		$this->open( __( 'Tools', 'licensekit' ) );
		?>

		<h2><?php esc_html_e( 'Migrate from EDD Software Licensing', 'licensekit' ); ?></h2>

		<?php if ( ! $this->eddsl_importer->is_edd_sl_present() ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'EDD Software Licensing is not detected on this site. Nothing to migrate.', 'licensekit' ); ?>
				</p>
			</div>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'Walks every EDD-SL license post and creates a matching LicenseKit license that preserves the original raw key — your customers will not need to re-paste anything. Idempotent: re-running skips licenses already imported.', 'licensekit' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Source licenses', 'licensekit' ); ?></th>
					<td><?php echo esc_html( (string) $this->eddsl_importer->source_count() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Batch size', 'licensekit' ); ?></th>
					<td>1000 (re-run for more)</td>
				</tr>
			</table>

			<form method="post" style="display:inline-block;margin-right:8px;">
				<?php wp_nonce_field( 'licensekit_eddsl_dry_run' ); ?>
				<input type="hidden" name="action" value="eddsl_dry_run">
				<button class="button"><?php esc_html_e( 'Dry Run', 'licensekit' ); ?></button>
			</form>

			<form method="post" style="display:inline-block;"
				onsubmit="return confirm('<?php echo esc_js( __( 'Run the import? Existing LicenseKit licenses with matching keys will be skipped, not overwritten.', 'licensekit' ) ); ?>');">
				<?php wp_nonce_field( 'licensekit_eddsl_import' ); ?>
				<input type="hidden" name="action" value="eddsl_import">
				<button class="button button-primary"><?php esc_html_e( 'Run Import', 'licensekit' ); ?></button>
			</form>

			<?php if ( null !== $run_result ) : ?>
				<h3 style="margin-top:32px;">
					<?php
					echo 'dry_run' === $mode
						? esc_html__( 'Dry run results', 'licensekit' )
						: esc_html__( 'Import results', 'licensekit' );
					?>
				</h3>
				<table class="wp-list-table widefat striped" style="max-width:600px;">
					<tr>
						<th><?php esc_html_e( 'Total scanned', 'licensekit' ); ?></th>
						<td><?php echo esc_html( (string) $run_result['licenses_total'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Migrated', 'licensekit' ); ?></th>
						<td><strong><?php echo esc_html( (string) $run_result['licenses_migrated'] ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Skipped (already imported)', 'licensekit' ); ?></th>
						<td><?php echo esc_html( (string) $run_result['licenses_skipped'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Errors', 'licensekit' ); ?></th>
						<td><?php echo esc_html( (string) $run_result['licenses_errored'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Activations migrated', 'licensekit' ); ?></th>
						<td><?php echo esc_html( (string) $run_result['activations_migrated'] ); ?></td>
					</tr>
				</table>

				<?php if ( ! empty( $run_result['errors'] ) ) : ?>
					<h4><?php esc_html_e( 'Errors', 'licensekit' ); ?></h4>
					<ul class="ul-disc">
						<?php foreach ( $run_result['errors'] as $err ) : ?>
							<li class="lk-mono"><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( 'import' === $mode && $run_result['licenses_migrated'] > 0 ) : ?>
					<div class="notice notice-success inline" style="margin-top:16px;">
						<p>
							<?php esc_html_e( 'Migration complete. Customers can keep using their existing license keys — the SDK on their site does not need any change.', 'licensekit' ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		$this->close();
	}
}
