# Contributing

Thank you for your interest in contributing to Dispatch for Telex. This guide covers everything you need to set up a local development environment, run tests, and submit a pull request.

## Prerequisites

- **PHP 8.2+** with the `openssl` and `mysqli` extensions
- **Composer** ([getcomposer.org](https://getcomposer.org))
- **Node.js 24+** and **npm**
- **MySQL 8.0+** or **MariaDB 10.5+** (for PHP tests)
- **WP-CLI** ([wp-cli.org](https://wp-cli.org)) — optional but recommended
- **Make** — available on macOS and Linux by default

## Setup

Clone the repository and run the setup target to install all dependencies:

```bash
git clone https://github.com/regionallyfamous/dispatch.git
cd dispatch
make setup
```

`make setup` runs `composer install` and `npm install`.

## Building JavaScript

The React admin UI and device flow are compiled with `@wordpress/scripts` (Webpack). Run a development build with file watching:

```bash
npm run start
```

Run a production build:

```bash
make build
```

Built files are written to `build/`. This directory is not committed to the repository.

## Linting

Run all linters (JavaScript, CSS, and PHP) in one command:

```bash
make lint
```

Or individually:

```bash
npm run lint:js      # ESLint via @wordpress/scripts
npm run lint:css     # Stylelint via @wordpress/scripts
npm run lint:php     # PHP_CodeSniffer (WordPress-Extra + WordPress-Docs)
```

The PHP coding standard configuration lives in `phpcs.xml.dist`. Rules follow WordPress Core conventions with PHP 8.2 as the baseline.

## Running PHP Tests

### Install the WordPress test suite

The PHP integration tests require a WordPress installation and a test database. Run the provided shell script to set everything up:

```bash
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Replace the database credentials with your local MySQL settings. This script downloads WordPress core and the WP test library to `/tmp/`.

### Run the tests

```bash
make test
# or
composer test
```

PHPUnit 11 is used. The test suite lives in `tests/` and targets `includes/` for coverage. Configuration is in `phpunit.xml.dist`.

## Project Structure

```
dispatch/
├── src/              React source (admin UI + device flow)
├── includes/         PHP plugin classes
├── lib/telex-sdk/    Embedded PHP SDK (PSR-18)
├── tests/            PHPUnit integration tests
├── bin/              Shell scripts (test setup, release zip)
├── docs/             This documentation
└── build/            Compiled JS/CSS (generated, not committed)
```

See [Architecture](architecture.md) for a full breakdown of the layered design.

## Coding Standards

- **PHP:** WordPress Coding Standards via PHP_CodeSniffer. Run `make lint` before every commit.
- **JavaScript:** ESLint + Prettier via `@wordpress/scripts`. Run `npm run lint:js`.
- **CSS:** Stylelint via `@wordpress/scripts`. Run `npm run lint:css`.
- **Commits:** Use clear, imperative commit messages (`Add circuit breaker reset command`, not `fixed stuff`).
- **Branches:** Use `feature/`, `fix/`, or `chore/` prefixes.

## Pull Request Checklist

Before opening a PR, make sure:

- [ ] `make lint` passes with no errors
- [ ] `make test` passes
- [ ] `make build` produces a clean build
- [ ] New PHP classes follow the existing `Telex_*` naming convention
- [ ] New features are covered by tests in `tests/`
- [ ] The changelog in `readme.txt` is updated if the change is user-facing

## Creating a Release

To build a distributable zip for release:

```bash
make release
```

This runs `make clean`, `make build`, and `bin/build-zip.sh` in sequence, producing `dispatch-for-telex.zip` in the repo root. The zip excludes all development files (`src/`, `tests/`, `node_modules/`, `vendor/` dev dependencies, etc.).

Tagged releases on GitHub automatically trigger the release workflow, which uploads the zip as a GitHub Release asset.
