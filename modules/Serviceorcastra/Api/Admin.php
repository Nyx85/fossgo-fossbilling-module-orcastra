<?php

declare(strict_types=1);

/**
 * Copyright 2026 FOSSGO
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Serviceorcastra\Api;

use FOSSBilling\Validation\Api\RequiredParams;

/**
 * Admin API for Orcastra VM/container services.
 */
class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Return service row + safe config for an order (for manage templates).
     * Prefer this over order_service — third-party types are not entity-backed in core.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function get($data): array
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $model = $this->getService()->getServiceByOrderId((int) $data['order_id']);

        return $this->getService()->toApiArray($model);
    }

    /**
     * Update service config JSON / runtime fields.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function update($data): bool
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        if (isset($data['config']) && is_array($data['config'])) {
            $this->getService()->updateConfig((int) $data['order_id'], $data['config']);
        } else {
            $config = $data;
            unset($config['order_id']);
            $this->getService()->updateConfig((int) $data['order_id'], $config);
        }

        return true;
    }

    /**
     * Test Orcastra API credentials (product/module config or explicit fields).
     */
    public function test_connection($data = []): array
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $cfg = is_array($data) ? $data : [];
        if (!empty($data['order_id'])) {
            $svc = $this->getService();
            $model = $svc->getServiceByOrderId((int) $data['order_id']);
            // Secrets are stripped from toApiArray — merge raw config for the test call
            $raw = $svc->getConfig($model);
            $cfg = array_merge($svc->getProductConfigDefaults(), $raw, $cfg);
        }

        return $this->getService()->testConnection($cfg);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function start($data): bool
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $order = $this->getService()->getOrderForServiceCall((int) $data['order_id']);

        return $this->getService()->startInstance($order);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function stop($data): bool
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $order = $this->getService()->getOrderForServiceCall((int) $data['order_id']);

        return $this->getService()->stopInstance($order);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function restart($data): bool
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $order = $this->getService()->getOrderForServiceCall((int) $data['order_id']);

        return $this->getService()->restartInstance($order);
    }

    /**
     * Mint console redirect_url (ticketed). Admin UI should navigate browser there.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function console_url($data): array
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $order = $this->getService()->getOrderForServiceCall((int) $data['order_id']);
        $url = $this->getService()->mintConsoleRedirect($order, 'console');

        return ['redirect_url' => $url];
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function terminal_url($data): array
    {
        $this->checkPermissions('serviceorcastra', 'manage');
        $order = $this->getService()->getOrderForServiceCall((int) $data['order_id']);
        $url = $this->getService()->mintConsoleRedirect($order, 'terminal');

        return ['redirect_url' => $url];
    }

    /**
     * Defaults / hints for product config form.
     */
    public function config_defaults($data = []): array
    {
        $this->checkPermissions('serviceorcastra', 'manage');

        return [
            'defaults' => $this->getService()->getProductConfigDefaults(),
            'clusters_hint' => ['central-1', 'central-2'],
            'notes' => 'Paste api_key_id (oak_…) and api_key_secret (oas_…) in product or module settings. Never commit secrets to git.',
        ];
    }
}
