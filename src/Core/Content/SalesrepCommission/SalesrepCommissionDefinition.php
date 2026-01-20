<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\SalesrepCommission;

use Salesrep\Core\Content\SalesrepCommission\SalesrepCommissionEntity;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

final class SalesrepCommissionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'salesrep_commission';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SalesrepCommissionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SalesrepCommissionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),

            (new FkField('order_id', 'orderId', OrderDefinition::class))->addFlags(new Required()),
            new ReferenceVersionField(OrderDefinition::class, 'order_version_id'),
            (new FloatField('commission_amount', 'commissionAmount'))->addFlags(new Required()),
            new ManyToOneAssociationField('order', 'order_id', OrderDefinition::class, 'id', false),

            new FkField('agent_id', 'agentId', UserDefinition::class),
            new ManyToOneAssociationField('agent', 'agent_id', UserDefinition::class, 'id', false),

            (new FloatField('effective_discount_percent', 'effectiveDiscountPercent'))->addFlags(new Required()),
            (new FloatField('commission_percent_applied', 'commissionPercentApplied'))->addFlags(new Required()),
            (new BoolField('excluded_by_agent_email', 'excludedByAgentEmail'))->addFlags(new Required()),
        ]);
    }
}
