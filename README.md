# Dispatch for Telex

### Ship your Telex AI-generated blocks and themes to WordPress without touching a terminal.

---

[Telex](https://telex.automattic.ai) is a natural language WordPress block and theme builder by Automattic AI Labs. Describe what you want in plain English, and Telex generates a fully functional block or theme you can download and install. Dispatch is what gets it onto your site.

Here's how custom WordPress projects normally ship without it: a designer finishes a block in Telex, files a ticket, a developer pulls the build, creates a ZIP, SSHes into the server, runs the upgrader, and hopes nothing breaks in production. Three days later the site has the new block.

Dispatch collapses that entire process into a button press.

Connect your WordPress site to your Telex account once. After that, every block and theme you've built shows up in a clean admin screen — available to install, update, or remove without ever leaving WordPress. No terminal access required. No deployment scripts. No developer bottleneck for work that should never have needed one.

---

## Install. Update. Done.

The core experience is deliberately simple.

Browse your Telex projects in the WordPress admin. Click **Install**. Dispatch fetches the latest build, validates every file against a blocklist of dangerous extensions, runs the package through WordPress' native upgrader, and activates the block. The whole thing takes a few seconds and uses the exact same upgrade mechanism WordPress uses for everything else — meaning it works with your existing backup tools, staging workflows, and hosting configurations without any special configuration.

Updates don't live in a separate dashboard you have to remember to check. They appear right inside the WordPress **Updates** screen, next to your theme and plugin updates, because that's where your team already looks. Dispatch injects them directly into the update transient — from WordPress' perspective, a Telex update looks exactly like any other update.

Removing a project is just as intentional. Dispatch checks whether a theme is active before deleting it, deactivates plugins before removal, handles the filesystem cleanup, and keeps its internal tracker in sync. Nothing gets orphaned.

---

## Secure by Default

There are no passwords anywhere in Dispatch.

Authentication uses the [OAuth 2.0 Device Authorization Grant](https://www.rfc-editor.org/rfc/rfc8628) (RFC 8628) — the same flow used by tools like the GitHub CLI and the AWS CLI. You get a short code and a URL. Open the URL on any device, sign into Telex, enter the code. That's the entire auth process. No password field. No API key to copy-paste. No secrets to store in environment variables.

The access token Dispatch receives is encrypted at rest using AES-256-GCM. The encryption key is derived from your site's secret salts and never leaves your server. The plaintext token is never written to disk, never logged, never transmitted anywhere except back to Telex in an Authorization header over HTTPS.

---

## Built for Real Deployment Workflows

### WP-CLI

Every action in the UI has a CLI equivalent. Every one.

```bash
# Connect a new environment to Telex
wp telex connect

# Install or update a specific project
wp telex install <project-id>
wp telex update <project-id>

# Update everything at once
wp telex update --all

# List what's installed and whether updates are available
wp telex list

# Remove a project
wp telex remove <project-id>

# Inspect circuit breaker and cache state
wp telex circuit status
wp telex cache warm
```

Put `wp telex update --all` in your deployment script and every environment stays current on every deploy. Spin up a new staging server and run `wp telex install` for each project instead of manually copying files. The CLI was a first-class design consideration, not an afterthought.

### WordPress Multisite

On a multisite network, connecting Telex once at the network level makes your entire project library available to every site on the network. Site administrators can install and manage Telex projects on their own sites without needing separate authentication. Network admins retain full visibility and control.

---

## When Things Go Wrong

### The API is unavailable

Dispatch includes a circuit breaker. After a threshold of consecutive API failures, it stops making requests to Telex entirely — so a degraded upstream doesn't cascade into your WordPress admin grinding to a halt. One probe request is allowed through periodically; when it succeeds, the circuit closes and everything resumes normally.

Your installed blocks and themes are completely unaffected. They're just files on disk.

### You need to know who did what

Every install, update, removal, connect, and disconnect is recorded in a dedicated audit log table with a timestamp and the acting user's ID. When someone asks "who deployed that?" you have an answer.

### Something looks wrong

**Tools → Site Health** shows Telex connection status, circuit breaker state, and API reachability as first-class diagnostic checks. Anyone with access to Site Health — your developer, your host, your security auditor — can see the plugin's operational state without you having to explain anything.

---

## Getting Started

**Requirements:** WordPress 6.7+, PHP 8.2+, and a [Telex account](https://telex.automattic.ai).

1. Install and activate the plugin.
2. Open **Dispatch** in the WordPress admin sidebar.
3. Click **Connect to Telex** — you'll get a URL and a short code.
4. Open the URL on any browser, sign in to Telex, and enter the code.
5. Your projects appear. Click **Install** on anything you want on the site.

That's a one-time setup. Everything after that is one click per project.

---

## Questions

**What is Telex?**
[Telex](https://telex.automattic.ai) is a natural language WordPress block and theme builder by Automattic AI Labs. Describe what you want in plain English and Telex generates a fully functional block or theme ready to deploy. Dispatch is what ships those creations to your WordPress site.

**Do I need a Telex account?**
Yes. Dispatch is the WordPress deployment layer for [Telex](https://telex.automattic.ai) — sign up there first to start building.

**Can I just FTP the files instead?**
You can. But then you're managing version tracking manually, running your own file validation, writing your own CLI tooling, and the WordPress Updates screen has no idea your project exists. Dispatch handles all of that and integrates with the workflows your team already uses.

**Will installing a block modify my theme?**
Never. Telex blocks install as self-contained plugins in `wp-content/plugins/`. They don't touch your theme, your `functions.php`, or anything else you didn't explicitly install.

**Does this work in a CI/CD pipeline?**
Yes — the WP-CLI surface was designed for it. `wp telex update --all` on every deploy is a one-liner.

**What if I want to audit who installed what?**
Every action is in the audit log. Timestamp, action type, project ID, and user ID. It's always there.

---

*Built by [Regionally Famous](https://regionallyfamous.com) · [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)*
