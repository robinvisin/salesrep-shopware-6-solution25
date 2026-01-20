<?php

declare(strict_types=1);

namespace Salesrep\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class MarkAbandonedCartTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'salesrep.abandoned_cart.mark';
    }

    public static function getDefaultInterval(): int
    {
        return 10;
    }
}
