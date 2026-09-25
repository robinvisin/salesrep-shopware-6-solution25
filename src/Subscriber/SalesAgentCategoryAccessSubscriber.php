<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouterInterface;

class SalesAgentCategoryAccessSubscriber implements EventSubscriberInterface
{
    private EntityRepository $categoryRepository;
    private RouterInterface $router;

    public function __construct(EntityRepository $categoryRepository, RouterInterface $router)
    {
        $this->categoryRepository = $categoryRepository;
        $this->router = $router;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
            ProductPageLoadedEvent::class  => 'onProductPageLoaded',
        ];
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $category = $event->getPage()->getHeader()->getNavigation()->getActive();
        if (!$category) {
            return;
        }

        if ($this->isAgentOnlyCategory($category)) {
            $this->denyAccessIfNotAgent($event->getSalesChannelContext());
        }
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $product = $event->getPage()->getProduct();
        $salesChannelContext = $event->getSalesChannelContext();

        $categoryIds = $product->getCategoryIds() ?? [];
        if (empty($categoryIds)) {
            return;
        }

        $criteria = new Criteria($categoryIds);
        $criteria->addFilter(new EqualsAnyFilter('customFields.sales_agent_only_category', [true, 1, '1']));

        $agentOnlyCategories = $this->categoryRepository
            ->search($criteria, $salesChannelContext->getContext())
            ->getEntities()->getElements();

        if (!empty($agentOnlyCategories)) {
            $this->denyAccessIfNotAgent($salesChannelContext);
        }
    }

    private function isAgentOnlyCategory($category): bool
    {
        $customFieldsT = $category->getTranslated()['customFields'] ?? [];
        $customFields  = $category->getCustomFields() ?? [];

        $flag = $customFieldsT['sales_agent_only_category']
            ?? $customFields['sales_agent_only_category']
            ?? false;

        return \in_array($flag, [true, 1, '1'], true);
    }

    private function denyAccessIfNotAgent($salesChannelContext): void
    {
        $customer = $salesChannelContext->getCustomer();
        $customerCf = $customer?->getCustomFields() ?? [];
        $agentFlag = $customerCf['sales_agent'] ?? $customerCf['is_sales_agent'] ?? false;

        $isAgent = ($salesChannelContext->getImitatingUserId() !== null)
            || ($customer && \in_array($agentFlag, [true, 1, '1'], true));

        if (!$isAgent) {
            $homeUrl = $this->router->generate('frontend.home.page');
            throw new HttpException(302, 'Redirecting', null, ['Location' => $homeUrl]);
        }
    }
}
