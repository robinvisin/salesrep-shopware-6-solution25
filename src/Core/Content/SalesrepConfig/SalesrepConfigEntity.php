<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\SalesrepConfig;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\User\UserEntity;

class SalesrepConfigEntity extends Entity
{
    use EntityIdTrait;

    protected string $userId;
    protected ?float $commissionPercentage = null;
    protected ?float $discountLimit = null;

    protected ?UserEntity $user = null;

    public function getUserId(): string
    {
        return $this->userId;
    }
    public function setUserId(string $id): void
    {
        $this->userId = $id;
    }

    public function getCommissionPercentage(): ?float
    {
        return $this->commissionPercentage;
    }
    public function setCommissionPercentage(?float $v): void
    {
        $this->commissionPercentage = $v;
    }

    public function getDiscountLimit(): ?float
    {
        return $this->discountLimit;
    }
    public function setDiscountLimit(?float $v): void
    {
        $this->discountLimit = $v;
    }

    public function getUser(): ?UserEntity
    {
        return $this->user;
    }
    public function setUser(?UserEntity $u): void
    {
        $this->user = $u;
    }
}
