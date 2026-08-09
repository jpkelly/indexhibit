# Indexhibit WHMCS Unattended Installer Plan

**Date**: August 9, 2026  
**Goal**: Enable installation of Indexhibit from WHMCS (or any automated provisioning system) without requiring the end user to use the web-based install wizard.

---

## Overview

The existing installer is a browser-based wizard at `ndxzstudio/install.php`. The goal is to extract the underlying setup logic so it can be invoked automatically via a single HTTP endpoint using parameters supplied by WHMCS.

---

## Segment 1 — Extract and Clean the Existing Installer Logic

**Goal**: Make the install logic reusable outside the browser wizard.

- Create `ndxzstudio/lib/installer.php` containing an `IndexhibitInstaller` class.
- Move configuration writing (`writeConfig`) and database setup (`install_db`) from `ndxzstudio/install.php` into the class.
- Convert those methods to accept inputs as parameters instead of reading `$_POST` directly.
- Keep `ndxzsite/config/config.example.php` as the canonical config template.
- Update `ndxzstudio/install.php` to use the new class so the wizard continues to work unchanged.

**Deliverable**: `ndxzstudio/lib/installer.php` with shared install logic; wizard still functional.

---

## Segment 2 — Build a Headless Auto-Install Endpoint

**Goal**: Provide a single HTTP endpoint that WHMCS can call to install Indexhibit.

- Create `ndxzstudio/auto-install.php`.
- Accept a JSON or POST payload with the following fields:
  - `site_name`
  - `admin_first_name`
  - `admin_last_name`
  - `admin_email`
  - `db_host`
  - `db_name`
  - `db_user`
  - `db_password`
  - `table_prefix` (optional, default `ndxzbt_`)
  - `admin_username` (optional, default `index1`)
  - `admin_password` (optional, default derived from current wizard default)
  - `auth_token` (required if configured)
- Validate PHP version, writable directories, and DB connectivity.
- Write `ndxzsite/config/config.php`.
- Run the schema and seed logic from the extracted installer class.
- Return a JSON response with `success`, `message`, and relevant details.
- Add a lock check: return 409/403 if `config.php` already exists or if a required auth token is missing/invalid.

**Deliverable**: `ndxzstudio/auto-install.php` callable by provisioning scripts.

---

## Segment 3 — Replace Hardcoded Seed Data with Configurable Defaults

**Goal**: Allow the unattended install to be customized from WHMCS rather than using wizard-only defaults.

- Make admin username configurable (currently hardcoded to `index1`).
- Support a custom admin password from the payload with a fallback to the current MD5-hashed default.
- Make site name, theme, first section names, and default header/footer text parameter-driven.
- Preserve current wizard defaults as fallbacks when optional parameters are omitted.

**Deliverable**: Seed data supports parameterized overrides.

---

## Segment 4 — Hook into WHMCS Provisioning

**Goal**: Trigger the installer automatically from WHMCS when a hosting product is created.

- Create a WHMCS module under `whmcs-module/`:
  - `indexhibit.php` — module metadata and `indexhibit_CreateAccount` function.
  - Optional client-area template for a login / site link.
- The module calls `https://DOMAIN/ndxzstudio/auto-install.php` with:
  - Database credentials from the WHMCS service.
  - Customer email as the admin email.
- Store module configuration in WHMCS:
  - Auto-install endpoint URL.
  - Auth token.
  - Default theme/site name template.

**Deliverable**: WHMCS module that provisions Indexhibit automatically.

---

## Segment 5 — Server-Level Packaging and Deployment

**Goal**: Make deployment onto an Ubuntu/Plesk server straightforward.

- Create `install.sh` for server-side setup:
  - Rename `htaccess` to `.htaccess`.
  - Set writable permissions on `files/`, `files/gimgs/`, `files/dimgs/`, and `ndxzsite/config/`.
  - Check for required PHP extensions: `mysqli`, `gd`/`imagick`.
- Create `package.sh` to build a clean distributable archive excluding git files and development docs.
- Document Plesk-specific paths and recommended file ownership/permissions.

**Deliverable**: `install.sh`, `package.sh`, and deployment notes.

---

## Segment 6 — Safety, Cleanup, and Documentation

**Goal**: Prevent accidental reinstallation and explain usage.

- After unattended install, optionally disable or delete:
  - `ndxzstudio/install.php`
  - `ndxzstudio/auto-install.php`
- Add README section documenting:
  - Default admin URL (`/ndxzstudio`).
  - Default login credentials and how to override them.
  - WHMCS module setup.
  - Manual endpoint usage for testing.
- Create a local test harness (`ndxzstudio/auto-install-test.php`) for dry-run validation.

**Deliverable**: Updated README, optional cleanup behavior, and test harness.

---

## Progress Tracker

| Segment | Status | Branch / Notes |
|---|---|---|
| 1 — Extract installer code | Complete | `ndxzstudio/lib/installer.php` created; `ndxzstudio/install.php` delegates env checks, config writing, and DB seeding to `IndexhibitInstaller`. |
| 2 — Build auto-install endpoint | Complete | `ndxzstudio/auto-install.php` created; accepts JSON/POST params, validates env + DB, writes config, seeds DB, returns JSON. |
| 3 — Configurable seed data | Complete | `IndexhibitInstaller` seed SQL now parameterizes theme, section titles/URLs, header/footer, api_key, user_hash, admin credentials; `auto-install.php` exposes those overrides. |
| 4 — WHMCS module | Complete | `whmcs-module/indexhibit.php`, `lib/indexhibit_client.php`, and `templates/clientarea.tpl` created. |
| 5 — Packaging scripts | Complete | `install.sh`, `package.sh`, and `DEPLOY_NOTES.md` created. |
| 6 — Cleanup + documentation | Complete | Added dry-run test harness, cleanup reminder in endpoint, README section, fallback redirect from ndxzstudio/index.php to auto-install.php. |

## Suggested Build Order

1. Segment 1 — Extract installer code.
2. Segment 2 — Build the auto-install endpoint.
3. Segment 3 — Configurable seed data.
4. Segment 5 — Packaging scripts.
5. Segment 4 — WHMCS module.
6. Segment 6 — Cleanup + documentation.

---

## Notes

- The existing web wizard should remain functional throughout; it will call the same extracted installer class.
- All changes should be backwards-compatible with existing Indexhibit installs.
- Keep security in mind: the auto-install endpoint must be locked or token-protected in production.
