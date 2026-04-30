<?php
/**
 * Release zip storage. Files live under `wp-content/uploads/licensekit/releases/{slug}/`
 * with deny-all `.htaccess` and `web.config` written on first use. Downloads are
 * served only via PHP through a signed-token endpoint — never directly by the
 * web server (where possible — operators on Nginx must add the appropriate
 * `internal;` rule themselves; we document this in the Settings page).
 *
 * @package LicenseKit
 */

declare( strict_types=1 );

namespace LicenseKit\Storage;

defined( 'ABSPATH' ) || exit;

class ReleaseFileStore {

	private const SUBDIR = 'licensekit/releases';

	/**
	 * Ensure the base directory exists and contains deny-all guards.
	 * Returns the absolute base directory path.
	 */
	public function ensure_directory(): string {
		$base = $this->base_dir();
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}
		$this->write_guard_files( $base );
		return $base;
	}

	/**
	 * Move an uploaded file into managed storage, compute SHA-256, return metadata.
	 *
	 * @param string $product_slug
	 * @param string $version
	 * @param string $source_path Filesystem path to the source zip (e.g. PHP upload tmp).
	 * @return array{success:bool, relative_path?:string, absolute_path?:string, size?:int, sha256?:string, error?:string}
	 */
	public function store( string $product_slug, string $version, string $source_path ): array {
		if ( ! is_readable( $source_path ) ) {
			return [ 'success' => false, 'error' => 'source_unreadable' ];
		}

		$slug    = $this->sanitize_segment( $product_slug );
		$version = $this->sanitize_segment( $version );
		if ( '' === $slug || '' === $version ) {
			return [ 'success' => false, 'error' => 'invalid_slug_or_version' ];
		}

		$base    = $this->ensure_directory();
		$dir     = $base . '/' . $slug;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$filename      = $slug . '-' . $version . '.zip';
		$absolute_path = $dir . '/' . $filename;

		// Use copy + delete rather than rename so this works across filesystems
		// (PHP upload tmp may be on a different mount). The OS umask gives the
		// file 0644 by default — explicit chmod is unnecessary.
		if ( ! @copy( $source_path, $absolute_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return [ 'success' => false, 'error' => 'copy_failed' ];
		}

		$size   = (int) filesize( $absolute_path );
		$sha256 = hash_file( 'sha256', $absolute_path );
		if ( false === $sha256 ) {
			wp_delete_file( $absolute_path );
			return [ 'success' => false, 'error' => 'hash_failed' ];
		}

		return [
			'success'       => true,
			'relative_path' => $slug . '/' . $filename,
			'absolute_path' => $absolute_path,
			'size'          => $size,
			'sha256'        => $sha256,
		];
	}

	public function delete( string $relative_path ): bool {
		$absolute = $this->absolute_path( $relative_path );
		if ( null === $absolute ) {
			return false;
		}
		if ( ! file_exists( $absolute ) ) {
			return true;
		}
		wp_delete_file( $absolute );
		return ! file_exists( $absolute );
	}

	/**
	 * Resolve a relative path to absolute, with traversal guard.
	 * Returns null if the path escapes the base directory.
	 */
	public function absolute_path( string $relative_path ): ?string {
		// Disallow empty, absolute, or traversal-bearing inputs.
		if ( '' === $relative_path || strpos( $relative_path, '..' ) !== false || $relative_path[0] === '/' ) {
			return null;
		}

		// Resolve the base first so we compare apples to apples on systems where
		// the base is reached via a symlink (macOS /tmp → /private/tmp, etc.).
		// The candidate file itself may not exist yet, so we don't realpath() it.
		$real_base = realpath( $this->base_dir() );
		if ( false === $real_base ) {
			return null;
		}
		$real_base = wp_normalize_path( $real_base );

		$candidate  = $real_base . '/' . ltrim( $relative_path, '/' );
		$normalized = $this->normalize_path( $candidate );
		if ( strpos( $normalized, $real_base ) !== 0 ) {
			return null;
		}
		return $normalized;
	}

	public function base_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$base    = ( $uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads' ) . '/' . self::SUBDIR;
		return wp_normalize_path( $base );
	}

	private function write_guard_files( string $dir ): void {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$content = "# LicenseKit — block direct downloads. Files are served via signed-token PHP endpoint.\n"
				. "<IfModule mod_authz_core.c>\n"
				. "    Require all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "    Order deny,allow\n"
				. "    Deny from all\n"
				. "</IfModule>\n";
			file_put_contents( $htaccess, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$webconfig = $dir . '/web.config';
		if ( ! file_exists( $webconfig ) ) {
			$content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<configuration>\n"
				. "    <system.webServer>\n"
				. "        <authorization>\n"
				. "            <clear />\n"
				. "            <deny users=\"*\" />\n"
				. "        </authorization>\n"
				. "    </system.webServer>\n"
				. "</configuration>\n";
			file_put_contents( $webconfig, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	private function sanitize_segment( string $segment ): string {
		// Allowed: a-z, 0-9, dot, dash. Strict — anything else dropped.
		$clean = preg_replace( '/[^a-z0-9.\-]/i', '', $segment ) ?? '';
		// Collapse runs of dots / dashes so `..evil` can't pass after stripping slashes.
		$clean = preg_replace( '/\.+/', '.', $clean ) ?? '';
		$clean = preg_replace( '/-+/', '-', $clean ) ?? '';
		// Trim leading/trailing punctuation; pure-dot/dash strings end up empty.
		return trim( $clean, '.-' );
	}

	private function normalize_path( string $path ): string {
		$path  = wp_normalize_path( $path );
		$parts = [];
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		// Preserve leading slash if original was absolute.
		return ( 0 === strpos( $path, '/' ) ? '/' : '' ) . implode( '/', $parts );
	}
}
