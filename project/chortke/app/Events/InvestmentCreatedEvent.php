<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;
use App\Contracts\DomainEvent;

class InvestmentCreatedEvent extends Event implements DomainEvent
{
    public int $userId;
    public int $investmentId;
    public string $amount;
    public string $currency;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $investmentId,
        string $amount,
        string $currency = 'usdt',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->investmentId = $investmentId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'investment_id' => $investmentId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }

    public function aggregateType(): string { return 'investment'; }
    public function aggregateId(): string { return (string)$this->investmentId; }
    /** @return array<string, mixed> */
    public function toPayload(): array { $payload = $this->getData(); return is_array($payload) ? $payload : []; }
}
