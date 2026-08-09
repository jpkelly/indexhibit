# Indexhibit Plesk Integration Plan

**Date**: August 9, 2026  
**Goal**: Provide a Plesk-native experience for installing Indexhibit so a user can choose a domain, have Plesk create the database and assign it to the domain, deploy the application files, and trigger the existing headless installer.

---

## Overview

The WHMCS installer created in `WHMCS_INSTALL_PLAN.md` provides a headless endpoint (`ndxzstudio/auto-install.php`) that creates `ndxzsite/config/config.php` and seeds the database when given credentials.

This plan adds a **Plesk extension** (or server-side provisioning wrapper) that:
1. Lists available domains the administrator/user has access to.
2. Creates a MySQL database and user within Plesk for the selected domain.
3. Deploys the packaged Indexhibit files to that domain's document root.
4. Runs the final deployment steps (`install.sh`).
5. Calls `https://DOMAIN/ndxzstudio/auto-install.php` to complete the install.

This work is intentionally separate from the WHMCS plan because it touches a different control plane (Plesk XML-RPC / REST API / CLI vs. WHMCS module hooks) and can be developed, reviewed, and shipped independently.

---

## Target Stack

- Plesk Obsidian (Linux)
- Ubuntu server
- Apache/Nginx + PHP + MySQL/MariaDB managed by Plesk
- Existing Indexhibit headless installer endpoint

---

## Segment 1 — Research and Validate Plesk APIs

**Goal**: Determine the best way to list domains, create databases, and deploy files from a Plesk extension or external script.

Tasks:
- Identify the Plesk version in use and available APIs.
- Confirm XML-RPC vs. REST API support for:
  - listing domains/subscriptions
  - creating a MySQL database and database user
  - granting privileges for that user to the database
  - fetching the document root for a subscription
  - triggering a PHP script or creating a scheduled task
- Review Plesk extension SDK requirements (PHP, descriptors, packaging).
- Document any API credential or session requirements (admin API key, reseller scope, etc.).

**Deliverable**: Short research note in this document + decision on API strategy.

---

## Segment 2 — Plesk Extension Skeleton

**Goal**: Create the minimum viable Plesk extension structure.

Tasks:
- Create `plesk-extension/` directory with required descriptors:
  - `meta.xml`
  - ` DESCRIPTION.md` (optional but recommended)
  - `htdocs/index.php` entry point
  - `plib/controllers/` for backend logic
  - `plib/library/` for Plesk API client / helpers
  - `plib/views/scripts/` for UI templates
- Ensure the extension can be installed and appears in the Plesk admin panel.

**Deliverable**: Loadable Plesk extension skeleton.

---

## Segment 3 — Domain Selection UI

**Goal**: Allow the Plesk user to pick the target domain.

Tasks:
- Add an admin UI page that lists subscriptions/domains the current Plesk user can manage.
- Support filtering by subscription status (active, not already using Indexhibit, etc.).
- Handle both admin and reseller scopes correctly.

**Deliverable**: UI where the user selects a domain and clicks "Install Indexhibit".

---

## Segment 4 — Database Provisioning in Plesk

**Goal**: Create the MySQL database and user inside Plesk for the selected domain.

Tasks:
- Generate a unique database name and database username based on the subscription or domain.
- Generate a strong random password.
- Call Plesk APIs to:
  - create the database
  - create the database user
  - associate the user with the database (full privileges)
- Store the credentials temporarily (session, encrypted storage, or pass them directly to the next segment).

**Deliverable**: Function that returns valid DB credentials for the selected domain.

---

## Segment 5 — File Deployment

**Goal**: Upload or extract the packaged Indexhibit files to the domain document root.

Tasks:
- Determine how to get the files onto the server:
  - bundle inside the Plesk extension
  - download from a configured URL
  - use a pre-uploaded archive path
- Extract the package to the domain's document root.
- Run `install.sh /path/to/docroot` or replicate its steps in PHP:
  - rename `htaccess` to `.htaccess`
  - set writable permissions on `files/`, `files/gimgs/`, `files/dimgs/`, and `ndxzsite/config/`
- Set correct ownership for the Plesk subscription user.

**Deliverable**: Files deployed and ready for the headless installer.

---

## Segment 6 — Trigger Indexhibit Headless Installer

**Goal**: Call the existing `ndxzstudio/auto-install.php` endpoint with credentials from Segment 4.

Tasks:
- Build the HTTPS URL from the selected domain.
- POST JSON payload:
  - `site_name` from subscription/company name
  - admin details from Plesk subscription owner
  - DB credentials from Segment 4
  - `auth_token` if configured
- Handle endpoint response and surface success/error in the Plesk UI.
- On success, optionally display the admin login URL and credentials.

**Deliverable**: End-to-end install flow working inside Plesk.

---

## Segment 7 — Security, Cleanup, and Documentation

**Goal**: Make the Plesk extension safe and documented.

Tasks:
- Enforce that only authorized Plesk users can run the install.
- Add CSRF protection to the UI form.
- Do not log database passwords in plaintext.
- Add configurable endpoint auth token and default admin password policy.
- Document how to install the Plesk extension, where to place the packaged Indexhibit archive, and how to configure it.
- Update `README.md` with Plesk-specific instructions.

**Deliverable**: Secure, documented Plesk extension ready for packaging and distribution.

---

## Progress Tracker

| Segment | Status | Notes |
|---|---|---|
| 1 — Research Plesk APIs | Complete | Use Plesk PHP SDK `InternalClient` / `pm_ApiRpc` from inside the extension; XML API for webspace/db operations; CLI as fallback only. |
| 2 — Extension skeleton | Complete | `plesk-extension/` directory with `meta.xml`, `DESCRIPTION.md`, `htdocs/index.php`, `plib/library/` controllers/API/deployer, and `plib/views/scripts/`. |
| 3 — Domain selection UI | Complete | Domain list filtered to active, hosted, not-already-installed subscriptions; form styled with CSS. |
| 4 — Database provisioning | Complete | `IndexhibitPleskApiClient::createDatabase()` creates MySQL DB and user on the selected subscription; safe DB names generated from domain. |
| 5 — File deployment | Complete | `IndexhibitDeployer::deploy()` extracts `data/indexhibit-package.tar.gz`, runs `install.sh` or falls back to default permissions/`.htaccess` rename. |
| 6 — Trigger headless installer | Complete | `IndexhibitDeployer` POSTs JSON to `https://DOMAIN/ndxzstudio/auto-install.php`; UI displays admin URL, username, and password on success. |
| 7 — Security + docs | In progress | CSRF token already in UI form; needs privilege check, password handling review, and final packaging docs. |

---

## Suggested Build Order

1. Segment 1 — Validate the Plesk API surface before writing extension code.
2. Segment 2 — Build the extension skeleton.
3. Segment 3 — Domain selection UI.
4. Segment 4 — Database provisioning.
5. Segment 5 — File deployment.
6. Segment 6 — Trigger installer.
7. Segment 7 — Security + docs.

---

## Notes

- Reuse the existing `ndxzstudio/auto-install.php` endpoint whenever possible; do not duplicate install logic.
- Plesk runs PHP as the subscription system user in many configurations; ensure file ownership and permissions reflect that.
- Consider supporting both admin-install (reseller/admin picks any domain) and customer-install (domain owner installs on their own domain) modes in a later iteration.
