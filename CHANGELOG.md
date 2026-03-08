# Changelog

---

## [Unreleased]

---

## [1.3.2] — 2026-03-08

### Fixed

- Eliminated flash of empty content on the connected projects screen by initialising the Redux store with `loading: true`.
- Eliminated flash of blank content on the disconnected screen by injecting a matching server-side skeleton into the React mount point before JavaScript initialises.
- Removed connecting line from the step indicator; steps now display with even spacing across the full width separated by a subtle `/` divider.

### Changed

- **Connect screen — waiting state**: redesigned around the device code as the hero element; code block now spans full card width with clear border dividers; copy button upgraded to secondary variant with check-icon feedback; WP Spinner replaced with an animated brand-colour pulse dot; Cancel demoted to a small text link.
- **Connect screen — success state**: extra vertical padding, heading restored to neutral colour with the green icon carrying the success signal, and copy updated to confirm account connection before redirect.
- **Connect screen — hero**: removed Telex logo from the left-hand hero column; heading leads directly.
- **Connect screen — card**: Telex wordmark logo replaces the generic plugins icon in the connect card.
- **Disconnect notice**: changed from `info` (blue) to `success` (green) type; copy updated to "Disconnected from Telex. Connect again whenever you're ready."
- **Step indicator**: removed inter-step connecting line; steps are evenly distributed across full width with centered content and a `/` separator at each boundary.

---

## [1.3.1] — 2026-03-08

### Fixed

- **Duplicate page header** — The Dispatch admin page was rendering its header and content twice because `add_menu_page` and `add_submenu_page` both registered a callback on the same WordPress hook (`toplevel_page_telex`). Removed the redundant callback from the submenu registration; the sidebar label still reads "Projects" as intended.
- **Activity & Health tab loading state** — Replaced the plain spinner on the Activity and Health tabs with proper skeleton tables that match the real content layout, eliminating a jarring layout shift when data loads.
- **PHPStan — `wp_json_encode` return type** (`Telex_Notifications`) — `wp_json_encode()` can return `false`; the return value is now stored in a variable and a safe fallback (`'{}'`) is used on failure.
- **PHPStan — redundant `array_values()` after `usort()`** (`Telex_Snapshot`) — `usort()` already reindexes the array in-place; the redundant `array_values()` call and unnecessary `??` fallbacks on guaranteed keys were removed.
- **PHPCS — doc-comment violations** in `Telex_Analytics` and `Telex_Health` — Inline `@var` type-narrowing annotations now use the `@phpstan-var` tag with a proper short description, satisfying both PHPDoc and PHPStan conventions.
- **PHPCS — false-positive "commented-out code"** — Array-shape type annotations in the form `array{key: type}` were being flagged as commented-out PHP code. Suppressed `Squiz.PHP.CommentedOutCode.Found` in `phpcs.xml.dist` for the entire project.

---

## [1.3.0] — 2026-03-08

This is the biggest Dispatch release yet. We went from a deployment tool to a
full project management platform — without losing any of the simplicity that
made the original useful. Everything you had before still works exactly as it
did. You just have a lot more to work with now.

### Build snapshots — roll back in one click

If you've ever pushed a Telex update that broke something, you know the
feeling. Now you can take a snapshot of your entire installed project set
before a big change, and restore it if anything goes sideways. Snapshots
capture the exact build version of every installed project. Restoring brings
every project back to where it was — no manual reinstalls, no digging through
version history.

The CLI gets it too: `wp telex snapshot create "Before launch"` and
`wp telex snapshot restore <id>`.

### Version pinning — freeze what's working

Lock any project at its current build. Pinned projects are excluded from
update checks and batch update operations — the `wp telex update --all`
command skips them automatically. Unpin when you're ready to upgrade.

### Auto-update — stay current automatically

Set any project to update automatically whenever a new build is available.
Per-project control means you can auto-update low-risk utility blocks while
keeping your main theme pinned. The scheduled runner fires daily and reports
what it updated, so there are no surprises.

### Notification channels — know when things happen

Configure email digests and Slack webhooks for install, update, and removal
events. The digest batches events so you're not getting pinged for every
single background update. Slack notifications include the project name, the
action taken, and who triggered it.

### Project health dashboard

A new tab shows the health status of every installed project: whether it's
active, whether its files are intact, whether its version is current, and
whether there are any known compatibility warnings. Aimed at site owners who
want a single-glance view of what's actually running.

### Block usage analytics

Dispatch now tracks how many times each installed block has actually been used
across your post content. The projects screen shows a live usage count next to
each block — so you can see which ones are embedded in dozens of posts and
which ones were tried once and forgotten.

### Project groups — organize your library

Named groups let you organize your Telex projects however makes sense for your
workflow. Tag a project to multiple groups, filter by group in the search bar,
and use group names in WP-CLI filters. Stored per-user, so each team member
can have their own organization.

### GDPR / Privacy framework integration

The audit log is now registered with WordPress's built-in privacy tools.
Site administrators can use **Tools → Erase Personal Data** to remove a
user's audit history, and **Tools → Export Personal Data** to include it in
data export requests. This is the correct way to handle this in WordPress,
and it means Dispatch now participates in any privacy workflow your site
already has in place.

### Settings page redesigned

The settings page has been rebuilt from the ground up. It now fills the full
available width, has a consistent page header matching the main Dispatch
screen, and loads skeleton placeholders while each panel fetches its data —
so there's no layout shift and no blank panels while things load. The webhook
secret section, notification settings, and build snapshot table each have
their own shaped skeleton that matches the real content.

### WP-CLI gets smarter

The `wp telex doctor` command now checks file-modification permissions using
`wp_is_file_mod_allowed()` — the WordPress-native API — rather than reading
the `DISALLOW_FILE_MODS` constant directly. The snapshot subcommand gains
`create`, `list`, `restore`, and `delete` subcommands. Version pinning and
auto-update preferences are configurable from the command line.

---

## [1.2.0] — 2026-03-08

This release is a focused performance and reliability pass. No new features —
just a faster, more resilient plugin that does the same things with less work.

### Faster admin page loads

The biggest day-to-day win: the admin page no longer decrypts the API token
or creates an HTTP client on every load when project version data is already
sitting in the cache. Before this change, every visit to the Dispatch screen
would always decrypt the stored token even if it had nothing useful to do with
it. Now it only decrypts when there is actual work to do.

Installing or removing a project also stopped flushing the entire project-list
cache. The old code would nuke the whole list, forcing the very next page view
to fetch fresh data from the API. That was redundant — the UI already requests
a force-refresh after every install. Now only the per-project cache entry is
invalidated, and the list stays warm.

### Less redundant work on busy sites

The `reconcile()` call that checks whether installed plugins and themes still
exist on disk now runs at most once per minute per site, protected by a short
transient lock. Previously it ran on every request to the projects endpoint —
meaning every admin page visit would issue one `is_dir()` filesystem call per
installed project. On a site with twenty installed projects and active editors,
that adds up quickly.

The background cache warming cron job also exits early if a user request has
already refreshed the data since the cron was queued, eliminating a common
redundant API round-trip on sites where caches are actively used.

### More resilient initial load

The project list now retries automatically once, after a 1.5-second delay,
when the initial page-load fetch fails — catching cold-server responses and
brief network blips without showing the user a permanent error banner. Rapid
keyboard-shortcut presses (`r` to refresh) are coalesced so only one request
is in flight at a time. And the build-status poll interval during installation
is now capped at 30 seconds so a pathological server response can no longer
stall the install UI indefinitely.

---

## [1.1.1] — 2026-03-07

This release is a visual and housekeeping pass. Every project in your library
now has a unique, eye-catching avatar — no two look the same. We also cleaned
up some behind-the-scenes roughness in the test setup and menu labels.

### Every project gets its own look

Project avatars used to be a flat colored square with a letter. Now each one
is a proper SVG with a gradient background (picked from a curated set of
color pairs) and a subtle geometric shape — all generated from the project's
ID, so the same project always shows the same avatar and no two projects ever
look identical. It sounds like a small thing, but scanning through a big
library of projects feels noticeably nicer.

### Settings page is properly named

The sub-menu item that was labeled "Audit Log" now says "Settings" — which is
what it actually is. It houses both the webhook configuration panel and the
audit log, so the old name was just confusing.

### Webhook URL is no longer baked into the page HTML

The auto-deploy webhook URL is now fetched on demand via an authenticated REST
call instead of being embedded in the page source. It was harmless before, but
fetching it only when needed is the cleaner approach.

### Test suite renamed for PHPUnit 11

PHP test files have been renamed to `Test_Telex_*.php` to follow PHPUnit 11's
class-name-based discovery convention. If you run tests locally you should see
proper class names in the output now.

---

## [1.1.0] — 2026-03-07

This is the "actually ships things" release — a full skeleton loading screen,
a comprehensive test suite, and a pile of CI hardening. More details in the
[1.1.0 release notes](https://github.com/RegionallyFamous/dispatch/releases/tag/v1.1.0).

---

## [1.0.3] — 2026-03-07

This release is all about reliability and trust. We did a full 20-pass security
and hardening audit of every layer of the plugin — REST API, installer, cache,
authentication, webhooks, multisite, and the JavaScript UI. Plus we fixed a
nasty crash that was breaking the projects screen for some sites.

### Your projects screen won't go blank anymore

A bug introduced in the hardening pass used a WordPress function that doesn't
actually exist. On sites where the project cache had expired, this crashed the
REST API entirely — instead of your projects, you'd see a raw WordPress error
notice. That's fixed. It now uses the correct atomic cache function and your
projects load normally.

### The auto-deploy webhook is a lot safer

The webhook endpoint now validates request timestamps and rejects anything
replayed more than 5 minutes later. It also rate-limits by IP so a flood of
webhook calls won't take your site down. Your secret key is no longer embedded
in the page HTML — it's fetched on demand so it never ends up in browser
history or cached pages.

### Downloads are verified before they touch your site

Every file Dispatch downloads from Telex is now verified with a SHA-256
checksum before it's unpacked. If a file has been tampered with in transit —
even a single byte — the install is aborted and nothing is written to disk.

### Faster, especially on large sites

Project data is now cached more intelligently so Dispatch makes far fewer API
calls. If you have a lot of projects, the screen loads noticeably quicker.
The JavaScript UI also avoids redundant re-renders so interactions feel snappier.
On multisite networks with hundreds of sites, the project cache now warms up
in batches instead of stopping at 100.

### The audit log is now sortable

Click the Date column header in the Audit Log to flip the sort order. Useful
when you're trying to track down what happened at a specific point in time.

### Errors tell you something went wrong, not how it went wrong

When an install fails due to an API error, the message you see is now a plain
explanation of the problem — not a raw PHP exception with a stack trace and
internal method names. Cleaner for users, and no accidental information leakage.

### Multisite uninstall is now thorough

Previously, uninstalling Dispatch on a large multisite network would only clean
up the first 100 subsites. It now iterates through every site on the network
and removes all Dispatch data before the plugin is deleted.

---

## [1.0.2] — 2026-03-07

Updates were broken. Three separate bugs were conspiring to make the install
and update flow unreliable, and we found all of them.

### Fixed

- **The progress bar was lying to you** — it would advance to step 3, then
  snap back to step 1 while waiting for the build. It now stays wherever it
  is. No more whiplash.
- **"Already up to date" when you just updated** — after installing an update,
  Dispatch was recording the old version number instead of the new one. So the
  next time you opened the screen, it would show an update badge for something
  you already installed. That's fixed — it now tracks the version you actually
  installed.
- **"This build isn't ready" after it clearly was** — a race condition between
  two separate API calls could make the installer think a build wasn't ready
  immediately after confirming it was. The duplicate call is gone.
- **Status badge is now inline with the project title** — the "Not installed" /
  "Up to date" indicator was sitting on its own line below the project name.
  It's been moved up next to the title where it makes more sense visually.

---

## [1.0.1] — 2026-03-07

A quick but meaningful polish pass. The plugin now looks and feels like it
belongs in WordPress — clean, consistent, and readable no matter what color
scheme you have set.

### Cleaner UI

- **Fits right into WP admin** — the plugin no longer tries to maintain its
  own dark mode. It uses WordPress's own design tokens throughout, so it looks
  consistent whether you're on a light scheme, a custom color palette, or
  anything in between.
- **Your admin colors, everywhere** — buttons, focus rings, and tab underlines
  now automatically pick up whatever admin color scheme you've chosen in your
  profile. If you're a "Midnight" person, Dispatch is a "Midnight" plugin.
- **Cards got a glow-up** — each project card now has a type-specific accent
  stripe at the top, a bigger avatar, and a full-width action button that's
  impossible to miss. The "Edit in Telex" link appears on hover so it's
  there when you need it without cluttering the default view.
- **Simpler install flow** — checkboxes and bulk-action toolbars are gone.
  Projects activate automatically on install. One click, done.

### Fixed

- The "Connected" badge now sits inline next to the "Dispatch" heading
  instead of dropping to its own line. Small thing, but it bothered us too.
- Badge contrast is now well above WCAG AA across all states — no more
  lime-green-on-white squinting.
- Type badges ("BLOCK", "THEME") were accidentally stretching to fill the
  full card width. They're back to being tidy little pills.

---

## [1.0.0] — 2026-03-06

This is it — Dispatch is here, and we're genuinely excited about it.

The whole idea is simple: you built something great in Telex. Now get it onto
your WordPress site without writing a deployment script, asking a developer,
or ever opening a terminal. Dispatch handles everything.

### What you get

- **A beautiful projects screen** — every Telex block and theme you've built
  lives here, searchable, filterable by type, and ready to install with a
  single click. Updates show up automatically. Removing something is just
  as easy.

- **Passwordless authentication** — connecting to Telex uses the
  [OAuth 2.0 Device Authorization Grant](https://www.rfc-editor.org/rfc/rfc8628)
  — the same flow as the GitHub CLI and AWS CLI. You get a short code, open a
  URL, sign in, and you're done. No API keys. No copy-pasting secrets. No
  passwords at all.

- **Updates where you already look** — installed Telex projects show up on
  WordPress's native Updates screen right next to your plugins and themes.
  Your team doesn't need to learn a new workflow; they'll see the update badge
  in the same place they always have.

- **Rock-solid security** — your access token is encrypted at rest with
  AES-256-GCM using a key derived from your site's own secret salts.
  The plaintext token never touches disk, never appears in logs, and goes
  nowhere except back to Telex over HTTPS. Every download is verified with a
  SHA-256 checksum. ZipSlip attacks and dangerous file extensions are blocked
  before anything reaches the filesystem.

- **A circuit breaker** — if the Telex API goes down, Dispatch backs off
  gracefully instead of hammering it with retries. Your site keeps working.

- **An audit log** — every install, update, remove, connect, and disconnect
  is recorded. You'll always know what changed, when, and who did it.

- **WP-CLI support** — `wp telex list`, `install`, `update`, `remove`,
  `connect`, `disconnect`, and more. Automate whatever you want.

- **Site Health integration** — API connectivity and circuit breaker status
  show up in the WordPress Site Health screen so you can spot problems at a
  glance.

- **Multisite ready** — Dispatch works on WordPress Multisite out of the box.

- **Try it in your browser** — a WordPress Playground blueprint is included
  so anyone can run the plugin in a browser sandbox in seconds, no install
  required.

---

[Unreleased]: https://github.com/regionallyfamous/dispatch/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/regionallyfamous/dispatch/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/regionallyfamous/dispatch/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/regionallyfamous/dispatch/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/regionallyfamous/dispatch/releases/tag/v1.0.0
