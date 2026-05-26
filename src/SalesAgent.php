<?php

declare(strict_types=1);

namespace SalesAgent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use SalesAgent\Core\Privileges\SalesAgentPrivileges;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;

class SalesAgent extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        $privileges = SalesAgentPrivileges::get();

        $existingRoleId = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM acl_role WHERE name = :name LIMIT 1',
            ['name' => 'Sales Agent']
        );

        if (!$existingRoleId) {
            $connection->executeStatement(
                'INSERT INTO acl_role (id, name, description, privileges, created_at, updated_at)
                 VALUES (UNHEX(:id), :name, :description, :privileges, NOW(), NOW())',
                [
                    'id' => Uuid::randomHex(),
                    'name' => 'Sales Agent',
                    'description' => 'Sales Agent Plugin Role',
                    'privileges' => json_encode($privileges, JSON_THROW_ON_ERROR),
                ]
            );
        } else {
            $connection->executeStatement(
                'UPDATE acl_role SET privileges = :privileges, updated_at = NOW() WHERE id = UNHEX(:id)',
                [
                    'id' => $existingRoleId,
                    'privileges' => json_encode($privileges, JSON_THROW_ON_ERROR),
                ]
            );
        }

        if (!$this->tableExists($connection, 'sales_agent_config')) {
            return;
        }

        $this->seedDefaultConfigForAdmins($connection);
    }

    public function postInstall(InstallContext $installContext): void
    {
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        if ($this->tableExists($connection, 'sales_agent_config')) {
            $this->seedDefaultConfigForAdmins($connection);
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        $connection->executeStatement(
            'DELETE FROM acl_role WHERE name = :name',
            ['name' => 'Sales Agent']
        );

        $connection->executeStatement('DROP TABLE IF EXISTS `sales_agent_commission`');
        $connection->executeStatement('DROP TABLE IF EXISTS `sales_agent_config`');
        $connection->executeStatement('DROP TABLE IF EXISTS `sales_agent_abandoned_cart`');
    }

    public function activate(ActivateContext $ctx): void {}
    public function deactivate(DeactivateContext $deactivateContext): void {}
    public function update(UpdateContext $updateContext): void {}
    public function postUpdate(UpdateContext $updateContext): void {}

    private function seedDefaultConfigForAdmins(Connection $connection): void
    {
        $adminUserIds = $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM `user` WHERE admin = 1'
        );

        if (!$adminUserIds) {
            $firstUserId = $connection->fetchOne(
                'SELECT LOWER(HEX(id)) FROM `user` ORDER BY created_at ASC LIMIT 1'
            );
            $adminUserIds = $firstUserId ? [$firstUserId] : [];
        }

        foreach ($adminUserIds as $uid) {
            $exists = $connection->fetchOne(
                'SELECT 1 FROM sales_agent_config WHERE user_id = UNHEX(:uid) LIMIT 1',
                ['uid' => $uid]
            );

            if (!$exists) {
                $connection->executeStatement(
                    'INSERT INTO sales_agent_config (id, user_id, commission_percentage, discount_limit, created_at, updated_at)
                     VALUES (UNHEX(:id), UNHEX(:uid), :commission, :discount, NOW(), NOW())',
                    [
                        'id' => Uuid::randomHex(),
                        'uid' => $uid,
                        'commission' => null,
                        'discount' => 100.0,
                    ]
                );
            }
        }
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        $sm = method_exists($connection, 'createSchemaManager')
            ? $connection->createSchemaManager()
            : $connection->getSchemaManager();

        /** @var AbstractSchemaManager $sm */
        return $sm->tablesExist([$table]);
    }
}
