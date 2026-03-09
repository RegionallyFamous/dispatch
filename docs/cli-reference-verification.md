# WP-CLI Reference Verification Notes

This file records the command surface verified against `includes/class-telex-cli.php`.

## Verified subcommands

- `list`
- `install`
- `update`
- `rollback`
- `remove`
- `connect`
- `disconnect`
- `health`
- `audit-log`
- `pin`
- `unpin`
- `snapshot` (`create|list|restore|delete`)
- `circuit` (`status|reset`)
- `cache` (`status|warm|clear`)
- `config` (`export|import`)

## Critical option details

- `pin` requires `--reason=<reason>`.
- `rollback` requires `--version=<version>`.
- `remove` supports `--yes`.
- `disconnect` supports `--yes`.
- `audit-log` supports `--limit`, `--action`, `--since`, `--until`, `--user`, `--export`, and `--format`.
- `config export` supports `--output=<file>`.

## Documentation safety notes

- Avoid claiming options that do not exist in command signatures.
- Keep examples aligned with current required flags.
- Prefer snapshot restore guidance for full-state recovery workflows.
