<?php

declare(strict_types=1);

namespace App\Events;

class ScoreDeltaAppendedEvent implements \App\Contracts\DomainEvent
{
    public string $entityType;
    public int $entityId;
    public string $domain;
    public float $delta;
    public string $source;
    public function __construct(
        string $entityType,
        int $entityId,
        string $domain,
        float $delta,
        string $source
    ) {
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->domain = $domain;
        $this->delta = $delta;
        $this->source = $source;
    }

    public function aggregateType(): string { return $this->entityType; }
    public function aggregateId(): string { return (string)$this->entityId; }
    public function toPayload(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id'   => $this->entityId,
            'domain'      => $this->domain,
            'delta'       => $this->delta,
            'source'      => $this->source,
        ];
    }
}
