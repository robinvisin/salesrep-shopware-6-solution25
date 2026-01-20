<?php

declare(strict_types=1);

namespace Salesrep\Service;

use Psr\Log\LoggerInterface;
use Salesrep\Core\Content\OrderClaimRequest\OrderClaimRequestEntity;
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
    private const CF_CLAIMED_USER_ID = 'salesrep_claimed_user_id';
    private const CF_CLAIMED_BY_ID   = 'salesrep_claimed_by_user_id';
    private const CF_CLAIMED_AT      = 'salesrep_claimed_at';
    private const CF_CLAIM_REASON    = 'salesrep_claim_reason';

    public function __construct(
        private readonly EntityRepository $orderRepo,
        private readonly EntityRepository $claimReqRepo,
        private readonly EntityRepository $salesrepConfigRepo,
        private readonly AgentResolver $agentResolver,
        private readonly OrderCommissionRecalculator $recalculator,

        // email deps
        private readonly EntityRepository $userRepo,
        private readonly AbstractMailService $mailService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function request(string $orderId, ?string $reason, Context $context): string
    {
        if (!Uuid::isValid($orderId)) {
            throw new BadRequestHttpException('Invalid orderId');
        }

        $userId = $this->agentResolver->resolve($context);
        if (!$userId || !Uuid::isValid($userId)) {
            throw new AccessDeniedHttpException('No acting user');
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

    public function approve(string $requestId, ?string $decisionNote, Context $context): void
    {
        $adminId = $this->requireAdminUserId($context);

        $req = $this->getRequestOrFail($requestId, $context);
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

    public function reject(string $requestId, ?string $decisionNote, Context $context): void
    {
        $adminId = $this->requireAdminUserId($context);

        $req = $this->getRequestOrFail($requestId, $context);
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
        return $this->salesrepConfigRepo->search($c, $context)->count() > 0;
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
