<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1739988888AddCommissionAmount extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1739988888;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `sales_agent_commission`
            ADD COLUMN `commission_amount` DOUBLE NOT NULL DEFAULT 0 AFTER `commission_percent_applied`;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
