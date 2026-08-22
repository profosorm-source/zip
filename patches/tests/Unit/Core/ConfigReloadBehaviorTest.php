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

    /**
     * رگرسیون: پیکربندیِ فایلی باید پس از reload هم زنده بماند.
     *
     * بارگذار داخلی از require_once استفاده می‌کرد؛ چون config_reload()
     * فقط کش داخلی ($configData/$configLoaded) را خالی می‌کند و نه جدولِ
     * فایل‌هایِ include‌شدهٔ PHP را، بارِ دوم به‌جای آرایه مقدار true
     * برمی‌گشت و کل پیکربندیِ فایلی برای همیشه ناپدید می‌شد.
     *
     * اثر واقعی در محصول: QueueWorker پس از هر job، config_reload() صدا
     * می‌زند؛ یعنی worker های بلندمدت تنظیماتشان را از دست می‌دادند.
     */
    public function test_full_reload_keeps_file_backed_configuration_readable(): void
    {
        $before = config('circuit_breaker');
        $this->assertIsArray($before);
        $this->assertArrayHasKey('payment_gateway:zarinpal', $before);

        config_reload();

        $after = config('circuit_breaker');
        $this->assertIsArray($after, 'پس از config_reload() پیکربندیِ فایلی دیگر آرایه نیست.');
        $this->assertArrayHasKey('payment_gateway:zarinpal', $after);
        $this->assertSame($before, $after);
    }

    public function test_repeated_reloads_do_not_erode_file_backed_configuration(): void
    {
        $expected = config('circuit_breaker');
        $this->assertIsArray($expected);

        // شبیه‌سازی چرخهٔ عمرِ یک worker که بعد از هر job، reload می‌کند.
        for ($i = 0; $i < 5; $i++) {
            config_reload();
            $this->assertSame(
                $expected,
                config('circuit_breaker'),
                "پیکربندی پس از reload شمارهٔ " . ($i + 1) . " تغییر کرد."
            );
        }

        $this->assertNotNull(config('app.url'));
    }

    public function test_scoped_reload_keeps_the_reloaded_file_readable(): void
    {
        $before = config('circuit_breaker');
        $this->assertIsArray($before);

        config_reload('config');

        $after = config('circuit_breaker');
        $this->assertIsArray($after);
        $this->assertArrayHasKey('payment_gateway:zarinpal', $after);
        $this->assertSame($before, $after);
    }
}
