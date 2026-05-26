<?php

declare(strict_types=1);

namespace SalesAgent\Cart;

use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use SalesAgent\Service\SalesAgentConfigProvider;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class SalesAgentPriceProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly SalesAgentConfigProvider $configProvider,
        private readonly QuantityPriceCalculator $calculator,
        private readonly SystemConfigService     $systemConfig
    ) {
    }

    public function process(
        CartDataCollection  $data,
        Cart                $original,
        Cart                $toCalculate,
        SalesChannelContext $context,
        CartBehavior        $behavior
    ): void {
        $agentId = $this->resolveActingUserId($context);
        if ($agentId === null) {
            return;
        }

        $discountLimit = $this->resolveDiscountLimit($agentId, $context);

        $customProductGrossMap = [];

        foreach ($toCalculate->getLineItems() as $item) {
            if ($item->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $customPrice = $item->getPayloadValue('saFinalUnitPrice');
            if ($customPrice === null) {
                $customPrice = $item->getPayloadValue('saCustomPrice');
            }
            if ($customPrice === null) {
                continue;
            }

            $customPrice = (float) $customPrice;

            if ($customPrice < 0.0) {
                $item->setPayloadValue('saFinalUnitPrice', null);
                $item->setPayloadValue('saCustomPrice', null);
                continue;
            }


            $qty = max(1, (int) ($item->getQuantity() ?? 1));

            $currentUnit  = (float) ($item->getPrice()?->getUnitPrice() ?? 0.0);
            $originalUnit = (float) ($item->getPayloadValue('saOriginalUnitPrice') ?? 0.0);

            if ($originalUnit <= 0.0) {
                $linePrice = $item->getPrice();
                $originalUnit = $linePrice
                    ? (float) $linePrice->getTotalPrice() / $qty
                    : $currentUnit;

                if ($originalUnit > 0.0) {
                    $item->setPayloadValue('saOriginalUnitPrice', $originalUnit);
                }
            }

            if ($originalUnit <= 0.0) {
                $item->setPayloadValue('saFinalUnitPrice', null);
                $item->setPayloadValue('saCustomPrice', null);
                continue;
            }

            $effectiveLimit = (float) $discountLimit;

            $approval = $item->getPayloadValue('saApproval');
            if (is_array($approval)) {
                $approvalLimit = isset($approval['effectiveLimit']) ? (float) $approval['effectiveLimit'] : 0.0;
                $expiresAtStr  = (string) ($approval['expiresAt'] ?? '');

                if ($approvalLimit > 0.0 && $this->approvalNotExpired($expiresAtStr)) {
                    $effectiveLimit = max($effectiveLimit, $approvalLimit);
                } else {
                    $item->setPayloadValue('saApproval', null);
                }
            }

            if ($customPrice > $originalUnit + 1e-6) {
                $item->setPayloadValue('saFinalUnitPrice', null);
                $item->setPayloadValue('saCustomPrice', null);
                continue;
            }

            if ($customPrice > 0.0) {
                $minAllowed = $originalUnit * (1.0 - ($effectiveLimit / 100.0));

                if ($effectiveLimit > 0.0 && $customPrice + 1e-8 < $minAllowed) {
                    $customPrice = $minAllowed;

                    $toCalculate->addErrors(new SalesAgentPriceLimitError(
                        $effectiveLimit,
                        $originalUnit,
                        $minAllowed,
                        (string) ($item->getLabel() ?? 'item')
                    ));
                }
            }
            $taxRules = $item->getPrice()?->getTaxRules() ?? new TaxRuleCollection();

            $definition = new QuantityPriceDefinition(
                $customPrice,
                $taxRules,
                $qty
            );

            $item->setPriceDefinition($definition);
            $item->setPrice($this->calculator->calculate($definition, $context));

            $discountPercent = max(0.0, ($originalUnit - $customPrice) / $originalUnit * 100.0);

            $item->setPayloadValue('saEffectiveDiscountPercent', round($discountPercent, 2));
            $item->setPayloadValue('saFinalUnitPrice', $customPrice);
            $item->setPayloadValue('saCustomPrice', $customPrice);

            $productId = (string) ($item->getReferencedId() ?? '');
            if ($productId !== '') {
                $customProductGrossMap[$productId] = $customPrice;
            }
        }

        if (!empty($customProductGrossMap)) {
            $dalContext = $context->getContext();
            $existing = $dalContext->getExtension('sa_custom_product_gross_prices');

            if ($existing instanceof ArrayStruct) {
                $merged = array_merge($existing->all(), $customProductGrossMap);
                $dalContext->addExtension('sa_custom_product_gross_prices', new ArrayStruct($merged));
            } else {
                $dalContext->addExtension('sa_custom_product_gross_prices', new ArrayStruct($customProductGrossMap));
            }
        }

            $toCalculate->markModified();
    }

    private function resolveActingUserId(SalesChannelContext $sc): ?string
    {
        $impersonated = $sc->getImitatingUserId();
        if ($impersonated) {
            return (string)$impersonated;
        }

        $src = $sc->getContext()->getSource();
        if ($src instanceof AdminApiSource) {
            return $src->getUserId();
        }

        return null;
    }

    private function resolveDiscountLimit(string $agentId, SalesChannelContext $context): float
    {
        /** @var SalesAgentConfigEntity|null $cfg */
        $cfg = $this->configProvider->getConfigByUserId($agentId, $context->getContext());

        $perUser = $cfg?->getDiscountLimit();
        if ($perUser !== null && $perUser !== '') {
            return (float)$perUser;
        }

        $cfgVal = $this->systemConfig->get('SalesAgent.config.discountLimit', $context->getSalesChannelId());
        if ($cfgVal === null || $cfgVal === '') {
            return 0.0;
        }
        if (is_string($cfgVal)) {
            $n = str_replace([' ', ','], ['', '.'], $cfgVal);
            return is_numeric($n) ? (float)$n : 0.0;
        }
        return (float)$cfgVal;
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

final class SalesAgentPriceLimitError extends Error
{
    public function __construct(
        private readonly float  $limitPercent,
        private readonly float  $originalUnit,
        private readonly float  $minAllowed,
        private readonly string $label
    ) {
    }

    public function getMessageKey(): string
    {
        return 'sales-agent-price-limit';
    }

    public function getParameters(): array
    {
        return [
            'limit' => number_format($this->limitPercent, 2, '.', ''),
            'original' => number_format($this->originalUnit, 2, '.', ''),
            'minAllowed' => number_format($this->minAllowed, 2, '.', ''),
            'label' => $this->label,
        ];
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function getId(): string
    {
        return 'sales-agent-price-limit';
    }

    public function blockOrder(): bool
    {
        return true;
    }
}
