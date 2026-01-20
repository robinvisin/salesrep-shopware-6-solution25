<?php

declare(strict_types=1);

namespace Salesrep\Controller;

use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AbandonedCartReminderController
{
    public function __construct(
        private readonly EntityRepository $abandonedCartRepository,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $salesChannelDomainRepository,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly MailService $mailService,
    ) {
    }

    #[Route(
        path: '/api/_action/salesrep/abandoned-cart/reminder',
        name: 'api.action.salesrep.abandoned_cart.reminder',
        methods: ['POST']
    )]
    public function sendReminder(Request $request, Context $context): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $cartId       = (string) ($payload['cartId'] ?? '');
        $customerId   = (string) ($payload['customerId'] ?? '');
        $note         = (string) ($payload['note'] ?? '');
        $includeItems = (bool) ($payload['includeItems'] ?? true);

        if ($cartId === '' || $customerId === '') {
            return new JsonResponse(['success' => false, 'message' => 'cartId and customerId are required'], 400);
        }

        $cart = $this->abandonedCartRepository->search(new Criteria([$cartId]), $context)->first();
        if (!$cart) {
            return new JsonResponse(['success' => false, 'message' => 'Cart not found'], 404);
        }

        $customer = $this->customerRepository->search(new Criteria([$customerId]), $context)->first();
        if (!$customer || !$customer->getEmail()) {
            return new JsonResponse(['success' => false, 'message' => 'Customer email not found'], 400);
        }

        $salesChannelId = $this->resolveSalesChannelId($context);
        if ($salesChannelId === '') {
            return new JsonResponse(['success' => false, 'message' => 'No active sales channel found'], 500);
        }

        $salesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannelId,
            []
        );

        $storefrontUrl = $this->resolveStorefrontUrl($salesChannelId, $context) ?? '';

        $cartToken = method_exists($cart, 'getCartToken')
            ? (string) $cart->getCartToken()
            : (string) ($cart->get('cartToken') ?? '');

        $recoveryUrl = ($storefrontUrl !== '' && $cartToken !== '')
            ? rtrim($storefrontUrl, '/') . '/checkout/cart?token=' . urlencode($cartToken)
            : '';

        $subject = 'You left something in your cart';

        $lineItemsHtml = '';
        if ($includeItems) {
            $lineItems = $cart->get('lineItems');
            if (\is_array($lineItems) && $lineItems !== []) {
                $lineItemsHtml .= '<ul>';
                foreach ($lineItems as $li) {
                    $label = htmlspecialchars((string) ($li['label'] ?? 'Item'), \ENT_QUOTES, 'UTF-8');
                    $qty   = (int) ($li['quantity'] ?? 1);
                    $lineItemsHtml .= "<li>{$label} × {$qty}</li>";
                }
                $lineItemsHtml .= '</ul>';
            }
        }

        $noteHtml = $note !== ''
            ? '<p><strong>Note:</strong><br>' . nl2br(htmlspecialchars($note, \ENT_QUOTES, 'UTF-8')) . '</p>'
            : '';

        $storeName = (string) ($salesChannelContext->getSalesChannel()->getName() ?? 'Store');
        $brand = '#111827';        // dark neutral
        $muted = '#6b7280';        // gray
        $bg    = '#f3f4f6';        // light gray

        $buttonHtml = $recoveryUrl !== ''
            ? '<a href="' . htmlspecialchars($recoveryUrl, \ENT_QUOTES, 'UTF-8') . '"
                 style="display:inline-block;background:' . $brand . ';color:#ffffff;
                        text-decoration:none;padding:12px 18px;border-radius:10px;
                        font-weight:600;font-size:14px;">
                 Resume your cart
               </a>'
            : '';

        $html = '
          <div style="margin:0;padding:0;background:' . $bg . ';width:100%;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
              <tr>
                <td align="center" style="padding:28px 14px;">
                  <table role="presentation" width="600" cellpadding="0" cellspacing="0"
                         style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;
                                box-shadow:0 6px 24px rgba(0,0,0,.08);overflow:hidden;">
                    <tr>
                      <td style="padding:22px 22px 10px 22px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
                        <div style="font-size:18px;font-weight:700;line-height:1.3;color:' . $brand . ';">
                          You left something in your cart
                        </div>
                        <div style="margin-top:8px;font-size:14px;line-height:1.55;color:' . $muted . ';">
                          Hey — it looks like you left some items behind. You can pick up right where you left off.
                        </div>
                      </td>
                    </tr>
        
                    ' . ($buttonHtml !== '' ? '
                    <tr>
                      <td style="padding:0 22px 16px 22px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
                        ' . $buttonHtml . '
                      </td>
                    </tr>' : '') . '
        
                    ' . ($lineItemsHtml !== '' ? '
                    <tr>
                      <td style="padding:0 22px 6px 22px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
                        <div style="font-size:14px;font-weight:700;color:' . $brand . ';margin:10px 0 8px 0;">
                          Items
                        </div>
                        <div style="font-size:14px;line-height:1.5;color:#111827;">
                          ' . str_replace('<ul>', '<ul style="margin:0;padding-left:18px;">', $lineItemsHtml) . '
                        </div>
                      </td>
                    </tr>' : '') . '
        
                    ' . ($noteHtml !== '' ? '
                    <tr>
                      <td style="padding:10px 22px 0 22px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;">
                          <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:' . $muted . ';font-weight:700;">
                            Note
                          </div>
                          <div style="margin-top:6px;font-size:14px;line-height:1.55;color:#111827;">
                            ' . nl2br(htmlspecialchars($note, \ENT_QUOTES, 'UTF-8')) . '
                          </div>
                        </div>
                      </td>
                    </tr>' : '') . '
        
                    <tr>
                      <td style="padding:18px 22px 22px 22px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;">
                        <div style="font-size:13px;color:' . $muted . ';">
                          — ' . htmlspecialchars($storeName, \ENT_QUOTES, 'UTF-8') . '
                        </div>
                      </td>
                    </tr>
                  </table>
        
                  <div style="max-width:600px;width:100%;margin-top:12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;
                              font-size:12px;line-height:1.5;color:' . $muted . ';text-align:center;">
                    If the button doesn’t work, copy and paste this link:<br>
                    <span style="word-break:break-all;">' . htmlspecialchars($recoveryUrl, \ENT_QUOTES, 'UTF-8') . '</span>
                  </div>
                </td>
              </tr>
            </table>
          </div>
        ';


        $recipientName = trim((string) ($customer->getFirstName() ?? '') . ' ' . (string) ($customer->getLastName() ?? ''));
        $recipientName = $recipientName !== '' ? $recipientName : (string) $customer->getEmail();

        $data = [
            'recipients'     => [(string) $customer->getEmail() => $recipientName],
            'senderName'     => $storeName,
            'subject'        => $subject,
            'contentHtml'    => $html,
            'contentPlain'   => strip_tags($html),
            'salesChannelId' => $salesChannelId,
        ];

        $this->mailService->send($data, $context, []);

        return new JsonResponse(['success' => true]);
    }

    private function resolveSalesChannelId(Context $context): string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->setLimit(1);

        $sc = $this->salesChannelRepository->search($criteria, $context)->first();

        return $sc ? (string) $sc->getId() : '';
    }

    private function resolveStorefrontUrl(string $salesChannelId, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->setLimit(1);

        $domain = $this->salesChannelDomainRepository->search($criteria, $context)->first();

        return $domain ? (string) $domain->getUrl() : null;
    }
}
