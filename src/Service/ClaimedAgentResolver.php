<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class ClaimedAgentResolver
{
    public function __construct(
        private readonly EntityRepository $salesAgentConfigRepo
    ) {
    }

    public function resolveAgentUserIdForOrder(OrderEntity $order, Context $context): ?string
    {
        $cf = $order->getCustomFields() ?? [];

        $claimedUserId = $cf['sales_agent_claimed_user_id'] ?? null;
        if (!\is_string($claimedUserId) || $claimedUserId === '' || !Uuid::isValid($claimedUserId)) {
            return null;
        }

        $claimedAt = $cf['sales_agent_claimed_at'] ?? null;
        if (!\is_string($claimedAt) || $claimedAt === '') {
            return null;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('userId', $claimedUserId))
            ->setLimit(1);

        $cfg = $this->salesAgentConfigRepo->search($criteria, $context)->getEntities()->first();

        return $cfg ? $claimedUserId : null;
    }
}
