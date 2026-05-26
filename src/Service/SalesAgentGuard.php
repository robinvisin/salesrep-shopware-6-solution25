<?php

declare(strict_types=1);

namespace SalesAgent\Service;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class SalesAgentGuard
{
    public function isAgent(SalesChannelContext $context): bool
    {
        if (method_exists($context, 'getImitatingUserId') && $context->getImitatingUserId()) {
            return true;
        }

        $customer = $context->getCustomer();
        if (!$customer) {
            return false;
        }

        $cf = $customer->getCustomFields() ?? [];
        $flag = $cf['sales_agent'] ?? false;

        return \in_array($flag, [true, 1, '1'], true);
    }
}
