<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class SalesAgentProductPriceOverrideSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_LOADED_EVENT => 'onProductLoaded',
        ];
    }

    public function onProductLoaded(EntityLoadedEvent $event): void
    {
        $ctx = $event->getContext();

        $ext = $ctx->getExtension('sa_custom_product_gross_prices');
        if (!$ext instanceof ArrayStruct) {
            return;
        }

        $map = $ext->all();
        if (empty($map)) {
            return;
        }

        $currencyId = method_exists($ctx, 'getCurrencyId') ? (string) $ctx->getCurrencyId() : '';

        foreach ($event->getEntities() as $entity) {
            if (!$entity instanceof ProductEntity) {
                continue;
            }

            $productId = $entity->getId();
            if (!isset($map[$productId])) {
                continue;
            }

            $gross = (float) $map[$productId];
            if ($gross <= 0.0 || $currencyId === '') {
                continue;
            }

            $price = new Price($currencyId, $gross, $gross, false);

            $entity->setPrice(new PriceCollection([$price]));
        }
    }
}
