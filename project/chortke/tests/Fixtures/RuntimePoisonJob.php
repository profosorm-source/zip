<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class RuntimePoisonJob
{
    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        throw new \TypeError('Deterministic poison message for queue/DLQ runtime test');
    }
}
