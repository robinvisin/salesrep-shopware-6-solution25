<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration2025101001AddSalesAgentOnlyShippingField extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 2025101001;
    }

    public function update(Connection $connection): void
    {
        $setName   = 'sales_agent';
        $fieldName = 'sales_agent_only';

        $relationCol = $this->detectRelationSetColumn($connection);

        $setId = $this->getCustomFieldSetId($connection, $setName);
        if ($setId === null) {
            $setId = Uuid::randomHex();
            $config = json_encode([
                'label'      => ['en-GB' => 'Sales Agent', 'de-DE' => 'Sales Agent'],
                'translated' => [],
            ], \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

            $connection->insert('custom_field_set', [
                'id'         => Uuid::fromHexToBytes($setId),
                'name'       => $setName,
                'config'     => $config,
                'active'     => 1,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }

        $hasRelation = (bool) $connection->fetchOne(
            "SELECT 1 FROM custom_field_set_relation WHERE {$relationCol} = :sid AND entity_name = :entity LIMIT 1",
            ['sid' => Uuid::fromHexToBytes($setId), 'entity' => 'shipping_method']
        );

        if (!$hasRelation) {
            $connection->insert('custom_field_set_relation', [
                'id'          => Uuid::fromHexToBytes(Uuid::randomHex()),
                $relationCol  => Uuid::fromHexToBytes($setId),
                'entity_name' => 'shipping_method',
                'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }

        $fieldId = $this->getCustomFieldId($connection, $setId, $fieldName);
        if ($fieldId === null) {
            $fieldId = Uuid::randomHex();
            $config = json_encode([
                'componentName' => 'sw-field',
                'type'          => 'bool',
                'label'         => ['en-GB' => 'Sales agent only', 'de-DE' => 'Nur für Sales-Agent'],
            ], \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

            $connection->insert('custom_field', [
                'id'         => Uuid::fromHexToBytes($fieldId),
                'name'       => $fieldName,
                'type'       => 'bool',
                'config'     => $config,
                'active'     => 1,
                'set_id'     => Uuid::fromHexToBytes($setId),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function getCustomFieldSetId(Connection $connection, string $name): ?string
    {
        $id = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name LIMIT 1',
            ['name' => $name]
        );

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function getCustomFieldId(Connection $connection, string $setId, string $name): ?string
    {
        $id = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM custom_field WHERE set_id = :sid AND name = :name LIMIT 1',
            ['sid' => Uuid::fromHexToBytes($setId), 'name' => $name]
        );

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return "custom_field_set_id"|"set_id" */
    private function detectRelationSetColumn(Connection $connection): string
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM custom_field_set_relation');
        if (\in_array('custom_field_set_id', $columns, true)) {
            return 'custom_field_set_id';
        }
        if (\in_array('set_id', $columns, true)) {
            return 'set_id';
        }
        throw new \RuntimeException('custom_field_set_relation missing both custom_field_set_id and set_id columns.');
    }
}
