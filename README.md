# Indexhibit

Indexhibit is an archetypal portfolio CMS for everybody.

https://www.indexhibit.org

Indexhibit is a registered trademark of Jeffery Vaska and Daniel Eatock.

---

## WHMCS / Unattended Installation

This fork adds a headless auto-install endpoint and a WHMCS provisioning module
so end users can install Indexhibit without using the web-based wizard.

There are two supported control paths:

1. **WHMCS as primary orchestrator (recommended)** — WHMCS reads the service
domain, calls the Plesk XML API to create the database, then posts to the
auto-install endpoint.
2. **Plesk extension (optional)** — a Plesk admin/reseller extension that lets
you pick a subscription, create the database, deploy files, and trigger the
installer. Build instructions are in
[`PLESK_INTEGRATION_PLAN.md`](PLESK_INTEGRATION_PLAN.md#segment-7--security-cleanup-and-documentation).

### Quick Start — WHMCS + Plesk

1. Upload the packaged application to the domain document root.
2. Run the server-side preparation script:
   ```bash
   chmod +x install.sh
   ./install.sh /var/www/vhosts/example.com/httpdocs
   ```
3. Set the auto-install endpoint token in `ndxzstudio/auto-install.php`:
   ```php
   $required_auth_token = 'A_STRONG_RANDOM_TOKEN';
   ```
4. Install the WHMCS module:
   - Copy `whmcs-module/` to `WHMCS_ROOT/modules/servers/indexhibit/`.
   - Create a product that uses the **Indexhibit** module.
5. Configure the product in WHMCS:
   | Config Option | Purpose |
   |---|---|
   | Auto-Install Endpoint URL | `https://{domain}/ndxzstudio/auto-install.php` |
   | Auth Token | Must match `$required_auth_token` |
   | Default Theme | e.g. `default` |
   | Default Admin Username | Fallback username |
   | Default Admin Password | Leave blank to generate |
   | Plesk API Base URL | `https://{serverhostname}:8443` |
   | Plesk API Username | Admin or reseller |
   | Plesk API Password | If not using an API key |
   | Plesk API Key | Recommended; overrides password |
6. Activate a service in WHMCS. The module will:
   - Find the Plesk subscription for the service domain.
   - Create a MySQL database and user on that subscription.
   - POST the install payload to the endpoint.
   - Save the generated admin credentials in WHMCS for the client area.
7. Use **Test Connection** in the WHMCS admin product config to validate Plesk
   API credentials and subscription lookup without creating a database or
   installing anything.

### Manual Endpoint Test

Use `ndxzstudio/auto-install-test.php` for a dry-run that validates
environment checks and database connectivity without writing anything:

```bash
curl -k -X POST https://example.com/ndxzstudio/auto-install-test.php \
  -H "Content-Type: application/json" \
  -d '{
    "db_host": "localhost",
    "db_name": "indexhibit_db",
    "db_user": "indexhibit_user",
    "db_password": "secret"
  }'
```

### Endpoint Request Format

A normal install request to `ndxzstudio/auto-install.php` looks like:

```bash
curl -k -X POST https://example.com/ndxzstudio/auto-install.php \
  -H "Content-Type: application/json" \
  -d '{
    "site_name": "My Gallery",
    "admin_first_name": "Admin",
    "admin_last_name": "User",
    "admin_email": "admin@example.com",
    "admin_username": "admin",
    "admin_password": "changeme",
    "db_host": "localhost",
    "db_name": "indexhibit_db",
    "db_user": "indexhibit_user",
    "db_password": "secret",
    "table_prefix": "ndxzbt_",
    "theme": "default",
    "auth_token": "YOUR_TOKEN"
  }'
```

Optional overrides are also accepted: `user_language`, `home_section_title`,
`project_section_title`, `tag_section_title`, `header_html`, `footer_html`,
`api_key`.

### Post-Install Security

After a successful unattended install, remove or disable these scripts to
prevent accidental reinstallation attempts:

- `ndxzstudio/install.php`
- `ndxzstudio/auto-install.php`
- `ndxzstudio/auto-install-test.php`

### Further Reading

- [DEPLOY_NOTES.md](DEPLOY_NOTES.md) — Plesk/Ubuntu deployment details.
- [WHMCS_INSTALL_PLAN.md](WHMCS_INSTALL_PLAN.md) — original WHMCS installer plan.
- [WHMCS_PLESK_PLAN.md](WHMCS_PLESK_PLAN.md) — WHMCS-orchestrated Plesk backend plan.
- [PLESK_INTEGRATION_PLAN.md](PLESK_INTEGRATION_PLAN.md) — Plesk extension plan (secondary tool).
