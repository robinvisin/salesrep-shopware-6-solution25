<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\SalesAgentCommission;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(SalesAgentCommissionEntity $entity)
 * @method void set(string $key, SalesAgentCommissionEntity $entity)
 * @method SalesAgentCommissionEntity[] getIterator()
 * @method SalesAgentCommissionEntity[] getElements()
 * @method SalesAgentCommissionEntity|null get(string $key)
 * @method SalesAgentCommissionCollection filter(callable $fn)
 * @method SalesAgentCommissionEntity first()
 * @method SalesAgentCommissionEntity last()
 */
final class SalesAgentCommissionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SalesAgentCommissionEntity::class;
    }
}
