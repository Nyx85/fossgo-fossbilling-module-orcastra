<?php

declare(strict_types=1);

/**
 * Copyright 2026 FOSSGO
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Box\Mod\Serviceorcastra\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FOSSBilling\Doctrine\TimestampTrait;
use FOSSBilling\Interfaces\TimestampInterface;

/**
 * Persisted Orcastra VM/container service row.
 *
 * Table name follows FOSSBilling third-party convention: service_{type}
 * where type is the module id suffix after "service" → "orcastra".
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_orcastra')]
#[ORM\Index(name: 'service_orcastra_client_id_idx', columns: ['client_id'])]
#[ORM\HasLifecycleCallbacks]
class ServiceOrcastra implements TimestampInterface
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    #[ORM\Column(name: 'client_id', type: Types::BIGINT, nullable: true)]
    private ?int $clientId = null;

    #[ORM\Column(name: 'instance_name', type: Types::STRING, length: 64, nullable: true)]
    private ?string $instanceName = null;

    #[ORM\Column(name: 'cluster_id', type: Types::STRING, length: 128, nullable: true)]
    private ?string $clusterId = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $project = null;

    #[ORM\Column(name: 'instance_type', type: Types::STRING, length: 32, nullable: true)]
    private ?string $instanceType = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $status = null;

    /** Product + runtime JSON (image, cpu, memory, disk, access metadata, …). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $config = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function setClientId(?int $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getInstanceName(): ?string
    {
        return $this->instanceName;
    }

    public function setInstanceName(?string $instanceName): self
    {
        $this->instanceName = $instanceName;

        return $this;
    }

    public function getClusterId(): ?string
    {
        return $this->clusterId;
    }

    public function setClusterId(?string $clusterId): self
    {
        $this->clusterId = $clusterId;

        return $this;
    }

    public function getProject(): ?string
    {
        return $this->project;
    }

    public function setProject(?string $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getInstanceType(): ?string
    {
        return $this->instanceType;
    }

    public function setInstanceType(?string $instanceType): self
    {
        $this->instanceType = $instanceType;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getConfig(): ?string
    {
        return $this->config;
    }

    public function setConfig(?string $config): self
    {
        $this->config = $config;

        return $this;
    }
}
