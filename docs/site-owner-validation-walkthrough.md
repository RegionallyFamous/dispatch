# Site-Owner Validation Walkthrough

Use this walkthrough before release to verify the primary user path.

## Scenario assumptions

- Fresh WordPress site.
- Dispatch installed.
- Valid Telex account available.

## Walkthrough steps

1. Open Dispatch screen.
2. Connect via OAuth device flow.
3. Confirm project library appears.
4. Install one project.
5. Update one installed project (or verify update state).
6. Run `wp telex health`.
7. Create and list snapshot.
8. Trigger troubleshooting path (simulate failed precondition such as disconnected state).

## Success criteria

- Every step maps to a documented page with exact instructions.
- User never needs implicit maintainer knowledge.
- Recovery path is visible from the same page when a step fails.

## Friction capture

Record any of the following:

- Missing prerequisite
- Ambiguous wording
- Incorrect expected outcome
- Missing rollback/recovery instruction
- Broken cross-link
