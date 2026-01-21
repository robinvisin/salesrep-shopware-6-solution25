<?php

declare(strict_types=1);

namespace Salesrep\Subscriber;

use Salesrep\Core\Content\SalesrepConfig\SalesrepConfigEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConfirmPageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $salesrepConfigRepository,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onLoaded',
            CheckoutCartPageLoadedEvent::class    => 'onLoadedCart',
        ];
    }

    public function onLoaded(CheckoutConfirmPageLoadedEvent $e): void
    {
        $this->applyExtensions($e->getPage(), $e->getSalesChannelContext(), $e->getContext());
    }

    public function onLoadedCart(CheckoutCartPageLoadedEvent $e): void
    {
        $this->applyExtensions($e->getPage(), $e->getSalesChannelContext(), $e->getContext());
    }

    private function applyExtensions($page, $scContext, $ctx): void
    {
        $salesChannelId = $scContext->getSalesChannelId();
        $agentId = (string) ($scContext->getImitatingUserId() ?? '');
        $isAgent = $agentId !== '';

        $this->hideCredovaForAgent($page, $isAgent);

        $commissionPercentage = 0.0;
        $discountLimit        = 0.0;

        if ($isAgent) {
            /** @var SalesrepConfigEntity|null $cfg */
            $cfg = $this->salesrepConfigRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('userId', $agentId)),
                $ctx
            )->first();

            $commissionPercentage = $this->resolveFloat(
                $cfg?->getCommissionPercentage(),
                'Salesrep.config.commissionPercentage',
                $salesChannelId
            );

            $discountLimit = $this->resolveFloat(
                $cfg?->getDiscountLimit(),
                'Salesrep.config.discountLimit',
                $salesChannelId
            );
        } else {
            $commissionPercentage = (float) ($this->systemConfig->get('Salesrep.config.commissionPercentage', $salesChannelId) ?? 0.0);
            $discountLimit        = (float) ($this->systemConfig->get('Salesrep.config.discountLimit', $salesChannelId) ?? 0.0);
        }

        $page->addExtension('saIsAgent', new ArrayEntity(['is' => $isAgent]));
        $page->addExtension('saAgentCfg', new ArrayEntity([
            'commissionPercentage' => $commissionPercentage,
            'discountLimit'        => $discountLimit,
        ]));
    }

    private function hideCredovaForAgent($page, bool $isAgent): void
    {
        if (!$isAgent) {
            return;
        }

        if (!method_exists($page, 'getPaymentMethods') || !method_exists($page, 'setPaymentMethods')) {
            return;
        }

        $methods = $page->getPaymentMethods();
        if (!$methods instanceof PaymentMethodCollection || $methods->count() === 0) {
            return;
        }

        $filtered = $methods->filter(function (PaymentMethodEntity $pm): bool {
            return !$this->isCredovaPaymentMethod($pm);
        });

        $page->setPaymentMethods($filtered);

        $page->addExtension('saBlockedPayments', new ArrayEntity([
            'credova' => true,
        ]));
    }

    private function isCredovaPaymentMethod(PaymentMethodEntity $pm): bool
    {
        $technical = strtolower((string) ($pm->getTechnicalName() ?? ''));
        $handler   = strtolower((string) ($pm->getHandlerIdentifier() ?? ''));
        $formatted = strtolower((string) ($pm->getFormattedHandlerIdentifier() ?? ''));

        if ($technical === 'credova' || $technical === 'credova_payment') {
            return true;
        }
        if ($formatted === 'handler_credova' || $formatted === 'handler_credova_payment') {
            return true;
        }

        return str_contains($technical, 'credova')
            || str_contains($handler, 'credova')
            || str_contains($formatted, 'credova');
    }

    private function resolveFloat(mixed $perUser, string $configKey, ?string $salesChannelId): float
    {
        if ($perUser !== null && $perUser !== '') {
            return (float) $perUser;
        }

        $cfg = $this->systemConfig->get($configKey, $salesChannelId);
        if ($cfg === null || $cfg === '') {
            return 0.0;
        }

        if (is_string($cfg)) {
            $n = str_replace([' ', ','], ['', '.'], $cfg);
            return is_numeric($n) ? (float) $n : 0.0;
        }

        return (float) $cfg;
    }
}
