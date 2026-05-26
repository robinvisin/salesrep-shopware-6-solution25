<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use SalesAgent\Service\AgentOnlyCategoryProvider;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SalesAgentSearchAccessSubscriber implements EventSubscriberInterface
{
    /**
     * @param AgentOnlyCategoryProvider $categoryProvider
     */
    public function __construct(
        private readonly AgentOnlyCategoryProvider $categoryProvider
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_SEARCH_CRITERIA  => 'onSearchCriteria',
            ProductEvents::PRODUCT_SUGGEST_CRITERIA => 'onSuggestCriteria',
        ];
    }

    public function onSearchCriteria(ProductSearchCriteriaEvent $event): void
    {
        $this->applyAgentOnlyCategoryExclusion(
            $event->getCriteria(),
            $event->getSalesChannelContext()
        );
    }

    public function onSuggestCriteria(ProductSuggestCriteriaEvent $event): void
    {
        $this->applyAgentOnlyCategoryExclusion(
            $event->getCriteria(),
            $event->getSalesChannelContext()
        );
    }

    private function applyAgentOnlyCategoryExclusion(Criteria $criteria, SalesChannelContext $scContext): void
    {
        if ($this->isAgent($scContext)) {
            return;
        }

        $agentOnlyCategoryIds = $this->categoryProvider->getAgentOnlyCategoryIds($scContext->getContext());
        if (!$agentOnlyCategoryIds) {
            return;
        }

        $criteria->addAssociation('categories');

        $criteria->addFilter(
            new NotFilter(MultiFilter::CONNECTION_AND, [
                new EqualsAnyFilter('categories.id', $agentOnlyCategoryIds),
            ])
        );
    }

    private function isAgent(SalesChannelContext $scContext): bool
    {
        $customer = $scContext->getCustomer();
        $customerCf = $customer?->getCustomFields() ?? [];
        $agentFlag = $customerCf['sales_agent'] ?? $customerCf['is_sales_agent'] ?? false;

        return $scContext->getImitatingUserId() !== null
            || ($customer && \in_array($agentFlag, [true, 1, '1'], true));
    }
}
