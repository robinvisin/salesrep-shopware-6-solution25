<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use SalesAgent\Core\Content\SalesAgentCommission\SalesAgentCommissionEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class CommissionUpserter
{
    public function __construct(private readonly EntityRepository $commissionRepo)
    {
    }

    /** @param array<int,array<string,mixed>> $payloads */
    public function upsertByOrderId(array $payloads, Context $context): void
    {
        $live = Defaults::LIVE_VERSION;

        foreach ($payloads as &$p) {
            if (!isset($p['orderVersionId']) || !is_string($p['orderVersionId']) || $p['orderVersionId'] === '') {
                $p['orderVersionId'] = $live;
            }
        }
        unset($p);

        $orderIds = [];
        foreach ($payloads as $p) {
            $oid = (string)($p['_resolve_by_order_id'] ?? $p['orderId'] ?? '');
            if ($oid !== '') {
                $orderIds[$oid] = true;
            }
        }
        $orderIds = array_keys($orderIds);

        if ($orderIds === []) {
            foreach ($payloads as &$p) {
                unset($p['_resolve_by_order_id']);
                $p['id'] = $p['id'] ?? Uuid::randomHex();
                $p['orderVersionId'] = $p['orderVersionId'] ?? $live;
            }
            unset($p);
            $this->commissionRepo->upsert($payloads, $context);
            return;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('orderId', $orderIds))
            ->addFilter(new EqualsFilter('orderVersionId', $live));

        $existing = $this->commissionRepo->search($criteria, $context);

        $byOrderId = [];
        /** @var Entity $row */
        foreach ($existing->getEntities() as $row) {
            if ($row instanceof SalesAgentCommissionEntity) {
                $byOrderId[$row->getOrderId()] = $row->getUniqueIdentifier();
            }
        }

        foreach ($payloads as &$p) {
            $oid = (string)($p['_resolve_by_order_id'] ?? $p['orderId'] ?? '');
            unset($p['_resolve_by_order_id']);

            $p['orderVersionId'] = $p['orderVersionId'] ?? $live;

            $p['id'] = $byOrderId[$oid] ?? ($p['id'] ?? Uuid::randomHex());
        }
        unset($p);

        $this->commissionRepo->upsert($payloads, $context);
    }

    public function zeroOutByOrderId(string $orderId, Context $context): void
    {
        if ($orderId === '') {
            return;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('orderId', [$orderId]))
            ->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));

        $existing = $this->commissionRepo->search($criteria, $context);
        if ($existing->count() === 0) {
            return;
        }

        $payloads = [];
        foreach ($existing->getEntities() as $entity) {
            if (!$entity instanceof SalesAgentCommissionEntity) {
                continue;
            }

            $payloads[] = [
                'id'                        => $entity->getUniqueIdentifier(),
                'orderId'                   => $entity->getOrderId(),
                'orderVersionId'            => Defaults::LIVE_VERSION,
                'agentId'                   => $entity->getAgentId(),
                'excludedByAgentEmail'      => $entity->isExcludedByAgentEmail(),
                'commissionPercentApplied'  => 0.00,
                'effectiveDiscountPercent'  => 0.00,
                'commissionAmount'          => 0.00,
            ];
        }

        $this->commissionRepo->upsert($payloads, $context);
    }

    /**
     * @param string $orderId
     * @param array<string> $excludeAgentIds
     * @param Context $context
     * @return void
     */
    public function zeroOutByOrderIdExcludingAgents(string $orderId, array $excludeAgentIds, Context $context): void
    {
        if ($orderId === '') {
            return;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('orderId', [$orderId]))
            ->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));

        $existing = $this->commissionRepo->search($criteria, $context);
        if ($existing->count() === 0) {
            return;
        }

        $payloads = [];
        foreach ($existing->getEntities() as $entity) {
            if (!$entity instanceof SalesAgentCommissionEntity) {
                continue;
            }

            if (in_array($entity->getAgentId(), $excludeAgentIds, true)) {
                continue;
            }

            $payloads[] = [
                'id'                        => $entity->getUniqueIdentifier(),
                'orderId'                   => $entity->getOrderId(),
                'orderVersionId'            => Defaults::LIVE_VERSION,
                'agentId'                   => $entity->getAgentId(),
                'excludedByAgentEmail'      => $entity->isExcludedByAgentEmail(),
                'commissionPercentApplied'  => 0.00,
                'effectiveDiscountPercent'  => 0.00,
                'commissionAmount'          => 0.00,
            ];
        }

        if ($payloads !== []) {
            $this->commissionRepo->upsert($payloads, $context);
        }
    }
}
 