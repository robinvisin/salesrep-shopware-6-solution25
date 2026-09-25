<?php

declare(strict_types=1);

namespace SalesAgent\Service\ScheduledTask;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(handles: CancelUnpaidOrdersTask::class)]
class CancelUnpaidOrdersTaskHandler extends ScheduledTaskHandler
{
    private const PAYPAL_PAY_BY_LINK_TECHNICAL_NAME = 's25_paypal_pay_by_link';
    private const PAYPAL_LAST_SENT_CF_KEY = 'paypal_invoice_last_sent_at';

    private EntityRepository $transactionRepository;
    private StateMachineRegistry $stateMachineRegistry;
    private ?LoggerInterface $logger;
    private int $secondsBeforeCancel;

    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> */
        EntityRepository $scheduledTaskRepository,
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> */
        EntityRepository $transactionRepository,
        StateMachineRegistry $stateMachineRegistry,
        ?LoggerInterface $logger = null,
        int $secondsBeforeCancel = 172800,
        LoggerInterface $exceptionLogger
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);

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
        $context = Context::createCLIContext();
        $context->addState(Context::SKIP_TRIGGER_FLOW);
        $context->addState('paypal_auto_cancel_in_progress');

        $threshold = (new DateTimeImmutable())->sub(
            new DateInterval('PT' . $this->secondsBeforeCancel . 'S')
        );


        $criteria = (new Criteria())
            ->addAssociation('order.stateMachineState')
            ->addAssociation('stateMachineState')
            ->addAssociation('paymentMethod')
            ->addFilter(new EqualsFilter('paymentMethod.technicalName', self::PAYPAL_PAY_BY_LINK_TECHNICAL_NAME))
            ->addFilter(new EqualsFilter('stateMachineState.technicalName', OrderTransactionStates::STATE_OPEN))
            ->addFilter(new NotFilter(
                NotFilter::CONNECTION_AND,
                [new EqualsFilter('order.stateMachineState.technicalName', OrderStates::STATE_CANCELLED)]
            ))
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


            foreach ($transactions as $tx) {
                $order = $tx->getOrder();
                if (!$order) {
                    continue;
                }
                // first check before processing
                $txCustomFields = $tx->getCustomFields() ?? [];
                $cancelMarker = $txCustomFields['auto_cancel_in_progress'] ?? null;
                if ($cancelMarker !== null) {
                    $this->logger?->debug('Transaction already marked for auto-cancellation, skipping', [
                        'orderId' => $order->getId(),
                        'transactionId' => $tx->getId(),
                        'markedAt' => $cancelMarker,
                    ]);
                    continue;
                }



                // second check if transaction is already cancelled
                $txState = $tx->getStateMachineState()?->getTechnicalName();
                if ($txState !== OrderTransactionStates::STATE_OPEN) {
                    $this->logger?->debug('Transaction not in OPEN state, skipping', [
                        'orderId' => $order->getId(),
                        'transactionId' => $tx->getId(),
                        'txState' => $txState,
                    ]);
                    continue;
                }



                // third check if order is already cancelled
                $orderState = $order->getStateMachineState()?->getTechnicalName();
                if ($orderState === OrderStates::STATE_CANCELLED) {
                    $this->logger?->debug('Order already cancelled, skipping', [
                        'orderId' => $order->getId(),
                        'transactionId' => $tx->getId(),
                    ]);
                    continue;
                }

                $lastSentRaw = $txCustomFields[self::PAYPAL_LAST_SENT_CF_KEY] ?? null;


                if (!is_string($lastSentRaw) || $lastSentRaw === '') {
                    continue;
                }

                try {
                    $lastSent = new DateTimeImmutable($lastSentRaw);
                } catch (Throwable) {
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
                } catch (Throwable $e) {
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
        //  prevents reprocessing
        $existingCustomFields = $tx->getCustomFields() ?? [];
        $updatedCustomFields = array_merge($existingCustomFields, [
            'auto_cancel_in_progress' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);

        $this->transactionRepository->update([
            [
                'id' => $tx->getId(),
                'customFields' => $updatedCustomFields,
            ],
        ], $context);

        // re-check states prevent duplicate cancelations
        $txState = $tx->getStateMachineState()?->getTechnicalName();
        if ($txState !== OrderTransactionStates::STATE_CANCELLED) {
            try {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderTransactionDefinition::ENTITY_NAME,
                        $tx->getId(),
                        'cancel',
                        'stateId'
                    ),
                    $context
                );
            } catch (Throwable $e) {
                $this->logger?->debug('Transaction transition failed (might already be cancelled)', [
                    'transactionId' => $tx->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }




        $orderState = $order->getStateMachineState()?->getTechnicalName();
        if ($orderState !== OrderStates::STATE_CANCELLED) {
            try {
                $this->stateMachineRegistry->transition(
                    new Transition(
                        OrderDefinition::ENTITY_NAME,
                        $order->getId(),
                        'cancel',
                        'stateId'
                    ),
                    $context
                );
            } catch (Throwable $e) {
                $this->logger?->debug('Order transition failed (might already be cancelled)', [
                    'orderId' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
