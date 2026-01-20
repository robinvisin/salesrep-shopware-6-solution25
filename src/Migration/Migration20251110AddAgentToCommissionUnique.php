<?php

declare(strict_types=1);

namespace Salesrep\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration20251110AddAgentToCommissionUnique extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2025111001;
    }

    public function update(Connection $connection): void
    {
        // drop old unique on order_id
        $connection->executeStatement('
            ALTER TABLE `salesrep_commission`
            DROP INDEX `uniq.salesrep_commission.order_id`
        ');

        // add new unique on (order_id, agent_id)
        $connection->executeStatement('
            ALTER TABLE `salesrep_commission`
            ADD UNIQUE `uniq.salesrep_commission.order_id_agent_id` (`order_id`, `agent_id`)
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // nothing
    }
}
