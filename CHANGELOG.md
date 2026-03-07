# Changelog

---

## [Unreleased]

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
