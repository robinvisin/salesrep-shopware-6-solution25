<?php

declare(strict_types=1);

namespace SalesAgent\Service\ScheduledTask;

use Psr\Log\LoggerInterface;
use Doctrine\DBAL\Exception;
use SalesAgent\Core\Checkout\AbandonedCart\AbandonedCartManager;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: UpdateAbandonedCartTask::class)]
class UpdateAbandonedCartTaskHandler extends ScheduledTaskHandler
{
    protected EntityRepository $scheduledTaskRepository;
    private AbandonedCartManager $manager;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        AbandonedCartManager $manager,
        LoggerInterface $exceptionLogger
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
        $this->manager = $manager;
    }

    /**
     * @throws Exception
     */
    public function run(): void
    {
        $this->manager->updateAbandonedCarts();
    }
}
