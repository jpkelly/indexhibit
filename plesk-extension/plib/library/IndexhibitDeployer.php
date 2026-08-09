<?php

/**
 * Deploys Indexhibit files to a Plesk subscription and triggers the headless
 * auto-install endpoint.
 */
class IndexhibitDeployer
{
    /** @var string */
    private $packagePath;

    public function __construct()
    {
        $this->packagePath = __DIR__ . '/../../data/indexhibit-package.tar.gz';
    }

    /**
     * Deploy Indexhibit and complete the install.
     *
     * @param string $documentRoot
     * @param string $domain
     * @param array $db
     * @return array Result with 'success', 'message', and 'admin_url'.
     */
    public function deploy($documentRoot, $domain, array $db)
    {
        if (!is_dir($documentRoot)) {
            return array('success' => false, 'message' => 'Document root does not exist: ' . $documentRoot);
        }

        if (!file_exists($this->packagePath)) {
            return array('success' => false, 'message' => 'Package not found: ' . $this->packagePath);
        }

        $extractResult = $this->extractPackage($documentRoot);
        if (!$extractResult['success']) {
            return $extractResult;
        }

        $installScript = $documentRoot . '/install.sh';
        if (file_exists($installScript)) {
            $this->runInstallScript($installScript, $documentRoot);
        }

        $endpoint = 'https://' . $domain . '/ndxzstudio/auto-install.php';
        $payload = array(
            'site_name' => $domain,
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
            'admin_email' => 'admin@' . $domain,
            'admin_username' => 'index1',
            'admin_password' => $this->generatePassword(),
            'db_host' => $db['db_host'],
            'db_name' => $db['db_name'],
            'db_user' => $db['db_user'],
            'db_password' => $db['db_password'],
            'table_prefix' => $db['table_prefix'],
            'theme' => 'default',
            'auth_token' => $this->getAuthToken(),
        );

        return $this->callAutoInstallEndpoint($endpoint, $payload);
    }

    private function extractPackage($documentRoot)
    {
        $command = sprintf(
            'tar -xzf %s -C %s',
            escapeshellarg($this->packagePath),
            escapeshellarg($documentRoot)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return array('success' => false, 'message' => 'Failed to extract Indexhibit package.');
        }

        return array('success' => true);
    }

    private function runInstallScript($script, $documentRoot)
    {
        $command = sprintf(
            'bash %s %s',
            escapeshellarg($script),
            escapeshellarg($documentRoot)
        );

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    private function callAutoInstallEndpoint($endpoint, array $payload)
    {
        $ch = curl_init($endpoint);
        $json = json_encode($payload);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return array('success' => false, 'message' => 'HTTP request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return array('success' => false, 'message' => 'Invalid JSON response from install endpoint (HTTP ' . $httpCode . ').');
        }

        if (empty($decoded['success'])) {
            return array('success' => false, 'message' => $decoded['message'] ?? 'Install endpoint reported failure.');
        }

        return array(
            'success' => true,
            'admin_url' => $decoded['details']['admin_url'] ?? 'https://' . $payload['site_name'] . '/ndxzstudio/',
        );
    }

    private function getAuthToken()
    {
        $tokenFile = __DIR__ . '/../../data/auth-token.txt';
        if (file_exists($tokenFile)) {
            return trim(file_get_contents($tokenFile));
        }
        return '';
    }

    private function generatePassword($length = 12)
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
