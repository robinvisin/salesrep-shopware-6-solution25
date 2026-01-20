<?php

declare(strict_types=1);

namespace Salesrep\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

final class DiscountCalculator
{
    public function __construct(private readonly NumberResolver $nums)
    {
    }

    /** @return array{0: float, 1: float} */
    public function computeFromOrder(OrderEntity $order): array
    {
        $base = 0.0;
        $disc = 0.0;

        foreach ($order->getLineItems() as $li) {
            if (!$li instanceof OrderLineItemEntity || $li->getType() !== 'product') {
                continue;
            }

            $qty = (float) ($li->getQuantity() ?? 1);
            $payload   = $li->getPayload() ?? [];
            $finalUnit = $this->nums->readNumber($payload['saFinalUnitPrice'] ?? null);
            $origUnit  = $this->nums->readNumber($payload['saOriginalUnitPrice'] ?? null);

            if ($origUnit === null || $finalUnit === null) {
                $price = $li->getPrice();
                if ($price !== null) {
                    $unit = $qty > 0.0 ? ((float) $price->getTotalPrice() / $qty) : 0.0;
                    $origUnit  ??= $unit;
                    $finalUnit ??= $unit;
                } else {
                    $origUnit  ??= 0.0;
                    $finalUnit ??= 0.0;
                }
            }

            $base += \max($origUnit, 0.0) * $qty;
            $disc += \max($origUnit - $finalUnit, 0.0) * $qty;
        }

        return [$base, $disc];
    }
}
