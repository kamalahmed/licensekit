<?php
/**
 * LicenseKit Client SDK — single-file drop-in for plugin and theme authors.
 *
 * Copy this file into your plugin or theme (e.g. `lib/licensekit-client.php`),
 * `require_once` it, and call `\LicenseKit_Client_v1::boot([...])` with your
 * configuration. See `client-sdk/examples/` for ~20-line plugin and theme
 * integrations.
 *
 * Version: 1.0.0
 * License: GPL-2.0-or-later
 *
 * Architecture notes:
 *   - The class is named `LicenseKit_Client_v1_0_0`. On `plugins_loaded`
 *     priority 1, the highest version of the SDK present on the site is
 *     `class_alias()`'d to `LicenseKit_Client_v1` — vendors only ever call
 *     the major-stable name, and the *newest* SDK on the site wins, no
 *     matter which plugin loads first. (Pattern borrowed from PUC.)
 *   - All server responses are HMAC-signed envelopes. The SDK rejects any
 *     response whose signature doesn't match the configured `signing_key`,
 *     treating tampering as a network error so the host plugin keeps working.
 *   - Network failure never breaks the host plugin. Update injection simply
 *     skips; license-gated features remain the host's responsibility.
 *
 * @package LicenseKit\ClientSDK
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// Versioning + alias machinery (PUC-style highest-version-wins)
// =============================================================================

if ( ! isset( $GLOBALS['licensekit_client_versions'] ) ) {
	$GLOBALS['licensekit_client_versions'] = [];
}
$GLOBALS['licensekit_client_versions']['1.0.0'] = 'LicenseKit_Client_v1_0_0';

if ( ! function_exists( 'licensekit_client_resolve_alias' ) ) {
	/**
	 * On plugins_loaded p=1, alias `LicenseKit_Client_v1` to the highest
	 * versioned class that any SDK file on this site has registered.
	 */
	function licensekit_client_resolve_alias() {
		if ( class_exists( 'LicenseKit_Client_v1', false ) ) {
			return;
		}
		$versions = isset( $GLOBALS['licensekit_client_versions'] )
			? (array) $GLOBALS['licensekit_client_versions']
			: [];
		if ( empty( $versions ) ) {
			return;
		}
		uksort( $versions, 'version_compare' );
		end( $versions );
		$winner = current( $versions );
		if ( is_string( $winner ) && class_exists( $winner ) ) {
			class_alias( $winner, 'LicenseKit_Client_v1' );
		}
	}
	add_action( 'plugins_loaded', 'licensekit_client_resolve_alias', 1 );
}

// =============================================================================
// SDK class
// =============================================================================

if ( ! class_exists( 'LicenseKit_Client_v1_0_0', false ) ) {

	/**
	 * @phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
	 * @phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
	 */
	final class LicenseKit_Client_v1_0_0 {

		const SDK_VERSION          = '1.0.0';
		const DEFAULT_CHECK_PERIOD = 43200;   // 12 hours
		const STALE_CACHE_MAX      = 604800;  // 7 days — fall back to stale-but-cached on network failure
		const FAIL_BACKOFF_BASE    = 60;      // 1 minute
		const FAIL_BACKOFF_MAX     = 21600;   // 6 hours

		const STATUS_ACTIVE   = 'active';
		const STATUS_INACTIVE = 'inactive';
		const STATUS_EXPIRED  = 'expired';
		const STATUS_INVALID  = 'invalid';

		const ITEM_PLUGIN = 'plugin';
		const ITEM_THEME  = 'theme';

		const ERR_NETWORK     = 'network_error';
		const ERR_SIGNATURE   = 'signature_invalid';
		const ERR_MISCONFIG   = 'misconfigured';

		/** @var array<string, mixed> */
		private $config;

		/** @var string Identifier used for caches/options/ajax actions. */
		private $slug;

		public static function boot( array $config ) {
			static $instances = [];
			$slug = isset( $config['product_slug'] ) ? (string) $config['product_slug'] : '';
			if ( '' === $slug ) {
				return null;
			}
			if ( isset( $instances[ $slug ] ) ) {
				return $instances[ $slug ];
			}
			$instances[ $slug ] = new self( $config );
			return $instances[ $slug ];
		}

		public function __construct( array $config ) {
			$this->config = $this->normalize_config( $config );
			$this->slug   = $this->config['product_slug'];
			$this->attach_hooks();
		}

		// -------------------------------------------------------------------
		// Configuration
		// -------------------------------------------------------------------

		/**
		 * @param array<string, mixed> $config
		 * @return array<string, mixed>
		 */
		private function normalize_config( array $config ) {
			$defaults = [
				'product_slug'       => '',
				'plugin_file'        => '',                          // for ITEM_PLUGIN
				'stylesheet'         => '',                          // for ITEM_THEME
				'item_type'          => self::ITEM_PLUGIN,
				'server_url'         => '',
				'version'            => '0.0.0',
				'license_option_key' => '',                          // SDK fully owns this option
				'text_domain'        => 'default',
				'render_field_hook'  => '',                          // do_action() name where SDK injects field
				'check_period'       => self::DEFAULT_CHECK_PERIOD,
				'update_channel'     => 'stable',
				'public_key'         => '',                          // Ed25519 public key (preferred — safe to publish in plugin source)
				'signing_key'        => '',                          // HMAC secret (legacy fallback for sodium-less hosts; not safe to publish)
				'capability'         => 'manage_options',
				'dev_mode'           => false,                       // allows http:// and skips signature verify
			];
			$config = array_merge( $defaults, $config );

			$config['product_slug']       = (string) $config['product_slug'];
			$config['server_url']         = untrailingslashit( (string) $config['server_url'] );
			$config['license_option_key'] = (string) $config['license_option_key'];
			$config['item_type']          = self::ITEM_THEME === $config['item_type'] ? self::ITEM_THEME : self::ITEM_PLUGIN;
			$config['check_period']       = max( 60, (int) $config['check_period'] );
			$config['dev_mode']           = (bool) $config['dev_mode'];

			$valid = '' !== $config['product_slug']
				&& '' !== $config['server_url']
				&& '' !== $config['license_option_key']
				&& ( self::ITEM_THEME === $config['item_type'] ? '' !== $config['stylesheet'] : '' !== $config['plugin_file'] );

			if ( ! $valid ) {
				$config['__misconfigured'] = true;
			}

			// Refuse non-HTTPS server URLs unless dev_mode.
			if ( ! $config['dev_mode'] && 0 !== strpos( $config['server_url'], 'https://' ) ) {
				$config['__misconfigured'] = true;
			}

			return $config;
		}

		private function is_misconfigured() {
			return ! empty( $this->config['__misconfigured'] );
		}

		// -------------------------------------------------------------------
		// Hook attachment
		// -------------------------------------------------------------------

		private function attach_hooks() {
			if ( $this->is_misconfigured() ) {
				return;
			}

			if ( self::ITEM_THEME === $this->config['item_type'] ) {
				add_filter( 'pre_set_site_transient_update_themes', [ $this, 'inject_update_transient' ] );
				add_filter( 'themes_api', [ $this, 'inject_plugins_api' ], 10, 3 );
			} else {
				add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update_transient' ] );
				add_filter( 'plugins_api', [ $this, 'inject_plugins_api' ], 10, 3 );
				// Re-mint a fresh download token if WP's upgrader is about to download a stale one.
				add_filter( 'upgrader_pre_download', [ $this, 'maybe_refresh_package_url' ], 10, 3 );
			}

			if ( '' !== $this->config['render_field_hook'] ) {
				add_action( $this->config['render_field_hook'], [ $this, 'render_field' ] );
			}

			$action = $this->action_name();
			add_action( 'admin_post_' . $action, [ $this, 'handle_form_post' ] );
		}

		// -------------------------------------------------------------------
		// License lifecycle (public; vendor can call directly if they prefer)
		// -------------------------------------------------------------------

		public function activate( $license_key ) {
			if ( $this->is_misconfigured() ) {
				return $this->error( self::ERR_MISCONFIG, __( 'SDK is not configured correctly.', 'default' ) );
			}
			$license_key = trim( (string) $license_key );
			if ( '' === $license_key ) {
				return $this->error( 'missing_field', __( 'Please enter a license key.', $this->config['text_domain'] ) );
			}

			$response = $this->http_request(
				'/license/activate',
				[
					'license_key'  => $license_key,
					'product_slug' => $this->config['product_slug'],
					'site_url'     => $this->normalized_site_url(),
					'environment'  => $this->detect_environment(),
				]
			);

			if ( ! $response['ok'] ) {
				return $response;
			}

			$body = $response['body'];
			if ( ! empty( $body['success'] ) ) {
				$this->save_license_state( $license_key, self::STATUS_ACTIVE, $body );
				$this->clear_caches();
				return [ 'ok' => true, 'success' => true, 'body' => $body ];
			}

			$this->save_license_state( $license_key, self::STATUS_INVALID, $body );
			return [ 'ok' => true, 'success' => false, 'body' => $body ];
		}

		public function deactivate() {
			if ( $this->is_misconfigured() ) {
				return $this->error( self::ERR_MISCONFIG, __( 'SDK is not configured correctly.', 'default' ) );
			}

			$state = $this->get_license_state();
			$key   = isset( $state['key'] ) ? (string) $state['key'] : '';
			if ( '' === $key ) {
				$this->clear_license_state();
				return [ 'ok' => true, 'success' => true ];
			}

			$response = $this->http_request(
				'/license/deactivate',
				[
					'license_key' => $key,
					'site_url'    => $this->normalized_site_url(),
				]
			);

			$this->clear_license_state();
			$this->clear_caches();
			return $response;
		}

		/**
		 * Periodic re-check. Returns cached state without a network call if recent.
		 *
		 * @param bool $force Bypass the check_period cache.
		 */
		public function validate( $force = false ) {
			if ( $this->is_misconfigured() ) {
				return $this->error( self::ERR_MISCONFIG, __( 'SDK is not configured correctly.', 'default' ) );
			}

			$state = $this->get_license_state();
			$key   = isset( $state['key'] ) ? (string) $state['key'] : '';
			if ( '' === $key ) {
				return [ 'ok' => true, 'success' => false, 'body' => [ 'error' => 'no_license' ] ];
			}

			if ( ! $force ) {
				$last  = isset( $state['last_check'] ) ? (int) $state['last_check'] : 0;
				$age   = time() - $last;
				if ( $age >= 0 && $age < $this->config['check_period'] ) {
					return [ 'ok' => true, 'success' => self::STATUS_ACTIVE === ( $state['status'] ?? '' ), 'body' => $state ];
				}
			}

			$response = $this->http_request(
				'/license/validate',
				[
					'license_key'  => $key,
					'product_slug' => $this->config['product_slug'],
					'site_url'     => $this->normalized_site_url(),
				]
			);

			if ( ! $response['ok'] ) {
				return $response;
			}

			$body = $response['body'];
			if ( ! empty( $body['success'] ) ) {
				$this->save_license_state( $key, self::STATUS_ACTIVE, $body );
			} else {
				$err = isset( $body['error'] ) ? (string) $body['error'] : '';
				$this->save_license_state( $key, ( 'expired' === $err ? self::STATUS_EXPIRED : self::STATUS_INVALID ), $body );
			}

			return $response;
		}

		// -------------------------------------------------------------------
		// Update transient + plugins_api / themes_api injection
		// -------------------------------------------------------------------

		public function inject_update_transient( $transient ) {
			if ( ! is_object( $transient ) ) {
				return $transient;
			}
			$metadata = $this->get_update_metadata();
			if ( null === $metadata ) {
				return $transient;
			}

			$has_newer = isset( $metadata['new_version'] )
				&& version_compare( $metadata['new_version'], $this->config['version'], '>' );

			if ( ! $has_newer ) {
				return $transient;
			}

			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = [];
			}

			if ( self::ITEM_THEME === $this->config['item_type'] ) {
				$theme_slug = (string) $this->config['stylesheet'];
				$transient->response[ $theme_slug ] = [
					'theme'       => $theme_slug,
					'new_version' => (string) $metadata['new_version'],
					'package'     => isset( $metadata['package'] ) ? (string) $metadata['package'] : '',
					'url'         => isset( $metadata['homepage'] ) ? (string) $metadata['homepage'] : '',
					'requires'    => isset( $metadata['requires'] ) ? (string) $metadata['requires'] : '',
					'requires_php' => isset( $metadata['requires_php'] ) ? (string) $metadata['requires_php'] : '',
				];
			} else {
				$basename = plugin_basename( $this->config['plugin_file'] );
				$transient->response[ $basename ] = (object) [
					'id'           => $basename,
					'slug'         => (string) $this->config['product_slug'],
					'plugin'       => $basename,
					'new_version'  => (string) $metadata['new_version'],
					'url'          => isset( $metadata['homepage'] ) ? (string) $metadata['homepage'] : '',
					'package'      => isset( $metadata['package'] ) ? (string) $metadata['package'] : '',
					'tested'       => isset( $metadata['tested'] ) ? (string) $metadata['tested'] : '',
					'requires'     => isset( $metadata['requires'] ) ? (string) $metadata['requires'] : '',
					'requires_php' => isset( $metadata['requires_php'] ) ? (string) $metadata['requires_php'] : '',
					'icons'        => isset( $metadata['icons'] ) ? (array) $metadata['icons'] : [],
					'banners'      => isset( $metadata['banners'] ) ? (array) $metadata['banners'] : [],
				];
			}

			return $transient;
		}

		public function inject_plugins_api( $result, $action, $args ) {
			$expected_action = self::ITEM_THEME === $this->config['item_type'] ? 'theme_information' : 'plugin_information';
			if ( $action !== $expected_action ) {
				return $result;
			}
			if ( ! isset( $args->slug ) || $args->slug !== $this->config['product_slug'] ) {
				return $result;
			}

			$metadata = $this->get_update_metadata();
			if ( null === $metadata ) {
				return $result;
			}

			$payload = [
				'name'          => isset( $metadata['name'] ) ? (string) $metadata['name'] : '',
				'slug'          => (string) $this->config['product_slug'],
				'version'       => isset( $metadata['version'] ) ? (string) $metadata['version'] : '',
				'author'        => isset( $metadata['author'] ) ? (string) $metadata['author'] : '',
				'homepage'      => isset( $metadata['homepage'] ) ? (string) $metadata['homepage'] : '',
				'requires'      => isset( $metadata['requires'] ) ? (string) $metadata['requires'] : '',
				'requires_php'  => isset( $metadata['requires_php'] ) ? (string) $metadata['requires_php'] : '',
				'tested'        => isset( $metadata['tested'] ) ? (string) $metadata['tested'] : '',
				'last_updated'  => isset( $metadata['last_updated'] ) ? (string) $metadata['last_updated'] : '',
				'sections'      => isset( $metadata['sections'] ) ? (array) $metadata['sections'] : [],
				'download_link' => isset( $metadata['package'] ) ? (string) $metadata['package'] : '',
			];

			if ( isset( $metadata['icons'] ) && is_array( $metadata['icons'] ) ) {
				$payload['icons'] = $metadata['icons'];
			}
			if ( isset( $metadata['banners'] ) && is_array( $metadata['banners'] ) ) {
				$payload['banners'] = $metadata['banners'];
			}

			return (object) $payload;
		}

		/**
		 * If WP is about to download a `package` URL whose signed token is
		 * older than ~80% of its TTL, refresh the metadata first to mint a
		 * fresh token. Returns the original $reply unchanged.
		 */
		public function maybe_refresh_package_url( $reply, $package, $upgrader ) {
			if ( ! is_string( $package ) || false === strpos( $package, $this->config['server_url'] ) ) {
				return $reply;
			}
			if ( false === strpos( $package, '/update/download' ) ) {
				return $reply;
			}
			// Force a fresh metadata fetch — this stores a new package URL in the cache.
			$this->fetch_update_metadata( true );
			return $reply;
		}

		// -------------------------------------------------------------------
		// License field UI (rendered into the vendor's settings page)
		// -------------------------------------------------------------------

		public function render_field() {
			$state    = $this->get_license_state();
			$key      = isset( $state['key'] ) ? (string) $state['key'] : '';
			$status   = isset( $state['status'] ) ? (string) $state['status'] : self::STATUS_INACTIVE;
			$action   = $this->action_name();
			$nonce    = wp_create_nonce( $action );
			$flash    = $this->consume_flash();
			$is_active = self::STATUS_ACTIVE === $status;
			$display  = $is_active ? $this->mask_key( $key ) : $key;

			$status_text = $this->status_label( $status );
			$msg_html    = $flash ? sprintf(
				'<p class="lk-flash lk-flash-%s">%s</p>',
				esc_attr( $flash['type'] ),
				esc_html( $flash['message'] )
			) : '';

			?>
			<div class="lk-license-field">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
					<input type="hidden" name="_lk_nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->current_admin_url() ); ?>">

					<label for="lk-key-<?php echo esc_attr( $this->slug ); ?>" class="screen-reader-text">
						<?php esc_html_e( 'License key', 'default' ); ?>
					</label>
					<input
						type="text"
						id="lk-key-<?php echo esc_attr( $this->slug ); ?>"
						name="license_key"
						value="<?php echo esc_attr( $display ); ?>"
						placeholder="LK1-XXXX-XXXX-XXXX-XXXX-XXXX"
						class="regular-text"
						<?php echo $is_active ? 'readonly' : ''; ?>
						autocomplete="off"
					>

					<?php if ( $is_active ) : ?>
						<button type="submit" name="op" value="deactivate" class="button">
							<?php esc_html_e( 'Deactivate', 'default' ); ?>
						</button>
					<?php else : ?>
						<button type="submit" name="op" value="activate" class="button button-primary">
							<?php esc_html_e( 'Activate', 'default' ); ?>
						</button>
					<?php endif; ?>

					<span class="lk-status lk-status-<?php echo esc_attr( $status ); ?>">
						<?php echo esc_html( $status_text ); ?>
					</span>
				</form>
				<?php echo wp_kses_post( $msg_html ); ?>
				<?php if ( $is_active && ! empty( $state['expires_at'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: expiry date */
							esc_html__( 'Expires: %s', 'default' ),
							esc_html( (string) $state['expires_at'] )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
			<style>
				.lk-license-field { display: block; }
				.lk-license-field input[type=text] { font-family: monospace; }
				.lk-status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; margin-left: 8px; }
				.lk-status-active { background: #d4edda; color: #155724; }
				.lk-status-expired { background: #fff3cd; color: #856404; }
				.lk-status-invalid { background: #f8d7da; color: #721c24; }
				.lk-status-inactive { background: #e2e3e5; color: #383d41; }
				.lk-flash { padding: 8px 12px; border-radius: 3px; margin-top: 8px; }
				.lk-flash-success { background: #d4edda; color: #155724; }
				.lk-flash-error { background: #f8d7da; color: #721c24; }
			</style>
			<?php
		}

		public function handle_form_post() {
			if ( ! current_user_can( $this->config['capability'] ) ) {
				wp_die( esc_html__( 'You do not have permission.', 'default' ), 403 );
			}
			$nonce = isset( $_POST['_lk_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_lk_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, $this->action_name() ) ) {
				wp_die( esc_html__( 'Invalid request.', 'default' ), 403 );
			}

			$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
			$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['license_key'] ) ) : '';

			if ( 'activate' === $op ) {
				$result = $this->activate( $key );
				if ( ! empty( $result['success'] ) ) {
					$this->set_flash( 'success', __( 'License activated.', 'default' ) );
				} else {
					$body = isset( $result['body'] ) ? (array) $result['body'] : [];
					$this->set_flash( 'error', $this->error_message_for( $result, $body ) );
				}
			} elseif ( 'deactivate' === $op ) {
				$this->deactivate();
				$this->set_flash( 'success', __( 'License deactivated.', 'default' ) );
			}

			$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) ) : admin_url();
			wp_safe_redirect( $redirect );
			exit;
		}

		// -------------------------------------------------------------------
		// HTTP + signature verification
		// -------------------------------------------------------------------

		/**
		 * @return array{ok:bool, success?:bool, body?:array, error?:string, message?:string}
		 */
		private function http_request( $endpoint, array $body, $method = 'POST' ) {
			$retry_after = $this->backoff_until();
			if ( $retry_after > time() ) {
				return $this->error( self::ERR_NETWORK, __( 'Backing off after recent failures. Try later.', 'default' ) );
			}

			$body['sdk_version'] = self::SDK_VERSION;
			$url                 = $this->config['server_url'] . '/wp-json/licensekit/v1' . $endpoint;
			$args                = [
				'method'     => $method,
				'timeout'    => 10,
				'headers'    => [ 'Content-Type' => 'application/json' ],
				'body'       => wp_json_encode( $body ),
				'user-agent' => 'LicenseKit-SDK/' . self::SDK_VERSION . '; ' . $this->config['product_slug'],
			];

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				$this->record_failure();
				return $this->error( self::ERR_NETWORK, $response->get_error_message() );
			}

			$code   = (int) wp_remote_retrieve_response_code( $response );
			$body_s = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body_s, true );
			if ( ! is_array( $decoded ) ) {
				$this->record_failure();
				return $this->error( self::ERR_NETWORK, __( 'Invalid response from server.', 'default' ) );
			}

			if ( ! $this->verify_signature( $decoded ) ) {
				$this->record_failure();
				return $this->error( self::ERR_SIGNATURE, __( 'Server response could not be verified.', 'default' ) );
			}

			$this->reset_failure();
			return [ 'ok' => true, 'success' => $code < 400, 'body' => $decoded, 'http_status' => $code ];
		}

		/**
		 * Verify the signature on a server response.
		 *
		 * Tries Ed25519 first (preferred — `public_key` is safe in plugin source).
		 * Falls back to HMAC if only `signing_key` is configured. Returns true
		 * unconditionally when `dev_mode` is on, for local development against
		 * a co-installed server with no key configured.
		 */
		private function verify_signature( array $envelope ) {
			if ( ! empty( $this->config['dev_mode'] ) ) {
				return true;
			}

			$presented = isset( $envelope['signature'] ) ? (string) $envelope['signature'] : '';
			if ( '' === $presented ) {
				return false;
			}
			unset( $envelope['signature'] );
			$canonical = $this->canonical_json( $envelope );

			// Path B (preferred): Ed25519 with the embedded public key.
			$public_key = (string) $this->config['public_key'];
			if ( '' !== $public_key && function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				$pub_raw = $this->b64url_decode( $public_key );
				$sig_raw = $this->b64url_decode( $presented );
				if ( null !== $pub_raw && null !== $sig_raw ) {
					return sodium_crypto_sign_verify_detached( $sig_raw, $canonical, $pub_raw );
				}
				return false;
			}

			// HMAC fallback for sodium-less hosts. NOT safe if the plugin source
			// is publicly downloadable — extracted key lets attackers forge.
			$signing_key = (string) $this->config['signing_key'];
			if ( '' === $signing_key ) {
				return false;
			}
			$expected = $this->b64url( hash_hmac( 'sha256', $canonical, $signing_key, true ) );
			return hash_equals( $expected, $presented );
		}

		private function canonical_json( $value ) {
			if ( is_array( $value ) ) {
				$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
				if ( $is_assoc ) {
					ksort( $value );
				}
				foreach ( $value as $k => $v ) {
					if ( is_array( $v ) ) {
						$value[ $k ] = $this->canonical_json_inner( $v );
					}
				}
			}
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		private function canonical_json_inner( array $value ) {
			$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
			if ( $is_assoc ) {
				ksort( $value );
			}
			foreach ( $value as $k => $v ) {
				if ( is_array( $v ) ) {
					$value[ $k ] = $this->canonical_json_inner( $v );
				}
			}
			return $value;
		}

		private function b64url( $raw ) {
			return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		private function b64url_decode( $token ) {
			$padded  = strtr( (string) $token, '-_', '+/' );
			$mod     = strlen( $padded ) % 4;
			$padded .= 0 === $mod ? '' : str_repeat( '=', 4 - $mod );
			$out     = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			return false === $out ? null : $out;
		}

		// -------------------------------------------------------------------
		// Update metadata cache
		// -------------------------------------------------------------------

		/**
		 * @return array<string, mixed>|null
		 */
		private function get_update_metadata() {
			$cached = get_site_transient( $this->cache_key( 'update' ) );
			if ( is_array( $cached ) ) {
				$age = time() - (int) ( $cached['fetched_at'] ?? 0 );
				if ( $age >= 0 && $age < $this->config['check_period'] ) {
					return $cached['payload'];
				}
				// Try a fresh fetch; if it fails, return the stale cache (up to STALE_CACHE_MAX).
				$fresh = $this->fetch_update_metadata();
				if ( null !== $fresh ) {
					return $fresh;
				}
				if ( $age < self::STALE_CACHE_MAX ) {
					return $cached['payload'];
				}
				return null;
			}
			return $this->fetch_update_metadata();
		}

		/**
		 * @param bool $force Bypass any cache.
		 * @return array<string, mixed>|null
		 */
		private function fetch_update_metadata( $force = false ) {
			$state = $this->get_license_state();
			$key   = isset( $state['key'] ) ? (string) $state['key'] : '';
			if ( '' === $key ) {
				return null;
			}

			$path = self::ITEM_THEME === $this->config['item_type'] ? '/update/theme/' : '/update/plugin/';
			$url  = $this->config['server_url'] . '/wp-json/licensekit/v1' . $path . rawurlencode( $this->config['product_slug'] )
				. '?' . http_build_query(
					[
						'license_key' => $key,
						'site_url'    => $this->normalized_site_url(),
						'channel'     => $this->config['update_channel'],
						'installed_version' => $this->config['version'],
					]
				);

			$retry_after = $this->backoff_until();
			if ( ! $force && $retry_after > time() ) {
				return null;
			}

			$response = wp_remote_get(
				$url,
				[
					'timeout'    => 10,
					'user-agent' => 'LicenseKit-SDK/' . self::SDK_VERSION . '; ' . $this->config['product_slug'],
				]
			);

			if ( is_wp_error( $response ) ) {
				$this->record_failure();
				return null;
			}

			$body    = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) || ! $this->verify_signature( $decoded ) || empty( $decoded['success'] ) ) {
				$this->record_failure();
				return null;
			}

			$this->reset_failure();
			set_site_transient(
				$this->cache_key( 'update' ),
				[
					'payload'    => $decoded,
					'fetched_at' => time(),
				],
				self::STALE_CACHE_MAX
			);
			return $decoded;
		}

		// -------------------------------------------------------------------
		// State persistence
		// -------------------------------------------------------------------

		/**
		 * @return array<string, mixed>
		 */
		private function get_license_state() {
			return (array) get_option( $this->config['license_option_key'], [] );
		}

		/**
		 * @param string               $key
		 * @param string               $status
		 * @param array<string, mixed> $body
		 */
		private function save_license_state( $key, $status, array $body ) {
			$license_block = isset( $body['license'] ) && is_array( $body['license'] ) ? $body['license'] : [];
			$product_block = isset( $body['product'] ) && is_array( $body['product'] ) ? $body['product'] : [];

			$state = [
				'key'                => $key,
				'status'             => $status,
				'expires_at'         => $license_block['expires_at']        ?? null,
				'grace_until'        => $license_block['grace_until']       ?? null,
				'activations_used'   => isset( $license_block['activations_used'] ) ? (int) $license_block['activations_used'] : 0,
				'activations_limit'  => isset( $license_block['activations_limit'] ) ? (int) $license_block['activations_limit'] : 0,
				'last_check'         => time(),
				'last_error'         => isset( $body['error'] ) ? (string) $body['error'] : null,
				'product'            => [
					'name'            => isset( $product_block['name'] ) ? (string) $product_block['name'] : '',
					'current_version' => isset( $product_block['current_version'] ) ? (string) $product_block['current_version'] : '',
				],
			];
			update_option( $this->config['license_option_key'], $state, false );
		}

		private function clear_license_state() {
			delete_option( $this->config['license_option_key'] );
		}

		public function clear_caches() {
			delete_site_transient( $this->cache_key( 'update' ) );
		}

		private function cache_key( $bucket ) {
			return 'lk_' . substr( md5( $this->slug ), 0, 12 ) . '_' . $bucket;
		}

		// -------------------------------------------------------------------
		// Backoff
		// -------------------------------------------------------------------

		private function backoff_until() {
			$state = (array) get_site_transient( $this->cache_key( 'backoff' ) );
			return isset( $state['retry_after'] ) ? (int) $state['retry_after'] : 0;
		}

		private function record_failure() {
			$state = (array) get_site_transient( $this->cache_key( 'backoff' ) );
			$count = isset( $state['failures'] ) ? (int) $state['failures'] + 1 : 1;
			$wait  = min( self::FAIL_BACKOFF_BASE * ( 2 ** ( $count - 1 ) ), self::FAIL_BACKOFF_MAX );
			set_site_transient(
				$this->cache_key( 'backoff' ),
				[
					'failures'    => $count,
					'retry_after' => time() + $wait,
				],
				self::FAIL_BACKOFF_MAX
			);
		}

		private function reset_failure() {
			delete_site_transient( $this->cache_key( 'backoff' ) );
		}

		// -------------------------------------------------------------------
		// Site URL normalization (mirrors server's SiteNormalizer exactly)
		// -------------------------------------------------------------------

		private function normalized_site_url() {
			static $cached = null;
			if ( null !== $cached ) {
				return $cached;
			}
			$url = home_url();
			$url = trim( (string) $url );
			if ( '' === $url ) {
				$cached = '';
				return $cached;
			}
			if ( false === strpos( $url, '://' ) ) {
				$url = 'https://' . $url;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				$cached = strtolower( $url );
				return $cached;
			}

			$host = strtolower( (string) $parts['host'] );
			if ( function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
				$idn = @idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false !== $idn && '' !== $idn ) {
					$host = $idn;
				}
			}
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
			if ( 80 === $port || 443 === $port ) {
				$port = 0;
			}

			$cached = $port > 0 ? $host . ':' . $port : $host;
			return $cached;
		}

		private function detect_environment() {
			if ( function_exists( 'wp_get_environment_type' ) ) {
				$env = wp_get_environment_type();
				if ( 'staging' === $env ) {
					return 'staging';
				}
				if ( in_array( $env, [ 'development', 'local' ], true ) ) {
					return 'local';
				}
				if ( 'production' === $env ) {
					return 'production';
				}
			}
			$host = $this->normalized_site_url();
			if ( 'localhost' === $host || preg_match( '/\.(local|test|localhost|invalid|example)$/', $host ) ) {
				return 'local';
			}
			return 'production';
		}

		// -------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------

		private function action_name() {
			return 'licensekit_' . preg_replace( '/[^a-z0-9_]/i', '_', $this->slug );
		}

		private function current_admin_url() {
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security
			return '' !== $ref ? $ref : admin_url();
		}

		private function mask_key( $key ) {
			$key = (string) $key;
			$len = strlen( $key );
			if ( $len <= 4 ) {
				return str_repeat( '•', $len );
			}
			return str_repeat( '•', max( 0, $len - 4 ) ) . substr( $key, -4 );
		}

		private function status_label( $status ) {
			$map = [
				self::STATUS_ACTIVE   => __( 'Active', 'default' ),
				self::STATUS_EXPIRED  => __( 'Expired', 'default' ),
				self::STATUS_INVALID  => __( 'Invalid', 'default' ),
				self::STATUS_INACTIVE => __( 'Not activated', 'default' ),
			];
			return isset( $map[ $status ] ) ? $map[ $status ] : __( 'Unknown', 'default' );
		}

		private function set_flash( $type, $message ) {
			set_transient( $this->cache_key( 'flash' ), [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
		}

		private function consume_flash() {
			$flash = get_transient( $this->cache_key( 'flash' ) );
			if ( is_array( $flash ) ) {
				delete_transient( $this->cache_key( 'flash' ) );
				return $flash;
			}
			return null;
		}

		private function error( $code, $message ) {
			return [ 'ok' => false, 'success' => false, 'error' => $code, 'message' => $message ];
		}

		private function error_message_for( array $result, array $body ) {
			if ( ! empty( $result['message'] ) ) {
				return (string) $result['message'];
			}
			if ( ! empty( $body['message'] ) ) {
				return (string) $body['message'];
			}
			$code = isset( $body['error'] ) ? (string) $body['error'] : '';
			$map  = [
				'invalid_key'         => __( 'License key not recognized.', 'default' ),
				'expired'             => __( 'License has expired.', 'default' ),
				'disabled'            => __( 'License is disabled.', 'default' ),
				'revoked'             => __( 'License was revoked.', 'default' ),
				'pending'             => __( 'License is pending activation.', 'default' ),
				'product_mismatch'    => __( 'License does not match this product.', 'default' ),
				'activation_limit'    => __( 'Activation limit reached.', 'default' ),
				'rate_limited'        => __( 'Too many requests. Please try again shortly.', 'default' ),
				self::ERR_NETWORK     => __( "Couldn't reach the license server.", 'default' ),
				self::ERR_SIGNATURE   => __( 'License server response could not be verified.', 'default' ),
				self::ERR_MISCONFIG   => __( 'License client is misconfigured.', 'default' ),
			];
			return isset( $map[ $code ] ) ? $map[ $code ] : __( 'Unknown error.', 'default' );
		}
	}
}
