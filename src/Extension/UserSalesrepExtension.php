<?php

declare(strict_types=1);

namespace Salesrep\Extension;

use Salesrep\Core\Content\AgentPayoutHistory\AgentPayoutHistoryDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class UserSalesrepExtension extends EntityExtension
{
    public function getDefinitionClass(): string
    {
        return UserDefinition::class;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new BoolField('is_salesrep', 'isSalesrep'))
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
                'salesrep_id'
            ))->addFlags(new ApiAware())
        );

        $collection->add(
            (new OneToManyAssociationField(
                'payoutHistory',
                AgentPayoutHistoryDefinition::class,
                'salesrep_id'
            ))->addFlags(new ApiAware())
        );
    }
}
