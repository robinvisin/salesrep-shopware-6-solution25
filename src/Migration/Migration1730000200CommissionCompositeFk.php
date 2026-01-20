<?php

declare(strict_types=1);

namespace Salesrep\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000200CommissionCompositeFk extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000200;
    }

    public function update(Connection $c): void
    {
        try {
            $c->executeStatement('
                ALTER TABLE `salesrep_commission`
                ADD COLUMN `order_version_id` BINARY(16) NULL
            ');
        } catch (\Throwable $e) {
            // already exists — continue
        }

        $c->executeStatement('
            UPDATE `salesrep_commission`
            SET `order_version_id` = UNHEX(:live)
            WHERE `order_version_id` IS NULL
        ', ['live' => Defaults::LIVE_VERSION]);

        $c->executeStatement('
            ALTER TABLE `salesrep_commission`
            MODIFY `order_version_id` BINARY(16) NOT NULL
        ');

        try {
            $c->executeStatement('
                ALTER TABLE `salesrep_commission`
                DROP FOREIGN KEY `fk.sales_agent_commission.order_id`
            ');
        } catch (\Throwable $e) {
            // may not exist — ignore
        }

        try {
            $c->executeStatement('
                ALTER TABLE `salesrep_commission`
                ADD CONSTRAINT `fk.salesrep_commission.order`
                FOREIGN KEY (`order_id`, `order_version_id`)
                REFERENCES `order` (`id`, `version_id`)
                ON DELETE RESTRICT ON UPDATE RESTRICT
            ');
        } catch (\Throwable $e) {
            // already present — ignore
        }

        try {
            $c->executeStatement('
                ALTER TABLE `salesrep_commission`
                ADD UNIQUE KEY `ux_commission_order` (`order_id`)
            ');
        } catch (\Throwable $e) {
        }
    }

    public function updateDestructive(Connection $c): void
    {
    }
}
