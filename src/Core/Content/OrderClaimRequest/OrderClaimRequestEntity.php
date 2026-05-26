<?php

declare(strict_types=1);

namespace SalesAgent\Core\Content\OrderClaimRequest;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class OrderClaimRequestEntity extends Entity
{
    use EntityIdTrait;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected string $orderId;
    protected string $orderVersionId;

    protected string $requestedByUserId;
    protected string $status;
    protected ?string $reason = null;
    protected \DateTimeInterface $requestedAt;

    protected ?string $decidedByUserId = null;
    protected ?\DateTimeInterface $decidedAt = null;
    protected ?string $decisionNote = null;

    public function getOrderId(): string
    {
        return $this->orderId;
    }
    public function setOrderId(string $v): void
    {
        $this->orderId = $v;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }
    public function setOrderVersionId(string $v): void
    {
        $this->orderVersionId = $v;
    }

    public function getRequestedByUserId(): string
    {
        return $this->requestedByUserId;
    }
    public function setRequestedByUserId(string $v): void
    {
        $this->requestedByUserId = $v;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
    public function setStatus(string $v): void
    {
        $this->status = $v;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
    public function setReason(?string $v): void
    {
        $this->reason = $v;
    }

    public function getRequestedAt(): \DateTimeInterface
    {
        return $this->requestedAt;
    }
    public function setRequestedAt(\DateTimeInterface $v): void
    {
        $this->requestedAt = $v;
    }

    public function getDecidedByUserId(): ?string
    {
        return $this->decidedByUserId;
    }
    public function setDecidedByUserId(?string $v): void
    {
        $this->decidedByUserId = $v;
    }

    public function getDecidedAt(): ?\DateTimeInterface
    {
        return $this->decidedAt;
    }
    public function setDecidedAt(?\DateTimeInterface $v): void
    {
        $this->decidedAt = $v;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }
    public function setDecisionNote(?string $v): void
    {
        $this->decisionNote = $v;
    }
}
