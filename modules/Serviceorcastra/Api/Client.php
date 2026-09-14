<?php

declare(strict_types=1);

/**
 * Copyright 2026 FOSSGO
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Serviceorcastra\Api;

use FOSSBilling\Validation\Api\RequiredParams;

/**
 * Client API for Orcastra power controls and ticketed console access.
 */
class Client extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Safe service details for the logged-in client's order.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function get($data): array
    {
        $identity = $this->getIdentity();
        $model = $this->getService()->getServiceByOrderId((int) $data['order_id'], (int) $identity->getId());

        return $this->getService()->toApiArray($model);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function start($data): bool
    {
        $order = $this->ownedOrder($data);

        return $this->getService()->startInstance($order);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function stop($data): bool
    {
        $order = $this->ownedOrder($data);

        return $this->getService()->stopInstance($order);
    }

    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function restart($data): bool
    {
        $order = $this->ownedOrder($data);

        return $this->getService()->restartInstance($order);
    }

    /**
     * Mint console redirect_url. Client UI must send the browser to redirect_url only.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function console_url($data): array
    {
        $order = $this->ownedOrder($data);
        $url = $this->getService()->mintConsoleRedirect($order, 'console');

        return ['redirect_url' => $url];
    }

    /**
     * Mint terminal (or console for VMs) redirect_url with ticket=.
     */
    #[RequiredParams(['order_id' => 'Order ID is required'])]
    public function terminal_url($data): array
    {
        $order = $this->ownedOrder($data);
        $url = $this->getService()->mintConsoleRedirect($order, 'terminal');

        return ['redirect_url' => $url];
    }

    private function ownedOrder(array $data): \Box\Mod\Order\Entity\Order
    {
        $identity = $this->getIdentity();

        return $this->getService()->getOrderForServiceCall((int) $data['order_id'], (int) $identity->getId());
    }
}
