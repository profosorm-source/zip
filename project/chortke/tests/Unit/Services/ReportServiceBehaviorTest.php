<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Interaction\ReportService;
use App\Enums\ModuleContext;
use App\Contracts\OutboxServiceInterface;

/**
 * @group architecture
 */
class ReportServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @return array{svc:ReportService,db:\Core\Database&\Mockery\MockInterface,logger:\App\Contracts\LoggerInterface&\Mockery\MockInterface,outbox:OutboxServiceInterface|null,model:\App\Models\InteractionModel&\Mockery\MockInterface} */
    private function make(?OutboxServiceInterface $outbox = null): array
    {
        $ed = m::mock('Core\\EventDispatcher'); $ed->shouldIgnoreMissing();
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\InteractionModel'); $model->shouldIgnoreMissing();

        $svc = new ReportService($db, $logger, $model, $outbox);
        return compact('svc', 'db', 'logger', 'outbox', 'model');
    }

    /** @test */
    public function submit_inserts_report_and_returns_true(): void
    {
        $c = $this->make();

        $c['db']->shouldReceive('beginTransaction')->once();
        $c['db']->shouldReceive('commit')->once();

        $c['model']->shouldReceive('createReport')->once()->andReturn(1);
        $c['model']->shouldReceive('countReports')->once()->andReturn(3); // < 5

        $this->assertTrue($c['svc']->submit(1, 'ad', 100, ModuleContext::SOCIAL_TASKS, 'inappropriate'));
    }

    /** @test */
    public function submit_triggers_outbox_when_threshold_reached(): void
    {
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $c = $this->make($outbox);

        $c['db']->shouldReceive('beginTransaction')->once();
        $c['db']->shouldReceive('commit')->once();

        $c['model']->shouldReceive('createReport')->once()->andReturn(5);
        $c['model']->shouldReceive('countReports')->once()->andReturn(5); // >= 5

        $outbox->shouldReceive('record')->with('report', 100, 'report.threshold_reached', m::type('array'))->once();

        $this->assertTrue($c['svc']->submit(1, 'ad', 100, ModuleContext::SOCIAL_TASKS, 'spam'));
    }

    /** @test */
    public function submit_returns_false_on_db_error(): void
    {
        $c = $this->make();
        $c['db']->shouldReceive('beginTransaction')->once();
        $c['model']->shouldReceive('createReport')->andThrow(new \RuntimeException('DB error'));
        $c['db']->shouldReceive('inTransaction')->andReturn(true);
        $c['db']->shouldReceive('rollBack')->once();

        $this->assertFalse($c['svc']->submit(1, 'ad', 100, ModuleContext::SOCIAL_TASKS, 'test'));
    }

    /** @test */
    public function get_report_count_delegates_to_model(): void
    {
        $c = $this->make();
        $c['model']->shouldReceive('countReports')->with('ad', 100, m::type('string'))->once()->andReturn(7);

        $this->assertEquals(7, $c['svc']->getReportCount('ad', 100));
    }

}
