<?php

declare(strict_types=1);

namespace SalesAgent\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\ContextTokenResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

#[Package('checkout')]
abstract class AbstractAgentImitateCustomerRoute
{
    abstract public function getDecorated(): AbstractAgentImitateCustomerRoute;

    abstract public function imitateAgentCustomerLogin(Request $request, RequestDataBag $data, SalesChannelContext $context): ContextTokenResponse;
}
