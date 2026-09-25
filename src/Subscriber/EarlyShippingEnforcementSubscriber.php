<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class EarlyShippingEnforcementSubscriber implements EventSubscriberInterface
{
    private const INSTORE_PAYMENT_TECHNICAL = 'sw.instore.cash.card';
    private const INSTORE_SHIPPING_TECHNICAL = 'local_pickup';

    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Checkout\Shipping\ShippingMethodCollection> */
        private readonly EntityRepository $shippingMethodRepository,
        private readonly SalesChannelContextPersister $contextPersister
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextCreatedEvent::class => 'onContextCreated',
        ];
    }

    public function onContextCreated(SalesChannelContextCreatedEvent $event): void
    {
        $context = $event->getSalesChannelContext();

        if ($context->getImitatingUserId() === null) {
            return;
        }

        $payment = $context->getPaymentMethod();
        $paymentTechnical = strtolower((string) ($payment->getTechnicalName() ?? ''));
        if ($paymentTechnical !== self::INSTORE_PAYMENT_TECHNICAL) {
            return;
        }

        $shipping = $context->getShippingMethod();
        if (method_exists($shipping, 'getTechnicalName')) {
            $shippingTechnical = strtolower((string) ($shipping->getTechnicalName() ?? ''));
            if ($shippingTechnical === self::INSTORE_SHIPPING_TECHNICAL) {
                return;
            }
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', self::INSTORE_SHIPPING_TECHNICAL));
        $criteria->setLimit(1);

        /** @var ShippingMethodEntity|null $localPickup */
        $localPickup = $this->shippingMethodRepository->search(
            $criteria,
            $context->getContext()
        )->getEntities()->first();

        if ($localPickup === null) {
            return;
        }

        $context->assign(['shippingMethod' => $localPickup]);

        $this->contextPersister->save(
            $context->getToken(),
            ['shippingMethodId' => $localPickup->getId()],
            $context->getSalesChannelId(),
            $context->getCustomerId()
        );
    }
}
