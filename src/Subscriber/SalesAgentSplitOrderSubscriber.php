<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SalesAgentSplitOrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SalesChannelContextPersister $contextPersister,
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
        $raw = $persisted['sales_agent_split'] ?? null;

        if (!$raw) {
            return;
        }

        $split = json_decode((string) $raw, true);
        if (!is_array($split)) {
            return;
        }

        $email = trim((string) ($split['email'] ?? ''));
        $percent = (int) ($split['percent'] ?? 0);

        if ($email === '' || $percent <= 0 || $percent > 100) {
            return;
        }

        $orderId = $event->getOrderId();
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => [
                'sales_agent_split_email' => $email,
                'sales_agent_split_percent' => $percent,
            ],
        ]], $event->getContext());

        $this->contextPersister->save($token, ['sales_agent_split' => null], $salesChannelId);
    }
}
