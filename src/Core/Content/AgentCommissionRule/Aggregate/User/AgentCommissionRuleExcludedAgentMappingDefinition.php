<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\AgentCommissionRule\Aggregate\User;

use Salesrep\Core\Content\AgentCommissionRule\AgentCommissionRuleDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\MappingEntityDefinition;
use Shopware\Core\System\User\UserDefinition;

class AgentCommissionRuleExcludedAgentMappingDefinition extends MappingEntityDefinition
{
    public const ENTITY_NAME = 'agent_commission_rule_excluded_agent';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('rule_id', 'ruleId', AgentCommissionRuleDefinition::class))
                ->addFlags(new PrimaryKey(), new Required(), new ApiAware()),

            (new FkField('agent_id', 'agentId', UserDefinition::class))
                ->addFlags(new PrimaryKey(), new Required(), new ApiAware()),

            (new ManyToOneAssociationField(
                'commissionRule',
                'rule_id',
                AgentCommissionRuleDefinition::class,
                'id',
                false
            ))->addFlags(new ApiAware()),

            (new ManyToOneAssociationField(
                'agent',
                'agent_id',
                UserDefinition::class,
                'id',
                false
            ))->addFlags(new ApiAware()),
        ]);
    }
}
