# Site Health

Dispatch integrates with the WordPress Site Health screen (**Tools → Site Health**) to surface connection status, API reachability, and circuit breaker state — all in a place your team already knows to look.

## Health Tests

Dispatch adds the following tests to the **Status** tab of Site Health. Each test can pass, produce a warning, or flag a critical issue.

### Telex Connection

| Result | Meaning |
|---|---|
| **Good** | Connected to Telex. OAuth token is present and valid. |
| **Recommended** | Not connected. Visit the Dispatch screen to connect. |

### Telex API Reachability

| Result | Meaning |
|---|---|
| **Good** | The Telex API responded successfully within the timeout. |
| **Critical** | The API is unreachable. Check your server's outbound HTTPS connectivity. |

### Circuit Breaker State

| Result | Meaning |
|---|---|
| **Good** | Circuit is closed. API calls are flowing normally. |
| **Recommended** | Circuit is half-open. The API recovered recently and is being tested. |
| **Critical** | Circuit is open. Too many API failures were detected. Dispatch has paused requests to protect your site. |

## Debug Information

The **Info** tab of Site Health includes a **Dispatch for Telex** section with:

- Plugin version
- Connection status (`connected` / `disconnected`)
- Circuit breaker state (`closed` / `open` / `half-open`) and failure count
- Cache status (age of cached project list, stale copy availability)
- WordPress and PHP versions (for support requests)

This information is included automatically when you use the **Copy site info to clipboard** button, which is useful when filing a support request.

## Acting on Failures

### Circuit breaker is open

This means Dispatch detected 5 or more API failures within 120 seconds and has paused outbound requests to protect your site's performance. The circuit resets automatically after 60 seconds.

To reset it manually:

```bash
wp telex circuit reset
```

Or wait for the automatic reset — Dispatch will send a single test request after the timeout, and if it succeeds, normal operation resumes.

### API is unreachable

Check:

1. Your server can make outbound HTTPS requests (port 443).
2. `telex.automattic.ai` is not blocked by a firewall or security plugin.
3. The [Telex status page](https://telex.automattic.ai) for any ongoing incidents.

### Not connected

Visit **Dispatch** in the WordPress admin and click **Connect to Telex**. See [Getting Started](getting-started.md) for the full connection walkthrough.
