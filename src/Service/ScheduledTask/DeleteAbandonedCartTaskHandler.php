<?php

declare(strict_types=1);

namespace SalesAgent\Service\ScheduledTask;

use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Exception;
use SalesAgent\Core\Checkout\AbandonedCart\AbandonedCartManager;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: DeleteAbandonedCartTask::class)]
class DeleteAbandonedCartTaskHandler extends ScheduledTaskHandler
{
    private AbandonedCartManager $manager;

    public function __construct(
        /** @var EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> */
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        AbandonedCartManager $manager
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->manager = $manager;
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->manager->cleanUp();
    }
}
