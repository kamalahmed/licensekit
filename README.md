# LicenseKit

> A self-hosted licensing and update server for WordPress plugins and themes. Sells via Easy Digital Downloads or WooCommerce. Includes a single-file client SDK.

[![Tests](https://github.com/kamalahmed/licensekit/actions/workflows/tests.yml/badge.svg)](https://github.com/kamalahmed/licensekit/actions/workflows/tests.yml)
[![PHPCS](https://github.com/kamalahmed/licensekit/actions/workflows/phpcs.yml/badge.svg)](https://github.com/kamalahmed/licensekit/actions/workflows/phpcs.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

LicenseKit turns a WordPress site into a complete licensing and update server for plugins or themes you sell. It's a self-hosted, GPL-licensed alternative to paid extensions like EDD Software Licensing — works with EDD **and** WooCommerce, no per-license fees.

## What you get

- **License issuance** on purchase (EDD or WooCommerce).
- **Update delivery** through WordPress's native update flow — customers see your updates the same way they see wp.org plugin updates.
- **Single-file client SDK** (~700 lines, PHP 7.4+). Vendor integrates in ~10 lines.
- **Customer dashboard** shortcode + WC My Account "Licenses" tab — click-to-copy keys, status, activations, expiry, self-service rotate button.
- **Vendor admin pages** — products, releases, licenses, activations, API tokens, webhooks, audit logs.
- **Vendor REST API** — full CRUD for automation (CI/CD release uploads, etc.) with capability-scoped Bearer tokens.
- **Webhooks** with retries via Action Scheduler.
- **Ed25519 signed responses** — public key safe to embed in shipped plugins.
- **EDD Software Licensing migration importer** — preserves customer keys.

[**Full documentation →**](docs/index.html)

## Quick start

### Install on your shop site

```bash
cd wp-content/plugins
git clone https://github.com/kamalahmed/licensekit.git
cd licensekit && composer install --no-dev
```

Activate from **WordPress → Plugins**. Open **License Kit → Settings** and copy the Ed25519 public key.

### Integrate the SDK in your plugin

```php
require_once __DIR__ . '/lib/licensekit-client.php';

add_action( 'plugins_loaded', static function () {
    \LicenseKit_Client_v1::boot( [
        'product_slug'       => 'my-awesome-plugin',
        'plugin_file'        => __FILE__,
        'item_type'          => 'plugin',
        'server_url'         => 'https://shop.example.com',
        'version'            => '1.0.0',
        'license_option_key' => 'myp_license',
        'text_domain'        => 'my-awesome-plugin',
        'render_field_hook'  => 'myp_render_license_field',
        'public_key'         => 'BASE64_ED25519_PUBLIC_KEY_FROM_SETTINGS',
    ] );
}, 5 );

// Anywhere in your settings UI:
do_action( 'myp_render_license_field' );
```

That's it. Activation, validation, the license input field, and update delivery all flow through the SDK.

## Compatibility

- WordPress 6.0+
- PHP 7.4+ (libsodium recommended for full Ed25519 signing; HMAC fallback otherwise)
- Easy Digital Downloads 3.0+ or WooCommerce 7.0+
- Optional: EDD Recurring or WooCommerce Subscriptions for renewal handling

## Security model

- License keys: stored as peppered SHA-256 hash plus sodium-encrypted copy for customer-dashboard reveal. Compromised database alone (without `wp-config.php`) cannot recover keys.
- Server responses: Ed25519-signed envelopes. Plugin source can be public — possessing the public key cannot forge signatures.
- Release downloads: 5-minute single-use HMAC-signed tokens. Direct URL access blocked by `.htaccess` / `web.config`.
- Brute force: per-IP and per-key rate limits. 5 failed activations on a key triggers a 15-minute lockout.
- HTTPS enforced on public REST endpoints (override only via `LICENSEKIT_ALLOW_HTTP` for dev).

## Development

```bash
composer install                  # install dev deps
composer test                     # phpunit (151 tests, 358 assertions)
composer lint                     # phpcs
composer lint:fix                 # phpcbf
```

### Plugin Check (PCP)

```bash
# After installing the Plugin Check plugin in your dev WordPress:
vendor/bin/phpcs \
  --standard=../plugin-check/phpcs-rulesets/plugin-check.ruleset.xml \
  licensekit.php src/ uninstall.php client-sdk/licensekit-client.php
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please run the test suite before submitting a PR.

## License

[GPL-2.0-or-later](LICENSE).
