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
 *
 * @version 2.1.6
 */

require_once __DIR__ . '/lib/indexhibit_client.php';

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
    );
}

function indexhibit_CreateAccount(array $params)
{
    $endpoint = indexhibit_build_endpoint($params);
    $token = $params['configoption2'];
    $theme = $params['configoption3'];
    $adminUsername = indexhibit_admin_username($params);
    $adminPassword = indexhibit_admin_password($params);

    $db = indexhibit_build_db_params($params);

    if (empty($db['db_name']) || empty($db['db_user'])) {
        return 'Database credentials from WHMCS are incomplete.';
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

function indexhibit_build_db_params(array $params)
{
    return array(
        'db_host'     => !empty($params['serverhostname']) ? $params['serverhostname'] : 'localhost',
        'db_name'     => $params['configoption4'] ?? $params['serviceid'] . '_indexhibit',
        'db_user'     => $params['configoption5'] ?? $params['serviceid'] . '_indexhibit',
        'db_password' => $params['configoption6'] ?? '',
    );
}

function indexhibit_admin_username(array $params)
{
    $username = $params['configoption4'];
    if (empty($username)) {
        $username = 'index1';
    }
    return $username;
}

function indexhibit_admin_password(array $params)
{
    $password = $params['configoption5'];
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
