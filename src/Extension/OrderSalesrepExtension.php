<?php

declare(strict_types=1);

namespace Salesrep\Extension;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\SetNullOnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\User\UserDefinition;

class OrderSalesrepExtension extends EntityExtension
{
    public function getDefinitionClass(): string
    {
        return OrderDefinition::class;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new FkField('salesrep_id', 'salesrepId', UserDefinition::class))
                ->addFlags(new ApiAware())
        );

        $collection->add(
            (new FloatField('total_commission_amount', 'totalCommissionAmount'))
                ->addFlags(new ApiAware())
        );

        $collection->add(
            (new ManyToOneAssociationField(
                'salesrep',
                'salesrep_id',
                UserDefinition::class,
                'id',
                false
            ))->addFlags(new ApiAware(), new SetNullOnDelete())
        );
    }
}
