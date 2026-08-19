<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * تست معماری Outbox — بررسی قوانین سراسری
 *
 * قانون ۴: هیچ Domain Event مستقیمی Dispatch نمی‌شود
 * قانون ۸: هیچ Service از dispatchAsync() استفاده نمی‌کند
 */
/**
 * @group architecture
 */
class OutboxArchitectureTest extends TestCase
{
    private function getProjectBase(): string
    {
        return dirname(__DIR__, 1) . '/../';
    }

    /** @test */
    public function no_dispatch_async_in_services(): void
    {
        $this->assertNoDispatchAsyncInDirectory('app/Services');
    }

    /** @test */
    public function no_dispatch_async_in_jobs(): void
    {
        $this->assertNoDispatchAsyncInDirectory('app/Jobs');
    }

    /** @test */
    public function no_dispatch_async_in_listeners(): void
    {
        $this->assertNoDispatchAsyncInDirectory('app/Listeners');
    }

    /** @test */
    public function no_dispatch_async_in_models(): void
    {
        $this->assertNoDispatchAsyncInDirectory('app/Models');
    }

    /** @test */
    public function outbox_service_interface_has_record_event(): void
    {
        $ref = new \ReflectionClass(\App\Contracts\OutboxServiceInterface::class);
        $methods = array_map(fn($m) => $m->getName(), $ref->getMethods());
        
        $this->assertContains('record', $methods);
        $this->assertContains('recordEvent', $methods);
    }

    /** @test */
    public function outbox_service_implements_interface(): void
    {
        $ref = new \ReflectionClass(\App\Services\OutboxService::class);
        $this->assertTrue($ref->implementsInterface(\App\Contracts\OutboxServiceInterface::class));
    }

    /** @test */
    public function domain_event_interface_has_required_methods(): void
    {
        $ref = new \ReflectionClass(\App\Contracts\DomainEvent::class);
        $methods = array_map(fn($m) => $m->getName(), $ref->getMethods());

        $this->assertContains('aggregateType', $methods);
        $this->assertContains('aggregateId', $methods);
        $this->assertContains('toPayload', $methods);
    }

    /** @test */
    public function generic_event_implements_domain_event(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(\Core\GenericEvent::class))
                ->implementsInterface(\App\Contracts\DomainEvent::class)
        );
    }

    /** @test */
    public function outbox_publisher_uses_sync_dispatch_not_async(): void
    {
        $cleaned = $this->readCode($this->getProjectBase() . 'app/Services/OutboxPublisher.php');

        $this->assertStringNotContainsString('dispatchAsync(', $cleaned,
            'OutboxPublisher should use dispatch() not dispatchAsync() — avoids double-queue');

        $this->assertStringContainsString('->dispatchOrFail(', $cleaned,
            'OutboxPublisher must use strict synchronous dispatch so failures trigger retry/DLQ');
    }

    /** @test */
    public function in_process_events_use_sync_dispatch(): void
    {
        // cache.invalidate and dashboard.stats باید dispatch سینکرون باشن
        $files = [
            'app/Services/MessageModerationService.php',
            'app/Services/AdminDashboard/DashboardQueryService.php',
        ];

        foreach ($files as $file) {
            $cleaned = $this->readCode($this->getProjectBase() . $file);

            if (strpos($cleaned, 'cache.invalidate') !== false || strpos($cleaned, 'dashboard.stats') !== false) {
                $this->assertStringNotContainsString('dispatchAsync(', $cleaned,
                    "$file: In-process events should use dispatch() not dispatchAsync()");
            }
        }
    }

    // ─── Helper ─────────────────────────────────────────────────

    private function readCode(string $path): string
    {
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $cleaned = preg_replace('/\/\/.*$/m', '', $content);
        $this->assertIsString($cleaned);
        $cleaned = preg_replace('/\/\*.*?\*\//s', '', $cleaned);
        $this->assertIsString($cleaned);
        return $cleaned;
    }

    private function assertNoDispatchAsyncInDirectory(string $dir): void
    {
        $base = $this->getProjectBase();
        $fullDir = $base . $dir;
        
        if (!is_dir($fullDir)) {
            $this->fail("Directory $dir not found");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullDir, \FilesystemIterator::SKIP_DOTS)
        );

        $violations = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') continue;

            $cleaned = $this->readCode($file->getPathname());

            if (strpos($cleaned, 'dispatchAsync(') !== false) {
                $relative = str_replace($base, '', $file->getPathname());
                $violations[] = $relative;
            }
        }

        $this->assertEmpty(
            $violations,
            "dispatchAsync() found in files (should use outbox->record):\n  " . implode("\n  ", $violations)
        );
    }
}
