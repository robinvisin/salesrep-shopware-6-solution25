<?php

declare(strict_types=1);

namespace SalesAgent\Controller;

use SalesAgent\Service\OrderCommissionRecalculator;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]

final class OrderClaimController extends AbstractController
{
    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
        private readonly EntityRepository $orderRepo,
        private readonly OrderCommissionRecalculator $recalculator
    ) {
    }

    #[Route(
        path: '/api/_action/sales-agent/order/claim',
        name: 'api.action.sales_agent.order.claim',
        methods: ['POST'],
        defaults: ['_acl' => ['order.editor']]
    )]
    public function claim(Request $request, Context $context): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?: [];

        $orderId = (string)($data['orderId'] ?? '');
        $agentUserId = trim((string)($data['agentUserId'] ?? '')); // empty => unclaim
        $reason = trim((string)($data['reason'] ?? ''));

        if (!Uuid::isValid($orderId)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid orderId'], 400);
        }
        if ($agentUserId !== '' && !Uuid::isValid($agentUserId)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid agentUserId'], 400);
        }

        $managerId = null;
        $src = $context->getSource();
        if ($src instanceof AdminApiSource) {
            $managerId = $src->getUserId();
        }

        $cf = [
            'sales_agent_claim_reason' => $reason,
        ];

        if ($agentUserId === '') {
            $cf['sales_agent_claimed_user_id'] = null;
            $cf['sales_agent_claimed_by_user_id'] = null;
            $cf['sales_agent_claimed_at'] = null;
        } else {
            $cf['sales_agent_claimed_user_id'] = $agentUserId;
            $cf['sales_agent_claimed_by_user_id'] = $managerId;
            $cf['sales_agent_claimed_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        }

        $this->orderRepo->update([[
            'id' => $orderId,
            'customFields' => $cf,
        ]], $context);

        $this->recalculator->recalcForOrderId($orderId, $context);

        return new JsonResponse(['success' => true]);
    }
}
