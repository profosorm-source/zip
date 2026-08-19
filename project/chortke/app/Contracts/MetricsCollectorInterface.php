<?php

declare(strict_types=1);

namespace App\Contracts;

interface MetricsCollectorInterface
{
    /** @param array<string, scalar|null> $tags */
    public function increment(string $metric, array $tags = []): void;
    /** @param array<string, scalar|null> $tags */
    public function gauge(string $metric, float $value, array $tags = []): void;
    /** @param array<string, scalar|null> $tags */
    public function timing(string $metric, float $seconds, array $tags = []): void;
}
