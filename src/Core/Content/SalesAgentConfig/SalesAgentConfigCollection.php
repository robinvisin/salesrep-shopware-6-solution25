<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\SalesAgentConfig;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(SalesAgentConfigEntity $entity)
 * @method void set(string $key, SalesAgentConfigEntity $entity)
 * @method SalesAgentConfigEntity[] getIterator()
 * @method SalesAgentConfigEntity[] getElements()
 * @method SalesAgentConfigEntity|null get(string $key)
 * @method SalesAgentConfigEntity|null first()
 * @method SalesAgentConfigEntity|null last()
 */
class SalesAgentConfigCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SalesAgentConfigEntity::class;
    }
}
