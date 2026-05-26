<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730390000CreateSalesAgentConfig extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730390000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            <<<SQL
            CREATE TABLE IF NOT EXISTS `sales_agent_config` (
            `id` BINARY(16) NOT NULL,
            `user_id` BINARY(16) NOT NULL,
            `commission_percentage` DOUBLE NULL,
            `discount_limit` DOUBLE NULL,
            `created_at` DATETIME(3) NOT NULL,
            `updated_at` DATETIME(3) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq.user_id` (`user_id`),
            CONSTRAINT `fk.sales_agent_config.user_id` FOREIGN KEY (`user_id`)
                REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
