<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\ServiceRestarter;

/**
 * تست‌های خودِ لایهٔ انتزاعیِ ری‌استارت.
 *
 * نکتهٔ اصلیِ توصیهٔ ۴ «تزریق‌پذیری» بود؛ ادعای تزریق‌پذیری بدون آزمون، ادعا
 * باقی می‌ماند. اینجا اجراکنندهٔ فرمان جعلی تزریق می‌شود تا انتخاب استراتژی
 * بدون دست زدن به هیچ سرویس واقعی سنجیده شود.
 */
final class ServiceRestarterTest extends TestCase
{
    /**
     * @param array<string,array{0:int,1:string}> $responses
     * @param list<string> $seen
     */
    private function fakeRunner(array $responses, array &$seen): callable
    {
        return static function (string $command) use ($responses, &$seen): array {
            $seen[] = $command;
            foreach ($responses as $needle => $reply) {
                if (str_contains($command, $needle)) {
                    return $reply;
                }
            }
            return [1, ''];
        };
    }

    public function test_override_env_var_wins_over_every_other_strategy(): void
    {
        putenv('CHAOS_REDIS_RESTART_CMD=/bin/true restart-me');
        try {
            $seen = [];
            $restarter = new ServiceRestarter($this->fakeRunner([
                // حتی اگر systemd هم پاسخ مثبت بدهد، override باید برنده شود.
                'systemctl list-unit-files' => [0, 'redis-server.service enabled'],
                '/bin/true restart-me' => [0, 'done'],
            ], $seen));

            $this->assertSame('override', $restarter->availableStrategy('redis-server'));
            $result = $restarter->restart('redis-server');
            $this->assertTrue($result['ok']);
            $this->assertSame('override', $result['strategy']);
            $this->assertContains('/bin/true restart-me', $seen);
        } finally {
            putenv('CHAOS_REDIS_RESTART_CMD');
        }
    }

    public function test_systemctl_is_used_when_the_unit_actually_exists(): void
    {
        $seen = [];
        $restarter = new ServiceRestarter($this->fakeRunner([
            'systemctl list-unit-files' => [0, 'redis-server.service enabled enabled'],
            'sudo -n true' => [1, ''],
            'systemctl restart' => [0, ''],
        ], $seen));

        $this->assertSame('systemctl', $restarter->availableStrategy('redis-server'));
        $result = $restarter->restart('redis-server');
        $this->assertTrue($result['ok']);
        $this->assertSame('systemctl', $result['strategy']);

        $restartCalls = array_values(array_filter($seen, static fn(string $c): bool => str_contains($c, 'systemctl restart')));
        $this->assertCount(1, $restartCalls);
        $this->assertStringContainsString('redis-server.service', $restartCalls[0]);
        // sudo در دسترس نبود، پس نباید به فرمان اضافه شده باشد.
        $this->assertStringNotContainsString('sudo', $restartCalls[0]);
    }

    public function test_sudo_prefix_is_added_only_when_passwordless_sudo_works(): void
    {
        $seen = [];
        $restarter = new ServiceRestarter($this->fakeRunner([
            'systemctl list-unit-files' => [0, 'redis-server.service enabled'],
            'sudo -n true' => [0, ''],
            'systemctl restart' => [0, ''],
        ], $seen));

        $restarter->restart('redis-server');
        $restartCalls = array_values(array_filter($seen, static fn(string $c): bool => str_contains($c, 'systemctl restart')));
        $this->assertCount(1, $restartCalls);
        $this->assertStringStartsWith('sudo -n ', $restartCalls[0]);
    }

    public function test_falls_back_to_process_strategy_when_no_service_manager_exists(): void
    {
        $seen = [];
        $restarter = new ServiceRestarter($this->fakeRunner([
            'systemctl list-unit-files' => [1, 'Unit redis-server.service not found.'],
            'ps -o pid=,args= -C' => [0, ' 4242 /usr/bin/redis-server 127.0.0.1:6379'],
        ], $seen));

        $this->assertSame('process', $restarter->availableStrategy('redis-server'));
    }

    public function test_returns_null_strategy_and_a_documented_skip_reason_when_nothing_is_available(): void
    {
        $seen = [];
        $restarter = new ServiceRestarter($this->fakeRunner([], $seen));

        $this->assertNull($restarter->availableStrategy('redis-server'));

        $result = $restarter->restart('redis-server');
        $this->assertFalse($result['ok']);
        $this->assertSame('none', $result['strategy']);

        // پیام skip باید هر چهار راه‌حل را به کاربر نشان دهد؛ skip مبهم بی‌فایده است.
        $reason = $restarter->skipReason('redis-server');
        $this->assertStringContainsString('systemctl', $reason);
        $this->assertStringContainsString('/etc/init.d/redis-server', $reason);
        $this->assertStringContainsString('CHAOS_REDIS_RESTART_CMD', $reason);
        $this->assertStringContainsString('محدودیتِ محیط است، نه نقص محصول', $reason);
    }

    public function test_process_strategy_waits_for_termination_before_relaunching(): void
    {
        // مسیر پیکربندی باید واقعاً موجود باشد: relaunchCommand عمداً یک
        // is_file() انجام می‌دهد تا سرویس را با مسیرِ ناموجود بالا نیاورد.
        $configPath = tempnam(sys_get_temp_dir(), 'restarter-conf-');
        $this->assertIsString($configPath);
        $seen = [];
        $restarter = new ServiceRestarter(static function (string $command) use (&$seen, $configPath): array {
            $seen[] = $command;
            if (str_contains($command, 'systemctl') || str_contains($command, 'sudo')) {
                return [1, ''];
            }
            if (str_contains($command, 'INFO server')) {
                return [0, "executable:/usr/bin/redis-server\nconfig_file:" . $configPath];
            }
            if (str_contains($command, 'ps -o pid=,args= -C')) {
                return [0, ' 4242 /usr/bin/redis-server 127.0.0.1:6379'];
            }
            if (str_contains($command, 'kill -0')) {
                return [1, ''];   // پروسه رفته است
            }
            return [0, ''];
        });

        $result = $restarter->restart('redis-server');

        // پیش از هر nohup، باید یک kill -TERM و سپس بررسی kill -0 دیده شود.
        $termIndex = null;
        $checkIndex = null;
        $launchIndex = null;
        foreach ($seen as $i => $command) {
            if ($termIndex === null && str_contains($command, 'kill -TERM 4242')) {
                $termIndex = $i;
            } elseif ($checkIndex === null && str_contains($command, 'kill -0 4242')) {
                $checkIndex = $i;
            } elseif ($launchIndex === null && str_contains($command, 'nohup')) {
                $launchIndex = $i;
            }
        }
        $this->assertNotNull($termIndex, 'سیگنال TERM ارسال نشد.');
        $this->assertNotNull($checkIndex, 'خروج پروسه بررسی نشد.');
        $this->assertNotNull($launchIndex, 'سرویس دوباره اجرا نشد.');
        $this->assertLessThan($checkIndex, $termIndex);
        $this->assertLessThan($launchIndex, $checkIndex);

        // خط فرمان باید از INFO ساخته شود، نه از عنوان بازنویسی‌شدهٔ ps.
        $launch = $seen[$launchIndex];
        $this->assertStringContainsString($configPath, $launch);
        $this->assertStringNotContainsString('127.0.0.1:6379', $launch);

        // نتیجه هرچه باشد، باید صادقانه گزارش شود: استراتژی حتماً «process»
        // است و خروجی هرگز ادعای موفقیتِ بی‌پشتوانه نمی‌کند. (موفقیت واقعی به
        // پذیرش اتصال روی پورت Redis گره خورده و به محیط بستگی دارد؛ بنابراین
        // اینجا روی مقدار آن ادعا نمی‌کنیم.)
        $this->assertSame('process', $result['strategy']);
        $this->assertIsBool($result['ok']);
        $this->assertNotSame('', $result['output']);

        @unlink($configPath);
    }

    public function test_sigkill_is_escalated_when_the_process_ignores_sigterm(): void
    {
        $seen = [];
        $restarter = new ServiceRestarter(static function (string $command) use (&$seen): array {
            $seen[] = $command;
            if (str_contains($command, 'systemctl') || str_contains($command, 'sudo')) {
                return [1, ''];
            }
            if (str_contains($command, 'INFO server')) {
                return [0, "executable:/usr/bin/redis-server\nconfig_file:/etc/redis/redis.conf"];
            }
            if (str_contains($command, 'ps -o pid=,args= -C')) {
                return [0, ' 4242 /usr/bin/redis-server 127.0.0.1:6379'];
            }
            if (str_contains($command, 'kill -0')) {
                return [0, ''];   // هرگز نمی‌میرد
            }
            return [0, ''];
        });

        $restarter->restart('redis-server');

        $this->assertNotEmpty(
            array_filter($seen, static fn(string $c): bool => str_contains($c, 'kill -KILL 4242')),
            'پروسه‌ای که TERM را نادیده می‌گیرد باید با KILL خاتمه یابد.'
        );
    }
}
