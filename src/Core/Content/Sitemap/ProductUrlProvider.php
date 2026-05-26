<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\Sitemap;

use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ProductUrlProvider extends AbstractUrlProvider
{
    public function __construct(
        private readonly AbstractUrlProvider $inner,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $productRepository
    ) {
    }

    public function getDecorated(): AbstractUrlProvider
    {
        throw new DecorationPatternException(self::class);
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        $result = $this->inner->getUrls($context, $limit, $offset);

        $hiddenCategoryIds = $this->fetchHiddenCategoryIds($context->getContext());
        if (!$hiddenCategoryIds) {
            return $result;
        }

        $hiddenProductIds = $this->fetchHiddenProductIds($hiddenCategoryIds, $context->getContext());
        if (!$hiddenProductIds) {
            return $result;
        }

        $filtered = array_values(array_filter(
            $result->getUrls(),
            fn (Url $url) => !in_array($url->getIdentifier(), $hiddenProductIds, true)
        ));

        return new UrlResult($filtered, $result->getNextOffset());
    }

    private function fetchHiddenCategoryIds(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customFields.sales_agent_only_category', true));

        return array_keys($this->categoryRepository->searchIds($criteria, $context)->getData());
    }

    private function fetchHiddenProductIds(array $categoryIds, Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('categories.id', $categoryIds))
            ->addAssociation('categories');

        return array_keys($this->productRepository->searchIds($criteria, $context)->getData());
    }
}
