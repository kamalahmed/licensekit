# LicenseKit Client SDK

Single-file PHP drop-in that lets any WordPress plugin or theme talk to a LicenseKit server: license activation, license validation, and automatic updates with changelog. Zero composer requirement, zero JS build, no external dependencies. PHP 7.4+ compatible.

## Install (3 steps)

1. **Copy the SDK file** into your plugin or theme:

   ```
   your-plugin/lib/licensekit-client.php
   ```

2. **Add ~10 lines** to your plugin's main file (or theme's `functions.php`). See `examples/plugin-bootstrap.php` and `examples/theme-functions-snippet.php`.

3. **Render the license field** wherever you want it in your settings UI:

   ```php
   do_action( 'myp_render_license_field' );
   ```

That's it. The SDK handles activation, validation, update injection, and the license input UI.

## Configuration

`\LicenseKit_Client_v1::boot([...])` accepts:

| Key | Required | Description |
| --- | --- | --- |
| `product_slug` | yes | Product identifier registered on your LicenseKit server. |
| `plugin_file` / `stylesheet` | yes | One of these depending on `item_type`. For plugins, pass `__FILE__`. For themes, pass `get_stylesheet()`. |
| `item_type` | yes | `'plugin'` or `'theme'`. |
| `server_url` | yes | Your LicenseKit server, e.g. `https://updates.example.com`. **HTTPS required** unless `dev_mode` is true. |
| `version` | yes | Current installed version of your plugin/theme — used in update version-compare. |
| `license_option_key` | yes | The wp_option key the SDK fully owns for license state. Pick something unique, e.g. `myp_license`. |
| `signing_key` | yes | Your server's `LICENSEKIT_HMAC_SECRET`. Find it on the LicenseKit Settings → Security page. |
| `text_domain` | yes | Your plugin's text domain for translatable UI strings. |
| `render_field_hook` | recommended | A `do_action` name where the SDK will render its license input. Vendor calls `do_action($name)` from their settings page. |
| `check_period` | no | Validate cache TTL in seconds. Default 12h (`43200`). Minimum 60s. |
| `update_channel` | no | `'stable'` (default), `'beta'`, or `'rc'`. |
| `capability` | no | Capability required to activate/deactivate. Default `manage_options`. |
| `dev_mode` | no | When true, allows `http://` server URLs and skips signature verification. **Never enable in production.** |

## What the SDK does

- Hooks `pre_set_site_transient_update_{plugins,themes}` so WP's standard update flow picks up your releases.
- Hooks `{plugins,themes}_api` so the "View details" popup shows your changelog, banners, and icons.
- Calls `/license/validate` once per `check_period`, caches the result, and serves stale-but-cached on network failures.
- Verifies the HMAC signature on every server response. Tampered responses are treated as network errors.
- Renders a license-key input + Activate/Deactivate button + status badge wherever you call `do_action( $render_field_hook )`.
- Implements exponential backoff on repeated failures (1m → 2m → 4m → … capped at 6h) so a misconfigured server can't hammer the customer's site.
- Site-URL normalization mirrors the server: `https://Example.com:443/foo/` → `example.com`. Local hostnames (`localhost`, `*.local`, `*.test`, RFC1918) report as `local` environment and are exempt from activation limits.
- Multiple plugins can ship the SDK without colliding: the highest version found on the site is auto-aliased to `LicenseKit_Client_v1`. (PUC-style — newest wins.)

## What the SDK does NOT do

- It does **not** lock or disable your plugin's features when the license is invalid. If a license expires, updates simply stop — your plugin keeps working. License-gating features is your job (read the SDK's `license_option_key` and decide).
- It does **not** add an admin menu or settings page. It renders into yours via `do_action()`.

## Forward compatibility

This SDK is `LicenseKit_Client_v1`. Major version bumps (`v2`) will require an opt-in change in your bootstrap — minor and patch versions will not.
