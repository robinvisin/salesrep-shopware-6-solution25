<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AgentOnlyCategoryProvider
{
    private const CACHE_KEY = 'sales_agent_only_category_ids';
    private const CACHE_TTL = 3600;

    /**
     * @param EntityRepository $categoryRepository
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly CacheInterface $cache
    ) {
    }

    public function getAgentOnlyCategoryIds(Context $context): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($context) {
            $item->expiresAfter(self::CACHE_TTL);

            $criteria = (new Criteria())
                ->addFilter(new EqualsAnyFilter('customFields.sales_agent_only_category', [true, 1, '1']));

            return $this->categoryRepository->searchIds($criteria, $context)->getIds();
        });
    }
}
