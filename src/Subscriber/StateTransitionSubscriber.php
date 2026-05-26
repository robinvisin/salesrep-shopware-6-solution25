<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use SalesAgent\Service\CommissionUpserter;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class StateTransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CommissionUpserter $upserter,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderDeliveryRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [StateMachineTransitionEvent::class => 'onStateMachineTransition'];
    }

    public function onStateMachineTransition(StateMachineTransitionEvent $event): void
    {
        $entity = $event->getEntityName();
        $toTech = $event->getToPlace()->getTechnicalName();
        $ctx    = $event->getContext();

        if ($entity === 'order') {
            if ($toTech === OrderStates::STATE_CANCELLED) {
                $this->revokeCommission($event->getEntityId(), $ctx);
            }
            return;
        }

        if ($entity === 'order_transaction') {
            if (\in_array($toTech, [
                OrderTransactionStates::STATE_FAILED,
                OrderTransactionStates::STATE_CANCELLED,
                OrderTransactionStates::STATE_REFUNDED,
                OrderTransactionStates::STATE_PARTIALLY_REFUNDED,
            ], true)) {
                if ($orderId = $this->resolveOrderIdFromTransaction($event->getEntityId(), $ctx)) {
                    $this->revokeCommission($orderId, $ctx);
                }
            }
            return;
        }

        if ($entity === 'order_delivery') {
            if (\in_array($toTech, [
                OrderDeliveryStates::STATE_RETURNED,
                OrderDeliveryStates::STATE_PARTIALLY_RETURNED,
                OrderDeliveryStates::STATE_CANCELLED,
            ], true)) {
                if ($orderId = $this->resolveOrderIdFromDelivery($event->getEntityId(), $ctx)) {
                    $this->revokeCommission($orderId, $ctx);
                }
            }
            return;
        }
    }

    private function resolveOrderIdFromTransaction(string $transactionId, Context $context): ?string
    {
        /** @var OrderTransactionEntity|null $tx */
        $tx = $this->orderTransactionRepository
            ->search((new Criteria([$transactionId]))->addAssociation('order'), $context)
            ->first();

        return $tx?->getOrder()?->getId();
    }

    private function resolveOrderIdFromDelivery(string $deliveryId, Context $context): ?string
    {
        /** @var OrderDeliveryEntity|null $delivery */
        $delivery = $this->orderDeliveryRepository
            ->search((new Criteria([$deliveryId]))->addAssociation('order'), $context)
            ->first();

        return $delivery?->getOrder()?->getId();
    }

    private function revokeCommission(string $orderId, Context $context): void
    {
        $this->upserter->zeroOutByOrderId($orderId, $context);
    }
}
