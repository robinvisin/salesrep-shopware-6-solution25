<?php

declare(strict_types=1);

namespace SalesAgent\Controller;

use SalesAgent\Service\OrderClaimRequestService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class OrderClaimRequestController extends AbstractController
{
    public function __construct(
        private readonly OrderClaimRequestService $svc
    ) {
    }

    #[Route(
        path: '/api/_action/sales-agent/order-claim/request',
        name: 'api.action.sales_agent.order_claim.request',
        defaults: ['_acl' => ['order.editor']],
        methods: ['POST']
    )]
    public function requestClaim(Request $request, Context $context): JsonResponse
    {
        $orderId = $this->readString($request, 'orderId');
        $reason  = $this->readNullableString($request, 'reason');

        $id = $this->svc->request($orderId, $reason, $context);

        return new JsonResponse(['status' => 'ok', 'requestId' => $id]);
    }

    #[Route(
        path: '/api/_action/sales-agent/order-claim/approve',
        name: 'api.action.sales_agent.order_claim.approve',
        defaults: ['_acl' => ['order.editor']],
        methods: ['POST']
    )]
    public function approve(Request $request, Context $context): JsonResponse
    {
        $requestId    = $this->readString($request, 'requestId');
        $decisionNote = $this->readNullableString($request, 'decisionNote');

        $this->svc->approve($requestId, $decisionNote, $context);

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(
        path: '/api/_action/sales-agent/order-claim/reject',
        name: 'api.action.sales_agent.order_claim.reject',
        defaults: ['_acl' => ['order.editor']],
        methods: ['POST']
    )]
    public function reject(Request $request, Context $context): JsonResponse
    {
        $requestId    = $this->readString($request, 'requestId');
        $decisionNote = $this->readNullableString($request, 'decisionNote');

        $this->svc->reject($requestId, $decisionNote, $context);

        return new JsonResponse(['status' => 'ok']);
    }

    private function readString(Request $request, string $key): string
    {
        $v = $request->request->get($key);
        if (\is_string($v) && $v !== '') {
            return $v;
        }

        $json = $this->readJson($request);
        if (\is_array($json) && \array_key_exists($key, $json)) {
            $jv = $json[$key];
            if (\is_string($jv)) {
                return $jv;
            }
            if (\is_int($jv) || \is_float($jv) || \is_bool($jv)) {
                return (string) $jv;
            }
        }

        return '';
    }

    private function readNullableString(Request $request, string $key): ?string
    {
        $v = $request->request->get($key);
        if (\is_string($v)) {
            $v = trim($v);
            return $v !== '' ? $v : null;
        }

        $json = $this->readJson($request);
        if (\is_array($json) && \array_key_exists($key, $json)) {
            $jv = $json[$key];

            if ($jv === null) {
                return null;
            }

            if (!\is_string($jv)) {
                $jv = (string) $jv;
            }

            $jv = trim($jv);
            return $jv !== '' ? $jv : null;
        }

        return null;
    }

    private function readJson(Request $request): ?array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return \is_array($data) ? $data : null;
    }
}
