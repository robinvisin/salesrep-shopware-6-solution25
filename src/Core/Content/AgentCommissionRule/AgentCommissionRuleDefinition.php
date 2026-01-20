<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\AgentCommissionRule;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class AgentCommissionRuleDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'agent_commission_rule';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentCommissionRuleEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AgentCommissionRuleCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new StringField('name', 'name'))->addFlags(new Required(), new ApiAware()),
            (new BoolField('active', 'active'))->addFlags(new Required(), new ApiAware()),
            (new FloatField('commission_percentage', 'commissionPercentage'))
                ->addFlags(new Required(), new ApiAware()),
            (new FloatField('max_discount_percentage', 'maxDiscountPercentage'))
                ->addFlags(new ApiAware()),
            (new JsonField('excluded_agent_ids', 'excludedAgentIds'))
                ->addFlags(new ApiAware()),
            (new ManyToManyAssociationField(
                'excludedAgents',
                UserDefinition::class,
                'agent_commission_rule_excluded_agent',
                'rule_id',
                'agent_id'
            ))->addFlags(new ApiAware()),
        ]);
    }
}
