<?php

declare(strict_types=1);

namespace Salesrep\Core\Content\AgentPayoutHistory;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\User\UserEntity;

class AgentPayoutHistoryEntity extends Entity
{
    use EntityIdTrait;

    protected string $agentId;
    protected \DateTimeInterface $payoutDate;
    protected string $paymentMethod;
    protected float $outstandingBalance;
    protected ?float $payoutAmount = null;
    protected ?string $note = null;
    protected ?UserEntity $salesrep = null;

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function setAgentId(string $agentId): void
    {
        $this->agentId = $agentId;
    }

    public function getPayoutDate(): \DateTimeInterface
    {
        return $this->payoutDate;
    }

    public function setPayoutDate(\DateTimeInterface $payoutDate): void
    {
        $this->payoutDate = $payoutDate;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): void
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function getOutstandingBalance(): float
    {
        return $this->outstandingBalance;
    }

    public function setOutstandingBalance(float $outstandingBalance): void
    {
        $this->outstandingBalance = $outstandingBalance;
    }

    public function getPayoutAmount(): ?float
    {
        return $this->payoutAmount;
    }

    public function setPayoutAmount(?float $payoutAmount): void
    {
        $this->payoutAmount = $payoutAmount;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getSalesrep(): ?UserEntity
    {
        return $this->salesrep;
    }

    public function setSalesrep(?UserEntity $salesrep): void
    {
        $this->salesrep = $salesrep;
    }
}
