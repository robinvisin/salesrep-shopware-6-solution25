<?php

declare(strict_types=1);

namespace SalesAgent\Storefront\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[\Symfony\Component\Routing\Attribute\Route(defaults: ['_routeScope' => ['store-api']])]
class SalesAgentCartNoteController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly SalesChannelContextPersister $contextPersister,
    ) {
    }

    #[\Symfony\Component\Routing\Attribute\Route(
        path: '/store-api/sales-agent/cart/note',
        name: 'store-api.sales-agent.cart.note',
        methods: ['POST']
    )]
    public function setNote(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?: [];

        $flat = $data;
        if (isset($data['sales_agent']) && is_array($data['sales_agent'])) {
            $flat = $data['sales_agent'];
        }

        $notes = $flat['infoplus_salesAgentOrderNotes'] ?? '';
        $doNotShipUntil = $flat['infoplus_salesAgentDoNotShipUntil'] ?? null;

        $payload = [
            'notes' => (string) $notes,
            'do_not_ship_until' => $doNotShipUntil ?: null,
        ];
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->contextPersister->save(
            $salesChannelContext->getToken(),
            [
                'sales_agent_note' => $encodedPayload,
            ],
            $salesChannelContext->getSalesChannelId()
        );

        $cart = $this->cartService->getCart(
            $salesChannelContext->getToken(),
            $salesChannelContext
        );

        $cart->addExtension('sales_agent', new ArrayEntity($payload));
        $this->cartService->recalculate($cart, $salesChannelContext);

        return new JsonResponse(['success' => true]);
    }
}
