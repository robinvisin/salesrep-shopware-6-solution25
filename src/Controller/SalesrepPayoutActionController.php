<?php

declare(strict_types=1);

namespace Salesrep\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class SalesrepPayoutActionController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $userRepository
    ) {
    }

    #[Route(
        path: '/api/_action/salesrep/agents/mark-paid',
        name: 'api.action.salesrep.agents.mark_paid',
        methods: ['POST'],
        defaults: ['_acl' => ['user:update']]
    )]
    public function markPaid(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $agentId = (string) $dataBag->get('agentId');
        $start = (string) $dataBag->get('start');
        $end = (string) $dataBag->get('end');
        $monthKey = (string) $dataBag->get('monthKey');

        if (!$agentId || !$monthKey) {
            return new JsonResponse(['success' => false, 'message' => 'Missing agentId/monthKey'], 400);
        }

        $sales = (float) ($dataBag->get('sales') ?? 0);
        $commission = (float) ($dataBag->get('commission') ?? 0);
        $orders = (int) ($dataBag->get('orders') ?? 0);

        $source = $context->getSource();
        $paidBy = $source instanceof AdminApiSource ? $source->getUserId() : null;

        $payload = [
            'id' => $agentId,
            'customFields' => [
                'salesrepPayouts' => [
                    $monthKey => [
                        'start' => $start,
                        'end' => $end,
                        'paid' => true,
                        'paidAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                        'paidBy' => $paidBy,
                        'sales' => $sales,
                        'commission' => $commission,
                        'orders' => $orders,
                    ],
                ],
            ],
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $scoped) use ($payload) {
            $this->userRepository->upsert([$payload], $scoped);
        });

        return new JsonResponse(['success' => true]);
    }
}
