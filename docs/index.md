# Dispatch for Telex

**Your Telex projects, one click from WordPress.**

Dispatch is the official WordPress plugin for the [Telex](https://telex.automattic.ai) platform. It gives your team a native install experience for custom blocks and themes — no FTP, no terminal, no deployment tickets.

## What Dispatch Does

- Connects your WordPress site to Telex via secure OAuth 2.0 Device Authorization
- Lets you browse, install, update, and remove Telex blocks and themes from the WordPress admin
- Surfaces Telex updates in the native WordPress Updates screen
- Provides a full WP-CLI interface for automation and CI/CD pipelines
- Logs every install, update, and connection event for security auditing

## Documentation

| Page | Description |
|---|---|
| [Getting Started](getting-started.md) | Requirements, installation, and first connection |
| [Managing Projects](managing-projects.md) | Install, update, and remove blocks and themes |
| [WP-CLI Reference](wp-cli.md) | Full command reference for automation |
| [Site Health](site-health.md) | Connection diagnostics and circuit breaker status |
| [Architecture](architecture.md) | How Dispatch works under the hood |
| [Contributing](contributing.md) | Dev setup, coding standards, running tests |

## Requirements

- WordPress 6.7+
- PHP 8.2+
- A Telex account at [telex.automattic.ai](https://telex.automattic.ai)

## License

GPL-2.0-or-later. See [LICENSE](../LICENSE) for details.
