<?php

declare(strict_types=1);

namespace Salesrep\Subscriber;

use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesrepCartToOrderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $cart = $event->getCart();
        $extension = $cart->getExtension('salesrep');

        if ($extension === null) {
            return;
        }

        $data = $extension->getVars();

        $converted    = $event->getConvertedCart();
        $customFields = $converted['customFields'] ?? [];

        if (!empty($data['notes'])) {
            $customFields['infoplus_salesrepOrderNotes'] = (string) $data['notes'];
        }

        if (!empty($data['do_not_ship_until'])) {
            $customFields['infoplus_salesrepDoNotShipUntil'] = $data['do_not_ship_until'];
        }

        $converted['customFields'] = $customFields;
        $event->setConvertedCart($converted);
    }
}
