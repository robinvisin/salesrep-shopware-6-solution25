<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\AgentCommissionRule;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\User\UserCollection;

class AgentCommissionRuleEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;
    protected bool $active;
    protected float $commissionPercentage;
    protected ?float $maxDiscountPercentage = null;
    protected ?array $excludedAgentIds = null;
    protected ?UserCollection $excludedAgents = null;

    // Getters
    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCommissionPercentage(): float
    {
        return $this->commissionPercentage;
    }

    public function getMaxDiscountPercentage(): ?float
    {
        return $this->maxDiscountPercentage;
    }

    public function getExcludedAgentIds(): ?array
    {
        return $this->excludedAgentIds;
    }

    public function getExcludedAgents(): ?UserCollection
    {
        return $this->excludedAgents;
    }

    // Setters
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function setCommissionPercentage(float $commissionPercentage): void
    {
        $this->commissionPercentage = $commissionPercentage;
    }

    public function setMaxDiscountPercentage(?float $maxDiscountPercentage): void
    {
        $this->maxDiscountPercentage = $maxDiscountPercentage;
    }

    public function setExcludedAgentIds(?array $excludedAgentIds): void
    {
        $this->excludedAgentIds = $excludedAgentIds;
    }

    public function setExcludedAgents(?UserCollection $excludedAgents): void
    {
        $this->excludedAgents = $excludedAgents;
    }

    public function isAgentExcluded(string $agentId): bool
    {
        return $this->excludedAgentIds !== null && in_array($agentId, $this->excludedAgentIds, true);
    }

    public function isOrderEligibleForCommission(float $orderDiscountPercentage): bool
    {
        if ($this->maxDiscountPercentage === null) {
            return true;
        }

        return $orderDiscountPercentage <= $this->maxDiscountPercentage;
    }
}
