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

## Security

- The extension requires administrator or reseller privileges.
- Configure the auto-install endpoint auth token under extension settings.
- Remove the auto-install endpoint scripts from the deployed site after installation.
