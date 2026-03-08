<div align="center">

# Dispatch for Telex

**Build a block in Telex. Click Install. It's live on your site.**

[![CI](https://github.com/RegionallyFamous/dispatch/actions/workflows/ci.yml/badge.svg)](https://github.com/RegionallyFamous/dispatch/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.3.2-0073aa?labelColor=1e293b)](https://github.com/RegionallyFamous/dispatch/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-0073aa?logo=wordpress&logoColor=white&labelColor=1e293b)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-7c3aed?logo=php&logoColor=white&labelColor=1e293b)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-22c55e?labelColor=1e293b)](https://www.gnu.org/licenses/gpl-2.0.html)

<br/>

[**▶ Try it in your browser — no install needed**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/RegionallyFamous/dispatch/main/blueprint.json)

<sub>Opens a live WordPress Playground with Dispatch pre-installed. Takes about 10 seconds.</sub>

<br/>

![](.github/assets/screenshot-1.png)

</div>

---

## The problem with zip files

[Telex](https://telex.automattic.ai) is Automattic AI Labs' natural language block and theme builder. Describe what you want in plain English, click Build, and two minutes later you have a fully functional WordPress block — a Minesweeper game, a pricing table, a personality quiz, a full Gutenberg theme. Without writing a single line of code.

Then you hit the zip file.

Every Telex-to-WordPress deploy without Dispatch follows the exact same script:

1. Click **Download** in Telex
2. Find the file in your Downloads folder
3. Switch to your WordPress dashboard
4. Navigate to Plugins → Add New → Upload Plugin
5. Choose the file
6. Wait for the upload
7. Click **Install Now**, then **Activate**

Seven steps. Per block. Per revision. The generation took two minutes. The deploy cycle takes five — and it repeats with every single iteration.

**Dispatch eliminates the entire loop.** Connect your site to Telex once. Your full project library appears inside WordPress admin. Click **Install**. Done.

---

## What it does

| | |
|---|---|
| **Browse your library** | Every block and theme you've built in Telex lives in a searchable grid inside WordPress admin — filterable by type, grouped by collection, sorted by what needs attention. |
| **One-click install** | Dispatch fetches the latest build, verifies every file with a SHA-256 checksum, runs it through WordPress' native upgrader, and activates it. The whole thing takes a few seconds. |
| **Native updates** | Updates appear on the WordPress **Updates** screen right next to your plugins and themes — because that's where your team already looks. No separate dashboard to remember. |
| **Clean removals** | Dispatch deactivates before deleting, handles filesystem cleanup, and keeps its tracker in sync. Nothing gets orphaned. |
| **Build snapshots** | Capture the exact build version of every installed project, then restore the whole set in one command if something breaks. Your escape hatch for risky deploys. |
| **Version pinning** | Lock any project at its current build. Pinned projects are excluded from updates — including `wp telex update --all` — until you're ready to move forward. |
| **Auto-update** | Set projects to update automatically whenever a new build lands. Per-project control so you can auto-update utilities while keeping your flagship blocks pinned. |
| **Notification channels** | Email digests and Slack webhooks for install, update, and removal events. Know what changed, when it changed, and who triggered it. |
| **Project health** | A dedicated tab showing active state, file integrity, version freshness, and compatibility warnings for every installed project. |
| **Block analytics** | See how many posts each installed block is embedded in. Know which ones are load-bearing before you touch them. |
| **Auto-deploy webhook** | Push a new build in Telex → your site updates automatically. Set it and forget it. |
| **WP-CLI** | `wp telex update --all`, `wp telex snapshot create`, `wp telex pin`. Automate everything. Drop Dispatch commands into your deployment pipeline. |
| **Multisite** | Connect once at the network level. Every site on the network gets access to your full project library. |
| **Audit log** | Every install, update, removal, and connection event is recorded with a timestamp and acting user. GDPR-ready — registered with WordPress Privacy Tools. |
| **Circuit breaker** | If the Telex API goes down, Dispatch backs off gracefully. Your installed blocks keep running — they're just files on disk. |

---

## Try it now

The fastest way to see Dispatch in action is WordPress Playground — a full WordPress environment that runs entirely in your browser, no account or install needed.

### [→ Open in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/RegionallyFamous/dispatch/main/blueprint.json)

The Playground opens pre-logged-in with Dispatch already installed and activated, dropped directly on the Dispatch projects screen. From there you can connect a Telex account and try the full install flow — or just poke around the UI.

> The Playground uses a shared `github-proxy.com` URL to pull the latest release zip. If the proxy is slow, give it 20–30 seconds on first load.

---

## Quick start

**Requirements:** WordPress 6.7+, PHP 8.2+, a [Telex account](https://telex.automattic.ai).

### Option A — Download from GitHub Releases

1. Grab `dispatch-for-telex-x.x.x.zip` from the [latest release](https://github.com/RegionallyFamous/dispatch/releases/latest).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and activate.

### Option B — WP-CLI

```bash
wp plugin install https://github.com/RegionallyFamous/dispatch/releases/latest/download/dispatch-for-telex.zip --activate
```

### Then: connect to Telex

1. Open **Dispatch** in the WordPress admin sidebar.
2. Click **Connect to Telex**.
3. Open the URL shown on any device, sign into Telex, and enter the code.
4. Your projects appear. Click **Install** on anything you want.

One-time setup. Everything after that is one click per project.

---

## WP-CLI

Every action in the UI has a CLI equivalent.

```bash
wp telex list                   # See all projects and their install status
wp telex install <id>           # Install a specific project
wp telex update <id>            # Update a specific project
wp telex update --all           # Update everything at once
wp telex remove <id>            # Remove a project
wp telex snapshot create        # Capture current install state
wp telex circuit status         # Inspect circuit breaker state
wp telex cache warm             # Pre-warm the project cache
```

Drop `wp telex update --all` into your CI/CD pipeline and every environment stays current on every deploy. See the [WP-CLI Reference](https://github.com/RegionallyFamous/dispatch/wiki/WP-CLI-Reference) wiki page for the full command surface.

---

## Security

There are no passwords anywhere in Dispatch.

**Authentication** uses the [OAuth 2.0 Device Authorization Grant](https://www.rfc-editor.org/rfc/rfc8628) (RFC 8628) — the same flow used by the GitHub CLI and the AWS CLI. You get a short code and a URL. Open the URL, sign in to Telex, enter the code. Done.

**Token storage** uses AES-256-GCM authenticated encryption. The key is derived from your site's own secret salts and never leaves your server.

**Downloads** are verified with a SHA-256 checksum before anything is unpacked. If a single byte has been tampered with in transit, the install is aborted.

**The webhook** validates HMAC-SHA256 signatures, rejects replayed requests (5-minute window), and rate-limits by IP.

See the [Security Model](https://github.com/RegionallyFamous/dispatch/wiki/Security-Model) wiki page for the full details.

---

## Documentation

All documentation lives in the [GitHub Wiki](https://github.com/RegionallyFamous/dispatch/wiki).

| | |
|---|---|
| [Getting Started](https://github.com/RegionallyFamous/dispatch/wiki/Getting-Started) | Requirements, installation, and first connection |
| [Managing Projects](https://github.com/RegionallyFamous/dispatch/wiki/Managing-Projects) | Install, update, remove, snapshots, pinning, auto-update |
| [WP-CLI Reference](https://github.com/RegionallyFamous/dispatch/wiki/WP-CLI-Reference) | Full command reference with examples |
| [Site Health & Diagnostics](https://github.com/RegionallyFamous/dispatch/wiki/Site-Health-and-Diagnostics) | Diagnostics, circuit breaker, and debug info |
| [Webhook & Auto-Deploy](https://github.com/RegionallyFamous/dispatch/wiki/Webhook-and-Auto-Deploy) | Auto-deploy on new Telex builds |
| [Multisite Setup](https://github.com/RegionallyFamous/dispatch/wiki/Multisite-Setup) | Network activation and per-site management |
| [Notification Channels](https://github.com/RegionallyFamous/dispatch/wiki/Notification-Channels) | Email and Slack notifications |
| [Architecture](https://github.com/RegionallyFamous/dispatch/wiki/Architecture) | Layered design, REST API, data flow |
| [Security Model](https://github.com/RegionallyFamous/dispatch/wiki/Security-Model) | Auth, encryption, webhook validation |
| [Troubleshooting](https://github.com/RegionallyFamous/dispatch/wiki/Troubleshooting) | Common issues and fixes |
| [Contributing](https://github.com/RegionallyFamous/dispatch/wiki/Contributing) | Dev setup, coding standards, running tests |

---

## Contributing

Bug reports and PRs are welcome. Please read [SECURITY.md](SECURITY.md) before reporting a security issue — don't open a public issue for vulnerabilities.

For everything else, [open an issue](https://github.com/RegionallyFamous/dispatch/issues) or see the [Contributing guide](https://github.com/RegionallyFamous/dispatch/wiki/Contributing).

---

<div align="center">

Built by [Regionally Famous](https://regionallyfamous.com) &nbsp;·&nbsp; [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) &nbsp;·&nbsp; [changelog](CHANGELOG.md)

</div>
