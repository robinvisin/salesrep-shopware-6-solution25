<?php

    declare(strict_types=1);

    namespace SalesAgent\Subscriber;

    use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
    use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
    use Shopware\Core\Framework\Uuid\Uuid;
    use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;

    final class SalesAgentNoteOrderSubscriber implements EventSubscriberInterface
    {
        public function __construct(
            private readonly SalesChannelContextPersister $contextPersister,
            /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
            private readonly EntityRepository $orderRepository,
        ) {
        }

        public static function getSubscribedEvents(): array
        {
            return [
                CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            ];
        }

        public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
        {
            $context = $event->getSalesChannelContext();
            $token = $context->getToken();
            $salesChannelId = $context->getSalesChannelId();

            $persisted = $this->contextPersister->load($token, $salesChannelId);
            $raw = $persisted['sales_agent_note'] ?? $persisted['customerComment'] ?? null;

            if (!is_string($raw) || $raw === '') {
                return;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return;
            }

            $notes = trim((string) ($decoded['notes'] ?? ''));
            $doNotShipUntil = $decoded['do_not_ship_until'] ?? null;

            if ($notes === '' && empty($doNotShipUntil)) {
                return;
            }

            $orderId = $event->getOrderId();
            if (!Uuid::isValid($orderId)) {
                return;
            }

            $existingCustomFields = $event->getOrder()->getCustomFields() ?? [];
            $updatedCustomFields = $existingCustomFields;

            if ($notes !== '') {
                $updatedCustomFields['infoplus_salesAgentOrderNotes'] = $notes;
            }

            if (!empty($doNotShipUntil)) {
                $updatedCustomFields['infoplus_salesAgentDoNotShipUntil'] = $doNotShipUntil;
            }

            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $updatedCustomFields,
            ]], $event->getContext());

            $this->contextPersister->save($token, ['sales_agent_note' => null], $salesChannelId);
        }
    }

