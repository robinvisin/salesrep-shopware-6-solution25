<?php

declare(strict_types=1);

namespace Salesrep\Service;

use Doctrine\DBAL\Connection;
use Salesrep\Core\Content\SalesrepConfig\SalesrepConfigEntity;
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
    private const CF_SPLIT_EMAIL      = 'salesrep_split_email';
    private const CF_SPLIT_PERCENT    = 'salesrep_split_percent';

    private const CF_CREATED_BY_SALESREP    = 'created_by_salesrep';
    private const CF_CREATED_BY_SALESREP_ID = 'created_by_salesrep_id';

    private const CF_CLAIMED_USER_ID = 'salesrep_claimed_user_id';
    private const CF_CLAIMED_AT      = 'salesrep_claimed_at';

    public function __construct(
        private readonly EntityRepository    $orderRepo,
        private readonly EntityRepository    $userRepo,
        private readonly EntityRepository    $salesrepConfigRepo,
        private readonly SystemConfigService $systemConfig,
        private readonly AgentResolver       $agentResolver,
        private readonly NumberResolver      $nums,
        private readonly DiscountCalculator  $discounts,
        private readonly CommissionUpserter  $upserter,
        private readonly ClaimedAgentResolver $claimedAgentResolver,
        private readonly Connection          $connection
    ) {
    }

    public function recalcForOrderId(string $orderId, Context $context): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $criteria = (new Criteria([$orderId]))
            ->addAssociation('lineItems')
            ->addAssociation('stateMachineState');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepo->search($criteria, $context)->first();
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

        /** @var SalesrepConfigEntity|null $agentCfg */
        $agentCfg = $this->salesrepConfigRepo
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentUserId)), $context)
            ->first();

        /** @var UserEntity|null $agentUser */
        $agentUser = $this->userRepo->search(new Criteria([$agentUserId]), $context)->first();
        $agentUserCf = $agentUser?->getCustomFields() ?? [];

        $isSalesrep =
            ($agentCfg !== null) ||
            (($agentUserCf['salesrep'] ?? false) === true || (string)($agentUserCf['salesrep'] ?? '') === '1');

        if (!$isSalesrep) {
            $this->zeroOutLive($orderId, $context);
            return;
        }

        $commissionPercentage = $this->nums->resolveFloat(
            $agentCfg?->getCommissionPercentage(),
            'Salesrep.config.commissionPercentage',
            $salesChannelId
        );

        $discountLimit = $this->nums->resolveFloat(
            $agentCfg?->getDiscountLimit(),
            'Salesrep.config.discountLimit',
            $salesChannelId
        );

        $excludedEmails = $this->normalizeExcludedEmails(
            $this->systemConfig->get('Salesrep.config.excludedEmails', $salesChannelId)
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

        $netSubtotal = 0.0;
        foreach ($order->getLineItems() ?? [] as $item) {
            if ($item->getType() !== 'product') {
                continue;
            }

            $price = $item->getPrice();
            if ($price === null) {
                continue;
            }

            $netPrice = (float) $price->getTotalPrice();

            if (\method_exists($price, 'getCalculatedTaxes') && $price->getCalculatedTaxes() && $price->getCalculatedTaxes()->count() > 0) {
                foreach ($price->getCalculatedTaxes() as $tax) {
                    $netPrice -= (float) $tax->getTax();
                }
            }

            $netSubtotal += max($netPrice, 0.0);
        }

        $commissionTotal = ($netSubtotal * $commissionPercentApplied) / 100.0;

        $orderCf = $order->getCustomFields() ?? [];
        $splitEmail = trim((string)($orderCf[self::CF_SPLIT_EMAIL] ?? ''));
        $splitPercent = (float)($orderCf[self::CF_SPLIT_PERCENT] ?? 0.0);
        $splitPercent = max(0.0, min(100.0, $splitPercent));

        $splitAmount = 0.0;
        if ($splitEmail !== '' && $splitPercent > 0.0) {
            $splitAmount = $commissionTotal * ($splitPercent / 100.0);
        }

        $mainCommission = $commissionTotal - $splitAmount;

        $commissionAmount = round(max(0.0, $mainCommission), 2);

        $context->scope(Context::SYSTEM_SCOPE, function (Context $system) use (
            $orderId,
            $agentUserId,
            $effectiveDiscountPercent,
            $commissionPercentApplied,
            $commissionAmount,
            $excludedByAgent
        ): void {
            $this->upserter->upsertByOrderId([[
                '_resolve_by_order_id'      => $orderId,
                'orderId'                   => $orderId,
                'orderVersionId'            => Defaults::LIVE_VERSION,
                'agentId'                   => $agentUserId,
                'effectiveDiscountPercent'  => (float) $effectiveDiscountPercent,
                'commissionPercentApplied'  => (float) $commissionPercentApplied,
                'commissionAmount'          => (float) $commissionAmount,
                'excludedByAgentEmail'      => (bool) $excludedByAgent,
            ]], $system);
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

        $cfg = $this->salesrepConfigRepo
            ->search((new Criteria())->addFilter(new EqualsFilter('userId', $claimedUserId))->setLimit(1), $context)
            ->first();

        return $cfg ? $claimedUserId : null;
    }

    private function resolveAgentUserIdFromOrder(OrderEntity $order, Context $context): ?string
    {
        $cf = $order->getCustomFields() ?? [];

        $createdBySalesrep = $cf[self::CF_CREATED_BY_SALESREP] ?? false;
        $createdBySalesrep = ($createdBySalesrep === true || $createdBySalesrep === 1 || $createdBySalesrep === '1');

        $createdBySalesrepId = $cf[self::CF_CREATED_BY_SALESREP_ID] ?? null;
        if ($createdBySalesrep && \is_string($createdBySalesrepId) && $createdBySalesrepId !== '' && Uuid::isValid($createdBySalesrepId)) {
            return $createdBySalesrepId;
        }

        $createdById = $order->getCreatedById();
        if (\is_string($createdById) && $createdById !== '' && Uuid::isValid($createdById)) {
            return $createdById;
        }

        $raw = $this->getOrderCreatedByIdRaw($order->getId());
        if ($raw && Uuid::isValid($raw)) {
            return $raw;
        }

        if ($createdBySalesrep) {
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
        });
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
