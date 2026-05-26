<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\SalesAgentConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\{FkField, FloatField, IdField, ManyToOneAssociationField};
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\{PrimaryKey, Required};
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class SalesAgentConfigDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'sales_agent_config';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }
    public function getEntityClass(): string
    {
        return SalesAgentConfigEntity::class;
    }
    public function getCollectionClass(): string
    {
        return SalesAgentConfigCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('user_id', 'userId', UserDefinition::class))->addFlags(new Required()),
            new FloatField('commission_percentage', 'commissionPercentage'),
            new FloatField('discount_limit', 'discountLimit'),
            new ManyToOneAssociationField('user', 'user_id', UserDefinition::class, 'id'),
        ]);
    }
}
