<?php

declare(strict_types=1);

namespace SalesAgent\Storefront\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID],
])]
final class SalesAgentValidateSalesAgentEmailRouteController
{
    public function __construct(
        private readonly EntityRepository $userRepository
    ) {
    }

    #[Route(
        path: '/sales-agent/validate-email',
        name: 'frontend.sales-agent.validate-email',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_csrf_protected' => false]
    )]
    public function validate(Request $request, SalesChannelContext $context): JsonResponse
    {
        $imitatingUserId = $context->getImitatingUserId();
        if (!$imitatingUserId) {
            throw new AccessDeniedHttpException('Only Sales Agents can validate split emails.');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->ok(false, 'invalid_format');
        }

        $caller = $this->userRepository
            ->search((new Criteria([$imitatingUserId]))->setLimit(1), $context->getContext())
            ->getEntities()->first();

        if ($caller && strcasecmp($email, (string) $caller->getEmail()) === 0) {
            return $this->ok(false, 'same_email');
        }

        $criteria = (new Criteria())
        ->addFilter(new EqualsFilter('email', $email))
        ->setLimit(1);

        $targetUser = $this->userRepository->search($criteria, $context->getContext())->getEntities()->first();

        if (!$targetUser) {
            return $this->ok(false, 'not_found');
        }


        $targetCF = $targetUser->getCustomFields() ?? [];
        $targetIsAgent = in_array(($targetCF['is_sales_agent'] ?? false), [true, 1, '1'], true);

        if (!$targetIsAgent) {
            return $this->ok(false, 'not_sales_agent');
        }

        return $this->ok(true);
    }

    private function ok(bool $valid, ?string $reason = null): JsonResponse
    {
        return new JsonResponse(['valid' => $valid, 'reason' => $reason]);
    }
}
