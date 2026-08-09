# Indexhibit Installer for Plesk

This Plesk extension installs [Indexhibit](https://www.indexhibit.org) onto a selected subscription.

It creates the MySQL database and user within Plesk, deploys the packaged Indexhibit files to the subscription's document root, and calls the headless auto-install endpoint to complete setup.

## Requirements

- Plesk Obsidian 18.0+
- PHP 7.4+
- MySQL/MariaDB managed by Plesk
- The packaged Indexhibit archive available to the extension

## Usage

1. Install the extension in Plesk.
2. Go to **Extensions > Indexhibit Installer**.
3. Select a subscription and click **Install Indexhibit**.
4. The extension creates the database, deploys files, and completes the install automatically.

## Packaging

1. Run `./package.sh` to build `indexhibit-2.1.6.tar.gz`.
2. Copy it into the extension data directory as `indexhibit-package.tar.gz`.
3. Copy `data/auth-token.example.txt` to `data/auth-token.txt` and set a strong token.
4. Zip the `plesk-extension/` contents and upload the archive in **Extensions > My Extensions > Upload Extension**.

## Security

- The extension requires administrator or reseller privileges.
- The auto-install endpoint auth token is read from `data/auth-token.txt`.
- Generated admin passwords are shown once after installation and are not logged.
- Remove the auto-install endpoint scripts from the deployed site after installation.
