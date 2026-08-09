# Indexhibit WHMCS Deployment Notes

**Target stack**: Ubuntu + Plesk + Apache/Nginx + PHP + MySQL/MariaDB

## File Layout

Upload the contents of the package archive to the domain's document root
(e.g. `/var/www/vhosts/example.com/httpdocs`). The following structure is
expected:

```
example.com/httpdocs/
├── files/
│   ├── dimgs/
│   └── gimgs/
├── htaccess          → renamed to .htaccess by install.sh
├── index.php
├── ndxzsite/
│   ├── config/       → must be writable
│   └── ...
└── ndxzstudio/
    ├── auto-install.php
    ├── install.php
    └── lib/installer.php
```

## Pre-Deployment Checklist

1. Create the MySQL database and user for the site.
2. Ensure the domain document root points at the directory containing
   `index.php`.
3. Ensure PHP has the `mysqli` extension loaded.
4. Ensure PHP has either `gd` or `imagick` loaded.
5. Run `./install.sh /var/www/vhosts/example.com/httpdocs` to:
   - rename `htaccess` to `.htaccess`
   - set writable permissions on `files/`, `files/gimgs/`, `files/dimgs/`,
     and `ndxzsite/config/`

## Plesk Notes

- In Plesk, PHP typically runs as the subscription system user, not `www-data`.
- After running `install.sh`, writable directories should be owned by the
  subscription user. The script attempts to detect `www-data`, `apache`,
  `nginx`, or `psacln` and chown when executed as root.
- If permission errors occur after install, check the subscription's PHP
  handler (FPM vs. FastCGI vs. Apache module) and adjust directory ownership.

## Install Methods

### Web Wizard (Manual)

Visit `https://example.com/ndxzstudio/install.php` and complete the form.

### WHMCS / Unattended

POST to `https://example.com/ndxzstudio/auto-install.php` with:

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
  "theme": "default"
}
```

Add `auth_token` if `ndxzstudio/auto-install.php` is configured to require one.

## Post-Install Security

After a successful unattended install, consider removing or disabling:

- `ndxzstudio/install.php`
- `ndxzstudio/auto-install.php`

Leaving these scripts publicly accessible allows anyone with the (optional)
auth token to re-run installation attempts. The endpoint already refuses to
overwrite an existing `ndxzsite/config/config.php`, but defense in depth is
recommended.
