=== Dispatch for Telex ===
Contributors: regionallyfamous
Tags: blocks, themes, installer, telex, ai
Requires at least: 6.7
Tested up to: 6.8
Stable tag: 1.0.3
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Telex builds the block. Dispatch ships it. One click to install, update, or remove any Telex-generated block or theme — no zip files, no upload forms.

== Description ==

**Build a block in Telex. Click Install. It's on your site.**

[Telex](https://telex.automattic.ai) is Automattic AI Labs' natural language block and theme builder — Matt Mullenweg's "V0 or Lovable, but specifically for WordPress." Describe what you want in plain English, click Build, and two minutes later you have a fully functional WordPress block. Thousands of creators have shipped Minesweeper games, pricing tables, EV charging calculators, personality quizzes, and complete Gutenberg themes with it. Without writing a line of code.

Then they hit the zip file.

Every Telex-to-WordPress deploy without Dispatch follows the same script: click Download, find the file, switch to WordPress, navigate to Plugins → Add New → Upload Plugin, choose the file, wait for upload, click Install Now, click Activate. Seven steps. Per block. Per revision. The generation took two minutes. The deploy cycle takes five — and it repeats with every single iteration.

Dispatch eliminates the entire loop. Connect your site to your Telex account once. Your entire project library appears in a clean admin screen. Click **Install** on anything you want on the site. Dispatch fetches the build, validates every file, runs the package through WordPress' native upgrader, and activates the block — in a few seconds, without you touching a zip file.

=== No Passwords. No Zip Files. No Waiting. ===

* **Browse your Telex projects** — every block and theme in your Telex account, searchable, in a clean grid right inside WordPress admin.
* **One-click install** — Dispatch downloads, validates, and activates the latest build. Blocks are live on your site in seconds.
* **Native WordPress updates** — available updates appear inside the WordPress Updates screen alongside your other plugins and themes. Nothing to remember to check.
* **Clean removals** — uninstall any Telex-managed project and Dispatch handles deactivation, file cleanup, and tracker sync. Nothing gets orphaned.
* **Secure auth** — OAuth 2.0 Device Authorization Grant (RFC 8628). No password stored anywhere. Authorize once from any browser, even on a headless server.
* **AES-256-GCM token encryption** — your OAuth credentials are encrypted at rest using a key derived from your site's secret salts.

=== Built for Teams and CI/CD ===

* **WP-CLI** — `wp telex install`, `wp telex update --all`, `wp telex list`. Automate everything. Drop `wp telex update --all` into your deployment script and every environment stays current on every deploy.
* **Multisite** — connect once at the network level; every site on the network gains access to your Telex projects.
* **Audit log** — every install, update, remove, and connection event is recorded with a timestamp and acting user ID.
* **Site Health integration** — connection status, circuit breaker state, and API reachability surface directly in WordPress Site Health.
* **Circuit breaker** — automatic protection against a degraded Telex API, with graceful fallback and self-healing.

=== Requirements ===

* WordPress 6.7 or later
* PHP 8.2 or later
* A Telex account ([telex.automattic.ai](https://telex.automattic.ai))

== Installation ==

1. Download and upload the `dispatch-for-telex` folder to `/wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate **Dispatch for Telex** through the **Plugins** screen in WordPress.
3. Navigate to **Dispatch** in the WordPress admin sidebar.
4. Click **Connect to Telex** and follow the on-screen device authorization flow — open the provided URL on any device, sign in with your Telex account, and enter the code shown on screen.
5. Your entire Telex project library appears immediately. Click **Install** on any block or theme to deploy it.

That's a one-time setup. Everything after that is one click per project.

== Frequently Asked Questions ==

= What is Telex? =

[Telex](https://telex.automattic.ai) is Automattic AI Labs' natural language WordPress block and theme builder. Describe what you want in plain English, click Build, and Telex generates a fully functional block or theme ready to deploy. It's free — sign up at telex.automattic.ai to start building. Dispatch is the plugin that gets your creations onto your WordPress site without the zip file.

= Do I need a Telex account? =

Yes. Build something in Telex first, then install Dispatch and click Connect. That's the whole setup.

= Why not just upload the zip manually? =

You can. But you'll re-upload it by hand every iteration, manage version tracking yourself, and the WordPress Updates screen will have no idea your block exists. The more you build and iterate in Telex, the more the manual cycle compounds. Dispatch handles all of that automatically and integrates with the tools your team already uses.

= Does this plugin work on WordPress Multisite? =

Yes. Connect once at the network level and every site on the network has access to your Telex projects. Individual site admins can install and manage projects within their own site without additional authentication.

= Is my OAuth token stored securely? =

Yes. Dispatch uses AES-256-GCM authenticated encryption to store your OAuth credentials in the WordPress database. The encryption key is derived from your site's secret keys and never leaves your server. The plaintext token is never written to disk or logged.

= Does installing a block modify my theme? =

No. Blocks installed by Dispatch are self-contained and live in `wp-content/plugins/` alongside other block plugins. They don't modify your active theme, `functions.php`, or any other theme file.

= Can I use Dispatch in a CI/CD pipeline? =

Yes. The full WP-CLI surface (`wp telex connect`, `wp telex install`, `wp telex update --all`, etc.) is designed for automated workflows. Drop `wp telex update --all` into your deployment script and every environment stays current on every deploy.

= What happens if the Telex API is unavailable? =

Dispatch includes a circuit breaker that detects API failures and stops hammering a degraded endpoint. Already-installed blocks and themes continue to work normally — they're just files on disk. The circuit resets automatically once the API recovers.

= Can I remove a project installed by Dispatch? =

Yes. Click **Remove** on any installed project in the Dispatch screen, or run `wp telex remove <project-id>` from the command line. Dispatch deactivates the item, removes the files, and updates its tracker.

= Where can I report bugs or request features? =

Open an issue at [github.com/regionallyfamous/dispatch](https://github.com/regionallyfamous/dispatch/issues).

== Screenshots ==

1. The Dispatch projects screen — browse, install, update, and remove your Telex blocks and themes from a single admin page. The stats bar shows total projects, how many are installed, and whether any updates are waiting.

== Changelog ==

= 1.0.3 =
* Fixed a crash that made the projects screen go blank on sites where the project cache had expired.
* The auto-deploy webhook now validates request timestamps, rejects replayed requests, and rate-limits by IP.
* Every downloaded file is now verified with a SHA-256 checksum before it touches your site.
* Project data is cached more intelligently — fewer API calls, noticeably faster page loads on large accounts.
* The audit log is now sortable by date.
* Error messages no longer leak internal implementation details.
* Multisite uninstall now cleans up all subsites, not just the first 100.

= 1.0.2 =
* Fixed update progress bar snapping back to step 1 mid-animation.
* Fixed version tracker recording the old version number after an update.
* Fixed a race condition that showed "build isn't ready" immediately after confirming it was ready.
* Status badge now sits inline with the project title instead of on a separate line.

= 1.0.1 =
* UI now matches WP admin's color scheme and design tokens throughout — no more separate dark mode.
* Cards redesigned with accent stripe, larger avatars, and full-width action buttons.
* Fixed "Connected" badge positioning — now sits inline with the page heading.
* Fixed badge contrast to meet WCAG AA on all states.
* Removed bulk-selection UI; projects activate automatically on install.

= 1.0.0 =
* Initial public release.
* AES-256-GCM token encryption for OAuth credentials at rest.
* REST API (`telex/v1`) replacing legacy AJAX handlers.
* React-based admin UI built with `@wordpress/components`.
* WP-CLI commands: `list`, `install`, `update`, `remove`, `connect`, `disconnect`, `circuit`, `cache`.
* Site Health integration with connection and circuit breaker diagnostics.
* Security audit log with custom database table.
* WordPress Multisite support.
* Circuit breaker pattern for Telex API resilience.
* PHP 8.2+ throughout: backed enums, readonly classes, match expressions.

== Upgrade Notice ==

= 1.0.2 =
Bug-fix update. Resolves update progress animation restart, incorrect post-update version tracking, and a race condition that could show a spurious "build isn't ready" error. No database changes required.

= 1.0.1 =
UI polish update. No database changes or upgrade steps required.

= 1.0.0 =
First public release. No upgrade steps required.
