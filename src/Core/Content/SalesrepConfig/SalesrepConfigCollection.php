<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\SalesrepConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(SalesrepConfigEntity $entity)
 * @method void set(string $key, SalesrepConfigEntity $entity)
 * @method SalesrepConfigEntity[] getIterator()
 * @method SalesrepConfigEntity[] getElements()
 * @method SalesrepConfigEntity|null get(string $key)
 * @method SalesrepConfigEntity|null first()
 * @method SalesrepConfigEntity|null last()
 */
class SalesrepConfigCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SalesrepConfigEntity::class;
    }
}
