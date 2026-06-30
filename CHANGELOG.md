# Changelog

All notable changes to `ahegyes/wp-framework-woocommerce` are documented in this file. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Pending entries live in [`changelog/`](./changelog) — add via `composer changelog:add:woocommerce` from the monorepo root. Aggregate into a release with `composer changelog:write:woocommerce`.

## 2.0.0 - unreleased

### Added

- **Complete rewrite of v1.** Lean library architecture: interfaces + final classes, PSR-11/PSR-3. PHP 8.5+, WordPress 7.0+, WooCommerce 10.9+ (HPOS-ready).
- **WooCommerce settings backend** — implements the framework's settings backend contract against WooCommerce's native settings API, binding one settings page per descriptor with field-level capability enforcement.
- **Product-data settings tab** — declares product-data fields over WooCommerce's native product panels, injecting a field's default for products that predate it.
- **Order-data fields** — HPOS-aware order meta-box field storage.
- **WooCommerce conditionals** — plugin-version and database-version gates.
- **WooCommerce logger** — a PSR-3 adapter over WooCommerce's logging.
