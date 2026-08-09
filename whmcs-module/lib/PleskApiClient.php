<?php

/**
 * Minimal Plesk XML API client for WHMCS provisioning.
 *
 * This client connects to Plesk from outside the Plesk extension context,
 * using basic auth or an API key. It is intentionally self-contained so it
 * can be bundled with the WHMCS module without Composer.
 *
 * @version 2.1.6
 */
class PleskApiClient
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var string */
    private $apiKey;

    /** @var int seconds */
    public $timeout = 60;

    public function __construct($baseUrl, $username = '', $password = '', $apiKey = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->apiKey = $apiKey;
    }

    /**
     * Find a subscription by domain name.
     *
     * @param string $domain
     * @return array|null Subscription with keys: id, webspace_id, domain, document_root.
     */
    public function findSubscriptionByDomain($domain)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<packet version="1.6.3.5">
  <webspace>
    <get>
      <filter>
        <name>' . $this->escapeXml($domain) . '</name>
      </filter>
      <dataset>
        <gen_info/>
        <hosting/>
      </dataset>
    </get>
  </webspace>
</packet>';

        $response = $this->callXmlApi($xml);
        if (!$response) {
            return null;
        }

        $result = $response->webspace->get->result;
        if ((string) $result->status !== 'ok') {
            return null;
        }

        $id = (int) $result->id;
        $documentRoot = '';
        if (isset($result->data->hosting->vrt_hst->property)) {
            foreach ($result->data->hosting->vrt_hst->property as $property) {
                if ((string) $property->name === 'document_root') {
                    $documentRoot = (string) $property->value;
                    break;
                }
            }
        }

        return array(
            'id' => $id,
            'webspace_id' => $id,
            'domain' => $domain,
            'document_root' => $documentRoot,
        );
    }

    /**
     * Create a MySQL database and user on a subscription.
     *
     * @param int $webspaceId
     * @param string $dbName
     * @param string $dbUser
     * @param string $dbPassword
     * @return array Result with keys: success (bool), message (string), db_id (int|null).
     */
    public function createDatabase($webspaceId, $dbName, $dbUser, $dbPassword)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<packet version="1.6.3.5">
  <database>
    <add-db>
      <webspace-id>' . (int) $webspaceId . '</webspace-id>
      <name>' . $this->escapeXml($dbName) . '</name>
      <type>mysql</type>
    </add-db>
  </database>
</packet>';

        $response = $this->callXmlApi($xml);
        if (!$response || !isset($response->database->{'add-db'}->result)) {
            return array('success' => false, 'message' => 'Invalid Plesk response creating database.', 'db_id' => null);
        }

        $result = $response->database->{'add-db'}->result;
        if ((string) $result->status !== 'ok') {
            return array('success' => false, 'message' => (string) $result->errtext, 'db_id' => null);
        }

        $dbId = (int) $result->id;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<packet version="1.6.3.5">
  <database>
    <add-db-user>
      <db-id>' . $dbId . '</db-id>
      <login>' . $this->escapeXml($dbUser) . '</login>
      <password>' . $this->escapeXml($dbPassword) . '</password>
    </add-db-user>
  </database>
</packet>';

        $response = $this->callXmlApi($xml);
        if (!$response || !isset($response->database->{'add-db-user'}->result)) {
            return array('success' => false, 'message' => 'Invalid Plesk response creating database user.', 'db_id' => $dbId);
        }

        $result = $response->database->{'add-db-user'}->result;
        if ((string) $result->status !== 'ok') {
            return array('success' => false, 'message' => (string) $result->errtext, 'db_id' => $dbId);
        }

        return array('success' => true, 'db_id' => $dbId);
    }

    /**
     * Send a raw XML API request to Plesk.
     *
     * @param string $xml
     * @return SimpleXMLElement|null
     */
    private function callXmlApi($xml)
    {
        $ch = curl_init($this->baseUrl . '/enterprise/control/agent.php');

        $headers = array('Content-Type: text/xml');
        if ($this->apiKey !== '') {
            $headers[] = 'HTTP_AUTH_PASSWD: ' . $this->apiKey;
            $headers[] = 'HTTP_AUTH_LOGIN: admin';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($this->username !== '' && $this->password !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        libxml_use_internal_errors(true);
        $xmlObj = simplexml_load_string($response);
        libxml_use_internal_errors(false);

        return $xmlObj ?: null;
    }

    private function escapeXml($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
    }
}
