<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SalesAgentConfigProvider
{
    private const CACHE_TTL = 300;
    private const CACHE_KEY_PREFIX = 'sales_agent_config_';

    /**
     * @param EntityRepository $salesAgentConfigRepository
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly EntityRepository $salesAgentConfigRepository,
        private readonly CacheInterface $cache
    ) {
    }

    public function getConfigByUserId(string $userId, Context $context): ?SalesAgentConfigEntity
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $userId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($userId, $context) {
            $item->expiresAfter(self::CACHE_TTL);

            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('userId', $userId))
                ->setLimit(1);

            return $this->salesAgentConfigRepository->search($criteria, $context)->getEntities()->first();
        });
    }
}
