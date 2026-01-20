<?php

declare(strict_types=1);

namespace Salesrep\Service\ScheduledTask;

use Doctrine\DBAL\Exception;
use Salesrep\Core\Checkout\AbandonedCart\AbandonedCartManager;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: MarkAbandonedCartTask::class)]
class MarkAbandonedCartTaskHandler extends ScheduledTaskHandler
{
    protected EntityRepository $scheduledTaskRepository;
    private AbandonedCartManager $manager;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        AbandonedCartManager $manager
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->manager = $manager;
    }
    /**
     * @throws Exception
     */

    public function run(): void
    {
        $this->manager->generate();
    }
}
