<?php declare(strict_types=1);

namespace SalesAgent\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class SalesAgentValidateSalesAgentEmailActionController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $userRepository
    ) {}

    #[Route(
        path: '/api/_action/sales-agent/validate-email',
        name: 'api.action.sales_agent.validate_email',
        methods: ['POST'],
        defaults: ['_acl' => ['order.editor']]
    )]
    public function validate(RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $email = trim((string) $dataBag->get('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->ok(false, 'invalid_format');
        }

        $callerId = (string) ($context->getSource()?->getUserId() ?? '');
        if ($callerId === '') {
            return $this->ok(false, 'not_authenticated');
        }

        $caller = $this->userRepository
            ->search((new Criteria([$callerId]))->setLimit(1), $context)
            ->first();

        if ($caller && strcasecmp($email, (string) $caller->getEmail()) === 0) {
            return $this->ok(false, 'same_email');
        }

        $target = $this->userRepository
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('email', $email))->setLimit(1),
                $context
            )
            ->first();

        if (!$target) {
            return $this->ok(false, 'not_found');
        }

        $cf = $target->getCustomFields() ?? [];
        $isAgent = in_array(($cf['is_sales_agent'] ?? false), [true, 1, '1'], true);

        if (!$isAgent) {
            return $this->ok(false, 'not_sales_agent');
        }

        return $this->ok(true);
    }

    private function ok(bool $valid, ?string $reason = null): JsonResponse
    {
        return new JsonResponse(['valid' => $valid, 'reason' => $reason]);
    }
}
