<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SalesAgentPriceService
{
    public function __construct(
        private readonly QuantityPriceCalculator $calculator
    ) {
    }

    public function resolveActingUserId(SalesChannelContext $context): ?string
    {
        if ($context->getImitatingUserId()) {
            return $context->getImitatingUserId();
        }

        $src = $context->getContext()->getSource();
        if ($src instanceof AdminApiSource) {
            return $src->getUserId();
        }

        return null;
    }

    public function approvalNotExpired(?string $expiresAt): bool
    {
        if (!$expiresAt) {
            return false;
        }

        try {
            $expires = new \DateTimeImmutable($expiresAt);
            return $expires > new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }
    }

    public function getEffectiveLimit(float $baseLimit, ?array $approval): float
    {
        if (!is_array($approval)) {
            return $baseLimit;
        }

        $approvalLimit = isset($approval['effectiveLimit']) ? (float) $approval['effectiveLimit'] : 0.0;
        $expiresAt = $approval['expiresAt'] ?? null;

        if ($approvalLimit > 0.0 && $this->approvalNotExpired($expiresAt)) {
            return max($baseLimit, $approvalLimit);
        }

        return $baseLimit;
    }

    public function isWithinAllowedRange(float $custom, float $original, float $limit): bool
    {
        if ($custom > $original + 1e-6) {
            return false;
        }

        $minAllowed = $original * (1.0 - ($limit / 100.0));
        return !($limit > 0.0 && $custom + 1e-8 < $minAllowed);
    }

    public function calculatePrice(float $customPrice, TaxRuleCollection $taxRules, SalesChannelContext $context): CalculatedPrice
    {
        $definition = new QuantityPriceDefinition($customPrice, $taxRules, 1);
        return $this->calculator->calculate($definition, $context);
    }

    public function buildCalculatedPrice(float $customPrice, CalculatedPrice $calc, TaxRuleCollection $taxRules): CalculatedPrice
    {
        return new CalculatedPrice(
            $customPrice,
            $customPrice,
            $calc->getCalculatedTaxes() ?? new CalculatedTaxCollection(),
            $taxRules,
            1
        );
    }
}
