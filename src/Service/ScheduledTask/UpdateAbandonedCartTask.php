<?php

declare(strict_types=1);

namespace Salesrep\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class UpdateAbandonedCartTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'salesrep.abandoned_cart.update';
    }

    public static function getDefaultInterval(): int
    {
        return 20;
    }
}
