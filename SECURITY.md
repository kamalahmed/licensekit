# Security policy

## Reporting a vulnerability

If you discover a security vulnerability in LicenseKit, please **do not** open a public GitHub issue. Instead, email the maintainer directly:

**contact@techydevs.com**

Please include:

- A clear description of the issue
- Steps to reproduce
- The version of LicenseKit you tested against
- Your assessment of impact (information disclosure, license forgery, RCE, etc.)

You should expect an acknowledgement within 72 hours and a status update within 7 days. Coordinated disclosure timelines will be discussed once the issue is confirmed.

## Supported versions

| Version | Supported          |
|---------|--------------------|
| 0.x     | :white_check_mark: |

Once 1.0.0 ships, we'll provide security updates for the latest minor and one previous minor version.

## What's in scope

- License forgery, signature bypass, or activation-limit evasion
- Authentication / authorization bypass on the vendor REST API
- Path traversal, SSRF, SQL injection, XSS (stored, reflected, or DOM)
- Insecure deserialization or RCE via plugin endpoints
- Information disclosure of license keys or secrets

## What's out of scope

- Issues requiring physical access to the operator's server
- Self-XSS that requires the operator to paste hostile script into their own admin
- Social engineering of the operator
- Theoretical attacks against properly-deployed Ed25519 / SHA-256 / sodium primitives
- Vulnerabilities in third-party plugins (EDD, WooCommerce, etc.) that we don't control — please report those upstream

## Security model recap

For a deep dive into how LicenseKit handles license keys, signing, and download protection, see the [Security model](docs/index.html#security) section of the docs.
