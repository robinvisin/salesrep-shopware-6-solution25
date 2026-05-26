<?php

declare(strict_types=1);

namespace SalesAgent\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CancelUnpaidOrdersTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'salesAgent.cancel_unpaid_orders';
    }

    public static function getDefaultInterval(): int
    {
        return 3600;
    }
}
