<?php

declare(strict_types=1);

namespace SalesAgent\Storefront\Controller;

use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[\Symfony\Component\Routing\Attribute\Route(defaults: ['_routeScope' => ['storefront']])]
final class SalesAgentShippingController extends StorefrontController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly EntityRepository $salesAgentConfigRepository,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    #[\Symfony\Component\Routing\Attribute\Route(
        path: '/sales-agent/shipping/price',
        name: 'frontend.sales_agent.set_shipping_price',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false, 'csrf_protected' => false],
        methods: ['POST']
    )]
    public function setShippingPrice(Request $request, SalesChannelContext $context): JsonResponse
    {
        $agentId = (string) ($context->getImitatingUserId() ?? '');
        if ($agentId === '') {
            return $this->jsonError('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $shippingId = (string) $request->request->get('shippingId', '');
        $priceRaw   = (string) $request->request->get('price', '');

        if ($shippingId === '' || $priceRaw === '') {
            return $this->jsonError('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        $newPrice = $this->toFloat($priceRaw);
        if ($newPrice < 0.0) {
            return $this->jsonError('Enter a valid price.', Response::HTTP_BAD_REQUEST);
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);

        $delivery = null;
        foreach ($cart->getDeliveries() as $d) {
            if ($d->getShippingMethod() && $d->getShippingMethod()->getId() === $shippingId) {
                $delivery = $d;
                break;
            }
        }

        if (!$delivery) {
            return $this->jsonError('Shipping method not found in cart', Response::HTTP_NOT_FOUND);
        }

        $salesChannelId = $context->getSalesChannelId();

        /** @var SalesAgentConfigEntity|null $cfg */
        $cfg = $this->salesAgentConfigRepository
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentId)), $context->getContext())
            ->first();

        $discountLimit = $this->resolveFloat(
            $cfg?->getDiscountLimit(),
            'SalesAgent.config.discountLimit',
            $salesChannelId
        );

        $shippingCosts = $delivery->getShippingCosts();
        $originalUnit  = (float) $shippingCosts->getUnitPrice();
        $test = $cart->getShippingCosts()->getTotalPrice();

        if ($originalUnit <= 0.0) {
            return $this->jsonError('Cannot determine original shipping price.', Response::HTTP_CONFLICT);
        }

        if ($newPrice > $originalUnit + 1e-6) {
            return $this->jsonError(
                sprintf('Shipping price cannot exceed %.2f.', $originalUnit),
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($newPrice > 0.0) {
            $minAllowed = $discountLimit > 0 ? $originalUnit * (1.0 - $discountLimit / 100.0) : $originalUnit;

            if ($discountLimit > 0 && $newPrice < $minAllowed - 1e-6) {
                return $this->jsonError(sprintf('Too low. Minimum allowed is %.2f.', $minAllowed), Response::HTTP_BAD_REQUEST);
            }
        }

        $cart->addExtension('saCustomShipping', new ArrayEntity([
            'customShippingPrice' => $newPrice,
            'shippingId'          => $shippingId,
            'originalPrice'       => $originalUnit,
            'discountLimit'       => $discountLimit,
        ]));
        $this->cartService->recalculate($cart, $context);

        return new JsonResponse([
            'success'        => true,
            'newPrice'       => $newPrice,
            'originalPrice'  => $originalUnit,
            'discountLimit'  => $discountLimit,
        ]);
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
