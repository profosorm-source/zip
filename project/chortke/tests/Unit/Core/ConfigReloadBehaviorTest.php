<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class ConfigReloadBehaviorTest extends TestCase
{
    public function test_file_config_reload_preserves_explicit_runtime_override(): void
    {
        $original = config('app.url');
        $this->assertIsString($original);
        config_set('app.url', 'https://runtime-override.example.test');

        try {
            config_reload();
            $this->assertSame('https://runtime-override.example.test', config('app.url'));
        } finally {
            config_set('app.url', $original);
        }
    }

    public function test_scoped_reload_preserves_unrelated_runtime_override(): void
    {
        $original = config('app.url');
        $this->assertIsString($original);
        config_set('app.url', 'https://runtime-override.example.test');

        try {
            config_reload('feature_flags');
            $this->assertSame('https://runtime-override.example.test', config('app.url'));
        } finally {
            config_set('app.url', $original);
        }
    }
}
