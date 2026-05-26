<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\Request;

class CommissionValidatorService
{
    public function __construct(
        private readonly EntityRepository $userRepository,
    ) {
    }

    public function isOrderCommissionValid(OrderEntity $order, $agentId)
    {

    }

    public function setSessionValues(Request $request)
    {
        $request->getSession();
    }
}
