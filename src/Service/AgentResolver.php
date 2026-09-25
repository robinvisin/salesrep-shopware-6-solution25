<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\RequestStack;

final class AgentResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
        /** @var EntityRepository<\SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigCollection> */
        private readonly EntityRepository $salesAgentConfigRepo
    ) {
    }

    public function resolve(Context $context): ?string
    {
        if ($req = $this->requestStack->getCurrentRequest()) {
            $scCtx = $req->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($scCtx) {
                $impersonator = (string) ($scCtx->getImitatingUserId() ?? '');
                if ($impersonator !== '') {
                    return $impersonator;
                }
            }
        }

        $src = $context->getSource();

        if ($src instanceof AdminApiSource) {
            $uid = (string) ($src->getUserId() ?? '');
            return $uid !== '' ? $uid : null;
        }

        if ($src instanceof AdminSalesChannelApiSource) {
            foreach (['getAdminUserId', 'getAdminId', 'getUserId'] as $m) {
                if (\is_callable([$src, $m])) {
                    $uid = (string) (\call_user_func([$src, $m]) ?? '');
                    if ($uid !== '') {
                        return $uid;
                    }
                }
            }
        }

        return null;
    }

    public function resolveAgentUserIdForOrder(OrderEntity $order, Context $context): ?string
    {
        $cf = $order->getCustomFields() ?? [];

        foreach (['sales_agent_user_id', 'sales_agent_agent_user_id'] as $k) {
            $uid = $cf[$k] ?? null;
            if (\is_string($uid) && $uid !== '' && Uuid::isValid($uid) && $this->isSalesAgentUser($uid, $context)) {
                return $uid;
            }
        }

        $createdById = $order->getCreatedById();
        if (\is_string($createdById) && $createdById !== '' && Uuid::isValid($createdById) && $this->isSalesAgentUser($createdById, $context)) {
            return $createdById;
        }

        $createdBySalesAgent = $cf['created_by_sales_agent'] ?? false;
        $createdBySalesAgent = ($createdBySalesAgent === true || $createdBySalesAgent === 1 || $createdBySalesAgent === '1');

        if ($createdBySalesAgent) {
            $acting = $this->resolve($context);
            if ($acting && Uuid::isValid($acting) && $this->isSalesAgentUser($acting, $context)) {
                return $acting;
            }
        }

        return null;
    }

    private function isSalesAgentUser(string $userId, Context $context): bool
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('userId', $userId))
            ->setLimit(1);

        return $this->salesAgentConfigRepo->search($criteria, $context)->getEntities()->count() > 0;
    }
}
