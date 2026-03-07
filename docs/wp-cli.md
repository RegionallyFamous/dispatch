# WP-CLI Reference

Dispatch exposes a full WP-CLI interface under the `wp telex` command group. Use it to automate installs, manage connections, and inspect plugin state in deployment pipelines or on headless servers.

## Global Options

All `wp telex` commands accept the standard WP-CLI global flags (`--path`, `--url`, `--allow-root`, `--quiet`, `--format`, etc.).

---

## `wp telex list`

List all Telex projects available to your account, along with their local install status.

```bash
wp telex list [--format=<format>]
```

**Options**

| Option | Default | Description |
|---|---|---|
| `--format` | `table` | Output format: `table`, `json`, `csv`, `yaml`, `ids`, `count` |

**Example**

```bash
wp telex list --format=json
```

---

## `wp telex install`

Install a Telex project by its public ID.

```bash
wp telex install <id> [--activate]
```

**Arguments**

| Argument | Description |
|---|---|
| `<id>` | The Telex project public ID (from `wp telex list`) |

**Options**

| Option | Default | Description |
|---|---|---|
| `--activate` | false | Activate the block plugin immediately after install |

**Example**

```bash
wp telex install abc123 --activate
```

---

## `wp telex update`

Update an installed Telex project to the latest available build.

```bash
wp telex update <id>
```

**Example**

```bash
wp telex update abc123
```

---

## `wp telex remove`

Remove an installed Telex project.

```bash
wp telex remove <id>
```

**Example**

```bash
wp telex remove abc123
```

---

## `wp telex connect`

Start the OAuth 2.0 Device Authorization flow from the terminal. Prints the authorization URL and code, then polls until the authorization is completed or the code expires.

```bash
wp telex connect
```

**Example output**

```
Open https://telex.automattic.ai/activate in a browser.
Enter code: ABCD-1234

Waiting for authorization... (press Ctrl+C to cancel)
Success: Connected to Telex.
```

---

## `wp telex disconnect`

Remove the stored OAuth token and disconnect this WordPress site from Telex. Already-installed projects are not affected.

```bash
wp telex disconnect
```

---

## `wp telex circuit`

Inspect and manage the API circuit breaker.

```bash
wp telex circuit status
wp telex circuit reset
```

**Subcommands**

| Subcommand | Description |
|---|---|
| `status` | Show the current circuit breaker state (`closed`, `open`, or `half-open`) and failure count |
| `reset` | Manually reset the circuit breaker to the `closed` state |

**Example**

```bash
wp telex circuit status
# State: open (5 failures in the last 120s)

wp telex circuit reset
# Success: Circuit breaker reset.
```

---

## `wp telex cache`

Inspect and manage the Telex project cache.

```bash
wp telex cache status
wp telex cache clear
wp telex cache warm
```

**Subcommands**

| Subcommand | Description |
|---|---|
| `status` | Show cache age and whether a stale copy is available |
| `clear` | Delete the cached project list |
| `warm` | Fetch and cache the project list immediately |

---

## Using in CI/CD

A typical deployment step that installs or updates a specific project:

```bash
# Install WordPress and activate plugin (adjust paths as needed)
wp plugin activate dispatch-for-telex

# Connect using a pre-authorized token via environment variable
# (set TELEX_TOKEN in your CI secrets, then use wp-cli config)
wp telex install abc123 --activate --allow-root --path=/var/www/html
```

> For fully automated pipelines, manage the OAuth token directly via `wp option update telex_token <encrypted-value>` using a token pre-generated in your Telex account dashboard.
