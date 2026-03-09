<div align="center">

# Dispatch for Telex

**Install and operate Telex projects in WordPress without zip uploads.**

[![CI](https://github.com/RegionallyFamous/dispatch/actions/workflows/ci.yml/badge.svg)](https://github.com/RegionallyFamous/dispatch/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.5.0-0073aa?labelColor=1e293b)](https://github.com/RegionallyFamous/dispatch/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-0073aa?logo=wordpress&logoColor=white&labelColor=1e293b)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-7c3aed?logo=php&logoColor=white&labelColor=1e293b)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-22c55e?labelColor=1e293b)](https://www.gnu.org/licenses/gpl-2.0.html)

[**Try it in WordPress Playground**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/RegionallyFamous/dispatch/main/blueprint.json)

</div>

---

## Why Dispatch exists

[Telex](https://telex.automattic.ai) can generate full WordPress blocks and themes quickly. The friction usually starts at deployment: download zip, upload zip, activate, repeat for each revision.

Dispatch removes that manual loop:

- Connect your site once with OAuth Device Flow (no API key copy/paste).
- Browse your Telex projects in WordPress admin.
- Install, update, and remove projects directly from WordPress.
- Use WP-CLI for automation across staging and production.

## Who this is for

- **Site owners/admins:** reliable install/update workflows and recovery tools.
- **Agencies/teams:** multisite support, update controls, audit visibility.
- **Automation-focused teams:** WP-CLI command surface for deploy pipelines.

## Quick start (site owner path)

**Requirements:** WordPress 6.7+, PHP 8.2+, Telex account.

1. Install Dispatch from GitHub Releases or WP-CLI.
2. Open **Dispatch** in WP Admin and click **Connect to Telex**.
3. Approve the device code at the Telex URL shown on screen.
4. Return to WordPress and install a project.

```bash
wp plugin install https://github.com/RegionallyFamous/dispatch/releases/latest/download/dispatch-for-telex.zip --activate
```

Expected result: your Telex project list loads and install actions are available.

## Verified core capabilities

- One-click install and update from Telex project library.
- Native WordPress update integration for installed Telex projects.
- Project organization with favorites, tags, and groups.
- Build snapshots for restore workflows.
- Version pinning and auto-update controls.
- Audit log, site health checks, and circuit breaker state visibility.
- WP-CLI commands for listing, install/update/remove, cache, snapshots, config, and diagnostics.

## WP-CLI examples

```bash
wp telex list
wp telex install <id> --activate
wp telex update --all
wp telex snapshot create --name="Before deploy"
wp telex snapshot list
wp telex snapshot restore <snapshot-id>
wp telex pin <id> --reason="Awaiting QA sign-off"
wp telex health
```

Full syntax and options: [WP-CLI Reference](https://github.com/RegionallyFamous/dispatch/wiki/WP-CLI-Reference)

## Documentation (canonical: Wiki)

Primary user docs live in the GitHub Wiki:

- [Wiki Home](https://github.com/RegionallyFamous/dispatch/wiki)
- [Getting Started](https://github.com/RegionallyFamous/dispatch/wiki/Getting-Started)
- [Managing Projects](https://github.com/RegionallyFamous/dispatch/wiki/Managing-Projects)
- [WP-CLI Reference](https://github.com/RegionallyFamous/dispatch/wiki/WP-CLI-Reference)
- [Site Health and Diagnostics](https://github.com/RegionallyFamous/dispatch/wiki/Site-Health-and-Diagnostics)
- [Troubleshooting](https://github.com/RegionallyFamous/dispatch/wiki/Troubleshooting)
- [Security Model](https://github.com/RegionallyFamous/dispatch/wiki/Security-Model)
- [Multisite Setup](https://github.com/RegionallyFamous/dispatch/wiki/Multisite-Setup)

Repo-local docs define quality and maintenance standards for contributors:

- [`docs/documentation-quality-standard.md`](docs/documentation-quality-standard.md)
- [`docs/documentation-coverage-matrix.md`](docs/documentation-coverage-matrix.md)
- [`docs/docs-release-checklist.md`](docs/docs-release-checklist.md)

## Contributing

- Read [`CONTRIBUTING.md`](CONTRIBUTING.md) for local setup and quality gates.
- Read [`SECURITY.md`](SECURITY.md) for vulnerability reporting.
- Open issues at [github.com/RegionallyFamous/dispatch/issues](https://github.com/RegionallyFamous/dispatch/issues).

---

<div align="center">
Built by [Regionally Famous](https://regionallyfamous.com) · [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) · [Changelog](CHANGELOG.md)
</div>
