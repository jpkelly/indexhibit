#!/usr/bin/env bash
#
# install.sh - Server-side deployment preparation for Indexhibit.
#
# Run this on the Ubuntu/Plesk target server after the application files
# have been uploaded to the document root. It performs the final steps that
# the web wizard normally expects the user to do manually:
#   - rename htaccess to .htaccess
#   - ensure writable directories for file uploads and config
#   - sanity-check required PHP extensions
#
# Usage:
#   chmod +x install.sh
#   ./install.sh [path/to/document/root]
#
# If DOCUMENT_ROOT is not provided, the script assumes the current directory.

set -e

ROOT=${1:-$(pwd)}

if [ ! -f "$ROOT/index.php" ] && [ ! -d "$ROOT/ndxzstudio" ]; then
    echo "Error: $ROOT does not look like an Indexhibit document root."
    exit 1
fi

echo "Preparing Indexhibit deployment in: $ROOT"

# 1. Enable Apache rewrite rules.
if [ -f "$ROOT/htaccess" ]; then
    if [ -f "$ROOT/.htaccess" ]; then
        echo "  .htaccess already exists; leaving existing rules in place."
    else
        mv "$ROOT/htaccess" "$ROOT/.htaccess"
        echo "  Renamed htaccess -> .htaccess"
    fi
else
    echo "  Warning: htaccess not found; skipping rewrite setup."
fi

# 2. Ensure directories exist and are writable by the web server.
ensure_writable() {
    local dir="$1"
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo "  Created $dir"
    fi
    chmod -R u+rwX "$dir"
    echo "  Ensured writable: $dir"
}

ensure_writable "$ROOT/files"
ensure_writable "$ROOT/files/gimgs"
ensure_writable "$ROOT/files/dimgs"
ensure_writable "$ROOT/ndxzsite/config"

# 3. PHP extension sanity check.
php -m 2>/dev/null | grep -qi '^mysqli$' || {
    echo "  Warning: PHP mysqli extension is not loaded."
}
(php -m 2>/dev/null | grep -qi '^gd$' || php -m 2>/dev/null | grep -qi '^imagick$') || {
    echo "  Warning: Neither PHP gd nor imagick extension is loaded."
}

# 4. Optional Plesk ownership fix. Only run when the current user can chown.
#    This block detects common Plesk/Apache user names; adjust as needed.
PLESK_USER=""
for candidate in www-data apache nginx psacln; do
    if id "$candidate" >/dev/null 2>&1; then
        PLESK_USER="$candidate"
        break
    fi
done

if [ -n "$PLESK_USER" ] && [ "$(id -u)" -eq 0 ]; then
    chown -R "$PLESK_USER:$PLESK_USER" "$ROOT/files" "$ROOT/ndxzsite/config"
    echo "  Set ownership of writable dirs to $PLESK_USER"
fi

echo "Deployment preparation complete."
echo "Next steps:"
echo "  - For manual install, visit /ndxzstudio/install.php"
echo "  - For WHMCS/unattended install, call /ndxzstudio/auto-install.php"
echo ""
echo "Security reminder: after unattended install, remove or disable:"
echo "  - ndxzstudio/install.php"
echo "  - ndxzstudio/auto-install.php"
