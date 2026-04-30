<?php
/**
 * Logs admin page — read-only audit trail.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

use LicenseKit\Models\Log;
use LicenseKit\Repositories\LogRepository;
use LicenseKit\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce + sanitization happen inside dispatched handler methods (handle_*) which call require_nonce + sanitize_*; PCP cannot trace cross-method flow.

final class Logs extends AbstractPage {

	private LogRepository $repo;

	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	public static function make(): self {
		return new self( new LogRepository() );
	}

	public function render(): void {
		$this->require_capability( Capabilities::VIEW_LOGS );
		$this->open( __( 'Logs', 'licensekit' ) );

		$rows = $this->repo->find_recent( 200 );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No log entries yet.', 'licensekit' ) . '</p>';
			$this->close();
			return;
		}

		echo '<table class="wp-list-table widefat striped lk-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'When', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Actor', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'licensekit' ) . '</th>';
		echo '<th>' . esc_html__( 'Context', 'licensekit' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $entry ) {
			/** @var Log $entry */
			echo '<tr>';
			echo '<td class="lk-mono" style="white-space:nowrap;">' . esc_html( (string) $entry->created_at ) . '</td>';
			echo '<td>' . esc_html( $entry->actor_type . ( $entry->actor_id ? ' #' . $entry->actor_id : '' ) ) . '</td>';
			echo '<td><code>' . esc_html( $entry->action ) . '</code></td>';
			echo '<td>' . esc_html( ( $entry->subject_type ?? '' ) . ( $entry->subject_id ? ' #' . $entry->subject_id : '' ) ) . '</td>';
			echo '<td><code style="font-size:11px;">' . esc_html( wp_json_encode( $entry->context ) ?: '' ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->close();
	}
}
