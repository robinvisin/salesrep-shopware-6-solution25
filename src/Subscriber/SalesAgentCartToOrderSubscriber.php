<?php

declare(strict_types=1);

namespace SalesAgent\Subscriber;

use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesAgentCartToOrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SalesChannelContextPersister $contextPersister
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $converted    = $event->getConvertedCart();
        $customFields = $converted['customFields'] ?? [];
        $data         = $this->resolveSalesAgentData($event);
        $splitData    = $this->resolveSplitData($event);

        if ($data === [] && $splitData === []) {
            return;
        }

        if (array_key_exists('notes', $data) && (string) $data['notes'] !== '') {
            $customFields['infoplus_salesAgentOrderNotes'] = (string) $data['notes'];
        }

        if (!empty($data['do_not_ship_until'])) {
            $customFields['infoplus_salesAgentDoNotShipUntil'] = $data['do_not_ship_until'];
        }

        if ($splitData !== []) {
            $customFields['sales_agent_split_email'] = $splitData['email'];
            $customFields['sales_agent_split_percent'] = $splitData['percent'];
        }

        $converted['customFields'] = $customFields;
        $event->setConvertedCart($converted);
    }

    /**
     * @return array{notes?: string, do_not_ship_until?: ?string}
     */
    private function resolveSalesAgentData(CartConvertedEvent $event): array
    {
        $cart = $event->getCart();
        $extension = $cart->getExtension('sales_agent');

        if ($extension !== null) {
            $vars = $extension->getVars();
            if (is_array($vars)) {
                return $vars;
            }
        }

        $converted = $event->getConvertedCart();
        $raw = $converted['customerComment'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return [
            'notes' => (string) ($decoded['notes'] ?? ''),
            'do_not_ship_until' => $decoded['do_not_ship_until'] ?? null,
        ];
    }

    /**
     * @return array{email: string, percent: int}|array{}
     */
    private function resolveSplitData(CartConvertedEvent $event): array
    {
        $cart = $event->getCart();
        $extension = $cart->getExtension('sales_agent_split');

        if ($extension instanceof ArrayEntity) {
            $vars = $extension->getVars();
            if (\is_array($vars)) {
                $split = $this->normalizeSplit($vars);
                if ($split !== []) {
                    return $split;
                }
            }
        }

        $scContext = $event->getSalesChannelContext();
        $persisted = $this->contextPersister->load(
            $scContext->getToken(),
            $scContext->getSalesChannelId()
        );
        $raw = $persisted['sales_agent_split'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (\is_array($decoded)) {
                $split = $this->normalizeSplit($decoded);
                if ($split !== []) {
                    return $split;
                }
            }
        }

        $converted = $event->getConvertedCart();
        $cf = $converted['customFields'] ?? [];
        if (\is_array($cf)) {
            return $this->normalizeSplit([
                'email' => $cf['sales_agent_split_email'] ?? null,
                'percent' => $cf['sales_agent_split_percent'] ?? null,
            ]);
        }

        return [];
    }

    /**
     * @param array<mixed> $data
     * @return array{email: string, percent: int}|array{}
     */
    private function normalizeSplit(array $data): array
    {
        $email = trim((string) ($data['email'] ?? ''));
        $percent = (int) ($data['percent'] ?? 0);

        if ($email === '' || $percent < 1 || $percent > 100) {
            return [];
        }

        return ['email' => $email, 'percent' => $percent];
    }
}
