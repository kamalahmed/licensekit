<?php
/**
 * Base class for LicenseKit admin pages — shared layout, nonces, and flash messaging.
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Admin\Pages;

defined( 'ABSPATH' ) || exit;

abstract class AbstractPage {

	abstract public function render(): void;

	/**
	 * Static factory used as the menu callback. Each page builds itself with
	 * fresh repository instances; this keeps menu wiring boilerplate-free.
	 */
	abstract public static function make(): self;

	public static function render_static(): void {
		static::make()->render();
	}

	/**
	 * Open the page wrapper with a heading. Returns nothing — the heading is echoed.
	 */
	protected function open( string $heading, string $subheading = '' ): void {
		echo '<div class="wrap lk-page">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $heading ) . '</h1>';
		if ( '' !== $subheading ) {
			echo '<p class="description">' . esc_html( $subheading ) . '</p>';
		}
		$this->render_flash();
	}

	protected function close(): void {
		echo '</div>';
	}

	protected function render_flash(): void {
		$flash = get_transient( $this->flash_key() );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $this->flash_key() );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( (string) ( $flash['type'] ?? 'info' ) ),
			wp_kses_post( (string) ( $flash['message'] ?? '' ) )
		);
	}

	protected function set_flash( string $type, string $message ): void {
		set_transient( $this->flash_key(), [ 'type' => $type, 'message' => $message ], 60 );
	}

	private function flash_key(): string {
		$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		return 'lk_flash_' . static::class . '_' . $user;
	}

	protected function require_nonce( string $action ): void {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Invalid request.', 'licensekit' ), 403 );
		}
	}

	protected function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'Insufficient permission.', 'licensekit' ), 403 );
		}
	}

	protected function admin_url( string $page, array $args = [] ): string {
		$args['page'] = $page;
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
