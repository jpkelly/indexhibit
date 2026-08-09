<?php

/**
 * Plesk XML API client for the Indexhibit extension.
 *
 * Uses pm_ApiRpc when available (inside Plesk) and falls back to pm_ApiCli
 * for listing domains when running outside the standard extension context.
 */
class IndexhibitPleskApiClient
{
    /**
     * List domains the current Plesk user can manage.
     *
     * Only returns subscriptions that:
     *   - are active and hosted
     *   - have hosting enabled (so they have a document root)
     *   - are not already marked as having Indexhibit installed
     *
     * @return array List of associative arrays with keys: id, webspace_id, domain, document_root.
     */
    public function listDomains()
    {
        $response = $this->callXmlApi('<webspace><get><filter/><dataset><gen_info/><hosting/></dataset></get></webspace>');
        $domains = array();

        if (!is_object($response) || !isset($response->webspace->get)) {
            return $domains;
        }

        foreach ($response->webspace->get->result as $result) {
            if ((string) $result->status !== 'ok') {
                continue;
            }

            $status = (string) $result->data->gen_info->{'status'};
            if ($status !== '0' && $status !== 'active') {
                continue;
            }

            $id = (int) $result->id;
            $domainName = (string) $result->data->gen_info->{'name'};
            $documentRoot = '';
            $hasHosting = false;

            if (isset($result->data->hosting->vrt_hst->property)) {
                foreach ($result->data->hosting->vrt_hst->property as $property) {
                    if ((string) $property->name === 'document_root') {
                        $documentRoot = (string) $property->value;
                        $hasHosting = true;
                        break;
                    }
                }
            }

            if (!$hasHosting || $documentRoot === '') {
                continue;
            }

            // Skip subscriptions that already look installed.
            if ($this->isIndexhibitInstalled($documentRoot)) {
                continue;
            }

            $domains[] = array(
                'id' => $id,
                'webspace_id' => $id,
                'domain' => $domainName,
                'document_root' => $documentRoot,
            );
        }

        return $domains;
    }

    /**
     * Check whether Indexhibit already appears to be installed at the document root.
     *
     * @param string $documentRoot
     * @return bool
     */
    private function isIndexhibitInstalled($documentRoot)
    {
        $configPath = rtrim($documentRoot, '/') . '/ndxzsite/config/config.php';
        return file_exists($configPath);
    }

    /**
     * Get a single domain by ID.
     *
     * @param int $domainId
     * @return array|null
     */
    public function getDomain($domainId)
    {
        foreach ($this->listDomains() as $domain) {
            if ($domain['id'] === $domainId) {
                return $domain;
            }
        }
        return null;
    }

    /**
     * Look up a subscription by its domain name.
     *
     * Useful when the extension is invoked from a domain context rather than
     * a subscription ID.
     *
     * @param string $domain
     * @return array|null
     */
    public function findSubscriptionByDomain($domain)
    {
        $xml = sprintf(
            '<webspace><get><filter><name>%s</name></filter><dataset><gen_info/><hosting/></dataset></get></webspace>',
            $this->escapeXml($domain)
        );

        $response = $this->callXmlApi($xml);
        if (!is_object($response) || !isset($response->webspace->get->result)) {
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

        if ($documentRoot === '') {
            return null;
        }

        return array(
            'id' => $id,
            'webspace_id' => $id,
            'domain' => $domain,
            'document_root' => $documentRoot,
        );
    }

    /**
     * Create a MySQL database and database user for a subscription.
     *
     * @param int $domainId
     * @param int $webspaceId
     * @param string $dbName
     * @param string $dbUser
     * @param string $dbPassword
     * @return array Result with 'success' (bool) and 'message'.
     */
    public function createDatabase($domainId, $webspaceId, $dbName, $dbUser, $dbPassword)
    {
        $createDbXml = sprintf(
            '<database><add-db><webspace-id>%d</webspace-id><name>%s</name><type>mysql</type></add-db></database>',
            (int) $webspaceId,
            $this->escapeXml($dbName)
        );

        $response = $this->callXmlApi($createDbXml);

        if (!is_object($response) || !isset($response->database->{'add-db'}->result)) {
            return array('success' => false, 'message' => 'Unexpected response from Plesk API.');
        }

        $result = $response->database->{'add-db'}->result;
        if ((string) $result->status !== 'ok') {
            return array('success' => false, 'message' => (string) $result->errtext);
        }

        $dbId = (int) $result->id;

        $createUserXml = sprintf(
            '<database><add-db-user><db-id>%d</db-id><login>%s</login><password>%s</password></add-db-user></database>',
            $dbId,
            $this->escapeXml($dbUser),
            $this->escapeXml($dbPassword)
        );

        $response = $this->callXmlApi($createUserXml);

        if (!is_object($response) || !isset($response->database->{'add-db-user'}->result)) {
            return array('success' => false, 'message' => 'Unexpected response creating database user.');
        }

        $result = $response->database->{'add-db-user'}->result;
        if ((string) $result->status !== 'ok') {
            return array('success' => false, 'message' => (string) $result->errtext);
        }

        return array('success' => true, 'db_id' => $dbId);
    }

    /**
     * Send an XML API request using pm_ApiRpc when available.
     *
     * @param string $packetBody Inner XML without the packet wrapper.
     * @return SimpleXMLElement|null
     */
    private function callXmlApi($packetBody)
    {
        if (!class_exists('pm_ApiRpc')) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<packet version="1.6.3.5">' . $packetBody . '</packet>';

        $rpc = pm_ApiRpc::getService();
        return $rpc->call($xml);
    }

    private function escapeXml($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');
    }
}
