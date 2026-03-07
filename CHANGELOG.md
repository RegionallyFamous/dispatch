# Changelog

All notable changes to Dispatch for Telex will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2026-03-06

### Added

- **Projects page** — searchable grid of all Telex blocks and themes with
  one-click install, update, and remove actions.
- **OAuth 2.0 Device Authorization Grant** (RFC 8628) — connect to Telex
  without entering credentials in the browser. The device flow UI is rendered
  by a dedicated React component that piggybacks on WP Heartbeat for polling.
- **AES-256-GCM token encryption** — the bearer token is encrypted at rest
  using a per-site key derived from `wp_salt('auth')`.
- **Native WordPress Updates integration** — installed Telex projects surface
  on the standard Updates screen; plugin row notices appear when a newer build
  is available.
- **WP-CLI commands** — `wp telex list|install|update|remove|connect|disconnect|circuit|cache`.
- **Site Health tests** — API reachability and circuit breaker status appear in
  the Site Health Info and Status screens.
- **Audit log** — every install, update, remove, connect, and disconnect event
  is persisted to a custom `{prefix}telex_audit_log` database table.
- **Audit log admin page** — read-only table view of recent security events,
  accessible under the Telex menu.
- **Circuit breaker** — three-state transient-backed circuit breaker (CLOSED /
  OPEN / HALF-OPEN) protects the site from cascading failures when the Telex
  API is unavailable.
- **Stale-while-revalidate cache** — API responses are cached in WordPress
  transients with a 5-minute freshness window and 24-hour stale fallback;
  an hourly WP-Cron job warms the cache proactively.
- **SSRF protection** — the internal PSR-18 HTTP client enforces HTTPS-only
  targets, maximum 3 redirects, and a 10 MB response cap.
- **Rate limiting** — per-user, per-action rate limits on all REST endpoints
  that interact with the Telex API.
- **Multisite support** — menu registration uses `network_admin_menu` on
  multisite installs.
- **Internationalization** — all strings are wrapped with `__()` / `_e()` /
  `esc_html__()`. A `.pot` file is included; JS translation JSON is compiled
  at build time via `wp i18n make-json`.
- **WordPress Playground blueprint** — `blueprint.json` lets anyone run the
  plugin in a browser sandbox in seconds.
- **React admin UI** — the projects page and device-flow screens are React
  applications built with `@wordpress/scripts` and `@wordpress/components`.
- **Security policy** — `SECURITY.md` documents the responsible disclosure
  process and response timeline.

### Security

- Token stored as AES-256-GCM ciphertext; plaintext never written to
  `wp_options`.
- All REST endpoints require authentication; unauthenticated requests receive
  401, unauthorized requests receive 403.
- `upgrader_source_selection` filter validates every package before it is
  moved into the plugins/themes directory (ZipSlip + blocked-extension check).
- SHA-256 checksum verified for every downloaded build file.

[Unreleased]: https://github.com/regionallyfamous/dispatch/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/regionallyfamous/dispatch/releases/tag/v1.0.0
