# Managing Projects

Once Dispatch is connected to your Telex account, you can install, update, and remove projects directly from the WordPress admin.

## The Projects Screen

Navigate to **Dispatch** in the WordPress admin sidebar. The screen shows a searchable grid of every block and theme available in your Telex account.

Each project card displays:

- Project name and type (Block or Theme)
- Current install status
- Available actions (Install, Update, Remove)

Use the search field at the top to filter projects by name.

## Installing a Project

1. Find the project in the grid.
2. Click **Install**.
3. Dispatch downloads the latest build from Telex, validates all files, and installs the project using the WordPress Upgrader API.
4. For blocks, the plugin is activated automatically. For themes, the theme is registered but not activated — switch to it manually via **Appearance → Themes** when ready.

The card updates to show **Installed** once the process is complete.

> **Via WP-CLI:** `wp telex install <project-id>`

## Updating a Project

When a new build is available, the project card shows an **Update** badge. You can update from:

- **The Dispatch screen** — click **Update** on the project card.
- **The WordPress Updates screen** — Dispatch injects Telex updates into the native updates list, so your team's existing update workflow doesn't change.

> **Via WP-CLI:** `wp telex update <project-id>`

## Removing a Project

1. Find the installed project in the grid.
2. Click **Remove**.
3. Confirm the removal in the dialog.
4. Dispatch uninstalls the project using the WordPress Upgrader API and removes it from tracking.

> **Via WP-CLI:** `wp telex remove <project-id>`

## Listing Installed Projects

To see what's currently installed and whether updates are available:

```bash
wp telex list
```

Example output:

```
+------------+-------+---------+----------+
| ID         | Name  | Type    | Status   |
+------------+-------+---------+----------+
| abc123     | Hero  | Block   | current  |
| def456     | Base  | Theme   | update   |
+------------+-------+---------+----------+
```

## Version Pinning

Lock any project at its current build to prevent it from being updated — even
by `wp telex update --all`. Useful for keeping a known-good version of a
mission-critical block stable while you test newer builds in a staging
environment.

To pin a project, click the pin icon on its card in the Dispatch screen. To
unpin, click again. Pinned projects show a lock badge.

> **Via WP-CLI:** `wp telex pin <project-id>` / `wp telex unpin <project-id>`

## Auto-Update

Set any project to update automatically whenever a new build is published in
Telex. Dispatch runs a daily scheduled check and updates any project with
auto-update enabled. The audit log records every automatic update so there are
no surprises.

Configure auto-update per project from the project card's settings menu. You
can also control it from the command line:

> **Via WP-CLI:** `wp telex update <project-id> --auto-update=on`

## Build Snapshots

Snapshots capture the full set of installed project versions at a point in
time. Take a snapshot before a risky deploy, then restore it in one command
if anything goes wrong.

**Taking a snapshot:**

```bash
wp telex snapshot create "Before v2 launch"
```

**Listing snapshots:**

```bash
wp telex snapshot list
```

**Restoring a snapshot:**

```bash
wp telex snapshot restore <snapshot-id>
```

Snapshots are also accessible from the **Settings → Build Snapshots** tab in
the WordPress admin.

## Project Groups

Organize your library into named collections. Groups are per-user — each team
member can arrange projects however makes sense for their workflow.

Create and manage groups from the projects screen. Use the group filter in the
search bar to show only projects in a specific group. Groups are also
filterable in WP-CLI:

> **Via WP-CLI:** `wp telex list --group="My Group"`

## Block Usage Analytics

Dispatch tracks how many posts each installed block appears in. The usage
count is shown on each project card — a quick signal for which blocks are
embedded widely and which ones were tried once and forgotten.

This data is collected locally from your `post_content` — nothing is sent to
Telex.

## Disconnecting

To disconnect your site from Telex, go to the Dispatch screen and click
**Disconnect**. This removes the stored OAuth token. Already-installed
projects remain installed and continue to work — only the Telex connection
is removed.

> **Via WP-CLI:** `wp telex disconnect`
