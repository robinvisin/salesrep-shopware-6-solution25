<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Doctrine\DBAL\Connection;
use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\User\UserEntity;

final class OrderCommissionRecalculator
{
    private const CF_SPLIT_EMAIL      = 'sales_agent_split_email';
    private const CF_SPLIT_PERCENT    = 'sales_agent_split_percent';
    private const CF_SPLIT_AMOUNT     = 'sales_agent_split_amount';
    private const CF_COMMISSION_SPLIT = 'sales_agent_commission_split';
    private const CF_SPLIT_AGENT_ID   = 'sales_agent_split_agent_id';

    private const CF_CREATED_BY_SALES_AGENT    = 'created_by_sales_agent';
    private const CF_CREATED_BY_SALES_AGENT_ID = 'created_by_sales_agent_id';

    private const CF_CLAIMED_USER_ID = 'sales_agent_claimed_user_id';
    private const CF_CLAIMED_AT      = 'sales_agent_claimed_at';
    private const CTX_STATE_SKIP_SPLIT_RECOMPUTE = 'sales_agent_skip_split_recompute';

    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
        private readonly EntityRepository    $orderRepo,
        /** @var EntityRepository<\Shopware\Core\System\User\UserCollection> */
        private readonly EntityRepository    $userRepo,
        /** @var EntityRepository<\SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigCollection> */
        private readonly EntityRepository    $salesAgentConfigRepo,
        private readonly SystemConfigService $systemConfig,
        private readonly AgentResolver       $agentResolver,
        private readonly NumberResolver      $nums,
        private readonly DiscountCalculator  $discounts,
        private readonly CommissionUpserter  $upserter,
        private readonly ClaimedAgentResolver $claimedAgentResolver,
        private readonly Connection          $connection
    ) {
    }

    public function recalcForOrderId(string $orderId, Context $context, float $commissionMultiplier = 1.0): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $commissionMultiplier = max(0.0, min(1.0, $commissionMultiplier));

        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems')
            ->addAssociation('stateMachineState');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepo->search($criteria, $context)->getEntities()->first();
        if (!$order instanceof OrderEntity) {
            return;
        }

        $salesChannelId = $order->getSalesChannelId();

        $claimedUserId = $this->resolveClaimedUserId($order, $context);

        $agentUserId = $claimedUserId ?: $this->resolveAgentUserIdFromOrder($order, $context);

        if (!$agentUserId || !Uuid::isValid($agentUserId)) {
            $this->zeroOutLive($orderId, $context);
            return;
        }

        /** @var SalesAgentConfigEntity|null $agentCfg */
        $agentCfg = $this->salesAgentConfigRepo
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentUserId))->setLimit(1), $context)
            ->getEntities()->first();

        /** @var UserEntity|null $agentUser */
        $agentUser = $this->userRepo->search(new Criteria([$agentUserId]), $context)->getEntities()->first();
        $agentUserCf = $agentUser?->getCustomFields() ?? [];

        $isSalesAgent =
            ($agentCfg !== null) ||
            (($agentUserCf['sales_agent'] ?? $agentUserCf['is_sales_agent'] ?? false) === true || (string)($agentUserCf['sales_agent'] ?? $agentUserCf['is_sales_agent'] ?? '') === '1');

        if (!$isSalesAgent) {
            $this->zeroOutLive($orderId, $context);
            return;
        }

        $commissionPercentage = $this->nums->resolveFloat(
            $agentCfg?->getCommissionPercentage(),
            'SalesAgent.config.commissionPercentage',
            $salesChannelId
        );

        $discountLimit = $this->nums->resolveFloat(
            $agentCfg?->getDiscountLimit(),
            'SalesAgent.config.discountLimit',
            $salesChannelId
        );

        $excludedEmails = $this->normalizeExcludedEmails(
            $this->systemConfig->get('SalesAgent.config.excludedEmails', $salesChannelId)
        );

        $agentEmail = $agentUser ? mb_strtolower((string)$agentUser->getEmail()) : null;
        $excludedByAgent = $agentEmail && \in_array($agentEmail, $excludedEmails, true);

        [$productBaseTotal, $promoDiscountTotal] = $this->discounts->computeFromOrder($order);
        $effectiveDiscountPercent = $productBaseTotal > 0.0
            ? ($promoDiscountTotal / $productBaseTotal) * 100.0
            : 0.0;

        $commissionPercentApplied = $excludedByAgent
            ? 0.0
            : (($effectiveDiscountPercent >= $discountLimit) ? 0.0 : (float)$commissionPercentage);

        $netSubtotal = $this->computeCommissionBaseSubtotal($order);

        $commissionTotal = ($netSubtotal * $commissionPercentApplied) / 100.0;
        $commissionTotal *= $commissionMultiplier;

        $orderCf = $order->getCustomFields() ?? [];
        $splitEmail = trim((string)($orderCf[self::CF_SPLIT_EMAIL] ?? ''));
        $splitPercent = (float)($orderCf[self::CF_SPLIT_PERCENT] ?? 0.0);
        $splitPercent = max(0.0, min(100.0, $splitPercent));

        $splitAmount = 0.0;
        $splitAgentId = null;
        if ($splitEmail !== '' && $splitPercent > 0.0) {
            $splitAmount = $commissionTotal * ($splitPercent / 100.0);
            $splitAgentId = $this->resolveSplitAgentIdByEmail($splitEmail, $context);
            if ($splitAgentId === $agentUserId) {
                $splitAgentId = null;
                $splitAmount = 0.0;
            }
        }

        $mainCommission = $commissionTotal - $splitAmount;

        $commissionAmount = round(max(0.0, $mainCommission), 2);
        $splitAmount = round(max(0.0, $splitAmount), 2);

        $context->scope(Context::SYSTEM_SCOPE, function (Context $system) use (
            $orderId,
            $agentUserId,
            $effectiveDiscountPercent,
            $commissionPercentApplied,
            $commissionAmount,
            $excludedByAgent,
            $splitAmount,
            $splitAgentId,
            $splitEmail,
            $splitPercent
        ): void {
            $payloads = [[
                '_resolve_by_order_id'      => $orderId,
                'orderId'                   => $orderId,
                'orderVersionId'            => Defaults::LIVE_VERSION,
                'agentId'                   => $agentUserId,
                'effectiveDiscountPercent'  => (float) $effectiveDiscountPercent,
                'commissionPercentApplied'  => (float) $commissionPercentApplied,
                'commissionAmount'          => (float) $commissionAmount,
                'excludedByAgentEmail'      => (bool) $excludedByAgent,
            ]];

            $keepAgentIds = [$agentUserId];

            if ($splitAgentId !== null) {
                $payloads[] = [
                    '_resolve_by_order_id'      => $orderId,
                    'orderId'                   => $orderId,
                    'orderVersionId'            => Defaults::LIVE_VERSION,
                    'agentId'                   => $splitAgentId,
                    'effectiveDiscountPercent'  => (float) $effectiveDiscountPercent,
                    'commissionPercentApplied'  => (float) $commissionPercentApplied,
                    'commissionAmount'          => (float) $splitAmount,
                    'excludedByAgentEmail'      => false,
                ];
                $keepAgentIds[] = $splitAgentId;
            }

            $this->upserter->upsertByOrderId($payloads, $system);
            $this->upserter->zeroOutByOrderIdExcludingAgents($orderId, $keepAgentIds, $system);
            $this->writeSplitMeta($orderId, $splitEmail, $splitPercent, $splitAmount, $splitAgentId, $system);
        });
    }

    private function resolveClaimedUserId(OrderEntity $order, Context $context): ?string
    {
        if (\method_exists($this->claimedAgentResolver, 'resolveAgentUserIdForOrder')) {
            return $this->claimedAgentResolver->resolveAgentUserIdForOrder($order, $context);
        }

        $cf = $order->getCustomFields() ?? [];

        $claimedUserId = $cf[self::CF_CLAIMED_USER_ID] ?? null;
        if (!\is_string($claimedUserId) || $claimedUserId === '' || !Uuid::isValid($claimedUserId)) {
            return null;
        }

        $claimedAt = $cf[self::CF_CLAIMED_AT] ?? null;
        if (!\is_string($claimedAt) || $claimedAt === '') {
            return null;
        }

        $cfg = $this->salesAgentConfigRepo
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $claimedUserId))->setLimit(1), $context)
            ->getEntities()->first();

        return $cfg ? $claimedUserId : null;
    }

    private function resolveAgentUserIdFromOrder(OrderEntity $order, Context $context): ?string
    {
        $cf = $order->getCustomFields() ?? [];

        $createdBySalesAgent = $cf[self::CF_CREATED_BY_SALES_AGENT] ?? false;
        $createdBySalesAgent = ($createdBySalesAgent === true || $createdBySalesAgent === 1 || $createdBySalesAgent === '1');

        $createdBySalesAgentId = $cf[self::CF_CREATED_BY_SALES_AGENT_ID] ?? null;
        if ($createdBySalesAgent && \is_string($createdBySalesAgentId) && $createdBySalesAgentId !== '' && Uuid::isValid($createdBySalesAgentId)) {
            return $createdBySalesAgentId;
        }

        $createdById = $order->getCreatedById();
        if (\is_string($createdById) && $createdById !== '' && Uuid::isValid($createdById)) {
            return $createdById;
        }

        $raw = $this->getOrderCreatedByIdRaw($order->getId());
        if ($raw && Uuid::isValid($raw)) {
            return $raw;
        }

        if ($createdBySalesAgent) {
            $acting = $this->agentResolver->resolve($context);
            if (\is_string($acting) && $acting !== '' && Uuid::isValid($acting)) {
                return $acting;
            }
        }

        return null;
    }

    private function zeroOutLive(string $orderId, Context $context): void
    {
        $context->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($orderId): void {
            $this->upserter->zeroOutByOrderId($orderId, $system);
            $this->writeSplitMeta($orderId, '', 0.0, 0.0, null, $system);
        });
    }

    private function resolveSplitAgentIdByEmail(string $email, Context $context): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        /** @var UserEntity|null $splitUser */
        $splitUser = $this->userRepo->search(
            (new Criteria())->addFilter(new EqualsFilter('email', $email))->setLimit(1),
            $context
        )->getEntities()->first();

        $splitId = $splitUser?->getId();
        if (!\is_string($splitId) || !Uuid::isValid($splitId)) {
            return null;
        }

        return $this->isSalesAgentUser($splitId, $splitUser, $context) ? $splitId : null;
    }

    private function isSalesAgentUser(string $userId, ?UserEntity $user, Context $context): bool
    {
        $cfg = $this->salesAgentConfigRepo
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $userId))->setLimit(1), $context)
            ->getEntities()->first();

        if ($cfg !== null) {
            return true;
        }

        $cf = $user?->getCustomFields() ?? [];

        return ($cf['sales_agent'] ?? $cf['is_sales_agent'] ?? false) === true
            || (string) ($cf['sales_agent'] ?? $cf['is_sales_agent'] ?? '') === '1';
    }

    private function writeSplitMeta(
        string $orderId,
        string $splitEmail,
        float $splitPercent,
        float $splitAmount,
        ?string $splitAgentId,
        Context $context
    ): void {
        $internal = clone $context;
        $internal->addState(self::CTX_STATE_SKIP_SPLIT_RECOMPUTE);

        /** @var OrderEntity|null $fresh */
        $fresh = $this->orderRepo->search(new Criteria([$orderId]), $internal)->getEntities()->first();
        if (!$fresh instanceof OrderEntity) {
            return;
        }

        $cf = $fresh->getCustomFields() ?? [];

        if ($splitAgentId !== null && $splitAmount > 0.0) {
            $cf[self::CF_SPLIT_EMAIL] = $splitEmail;
            $cf[self::CF_SPLIT_PERCENT] = max(0.0, min(100.0, $splitPercent));
            $cf[self::CF_SPLIT_AMOUNT] = $splitAmount;
            $cf[self::CF_COMMISSION_SPLIT] = $splitAmount;
            $cf[self::CF_SPLIT_AGENT_ID] = $splitAgentId;
        } else {
            unset($cf[self::CF_SPLIT_AMOUNT], $cf[self::CF_COMMISSION_SPLIT], $cf[self::CF_SPLIT_AGENT_ID]);
        }

        $this->orderRepo->update([[
            'id' => $orderId,
            'customFields' => $cf,
        ]], $internal);
    }

    private function normalizeExcludedEmails(mixed $value): array
    {
        if (\is_array($value)) {
            $raw = implode(',', array_map('strval', $value));
        } elseif (\is_string($value)) {
            $raw = $value;
        } elseif ($value === null) {
            $raw = '';
        } else {
            $raw = (string)$value;
        }

        $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $emails = array_map(static fn ($e) => strtolower(trim((string)$e)), $emails);
        $emails = array_values(array_unique(array_filter($emails)));

        return $emails;
    }

    private function computeCommissionBaseSubtotal(OrderEntity $order): float
    {
        $productNetSubtotal = 0.0;
        $promotionNetDiscount = 0.0;

        foreach ($order->getLineItems() ?? [] as $item) {
            $price = $item->getPrice();
            if ($price === null) {
                continue;
            }

            $lineNetTotal = (float) $price->getTotalPrice();

            $type = (string) $item->getType();

            if ($type === 'product') {
                $productNetSubtotal += max($lineNetTotal, 0.0);
                continue;
            }

            if (($type === 'promotion' || $type === 'discount') && $lineNetTotal < 0.0) {
                $promotionNetDiscount += abs($lineNetTotal);
            }
        }

        return max(0.0, $productNetSubtotal - $promotionNetDiscount);
    }

    private function getOrderCreatedByIdRaw(string $orderId): ?string
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }

        $idBytes = Uuid::fromHexToBytes($orderId);

        $hex = $this->connection->fetchOne(
            'SELECT LOWER(HEX(created_by_id)) FROM `order` WHERE id = :id',
            ['id' => $idBytes]
        );

        return \is_string($hex) && $hex !== '' ? $hex : null;
    }
}
