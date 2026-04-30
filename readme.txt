=== LicenseKit ===
Contributors: kamalahmed
Tags: license manager, software licensing, plugin updates, easy digital downloads, woocommerce
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted license issuance and update server for WordPress plugins and themes. Sells via Easy Digital Downloads or WooCommerce.

== Description ==

LicenseKit turns a WordPress site into a complete licensing and update server for the plugins and themes you sell. Customers buy through Easy Digital Downloads or WooCommerce, get a license key emailed to them, and their installed plugin or theme receives automatic updates with changelogs through WordPress's standard update flow.

= What's in the box =

* Sell licensed products through Easy Digital Downloads or WooCommerce — a "LicenseKit" panel on the product editor turns any product into a licensed item.
* Auto-issue license keys on purchase. The raw key is shown once on the receipt page and email; storage is sodium-encrypted at rest plus a peppered SHA-256 hash for lookup.
* Plugin and theme update delivery using WordPress's native `pre_set_site_transient_update_*` and `*_api` filters — your customers see updates the same way they see updates to wp.org plugins.
* Single-file client SDK (~700 lines, PHP 7.4+) that drops into any plugin or theme. Vendor integrates in ~10 lines.
* Customer dashboard — `[licensekit_customer_licenses]` shortcode + WooCommerce My Account "Licenses" tab — with click-to-copy keys and a self-service rotate button.
* Vendor admin pages — products, releases, licenses, activations, API tokens, webhooks, audit logs, tools, settings.
* Vendor REST API for automation — products, releases, licenses, activations, webhooks, logs CRUD via Bearer tokens with capability-scoped abilities.
* Webhooks for license and release lifecycle events, delivered asynchronously via Action Scheduler with exponential-backoff retries.
* Ed25519 signed responses on SDK-facing endpoints (HMAC fallback for hosts without libsodium). The public key is safe to embed in shipped plugins; only the private key can forge signatures, and it never leaves your server.
* Signed, single-use, 5-minute-TTL download tokens. Release zips are PHP-streamed; direct URL access blocked by `.htaccess` / `web.config`.
* WooCommerce variable-pricing per-variation tier overrides.
* Activation tiers (single, five, unlimited, custom — fully customizable via filter). Local and staging environments are exempt from activation limits by default.
* Renewals, grace periods, license rotation. Optional EDD Recurring or WooCommerce Subscriptions integration for subscription-driven renewals.
* One-click migration importer from EDD Software Licensing — preserves customer keys.
* GDPR data export and erasure for license + activation records.

= Why a self-hosted licensing plugin? =

Existing solutions either charge per-license fees, hardcode themselves to one e-commerce platform, or only validate licenses without serving updates. LicenseKit puts both halves in one GPL-licensed plugin so vendors keep full control of their licensing infrastructure and pay no recurring fees.

= External services =

LicenseKit acts as a server. **The plugin itself does NOT make outbound HTTP requests.** It receives requests from copies of your customer-facing plugins (which embed the LicenseKit Client SDK) and responds with license validation or update metadata.

If you configure webhooks in **License Kit → Webhooks**, the plugin will then make outbound HTTP POST requests to whichever URL(s) you configured, signed with HMAC-SHA256 using the per-webhook secret. Webhook URLs are explicitly opt-in.

The Client SDK (`licensekit-client.php`, distributed separately via GitHub releases) runs inside a vendor's customer's plugin. It makes outbound HTTP requests to the vendor's configured `server_url` for license activation, validation, and update checks. This is by design — without those requests, the SDK can't validate licenses or fetch updates.

== Installation ==

1. Upload the `licensekit` folder to `wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Open **License Kit → Settings** and copy the **Ed25519 public key** at the top of the page.
4. For production, add these constants to your `wp-config.php`:

       define( 'LICENSEKIT_HASH_PEPPER',     'paste-from-Settings' );
       define( 'LICENSEKIT_DOWNLOAD_SECRET', 'paste-from-Settings' );
       define( 'LICENSEKIT_HMAC_SECRET',     'paste-from-Settings' );
       define( 'LICENSEKIT_SIGN_SECRET',     'paste-from-Settings' );
       define( 'LICENSEKIT_SIGN_PUBLIC',     'paste-from-Settings' );

5. In your plugin or theme, drop the LicenseKit Client SDK (`licensekit-client.php`) into a `lib/` folder and call `\LicenseKit_Client_v1::boot([...])` with your configuration. The client SDK and example templates are distributed separately from the WordPress.org build — download the latest copy from the LicenseKit GitHub releases page or from **License Kit → Tools → Client SDK** in the admin.

== Frequently Asked Questions ==

= Does LicenseKit work with both EDD and WooCommerce? =

Yes. Both bridges are first-class. Activate either or both. License issuance, refunds, subscriptions, and customer dashboards work consistently across the two.

= Where are license keys stored? =

LicenseKit stores a peppered SHA-256 hash for lookup, plus a sodium-secretbox encrypted copy of the raw key. The pepper lives in `wp-config.php` (recommended) or in a wp-option fallback. A leaked database alone — without `wp-config.php` — cannot recover keys. The customer's dashboard decrypts on demand so they can copy their key any time. If both pepper and DB are lost, the operator rotates the key for the customer.

= What happens to existing activations when a license key is rotated? =

They keep working. Activations are linked to the license id, not the raw key. Rotation only changes the user-facing key; the license record (and its activation count, expiry, etc.) is preserved.

= Will my customers' sites break if my LicenseKit server goes down? =

No. The Client SDK serves stale-but-cached metadata for up to 7 days when the server is unreachable. The host plugin's other features keep working too — the SDK never disables anything on validation failure, it only suppresses update injection.

= Does the SDK work for themes? =

Yes. Pass `'item_type' => 'theme'` and `'stylesheet' => get_stylesheet()` instead of `'plugin_file'`.

= Can I migrate from EDD Software Licensing? =

Yes. **License Kit → Tools → Migrate from EDD Software Licensing**. Includes a dry-run mode. Preserves customer keys so installed sites keep working without re-pasting.

= Does the plugin work without libsodium? =

Yes. Where libsodium is unavailable, response signing falls back to HMAC-SHA256 and license-key encryption is skipped (the customer dashboard then shows only the prefix).

== Privacy ==

LicenseKit stores customer email addresses and (optionally) site URLs that customers activate against. It never collects or sends data to third parties. The standard WordPress privacy tooling — **Tools → Export Personal Data** and **Tools → Erase Personal Data** — includes all LicenseKit licenses and activations matching the customer's email.

== Changelog ==

= 0.1.0 =
* Initial release.
