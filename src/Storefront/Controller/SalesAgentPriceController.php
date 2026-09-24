<?php

declare(strict_types=1);

namespace SalesAgent\Storefront\Controller;

use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[\Symfony\Component\Routing\Attribute\Route(defaults: ['_routeScope' => ['storefront']])]
final class SalesAgentPriceController extends StorefrontController
{
    public function __construct(
        private readonly CartService         $cartService,
        private readonly EntityRepository    $salesAgentConfigRepository,
        private readonly SystemConfigService $systemConfig,
    ) {
    }


    #[\Symfony\Component\Routing\Attribute\Route(
        path: '/sales-agent/line-item/price',
        name: 'frontend.sales_agent.set_price',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function setPrice(Request $request, SalesChannelContext $context): JsonResponse
    {
        $agentId = (string) ($context->getImitatingUserId() ?? '');
        if ($agentId === '') {
            return $this->jsonError('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $lineItemId = (string) $request->request->get('lineItemId', '');
        $priceRaw   = (string) $request->request->get('price', '');

        if ($lineItemId === '' || $priceRaw === '') {
            return $this->jsonError('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        $newPrice = $this->toFloat($priceRaw);
        if ($newPrice < 0.0) {
            return $this->jsonError('Enter a valid price (0 or more).', Response::HTTP_BAD_REQUEST);
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $lineItem = $cart->getLineItems()->get($lineItemId);
        if (!$lineItem instanceof LineItem) {
            return $this->jsonError('Line item not found', Response::HTTP_NOT_FOUND);
        }
        if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
            return $this->jsonError('Only product line items can be discounted.', Response::HTTP_BAD_REQUEST);
        }

        $salesChannelId = $context->getSalesChannelId();

        /** @var SalesAgentConfigEntity|null $cfg */
        $cfg = $this->salesAgentConfigRepository
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentId))->setLimit(1), $context->getContext())
            ->first();

        $discountLimit = $this->resolveFloat(
            $cfg?->getDiscountLimit(),
            'SalesAgent.config.discountLimit',
            $salesChannelId
        );

        $payload  = $lineItem->getPayload() ?? [];
        $origUnit = $this->toFloat($payload['saOriginalUnitPrice'] ?? null);

        if ($origUnit <= 0.0) {
            $qty = max(1, (int) ($lineItem->getQuantity() ?? 1));
            $linePrice = $lineItem->getPrice();
            $origUnit  = $linePrice ? (float) $linePrice->getTotalPrice() / $qty : 0.0;
        }
        if ($origUnit <= 0.0) {
            return $this->jsonError('Cannot determine original price.', Response::HTTP_CONFLICT);
        }

        if ($newPrice > $origUnit + 1e-6) {
            return $this->jsonError(sprintf('Price cannot exceed %.2f.', $origUnit), Response::HTTP_BAD_REQUEST);
        }

        if ($newPrice > 0.0) {
            $minAllowed = $discountLimit > 0 ? $origUnit * (1.0 - $discountLimit / 100.0) : $origUnit;

            if ($discountLimit > 0 && $newPrice < $minAllowed - 1e-6) {
                return $this->jsonError(sprintf('Too low. Minimum allowed is %.2f.', $minAllowed), Response::HTTP_BAD_REQUEST);
            }
        }

        if (!$lineItem->getPayloadValue('saOriginalUnitPrice')) {
            $lineItem->setPayloadValue('saOriginalUnitPrice', $origUnit);
        }
        $lineItem->setPayloadValue('saFinalUnitPrice', $newPrice);
        $lineItem->setPayloadValue('saCustomPrice', $newPrice);
        $lineItem->setPayloadValue('saEditedByAgentId', $agentId);

        $this->cartService->recalculate($cart, $context);

        return new JsonResponse(['success' => true]);
    }

    private function jsonError(string $msg, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $msg], $status);
    }

    private function toFloat(mixed $v): float
    {
        if ($v === null) {
            return 0.0;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        if (is_string($v)) {
            $n = str_replace([' ', ','], ['', '.'], $v);
            return is_numeric($n) ? (float) $n : 0.0;
        }
        return 0.0;
    }

    private function resolveFloat(mixed $perUser, string $configKey, ?string $salesChannelId): float
    {
        if ($perUser !== null && $perUser !== '') {
            return (float) $perUser;
        }
        $cfg = $this->systemConfig->get($configKey, $salesChannelId);
        if ($cfg === null || $cfg === '') {
            return 0.0;
        }

        if (is_string($cfg)) {
            $n = str_replace([' ', ','], ['', '.'], $cfg);
            return is_numeric($n) ? (float) $n : 0.0;
        }
        return (float) $cfg;
    }
}
