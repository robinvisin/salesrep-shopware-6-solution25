<?php

declare(strict_types=1);

namespace Salesrep\Service\ScheduledTask;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class CancelUnpaidOrdersTaskHandler extends ScheduledTaskHandler
{
    private const PAYPAL_PAY_BY_LINK_TECHNICAL_NAME = 's25_paypal_pay_by_link';
    private const PAYPAL_LAST_SENT_CF_KEY = 'paypal_invoice_last_sent_at';

    private EntityRepository $transactionRepository;
    private StateMachineRegistry $stateMachineRegistry;
    private ?LoggerInterface $logger;
    private int $secondsBeforeCancel;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $transactionRepository,
        StateMachineRegistry $stateMachineRegistry,
        ?LoggerInterface $logger = null,
        int $secondsBeforeCancel = 172800
    ) {
        parent::__construct($scheduledTaskRepository);

        $this->transactionRepository = $transactionRepository;
        $this->stateMachineRegistry  = $stateMachineRegistry;
        $this->logger                = $logger;
        $this->secondsBeforeCancel   = $secondsBeforeCancel;
    }

    public static function getHandledMessages(): iterable
    {
        return [CancelUnpaidOrdersTask::class];
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();

        $threshold = (new DateTimeImmutable())->sub(
            new DateInterval('PT' . $this->secondsBeforeCancel . 'S')
        );

        $criteria = (new Criteria())
            ->addAssociation('order.stateMachineState')
            ->addAssociation('stateMachineState')
            ->addAssociation('paymentMethod')
            ->addFilter(new EqualsFilter('paymentMethod.technicalName', self::PAYPAL_PAY_BY_LINK_TECHNICAL_NAME))
            ->addFilter(new EqualsFilter('stateMachineState.technicalName', OrderTransactionStates::STATE_OPEN))
            ->setLimit(200);

        $offset = 0;

        do {
            $criteria->setOffset($offset);

            $result = $this->transactionRepository->search($criteria, $context);
            $transactions = $result->getEntities();
            $count = $transactions->count();

            if ($count === 0) {
                break;
            }

            /** @var OrderTransactionEntity $tx */
            foreach ($transactions as $tx) {
                $order = $tx->getOrder();
                if (!$order) {
                    continue;
                }

                $txState = $tx->getStateMachineState()?->getTechnicalName();
                if ($txState !== OrderTransactionStates::STATE_OPEN) {
                    continue;
                }

                $txCustomFields = $tx->getCustomFields() ?? [];
                $lastSentRaw = $txCustomFields[self::PAYPAL_LAST_SENT_CF_KEY] ?? null;

                if (!is_string($lastSentRaw) || $lastSentRaw === '') {
                    continue;
                }

                try {
                    $lastSent = new DateTimeImmutable($lastSentRaw);
                } catch (\Throwable) {
                    $this->logger?->warning('Invalid paypal_invoice_last_sent_at value', [
                        'orderId' => $order->getId(),
                        'transactionId' => $tx->getId(),
                        'value' => $lastSentRaw,
                    ]);
                    continue;
                }

                if ($lastSent > $threshold) {
                    continue;
                }

                try {
                    $this->cancelOrderAndTransaction($order, $tx, $context);

                    $this->logger?->info(sprintf(
                        'Cancelled unpaid PayPal Pay By Link order %s after %d seconds since payment link email was sent.',
                        $order->getOrderNumber(),
                        $this->secondsBeforeCancel
                    ));
                } catch (\Throwable $e) {
                    $this->logger?->error('Error cancelling unpaid PayPal Pay By Link order', [
                        'orderId' => $order->getId(),
                        'transactionId' => $tx->getId(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $offset += $count;
        } while ($count === $criteria->getLimit());
    }

    private function cancelOrderAndTransaction(OrderEntity $order, OrderTransactionEntity $tx, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                (string) $tx->getId(),
                'cancel',
                'stateId'
            ),
            $context
        );

        $this->stateMachineRegistry->transition(
            new Transition(
                OrderDefinition::ENTITY_NAME,
                (string) $order->getId(),
                'cancel',
                'stateId'
            ),
            $context
        );
    }
}
