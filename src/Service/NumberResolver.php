<?php

declare(strict_types=1);

namespace Salesrep\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class NumberResolver
{
    public function __construct(private readonly SystemConfigService $cfg)
    {
    }

    public function resolveFloat(mixed $perUser, string $configKey, ?string $salesChannelId): float
    {
        if ($perUser !== null && $perUser !== '') {
            return (float) $perUser;
        }
        $v = $this->cfg->get($configKey, $salesChannelId);
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (\is_string($v)) {
            $n = \str_replace([' ', ','], ['', '.'], $v);
            return \is_numeric($n) ? (float) $n : 0.0;
        }
        return (float) $v;
    }

    public function readNumber(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (\is_numeric($v)) {
            return (float) $v;
        }
        if (\is_string($v)) {
            $n = \str_replace([' ', ','], ['', '.'], $v);
            return \is_numeric($n) ? (float) $n : null;
        }
        return null;
    }
}
