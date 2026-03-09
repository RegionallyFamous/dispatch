# Dispatch Documentation Coverage Matrix

This matrix defines what must be documented for site owners and where each topic lives.

## Priority definition

- `P0`: User cannot safely install, operate, or recover without it.
- `P1`: Improves day-2 reliability and team operations.
- `P2`: Useful reference depth and contributor support.

## Coverage matrix

| Area | Priority | Canonical page | Status target | Notes |
|---|---|---|---|---|
| Installation and prerequisites | P0 | Wiki `Getting-Started` | Required | Include WP/PHP minimums and install variants |
| OAuth device connection flow | P0 | Wiki `Getting-Started` | Required | Include expected success state and reconnect path |
| First project install | P0 | Wiki `Managing-Projects` | Required | Include post-install verification checklist |
| Updates and update badges | P0 | Wiki `Managing-Projects` | Required | Explain native updates integration and edge cases |
| Remove workflow | P0 | Wiki `Managing-Projects` | Required | Include impact and safe-removal expectations |
| Troubleshooting entrypoint | P0 | Wiki `Troubleshooting` | Required | Must include first-60-seconds triage |
| Security reporting | P0 | `SECURITY.md` | Required | Private disclosure path only |
| Snapshot create/restore | P1 | Wiki `Managing-Projects` + CLI ref | Required | Include rollback warnings and verification |
| Version pinning | P1 | Wiki `Managing-Projects` + CLI ref | Required | Include requirement for pin reason in CLI |
| Auto-update and approvals | P1 | Wiki `Managing-Projects` | Required | Include risk controls for production |
| Health diagnostics | P1 | Wiki `Site-Health-and-Diagnostics` | Required | Map checks to actionable responses |
| Cache and circuit breaker ops | P1 | Wiki `WP-CLI-Reference` + troubleshooting | Required | Explain when manual reset/clear is appropriate |
| Config export/import | P1 | Wiki `WP-CLI-Reference` | Required | Include migration workflow and file hygiene |
| Multisite behavior | P1 | Wiki `Multisite-Setup` | Required | Clarify network-level expectations |
| Audit log usage | P1 | Wiki `Managing-Projects` + CLI ref | Required | Include filtering and CSV export use cases |
| CI/CD automation patterns | P2 | Wiki `WP-CLI-Reference` | Required | Include copy-paste-safe examples |
| Contributor workflows | P2 | `CONTRIBUTING.md` + wiki contributing | Required | Keep commands aligned with CI |
| Architecture/security internals | P2 | Wiki `Architecture` + `Security-Model` | Required | Keep claims bounded and testable |

## Site-owner P0 user tasks

The following tasks must always be executable with no hidden assumptions:

1. Install Dispatch.
2. Connect Telex account.
3. Install first project.
4. Update an installed project.
5. Remove an installed project.
6. Recover from a failed update (troubleshooting + snapshots).
7. Validate health status and know what to do if a check fails.
