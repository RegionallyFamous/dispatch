# Documentation Release Checklist

Use this checklist before tagging a release.

## Release docs gate

- [ ] Changelog updated under `[Unreleased]` with user-visible docs-impacting changes.
- [ ] README reflects current release capabilities and version requirements.
- [ ] Wiki pages for affected features are updated.
- [ ] CLI docs verified against `includes/class-telex-cli.php`.
- [ ] `SECURITY.md` links and scope text are current.
- [ ] `CONTRIBUTING.md` commands and quality gates match CI.
- [ ] Docs lint and link checks pass.

## Ownership model

| Area | Owner role |
|---|---|
| README and release summary | Maintainer shipping release |
| CLI reference accuracy | Maintainer touching CLI code |
| Security policy/model docs | Security response owner |
| Troubleshooting and recovery docs | Maintainer for operational changes |
| CI docs validation workflow | Tooling/CI maintainer |

## Go / no-go criteria

No release tag should be created if:

- Any P0 site-owner workflow is undocumented or stale.
- Any command example fails against current implementation.
- Docs CI checks are failing.
