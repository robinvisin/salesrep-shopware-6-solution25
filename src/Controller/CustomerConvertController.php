<?php

declare(strict_types=1);

namespace SalesAgent\Controller;

use Shopware\Core\Checkout\Customer\SalesChannel\AbstractSendPasswordRecoveryMailRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class CustomerConvertController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $salesChannelDomainRepository,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly AbstractSendPasswordRecoveryMailRoute $recoveryRoute,
    ) {
    }

    #[Route(
        path: '/api/_action/sales-agent/customer/convert-guest',
        name: 'api.action.sales_agent.customer.convert_guest',
        methods: ['POST'],
    )]
    public function convert(Request $request, Context $context): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?? [];
        $customerId = (string) ($data['customerId'] ?? '');

        if (!Uuid::isValid($customerId)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid customerId'], 400);
        }

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();

        if ($customer === null) {
            return new JsonResponse(['success' => false, 'message' => 'Customer not found'], 404);
        }

        if (!$customer->getGuest()) {
            return new JsonResponse(['success' => false, 'message' => 'Customer is already a registered user'], 400);
        }

        $email = $customer->getEmail();
        $salesChannelId = $customer->getSalesChannelId();

        $duplicateCriteria = new Criteria();
        $duplicateCriteria->addFilter(new EqualsFilter('email', $email));
        $duplicateCriteria->addFilter(new EqualsFilter('guest', false));
        $duplicateCriteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('id', $customerId),
        ]));
        $duplicateCriteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('salesChannelId', $salesChannelId),
            new EqualsFilter('boundSalesChannelId', null),
            new EqualsFilter('boundSalesChannelId', $salesChannelId),
        ]));
        $duplicateCriteria->setLimit(1);

        if ($this->customerRepository->search($duplicateCriteria, $context)->count() > 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'A registered account with this email address already exists',
            ], 409);
        }

        $this->customerRepository->update([[
            'id' => $customerId,
            'guest' => false,
        ]], $context);

        $storefrontUrl = $this->resolveStorefrontUrl($salesChannelId, $context);

        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannelId,
            [],
        );

        $this->recoveryRoute->sendRecoveryMail(
            new RequestDataBag([
                'email' => $email,
                'storefrontUrl' => $storefrontUrl,
            ]),
            $salesChannelContext,
            false,
        );

        return new JsonResponse(['success' => true]);
    }

    private function resolveStorefrontUrl(string $salesChannelId, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->setLimit(1);

        $domain = $this->salesChannelDomainRepository->search($criteria, $context)->first();

        return $domain !== null ? rtrim((string) $domain->getUrl(), '/') : '';
    }
}
