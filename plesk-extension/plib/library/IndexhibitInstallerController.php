<?php

/**
 * Main controller for the Indexhibit Plesk extension.
 *
 * Handles UI rendering and the install action. Delegates Plesk API calls
 * to PleskApiClient and deployment to IndexhibitDeployer.
 */
class IndexhibitInstallerController
{
    /** @var IndexhibitPleskApiClient */
    private $apiClient;

    /** @var IndexhibitDeployer */
    private $deployer;

    /** @var array */
    private $messages = array();

    public function __construct()
    {
        $this->apiClient = new IndexhibitPleskApiClient();
        $this->deployer = new IndexhibitDeployer();
    }

    public function run()
    {
        if (!$this->isAuthorized()) {
            $this->messages[] = array('type' => 'error', 'text' => 'Access denied. Administrator or reseller privileges required.');
            $this->showIndex();
            return;
        }

        $action = isset($_GET['action']) ? $_GET['action'] : 'index';

        switch ($action) {
            case 'install':
                $this->handleInstall();
                break;
            case 'index':
            default:
                $this->showIndex();
                break;
        }
    }

    /**
     * Verify that the current Plesk user is allowed to run the installer.
     *
     * @return bool
     */
    private function isAuthorized()
    {
        if (!class_exists('pm_Session')) {
            return false;
        }

        $client = pm_Session::getClient();
        return $client->isAdmin() || $client->isReseller();
    }

    /**
     * Generate a safe database name from a base string.
     *
     * @param string $base
     * @return string
     */
    private function safeDbName($base)
    {
        $name = strtolower(preg_replace('/[^a-z0-9_]/', '_', $base));
        $name = trim($name, '_');
        if (strlen($name) > 32) {
            $name = substr($name, 0, 32);
        }
        return $name;
    }

    private function showIndex()
    {
        $domains = $this->apiClient->listDomains();

        $csrfToken = pm_Session::getClient()->getSecretKey();

        require __DIR__ . '/../views/scripts/index.phtml';

        // Prevent sensitive values from lingering in the messages array.
        foreach ($this->messages as $key => $message) {
            if (isset($message['admin_password'])) {
                unset($this->messages[$key]['admin_password']);
            }
        }
    }

    private function handleInstall()
    {
        try {
            pm_Session::getClient()->checkSecurityToken('get');
        } catch (pm_Exception $e) {
            $this->messages[] = array('type' => 'error', 'text' => 'Invalid security token.');
            $this->showIndex();
            return;
        }

        $domainId = isset($_POST['domain_id']) ? (int) $_POST['domain_id'] : 0;
        if ($domainId <= 0) {
            $this->messages[] = array('type' => 'error', 'text' => 'Please select a domain.');
            $this->showIndex();
            return;
        }

        $domain = $this->apiClient->getDomain($domainId);
        if (!$domain) {
            $this->messages[] = array('type' => 'error', 'text' => 'Domain not found or not accessible.');
            $this->showIndex();
            return;
        }

        $dbPassword = $this->generatePassword();
        $dbName = $this->safeDbName('indexhibit_' . $domain['domain']);
        $dbUser = $dbName;

        $result = $this->apiClient->createDatabase(
            $domainId,
            $domain['webspace_id'],
            $dbName,
            $dbUser,
            $dbPassword
        );

        if (!$result['success']) {
            $this->messages[] = array('type' => 'error', 'text' => 'Database creation failed: ' . $result['message']);
            $this->showIndex();
            return;
        }

        $deployResult = $this->deployer->deploy(
            $domain['document_root'],
            $domain['domain'],
            array(
                'db_host' => 'localhost',
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'db_password' => $dbPassword,
                'table_prefix' => 'ndxzbt_',
            )
        );

        if (!$deployResult['success']) {
            $this->messages[] = array('type' => 'error', 'text' => 'Deployment failed: ' . $deployResult['message']);
            $this->showIndex();
            return;
        }

        $message = sprintf(
            'Indexhibit installed on %s. Admin URL: %s. Admin username: %s',
            htmlspecialchars($domain['domain']),
            htmlspecialchars($deployResult['admin_url']),
            htmlspecialchars($deployResult['admin_username'])
        );

        $this->messages[] = array(
            'type' => 'success',
            'text' => $message,
            'admin_password' => $deployResult['admin_password'],
        );

        $this->showIndex();
    }

    private function generatePassword($length = 16)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }
}
