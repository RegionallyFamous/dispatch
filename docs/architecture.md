# Architecture

This page describes how Dispatch is structured internally — useful for contributors, reviewers, or anyone integrating with the plugin.

## Layers

Dispatch follows a strict layered architecture. Each layer has a single responsibility and communicates only with the layer directly below it.

```
┌─────────────────────────────────────────┐
│         React UI  (src/)                │  Admin & Device Flow apps
├─────────────────────────────────────────┤
│         REST API  (Telex_REST)          │  telex/v1 endpoints
├─────────────────────────────────────────┤
│         Services  (includes/)           │  Auth, Installer, Updater,
│                                         │  Tracker, Cache, Circuit Breaker
├─────────────────────────────────────────┤
│         Telex SDK  (lib/telex-sdk/)     │  PSR-18 HTTP abstraction
├─────────────────────────────────────────┤
│         WP HTTP Client                  │  Bridges PSR-18 → wp_remote_*
└─────────────────────────────────────────┘
```

## Key Classes

| Class | File | Responsibility |
|---|---|---|
| `Telex_Admin` | `includes/class-telex-admin.php` | Admin menu, asset enqueuing, Site Health, Heartbeat |
| `Telex_REST` | `includes/class-telex-rest.php` | REST routes under `telex/v1` |
| `Telex_Auth` | `includes/class-telex-auth.php` | AES-256-GCM token encryption, rate limiting, device flow state |
| `Telex_Installer` | `includes/class-telex-installer.php` | Download, validate, and install project builds |
| `Telex_Updater` | `includes/class-telex-updater.php` | Inject Telex projects into the WP Updates screen |
| `Telex_Tracker` | `includes/class-telex-tracker.php` | `wp_options`-backed registry of installed projects |
| `Telex_Cache` | `includes/class-telex-cache.php` | Transient cache with stale-while-revalidate |
| `Telex_Circuit_Breaker` | `includes/class-telex-circuit-breaker.php` | Three-state circuit breaker for API resilience |
| `Telex_Audit_Log` | `includes/class-telex-audit-log.php` | Custom DB table for security events |
| `Telex_WP_Http_Client` | `includes/class-telex-wp-http-client.php` | PSR-18 adapter over `wp_remote_*` |
| `TelexClient` | `lib/telex-sdk/src/TelexClient.php` | SDK entry point |

## OAuth 2.0 Device Authorization Flow

```
WordPress Admin          Dispatch REST API         Telex Cloud
      │                        │                        │
      │  POST /auth/device     │                        │
      │───────────────────────>│                        │
      │                        │  Request device code   │
      │                        │───────────────────────>│
      │                        │  { device_code,        │
      │                        │    user_code, url }    │
      │  { user_code, url }    │<───────────────────────│
      │<───────────────────────│                        │
      │                        │                        │
      │  (user opens URL,      │                        │
      │   enters code in       │                        │
      │   browser)             │    User authorizes     │
      │                        │                        │
      │  GET /auth/device      │                        │
      │  (heartbeat polling)   │                        │
      │───────────────────────>│  Poll for token        │
      │                        │───────────────────────>│
      │                        │  { access_token }      │
      │                        │<───────────────────────│
      │  { status: connected } │                        │
      │<───────────────────────│                        │
```

The device flow state is stored in a short-lived WordPress transient. Polling uses the WordPress Heartbeat API (15–60 second interval) supplemented by an RFC 8628-compliant interval timer in the React client.

## Token Storage

OAuth tokens are encrypted before being written to `wp_options`:

1. A random 12-byte IV is generated for each write.
2. The token is encrypted with **AES-256-GCM** using a key derived from `wp_salt('auth')`.
3. The ciphertext, IV, and authentication tag are base64-encoded and prefixed with `v2:`.
4. The encrypted string is stored in `wp_options` under `telex_token`.

The plaintext token is never logged or written to disk.

## REST API Endpoints

All endpoints are under the `telex/v1` namespace and require `manage_options` capability unless noted.

| Method | Path | Description |
|---|---|---|
| `GET` | `/projects` | List all Telex projects (cached) |
| `POST` | `/projects` | Install a project |
| `DELETE` | `/projects/<id>` | Remove a project |
| `POST` | `/auth/device` | Start device authorization flow |
| `GET` | `/auth/device` | Poll for authorization completion |
| `DELETE` | `/auth/device` | Cancel an in-progress device flow |
| `DELETE` | `/auth` | Disconnect (remove stored token) |
| `GET` | `/auth/status` | Return current connection status |
| `GET` | `/installed` | List locally installed projects |

## Build System

The React frontend (`src/`) is compiled by `@wordpress/scripts` (Webpack under the hood):

| Entry point | Output |
|---|---|
| `src/admin/index.js` | `build/admin.js` + `build/admin.asset.php` |
| `src/device-flow/index.js` | `build/device-flow.js` + `build/device-flow.asset.php` |

The `build/` directory is not committed. Run `make build` to generate it.

## Data Flow: Install a Project

```
React UI
  └─ POST telex/v1/projects { id }
       └─ Telex_REST::install_project()
            └─ Telex_Auth::get_client()          → SDK credentials
            └─ TelexClient→projects->getBuild()  → build manifest
            └─ Telex_Installer::install()
                 └─ download build files via Telex_WP_Http_Client
                 └─ validate paths (ZipSlip, extension blocklist)
                 └─ create ZIP in wp_temp_dir()
                 └─ WP_Upgrader::install()
                 └─ Telex_Tracker::track()       → record in wp_options
                 └─ Telex_Audit_Log::log()       → write audit event
```
