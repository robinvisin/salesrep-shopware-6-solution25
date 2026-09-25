<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Storefront\Page\Account\Order\AccountOrderDetailPageLoadedEvent;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Shopware\Storefront\Page\Account\Overview\AccountOverviewPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SalesAgentHideOrdersWithHiddenCategorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Content\Category\CategoryCollection> */
        private readonly EntityRepository $categoryRepository,
        /** @var EntityRepository<\Shopware\Core\Content\Product\ProductCollection> */
        private readonly EntityRepository $productRepository,
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountOrderPageLoadedEvent::class        => 'onAccountOrderPageLoaded',
            AccountOrderDetailPageLoadedEvent::class  => 'onAccountOrderDetailPageLoaded',
            AccountOverviewPageLoadedEvent::class     => 'onAccountOverviewPageLoaded',
        ];
    }

    public function onAccountOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $scCtx = $event->getSalesChannelContext();
        if ($this->isAgent($scCtx)) {
            return;
        }

        $ordersResult = $event->getPage()->getOrders();
        if (!$ordersResult) {
            return;
        }

        /** @var OrderCollection $orders */
        $orders = $ordersResult->getEntities();
        if ($orders->count() === 0) {
            return;
        }

        $hiddenSet = $this->buildHiddenProductSetForOrders($orders, $scCtx->getContext());
        if (empty($hiddenSet)) {
            return;
        }

        $kept = [];
        $removed = [];

        /** @var OrderEntity $order */
        foreach ($orders as $order) {
            $hasHidden = $this->orderContainsAnyHiddenProduct($order, $hiddenSet);
            if ($hasHidden) {
                $removed[] = $order->getOrderNumber() ?: $order->getId();
                continue;
            }
            $kept[] = $order;
        }

        $filtered = new OrderCollection($kept);
        $this->forceReplaceSearchResultEntities($ordersResult, $filtered);

        $this->logger->info('[HideOrders] /account/order filtered', [
            'originalCount' => $orders->count(),
            'keptCount' => $filtered->count(),
            'removedCount' => count($removed),
            'removedOrders' => array_slice($removed, 0, 50),
        ]);
    }

    public function onAccountOrderDetailPageLoaded(AccountOrderDetailPageLoadedEvent $event): void
    {
        $scCtx = $event->getSalesChannelContext();
        if ($this->isAgent($scCtx)) {
            return;
        }

        $order = $event->getPage()->getOrder();
        if (!$order) {
            return;
        }

        $order = $this->refetchOrderWithLineItems($order->getId(), $scCtx->getContext()) ?? $order;

        $hiddenSet = $this->buildHiddenProductSetForOrders(new OrderCollection([$order]), $scCtx->getContext());
        if (empty($hiddenSet)) {
            return;
        }

        if ($this->orderContainsAnyHiddenProduct($order, $hiddenSet)) {
            throw new NotFoundHttpException('Page not found');
        }
    }

    public function onAccountOverviewPageLoaded(AccountOverviewPageLoadedEvent $event): void
    {
        $scCtx = $event->getSalesChannelContext();
        if ($this->isAgent($scCtx)) {
            return;
        }

        $ctx = $scCtx->getContext();
        $page = $event->getPage();

        $lastOrder = null;
        if (method_exists($page, 'getLastOrder')) {
            $lastOrder = $page->getLastOrder();
        } elseif ($page->hasExtension('lastOrder')) {
            $lastOrder = $page->getExtension('lastOrder');
        } elseif ($page->hasExtension('last-order')) {
            $lastOrder = $page->getExtension('last-order');
        }

        $candidateIds = [];
        if ($lastOrder instanceof OrderEntity && $lastOrder->getId()) {
            $candidateIds[$lastOrder->getId()] = true;
        }

        $ordersResult = null;
        if (method_exists($page, 'getOrders')) {
            $ordersResult = $page->getOrders();
        }

        if ($ordersResult) {
            $entities = $ordersResult->getEntities();
            if ($entities instanceof OrderCollection) {
                /** @var OrderEntity $o */
                foreach ($entities as $o) {
                    if ($o->getId()) {
                        $candidateIds[$o->getId()] = true;
                    }
                }
            }
        }

        if (empty($candidateIds)) {
            $this->logger->debug('[HideOrders] /account overview: no candidate order ids', [
                'pageClass' => get_class($page),
                'extensions' => array_keys($page->getExtensions()),
            ]);
            return;
        }

        $ordersFull = $this->refetchOrdersWithLineItems(array_keys($candidateIds), $ctx);
        if ($ordersFull->count() === 0) {
            return;
        }

        $hiddenSet = $this->buildHiddenProductSetForOrders($ordersFull, $ctx);
        if (empty($hiddenSet)) {
            return;
        }

        if ($lastOrder instanceof OrderEntity) {
            $fullLast = $ordersFull->get($lastOrder->getId()) ?? null;
            if ($fullLast instanceof OrderEntity) {
                $hasHidden = $this->orderContainsAnyHiddenProduct($fullLast, $hiddenSet);

                $this->logger->info('[HideOrders] /account overview lastOrder check', [
                    'orderNumber' => $fullLast->getOrderNumber(),
                    'orderId' => $fullLast->getId(),
                    'hasHidden' => $hasHidden,
                ]);

                if ($hasHidden) {
                    if (method_exists($page, 'setLastOrder')) {
                        $page->setLastOrder(null);
                    }
                    if ($page->hasExtension('lastOrder')) {
                        $page->removeExtension('lastOrder');
                    }
                    if ($page->hasExtension('last-order')) {
                        $page->removeExtension('last-order');
                    }
                }
            }
        }

        if ($ordersResult) {
            $entities = $ordersResult->getEntities();
            if ($entities instanceof OrderCollection && $entities->count() > 0) {
                $kept = [];
                /** @var OrderEntity $o */
                foreach ($entities as $o) {
                    $full = $ordersFull->get($o->getId()) ?? $o;
                    if (!$this->orderContainsAnyHiddenProduct($full, $hiddenSet)) {
                        $kept[] = $o;
                    }
                }
                $this->forceReplaceSearchResultEntities($ordersResult, new OrderCollection($kept));
            }
        }
    }

    private function refetchOrderWithLineItems(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('lineItems.children');

        return $this->orderRepository->search($criteria, $context)->getEntities()->first();
    }

    private function refetchOrdersWithLineItems(array $orderIds, Context $context): OrderCollection
    {
        $criteria = new Criteria($orderIds);
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('lineItems.children');

        $res = $this->orderRepository->search($criteria, $context);

        /** @var OrderCollection $col */
        $col = $res->getEntities();

        return $col instanceof OrderCollection ? $col : new OrderCollection();
    }

    private function isAgent($salesChannelContext): bool
    {
        $customer = $salesChannelContext->getCustomer();

        return ($salesChannelContext->getImitatingUserId() !== null)
            || ($customer && (($customer->getCustomFields()['sales_agent'] ?? false) === true));
    }

    private function buildHiddenProductSetForOrders(OrderCollection $orders, Context $context): array
    {
        $agentOnlyCategoryIds = $this->getAgentOnlyCategoryIds($context);
        if (empty($agentOnlyCategoryIds)) {
            return [];
        }

        $productIds = [];

        /** @var OrderEntity $order */
        foreach ($orders as $order) {
            foreach ($order->getLineItems() ?? [] as $li) {
                $this->collectProductIdsRecursive($li, $productIds);
            }
        }

        $productIds = array_keys($productIds);
        if (empty($productIds)) {
            return [];
        }

        $criteria = new Criteria($productIds);
        $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $agentOnlyCategoryIds));

        $hiddenProductIds = $this->productRepository->searchIds($criteria, $context)->getIds();
        if (empty($hiddenProductIds)) {
            return [];
        }

        return array_fill_keys($hiddenProductIds, true);
    }

    private function getAgentOnlyCategoryIds(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('customFields.sales_agent_only_category', [true, 1, '1']));

        return $this->categoryRepository->searchIds($criteria, $context)->getIds();
    }

    private function orderContainsAnyHiddenProduct(OrderEntity $order, array $hiddenSet): bool
    {
        foreach ($order->getLineItems() ?? [] as $li) {
            if ($this->lineItemContainsHiddenProductRecursive($li, $hiddenSet)) {
                return true;
            }
        }
        return false;
    }

    private function lineItemContainsHiddenProductRecursive($li, array $hiddenSet): bool
    {
        $pid = $this->extractProductIdFromLineItem($li);
        if ($pid && isset($hiddenSet[$pid])) {
            return true;
        }

        $children = method_exists($li, 'getChildren') ? $li->getChildren() : null;
        if ($children) {
            foreach ($children as $child) {
                if ($this->lineItemContainsHiddenProductRecursive($child, $hiddenSet)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function collectProductIdsRecursive($li, array &$productIds): void
    {
        $pid = $this->extractProductIdFromLineItem($li);
        if ($pid) {
            $productIds[$pid] = true;
        }

        $children = method_exists($li, 'getChildren') ? $li->getChildren() : null;
        if ($children) {
            foreach ($children as $child) {
                $this->collectProductIdsRecursive($child, $productIds);
            }
        }
    }

    private function extractProductIdFromLineItem($li): ?string
    {
        if (!$li) {
            return null;
        }

        $type = (string) $li->getType();

        if ($type === LineItem::PRODUCT_LINE_ITEM_TYPE || $type === 'product') {
            return $li->getReferencedId() ?: null;
        }

        $payload = $li->getPayload() ?? [];
        if (is_array($payload) && !empty($payload['productId']) && is_string($payload['productId'])) {
            return $payload['productId'];
        }

        return null;
    }

    private function forceReplaceSearchResultEntities(object $searchResult, EntityCollection $entities): void
    {
        if (method_exists($searchResult, 'setEntities')) {
            $searchResult->setEntities($entities);
        } else {
            $this->setPrivateProp($searchResult, 'entities', $entities);
        }

        if (method_exists($searchResult, 'setElements')) {
            $searchResult->setElements($entities->getElements());
        } else {
            $this->setPrivateProp($searchResult, 'elements', $entities->getElements());
        }

        if (method_exists($searchResult, 'setTotal')) {
            $searchResult->setTotal($entities->count());
        } else {
            $this->setPrivateProp($searchResult, 'total', $entities->count());
        }
    }

    private function setPrivateProp(object $obj, string $prop, mixed $value): void
    {
        $r = new \ReflectionObject($obj);

        while ($r) {
            if ($r->hasProperty($prop)) {
                $p = $r->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($obj, $value);
                return;
            }

            $r = $r->getParentClass() ?: null;
        }

        $this->logger->debug('[HideOrders] Reflection failed to set prop', [
            'class' => get_class($obj),
            'prop' => $prop,
        ]);
    }
}
