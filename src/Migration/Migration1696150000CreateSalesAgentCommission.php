<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1696150000CreateSalesAgentCommission extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1696150000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            <<<SQL
        CREATE TABLE IF NOT EXISTS `sales_agent_commission` (
            `id` BINARY(16) NOT NULL,
            `order_id` BINARY(16) NOT NULL,
            `agent_id` BINARY(16) NULL,

            `effective_discount_percent` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `commission_percent_applied` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `excluded_by_agent_email` TINYINT(1) NOT NULL DEFAULT 0,

            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,

            CONSTRAINT `pk.sales_agent_commission` PRIMARY KEY (`id`),
            CONSTRAINT `fk.sales_agent_commission.order_id` FOREIGN KEY (`order_id`)
                REFERENCES `order` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk.sales_agent_commission.agent_id` FOREIGN KEY (`agent_id`)
                REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

            UNIQUE KEY `uniq.sales_agent_commission.order_id` (`order_id`),
            KEY `idx.sales_agent_commission.agent_id` (`agent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }
}
