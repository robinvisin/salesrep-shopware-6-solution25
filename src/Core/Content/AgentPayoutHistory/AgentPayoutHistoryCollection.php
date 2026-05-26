<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\AgentPayoutHistory;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class AgentPayoutHistoryCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'agent_payout_history_collection';
    }

    protected function getExpectedClass(): string
    {
        return AgentPayoutHistoryEntity::class;
    }

    public function filterByAgent(string $agentId): AgentPayoutHistoryCollection
    {
        return $this->filter(fn (AgentPayoutHistoryEntity $entity) => $entity->getAgentId() === $agentId);
    }

    public function getTotalPayoutAmount(): float
    {
        $total = 0.0;
        foreach ($this as $payout) {
            if ($payout->getPayoutAmount() !== null) {
                $total += $payout->getPayoutAmount();
            }
        }
        return $total;
    }

    public function filterByPaymentMethod(string $paymentMethod): AgentPayoutHistoryCollection
    {
        return $this->filter(fn (AgentPayoutHistoryEntity $entity) => $entity->getPaymentMethod() === $paymentMethod);
    }
}
