# Contributing to Dispatch for Telex

Thank you for your interest in contributing. This document covers everything you need to get started.

## Quick start

```bash
git clone https://github.com/regionallyfamous/dispatch.git
cd dispatch
composer install
npm install
npm run build
```

## Development workflow

| Task | Command |
|---|---|
| Build JS/CSS assets | `npm run build` |
| Watch for JS/CSS changes | `npm run start` |
| Lint JS | `npm run lint:js` |
| Lint CSS | `npm run lint:css` |
| Lint docs markdown | `npm run lint:docs:markdown` |
| Check docs links | `npm run lint:docs:links` |
| Lint PHP | `composer lint` |
| Fix PHP coding standards | `vendor/bin/phpcbf` |
| Run PHP tests | `composer test` |
| Run JS tests | `npm run test:js` |
| Static analysis | `vendor/bin/phpstan analyse` |

## Submitting changes

1. Fork the repository and create a branch from `main`.
2. Make your changes and ensure all CI checks pass locally before opening a PR.
3. Open a pull request using the provided template. Include screenshots for UI changes.

All pull requests require:

- Passing CI (lint, tests, PHPStan, Plugin Check)
- Test coverage for new code paths
- A `CHANGELOG.md` entry under `[Unreleased]`
- Translated strings wrapped in `__()` / `_n()` with the `dispatch` textdomain
- Documentation updates for any user-visible or CLI behavior change
- Passing docs quality checks (`npm run lint:docs:markdown && npm run lint:docs:links`)

## Reporting bugs

Please use the [bug report template](.github/ISSUE_TEMPLATE/bug.yml). For security vulnerabilities, follow the [Security Policy](SECURITY.md) — do not open a public issue.

## Code style

- PHP follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- JavaScript follows the [@wordpress/eslint-plugin](https://github.com/WordPress/gutenberg/tree/trunk/packages/eslint-plugin) config.
- All PHP must pass PHPStan at level 8.

## Further reading

- User-facing docs are canonical in the GitHub Wiki.
- Repo docs quality and release standards live in:
  - `docs/documentation-quality-standard.md`
  - `docs/documentation-coverage-matrix.md`
  - `docs/docs-release-checklist.md`
