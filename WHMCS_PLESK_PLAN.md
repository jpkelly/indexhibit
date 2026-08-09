# Indexhibit WHMCS-orchestrated Plesk Plan

**Date**: August 9, 2026  
**Goal**: Let clients provision Indexhibit entirely from WHMCS. WHMCS reads the service domain, calls the Plesk XML API to create the database/user, then triggers the existing `ndxzstudio/auto-install.php` endpoint.

---

## Flow

1. Client orders hosting product attached to this WHMCS module.
2. WHMCS calls `indexhibit_CreateAccount()` with `$params['domain']`.
3. Module builds an `IndexhibitAutoInstallClient` endpoint from `$params['configoption1']`.
4. Module builds DB credentials:
   - If custom fields `Database Name` / `Database User` are set, use them.
   - Otherwise instantiate `PleskApiClient` (config options 6–9) and:
     - Look up the subscription by `$params['domain']`.
     - Create a MySQL database + user on that subscription.
5. Module posts the install payload to `https://DOMAIN/ndxzstudio/auto-install.php`.
6. On success, WHMCS stores the generated admin username/password in `tblhosting`.

---

## Config Options Mapping

| WHMCS Config Option | Field | Purpose |
|---|---|---|
| configoption1 | Auto-Install Endpoint URL | URL of `ndxzstudio/auto-install.php`; `{domain}` placeholder |
| configoption2 | Auth Token | Shared secret for the auto-install endpoint |
| configoption3 | Default Theme | Indexhibit theme |
| configoption4 | Default Admin Username | Fallback admin username |
| configoption5 | Default Admin Password | Fallback admin password |
| configoption6 | Plesk API Base URL | e.g. `https://{serverhostname}:8443` |
| configoption7 | Plesk API Username | Plesk admin/reseller |
| configoption8 | Plesk API Password | Plesk password |
| configoption9 | Plesk API Key | API secret key; overrides password |

---

## Custom Fields (optional)

| Field | Purpose |
|---|---|
| Admin Username | Client-chosen admin username |
| Admin Password | Client-chosen admin password |
| Database Name | Pre-existing DB name |
| Database User | Pre-existing DB user |
| Database Password | Pre-existing DB password |
| Database Host | Defaults to `localhost` |

If database custom fields are supplied, the Plesk API is not called.

---

## Files

- `whmcs-module/indexhibit.php` — main module
- `whmcs-module/lib/indexhibit_client.php` — HTTP client for auto-install endpoint
- `whmcs-module/lib/PleskApiClient.php` — external Plesk XML API client

---

## Status

- [x] Add Plesk API client for external WHMCS calls
- [x] Refactor WHMCS module config options for Plesk
- [x] Refactor DB provisioning to use Plesk when no custom DB fields supplied
- [x] Allow custom admin credentials via WHMCS custom fields
- [ ] Validate against a real Plesk + WHMCS environment
- [ ] Add test mode / dry-run path
- [ ] Update README/WHMCS install docs
