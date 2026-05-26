<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Psr\Log\LoggerInterface;
use SalesAgent\Core\Content\OrderClaimRequest\OrderClaimRequestEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\User\UserEntity;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderClaimRequestService
{
    private const CF_CLAIMED_USER_ID = 'sales_agent_claimed_user_id';
    private const CF_CLAIMED_BY_ID   = 'sales_agent_claimed_by_user_id';
    private const CF_CLAIMED_AT      = 'sales_agent_claimed_at';
    private const CF_CLAIM_REASON    = 'sales_agent_claim_reason';

    /**
     * @param EntityRepository $orderRepo
     * @param EntityRepository $claimReqRepo
     * @param EntityRepository $salesAgentConfigRepo
     * @param AgentResolver $agentResolver
     * @param OrderCommissionRecalculator $recalculator
     * @param EntityRepository $userRepo
     * @param AbstractMailService $mailService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityRepository $orderRepo,
        private readonly EntityRepository $claimReqRepo,
        private readonly EntityRepository $salesAgentConfigRepo,
        private readonly AgentResolver $agentResolver,
        private readonly OrderCommissionRecalculator $recalculator,
        private readonly EntityRepository $userRepo,
        private readonly AbstractMailService $mailService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $orderId
     * @param string|null $reason
     * @param Context $context
     * @return string
     */
    public function request(string $orderId, ?string $reason, Context $context): string
    {
        if (!Uuid::isValid($orderId)) {
            throw new BadRequestHttpException('Invalid orderId');
        }

        $userId = $this->agentResolver->resolve($context);
        if (!$userId || !Uuid::isValid($userId)) {
            throw new AccessDeniedHttpException('No acting user');
        }
        $orderCriteria = (new Criteria([$orderId]))
            ->addAssociation('stateMachineState')
            ->setLimit(1);

        /** @var OrderEntity|null $order */
        $order = $this->orderRepo->search($orderCriteria, $context)->first();

        if (!$order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $orderState = $order->getStateMachineState()?->getTechnicalName();
        if ($orderState === 'cancelled') {
            throw new BadRequestHttpException('Claim request cannot be created for cancelled orders.');
        }


        // if (!$this->hasAgentConfig($userId, $context)) {
        //     throw new AccessDeniedHttpException('Only sales agents can request a claim.');
        // }

        $pending = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION))
            ->addFilter(new EqualsFilter('status', OrderClaimRequestEntity::STATUS_PENDING))
            ->setLimit(1);

        if ($this->claimReqRepo->search($pending, $context)->count() > 0) {
            throw new ConflictHttpException('There is already a pending claim request for this order.');
        }

        $id = Uuid::randomHex();
        $now = new \DateTimeImmutable('now');

        $this->claimReqRepo->create([[
            'id' => $id,
            'orderId' => $orderId,
            'orderVersionId' => Defaults::LIVE_VERSION,
            'requestedByUserId' => $userId,
            'status' => OrderClaimRequestEntity::STATUS_PENDING,
            'reason' => $reason !== null ? trim($reason) : null,
            'requestedAt' => $now,
        ]], $context);

        return $id;
    }

    /**
     * @param string $requestId
     * @param string|null $decisionNote
     * @param Context $context
     * @return void
     */
    public function approve(string $requestId, ?string $decisionNote, Context $context): void
    {
        $adminId = $this->requireAdminUserId($context);

        $req = $this->getRequestOrFail($requestId, $context);
        $this->assertApproverCanDecide($adminId, $req, $context);
        if ($req->getStatus() !== OrderClaimRequestEntity::STATUS_PENDING) {
            throw new ConflictHttpException('Request is not pending.');
        }

        $nowStr = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');

        $this->orderRepo->update([[
            'id' => $req->getOrderId(),
            'versionId' => Defaults::LIVE_VERSION,
            'customFields' => [
                self::CF_CLAIMED_USER_ID => $req->getRequestedByUserId(),
                self::CF_CLAIMED_BY_ID   => $adminId,
                self::CF_CLAIMED_AT      => $nowStr,
                self::CF_CLAIM_REASON    => $req->getReason(),
            ],
        ]], $context);

        $this->claimReqRepo->update([[
            'id' => $requestId,
            'status' => OrderClaimRequestEntity::STATUS_APPROVED,
            'decidedByUserId' => $adminId,
            'decidedAt' => new \DateTimeImmutable('now'),
            'decisionNote' => $decisionNote !== null ? trim($decisionNote) : null,
        ]], $context);

        $context->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($req): void {
            $this->recalculator->recalcForOrderId($req->getOrderId(), $system);
        });

        $this->safeEmailAgentDecision($req, true, $decisionNote, $context);
    }

    /**
     * @param string $requestId
     * @param string|null $decisionNote
     * @param Context $context
     * @return void
     */
    public function reject(string $requestId, ?string $decisionNote, Context $context): void
    {
        $adminId = $this->requireAdminUserId($context);

        $req = $this->getRequestOrFail($requestId, $context);
        $this->assertApproverCanDecide($adminId, $req, $context);
        if ($req->getStatus() !== OrderClaimRequestEntity::STATUS_PENDING) {
            throw new ConflictHttpException('Request is not pending.');
        }

        $this->claimReqRepo->update([[
            'id' => $requestId,
            'status' => OrderClaimRequestEntity::STATUS_REJECTED,
            'decidedByUserId' => $adminId,
            'decidedAt' => new \DateTimeImmutable('now'),
            'decisionNote' => $decisionNote !== null ? trim($decisionNote) : null,
        ]], $context);

        $this->safeEmailAgentDecision($req, false, $decisionNote, $context);
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return void
     */
    public function cancel(string $orderId, Context $context): void
    {
        if (!Uuid::isValid($orderId)) {
            throw new BadRequestHttpException('Invalid orderId');
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $orderId))
            ->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION))
            ->addFilter(new EqualsFilter('status', OrderClaimRequestEntity::STATUS_PENDING));

        $reqs = $this->claimReqRepo->search($criteria, $context);

        if ($reqs->count() === 0) {
            return;
        }

        $updates = [];
        foreach ($reqs as $req) {
            $updates[] = [
                'id' => $req->getId(),
                'status' => OrderClaimRequestEntity::STATUS_CANCELLED,
                'decidedAt' => new \DateTimeImmutable('now'),
            ];
        }

        $this->claimReqRepo->update($updates, $context);
    }

    /**
     * @param OrderClaimRequestEntity $req
     * @param bool $approved
     * @param string|null $decisionNote
     * @param Context $context
     * @return void
     */
    private function safeEmailAgentDecision(
        OrderClaimRequestEntity $req,
        bool $approved,
        ?string $decisionNote,
        Context $context
    ): void {
        try {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($req, $approved, $decisionNote): void {
                $this->sendDecisionMail($req, $approved, $decisionNote, $system);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('[OrderClaim] Failed to email agent decision', [
                'requestId' => $req->getId(),
                'orderId' => $req->getOrderId(),
                'requestedByUserId' => $req->getRequestedByUserId(),
                'approved' => $approved,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param OrderClaimRequestEntity $req
     * @param bool $approved
     * @param string|null $decisionNote
     * @param Context $context
     * @return void
     */
    private function sendDecisionMail(
        OrderClaimRequestEntity $req,
        bool $approved,
        ?string $decisionNote,
        Context $context
    ): void {
        $agent = $this->getUserOrFail($req->getRequestedByUserId(), $context);

        $toEmail = (string) $agent->getEmail();
        if ($toEmail === '') {
            throw new \RuntimeException('Agent has no email.');
        }

        [$orderNumber, $salesChannelId] = $this->getOrderMeta($req->getOrderId(), $context);

        $statusLabel = $approved ? 'APPROVED' : 'REJECTED';

        $subject = sprintf('Order claim %s — Order %s', $statusLabel, $orderNumber);

        $plain = trim(sprintf(
            "Your order claim was %s.\n\nOrder: %s\nReason: %s\nDecision note: %s\n\n— Sales Agent System",
            $statusLabel,
            $orderNumber,
            $req->getReason() ?: '-',
            ($decisionNote !== null && trim($decisionNote) !== '') ? trim($decisionNote) : '-'
        ));

        $html = nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $data = [
            'recipients' => [
                $toEmail => $this->formatUserName($agent),
            ],
            'senderName' => 'Sales Agent System',
            'subject' => $subject,
            'contentPlain' => $plain,
            'contentHtml' => $html,
            'salesChannelId' => $salesChannelId,
        ];

        $this->mailService->send($data, $context);
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return array
     */
    private function getOrderMeta(string $orderId, Context $context): array
    {
        if (!Uuid::isValid($orderId)) {
            throw new BadRequestHttpException('Invalid orderId');
        }

        $criteria = (new Criteria([$orderId]))
            ->setLimit(1);

        /** @var OrderEntity|null $order */
        $order = $this->orderRepo->search($criteria, $context)->first();
        if (!$order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $orderNumber = (string) ($order->getOrderNumber() ?: $orderId);

        $salesChannelId = (string) $order->getSalesChannelId();
        if ($salesChannelId === '' || !Uuid::isValid($salesChannelId)) {
            $salesChannelId = Defaults::SALES_CHANNEL;
        }

        return [$orderNumber, $salesChannelId];
    }

    /**
     * @param string $userId
     * @param Context $context
     * @return UserEntity
     */
    private function getUserOrFail(string $userId, Context $context): UserEntity
    {
        if (!Uuid::isValid($userId)) {
            throw new BadRequestHttpException('Invalid userId');
        }

        $criteria = (new Criteria([$userId]))->setLimit(1);

        /** @var UserEntity|null $u */
        $u = $this->userRepo->search($criteria, $context)->first();
        if (!$u) {
            throw new NotFoundHttpException('Agent user not found.');
        }

        return $u;
    }

    private function formatUserName(UserEntity $u): string
    {
        $first = trim((string) $u->getFirstName());
        $last  = trim((string) $u->getLastName());
        $name = trim($first . ' ' . $last);

        return $name !== '' ? $name : ((string) $u->getUsername() ?: (string) $u->getEmail());
    }

    private function hasAgentConfig(string $userId, Context $context): bool
    {
        $c = (new Criteria())->addFilter(new EqualsFilter('userId', $userId))->setLimit(1);
        return $this->salesAgentConfigRepo->search($c, $context)->count() > 0;
    }

    private function requireAdminUserId(Context $context): string
    {
        $src = $context->getSource();
        if (!$src instanceof AdminApiSource) {
            throw new AccessDeniedHttpException('Admin API only.');
        }

        $adminId = (string)($src->getUserId() ?? '');
        if ($adminId === '' || !Uuid::isValid($adminId)) {
            throw new AccessDeniedHttpException('Missing admin user id.');
        }

        return $adminId;
    }

    private function assertApproverCanDecide(string $adminId, OrderClaimRequestEntity $req, Context $context): void
    {
        if ($adminId === $req->getRequestedByUserId()) {
            throw new AccessDeniedHttpException('You cannot decide your own claim request.');
        }

        if ($this->isSalesAgentUser($adminId, $context)) {
            throw new AccessDeniedHttpException('Sales agents cannot approve or reject claim requests.');
        }
    }

    private function isSalesAgentUser(string $userId, Context $context): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        /** @var UserEntity|null $user */
        $user = $this->userRepo->search((new Criteria([$userId]))->setLimit(1), $context)->first();
        if (!$user) {
            return false;
        }

        // Never classify global admins as sales agents for claim decisions.
        $isAdmin = \method_exists($user, 'isAdmin')
            ? (bool) $user->isAdmin()
            : false;
        if ($isAdmin) {
            return false;
        }

        $cfg = (new Criteria())
            ->addFilter(new EqualsFilter('userId', $userId))
            ->setLimit(1);

        if ($this->salesAgentConfigRepo->search($cfg, $context)->count() > 0) {
            return true;
        }

        $cf = $user->getCustomFields() ?? [];
        $flag = $cf['is_sales_agent'] ?? $cf['sales_agent'] ?? false;

        return \in_array($flag, [true, 1, '1'], true);
    }

    /**
     * @param string $id
     * @param Context $context
     * @return OrderClaimRequestEntity
     */
    private function getRequestOrFail(string $id, Context $context): OrderClaimRequestEntity
    {
        if (!Uuid::isValid($id)) {
            throw new BadRequestHttpException('Invalid requestId');
        }

        $criteria = (new Criteria([$id]))->setLimit(1);

        /** @var OrderClaimRequestEntity|null $req */
        $req = $this->claimReqRepo->search($criteria, $context)->first();
        if (!$req) {
            throw new NotFoundHttpException('Claim request not found.');
        }

        return $req;
    }
}
