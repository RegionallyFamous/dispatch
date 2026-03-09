# WP-CLI Reference

Dispatch commands are available under:

```bash
wp telex <subcommand>
```

## Core commands

```bash
wp telex list [--format=<format>] [--status=<status>] [--type=<type>] [--fields=<fields>]
wp telex install <id> [--activate]
wp telex update <id>
wp telex update --all
wp telex rollback <id> --version=<version> [--yes]
wp telex remove <id> [--yes]
wp telex connect
wp telex disconnect [--yes]
wp telex health
wp telex audit-log [--limit=<n>] [--action=<action>] [--since=<date>] [--until=<date>] [--user=<login>] [--export=<path>] [--format=<format>]
wp telex pin <id> --reason=<reason>
wp telex unpin <id>
wp telex snapshot create [--name=<name>]
wp telex snapshot list
wp telex snapshot restore <snapshot-id>
wp telex snapshot delete <snapshot-id>
wp telex circuit
wp telex circuit reset
wp telex cache status
wp telex cache warm
wp telex cache clear
wp telex config export [--output=<file>]
wp telex config import <file>
```

## Operational reminders

- `pin` requires `--reason`.
- Use snapshot restore for full-state recovery.
- Run `wp telex health` before and after risky operations.

## Related pages

- [Managing Projects](https://github.com/RegionallyFamous/dispatch/wiki/Managing-Projects)
- [Troubleshooting](https://github.com/RegionallyFamous/dispatch/wiki/Troubleshooting)
