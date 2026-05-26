<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Shopware\Core\Checkout\Customer\Event\CustomerBeforeLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CustomerLoginSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
           CustomerLoginEvent::class => 'onCustomerLogin',
            CustomerBeforeLoginEvent::class => 'onCustomerBeforeLogin',
        ];
    }

    public function onCustomerLogin(CustomerLoginEvent $event): void
    {
        $customerEvent = $event;
    }

    public function onCustomerBeforeLogin(CustomerBeforeLoginEvent $event): void
    {

    }

}
