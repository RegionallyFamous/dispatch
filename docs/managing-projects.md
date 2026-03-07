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

## Disconnecting

To disconnect your site from Telex, go to the Dispatch screen and click **Disconnect**. This removes the stored OAuth token. Already-installed projects remain installed and continue to work — only the Telex connection is removed.

> **Via WP-CLI:** `wp telex disconnect`
