# Changelog

All notable changes to Dispatch for Telex will be documented here.

---

## [Unreleased]

---

## [1.0.2] — 2026-03-07

Three bugs introduced by the install/update pipeline are now squashed.

### Fixed

- **Update progress no longer restarts** — the step indicator was being
  reset to step 1 by an `onBuilding` callback even after the fake-progress
  timers had already advanced it to step 3. The reset is removed; the
  indicator now stays wherever it is while waiting for the server-side
  build to complete.
- **Installed version is now tracked correctly** — after an update, the
  tracker was recording the *currently-deployed* version returned by
  `projects.get()` (e.g. v1) instead of the *latest-build* version (e.g.
  v2) the user actually installed. The installer now takes
  `max(api_version, cached_version)`, where the cache value was populated
  when the "update available" badge was computed and correctly reflects
  the build that was installed.
- **Eliminated a duplicate `getBuild()` race condition** — the REST
  controller and the installer each called `getBuild()` independently. The
  second call could land between build states and return `not_ready`,
  surfacing a spurious "This build isn't ready" error immediately after
  polling had confirmed readiness. The controller now passes its
  already-fetched build data to the installer, removing the redundant
  round-trip entirely.

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

This is it — Dispatch is here, and we're genuinely excited about it. ✨

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

[Unreleased]: https://github.com/regionallyfamous/dispatch/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/regionallyfamous/dispatch/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/regionallyfamous/dispatch/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/regionallyfamous/dispatch/releases/tag/v1.0.0
