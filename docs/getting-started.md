# Getting Started

This guide walks you through installing Dispatch for Telex and connecting it to your Telex account.

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 6.7 |
| PHP | 8.2 |
| MySQL / MariaDB | 8.0 / 10.5 |
| Telex account | — |

> **Multisite:** Dispatch is multisite-compatible. Install and activate it network-wide, then connect at the network level. All sites on the network share the connection.

## Installation

### From the WordPress Plugin Directory

1. In your WordPress admin, go to **Plugins → Add New Plugin**.
2. Search for **Dispatch for Telex**.
3. Click **Install Now**, then **Activate**.

### Manual Installation

1. Download the latest release zip from [GitHub Releases](https://github.com/regionallyfamous/dispatch/releases).
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the zip file and click **Install Now**, then **Activate**.

### Via WP-CLI

```bash
wp plugin install dispatch-for-telex --activate
```

## Connecting to Telex

Dispatch uses the [OAuth 2.0 Device Authorization Grant (RFC 8628)](https://www.rfc-editor.org/rfc/rfc8628) — no passwords are ever stored.

1. In your WordPress admin sidebar, click **Dispatch**.
2. Click **Connect to Telex**.
3. Dispatch will display a short alphanumeric code and a URL (e.g. `telex.automattic.ai/activate`).
4. Open that URL on any device — your phone, another browser tab, anywhere.
5. Enter the code and authorize the connection.
6. Return to your WordPress admin. Dispatch detects the authorization automatically and loads your projects.

> **Headless / CLI:** Use `wp telex connect` to start the device flow from the terminal. The command prints the URL and code, polls for completion, and confirms when the connection succeeds. See the [WP-CLI reference](wp-cli.md).

## After Connecting

Your Telex projects appear in a searchable grid. From here you can:

- **Install** any block or theme with one click
- **Update** projects when new builds are available
- **Remove** projects you no longer need

Continue to [Managing Projects](managing-projects.md) for details.
