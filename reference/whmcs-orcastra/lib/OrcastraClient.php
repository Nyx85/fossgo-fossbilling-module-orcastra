<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class OrcastraClient
{
    private $baseUrl;
    private $keyId;
    private $keySecret;
    private $timeout;
    private $lastRaw = '';

    public function __construct($hostname, $keyId, $keySecret, $timeout = 180)
    {
        $host = trim((string) $hostname);
        if ($host === '') {
            throw new Exception('Orcastra API hostname is required');
        }
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $this->baseUrl = rtrim($host, '/');
        $this->keyId = trim((string) $keyId);
        $this->keySecret = (string) $keySecret;
        $this->timeout = (int) $timeout;
        if ($this->keyId === '' || $this->keySecret === '') {
            throw new Exception('Orcastra API key id and secret are required');
        }
    }

    public function whoami()
    {
        try {
            return $this->request('GET', '/api/v1/integrations/whoami');
        } catch (Exception $e) {
            // RC4 production exposes POST /validate instead of GET /whoami
            return $this->request('POST', '/api/v1/integrations/validate', array(
                'api_key_id' => $this->keyId,
                'api_key_secret' => $this->keySecret,
            ));
        }
    }

    public function listClusters()
    {
        try {
            return $this->request('GET', '/api/v1/integrations/clusters');
        } catch (Exception $e) {
            return $this->request('GET', '/api/v1/integrations/available-clusters');
        }
    }

    public function provisionVmAccess(array $payload)
    {
        return $this->request('POST', '/api/v1/integrations/provision-vm-access', $payload);
    }

    public function revokeVmAccess(array $payload)
    {
        return $this->request('POST', '/api/v1/integrations/revoke-vm-access', $payload);
    }

    /**
     * Mint a short-lived console/terminal ticket and redirect URL (Mini).
     * Body: instance, cluster_id, project, mode, console_type, dashboard_base,
     * instance_type, tab, client_email (optional).
     * Response: ticket, expires_in, redirect_url.
     */
    public function consoleAccess(array $payload)
    {
        return $this->request('POST', '/api/v1/integrations/console-access', $payload);
    }

    public function rawProxy($clusterId, $method, $path, $project, $body = null)
    {
        $payload = array(
            'cluster_id' => $clusterId,
            'method' => strtoupper($method),
            'path' => $path,
            'project' => $project ?: 'default',
        );
        if ($body !== null) {
            $payload['body'] = $body;
        }
        return $this->request('POST', '/api/v1/integrations/raw-proxy', $payload);
    }

    public function proxy($clusterId, $operation, $project, array $params = array(), $wait = true)
    {
        // FastAPI ProxyRequest.params is Dict[str, Any]. json_encode(array())
        // is JSON [] which 422s; empty object {} is required for list_* loaders.
        $body = array(
            'cluster_id' => $clusterId,
            'operation' => $operation,
            'project' => $project ?: 'default',
            'params' => $params === array() ? new \stdClass() : $params,
            'wait' => (bool) $wait,
        );
        $result = $this->request('POST', '/api/v1/integrations/proxy', $body);
        if (isset($result['success']) && $result['success'] === false) {
            $code = isset($result['error_code']) ? $result['error_code'] . ': ' : '';
            $msg = isset($result['error']) ? $result['error'] : 'proxy operation failed';
            throw new Exception($code . $msg);
        }
        return $result;
    }

    public function getLastRaw()
    {
        return $this->lastRaw;
    }

    private function request($method, $path, $body = null)
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $headers = array(
            'Accept: application/json',
            'X-API-Key-ID: ' . $this->keyId,
            'X-API-Key-Secret: ' . $this->keySecret,
        );
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->lastRaw = (string) $raw;

        if ($errno) {
            throw new Exception('Orcastra request failed: ' . $err);
        }

        $decoded = json_decode((string) $raw, true);
        if ($status >= 400) {
            $detail = is_array($decoded) && isset($decoded['detail'])
                ? (is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']))
                : substr((string) $raw, 0, 400);
            throw new Exception('Orcastra HTTP ' . $status . ': ' . $detail);
        }
        if ($decoded === null && $raw !== '' && $raw !== 'null') {
            throw new Exception('Orcastra returned non-JSON');
        }
        return $decoded;
    }
}
