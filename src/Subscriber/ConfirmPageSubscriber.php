<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use SalesAgent\Core\Content\SalesAgentConfig\SalesAgentConfigEntity;
use SalesAgent\Service\SalesAgentConfigProvider;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConfirmPageSubscriber implements EventSubscriberInterface
{
    private const INSTORE_SHIPPING_TECHNICAL = 'local_pickup';

    public function __construct(
        private readonly SalesAgentConfigProvider $configProvider,
        private readonly SystemConfigService $systemConfig
    ) {}

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

    private function applyExtensions($page, SalesChannelContext $scContext, $ctx): void
    {

        $salesChannelId = $scContext->getSalesChannelId();
        $agentId = (string) ($scContext->getImitatingUserId() ?? '');
        $isAgent = $agentId !== '';

        $this->showInstoreCashCardOnlyForAgent($page, $isAgent);


        $this->hideCredovaForAgent($page, $isAgent);


        $this->enforceInStoreShippingForInstoreCashCard($page, $scContext);

        $commissionPercentage = 0.0;
        $discountLimit        = 0.0;

        if ($isAgent) {
            /** @var SalesAgentConfigEntity|null $cfg */
            $cfg = $this->configProvider->getConfigByUserId($agentId, $ctx);

            $commissionPercentage = $this->resolveFloat(
                $cfg?->getCommissionPercentage(),
                'SalesAgent.config.commissionPercentage',
                $salesChannelId
            );

            $discountLimit = $this->resolveFloat(
                $cfg?->getDiscountLimit(),
                'SalesAgent.config.discountLimit',
                $salesChannelId
            );
        } else {
            $commissionPercentage = (float) ($this->systemConfig->get('SalesAgent.config.commissionPercentage', $salesChannelId) ?? 0.0);
            $discountLimit        = (float) ($this->systemConfig->get('SalesAgent.config.discountLimit', $salesChannelId) ?? 0.0);
        }



        $page->addExtension('saIsAgent', new ArrayEntity(['is' => $isAgent]));
        $page->addExtension('saAgentCfg', new ArrayEntity([
            'commissionPercentage' => $commissionPercentage,
            'discountLimit'        => $discountLimit,
        ]));
    }

    private function enforceInStoreShippingForInstoreCashCard($page, SalesChannelContext $scContext): void
    {
        if (!method_exists($page, 'getPaymentMethods') || !method_exists($page, 'getShippingMethods')) {
            return;
        }
        if (!method_exists($page, 'setShippingMethods')) {
            return;
        }

        /** @var PaymentMethodCollection|null $paymentMethodSelected */
        $paymentMethodSelected = $page->getPaymentMethods();
        /** @var ShippingMethodCollection|null $ShippingMethodSelected */
        $ShippingMethodSelected = $page->getShippingMethods();


        if (!$paymentMethodSelected instanceof PaymentMethodCollection || !$ShippingMethodSelected instanceof ShippingMethodCollection) {
            return;
        }

        $instorePm = $paymentMethodSelected->filter(fn (PaymentMethodEntity $pm) => $this->isInstoreCashCard($pm))->first();
        $inStoreSm = $ShippingMethodSelected->filter(fn (ShippingMethodEntity $sm) => $this->isInStoreShipping($sm))->first();


        $page->addExtension('saShippingRules', new ArrayEntity([
            'instorePaymentId'   => $instorePm?->getId(),
            'inStoreShippingId'  => $inStoreSm?->getId(),
            'shippingTechnical'  => self::INSTORE_SHIPPING_TECHNICAL,
        ]));

        $selectedPayment = $scContext->getPaymentMethod();


        if (!$selectedPayment instanceof PaymentMethodEntity || !$this->isInstoreCashCard($selectedPayment)) {
            return;
        }

        if (!$inStoreSm instanceof ShippingMethodEntity) {
            return;
        }

        $filtered = $ShippingMethodSelected->filter(fn (ShippingMethodEntity $sm) => $sm->getId() === $inStoreSm->getId());
        if ($filtered->count() > 0) {
            $page->setShippingMethods($filtered);
        }

    }

    private function isInStoreShipping(ShippingMethodEntity $sm): bool
    {
        if (method_exists($sm, 'getTechnicalName')) {
            $technical = strtolower((string) ($sm->getTechnicalName() ?? ''));
            return $technical === self::INSTORE_SHIPPING_TECHNICAL;
        }
        $name = strtolower(trim((string) ($sm->getName() ?? '')));
        return $name === self::INSTORE_SHIPPING_TECHNICAL || str_contains($name, 'in_store');
    }

    private function showInstoreCashCardOnlyForAgent($page, bool $isAgent): void
    {
        if ($isAgent) {
            $page->addExtension('saAgentOnlyPayments', new ArrayEntity([
                'sw_instore_cash_card_visible' => true,
            ]));
            return;
        }

        if (!method_exists($page, 'getPaymentMethods') || !method_exists($page, 'setPaymentMethods')) {
            return;
        }

        $methods = $page->getPaymentMethods();
        if (!$methods instanceof PaymentMethodCollection || $methods->count() === 0) {
            return;
        }

        $filtered = $methods->filter(fn (PaymentMethodEntity $pm) => !$this->isInstoreCashCard($pm));
        $page->setPaymentMethods($filtered);

        $page->addExtension('saAgentOnlyPayments', new ArrayEntity([
            'sw_instore_cash_card_hidden_for_regular_users' => true,
        ]));
    }

    private function isInstoreCashCard(PaymentMethodEntity $pm): bool
    {
        $technical = strtolower((string) ($pm->getTechnicalName() ?? ''));
        return $technical === 'sw.instore.cash.card';
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

        $filtered = $methods->filter(fn (PaymentMethodEntity $pm) => !$this->isCredovaPaymentMethod($pm));
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
 