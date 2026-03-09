# Documentation Versioning Policy

## Purpose

Keep docs synchronized with plugin behavior on every release.

## Canonical locations

- User-facing long-form docs: GitHub Wiki (canonical).
- Repo docs: governance, contributor workflow, policies, changelog.
- README: front-door summary and routing only.

## Page classifications

- **Evergreen pages:** architecture intent, contribution policy, coding standards.
- **Release-sensitive pages:** getting started, managing projects, WP-CLI reference, troubleshooting.

## Update requirements by change type

| Change type | Required docs updates |
|---|---|
| New user-visible feature | README summary + affected wiki pages + changelog |
| CLI command/option change | WP-CLI wiki reference + README examples if referenced |
| Security behavior change | `SECURITY.md` and `Security-Model` wiki page |
| Breaking/behavioral change | troubleshooting and migration notes |
| CI/docs tooling change | `CONTRIBUTING.md` and docs quality standards |

## Release synchronization policy

Before release tag:

1. Changelog section exists for release.
2. Release-sensitive docs are updated.
3. Command examples are validated against implementation.
4. Link and markdown checks pass in CI.
5. Reviewer signs off docs readiness.
