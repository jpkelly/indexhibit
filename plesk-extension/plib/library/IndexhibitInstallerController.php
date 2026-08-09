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

    private function showIndex()
    {
        $domains = $this->apiClient->listDomains();

        $csrfToken = pm_Session::getClient()->getSecretKey();

        require __DIR__ . '/../views/scripts/index.phtml';
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
        $dbName = 'indexhibit_' . $domainId;
        $dbUser = 'indexhibit_' . $domainId;

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

        $this->messages[] = array(
            'type' => 'success',
            'text' => 'Indexhibit installed on ' . htmlspecialchars($domain['domain']) . '. Admin URL: ' . htmlspecialchars($deployResult['admin_url'])
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
