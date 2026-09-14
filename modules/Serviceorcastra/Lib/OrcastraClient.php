<?php

declare(strict_types=1);

/**
 * Copyright 2026 FOSSGO
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Serviceorcastra\Lib;

/**
 * HTTP client for Orcastra integrations API (Mini / production).
 *
 * Credentials:
 * - hostname without scheme (e.g. orcastra.orca.id) — https:// is prepended
 * - api_key_id (oak_…) via X-API-Key-ID
 * - api_key_secret (oas_…) via X-API-Key-Secret
 */
class OrcastraClient
{
    private string $baseUrl;
    private string $keyId;
    private string $keySecret;
    private int $timeout;
    private string $lastRaw = '';

    public function __construct(string $hostname, string $keyId, string $keySecret, int $timeout = 180)
    {
        $host = trim($hostname);
        if ($host === '') {
            throw new \RuntimeException('Orcastra API hostname is required');
        }
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $this->baseUrl = rtrim($host, '/');
        $this->keyId = trim($keyId);
        $this->keySecret = $keySecret;
        $this->timeout = $timeout;
        if ($this->keyId === '' || $this->keySecret === '') {
            throw new \RuntimeException('Orcastra API key id and secret are required');
        }
    }

    public function whoami(): mixed
    {
        try {
            return $this->request('GET', '/api/v1/integrations/whoami');
        } catch (\Throwable $e) {
            // RC4 / some deployments expose POST /validate instead of GET /whoami
            return $this->request('POST', '/api/v1/integrations/validate', [
                'api_key_id' => $this->keyId,
                'api_key_secret' => $this->keySecret,
            ]);
        }
    }

    public function listClusters(): mixed
    {
        try {
            return $this->request('GET', '/api/v1/integrations/clusters');
        } catch (\Throwable $e) {
            return $this->request('GET', '/api/v1/integrations/available-clusters');
        }
    }

    /**
     * Best-effort per-VM tenant policy grant (draft/partial on Mini).
     */
    public function provisionVmAccess(array $payload): mixed
    {
        return $this->request('POST', '/api/v1/integrations/provision-vm-access', $payload);
    }

    /**
     * Best-effort revoke of per-VM tenant policy.
     */
    public function revokeVmAccess(array $payload): mixed
    {
        return $this->request('POST', '/api/v1/integrations/revoke-vm-access', $payload);
    }

    /**
     * Mint a short-lived console/terminal ticket.
     * Response: ticket, expires_in, redirect_url (must contain ticket=).
     */
    public function consoleAccess(array $payload): mixed
    {
        return $this->request('POST', '/api/v1/integrations/console-access', $payload);
    }

    /**
     * Prefer this over high-level proxy for create/list — Mini pylxd proxy can be flaky.
     *
     * @param mixed $body JSON-serializable body for non-GET methods
     */
    public function rawProxy(string $clusterId, string $method, string $path, string $project, mixed $body = null): mixed
    {
        $payload = [
            'cluster_id' => $clusterId,
            'method' => strtoupper($method),
            'path' => $path,
            'project' => $project !== '' ? $project : 'default',
        ];
        if ($body !== null) {
            $payload['body'] = $body;
        }

        return $this->request('POST', '/api/v1/integrations/raw-proxy', $payload);
    }

    /**
     * High-level pylxd-style proxy (fallback for simple ops). Prefer rawProxy for create/list.
     */
    public function proxy(string $clusterId, string $operation, string $project, array $params = [], bool $wait = true): mixed
    {
        $body = [
            'cluster_id' => $clusterId,
            'operation' => $operation,
            'project' => $project !== '' ? $project : 'default',
            // FastAPI ProxyRequest.params is Dict — empty object {}, not []
            'params' => $params === [] ? new \stdClass() : $params,
            'wait' => $wait,
        ];
        $result = $this->request('POST', '/api/v1/integrations/proxy', $body);
        if (is_array($result) && isset($result['success']) && $result['success'] === false) {
            $code = isset($result['error_code']) ? $result['error_code'] . ': ' : '';
            $msg = isset($result['error']) ? (string) $result['error'] : 'proxy operation failed';
            throw new \RuntimeException($code . $msg);
        }

        return $result;
    }

    public function getLastRaw(): string
    {
        return $this->lastRaw;
    }

    private function request(string $method, string $path, mixed $body = null): mixed
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Orcastra: unable to init curl');
        }

        $headers = [
            'Accept: application/json',
            'X-API-Key-ID: ' . $this->keyId,
            'X-API-Key-Secret: ' . $this->keySecret,
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->lastRaw = is_string($raw) ? $raw : '';

        if ($errno) {
            throw new \RuntimeException('Orcastra request failed: ' . $err);
        }

        $decoded = json_decode($this->lastRaw, true);
        if ($status >= 400) {
            $detail = is_array($decoded) && isset($decoded['detail'])
                ? (is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']))
                : substr($this->lastRaw, 0, 400);
            throw new \RuntimeException('Orcastra HTTP ' . $status . ': ' . $detail);
        }
        if ($decoded === null && $this->lastRaw !== '' && $this->lastRaw !== 'null') {
            throw new \RuntimeException('Orcastra returned non-JSON');
        }

        return $decoded;
    }
}
