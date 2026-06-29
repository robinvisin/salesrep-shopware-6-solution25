<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Doctrine\DBAL\Connection;
use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use SalesAgent\Service\AgentResolver;
use SalesAgent\Service\CommissionUpserter;
use SalesAgent\Service\DiscountCalculator;
use SalesAgent\Service\NumberResolver;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\User\UserEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class OrderWrittenSubscriber implements EventSubscriberInterface
{
    private const CF_SPLIT_EMAIL      = 'sales_agent_split_email';
    private const CF_SPLIT_PERCENT    = 'sales_agent_split_percent';
    private const CF_SPLIT_AMOUNT     = 'sales_agent_split_amount';
    private const CF_COMMISSION_SPLIT = 'sales_agent_commission_split';
    private const CF_SPLIT_AGENT_ID   = 'sales_agent_split_agent_id';
    private const CF_CREATED_BY_SALES_AGENT    = 'created_by_sales_agent';
    private const CF_CREATED_BY_SALES_AGENT_ID = 'created_by_sales_agent_id';
    private const CTX_STATE_SKIP = 'sales_agent_skip_split_recompute';

    public function __construct(
        private readonly EntityRepository    $orderRepository,
        private readonly EntityRepository    $userRepository,
        private readonly EntityRepository    $salesAgentConfigRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly RequestStack        $requestStack,
        private readonly AgentResolver       $agentResolver,
        private readonly NumberResolver      $nums,
        private readonly DiscountCalculator  $discounts,
        private readonly CommissionUpserter  $upserter,
        private readonly Connection          $connection,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            OrderDefinition::ENTITY_NAME . '.written' => 'onOrderWritten',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        $ctx = $event->getContext();

        if ($ctx->hasState(self::CTX_STATE_SKIP)) {
            return;
        }

        $req = $this->requestStack->getCurrentRequest();

        $requestSalesChannelId = null;
        if ($req) {
            $scContext = $req->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($scContext) {
                $requestSalesChannelId = $scContext->getSalesChannelId();
            }
        }

        $payloads = [];
        $keepAgentIdsByOrderId = [];

        foreach ($event->getWriteResults() as $wr) {
            $op = $wr->getOperation();

            if (!\in_array($op, [
                EntityWriteResult::OPERATION_INSERT,
                EntityWriteResult::OPERATION_UPDATE,
            ], true)) {
                continue;
            }

            $wrPayload = $wr->getPayload() ?? [];
            $orderId = (string)($wrPayload['id'] ?? '');
            if ($orderId === '') {
                continue;
            }

            $versionId = (string)($wrPayload['versionId'] ?? $ctx->getVersionId() ?? Defaults::LIVE_VERSION);
            if ($versionId === '') {
                $versionId = Defaults::LIVE_VERSION;
            }

            if ($op === EntityWriteResult::OPERATION_UPDATE && !$this->shouldProcessUpdate($wrPayload)) {
                continue;
            }

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository
                ->search(
                    (new Criteria([$orderId]))
                        ->addAssociation('lineItems')
                        ->addAssociation('stateMachineState'),
                    $ctx
                )
                ->first();

            if (!$order) {
                continue;
            }

            $salesChannelId = $order->getSalesChannelId() ?: $requestSalesChannelId;

            $state = $order->getStateMachineState()?->getTechnicalName();
            if ($state === OrderStates::STATE_CANCELLED) {
                if ($versionId === Defaults::LIVE_VERSION) {
                    $this->upserter->zeroOutByOrderId($orderId, $ctx);
                }
                continue;
            }

            if ($order->getLineItems() === null || $order->getLineItems()->count() === 0) {
                continue;
            }

            $orderCfNow = $order->getCustomFields() ?? [];
            $createdBySalesAgent = $orderCfNow[self::CF_CREATED_BY_SALES_AGENT] ?? false;
            $createdBySalesAgent = $createdBySalesAgent === true || $createdBySalesAgent === 1 || $createdBySalesAgent === '1';
            $createdBySalesAgentId = $orderCfNow[self::CF_CREATED_BY_SALES_AGENT_ID] ?? null;

            $agentId = (string)($wrPayload['createdById'] ?? '');
            if ($agentId === '') {
                $agentId = (string)($this->getOrderCreatedByIdRaw($orderId) ?? '');
            }
            if ($createdBySalesAgent && \is_string($createdBySalesAgentId) && Uuid::isValid($createdBySalesAgentId)) {
                $agentId = $createdBySalesAgentId;
            }
            if ($agentId === '') {
                $agentId = (string)($this->agentResolver->resolve($ctx) ?? '');
            }

            $isSalesAgent = false;

            /** @var SalesAgentConfigEntity|null $agentCfg */
            $agentCfg = null;

            /** @var UserEntity|null $agentUser */
            $agentUser = null;

            if ($agentId !== '') {
                $agentCfg = $this->salesAgentConfigRepository
                    ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentId)), $ctx)
                    ->first();

                $agentUser = $this->userRepository->search(new Criteria([$agentId]), $ctx)->first();
                $agentUserCf = $agentUser?->getCustomFields() ?? [];

                $isSalesAgent =
                    ($agentCfg !== null) ||
                    (($agentUserCf['sales_agent'] ?? false) === true || (string)($agentUserCf['sales_agent'] ?? '') === '1');
            }

            $flagExists = \array_key_exists(self::CF_CREATED_BY_SALES_AGENT, $orderCfNow);

            if ($op === EntityWriteResult::OPERATION_INSERT || !$flagExists) {
                $this->storeCreatedBySalesAgentMeta(
                    $orderId,
                    $versionId,
                    $isSalesAgent,
                    $agentId !== '' ? $agentId : null,
                    $ctx
                );
            }

            $orderCf = $order->getCustomFields() ?? [];

            $postedSplitEmail = trim((string)($orderCf[self::CF_SPLIT_EMAIL] ?? ''));
            $postedSplitPercent = (float)($orderCf[self::CF_SPLIT_PERCENT] ?? 0.0);
            $postedSplitPercent = max(0.0, min(100.0, $postedSplitPercent));

            $splitMutationRequested = $op === EntityWriteResult::OPERATION_UPDATE
                && $this->hasSplitMutationInPayload($wrPayload);
            if ($splitMutationRequested && !$this->canActorMutateSplit($agentId, $ctx)) {
                $postedSplitEmail = '';
                $postedSplitPercent = 0.0;
                $this->clearSplitMeta($orderId, $versionId, $ctx);
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

            $agentEmail = $agentUser ? $this->lower((string)$agentUser->getEmail()) : null;

            [$productBaseTotal, $promoDiscountTotal] = $this->discounts->computeFromOrder($order);

            $effectiveDiscountPercent = $productBaseTotal > 0.0
                ? ($promoDiscountTotal / $productBaseTotal) * 100.0
                : 0.0;

            $excludedByAgent = $agentEmail && \in_array($agentEmail, $excludedEmails, true);

            $commissionPercentApplied = $excludedByAgent
                ? 0.0
                : (($effectiveDiscountPercent >= $discountLimit) ? 0.0 : (float)$commissionPercentage);

            $netSubtotal = $this->computeNetProductSubtotal($order);

            if ($netSubtotal <= 0.0) {
                $this->maybeStoreOrClearSplitMeta($orderId, $versionId, $postedSplitEmail, $postedSplitPercent, 0.0, null, $ctx);
                continue;
            }

            $commissionTotal = ($netSubtotal * $commissionPercentApplied) / 100.0;

            $splitAmount = 0.0;
            $splitAgentId = null;

            if ($postedSplitEmail !== '' && $postedSplitPercent > 0.0) {
                $splitAmount = $commissionTotal * ($postedSplitPercent / 100.0);

                /** @var UserEntity|null $splitUser */
                $splitUser = $this->userRepository->search(
                    (new Criteria())
                        ->addFilter(new EqualsFilter('email', $this->lower($postedSplitEmail)))
                        ->setLimit(1),
                    $ctx
                )->first();

                if ($splitUser && $splitUser->getId() !== $agentId && $this->isSalesAgentUserId((string) $splitUser->getId(), $ctx)) {
                    $splitAgentId = $splitUser->getId();
                } else {
                    $postedSplitEmail = '';
                    $postedSplitPercent = 0.0;
                    $splitAmount = 0.0;
                }

                $this->maybeStoreOrClearSplitMeta(
                    $orderId,
                    $versionId,
                    $postedSplitEmail,
                    $postedSplitPercent,
                    $splitAmount,
                    $splitAgentId,
                    $ctx
                );
            } else {
                $this->clearSplitMeta($orderId, $versionId, $ctx);
            }

            if ($versionId !== Defaults::LIVE_VERSION) {
                continue;
            }

            if ($agentId === '') {
                continue;
            }

            $mainCommission = max(0.0, $commissionTotal - $splitAmount);

            $payloads[] = [
                '_resolve_by_order_id'      => $orderId,
                'orderId'                   => $orderId,
                'orderVersionId'            => Defaults::LIVE_VERSION,
                'agentId'                   => $agentId,
                'effectiveDiscountPercent'  => $effectiveDiscountPercent,
                'commissionPercentApplied'  => $commissionPercentApplied,
                'commissionAmount'          => $mainCommission,
                'excludedByAgentEmail'      => (bool)$excludedByAgent,
            ];
            $keepAgentIdsByOrderId[$orderId][$agentId] = true;

            if ($splitAgentId !== null && $splitAmount > 0.0) {
                $payloads[] = [
                    '_resolve_by_order_id'      => $orderId,
                    'orderId'                   => $orderId,
                    'orderVersionId'            => Defaults::LIVE_VERSION,
                    'agentId'                   => $splitAgentId,
                    'effectiveDiscountPercent'  => $effectiveDiscountPercent,
                    'commissionPercentApplied'  => $commissionPercentApplied,
                    'commissionAmount'          => $splitAmount,
                    'excludedByAgentEmail'      => false,
                ];
                $keepAgentIdsByOrderId[$orderId][$splitAgentId] = true;
            }
        }

        if ($payloads === []) {
            return;
        }

        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($payloads, $keepAgentIdsByOrderId): void {
            $this->upserter->upsertByOrderId($payloads, $system);

            foreach ($keepAgentIdsByOrderId as $orderId => $agentIds) {
                $this->upserter->zeroOutByOrderIdExcludingAgents($orderId, array_keys($agentIds), $system);
            }
        });
    }

    private function shouldProcessUpdate(array $wrPayload): bool
    {
        $cfPayload = $wrPayload['customFields'] ?? null;
        if (is_array($cfPayload)) {
            if (array_key_exists(self::CF_SPLIT_EMAIL, $cfPayload) || array_key_exists(self::CF_SPLIT_PERCENT, $cfPayload)) {
                return true;
            }

            return false;
        }

        foreach (['lineItems','price','amountTotal','shippingTotal','transactions','deliveries'] as $k) {
            if (array_key_exists($k, $wrPayload)) return true;
        }

        return false;
    }

    private function hasSplitMutationInPayload(array $wrPayload): bool
    {
        $cfPayload = $wrPayload['customFields'] ?? null;

        return \is_array($cfPayload)
            && (
                \array_key_exists(self::CF_SPLIT_EMAIL, $cfPayload)
                || \array_key_exists(self::CF_SPLIT_PERCENT, $cfPayload)
            );
    }

    private function canActorMutateSplit(string $orderAgentId, Context $context): bool
    {
        $actorId = (string)($this->agentResolver->resolve($context) ?? '');
        if ($actorId === '' || !Uuid::isValid($actorId)) {
            return false;
        }

        if ($this->isGlobalAdminUserId($actorId, $context)) {
            return true;
        }

        if ($orderAgentId === '' || !Uuid::isValid($orderAgentId)) {
            return false;
        }

        if ($actorId !== $orderAgentId) {
            return false;
        }

        return $this->isSalesAgentUserId($actorId, $context);
    }

    private function isSalesAgentUserId(string $userId, Context $context): bool
    {
        if ($userId === '' || !Uuid::isValid($userId)) {
            return false;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('userId', $userId))
            ->setLimit(1);

        if ($this->salesAgentConfigRepository->search($criteria, $context)->count() > 0) {
            return true;
        }

        /** @var UserEntity|null $user */
        $user = $this->userRepository->search((new Criteria([$userId]))->setLimit(1), $context)->first();
        $cf = $user?->getCustomFields() ?? [];

        return ($cf['sales_agent'] ?? $cf['is_sales_agent'] ?? false) === true
            || (string) ($cf['sales_agent'] ?? $cf['is_sales_agent'] ?? '') === '1';
    }

    private function isGlobalAdminUserId(string $userId, Context $context): bool
    {
        if ($userId === '' || !Uuid::isValid($userId)) {
            return false;
        }

        /** @var UserEntity|null $user */
        $user = $this->userRepository->search((new Criteria([$userId]))->setLimit(1), $context)->first();
        if (!$user) {
            return false;
        }

        return \method_exists($user, 'isAdmin') && (bool) $user->isAdmin();
    }

    private function computeNetProductSubtotal(OrderEntity $order): float
    {
        $netSubtotal = 0.0;

        foreach ($order->getLineItems() as $item) {
            if ($item->getType() !== 'product') {
                continue;
            }

            $price = $item->getPrice();
            if ($price === null) {
                continue;
            }

            $netPrice = (float)$price->getTotalPrice();

            if (method_exists($price, 'getCalculatedTaxes') && $price->getCalculatedTaxes() && $price->getCalculatedTaxes()->count() > 0) {
                foreach ($price->getCalculatedTaxes() as $tax) {
                    $netPrice -= (float)$tax->getTax();
                }
            }

            $netSubtotal += max($netPrice, 0.0);
        }

        return $netSubtotal;
    }

    private function maybeStoreOrClearSplitMeta(
        string $orderId,
        string $orderVersionId,
        string $splitEmail,
        float $splitPercent,
        float $splitAmount,
        ?string $splitAgentId,
        Context $ctx
    ): void {
        if ($splitEmail !== '' && $splitPercent > 0.0) {
            $this->storeSplitMeta($orderId, $orderVersionId, $splitEmail, $splitPercent, $splitAmount, $splitAgentId, $ctx);
            return;
        }

        $this->clearSplitMeta($orderId, $orderVersionId, $ctx);
    }

    private function internalWriteContext(Context $base, string $orderVersionId): Context
    {
        $internal = ($orderVersionId !== '' && $orderVersionId !== Defaults::LIVE_VERSION)
            ? $base->createWithVersionId($orderVersionId)
            : clone $base;
    
        $internal->addState(self::CTX_STATE_SKIP);
    
        return $internal;
    }
    

    private function clearSplitMeta(string $orderId, string $orderVersionId, Context $ctx): void
    {
        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($orderId, $orderVersionId): void {
            $internal = $this->internalWriteContext($system, $orderVersionId);

            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderId]), $internal)->first();
            $cf = $fresh?->getCustomFields() ?? [];

            unset(
                $cf[self::CF_SPLIT_EMAIL],
                $cf[self::CF_SPLIT_PERCENT],
                $cf[self::CF_COMMISSION_SPLIT],
                $cf[self::CF_SPLIT_AMOUNT],
                $cf[self::CF_SPLIT_AGENT_ID]
            );

            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $cf,
            ]], $internal);
        });
    }

    private function storeSplitMeta(
        string $orderId,
        string $orderVersionId,
        string $splitEmail,
        float $splitPercent,
        float $splitAmount,
        ?string $splitAgentId,
        Context $ctx
    ): void {
        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use (
            $orderId,
            $orderVersionId,
            $splitEmail,
            $splitPercent,
            $splitAmount,
            $splitAgentId
        ): void {
            $internal = $this->internalWriteContext($system, $orderVersionId);

            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderId]), $internal)->first();
            $cf = $fresh?->getCustomFields() ?? [];

            $cf[self::CF_SPLIT_EMAIL]    = $splitEmail;
            $cf[self::CF_SPLIT_PERCENT]  = max(0.0, min(100.0, $splitPercent));
            $cf[self::CF_COMMISSION_SPLIT] = $splitAmount;
            $cf[self::CF_SPLIT_AMOUNT]     = $splitAmount;

            if ($splitAgentId !== null) {
                $cf[self::CF_SPLIT_AGENT_ID] = $splitAgentId;
            } else {
                unset($cf[self::CF_SPLIT_AGENT_ID]);
            }

            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $cf,
            ]], $internal);
        });
    }

    private function storeCreatedBySalesAgentMeta(
        string $orderId,
        string $orderVersionId,
        bool $isSalesAgent,
        ?string $agentId,
        Context $ctx
    ): void {
        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($orderId, $orderVersionId, $isSalesAgent, $agentId): void {
            $internal = $this->internalWriteContext($system, $orderVersionId);

            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderId]), $internal)->first();
            $cf = $fresh?->getCustomFields() ?? [];

            $cf[self::CF_CREATED_BY_SALES_AGENT] = $isSalesAgent;

            if ($isSalesAgent && $agentId) {
                $cf[self::CF_CREATED_BY_SALES_AGENT_ID] = $agentId;
            } else {
                unset($cf[self::CF_CREATED_BY_SALES_AGENT_ID]);
            }

            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $cf,
            ]], $internal);
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
        $emails = array_map(fn($e) => $this->lower(trim((string)$e)), $emails);
        $emails = array_values(array_unique(array_filter($emails)));

        return $emails;
    }

    private function getOrderCreatedByIdRaw(string $orderId): ?string
    {
        $idBytes = Uuid::fromHexToBytes($orderId);

        $hex = $this->connection->fetchOne(
            'SELECT LOWER(HEX(created_by_id)) FROM `order` WHERE id = :id',
            ['id' => $idBytes]
        );

        return \is_string($hex) && $hex !== '' ? $hex : null;
    }

    private function lower(string $value): string
    {
        if (\function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }
        return strtolower($value);
    }
}
