<?php

declare(strict_types=1);

namespace SalesAgent\Storefront\Controller;

use SalesAgent\Service\SalesAgentGuard;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
final class SalesAgentCartSplitController extends AbstractController
{
    public function __construct(
        private readonly SalesChannelContextPersister $contextPersister,
        private readonly CartService $cartService,
        private readonly SalesAgentGuard $guard,
    ) {
    }

    #[Route(
        path: '/sales-agent/split',
        name: 'frontend.sales_agent.split',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => false]
    )]
    public function saveSplit(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->guard->isAgent($context)) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $data = json_decode((string) $request->getContent(), true) ?: [];

        $email = trim((string) ($data['email'] ?? ''));
        $percent = (int) ($data['percent'] ?? 0);

        if ($email === '' && $percent === 0) {
            $this->contextPersister->save(
                $context->getToken(),
                ['sales_agent_split' => null],
                $context->getSalesChannelId()
            );

            return new JsonResponse(['success' => true, 'cleared' => true]);
        }

        if ($email === '' || $percent < 1 || $percent > 100) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload'], 400);
        }

        $payload = [
            'email' => $email,
            'percent' => $percent,
        ];

        $this->contextPersister->save(
            $context->getToken(),
            ['sales_agent_split' => json_encode($payload, JSON_THROW_ON_ERROR)],
            $context->getSalesChannelId()
        );

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $cart->addExtension('sales_agent_split', new ArrayEntity($payload));
        $this->cartService->recalculate($cart, $context);

        return new JsonResponse(['success' => true]);
    }
}
