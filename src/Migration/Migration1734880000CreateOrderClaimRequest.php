<?php

declare(strict_types=1);

namespace Salesrep\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1734880000CreateOrderClaimRequest extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1734880000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<SQL
        CREATE TABLE IF NOT EXISTS `salesrep_order_claim_request` (
        `id` BINARY(16) NOT NULL,
        `order_id` BINARY(16) NOT NULL,
        `order_version_id` BINARY(16) NOT NULL,
        `requested_by_user_id` BINARY(16) NOT NULL,
        `status` VARCHAR(32) NOT NULL,
        `reason` LONGTEXT NULL,
        `requested_at` DATETIME(3) NOT NULL,
        `decided_by_user_id` BINARY(16) NULL,
        `decided_at` DATETIME(3) NULL,
        `decision_note` LONGTEXT NULL,
        `created_at` DATETIME(3) NOT NULL,
        `updated_at` DATETIME(3) NULL,
        PRIMARY KEY (`id`),
        KEY `idx.sa_claim_req.order` (`order_id`, `order_version_id`),
        KEY `idx.sa_claim_req.status` (`status`),
        KEY `idx.sa_claim_req.requested_by` (`requested_by_user_id`),
        CONSTRAINT `fk.sa_claim_req.order`
            FOREIGN KEY (`order_id`, `order_version_id`)
            REFERENCES `order` (`id`, `version_id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk.sa_claim_req.requested_by`
            FOREIGN KEY (`requested_by_user_id`)
            REFERENCES `user` (`id`)
            ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk.sa_claim_req.decided_by`
            FOREIGN KEY (`decided_by_user_id`)
            REFERENCES `user` (`id`)
            ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
