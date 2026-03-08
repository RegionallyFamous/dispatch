# Security Policy

## Supported Versions

Security patches are applied to the latest stable release only. Older major/minor
branches receive critical patches at maintainer discretion.

| Version | Supported          |
| ------- | ------------------ |
| 1.3.x   | Yes (latest)       |
| 1.2.x   | Critical only      |
| < 1.2   | No                 |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you discover a security vulnerability in Dispatch for Telex, please report it
using one of the following channels:

1. **GitHub Private Vulnerability Reporting** — use the
   [Report a vulnerability](../../security/advisories/new) button on the Security
   tab of this repository. This is the preferred channel.

2. **Email** — send details to `security@regionallyfamous.com`. If the disclosure
   is sensitive, please encrypt your message using our PGP key (available on
   request).

Please include as much of the following information as possible to help us
understand the nature and scope of the issue:

- Type of vulnerability (e.g. SQL injection, XSS, privilege escalation, CSRF)
- Full path of the source file(s) related to the vulnerability
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if available)
- Impact of the vulnerability, including how an attacker might exploit it
- Affected versions

## Response Timeline

| Milestone                        | Target     |
| -------------------------------- | ---------- |
| Acknowledgement of report        | 48 hours   |
| Triage and severity assessment   | 5 days     |
| Patch released (critical/high)   | 14 days    |
| Patch released (medium/low)      | 60 days    |
| Public disclosure (coordinated)  | 90 days    |

We will keep you informed at each stage and coordinate public disclosure timing
with you.

## Scope

The following are **in scope** for this policy:

- The Dispatch for Telex WordPress plugin (this repository)
- The Telex REST API endpoints registered by this plugin (`telex/v1/*`)
- The OAuth 2.0 device authorization flow and token storage

The following are **out of scope**:

- The Telex platform itself (`telex.automattic.ai`) — report those to Automattic
- WordPress core vulnerabilities — report those to the
  [WordPress security team](https://make.wordpress.org/core/handbook/testing/reporting-security-vulnerabilities/)
- Vulnerabilities in third-party libraries (report to the respective project and
  we will update our dependency once a fix is available)
- Social engineering attacks

## Disclosure Policy

We follow **coordinated vulnerability disclosure**. We ask that you give us a
reasonable amount of time to address the issue before any public disclosure.
We will credit reporters in the release notes unless you prefer to remain
anonymous.

## Security Features

For an overview of the security controls built into this plugin (AES-256-GCM
token encryption, circuit breaker, audit log, SSRF protection, rate limiting),
see [docs/architecture.md](docs/architecture.md).
