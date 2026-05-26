<?php

declare(strict_types=1);

namespace SalesAgent\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

final class Migration1734860000AddOrderClaimCustomFields extends MigrationStep
{
    private const SET_NAME = 'sales_agent_order_claim';
    private const ENTITY   = 'order';

    public function getCreationTimestamp(): int
    {
        return 1734860000;
    }

    public function update(Connection $connection): void
    {
        $setId = $this->getOrCreateSetId($connection);

        $this->ensureSetRelation($connection, $setId, self::ENTITY);

        $this->upsertField($connection, $setId, 'sales_agent_claimed_user_id', 'text', [
            'label' => [
                'en-GB' => 'Claimed sales agent (User ID)',
                'de-DE' => 'Zugewiesener Sales Agent (User-ID)',
            ],
            'componentName'   => 'sw-field',
            'customFieldType' => 'text',
        ]);

        $this->upsertField($connection, $setId, 'sales_agent_claimed_by_user_id', 'text', [
            'label' => [
                'en-GB' => 'Claimed by (Manager User ID)',
                'de-DE' => 'Zugewiesen von (Manager User-ID)',
            ],
            'componentName'   => 'sw-field',
            'customFieldType' => 'text',
        ]);

        $this->upsertField($connection, $setId, 'sales_agent_claimed_at', 'datetime', [
            'label' => [
                'en-GB' => 'Claimed at',
                'de-DE' => 'Zugewiesen am',
            ],
            'componentName'   => 'sw-field',
            'customFieldType' => 'datetime',
        ]);

        $this->upsertField($connection, $setId, 'sales_agent_claim_reason', 'text', [
            'label' => [
                'en-GB' => 'Claim reason',
                'de-DE' => 'Grund der Zuweisung',
            ],
            'componentName'   => 'sw-textarea-field',
            'customFieldType' => 'text',
        ]);
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }

    /**
     * Returns existing set id (bytes) by name, or creates it and returns new id (bytes).
     */
    private function getOrCreateSetId(Connection $connection): string
    {
        $existing = $connection->fetchOne(
            'SELECT id FROM custom_field_set WHERE name = :name LIMIT 1',
            ['name' => self::SET_NAME]
        );

        if (is_string($existing) && $existing !== '') {
            // Ensure set is active + config updated (idempotent)
            $connection->executeStatement(
                'UPDATE custom_field_set SET active = 1, config = :config WHERE id = :id',
                [
                    'id' => $existing,
                    'config' => json_encode([
                        'label' => [
                            'en-GB' => 'Sales Agent Claim',
                            'de-DE' => 'Sales Agent Claim',
                        ],
                    ], JSON_THROW_ON_ERROR),
                ]
            );

            return $existing;
        }

        $setId = Uuid::randomBytes();

        $connection->executeStatement(
            'INSERT INTO custom_field_set (id, name, config, active, created_at)
             VALUES (:id, :name, :config, 1, NOW())',
            [
                'id' => $setId,
                'name' => self::SET_NAME,
                'config' => json_encode([
                    'label' => [
                        'en-GB' => 'Sales Agent Claim',
                        'de-DE' => 'Sales Agent Claim',
                    ],
                ], JSON_THROW_ON_ERROR),
            ]
        );

        return $setId;
    }

    /**
     * Ensures relation exists for (set_id, entity_name).
     */
    private function ensureSetRelation(Connection $connection, string $setId, string $entityName): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM custom_field_set_relation WHERE set_id = :setId AND entity_name = :entity LIMIT 1',
            ['setId' => $setId, 'entity' => $entityName]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO custom_field_set_relation (id, set_id, entity_name, created_at)
             VALUES (:id, :setId, :entity, NOW())',
            [
                'id' => Uuid::randomBytes(),
                'setId' => $setId,
                'entity' => $entityName,
            ]
        );
    }

    /**
     * Insert OR update a custom field by name; force it into this set, active=1, and update type/config.
     */
    private function upsertField(Connection $connection, string $setIdBytes, string $name, string $type, array $config): void
    {
        $existingId = $connection->fetchOne(
            'SELECT id FROM custom_field WHERE name = :name LIMIT 1',
            ['name' => $name]
        );

        $configJson = json_encode($config, JSON_THROW_ON_ERROR);

        if (is_string($existingId) && $existingId !== '') {
            $connection->executeStatement(
                'UPDATE custom_field
                   SET type = :type,
                       config = :config,
                       active = 1,
                       set_id = :setId
                 WHERE id = :id',
                [
                    'id' => $existingId,
                    'type' => $type,
                    'config' => $configJson,
                    'setId' => $setIdBytes,
                ]
            );

            return;
        }

        $connection->executeStatement(
            'INSERT INTO custom_field (id, name, type, config, active, set_id, created_at)
             VALUES (:id, :name, :type, :config, 1, :setId, NOW())',
            [
                'id' => Uuid::randomBytes(),
                'name' => $name,
                'type' => $type,
                'config' => $configJson,
                'setId' => $setIdBytes,
            ]
        );
    }
}
