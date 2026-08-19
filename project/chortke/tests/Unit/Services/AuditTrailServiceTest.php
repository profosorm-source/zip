<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Models\AuditTrail as AuditTrailModel;
use App\Services\AuditTrail;

class AuditTrailServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeService(?AuditTrailModel $model = null): AuditTrail
    {
        return new AuditTrail(
            $model ?? m::mock(AuditTrailModel::class),
            new \Core\PathResolver(dirname(__DIR__, 3))
        );
    }

    /** @test */
    public function can_be_instantiated(): void
    {
        $this->assertInstanceOf(AuditTrail::class, $this->makeService());
    }

    /** @test */
    public function diff_returns_true_when_no_changes(): void
    {
        $service = $this->makeService();
        $this->assertTrue($service->diff('user.update', 1, ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]));
    }

    /** @test */
    public function diff_ignores_excluded_fields(): void
    {
        $service = $this->makeService();
        $this->assertTrue($service->diff('user.update', 1, ['password' => 'x'], ['password' => 'y']));
    }

    /** @test */
    public function get_event_types_returns_model_result(): void
    {
        $model = m::mock(AuditTrailModel::class);
        $model->shouldReceive('getEventTypes')->once()->andReturn(['login', 'logout']);

        $service = $this->makeService($model);
        $this->assertSame(['login', 'logout'], $service->getEventTypes());
    }

    /** @test */
    public function get_event_types_returns_empty_on_error(): void
    {
        $model = m::mock(AuditTrailModel::class);
        $model->shouldReceive('getEventTypes')->once()->andThrow(new \RuntimeException('down'));

        $service = $this->makeService($model);
        $this->assertSame([], $service->getEventTypes());
    }

    /** @test */
    public function get_stats_returns_model_result(): void
    {
        $model = m::mock(AuditTrailModel::class);
        $model->shouldReceive('getStats')->once()->with(null, null)->andReturn(['total' => 5]);

        $service = $this->makeService($model);
        $this->assertSame(['total' => 5], $service->getStats());
    }

    /** @test */
    public function create_entry_chains_hash_from_stdclass_previous_row(): void
    {
        $previousHash = str_repeat('b', 64);
        $db = m::mock(\Core\Database::class);
        $query = m::mock(\Core\QueryBuilder::class);

        $db->shouldReceive('inTransaction')->andReturn(false, true);
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('fetch')->once()->andReturn((object)['hash' => $previousHash]);
        $db->shouldReceive('table')->with('audit_trail')->andReturn($query);
        $query->shouldReceive('insert')->once()->with(m::on(function (array $data) use ($previousHash): bool {
            return ($data['prev_hash'] ?? null) === $previousHash
                && isset($data['hash'])
                && is_string($data['hash'])
                && strlen($data['hash']) === 64
                && $data['event'] === 'auth.login';
        }))->andReturn(15);
        $db->shouldReceive('commit')->once();

        $model = new AuditTrailModel($db);
        $this->assertSame(15, $model->createEntry([
            'event' => 'auth.login',
            'user_id' => 9,
        ]));
    }

    /** @test */
    public function create_entry_uses_genesis_hash_when_table_is_empty(): void
    {
        $db = m::mock(\Core\Database::class);
        $query = m::mock(\Core\QueryBuilder::class);

        $db->shouldReceive('inTransaction')->andReturn(false, true);
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('fetch')->once()->andReturn(null);
        $db->shouldReceive('table')->with('audit_trail')->andReturn($query);
        $query->shouldReceive('insert')->once()->with(m::on(function (array $data): bool {
            return ($data['prev_hash'] ?? null) === str_repeat('0', 64);
        }))->andReturn(1);
        $db->shouldReceive('commit')->once();

        $model = new AuditTrailModel($db);
        $this->assertSame(1, $model->createEntry(['event' => 'system.boot']));
    }
}
