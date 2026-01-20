<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\OrderClaimRequest;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                         add(OrderClaimRequestEntity $entity)
 * @method void                         set(string $key, OrderClaimRequestEntity $entity)
 * @method OrderClaimRequestEntity[]    getIterator()
 * @method OrderClaimRequestEntity[]    getElements()
 * @method OrderClaimRequestEntity|null get(string $key)
 * @method OrderClaimRequestEntity|null first()
 * @method OrderClaimRequestEntity|null last()
 */
final class OrderClaimRequestCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return OrderClaimRequestEntity::class;
    }
}
