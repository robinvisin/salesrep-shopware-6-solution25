<?php

declare(strict_types=1);

namespace Salesrep\Subscriber;

use Doctrine\DBAL\Connection;
use Salesrep\Core\Content\SalesrepConfig\SalesrepConfigEntity;
use Salesrep\Service\AgentResolver;
use Salesrep\Service\CommissionUpserter;
use Salesrep\Service\DiscountCalculator;
use Salesrep\Service\NumberResolver;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class OrderWrittenSubscriber implements EventSubscriberInterface
{
    private const CF_SPLIT_EMAIL      = 'salesrep_split_email';
    private const CF_SPLIT_PERCENT    = 'salesrep_split_percent';
    private const CF_SPLIT_AMOUNT     = 'salesrep_split_amount';
    private const CF_COMMISSION_SPLIT = 'salesrep_commission_split';
    private const CF_SPLIT_AGENT_ID   = 'salesrep_split_agent_id';

    private const CF_CREATED_BY_SALESREP    = 'created_by_salesrep';
    private const CF_CREATED_BY_SALESREP_ID = 'created_by_salesrep_id';

    private const CTX_STATE_SKIP = 'salesrep_skip_split_recompute';

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $userRepository,
        private readonly EntityRepository $salesrepConfigRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly RequestStack $requestStack,
        private readonly AgentResolver $agentResolver,
        private readonly NumberResolver $nums,
        private readonly DiscountCalculator $discounts,
        private readonly CommissionUpserter $upserter,
        private readonly Connection $connection,
    ) {
    }

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
        [$reqSplitEmail, $reqSplitPercent] = $this->extractSplitFromRequest($req);

        $requestSalesChannelId = null;
        if ($req) {
            $scContext = $req->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
            if ($scContext) {
                $requestSalesChannelId = $scContext->getSalesChannelId();
            }
        }

        $payloads = [];

        foreach ($event->getWriteResults() as $wr) {
            $op = $wr->getOperation();

            if (
                !\in_array($op, [
                EntityWriteResult::OPERATION_INSERT,
                EntityWriteResult::OPERATION_UPDATE,
                ], true)
            ) {
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

            if ($op === EntityWriteResult::OPERATION_UPDATE && !$this->shouldProcessUpdate($wrPayload, $reqSplitEmail, $reqSplitPercent)) {
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

            $agentId = (string)($wrPayload['createdById'] ?? '');
            if ($agentId === '') {
                $agentId = (string)($this->getOrderCreatedByIdRaw($orderId) ?? '');
            }
            if ($agentId === '') {
                $agentId = (string)($this->agentResolver->resolve($ctx) ?? '');
            }

            $isSalesAgent = false;

            /** @var SalesrepConfigEntity|null $agentCfg */
            $agentCfg = null;

            /** @var UserEntity|null $agentUser */
            $agentUser = null;

            if ($agentId !== '') {
                $agentCfg = $this->salesrepConfigRepository
                    ->search((new Criteria())->addFilter(new EqualsFilter('userId', $agentId)), $ctx)
                    ->first();

                $agentUser = $this->userRepository->search(new Criteria([$agentId]), $ctx)->first();
                $agentUserCf = $agentUser?->getCustomFields() ?? [];

                $isSalesAgent =
                    ($agentCfg !== null) ||
                    (($agentUserCf['salesrep'] ?? false) === true || (string)($agentUserCf['salesrep'] ?? '') === '1');
            }

            $orderCfNow = $order->getCustomFields() ?? [];
            $flagExists = \array_key_exists(self::CF_CREATED_BY_SALESREP, $orderCfNow);

            if ($op === EntityWriteResult::OPERATION_INSERT || !$flagExists) {
                $this->storeCreatedBySalesrepMeta(
                    $orderId,
                    $versionId,
                    $isSalesAgent,
                    $agentId !== '' ? $agentId : null,
                    $ctx
                );
            }

            $orderCf = $order->getCustomFields() ?? [];

            $postedSplitEmail = trim((string)($orderCf[self::CF_SPLIT_EMAIL] ?? $reqSplitEmail));
            $postedSplitPercent = (float)($orderCf[self::CF_SPLIT_PERCENT] ?? $reqSplitPercent);
            $postedSplitPercent = max(0.0, min(100.0, $postedSplitPercent));

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

                if ($splitUser) {
                    $splitAgentId = $splitUser->getId();
                }

                $this->storeSplitMeta(
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
        }

        if ($payloads === []) {
            return;
        }

        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($payloads): void {
            $this->upserter->upsertByOrderId($payloads, $system);
        });
    }

    private function shouldProcessUpdate(array $wrPayload, string $reqSplitEmail, float $reqSplitPercent): bool
    {
        if ($reqSplitEmail !== '' || $reqSplitPercent > 0.0) {
            return true;
        }

        $cfPayload = $wrPayload['customFields'] ?? null;
        if (\is_array($cfPayload)) {
            if (\array_key_exists(self::CF_SPLIT_EMAIL, $cfPayload) || \array_key_exists(self::CF_SPLIT_PERCENT, $cfPayload)) {
                return true;
            }

            $internalKeys = [
                self::CF_SPLIT_AMOUNT,
                self::CF_COMMISSION_SPLIT,
                self::CF_SPLIT_AGENT_ID,
                self::CF_CREATED_BY_SALESREP,
                self::CF_CREATED_BY_SALESREP_ID,
            ];

            $allKeys = array_keys($cfPayload);
            $nonInternal = array_diff($allKeys, $internalKeys);
            if ($nonInternal === []) {
                return false;
            }

            return true;
        }

        $interestingKeys = [
            'lineItems',
            'price',
            'amountTotal',
            'shippingTotal',
            'stateMachineStateId',
            'transactions',
            'deliveries',
            'salesChannelId',
            'createdById',
        ];

        foreach ($interestingKeys as $k) {
            if (\array_key_exists($k, $wrPayload)) {
                return true;
            }
        }

        return true;
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

    private function clearSplitMeta(string $orderId, string $orderVersionId, Context $ctx): void
    {
        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($orderId, $orderVersionId): void {
            $system->addState(self::CTX_STATE_SKIP);

            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderId]), $system)->first();
            $cf = $fresh?->getCustomFields() ?? [];

            unset($cf[self::CF_SPLIT_EMAIL]);
            unset($cf[self::CF_SPLIT_PERCENT]);
            unset($cf[self::CF_COMMISSION_SPLIT]);
            unset($cf[self::CF_SPLIT_AMOUNT]);
            unset($cf[self::CF_SPLIT_AGENT_ID]);

            $this->orderRepository->update([[
                'id' => $orderId,
                'versionId' => $orderVersionId,
                'customFields' => $cf,
            ]], $system);
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
            $system->addState(self::CTX_STATE_SKIP);

            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderId]), $system)->first();
            $cf = $fresh?->getCustomFields() ?? [];

            $cf[self::CF_SPLIT_EMAIL] = $splitEmail;
            $cf[self::CF_SPLIT_PERCENT] = max(0.0, min(100.0, $splitPercent));

            $cf[self::CF_COMMISSION_SPLIT] = $splitAmount;
            $cf[self::CF_SPLIT_AMOUNT] = $splitAmount;

            if ($splitAgentId !== null) {
                $cf[self::CF_SPLIT_AGENT_ID] = $splitAgentId;
            } else {
                unset($cf[self::CF_SPLIT_AGENT_ID]);
            }

            $this->orderRepository->update([[
                'id' => $orderId,
                'versionId' => $orderVersionId,
                'customFields' => $cf,
            ]], $system);
        });
    }

    private function storeCreatedBySalesrepMeta(
        string $orderId,
        string $orderVersionId,
        bool $isSalesAgent,
        ?string $agentId,
        Context $ctx
    ): void {
        $ctx->scope(Context::SYSTEM_SCOPE, function (Context $system) use ($orderId, $orderVersionId, $isSalesAgent, $agentId): void {
            $system->addState(self::CTX_STATE_SKIP);

            /** @var OrderEntity|null $fresh */
            $fresh = $this->orderRepository->search(new Criteria([$orderId]), $system)->first();
            $cf = $fresh?->getCustomFields() ?? [];

            $cf[self::CF_CREATED_BY_SALESREP] = $isSalesAgent;

            if ($isSalesAgent && $agentId) {
                $cf[self::CF_CREATED_BY_SALESREP_ID] = $agentId;
            } else {
                unset($cf[self::CF_CREATED_BY_SALESREP_ID]);
            }

            $this->orderRepository->update([[
                'id' => $orderId,
                'versionId' => $orderVersionId,
                'customFields' => $cf,
            ]], $system);
        });
    }

    private function extractSplitFromRequest(?Request $req): array
    {
        if (!$req) {
            return ['', 0.0];
        }

        $email = trim((string)($req->request->get(self::CF_SPLIT_EMAIL) ?? ''));
        $percent = (float)($req->request->get(self::CF_SPLIT_PERCENT) ?? 0);

        if ($email === '' && $percent <= 0.0) {
            $raw = (string)$req->getContent();
            if ($raw !== '') {
                $json = json_decode($raw, true);
                if (\is_array($json)) {
                    $foundEmail = $this->findKeyRecursive($json, self::CF_SPLIT_EMAIL);
                    $foundPercent = $this->findKeyRecursive($json, self::CF_SPLIT_PERCENT);

                    $email = trim((string)($foundEmail ?? $email));
                    $percent = (float)($foundPercent ?? $percent);
                }
            }
        }

        $percent = max(0.0, min(100.0, (float)$percent));

        return [$email, $percent];
    }

    private function findKeyRecursive(array $data, string $key): mixed
    {
        foreach ($data as $k => $v) {
            if ($k === $key) {
                return $v;
            }
            if (\is_array($v)) {
                $found = $this->findKeyRecursive($v, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
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
