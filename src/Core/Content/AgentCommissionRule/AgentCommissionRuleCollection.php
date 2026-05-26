<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\AgentCommissionRule;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AgentCommissionRuleEntity>
 */
class AgentCommissionRuleCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'agent_commission_rule_collection';
    }

    protected function getExpectedClass(): string
    {
        return AgentCommissionRuleEntity::class;
    }

    /**
     * Get the first active commission rule
     */
    public function getActiveRule(): ?AgentCommissionRuleEntity
    {
        return $this->filter(function (AgentCommissionRuleEntity $rule) {
            return $rule->isActive();
        })->first();
    }
}
