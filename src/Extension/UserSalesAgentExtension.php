<?php

declare(strict_types=1);

namespace SalesAgent\Extension;

use SalesAgent\Core\Content\AgentPayoutHistory\AgentPayoutHistoryDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class UserSalesAgentExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new BoolField('is_sales_agent', 'isSalesAgent'))
                ->addFlags(new ApiAware())
        );

        $collection->add(
            (new FloatField('commission_rate', 'commissionRate'))
                ->addFlags(new ApiAware())
        );

        $collection->add(
            (new OneToManyAssociationField(
                'handledOrders',
                OrderDefinition::class,
                'sales_agent_id'
            ))->addFlags(new ApiAware())
        );

        $collection->add(
            (new OneToManyAssociationField(
                'payoutHistory',
                AgentPayoutHistoryDefinition::class,
                'sales_agent_id'
            ))->addFlags(new ApiAware())
        );
    }
    public function getEntityName(): string
    {
        return UserDefinition::ENTITY_NAME;
    }
}
