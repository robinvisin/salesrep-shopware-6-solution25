<?php

declare(strict_types=1);

namespace Salesrep\Storefront\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class SalesrepCartNoteController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly SalesChannelContextPersister $contextPersister,
    ) {
    }

    #[Route(
        path: '/store-api/salesrep/cart/note',
        name: 'store-api.salesrep.cart.note',
        methods: ['POST']
    )]
    public function setNote(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?: [];

        $flat = $data;
        if (isset($data['salesrep']) && is_array($data['salesrep'])) {
            $flat = $data['salesrep'];
        }

        $notes = $flat['infoplus_salesrepOrderNotes'] ?? '';
        $doNotShipUntil = $flat['infoplus_salesrepDoNotShipUntil'] ?? null;

        $payload = [
            'notes' => (string) $notes,
            'do_not_ship_until' => $doNotShipUntil ?: null,
        ];

        $this->contextPersister->save(
            $salesChannelContext->getToken(),
            ['customerComment' => json_encode($payload, JSON_THROW_ON_ERROR)],
            $salesChannelContext->getSalesChannelId()
        );

        $cart = $this->cartService->getCart(
            $salesChannelContext->getToken(),
            $salesChannelContext
        );

        $cart->addExtension('salesrep', new ArrayEntity($payload));
        $this->cartService->recalculate($cart, $salesChannelContext);

        return new JsonResponse(['success' => true]);
    }
}
