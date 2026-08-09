<?php

/**
 * Deploys Indexhibit files to a Plesk subscription and triggers the headless
 * auto-install endpoint.
 */
class IndexhibitDeployer
{
    /** @var string */
    private $packagePath;

    /** @var string */
    private $systemUser;

    public function __construct($packagePath = '', $systemUser = '')
    {
        $this->packagePath = $packagePath ?: __DIR__ . '/../../data/indexhibit-package.tar.gz';
        $this->systemUser = $systemUser;
    }

    /**
     * Deploy Indexhibit and complete the install.
     *
     * @param string $documentRoot
     * @param string $domain
     * @param array $db
     * @return array Result with 'success', 'message', 'admin_url', 'admin_username', 'admin_password'.
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
        } else {
            $this->applyDefaultPermissions($documentRoot);
        }

        $adminUsername = !empty($db['admin_username']) ? $db['admin_username'] : 'index1';
        $adminPassword = !empty($db['admin_password']) ? $db['admin_password'] : $this->generatePassword();

        $endpoint = 'https://' . $domain . '/ndxzstudio/auto-install.php';
        $payload = array(
            'site_name' => $domain,
            'admin_first_name' => 'Admin',
            'admin_last_name' => 'User',
            'admin_email' => 'admin@' . $domain,
            'admin_username' => $adminUsername,
            'admin_password' => $adminPassword,
            'db_host' => $db['db_host'],
            'db_name' => $db['db_name'],
            'db_user' => $db['db_user'],
            'db_password' => $db['db_password'],
            'table_prefix' => $db['table_prefix'],
            'theme' => !empty($db['theme']) ? $db['theme'] : 'default',
            'auth_token' => $this->getAuthToken(),
        );

        $result = $this->callAutoInstallEndpoint($endpoint, $payload);
        if (!$result['success']) {
            return $result;
        }

        $result['admin_username'] = $adminUsername;
        $result['admin_password'] = $adminPassword;

        return $result;
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

        if ($this->systemUser !== '') {
            $command = sprintf('su -s /bin/bash %s -c %s', escapeshellarg($this->systemUser), escapeshellarg($command));
        }

        exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Fallback when install.sh is missing: rename htaccess and set writable dirs.
     *
     * @param string $documentRoot
     */
    private function applyDefaultPermissions($documentRoot)
    {
        $htaccess = $documentRoot . '/htaccess';
        if (file_exists($htaccess) && !file_exists($documentRoot . '/.htaccess')) {
            rename($htaccess, $documentRoot . '/.htaccess');
        }

        $writable = array(
            $documentRoot . '/files',
            $documentRoot . '/files/gimgs',
            $documentRoot . '/files/dimgs',
            $documentRoot . '/ndxzsite/config',
        );

        foreach ($writable as $path) {
            if (is_dir($path)) {
                chmod($path, 0755);
            }
        }
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
