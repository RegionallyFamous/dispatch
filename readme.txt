=== Dispatch for Telex ===
Contributors: regionallyfamous
Tags: blocks, themes, installer, telex, ai
Requires at least: 6.7
Tested up to: 6.8
Stable tag: 1.0.1
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ship your Telex AI-generated blocks and themes to WordPress without FTP, terminals, or waiting on a developer.

== Description ==

[Telex](https://telex.automattic.ai) is a natural language WordPress block and theme builder by Automattic AI Labs. Describe what you want in plain English, and Telex generates a fully functional block or theme you can download and install. **Dispatch for Telex** is the bridge that ships those creations to your live WordPress site — directly from the admin, in seconds.

No FTP. No terminal. No deployment tickets.

=== Why Dispatch? ===

Blocks and themes built with Telex deserve a first-class home in WordPress. Dispatch makes that happen with a native install experience that feels like it was always part of WordPress — because it was built to be.

=== What You Can Do ===

* **Browse your Telex projects** — see every block and theme available to your account in a clean, searchable grid.
* **Install with one click** — Dispatch downloads, validates, and installs the latest build. Blocks are activated automatically.
* **Stay current effortlessly** — Dispatch surfaces available updates right inside the native WordPress Updates screen so nothing falls through the cracks.
* **Remove cleanly** — uninstall any Telex-managed project and Dispatch handles the cleanup.
* **Connect securely** — OAuth 2.0 Device Authorization Grant (RFC 8628) means no passwords are ever stored. Authorize once from any browser, even on a headless server.

=== Built for Teams ===

* **WP-CLI support** — automate installs, updates, and connection management in your deployment pipeline with `wp telex`.
* **Multisite ready** — connect once at the network level; every site on the network gains access.
* **Audit log** — every install, update, remove, and connection event is recorded with a timestamp and acting user.
* **Site Health integration** — connection status, circuit breaker state, and cache health surface directly in WordPress Site Health.
* **Circuit breaker** — automatic protection against a degraded Telex API, with graceful fallback and self-healing.
* **AES-256-GCM token encryption** — your OAuth credentials are encrypted at rest. Always.

=== Requirements ===

* WordPress 6.7 or later
* PHP 8.2 or later
* A Telex account ([telex.automattic.ai](https://telex.automattic.ai))

== Installation ==

1. Download and upload the `dispatch-for-telex` folder to `/wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate **Dispatch for Telex** through the **Plugins** screen in WordPress.
3. Navigate to **Dispatch** in the WordPress admin sidebar.
4. Click **Connect to Telex** and follow the on-screen device authorization flow — open the provided URL on any device, enter the code, and you're connected.
5. Your Telex projects will appear immediately. Click **Install** on any block or theme to deploy it.

== Frequently Asked Questions ==

= What is Telex? =

[Telex](https://telex.automattic.ai) is a natural language WordPress block and theme builder by Automattic AI Labs. Describe what you want in plain English and Telex generates a fully functional block or theme you can download and deploy. Dispatch is the plugin that installs and manages those projects on your WordPress site.

= Do I need a Telex account? =

Yes. Dispatch is the WordPress delivery layer for [Telex](https://telex.automattic.ai). Sign up at telex.automattic.ai to start building, then use Dispatch to deploy your projects to any WordPress site.

= Does this plugin work on WordPress Multisite? =

Yes. Connect once at the network level and every site on the network has access to your Telex projects. Individual site admins can install and manage projects within their site without additional authentication.

= Is my OAuth token stored securely? =

Yes. Dispatch uses AES-256-GCM authenticated encryption to store your OAuth credentials in the WordPress database. The encryption key is derived from your site's secret keys. The plaintext token is never written to disk or logged.

= Does installing a block modify my theme? =

No. Blocks installed by Dispatch are self-contained and live in `wp-content/plugins/` alongside other block plugins. They don't modify your active theme, `functions.php`, or any other theme file.

= Can I use Dispatch in a CI/CD pipeline? =

Yes. The full WP-CLI surface (`wp telex connect`, `wp telex install`, `wp telex update`, etc.) is designed for automated workflows. See the [WP-CLI documentation](https://github.com/regionallyfamous/dispatch/blob/main/docs/wp-cli.md) for details.

= What happens if the Telex API is unavailable? =

Dispatch includes a circuit breaker that detects API failures and stops hammering a degraded endpoint. Already-installed blocks and themes continue to work normally. The circuit resets automatically once the API recovers.

= Can I remove a project installed by Dispatch? =

Yes. Click **Remove** on any installed project in the Dispatch screen, or run `wp telex remove <project-id>` from the command line.

= Where can I report bugs or request features? =

Open an issue at [github.com/regionallyfamous/dispatch](https://github.com/regionallyfamous/dispatch/issues).

== Screenshots ==

1. The Dispatch projects screen — browse, install, update, and remove your Telex blocks and themes from a single admin page. The stats bar shows total projects, how many are installed, and whether any updates are waiting.

== Changelog ==

= 1.0.1 =
* Removed OS dark-mode overrides — UI now matches WP admin's light-mode design consistently.
* Brand accent color now inherits WP admin's active color scheme (`--wp-admin-theme-color`).
* Fixed "Connected" badge positioning — now sits inline with the page heading.
* Improved badge contrast to meet WCAG AA on all states.
* Removed bulk-selection UI; projects activate automatically on install.
* Fixed type badge stretching to full card width.
* Card redesign: accent stripe, larger avatars, full-width action buttons.

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

= 1.0.1 =
UI polish update. No database changes or upgrade steps required.

= 1.0.0 =
First public release. No upgrade steps required.
