<?php

declare(strict_types=1);


namespace SalesAgent\Subscriber;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use SalesAgent\Service\CommissionUpserter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CommercialReturnSubscriber implements EventSubscriberInterface
{
    private const CTX_STATE_SKIP_SPLIT_RECOMPUTE = 'sales_agent_skip_split_recompute';

    private const ORDER_RETURN_LINE_ITEM_DEFINITION =
        'Shopware\\Commercial\\ReturnManagement\\Entity\\OrderReturnLineItem\\OrderReturnLineItemDefinition';

    public function __construct(
        private readonly EntityRepository   $orderRepository,
        private readonly EntityRepository   $orderReturnLineItemRepository,
        private readonly CommissionUpserter $upserter,
        private readonly Connection         $connection,
        private readonly LoggerInterface    $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        if (!class_exists(self::ORDER_RETURN_LINE_ITEM_DEFINITION)) {
            return [];
        }

        $definitionClass = self::ORDER_RETURN_LINE_ITEM_DEFINITION;

        return [
            $definitionClass::ENTITY_NAME . '.written' => 'onReturnLineItemWritten',
        ];
    }

    public function onReturnLineItemWritten(EntityWrittenEvent $event): void
    {
        $ctx = $event->getContext();

        $returnIds = [];
        foreach ($event->getWriteResults() as $wr) {
            $payload = $wr->getPayload() ?? [];
            $rid = (string)($payload['orderReturnId'] ?? '');
            if ($rid !== '') {
                $returnIds[$rid] = true;
            }
        }

        if ($returnIds === []) {
            return;
        }

        $orderIds = [];
        foreach (array_keys($returnIds) as $returnIdHex) {
            $row = $this->connection->fetchAssociative(
                'SELECT LOWER(HEX(order_id)) AS order_id FROM order_return WHERE id = UNHEX(:rid) LIMIT 1',
                ['rid' => $returnIdHex]
            );
            $oid = (string)($row['order_id'] ?? '');
            if ($oid !== '') {
                $orderIds[$oid] = true;
            }
        }

        if ($orderIds === []) {
            return;
        }

        foreach (array_keys($orderIds) as $orderIdHexLower) {
            try {
                $this->recalculateCommissionAfterReturnForOrder($orderIdHexLower, $ctx);
            } catch (\Throwable $e) {
                $this->logger->error('[CommercialReturnSubscriber] Failed to recalc commission after return', [
                    'orderId' => $orderIdHexLower,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    private function recalculateCommissionAfterReturnForOrder(string $orderIdHexLower, Context $ctx): void
    {
        /** @var OrderEntity|null $order */
        $order = $this->orderRepository
            ->search((new Criteria([$orderIdHexLower]))->addAssociation('lineItems'), $ctx)
            ->getEntities()->first();

        if (!$order) {
            $this->logger->warning('[CommercialReturnSubscriber] Order not found while recalculating commission', [
                'orderId' => $orderIdHexLower,
            ]);
            return;
        }

        $commissionRow = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id_hex, commission_percent_applied
             FROM sales_agent_commission
             WHERE order_id = UNHEX(:oid)
             ORDER BY created_at DESC
             LIMIT 1',
            ['oid' => $orderIdHexLower]
        );

        if (!$commissionRow) {
            return;
        }

        $percentApplied = (float)($commissionRow['commission_percent_applied'] ?? 0.0);
        $orderNet = $this->calculateOrderNet($order);
        $returnedNet = $this->getReturnedNetTotalForOrder($orderIdHexLower, $ctx);
        $effectiveNet = max(0.0, $orderNet - $returnedNet);
        $totalCommission = round(($effectiveNet * $percentApplied) / 100, 2);
        $cf = $this->fetchOrderCustomFields($orderIdHexLower);
        $splitEmail   = trim((string)($cf['sales_agent_split_email'] ?? ''));
        $splitPercent = (float)($cf['sales_agent_split_percent'] ?? 0.0);
        $splitPercent = max(0.0, min(100.0, $splitPercent));
        $hasSplit     = ($splitEmail !== '' && $splitPercent > 0.0);
        $splitAmount = 0.0;
        $mainAmount  = $totalCommission;

        if ($hasSplit) {
            $splitAmount = round($totalCommission * ($splitPercent / 100.0), 2);
            $mainAmount  = round(max(0.0, $totalCommission - $splitAmount), 2);
        }

        $this->connection->executeStatement(
            'UPDATE sales_agent_commission
             SET commission_amount = :amt, updated_at = NOW(3)
             WHERE id = UNHEX(:id)',
            [
                'amt' => $mainAmount,
                'id'  => (string)$commissionRow['id_hex'],
            ]
        );

        $this->updateOrderSplitAmountsViaDal($orderIdHexLower, $splitAmount, $hasSplit, $ctx);
    }

    private function getReturnedNetTotalForOrder(string $orderIdHexLower, Context $ctx): float
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id_hex FROM order_return WHERE order_id = UNHEX(:oid)',
            ['oid' => $orderIdHexLower]
        );

        $returnIds = [];
        foreach ($rows as $r) {
            $id = (string)($r['id_hex'] ?? '');
            if ($id !== '') {
                $returnIds[] = $id;
            }
        }

        if ($returnIds === []) {
            return 0.0;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('orderReturnId', $returnIds));

        $result = $this->orderReturnLineItemRepository->search($criteria, $ctx);

        $totalNet = 0.0;

        /** @var OrderReturnLineItemEntity $item */
        foreach ($result->getEntities() as $item) {
            $price = $item->getPrice();
            if ($price === null) {
                continue;
            }

            $gross  = (float)$price->getTotalPrice();
            $taxSum = 0.0;

            foreach ($price->getCalculatedTaxes() as $tax) {
                $taxSum += (float)$tax->getTax();
            }

            $net = max(0.0, $gross - $taxSum);
            $totalNet += $net;
        }

        return round($totalNet, 2);
    }

    private function calculateOrderNet(OrderEntity $order): float
    {
        $sum = 0.0;

        $items = $order->getLineItems();
        if ($items === null || $items->count() === 0) {
            return 0.0;
        }

        foreach ($items as $line) {
            $price = $line->getPrice();
            if ($price === null) {
                continue;
            }

            $net = (float)$price->getTotalPrice();
            foreach ($price->getCalculatedTaxes() as $tax) {
                $net -= (float)$tax->getTax();
            }

            $sum += max(0.0, $net);
        }

        return round($sum, 2);
    }

    /** @return array<string,mixed> */
    private function fetchOrderCustomFields(string $orderIdHexLower): array
    {
        $json = $this->connection->fetchOne(
            'SELECT custom_fields FROM `order` WHERE id = UNHEX(:id) LIMIT 1',
            ['id' => $orderIdHexLower]
        );

        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function updateOrderSplitAmountsViaDal(string $orderIdHexLower, float $splitAmount, bool $hasSplit, Context $ctx): void
    {
        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($orderIdHexLower, $splitAmount, $hasSplit): void {
            $internal = clone $system;
            $internal->addState(self::CTX_STATE_SKIP_SPLIT_RECOMPUTE);
    
            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderIdHexLower]), $internal)->getEntities()->first();
            $cf = $fresh?->getCustomFields() ?? [];
    
            if ($hasSplit) {
                $amt = round($splitAmount, 2);
                $cf['sales_agent_commission_split'] = $amt;
                $cf['sales_agent_split_amount']     = $amt;
            } else {
                unset($cf['sales_agent_commission_split'], $cf['sales_agent_split_amount']);
            }
    
            $this->orderRepository->update([[
                'id' => $orderIdHexLower,
                'versionId' => Defaults::LIVE_VERSION,
                'customFields' => $cf,
            ]], $internal);
        });
    }
    
}
