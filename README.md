<div align="center">

# Dispatch for Telex

**Build a block in Telex. Click Install. It's live on your site.**

[![CI](https://github.com/RegionallyFamous/dispatch/actions/workflows/ci.yml/badge.svg)](https://github.com/RegionallyFamous/dispatch/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.1.1-0073aa?labelColor=1e293b)](https://github.com/RegionallyFamous/dispatch/releases)
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
3. Switch over to your WordPress dashboard
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
| **Browse your library** | Every block and theme you've built in Telex lives in a searchable grid inside WordPress admin — filterable by type, sorted by what needs attention. |
| **One-click install** | Dispatch fetches the latest build, verifies every file with a SHA-256 checksum, runs it through WordPress' native upgrader, and activates it. The whole thing takes a few seconds. |
| **Native updates** | Updates appear on the WordPress **Updates** screen right next to your plugins and themes — because that's where your team already looks. No separate dashboard to remember. |
| **Clean removals** | Dispatch deactivates before deleting, handles filesystem cleanup, and keeps its tracker in sync. Nothing gets orphaned. |
| **Auto-deploy webhook** | Push a new build in Telex → your site updates automatically. Set it and forget it. |
| **WP-CLI** | `wp telex update --all` in your deployment script. Every environment stays current on every deploy. |
| **Multisite** | Connect once at the network level. Every site on the network gets access to your full project library. |
| **Audit log** | Every install, update, removal, and connection event is recorded with a timestamp and acting user. You'll always know what changed and who did it. |
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

### Option B — Composer / Composer-managed deploys

```bash
composer require regionallyfamous/dispatch-for-telex
```

### Option C — WP-CLI

```bash
wp plugin install https://github.com/RegionallyFamous/dispatch/releases/latest/download/dispatch-for-telex.zip --activate
```

### Then: connect to Telex

1. Open **Dispatch** in the WordPress admin sidebar.
2. Click **Connect to Telex**.
3. Open the URL shown on any device, sign into Telex, enter the code.
4. Your projects appear. Click **Install** on anything you want.

That's a one-time setup. Everything after that is one click per project.

---

## WP-CLI

Every action in the UI has a CLI equivalent.

```bash
# Connect a new environment
wp telex connect

# See what's installed and what needs an update
wp telex list

# Install a specific project
wp telex install <project-id>

# Update a specific project
wp telex update <project-id>

# Update everything at once — great for deployment scripts
wp telex update --all

# Remove a project
wp telex remove <project-id>

# Inspect circuit breaker state
wp telex circuit status

# Manually warm the project cache
wp telex cache warm
```

Drop `wp telex update --all` into your CI/CD pipeline and every environment stays current on every deploy without any manual steps.

---

## Security

There are no passwords anywhere in Dispatch.

**Authentication** uses the [OAuth 2.0 Device Authorization Grant](https://www.rfc-editor.org/rfc/rfc8628) (RFC 8628) — the same flow used by the GitHub CLI, the AWS CLI, and most modern headless tools. You get a short code and a URL. Open the URL, sign in to Telex, enter the code. Done. No password field. No API key to copy-paste. No secrets in environment variables.

**Token storage** uses AES-256-GCM authenticated encryption. The key is derived from your site's own secret salts and never leaves your server. The plaintext token is never written to disk, never logged, and goes nowhere except back to Telex in an `Authorization` header over HTTPS.

**Downloads** are verified with a SHA-256 checksum before anything is unpacked. If a file has been tampered with in transit — even a single byte — the install is aborted and nothing is written to disk.

**The webhook** validates HMAC-SHA256 signatures, rejects replayed requests (5-minute window), and rate-limits by IP.

**File safety** checks every path in a zip for traversal attacks and blocks dangerous extensions before extraction begins.

---

## How it works

```
Telex API ─────────────────────────────────────────────────────────────────────┐
                                                                                 │
  Your project library ──► Dispatch projects screen (React, @wordpress/*)       │
  Build status polling ──► Install/update progress UI                           │
  Webhook endpoint    ──► Auto-deploy on new build                               │
                                                                                 │
WordPress ─────────────────────────────────────────────────────────────────────┤
                                                                                 │
  WP Upgrader API ──► Installs/updates (same mechanism as all plugins/themes)   │
  Site Transient  ──► Updates appear on native WP Updates screen                │
  WP-CLI          ──► wp telex * commands                                        │
  REST API        ──► telex/v1/* endpoints (nonce-authenticated)                 │
  Options API     ──► AES-256-GCM encrypted token storage                       │
  Custom DB table ──► Audit log                                                  │
```

The plugin never monkey-patches WordPress core functions. It hooks into the same extension points WordPress intends for this purpose — `pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_process_complete`, and the REST API. From WordPress' perspective, a Telex block install looks exactly like any other plugin install.

---

## Development

```bash
# Clone and install
git clone https://github.com/RegionallyFamous/dispatch.git
cd dispatch
composer install
npm install

# Build JS/CSS
npm run build

# Watch for changes
npm run start

# Run the full suite
make lint   # PHPCS + ESLint + Stylelint
make test   # PHPUnit + Jest
make build  # Production build
```

### Running tests locally

```bash
# PHP tests (requires a test database)
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test

# JavaScript tests
npm run test:js

# Static analysis
php -d memory_limit=1G vendor/bin/phpstan analyse
```

### Using wp-env

```bash
npm run env:start   # starts a local WP environment at http://localhost:8888
npm run env:stop
```

---

## Project structure

```
dispatch/
├── includes/          PHP classes (REST, installer, auth, cache, audit log…)
├── src/
│   ├── admin/         React admin screen (index.js + store.js)
│   └── device-flow/   OAuth device authorization flow UI
├── lib/telex-sdk/     PSR-18 HTTP client for the Telex API
├── tests/             PHPUnit test suites
├── bin/               Build and setup scripts
├── docs/              Extended documentation (wiki)
├── assets/css/        Compiled CSS
└── .github/           CI/CD workflows, issue templates, PR template
```

---

## Contributing

Bug reports and PRs are welcome. Please read [SECURITY.md](SECURITY.md) before reporting a security issue — don't open a public issue for vulnerabilities.

For everything else, [open an issue](https://github.com/RegionallyFamous/dispatch/issues) describing what you'd like to change. The [PR template](.github/PULL_REQUEST_TEMPLATE.md) will walk you through what we need.

---

## Documentation

Extended docs live in the [`docs/`](docs/) folder and the [GitHub Wiki](https://github.com/RegionallyFamous/dispatch/wiki):

- [Getting Started](docs/getting-started.md)
- [WP-CLI Reference](docs/wp-cli.md)
- [Webhook & Auto-deploy](docs/webhook.md)
- [Security Model](docs/security.md)
- [Multisite Setup](docs/multisite.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Architecture Overview](docs/architecture.md)

---

<div align="center">

Built by [Regionally Famous](https://regionallyfamous.com) &nbsp;·&nbsp; [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) &nbsp;·&nbsp; [changelog](CHANGELOG.md)

</div>
