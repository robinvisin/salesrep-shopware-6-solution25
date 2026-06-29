<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration2026062903BackfillSplitCommissionsFromUserMarkers extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2026062903;
    }

    public function update(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT
                LOWER(HEX(o.id)) AS order_id,
                LOWER(HEX(o.version_id)) AS order_version_id,
                LOWER(HEX(u.id)) AS split_agent_id,
                LOWER(HEX(main.id)) AS main_commission_id,
                main.commission_amount AS main_commission_amount,
                main.effective_discount_percent,
                main.commission_percent_applied,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.sales_agent_split_percent')) AS DECIMAL(10, 2)) AS split_percent
            FROM `order` o
            INNER JOIN `user` u
                ON LOWER(u.email) = LOWER(JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.sales_agent_split_email')))
            LEFT JOIN `sales_agent_config` split_config
                ON split_config.user_id = u.id
            INNER JOIN `sales_agent_commission` main
                ON main.order_id = o.id
                AND main.order_version_id = o.version_id
                AND main.agent_id <> u.id
                AND main.commission_amount > 0
            LEFT JOIN `sales_agent_commission` split
                ON split.order_id = o.id
                AND split.order_version_id = o.version_id
                AND split.agent_id = u.id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.sales_agent_split_email')) IS NOT NULL
                AND JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.sales_agent_split_email')) <> ''
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(o.custom_fields, '$.sales_agent_split_percent')) AS DECIMAL(10, 2)) > 0
                AND (
                    split_config.user_id IS NOT NULL
                    OR JSON_UNQUOTE(JSON_EXTRACT(u.custom_fields, '$.is_sales_agent')) IN ('true', '1')
                    OR JSON_UNQUOTE(JSON_EXTRACT(u.custom_fields, '$.sales_agent')) IN ('true', '1')
                )
                AND split.id IS NULL
        SQL);

        foreach ($rows as $row) {
            $splitPercent = max(0.0, min(100.0, (float) $row['split_percent']));
            $totalCommission = (float) $row['main_commission_amount'];

            if ($splitPercent <= 0.0 || $totalCommission <= 0.0) {
                continue;
            }

            $splitAmount = round($totalCommission * ($splitPercent / 100.0), 2);
            $mainAmount = round(max(0.0, $totalCommission - $splitAmount), 2);

            if ($splitAmount <= 0.0) {
                continue;
            }

            $connection->executeStatement(
                'UPDATE `sales_agent_commission`
                 SET `commission_amount` = :amount, `updated_at` = NOW(3)
                 WHERE `id` = :id',
                [
                    'amount' => $mainAmount,
                    'id' => Uuid::fromHexToBytes((string) $row['main_commission_id']),
                ]
            );

            $connection->executeStatement(
                'INSERT INTO `sales_agent_commission`
                    (`id`, `order_id`, `order_version_id`, `agent_id`, `effective_discount_percent`, `commission_percent_applied`, `excluded_by_agent_email`, `commission_amount`, `created_at`, `updated_at`)
                 VALUES
                    (:id, :orderId, :orderVersionId, :agentId, :effectiveDiscountPercent, :commissionPercentApplied, 0, :commissionAmount, NOW(3), NULL)',
                [
                    'id' => Uuid::randomBytes(),
                    'orderId' => Uuid::fromHexToBytes((string) $row['order_id']),
                    'orderVersionId' => Uuid::fromHexToBytes((string) $row['order_version_id']),
                    'agentId' => Uuid::fromHexToBytes((string) $row['split_agent_id']),
                    'effectiveDiscountPercent' => (float) $row['effective_discount_percent'],
                    'commissionPercentApplied' => (float) $row['commission_percent_applied'],
                    'commissionAmount' => $splitAmount,
                ]
            );

            $connection->executeStatement(
                "UPDATE `order`
                 SET `custom_fields` = JSON_SET(
                     COALESCE(`custom_fields`, JSON_OBJECT()),
                     '$.sales_agent_split_amount', :splitAmount,
                     '$.sales_agent_commission_split', :splitAmount,
                     '$.sales_agent_split_agent_id', :splitAgentId
                 )
                 WHERE `id` = :orderId AND `version_id` = :orderVersionId",
                [
                    'splitAmount' => $splitAmount,
                    'splitAgentId' => (string) $row['split_agent_id'],
                    'orderId' => Uuid::fromHexToBytes((string) $row['order_id']),
                    'orderVersionId' => Uuid::fromHexToBytes((string) $row['order_version_id']),
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }
}
