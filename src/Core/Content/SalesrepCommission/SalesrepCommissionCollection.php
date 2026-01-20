<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\SalesrepCommission;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(SalesrepCommissionEntity $entity)
 * @method void set(string $key, SalesrepCommissionEntity $entity)
 * @method SalesrepCommissionEntity[] getIterator()
 * @method SalesrepCommissionEntity[] getElements()
 * @method SalesrepCommissionEntity|null get(string $key)
 * @method SalesrepCommissionCollection filter(callable $fn)
 * @method SalesrepCommissionEntity first()
 * @method SalesrepCommissionEntity last()
 */
final class SalesrepCommissionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SalesrepCommissionEntity::class;
    }
}
