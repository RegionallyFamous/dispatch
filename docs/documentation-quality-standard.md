# Dispatch Documentation Quality Standard

This document is the release-quality bar for docs.

## Non-negotiable standards

- No broken links in repo markdown files.
- No command examples that diverge from implemented behavior.
- No contradictory claims between README, wiki pages, and policy docs.
- No missing rollback guidance for risky or destructive workflows.
- No unresolved placeholder content.

## Readability and accessibility

- Use short sections with explicit headings.
- Use meaningful link text (avoid "click here").
- Keep command examples copy-paste safe.
- Prefer direct, concrete language over marketing phrasing for procedural content.
- Include expected result after major procedures.

## Trust boundaries

- State limitations explicitly when behavior has constraints.
- Separate guaranteed behavior from recommended best practices.
- Keep security claims specific and testable.
- Avoid promises that require external systems beyond plugin control.

## Consistency rules

- Use the same term for the same concept across all docs.
- Match command names/options exactly to `includes/class-telex-cli.php`.
- Keep version requirements synchronized across README and `readme.txt`.
- Ensure release notes and docs claim the same feature set.

## Verification gates

- `npm run lint:docs:markdown`
- `npm run lint:docs:links`
- Manual smoke-check of high-traffic user path:
  - install
  - connect
  - install project
  - update project
  - troubleshoot failed path

## Definition of done for a docs change

1. Updated pages are linked from the appropriate hub page.
2. Commands/options validated against code.
3. Recovery guidance exists for risky operations.
4. Link and markdown checks pass.
5. Changelog entry added under `[Unreleased]` when behavior or docs expectations changed.
