# Dispatch Quick Reference

## Install and connect

```bash
wp plugin install https://github.com/RegionallyFamous/dispatch/releases/latest/download/dispatch-for-telex.zip --activate
wp telex connect
```

Expected result: `Connected! You're all set.`

## Daily operations

```bash
wp telex list
wp telex install <id> --activate
wp telex update --all
wp telex remove <id> --yes
```

## Recovery operations

```bash
wp telex snapshot create --name="Before risky update"
wp telex snapshot list
wp telex snapshot restore <snapshot-id>
```

## Diagnostics

```bash
wp telex health
wp telex circuit
wp telex cache status
```

## High-signal reminders

- Use `wp telex pin <id> --reason="<why>"` to freeze critical projects.
- Use snapshots for full-state recovery workflows.
- If disconnected, reconnect first (`wp telex connect`) before install/update commands.
