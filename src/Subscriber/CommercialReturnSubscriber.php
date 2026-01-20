<?php

declare(strict_types=1);

namespace Salesrep\Subscriber;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Salesrep\Service\CommissionUpserter;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturnLineItem\OrderReturnLineItemDefinition;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturnLineItem\OrderReturnLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CommercialReturnSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EntityRepository $orderRepository, private readonly EntityRepository $orderReturnLineItemRepository, private readonly CommissionUpserter $upserter, private readonly Connection $connection, private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [OrderReturnLineItemDefinition::ENTITY_NAME . '.written' => 'onReturnLineItemWritten',];
    }

    public function onReturnLineItemWritten(EntityWrittenEvent $event): void
    {
        $ctx = $event->getContext();
        $pairs = [];
        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload() ?? [];
            $orderReturnIdHex = (string)($payload['orderReturnId'] ?? '');
            $orderLineItemIdHex = (string)($payload['orderLineItemId'] ?? '');
            if ($orderLineItemIdHex === '') {
                continue;
            }
            $orderIdHex = '';
            if ($orderReturnIdHex !== '') {
                $row = $this->connection->fetchAssociative('SELECT HEX(order_id) AS order_id FROM order_return WHERE id = UNHEX(:returnId) LIMIT 1', ['returnId' => $orderReturnIdHex]);
                $orderIdHex = (string)($row['order_id'] ?? '');
            }
            if ($orderIdHex !== '') {
                $pairs[$orderLineItemIdHex] = $orderIdHex;
            }
        }
        if (!$pairs) {
            return;
        }
        foreach ($pairs as $orderLineItemIdHex => $orderIdHex) {
            try {
                $this->recalculateCommissionAfterReturn($orderLineItemIdHex, $orderIdHex, $ctx);
            } catch (\Throwable $e) {
                $this->logger->error('[CommercialReturnSubscriber] Failed to recalc commission after return', ['orderLineItemId' => $orderLineItemIdHex, 'orderId' => $orderIdHex, 'error' => $e->getMessage(),]);
            }
        }
    }

    private function recalculateCommissionAfterReturn(string $orderLineItemIdHex, string $orderIdHex, Context $ctx): void
    {
        $returnedNet = $this->getReturnedNetTotalForLineItem($orderLineItemIdHex, $ctx);
        $orderIdHexLower = strtolower($orderIdHex);
        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search((new Criteria([$orderIdHexLower]))->addAssociation('lineItems'), $ctx)->first();
        if (!$order) {
            $this->logger->warning('[CommercialReturnSubscriber] Order not found while recalculating commission', ['orderId' => $orderIdHex,]);
            return;
        }
        $commissionRow = $this->connection->fetchAssociative('SELECT HEX(id) AS id_hex, commission_percent_applied FROM salesrep_commission WHERE order_id = UNHEX(:orderId) LIMIT 1', ['orderId' => $orderIdHex]);
        if (!$commissionRow) {
            return;
        }
        $percentApplied = (float)$commissionRow['commission_percent_applied'];
        $orderNet = $this->calculateOrderNet($order);
        $effectiveNet = max(0.0, $orderNet - $returnedNet);
        $newAmount = round(($effectiveNet * $percentApplied) / 100, 2);
        $this->connection->executeStatement('UPDATE salesrep_commission SET commission_amount = :amount, updated_at = NOW() WHERE id = UNHEX(:idHex)', ['amount' => $newAmount, 'idHex' => (string)$commissionRow['id_hex'],]);
        $this->logger->info('[CommercialReturnSubscriber] Commission updated after return', ['orderId' => $orderIdHex, 'orderNet' => $orderNet, 'returnedNet' => $returnedNet, 'percentApplied' => $percentApplied, 'commissionAmt' => $newAmount,]);
    }

    private function getReturnedNetTotalForLineItem(string $orderLineItemIdHex, Context $ctx): float
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('orderLineItemId', $orderLineItemIdHex));
        $result = $this->orderReturnLineItemRepository->search($criteria, $ctx);
        $totalNet = 0.0;
        /** @var OrderReturnLineItemEntity $item */
        foreach ($result->getEntities() as $item) {
            $price = $item->getPrice();
            if ($price === null) {
                continue;
            }
            $gross = (float)$price->getTotalPrice();
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
        $lineItems = $order->getLineItems();
        if ($lineItems === null || $lineItems->count() === 0) {
            return 0.0;
        }
        foreach ($lineItems as $line) {
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
}
