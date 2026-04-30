# Contributing to LicenseKit

Thanks for considering a contribution! Here's how to get set up and what to expect.

## Development setup

```bash
git clone https://github.com/kamalahmed/licensekit.git
cd licensekit
composer install
```

You'll need a local WordPress install to test changes against. [Local](https://localwp.com/), [DDEV](https://ddev.com/), or wp-env all work. Activate this plugin from `wp-content/plugins/licensekit/` and you're set.

## Running tests

```bash
composer test               # PHPUnit — 151 tests, 358 assertions, runs in ~50ms
composer lint               # PHPCS with WordPress + PluginCheck rulesets
composer lint:fix           # phpcbf for auto-fixable issues
```

The test suite has zero database dependency — it uses in-memory shims defined in `tests/bootstrap.php`. Add your tests under `tests/Unit/` mirroring the `src/` layout.

## Code style

- **Tabs for indentation.** Yoast/WordPress convention.
- **PHP 7.4+ syntax** — typed properties, nullable types, arrow functions are fine. Avoid 8.0-only features (named args, enums, readonly) so the plugin runs on the broadest WP host base.
- **No comments restating what code does.** Only WHY (constraints, surprising invariants, workarounds). The HMAC-vs-Ed25519 backstory in `Signer.php` is an example of a useful comment.
- **One responsibility per class.** If a service does five things, split it.
- **Repositories for SQL, Services for business logic, Controllers for HTTP, Models for data shape.** Don't cross the streams.

## What we welcome

- Bug fixes with a regression test.
- Documentation improvements (especially the HTML docs in `docs/index.html`).
- New e-commerce backends (Stripe direct, SureCart, etc.) following the EDD/WC bridge pattern.
- Migration importers from other licensing plugins.
- Translation files (`languages/`).
- Performance improvements with measurements (e.g. "queries dropped from N to 1").

## What needs prior discussion

Open an issue first if you're planning:

- Schema changes (every column lives forever in customer DBs — design it once).
- Breaking changes to the Client SDK contract (existing customer plugins must keep working).
- Changes to the wire format / REST API (older SDKs in the wild won't be updated).
- New top-level dependencies (Composer or otherwise) — we prefer to keep the plugin lean.

## PR checklist

- [ ] `composer test` passes
- [ ] `composer lint` reports zero errors and zero warnings
- [ ] Plugin Check (PCP) ruleset still clean if you touched anything outside `tests/`
- [ ] No new files in `vendor/` committed (we run `composer install` in CI)
- [ ] No `_debug.php` or scratch files
- [ ] No screenshots / binaries unless they're tracked assets

## Security

If you find a security vulnerability, please email the maintainer directly rather than opening a public issue. See [SECURITY.md](SECURITY.md) for the disclosure process.

## License

By contributing, you agree your contribution is licensed under [GPL-2.0-or-later](LICENSE).
