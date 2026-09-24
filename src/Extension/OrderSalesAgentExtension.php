<?php

declare(strict_types=1);

namespace SalesAgent\Extension;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SetNullOnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class OrderSalesAgentExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new FkField('sales_agent_id', 'salesAgentId', UserDefinition::class))
                ->addFlags(new ApiAware())
        );

        $collection->add(
            (new FloatField('total_commission_amount', 'totalCommissionAmount'))
                ->addFlags(new ApiAware())
        );

        $collection->add(
            (new ManyToOneAssociationField(
                'salesAgent',
                'sales_agent_id',
                UserDefinition::class,
                'id',
                false
            ))->addFlags(new ApiAware(), new SetNullOnDelete())
        );
    }
    public function getEntityName(): string
    {
        return OrderDefinition::ENTITY_NAME;
    }
}
