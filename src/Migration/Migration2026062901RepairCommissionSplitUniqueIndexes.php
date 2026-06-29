<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration2026062901RepairCommissionSplitUniqueIndexes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2026062901;
    }

    public function update(Connection $connection): void
    {
        foreach (['uniq.sales_agent_commission.order_id', 'ux_commission_order'] as $indexName) {
            try {
                $connection->executeStatement(sprintf(
                    'ALTER TABLE `sales_agent_commission` DROP INDEX `%s`',
                    $indexName
                ));
            } catch (\Throwable) {
                // already removed or absent
            }
        }

        try {
            $connection->executeStatement('
                ALTER TABLE `sales_agent_commission`
                ADD UNIQUE `uniq.sales_agent_commission.order_id_agent_id` (`order_id`, `agent_id`)
            ');
        } catch (\Throwable) {
            // already present
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }
}
