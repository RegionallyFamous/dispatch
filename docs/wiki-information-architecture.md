# Dispatch Wiki Information Architecture

Canonical user documentation lives in the GitHub Wiki. This file defines structure and page contracts to prevent drift.

## Navigation model

Use task-first routing from the wiki home page:

1. Start here
2. Daily use
3. Operations and recovery
4. Reference
5. Contributor and policy links

## Target wiki structure

### Start here

- `Getting-Started`
- `Troubleshooting` (first-responder section linked from Getting Started)

### Daily use

- `Managing-Projects`
- `Multisite-Setup` (if multisite user path is common)

### Operations and recovery

- `Site-Health-and-Diagnostics`
- `Troubleshooting`

### Reference

- `WP-CLI-Reference`
- `Security-Model`
- `Architecture`

### Contributor/policy routes

- `Contributing` (wiki)
- Repo docs: `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md`

## Required page template

Each user-facing wiki page should include:

1. **Who this page is for**
2. **Prerequisites**
3. **Task steps**
4. **Expected result**
5. **If it fails**
6. **Rollback/safe recovery**
7. **Related pages**

## Routing rules

- Every page links back to wiki home.
- Every risky operation links to troubleshooting and rollback path.
- Every CLI section links to equivalent UI path when available.
- Every UI guide links to CLI path when automation is likely.
