<?php

declare(strict_types=1);

/**
 * Copyright 2026 FOSSGO
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Serviceorcastra;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Product\Entity\Product;
use Box\Mod\Serviceorcastra\Entity\ServiceOrcastra;
use Box\Mod\Serviceorcastra\Lib\OrcastraClient;
use FOSSBilling\InjectionAwareInterface;

/**
 * FOSSBilling product-type service for Orcastra VM/container (MVP, no VDC).
 *
 * FOSSBilling treats non-core service modules as third-party: Order dispatches
 * unprefixed create/activate/suspend/… with ($order, $serviceRow). Built-in
 * style action_* methods are kept as aliases for Servicecustom parity.
 */
class Service implements InjectionAwareInterface
{
    public const TABLE = 'service_orcastra';

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage' => [
                'type' => 'bool',
                'display_name' => 'Manage Orcastra services',
                'description' => 'Allows staff to update Orcastra service config and call power/console APIs.',
            ],
        ];
    }

    /**
     * Create service_orcastra table (Doctrine entity sync may also apply on install).
     */
    public function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) DEFAULT NULL,
            `instance_name` varchar(64) DEFAULT NULL,
            `cluster_id` varchar(128) DEFAULT NULL,
            `project` varchar(64) DEFAULT NULL,
            `instance_type` varchar(32) DEFAULT NULL,
            `status` varchar(32) DEFAULT NULL,
            `config` text,
            `created_at` datetime DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `service_orcastra_client_id_idx` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        try {
            if (isset($this->di['db']) && method_exists($this->di['db'], 'exec')) {
                $this->di['db']->exec($sql);
            } elseif (isset($this->di['em'])) {
                $this->di['em']->getConnection()->executeStatement($sql);
            }
        } catch (\Throwable $e) {
            // PostgreSQL / already-synced Doctrine schema — ignore duplicate
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('serviceorcastra install: ' . $e->getMessage());
            }
        }

        // Prefer Doctrine additive sync when available (FOSSBilling 0.8+)
        try {
            if (isset($this->di['em']) && class_exists(\FOSSBilling\Doctrine\SchemaSynchronizer::class)) {
                \FOSSBilling\Doctrine\SchemaSynchronizer::syncEntities(
                    $this->di['em'],
                    [ServiceOrcastra::class]
                );
            }
        } catch (\Throwable $e) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('serviceorcastra schema sync: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        try {
            $sql = 'DROP TABLE IF EXISTS `' . self::TABLE . '`';
            if (isset($this->di['db']) && method_exists($this->di['db'], 'exec')) {
                $this->di['db']->exec($sql);
            } elseif (isset($this->di['em'])) {
                $this->di['em']->getConnection()->executeStatement($sql);
            }
        } catch (\Throwable $e) {
            // leave table if drop fails
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Third-party Order hooks (unprefixed) + Servicecustom-style aliases
    // -------------------------------------------------------------------------

    public function create(Order $order, $service = null): ServiceOrcastra
    {
        return $this->action_create($order);
    }

    public function activate(Order $order, $service = null): bool
    {
        return $this->action_activate($order);
    }

    public function suspend(Order $order, $service = null): bool
    {
        return $this->action_suspend($order);
    }

    public function unsuspend(Order $order, $service = null): bool
    {
        return $this->action_unsuspend($order);
    }

    public function cancel(Order $order, $service = null): bool
    {
        return $this->action_cancel($order);
    }

    public function uncancel(Order $order, $service = null): bool
    {
        return $this->action_unsuspend($order);
    }

    public function delete(Order $order, $service = null): bool
    {
        return $this->action_delete($order);
    }

    public function renew(Order $order, $service = null): bool
    {
        return $this->action_renew($order);
    }

    public function action_create(Order $order): ServiceOrcastra
    {
        $product = $this->di['mod_service']('product')->findProductById((int) $order->getProductId());
        if (!$product instanceof Product) {
            throw new \FOSSBilling\InformationException('Product not found');
        }

        $productConfig = $this->decodeJson($product->getConfig());
        $orderConfig = $this->decodeJson($order->getConfig());
        $merged = array_merge($productConfig, is_array($orderConfig) ? $orderConfig : []);

        $model = new ServiceOrcastra();
        $model->setClientId((int) $order->getClientId());
        $model->setClusterId((string) ($merged['cluster_id'] ?? ''));
        $model->setProject((string) ($merged['project'] ?? 'default') ?: 'default');
        $model->setInstanceType($this->normalizeInstanceType((string) ($merged['instance_type'] ?? 'virtual-machine')));
        $model->setStatus('pending');
        $model->setConfig(json_encode($merged, JSON_UNESCAPED_SLASHES));

        $this->persistModel($model);

        return $model;
    }

    public function action_activate(Order $order): bool
    {
        $model = $this->requireModel($order);
        if ($model->getInstanceName()) {
            // Already provisioned (retry after failed_setup edge cases)
            return true;
        }

        $cfg = $this->mergedConfig($order, $model);
        $client = $this->buildClientFromConfig($cfg);
        $cluster = trim((string) ($cfg['cluster_id'] ?? ''));
        $project = trim((string) ($cfg['project'] ?? 'default')) ?: 'default';
        $type = $this->normalizeInstanceType((string) ($cfg['instance_type'] ?? 'virtual-machine'));
        $image = trim((string) ($cfg['image'] ?? $cfg['os'] ?? 'ubuntu:24.04'));
        $cpu = preg_replace('/[^0-9]/', '', (string) ($cfg['cpu'] ?? '2')) ?: '2';
        $memory = $this->normalizeSize((string) ($cfg['memory'] ?? '2GB'), 'GB');
        $disk = $this->normalizeSize((string) ($cfg['disk'] ?? '20GB'), 'GB');
        $pool = trim((string) ($cfg['storage_pool'] ?? $cfg['pool'] ?? 'nvme')) ?: 'nvme';
        $profileRaw = (string) ($cfg['profiles'] ?? $cfg['profile'] ?? 'default');
        $network = trim((string) ($cfg['network'] ?? ''));

        if ($cluster === '') {
            throw new \FOSSBilling\InformationException('Product is missing cluster_id');
        }

        $name = $this->sanitizeInstanceName('vm' . (int) $order->getId());
        $profiles = array_values(array_filter(array_map('trim', explode(',', $profileRaw))));
        if ($profiles === []) {
            $profiles = ['default'];
        }

        $email = $this->clientEmail((int) $order->getClientId());

        $createBody = [
            'name' => $name,
            'type' => $type,
            'source' => $this->imageSource($image),
            'config' => [
                'limits.cpu' => (string) $cpu,
                'limits.memory' => (string) $memory,
                'user.orcastra.fossbilling_order' => (string) $order->getId(),
                'user.orcastra.client_email' => $email,
            ],
            'devices' => [
                'root' => [
                    'type' => 'disk',
                    'path' => '/',
                    'pool' => $pool,
                    'size' => (string) $disk,
                ],
            ],
            'profiles' => $profiles,
        ];
        if ($network !== '') {
            $createBody['devices']['eth0'] = [
                'type' => 'nic',
                'network' => $network,
            ];
        }

        $createdName = $name;
        try {
            // Prefer raw-proxy POST /1.0/instances (Mini high-level proxy is flaky)
            $result = $client->rawProxy($cluster, 'POST', '/1.0/instances', $project, $createBody);
            $createdName = $this->extractCreatedName($result, $name);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $adopt = (stripos($msg, 'already exists') !== false)
                || (stripos($msg, 'timed out') !== false)
                || (stripos($msg, 'timeout') !== false);
            if (!$adopt) {
                throw new \FOSSBilling\Exception('Failed to create instance: ' . $msg);
            }
            try {
                $info = $client->rawProxy(
                    $cluster,
                    'GET',
                    '/1.0/instances/' . rawurlencode($name),
                    $project
                );
                if (!$this->rawHasMetadata($info)) {
                    throw $e;
                }
                $createdName = $name;
            } catch (\Throwable $e2) {
                throw new \FOSSBilling\Exception('Failed to create instance: ' . $msg);
            }
        }

        try {
            $client->rawProxy(
                $cluster,
                'PUT',
                '/1.0/instances/' . rawurlencode($createdName) . '/state',
                $project,
                ['action' => 'start', 'timeout' => 120, 'force' => false, 'stateful' => false]
            );
        } catch (\Throwable $e) {
            // create may already start depending on LXD defaults
        }

        $model->setInstanceName($createdName);
        $model->setClusterId($cluster);
        $model->setProject($project);
        $model->setInstanceType($type);
        $model->setStatus('active');
        $cfg['instance_name'] = $createdName;
        $cfg['cluster_id'] = $cluster;
        $cfg['project'] = $project;
        $cfg['instance_type'] = $type;
        $model->setConfig(json_encode($cfg, JSON_UNESCAPED_SLASHES));
        $this->persistModel($model);

        $this->provisionVmAccessBestEffort($client, $cfg, $email, $cluster, $project, $createdName, (string) $order->getId());

        return true;
    }

    public function action_suspend(Order $order): bool
    {
        return $this->lifecycle($order, 'stop', 'suspended', true);
    }

    public function action_unsuspend(Order $order): bool
    {
        return $this->lifecycle($order, 'start', 'active', false);
    }

    public function action_cancel(Order $order): bool
    {
        return $this->terminateInstance($order, false);
    }

    public function action_delete(Order $order): bool
    {
        return $this->terminateInstance($order, true);
    }

    public function action_renew(Order $order): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Client / admin helpers
    // -------------------------------------------------------------------------

    public function startInstance(Order $order): bool
    {
        return $this->lifecycle($order, 'start', 'active', false);
    }

    public function stopInstance(Order $order): bool
    {
        return $this->lifecycle($order, 'stop', 'stopped', true);
    }

    public function restartInstance(Order $order): bool
    {
        return $this->lifecycle($order, 'restart', 'active', true);
    }

    /**
     * Server-side mint via console-access; returns redirect_url with ticket=.
     * Never returns a bare /terminal or /console URL.
     */
    public function mintConsoleRedirect(Order $order, string $mode = 'console'): string
    {
        $model = $this->requireModel($order);
        $name = $model->getInstanceName();
        if (!$name) {
            throw new \FOSSBilling\InformationException('Instance has not been provisioned yet.');
        }

        $cfg = $this->mergedConfig($order, $model);
        $type = $this->normalizeInstanceType((string) ($model->getInstanceType() ?: ($cfg['instance_type'] ?? 'virtual-machine')));
        $isContainer = ($type === 'container');

        // Text terminal needs guest agent; for VMs open graphics console instead.
        if ($mode === 'terminal' && !$isContainer) {
            $mode = 'console';
        }
        $mode = ($mode === 'terminal') ? 'terminal' : 'console';
        $tab = $isContainer ? 'text' : 'graphics';

        $dashboard = rtrim((string) ($cfg['dashboard_base'] ?? $cfg['dashboard_url'] ?? 'https://orcastra.orca.id'), '/');
        if ($dashboard === '') {
            $dashboard = 'https://orcastra.orca.id';
        }

        $payload = [
            'instance' => $name,
            'cluster_id' => (string) $model->getClusterId(),
            'project' => (string) ($model->getProject() ?: 'default'),
            'mode' => $mode,
            'console_type' => 'vga',
            'dashboard_base' => $dashboard,
            'instance_type' => $type,
            'tab' => $tab,
        ];
        $email = $this->clientEmail((int) $order->getClientId());
        if ($email !== '') {
            $payload['client_email'] = $email;
        }

        $client = $this->buildClientFromConfig($cfg);
        $res = $client->consoleAccess($payload);
        if (!is_array($res) || empty($res['redirect_url'])) {
            throw new \FOSSBilling\Exception('Console access API did not return redirect_url');
        }
        $url = (string) $res['redirect_url'];
        if (strpos($url, 'ticket=') === false) {
            throw new \FOSSBilling\Exception('Console access redirect_url missing ticket');
        }

        return $url;
    }

    public function testConnection(array $cfg): array
    {
        try {
            $client = $this->buildClientFromConfig($cfg);
            $me = $client->whoami();

            return [
                'success' => true,
                'whoami' => is_array($me) ? $me : ['raw' => $me],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getConfig(ServiceOrcastra|array $model): array
    {
        if (is_array($model)) {
            return $this->decodeJson($model['config'] ?? null);
        }

        return $this->decodeJson($model->getConfig());
    }

    public function toApiArray(ServiceOrcastra|array $model, $deep = true, $identity = null): array
    {
        if (is_array($model)) {
            $cfg = $this->decodeJson($model['config'] ?? null);
            $data = array_merge($cfg, [
                'id' => isset($model['id']) ? (int) $model['id'] : null,
                'client_id' => isset($model['client_id']) ? (int) $model['client_id'] : null,
                'instance_name' => $model['instance_name'] ?? ($cfg['instance_name'] ?? null),
                'cluster_id' => $model['cluster_id'] ?? ($cfg['cluster_id'] ?? null),
                'project' => $model['project'] ?? ($cfg['project'] ?? 'default'),
                'instance_type' => $model['instance_type'] ?? ($cfg['instance_type'] ?? null),
                'status' => $model['status'] ?? ($cfg['status'] ?? null),
                'created_at' => $model['created_at'] ?? null,
                'updated_at' => $model['updated_at'] ?? null,
            ]);
            // Never leak secrets to client/admin API arrays
            unset($data['api_key_secret'], $data['api_key_id'], $data['key_secret'], $data['key_id']);

            return $data;
        }

        $cfg = $this->getConfig($model);
        $data = array_merge($cfg, [
            'id' => $model->getId(),
            'client_id' => $model->getClientId(),
            'instance_name' => $model->getInstanceName(),
            'cluster_id' => $model->getClusterId(),
            'project' => $model->getProject(),
            'instance_type' => $model->getInstanceType(),
            'status' => $model->getStatus(),
            'updated_at' => $model->getUpdatedAt()?->format('Y-m-d H:i:s'),
            'created_at' => $model->getCreatedAt()?->format('Y-m-d H:i:s'),
        ]);
        unset($data['api_key_secret'], $data['api_key_id'], $data['key_secret'], $data['key_id']);

        return $data;
    }

    public function updateConfig(int $orderId, array $config): void
    {
        $order = $this->di['em']->getRepository(Order::class)->find($orderId);
        if (!$order instanceof Order) {
            throw new \FOSSBilling\InformationException('Order not found');
        }
        $model = $this->requireModel($order);
        $current = $this->getConfig($model);
        // Do not wipe secrets if form omitted them
        foreach (['api_key_secret', 'api_key_id'] as $secretKey) {
            if (!isset($config[$secretKey]) || $config[$secretKey] === '') {
                if (isset($current[$secretKey])) {
                    $config[$secretKey] = $current[$secretKey];
                }
            }
        }
        $merged = array_merge($current, $config);
        if (!empty($merged['instance_name'])) {
            $model->setInstanceName($this->sanitizeInstanceName((string) $merged['instance_name']));
        }
        if (isset($merged['cluster_id'])) {
            $model->setClusterId((string) $merged['cluster_id']);
        }
        if (isset($merged['project'])) {
            $model->setProject((string) $merged['project']);
        }
        if (isset($merged['instance_type'])) {
            $model->setInstanceType($this->normalizeInstanceType((string) $merged['instance_type']));
        }
        if (isset($merged['status'])) {
            $model->setStatus((string) $merged['status']);
        }
        $model->setConfig(json_encode($merged, JSON_UNESCAPED_SLASHES));
        $this->persistModel($model);
        if (isset($this->di['logger'])) {
            $this->di['logger']->info('Orcastra service updated #{id}', ['id' => $model->getId()]);
        }
    }

    /**
     * Load service for an order (entity preferred; falls back to DBAL row).
     */
    public function getServiceByOrderId(int $orderId, ?int $clientId = null): ServiceOrcastra|array
    {
        $orderService = $this->di['mod_service']('order');
        if ($clientId !== null) {
            $order = $this->di['em']->getRepository(Order::class)->findOneBy([
                'id' => $orderId,
                'clientId' => $clientId,
            ]);
            if (!$order instanceof Order) {
                throw new \FOSSBilling\InformationException('Order not found');
            }
            $orderService->assertOrderUsable($order);
            if ($order->getStatus() !== Order::STATUS_ACTIVE) {
                throw new \FOSSBilling\InformationException('Order is not activated');
            }
        } else {
            $order = $this->di['em']->getRepository(Order::class)->find($orderId);
            if (!$order instanceof Order) {
                throw new \FOSSBilling\InformationException('Order not found');
            }
        }

        return $this->requireModel($order);
    }

    public function getOrderForServiceCall(int $orderId, ?int $clientId = null): Order
    {
        if ($clientId !== null) {
            $order = $this->di['em']->getRepository(Order::class)->findOneBy([
                'id' => $orderId,
                'clientId' => $clientId,
            ]);
        } else {
            $order = $this->di['em']->getRepository(Order::class)->find($orderId);
        }
        if (!$order instanceof Order) {
            throw new \FOSSBilling\InformationException('Order not found');
        }
        if ($clientId !== null) {
            $this->di['mod_service']('order')->assertOrderUsable($order);
            if ($order->getStatus() !== Order::STATUS_ACTIVE) {
                throw new \FOSSBilling\InformationException('Order is not activated');
            }
        }

        return $order;
    }

    /**
     * Default product config schema keys for admin UI / docs.
     */
    public function getProductConfigDefaults(): array
    {
        return [
            'api_hostname' => 'orcastra.orca.id',
            'api_key_id' => '',
            'api_key_secret' => '',
            'cluster_id' => 'central-1',
            'project' => 'default',
            'instance_type' => 'virtual-machine',
            'image' => 'ubuntu:24.04',
            'cpu' => '2',
            'memory' => '2GB',
            'disk' => '20GB',
            'storage_pool' => 'nvme',
            'profiles' => 'default',
            'network' => '',
            'dashboard_base' => 'https://orcastra.orca.id',
            'tenant_org' => '',
        ];
    }

    public function buildClientFromConfig(array $cfg): OrcastraClient
    {
        $modCfg = [];
        try {
            $modCfg = $this->di['mod']('serviceorcastra')->getConfig();
            if (!is_array($modCfg)) {
                $modCfg = [];
            }
        } catch (\Throwable $e) {
            $modCfg = [];
        }

        $hostname = trim((string) ($cfg['api_hostname'] ?? $cfg['hostname'] ?? $modCfg['api_hostname'] ?? 'orcastra.orca.id'));
        $keyId = trim((string) ($cfg['api_key_id'] ?? $cfg['key_id'] ?? $modCfg['api_key_id'] ?? ''));
        $keySecret = (string) ($cfg['api_key_secret'] ?? $cfg['key_secret'] ?? $modCfg['api_key_secret'] ?? '');

        return new OrcastraClient($hostname, $keyId, $keySecret);
    }

    /**
     * Alnum, dot, hyphen, underscore only; strip query junk like ?project=.
     */
    public function sanitizeInstanceName(string $name): string
    {
        $name = trim($name);
        if (($q = strpos($name, '?')) !== false) {
            $name = substr($name, 0, $q);
        }
        if (($h = strpos($name, '#')) !== false) {
            $name = substr($name, 0, $h);
        }
        if (($s = strpos($name, '/')) !== false) {
            // never allow path segments in names
            $parts = explode('/', $name);
            $name = (string) end($parts);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \FOSSBilling\Exception('Invalid instance name');
        }

        return substr($name, 0, 63);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function terminateInstance(Order $order, bool $removeRow): bool
    {
        try {
            $model = $this->requireModel($order);
        } catch (\Throwable $e) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->error($e->getMessage());
            }

            return true;
        }

        $name = $model->getInstanceName();
        if ($name) {
            $cfg = $this->mergedConfig($order, $model);
            try {
                $client = $this->buildClientFromConfig($cfg);
                $cluster = (string) $model->getClusterId();
                $project = (string) ($model->getProject() ?: 'default');
                try {
                    $client->rawProxy(
                        $cluster,
                        'PUT',
                        '/1.0/instances/' . rawurlencode($name) . '/state',
                        $project,
                        ['action' => 'stop', 'timeout' => 60, 'force' => true, 'stateful' => false]
                    );
                } catch (\Throwable $e) {
                    // already stopped is fine
                }
                $client->rawProxy(
                    $cluster,
                    'DELETE',
                    '/1.0/instances/' . rawurlencode($name),
                    $project
                );
                $this->revokeVmAccessBestEffort(
                    $client,
                    $cfg,
                    $this->clientEmail((int) $order->getClientId()),
                    $cluster,
                    $project,
                    $name,
                    (string) $order->getId()
                );
            } catch (\Throwable $e) {
                throw new \FOSSBilling\Exception('Failed to terminate instance: ' . $e->getMessage());
            }
        }

        if ($removeRow) {
            $this->removeModel($model);
        } else {
            $model->setStatus('cancelled');
            $this->persistModel($model);
        }

        return true;
    }

    private function lifecycle(Order $order, string $action, string $status, bool $force): bool
    {
        $model = $this->requireModel($order);
        $name = $model->getInstanceName();
        if (!$name) {
            throw new \FOSSBilling\InformationException('Instance record not found / not provisioned');
        }
        $cfg = $this->mergedConfig($order, $model);
        $client = $this->buildClientFromConfig($cfg);
        $cluster = (string) $model->getClusterId();
        $project = (string) ($model->getProject() ?: 'default');

        $client->rawProxy(
            $cluster,
            'PUT',
            '/1.0/instances/' . rawurlencode($name) . '/state',
            $project,
            [
                'action' => $action,
                'timeout' => 120,
                'force' => $force,
                'stateful' => false,
            ]
        );
        $model->setStatus($status);
        $this->persistModel($model);

        return true;
    }

    private function provisionVmAccessBestEffort(
        OrcastraClient $client,
        array $cfg,
        string $email,
        string $cluster,
        string $project,
        string $instanceName,
        string $orderId
    ): void {
        if ($email === '') {
            return;
        }
        try {
            $payload = [
                'user_email' => $email,
                'cluster_id' => $cluster,
                'project' => $project,
                'instance_name' => $instanceName,
                'service_id' => $orderId,
                'permissions' => ['view', 'operate', 'console'],
            ];
            $orgId = trim((string) ($cfg['tenant_org'] ?? $cfg['organization_id'] ?? ''));
            if ($orgId !== '' && ctype_digit($orgId)) {
                $payload['organization_id'] = (int) $orgId;
            }
            $client->provisionVmAccess($payload);
        } catch (\Throwable $e) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('serviceorcastra provisionVmAccess soft-fail: ' . $e->getMessage());
            }
        }
    }

    private function revokeVmAccessBestEffort(
        OrcastraClient $client,
        array $cfg,
        string $email,
        string $cluster,
        string $project,
        string $instanceName,
        string $orderId
    ): void {
        try {
            $payload = [
                'user_email' => $email,
                'cluster_id' => $cluster,
                'project' => $project,
                'instance_name' => $instanceName,
                'service_id' => $orderId,
            ];
            $orgId = trim((string) ($cfg['tenant_org'] ?? $cfg['organization_id'] ?? ''));
            if ($orgId !== '' && ctype_digit($orgId)) {
                $payload['organization_id'] = (int) $orgId;
            }
            $client->revokeVmAccess($payload);
        } catch (\Throwable $e) {
            if (isset($this->di['logger'])) {
                $this->di['logger']->info('serviceorcastra revokeVmAccess soft-fail: ' . $e->getMessage());
            }
        }
    }

    private function requireModel(Order $order): ServiceOrcastra
    {
        $serviceId = $order->getServiceId();
        if (!$serviceId) {
            throw new \FOSSBilling\Exception('Order :id has no active service', [':id' => $order->getId()]);
        }

        // Prefer Doctrine entity when mapped
        try {
            $model = $this->di['em']->find(ServiceOrcastra::class, (int) $serviceId);
            if ($model instanceof ServiceOrcastra) {
                return $model;
            }
        } catch (\Throwable $e) {
            // fall through to SQL hydrate
        }

        $row = $this->di['em']->getConnection()->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => (int) $serviceId]
        );
        if (!$row) {
            throw new \FOSSBilling\Exception('Order :id has no active service', [':id' => $order->getId()]);
        }

        return $this->hydrateFromRow($row);
    }

    private function hydrateFromRow(array $row): ServiceOrcastra
    {
        $model = new ServiceOrcastra();
        $ref = new \ReflectionClass($model);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($model, isset($row['id']) ? (int) $row['id'] : null);
        $model->setClientId(isset($row['client_id']) ? (int) $row['client_id'] : null);
        $model->setInstanceName($row['instance_name'] ?? null);
        $model->setClusterId($row['cluster_id'] ?? null);
        $model->setProject($row['project'] ?? null);
        $model->setInstanceType($row['instance_type'] ?? null);
        $model->setStatus($row['status'] ?? null);
        $model->setConfig($row['config'] ?? null);

        return $model;
    }

    private function persistModel(ServiceOrcastra $model): void
    {
        try {
            $this->di['em']->persist($model);
            $this->di['em']->flush();

            return;
        } catch (\Throwable $e) {
            // Fall back to raw SQL upsert for environments without entity mapping
        }

        $conn = $this->di['em']->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $data = [
            'client_id' => $model->getClientId(),
            'instance_name' => $model->getInstanceName(),
            'cluster_id' => $model->getClusterId(),
            'project' => $model->getProject(),
            'instance_type' => $model->getInstanceType(),
            'status' => $model->getStatus(),
            'config' => $model->getConfig(),
            'updated_at' => $now,
        ];
        if ($model->getId()) {
            $conn->update(self::TABLE, $data, ['id' => $model->getId()]);
        } else {
            $data['created_at'] = $now;
            $conn->insert(self::TABLE, $data);
            $id = (int) $conn->lastInsertId();
            $ref = new \ReflectionClass($model);
            $idProp = $ref->getProperty('id');
            $idProp->setAccessible(true);
            $idProp->setValue($model, $id);
        }
    }

    private function removeModel(ServiceOrcastra $model): void
    {
        try {
            $managed = $model->getId()
                ? $this->di['em']->find(ServiceOrcastra::class, $model->getId())
                : null;
            if ($managed instanceof ServiceOrcastra) {
                $this->di['em']->remove($managed);
                $this->di['em']->flush();

                return;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        if ($model->getId()) {
            $this->di['em']->getConnection()->delete(self::TABLE, ['id' => $model->getId()]);
        }
    }

    private function mergedConfig(Order $order, ServiceOrcastra $model): array
    {
        $productConfig = [];
        try {
            $product = $this->di['mod_service']('product')->findProductById((int) $order->getProductId());
            if ($product instanceof Product) {
                $productConfig = $this->decodeJson($product->getConfig());
            }
        } catch (\Throwable $e) {
            $productConfig = [];
        }
        $orderConfig = $this->decodeJson($order->getConfig());
        $serviceConfig = $this->getConfig($model);

        return array_merge($this->getProductConfigDefaults(), $productConfig, $orderConfig, $serviceConfig);
    }

    private function clientEmail(int $clientId): string
    {
        if ($clientId < 1) {
            return '';
        }
        try {
            $client = $this->di['em']->find(Client::class, $clientId);
            if ($client instanceof Client && method_exists($client, 'getEmail')) {
                return trim((string) $client->getEmail());
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            if (isset($this->di['db'])) {
                $row = $this->di['db']->getRow('SELECT email FROM client WHERE id = :id', [':id' => $clientId]);
                if (is_array($row) && !empty($row['email'])) {
                    return trim((string) $row['email']);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '';
    }

    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeInstanceType(string $type): string
    {
        $type = trim(str_replace('_', '-', strtolower($type)));
        if ($type === 'container') {
            return 'container';
        }

        return 'virtual-machine';
    }

    private function normalizeSize(string $v, string $suffix): string
    {
        $v = trim($v);
        if ($v === '') {
            return '1' . $suffix;
        }
        if (preg_match('/^\d+$/', $v)) {
            return $v . $suffix;
        }

        return $v;
    }

    private function imageSource(string $image): array
    {
        $image = trim($image);
        $remotes = [
            'images:' => 'https://images.lxd.canonical.com',
            'ubuntu:' => 'https://cloud-images.ubuntu.com/releases',
            'ubuntu-minimal:' => 'https://cloud-images.ubuntu.com/minimal/releases',
        ];
        foreach ($remotes as $prefix => $server) {
            if (str_starts_with($image, $prefix)) {
                return [
                    'type' => 'image',
                    'mode' => 'pull',
                    'protocol' => 'simplestreams',
                    'server' => $server,
                    'alias' => substr($image, strlen($prefix)),
                ];
            }
        }
        if (preg_match('/^[0-9a-f]{12,}$/i', $image)) {
            return ['type' => 'image', 'fingerprint' => $image];
        }
        if (preg_match('#^ubuntu/(\d+\.\d+)#', $image, $m)) {
            return [
                'type' => 'image',
                'mode' => 'pull',
                'protocol' => 'simplestreams',
                'server' => 'https://cloud-images.ubuntu.com/releases',
                'alias' => $m[1],
            ];
        }
        if (str_contains($image, '/')) {
            return [
                'type' => 'image',
                'mode' => 'pull',
                'protocol' => 'simplestreams',
                'server' => 'https://images.lxd.canonical.com',
                'alias' => $image,
            ];
        }

        return ['type' => 'image', 'alias' => $image];
    }

    private function extractCreatedName(mixed $result, string $fallback): string
    {
        if (!is_array($result)) {
            return $fallback;
        }
        foreach ([
            $result['metadata']['name'] ?? null,
            $result['data']['metadata']['name'] ?? null,
            $result['data']['name'] ?? null,
            $result['name'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $this->sanitizeInstanceName($candidate);
            }
        }

        return $fallback;
    }

    private function rawHasMetadata(mixed $info): bool
    {
        if (!is_array($info)) {
            return false;
        }
        if (!empty($info['metadata']) || !empty($info['data']['metadata']) || !empty($info['data'])) {
            return true;
        }

        return false;
    }
}
