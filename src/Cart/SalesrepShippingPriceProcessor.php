<?php

declare(strict_types=1);

namespace Salesrep\Cart;

use Salesrep\Core\Content\SalesrepConfig\SalesrepConfigEntity;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class SalesrepShippingPriceProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly EntityRepository $salesrepConfigRepository,
        private readonly QuantityPriceCalculator $calculator,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {

        $agentId = $this->resolveActingUserId($context);
        if ($agentId === null) {
            return;
        }

        $cartExt = $toCalculate->getExtension('saCustomShipping');
        if (!$cartExt instanceof \Shopware\Core\Framework\Struct\ArrayEntity) {
            return;
        }

        $shippingId   = (string) ($cartExt->get('shippingId') ?? '');
        $customPrice  = (float) ($cartExt->get('customShippingPrice') ?? 0.0);
        $discountLimit = (float) ($cartExt->get('discountLimit') ?? 0.0);

        if ($shippingId === '' || $customPrice < 0.0) {
            return;
        }

        $deliveries = $toCalculate->getDeliveries();
        if ($deliveries === null || $deliveries->count() === 0) {
            return;
        }

        foreach ($deliveries as $delivery) {
            $shippingMethod = $delivery->getShippingMethod();
            if (!$shippingMethod || $shippingMethod->getId() !== $shippingId) {
                continue;
            }

            $shippingCosts = $delivery->getShippingCosts();
            $originalPrice = (float) $shippingCosts->getUnitPrice();
            if ($originalPrice <= 0.0) {
                continue;
            }

            $effectiveLimit = $discountLimit;
            $cf = $shippingMethod->getCustomFields() ?? [];
            $approval = $cf['saApproval'] ?? null;
            if (is_array($approval)) {
                $approvalLimit = isset($approval['effectiveLimit']) ? (float) $approval['effectiveLimit'] : 0.0;
                $expiresAtStr  = (string) ($approval['expiresAt'] ?? '');
                if ($approvalLimit > 0.0 && $this->approvalNotExpired($expiresAtStr)) {
                    $effectiveLimit = max($effectiveLimit, $approvalLimit);
                } else {
                    $cf['saApproval'] = null;
                }
            }

            if ($customPrice > $originalPrice + 1e-6) {
                continue;
            }

            if ($customPrice > 0.0) {
                $minAllowed = $originalPrice * (1.0 - ($effectiveLimit / 100.0));

                if ($effectiveLimit > 0.0 && $customPrice + 1e-8 < $minAllowed) {
                    $toCalculate->addErrors(new SalesrepShippingLimitError(
                        $effectiveLimit,
                        $originalPrice,
                        $minAllowed,
                        $shippingMethod->getName() ?? 'Shipping'
                    ));
                    continue;
                }
            }


            $taxRules = $shippingCosts->getTaxRules() ?? new TaxRuleCollection();
            $definition = new QuantityPriceDefinition($customPrice, $taxRules, 1);
            $calculated = $this->calculator->calculate($definition, $context);

            $newShippingCosts = new CalculatedPrice(
                $customPrice,
                $customPrice,
                $calculated->getCalculatedTaxes() ?? new CalculatedTaxCollection(),
                $taxRules,
                1
            );

            $delivery->setShippingCosts($newShippingCosts);
        }
        $toCalculate->markModified();
    }

    private function resolveActingUserId(SalesChannelContext $sc): ?string
    {
        if ($sc->getImitatingUserId()) {
            return $sc->getImitatingUserId();
        }

        $src = $sc->getContext()->getSource();
        if ($src instanceof AdminApiSource) {
            return $src->getUserId();
        }

        return null;
    }

    private function resolveDiscountLimit(string $agentId, SalesChannelContext $context): float
    {
        /** @var SalesrepConfigEntity|null $cfg */
        $cfg = $this->salesrepConfigRepository
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentId)), $context->getContext())
            ->first();

        $perUser = $cfg?->getDiscountLimit();
        if ($perUser !== null && $perUser !== '') {
            return (float) $perUser;
        }

        $cfgVal = $this->systemConfig->get('Salesrep.config.discountLimit', $context->getSalesChannelId());
        if ($cfgVal === null || $cfgVal === '') {
            return 0.0;
        }

        if (is_string($cfgVal)) {
            $n = str_replace([' ', ','], ['', '.'], $cfgVal);
            return is_numeric($n) ? (float) $n : 0.0;
        }

        return (float) $cfgVal;
    }

    private function approvalNotExpired(string $expiresAtIso8601): bool
    {
        if ($expiresAtIso8601 === '') {
            return false;
        }

        try {
            $expires = new \DateTimeImmutable($expiresAtIso8601);
            return $expires > new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }
    }
}

final class SalesrepShippingLimitError extends Error
{
    public function __construct(
        private readonly float $limitPercent,
        private readonly float $originalUnit,
        private readonly float $minAllowed,
        private readonly string $label
    ) {
    }

    public function getMessageKey(): string
    {
        return 'salesrep-shipping-limit';
    }

    public function getParameters(): array
    {
        return [
            'limit'      => number_format($this->limitPercent, 2, '.', ''),
            'original'   => number_format($this->originalUnit, 2, '.', ''),
            'minAllowed' => number_format($this->minAllowed, 2, '.', ''),
            'label'      => $this->label,
        ];
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function getId(): string
    {
        return 'salesrep-shipping-limit';
    }

    public function blockOrder(): bool
    {
        return true;
    }
}
