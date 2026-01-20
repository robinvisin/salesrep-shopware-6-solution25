<?php

declare(strict_types=1);

namespace Salesrep\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class OrderClaimService
{
    public function __construct(
        private readonly EntityRepository $orderRepo,
        private readonly EntityRepository $salesrepConfigRepo,
        private readonly AgentResolver $agentResolver,
        private readonly OrderCommissionRecalculator $recalculator
    ) {
    }

    public function claim(string $orderId, string $claimedUserId, ?string $reason, Context $context): void
    {
        if (!Uuid::isValid($orderId) || !Uuid::isValid($claimedUserId)) {
            return;
        }

        $agentConfigId = $this->findConfigIdByUserId($claimedUserId, $context);
        if (!$agentConfigId) {
            return;
        }

        $claimerUserId = $this->agentResolver->resolve($context);
        if ($claimerUserId !== null && !Uuid::isValid($claimerUserId)) {
            $claimerUserId = null;
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');

        $customFields = [
            'salesrep_claimed_user_id'    => $claimedUserId,
            'salesrep_claimed_by_user_id' => $claimerUserId,
            'salesrep_claimed_at'         => $now,
            'salesrep_claim_reason'       => $reason !== null ? trim($reason) : null,
        ];

        $this->orderRepo->update([[
            'id' => $orderId,
            'customFields' => $customFields,
        ]], $context);

        $this->recalculator->recalcForOrderId($orderId, $context);
    }

    public function unclaim(string $orderId, Context $context): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $this->orderRepo->update([[
            'id' => $orderId,
            'customFields' => [
                'salesrep_claimed_user_id'    => null,
                'salesrep_claimed_by_user_id' => null,
                'salesrep_claimed_at'         => null,
                'salesrep_claim_reason'       => null,
            ],
        ]], $context);

        $this->recalculator->recalcForOrderId($orderId, $context);
    }

    private function findConfigIdByUserId(string $userId, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('userId', $userId))
            ->setLimit(1);

        $cfg = $this->salesrepConfigRepo->search($criteria, $context)->first();

        return $cfg?->getUniqueIdentifier(); // config id
    }
}
