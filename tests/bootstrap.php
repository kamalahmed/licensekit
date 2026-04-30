<?php
/**
 * PHPUnit bootstrap.
 *
 * Stubs the WordPress functions used by LicenseKit's pure/service code so
 * unit tests run without wp-phpunit. Tests that need a real DB are kept out
 * of this suite — they'd require wp-phpunit + a real DB and are integration
 * tests by definition.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// In-memory option + transient stores. Tests can reset them via the helpers below.
$GLOBALS['__lk_options']    = [
	'licensekit_secrets' => [
		'hash_pepper'     => 'phpunit-pepper',
		'hmac_secret'     => 'phpunit-hmac',
		'download_secret' => 'phpunit-download',
	],
];
$GLOBALS['__lk_transients'] = [];

function lk_test_reset_state(): void {
	$GLOBALS['__lk_options']['licensekit_secrets'] = [
		'hash_pepper'     => 'phpunit-pepper',
		'hmac_secret'     => 'phpunit-hmac',
		'download_secret' => 'phpunit-download',
	];
	$GLOBALS['__lk_transients'] = [];
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $options = 0 ) {
		return json_encode( $value, $options );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $p ) {
		return str_replace( '\\', '/', (string) $p );
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $p ) {
		return is_dir( $p ) || mkdir( $p, 0755, true );
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $t = null, $c = false ) {
		return [ 'basedir' => sys_get_temp_dir() . '/lk-tests/uploads' ];
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $f ) {
		return preg_replace( '/[^a-zA-Z0-9.\-_]/', '', (string) $f );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $f ) {
		if ( file_exists( $f ) ) {
			@unlink( $f ); // phpcs:ignore
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile
	class WP_Post { // phpcs:ignore
		public int $ID            = 0;
		public string $post_type   = '';
		public string $post_title  = '';
		public string $post_name   = '';
		public string $post_status = 'publish';
		public string $post_date   = '';
		public string $post_date_gmt = '';
		public int $post_author     = 0;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$a ) {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return false;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}

// Option store
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['__lk_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__lk_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['__lk_options'][ $key ] );
		return true;
	}
}

// Transient store with TTL bookkeeping.
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) {
		$entry = $GLOBALS['__lk_transients'][ $k ] ?? null;
		if ( null === $entry ) {
			return false;
		}
		if ( isset( $entry['expires'] ) && $entry['expires'] < time() ) {
			unset( $GLOBALS['__lk_transients'][ $k ] );
			return false;
		}
		return $entry['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl ) {
		$GLOBALS['__lk_transients'][ $k ] = [
			'value'   => $v,
			'expires' => $ttl > 0 ? time() + $ttl : PHP_INT_MAX,
		];
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) {
		unset( $GLOBALS['__lk_transients'][ $k ] );
		return true;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) {
		return is_string( $v ) ? stripslashes( $v ) : $v;
	}
}

// Plugin source autoloader.
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'LicenseKit\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/../src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
