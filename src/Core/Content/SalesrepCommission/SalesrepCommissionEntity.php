<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\SalesrepCommission;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\User\UserEntity;

final class SalesrepCommissionEntity extends Entity
{
    use EntityIdTrait;

    protected string $orderId;
    protected string $orderVersionId;
    protected ?string $agentId = null;

    protected float $effectiveDiscountPercent = 0.0;
    protected float $commissionPercentApplied = 0.0;
    protected float $commissionAmount = 0.0;

    protected bool $excludedByAgentEmail = false;

    /** @var OrderEntity|null */
    protected $order;

    /** @var UserEntity|null */
    protected $agent;

    public function getOrderId(): string
    {
        return $this->orderId;
    }
    public function setOrderId(string $id): void
    {
        $this->orderId = $id;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }
    public function setOrderVersionId(string $v): void
    {
        $this->orderVersionId = $v;
    }

    public function getAgentId(): ?string
    {
        return $this->agentId;
    }
    public function setAgentId(?string $id): void
    {
        $this->agentId = $id;
    }

    public function getEffectiveDiscountPercent(): float
    {
        return $this->effectiveDiscountPercent;
    }
    public function setEffectiveDiscountPercent(float $v): void
    {
        $this->effectiveDiscountPercent = $v;
    }

    public function getCommissionPercentApplied(): float
    {
        return $this->commissionPercentApplied;
    }
    public function setCommissionPercentApplied(float $v): void
    {
        $this->commissionPercentApplied = $v;
    }
    public function getCommissionAmount(): float
    {
        return $this->commissionAmount;
    }
    public function setCommissionAmount(float $v): void
    {
        $this->commissionAmount = $v;
    }

    public function isExcludedByAgentEmail(): bool
    {
        return $this->excludedByAgentEmail;
    }
    public function setExcludedByAgentEmail(bool $v): void
    {
        $this->excludedByAgentEmail = $v;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }
    public function setOrder(?OrderEntity $o): void
    {
        $this->order = $o;
    }

    public function getAgent(): ?UserEntity
    {
        return $this->agent;
    }
    public function setAgent(?UserEntity $u): void
    {
        $this->agent = $u;
    }
}
