# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-04-30

Initial release.

### Added
- License issuance, validation, activation, deactivation, rotation, extension, status changes.
- Update server with Ed25519-signed metadata responses + HMAC fallback.
- Signed-token, single-use, 5-minute-TTL release downloads.
- Single-file Client SDK (`client-sdk/licensekit-client.php`) — versioned class with PUC-style alias resolution. PHP 7.4+ compatible. Verifies Ed25519 signatures with embedded public key.
- Easy Digital Downloads bridge: per-download licensing meta box, license issuance on `edd_complete_purchase`, refund handling, EDD Recurring guarded integration, receipt page + email auto-append, `[licensekit_customer_licenses]` shortcode, customer self-rotate.
- WooCommerce bridge: per-product LicenseKit panel on the product editor, variable-pricing per-variation tier overrides, license issuance on `woocommerce_order_status_completed`, refund handling, WC Subscriptions guarded integration, receipt + email auto-append, My Account "Licenses" tab, customer self-rotate.
- EDD Software Licensing migration importer with dry-run mode. Preserves customer keys.
- Vendor admin UI: Dashboard, Products, Releases, Licenses, Activations, API Tokens, Webhooks, Logs, Tools (migration), Settings.
- Vendor REST API at `/wp-json/licensekit/v1/admin/*` — Bearer-token auth with capability scoping (`products.read`, `releases.write`, etc.).
- Webhooks for license + release lifecycle events. Async delivery via Action Scheduler with exponential-backoff retries.
- Encrypted license-key storage (sodium secretbox) for customer-dashboard reveal.
- Customer self-rotate (3 rotations / day / license, preserves remaining validity).
- HTTPS enforcement on public REST endpoints.
- Per-IP and per-key sliding-window rate limits with hard lockout on repeated failures.
- GDPR exporters and erasers for license + activation data.
- Daily cron pruning of old log entries (default 90 days).
- Filterable extension points: `licensekit_tier_options`, `licensekit_expiry_options`, `licensekit_grace_period_days`, `licensekit_log_retention_days`.
- Comprehensive HTML documentation at `docs/index.html`.
- 151 unit tests, 358 assertions. PHPCS with WordPress + PluginCheck rulesets — zero errors, zero warnings.

[Unreleased]: https://github.com/kamalahmed/licensekit/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/kamalahmed/licensekit/releases/tag/v0.1.0
