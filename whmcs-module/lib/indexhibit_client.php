<?php

/**
 * HTTP client for the Indexhibit headless auto-install endpoint.
 *
 * This file is kept separate from indexhibit.php so it can be unit tested
 * or reused by custom provisioning hooks without loading the full WHMCS
 * module bootstrap.
 *
 * @version 2.1.6
 */
class IndexhibitAutoInstallClient
{
    /** @var string */
    private $endpoint;

    /** @var string */
    private $token;

    /** @var int seconds */
    public $timeout = 60;

    public function __construct($endpoint, $token = '')
    {
        $this->endpoint = rtrim($endpoint, '/');
        $this->token = $token;
    }

    /**
     * Trigger the unattended install.
     *
     * @param array $payload Field names matching ndxzstudio/auto-install.php.
     * @return array Parsed JSON response with at least 'success' and 'message'.
     */
    public function install(array $payload)
    {
        $ch = curl_init($this->endpoint);

        $json = json_encode($payload);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
        ));

        // For self-signed certificates on Plesk dev environments.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return array(
                'success' => false,
                'message' => 'HTTP request failed: ' . $error,
            );
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return array(
                'success' => false,
                'message' => 'Invalid JSON response from install endpoint (HTTP ' . $httpCode . ').',
                'raw'     => $response,
            );
        }

        return $decoded;
    }
}
