# Getting Started

## Who this page is for

Site owners who want to install Dispatch and deploy Telex projects without zip uploads.

## Prerequisites

- WordPress 6.7+
- PHP 8.2+
- Telex account
- Permission to install plugins

## Install Dispatch

### WP-CLI

```bash
wp plugin install https://github.com/RegionallyFamous/dispatch/releases/latest/download/dispatch-for-telex.zip --activate
```

### Admin UI

1. Download latest release zip.
2. Go to **Plugins -> Add New Plugin -> Upload Plugin**.
3. Upload and activate **Dispatch for Telex**.

## Connect to Telex

1. Open **Dispatch** in WordPress admin.
2. Click **Connect to Telex**.
3. Open the provided URL and enter the displayed code.
4. Return to WordPress and wait for confirmation.

## Expected result

The project list loads and install actions are available.

## If it fails

- If not connected, run `wp telex connect`.
- If health checks fail, run `wp telex health` and follow the output.
- For API/backoff issues, see troubleshooting and circuit breaker guidance.

## Related pages

- [Managing Projects](https://github.com/RegionallyFamous/dispatch/wiki/Managing-Projects)
- [WP-CLI Reference](https://github.com/RegionallyFamous/dispatch/wiki/WP-CLI-Reference)
- [Troubleshooting](https://github.com/RegionallyFamous/dispatch/wiki/Troubleshooting)
