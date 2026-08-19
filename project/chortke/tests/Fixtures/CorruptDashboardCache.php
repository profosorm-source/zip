<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class CorruptDashboardCache extends \Core\Cache
{
    public int $getCalls = 0;

    public function __construct() {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->getCalls++;
        return 'corrupt-cache-payload';
    }
}
