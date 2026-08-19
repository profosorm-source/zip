<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

class SagaOrchestratorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function execute_runs_all_steps_and_returns_last_result(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        // saveState/updateStatus داخلی روی جدول saga_executions کوئری می‌زنند → Mock می‌شوند.
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $db->shouldReceive('prepare')->andReturn($stmt)->byDefault();

        $saga = new \App\Services\SagaOrchestrator($db, $logger);

        // رفتار واقعی Orchestrator: هر step یک آرایه برمی‌گرداند که در «context» تجمعی
        // merge می‌شود و execute() همان context نهایی را برمی‌گرداند. تمام سرویس‌های واقعی
        // (Payment/Withdrawal/Investment/Ad/Lottery) دقیقاً بر همین الگو (function($ctx)) تکیه دارند.
        $saga->setSaga('test_saga', ['init' => true]);
        $saga->addStep('step1', function($ctx) { return ['one' => 'v1']; }, function() {});
        $saga->addStep('step2', function($ctx) {
            // step بعدی به نتیجه‌ی merged step قبلی دسترسی دارد
            return ['two' => $ctx['one'] . '-v2'];
        }, function() {});

        $res = $saga->execute();

        $this->assertIsArray($res);
        $this->assertSame('v1', $res['one']);
        $this->assertSame('v1-v2', $res['two']);
        $this->assertTrue($res['init']);
    }

    /** @test */
    public function execute_on_failure_runs_compensations_in_reverse_order(): void
    {
$db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        // saveState/updateStatus داخلی روی جدول saga_executions کوئری می‌زنند → Mock می‌شوند.
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $db->shouldReceive('prepare')->andReturn($stmt)->byDefault();
        $db->shouldReceive('inTransaction')->andReturn(false)->byDefault();
        $db->shouldReceive('beginTransaction')->andReturn(true)->byDefault();
        $db->shouldReceive('commit')->andReturn(true)->byDefault();

        $compensated = [];

        $saga = new \App\Services\SagaOrchestrator($db, $logger);
        $saga->setSaga('test_saga', ['init' => true]);

        $saga->addStep(
            'first',
            function() { return 'x'; },
            function($err) use (&$compensated) { $compensated[] = 'first'; }
        );

        $saga->addStep(
            'second',
            function($prev) { throw new \Exception('boom'); },
            function($err) use (&$compensated) { $compensated[] = 'second'; }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Saga transaction failed and compensated');

        try {
            $saga->execute();
        } catch (\RuntimeException $e) {
            // پس از اجرای compensate، باید فقط "first" در آرایه موجود باشد (step دوم اجرا نشده)
            $this->assertSame(['first'], $compensated);
            throw $e;
        }
    }
}
