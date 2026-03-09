# Managing Projects

## Who this page is for

Site owners operating Telex projects in daily workflows.

## Common tasks

### Install a project

1. Open Dispatch project list.
2. Select a project and click **Install**.
3. Confirm installation state in the project card.

### Update projects

- UI path: use update actions in Dispatch/WordPress update UI.
- CLI path:

```bash
wp telex update --all
```

### Remove a project

```bash
wp telex remove <id> --yes
```

### Pin a project (prevent updates)

```bash
wp telex pin <id> --reason="Awaiting QA sign-off"
```

### Snapshot and restore

```bash
wp telex snapshot create --name="Before update wave"
wp telex snapshot list
wp telex snapshot restore <snapshot-id>
```

## Expected result

Projects are installed/updated/removed with state reflected in Dispatch UI and CLI output.

## Safe recovery

- Prefer snapshot restore for multi-project rollback scenarios.
- Use pinning for critical projects before broad update operations.

## Related pages

- [WP-CLI Reference](https://github.com/RegionallyFamous/dispatch/wiki/WP-CLI-Reference)
- [Troubleshooting](https://github.com/RegionallyFamous/dispatch/wiki/Troubleshooting)
