<?php

declare(strict_types=1);

namespace Admin\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService
{
    /**
     * @param SystemConfigService $configService
     */
    public function __construct(
        private readonly SystemConfigService $configService
    ) {
    }

    /**
     * @return int
     */
    public function getAbandonedCartTime(): int
    {
        return $this->configService->get('AbandonedCartAdmin.config.markAbandonedAfter') ?? 300;
    }

    /**
     * @param int $seconds
     * @return void
     */
    public function setAbandonedCartTime(int $seconds): void
    {
        $this->configService->set('AbandonedCartAdmin.config.markAbandonedAfter', $seconds);
    }
}
