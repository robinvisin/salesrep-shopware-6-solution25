<?php

declare(strict_types=1);

namespace Salesrep\Subscriber;

use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSearchCriteriaEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestCriteriaEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesrepSearchAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $categoryRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCriteriaEvent::class => 'filterSearchResults',
            ProductSuggestCriteriaEvent::class => 'filterSearchResults',
            ProductSearchCriteriaEvent::class  => 'filterSearchResults',
        ];
    }

    public function filterSearchResults(object $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $customer = $salesChannelContext->getCustomer();

        $isAgent = ($salesChannelContext->getImitatingUserId() !== null)
            || ($customer && (($customer->getCustomFields()['salesrep'] ?? false) === true));

        if ($isAgent) {
            return;
        }

        $agentOnlyCategoryIds = $this->getAgentOnlyCategoryIds($salesChannelContext->getContext());
        if (empty($agentOnlyCategoryIds)) {
            return;
        }

        $event->getCriteria()->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [
                    new EqualsAnyFilter('categoriesRo.id', $agentOnlyCategoryIds),
                ]
            )
        );
    }

    private function getAgentOnlyCategoryIds(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('customFields.salesrep_only_category', true));

        return $this->categoryRepository->searchIds($criteria, $context)->getIds();
    }
}
