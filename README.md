# Indexhibit

Indexhibit is an archetypal portfolio CMS for everybody.

https://www.indexhibit.org

Indexhibit is a registered trademark of Jeffery Vaska and Daniel Eatock.

---

## WHMCS / Unattended Installation

This fork adds a headless auto-install endpoint for provisioning Indexhibit
from WHMCS without requiring end users to use the web-based wizard.

### Quick Start

1. Upload the packaged application to the domain document root.
2. Run the server-side preparation script:
   ```bash
   chmod +x install.sh
   ./install.sh /var/www/vhosts/example.com/httpdocs
   ```
3. Configure the WHMCS module (`whmcs-module/`) with:
   - Auto-Install Endpoint URL: `https://example.com/ndxzstudio/auto-install.php`
   - Auth Token: a strong random string (must match `$required_auth_token` in `ndxzstudio/auto-install.php`)
   - Default Theme, Admin Username, Admin Password
4. Activate a service in WHMCS; the module will POST to the endpoint and
create the database tables and admin user automatically.

### Manual Endpoint Test

Use `ndxzstudio/auto-install-test.php` for a dry-run that validates
environment checks and database connectivity without writing anything:

```bash
curl -X POST https://example.com/ndxzstudio/auto-install-test.php \
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

```json
{
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
}
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

See [DEPLOY_NOTES.md](DEPLOY_NOTES.md) for Plesk/Ubuntu details and
[WHMCS_INSTALL_PLAN.md](WHMCS_INSTALL_PLAN.md) for the full project plan.
