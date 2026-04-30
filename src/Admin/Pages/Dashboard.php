<?php
/**
 * Dashboard — top-of-funnel overview: counters and recent activity.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\Log;
use LicenseKit\Repositories\ActivationRepository;
use LicenseKit\Repositories\LicenseRepository;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Repositories\ProductRepository;
use LicenseKit\Support\Helpers;

defined( 'ABSPATH' ) || exit;

final class Dashboard extends AbstractPage {

	private LicenseRepository $licenses;
	private ProductRepository $products;
	private ActivationRepository $activations;
	private LogRepository $logs;

	public function __construct(
		LicenseRepository $licenses,
		ProductRepository $products,
		ActivationRepository $activations,
		LogRepository $logs
	) {
		$this->licenses    = $licenses;
		$this->products    = $products;
		$this->activations = $activations;
		$this->logs        = $logs;
	}

	public static function make(): self {
		return new self(
			new LicenseRepository(),
			new ProductRepository(),
			new ActivationRepository(),
			new LogRepository()
		);
	}

	public function render(): void {
		$this->open( __( 'License Kit Dashboard', 'licensekit' ) );

		$products_count = $this->products->count_all();
		$licenses_count = $this->licenses->count_all();
		$activations_count = $this->activations->count_all();

		$expiring_30d = count(
			$this->licenses->find_expiring_before( gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ), 100 )
		);

		?>
		<div class="lk-cards">
			<div class="lk-card">
				<div class="num"><?php echo esc_html( (string) $products_count ); ?></div>
				<div class="label"><?php esc_html_e( 'Products', 'licensekit' ); ?></div>
			</div>
			<div class="lk-card">
				<div class="num"><?php echo esc_html( (string) $licenses_count ); ?></div>
				<div class="label"><?php esc_html_e( 'Licenses', 'licensekit' ); ?></div>
			</div>
			<div class="lk-card">
				<div class="num"><?php echo esc_html( (string) $activations_count ); ?></div>
				<div class="label"><?php esc_html_e( 'Activations', 'licensekit' ); ?></div>
			</div>
			<div class="lk-card">
				<div class="num"><?php echo esc_html( (string) $expiring_30d ); ?></div>
				<div class="label"><?php esc_html_e( 'Expiring in 30 days', 'licensekit' ); ?></div>
			</div>
		</div>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Recent activity', 'licensekit' ); ?></h2>
		<?php
		$recent = $this->logs->find_recent( 20 );
		if ( empty( $recent ) ) {
			echo '<p>' . esc_html__( 'No activity yet. Issue a license or upload a release to see entries here.', 'licensekit' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped lk-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'When', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Subject', 'licensekit' ) . '</th>';
			echo '<th>' . esc_html__( 'Context', 'licensekit' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $recent as $entry ) {
				/** @var Log $entry */
				echo '<tr>';
				echo '<td class="lk-mono">' . esc_html( (string) $entry->created_at ) . '</td>';
				echo '<td>' . esc_html( $entry->action ) . '</td>';
				echo '<td>' . esc_html( ( $entry->subject_type ?? '' ) . ' #' . ( $entry->subject_id ?? '-' ) ) . '</td>';
				echo '<td><code>' . esc_html( wp_json_encode( $entry->context ) ?: '' ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->close();
	}
}
