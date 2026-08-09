<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Indexhibit WHMCS Provisioning Module
 *
 * Triggers the headless auto-install endpoint on the remote server when a
 * hosting product using this module is activated. Requires the remote server
 * to already have Indexhibit uploaded and the install.sh steps completed.
 *
 * Configuration fields (per product in WHMCS Admin -> Products/Services):
 *   - Auto-Install Endpoint URL  (e.g. https://DOMAIN/ndxzstudio/auto-install.php)
 *   - Auth Token                 (must match the $required_auth_token on the remote endpoint)
 *   - Default Theme              (e.g. default)
 *   - Default Admin Username     (e.g. index1)
 *   - Default Admin Password     (generated if left blank)
 *   - Plesk API Base URL         (e.g. https://{serverhostname}:8443)
 *   - Plesk API Username         (admin or reseller)
 *   - Plesk API Password         (or API Key below)
 *   - Plesk API Key              (takes precedence over password)
 *
 * If no database custom fields are supplied, the module asks Plesk to create
 * a MySQL database on the service domain before calling the auto-install endpoint.
 *
 * @version 2.1.6
 */

require_once __DIR__ . '/lib/indexhibit_client.php';
require_once __DIR__ . '/lib/PleskApiClient.php';

function indexhibit_MetaData()
{
    return array(
        'DisplayName' => 'Indexhibit',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
    );
}

function indexhibit_ConfigOptions()
{
    return array(
        'Auto-Install Endpoint URL' => array(
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'https://{domain}/ndxzstudio/auto-install.php',
            'Description' => 'URL to ndxzstudio/auto-install.php on the remote site. Use {domain} as a placeholder.',
        ),
        'Auth Token' => array(
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Must match the $required_auth_token in ndxzstudio/auto-install.php.',
        ),
        'Default Theme' => array(
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'default',
            'Description' => 'Default Indexhibit theme to activate.',
        ),
        'Default Admin Username' => array(
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'index1',
            'Description' => 'Fallback admin username if the client does not supply one.',
        ),
        'Default Admin Password' => array(
            'Type' => 'password',
            'Size' => '32',
            'Default' => '',
            'Description' => 'Fallback admin password if the client does not supply one. Leave blank to generate.',
        ),
        'Plesk API Base URL' => array(
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'https://{serverhostname}:8443',
            'Description' => 'Base URL for Plesk XML API. Use {serverhostname} as placeholder.',
        ),
        'Plesk API Username' => array(
            'Type' => 'text',
            'Size' => '32',
            'Default' => 'admin',
            'Description' => 'Plesk admin or reseller username.',
        ),
        'Plesk API Password' => array(
            'Type' => 'password',
            'Size' => '32',
            'Default' => '',
            'Description' => 'Plesk admin or reseller password. Required if API Key is not used.',
        ),
        'Plesk API Key' => array(
            'Type' => 'password',
            'Size' => '64',
            'Default' => '',
            'Description' => 'Plesk API secret key. If provided, it takes precedence over password.',
        ),
    );
}

function indexhibit_CreateAccount(array $params)
{
    $endpoint = indexhibit_build_endpoint($params);
    $token = $params['configoption2'];
    $theme = $params['configoption3'];
    $adminUsername = indexhibit_admin_username($params);
    $adminPassword = indexhibit_admin_password($params);

    $db = indexhibit_build_db_params($params, $params['domain']);

    if (empty($db['db_name']) || empty($db['db_user']) || empty($db['db_password'])) {
        return 'Database credentials are incomplete.';
    }

    $payload = array(
        'site_name'           => $params['clientsdetails']['companyname']
            ? $params['clientsdetails']['companyname']
            : $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'] . ' Site',
        'admin_first_name'    => $params['clientsdetails']['firstname'],
        'admin_last_name'     => $params['clientsdetails']['lastname'],
        'admin_email'         => $params['clientsdetails']['email'],
        'admin_username'      => $adminUsername,
        'admin_password'      => $adminPassword,
        'db_host'             => $db['db_host'],
        'db_name'             => $db['db_name'],
        'db_user'             => $db['db_user'],
        'db_password'         => $db['db_password'],
        'table_prefix'        => 'ndxzbt_',
        'theme'               => $theme,
        'user_language'       => 'en-us',
        'auth_token'          => $token,
    );

    $client = new IndexhibitAutoInstallClient($endpoint, $token);
    $result = $client->install($payload);

    if (!$result['success']) {
        return 'Indexhibit auto-install failed: ' . $result['message'];
    }

    // Store the admin credentials so they can be shown in the client area.
    Capsule::table('tblhosting')
        ->where('id', $params['serviceid'])
        ->update(array(
            'username' => $adminUsername,
            'password' => encrypt($adminPassword),
        ));

    return 'success';
}

function indexhibit_SuspendAccount(array $params)
{
    // No suspend action implemented at this time.
    return 'success';
}

function indexhibit_UnsuspendAccount(array $params)
{
    // No unsuspend action implemented at this time.
    return 'success';
}

function indexhibit_TerminateAccount(array $params)
{
    // No terminate action implemented at this time.
    return 'success';
}

function indexhibit_ClientArea(array $params)
{
    $adminUrl = 'https://' . $params['domain'] . '/ndxzstudio/';

    return array(
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => array(
            'adminUrl' => $adminUrl,
            'adminUsername' => $params['username'],
        ),
    );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function indexhibit_build_endpoint(array $params)
{
    $template = $params['configoption1'];
    if (empty($template)) {
        $template = 'https://{domain}/ndxzstudio/auto-install.php';
    }

    return str_replace('{domain}', $params['domain'], $template);
}

function indexhibit_build_db_params(array $params, $domain)
{
    $db = array();

    // Prefer explicit custom fields if populated.
    if (!empty($params['customfields']['Database Name']) && !empty($params['customfields']['Database User'])) {
        $db['db_name'] = $params['customfields']['Database Name'];
        $db['db_user'] = $params['customfields']['Database User'];
        $db['db_password'] = $params['customfields']['Database Password'];
        $db['db_host'] = !empty($params['customfields']['Database Host'])
            ? $params['customfields']['Database Host']
            : 'localhost';

        return $db;
    }

    // Otherwise, ask Plesk to create a database on the service domain.
    $plesk = indexhibit_plesk_client($params);
    if (!$plesk) {
        return $db;
    }

    $subscription = $plesk->findSubscriptionByDomain($domain);
    if (!$subscription) {
        return $db;
    }

    $dbHost = 'localhost';
    $dbName = indexhibit_safe_db_name('indexhibit_' . $domain);
    $dbUser = $dbName;
    $dbPassword = indexhibit_random_password(18);

    $created = $plesk->createDatabase($subscription['id'], $dbName, $dbUser, $dbPassword);
    if (!$created['success']) {
        return $db;
    }

    $db['db_host'] = $dbHost;
    $db['db_name'] = $dbName;
    $db['db_user'] = $dbUser;
    $db['db_password'] = $dbPassword;

    return $db;
}

function indexhibit_plesk_client(array $params)
{
    $baseUrl = $params['configoption6'];
    if (empty($baseUrl)) {
        $baseUrl = 'https://' . $params['serverhostname'] . ':8443';
    } else {
        $baseUrl = str_replace('{serverhostname}', $params['serverhostname'], $baseUrl);
    }

    $username = $params['configoption7'];
    $password = $params['configoption8'];
    $apiKey = $params['configoption9'];

    if (empty($baseUrl) || (empty($apiKey) && (empty($username) || empty($password)))) {
        return null;
    }

    return new PleskApiClient($baseUrl, $username, $password, $apiKey);
}

function indexhibit_safe_db_name($name)
{
    $name = strtolower(preg_replace('/[^a-z0-9_]/', '_', $name));
    $name = trim($name, '_');
    if (strlen($name) > 32) {
        $name = substr($name, 0, 32);
    }
    return $name;
}

function indexhibit_admin_username(array $params)
{
    $username = isset($params['customfields']['Admin Username']) && !empty($params['customfields']['Admin Username'])
        ? $params['customfields']['Admin Username']
        : $params['configoption4'];

    if (empty($username)) {
        $username = 'index1';
    }
    return $username;
}

function indexhibit_admin_password(array $params)
{
    $password = isset($params['customfields']['Admin Password']) && !empty($params['customfields']['Admin Password'])
        ? $params['customfields']['Admin Password']
        : $params['configoption5'];

    if (empty($password)) {
        $password = indexhibit_random_password();
    }
    return $password;
}

function indexhibit_random_password($length = 12)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}
