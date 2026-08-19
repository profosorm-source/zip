<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Models\SentryModel;
use App\Services\AuditTrail;
use App\Services\Sentry\Audit\AdvancedAuditTrail;
use Core\Session;

class AdvancedAuditTrailTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeService(?SentryModel $model = null, ?AuditTrail $auditTrail = null, ?Session $session = null): AdvancedAuditTrail
    {
        return new AdvancedAuditTrail(
            $model ?? m::mock(SentryModel::class),
            m::mock(\Core\Logger::class),
            $auditTrail ?? m::mock(AuditTrail::class),
            $session ?? m::mock(Session::class),
            new \Core\PathResolver(dirname(__DIR__, 3)),
            []
        );
    }

    /** @test */
    public function compare_changes_returns_error_when_record_missing(): void
    {
        $model = m::mock(SentryModel::class);
        $model->shouldReceive('getAuditRecordById')->with(1)->andReturn(null);
        $model->shouldReceive('getAuditRecordById')->with(2)->andReturn(null);

        $service = $this->makeService($model);
        $result = $service->compareChanges(1, 2);

        $this->assertSame(['error' => 'Records not found'], $result);
    }

    /** @test */
    public function compare_changes_diffs_contexts_between_two_records(): void
    {
        $record1 = (object)[
            'id' => 1,
            'event' => 'user.update',
            'created_at' => '2026-01-01',
            'context' => json_encode(['name' => 'Ali', 'email' => 'a@x.com']),
        ];
        $record2 = (object)[
            'id' => 2,
            'event' => 'user.update',
            'created_at' => '2026-01-02',
            'context' => json_encode(['name' => 'Ali', 'email' => 'b@x.com']),
        ];

        $model = m::mock(SentryModel::class);
        $model->shouldReceive('getAuditRecordById')->with(1)->andReturn($record1);
        $model->shouldReceive('getAuditRecordById')->with(2)->andReturn($record2);

        $service = $this->makeService($model);
        $result = $service->compareChanges(1, 2);

        $record1 = $result['record1'] ?? null;
        $record2 = $result['record2'] ?? null;
        $this->assertIsArray($record1);
        $this->assertIsArray($record2);
        $this->assertSame(1, $record1['id']);
        $this->assertSame(2, $record2['id']);
        $this->assertIsArray($result['changes']);
        $this->assertArrayHasKey('email', $result['changes']);
    }

    /** @test */
    public function compare_changes_handles_invalid_context_json(): void
    {
        $record1 = (object)[
            'id' => 1,
            'event' => 'e',
            'created_at' => '2026-01-01',
            'context' => '{invalid-json',
        ];
        $record2 = (object)[
            'id' => 2,
            'event' => 'e',
            'created_at' => '2026-01-01',
            'context' => null,
        ];

        $model = m::mock(SentryModel::class);
        $model->shouldReceive('getAuditRecordById')->with(1)->andReturn($record1);
        $model->shouldReceive('getAuditRecordById')->with(2)->andReturn($record2);

        $service = $this->makeService($model);
        $result = $service->compareChanges(1, 2);

        $this->assertArrayHasKey('changes', $result);
        // context نامعتبر => arrayDiff روی دو آرایه خالی => تغییرات خالی
        $this->assertSame([], $result['changes']);
    }
}
