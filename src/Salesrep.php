<?php

declare(strict_types=1);

namespace Salesrep;

use Doctrine\DBAL\Connection;
use Salesrep\Core\Privileges\SalesrepPrivileges;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;

class Salesrep extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        $connection = $this->container->get(Connection::class);

        $roleId = Uuid::fromStringToHex('SALESREP');

        $privileges = SalesrepPrivileges::get();


        $existingRole = $connection->fetchOne(
            'SELECT id FROM acl_role WHERE name = ?',
            ['Sales rep']
        );

        if (!$existingRole) {
            $connection->executeStatement(
                'INSERT INTO acl_role (id, name, description, privileges, created_at, updated_at) 
         VALUES (:id, :name, :description, :privileges, NOW(), NOW())',
                [
                    'id' => hex2bin($roleId),
                    'name' => 'Sales rep ',
                    'description' => 'Salesrep Plugin Role',
                    'privileges' => json_encode($privileges),
                ]
            );
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $roleId = Uuid::fromStringToHex('SALESREP');

        $connection->executeStatement(
            'DELETE FROM acl_role WHERE id = ?',
            [hex2bin($roleId)]
        );

        $connection->executeStatement('DROP TABLE IF EXISTS `salesrep_commission`');
        $connection->executeStatement('DROP TABLE IF EXISTS `salesrep_config`');
        $connection->executeStatement('DROP TABLE IF EXISTS `salesrep_abandoned_cart`');
    }


    public function activate(ActivateContext $ctx): void
    {
    }


    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    public function update(UpdateContext $updateContext): void
    {
        // Update necessary stuff, mostly non-database related
    }

    public function postInstall(InstallContext $installContext): void
    {
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }
}
