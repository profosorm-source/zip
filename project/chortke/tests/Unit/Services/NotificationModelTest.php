<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Models\Notification;

class NotificationModelTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeModel(?Database $db = null): Notification
    {
        return new Notification($db ?? m::mock(Database::class));
    }

    /** @test */
    public function exposes_type_and_priority_constants(): void
    {
        $this->assertSame('system', Notification::TYPE_SYSTEM);
        $this->assertSame('deposit', Notification::TYPE_DEPOSIT);
        $this->assertSame('normal', Notification::PRIORITY_NORMAL);
        $this->assertSame('urgent', Notification::PRIORITY_URGENT);
    }

    /** @test */
    public function create_returns_insert_id_when_successful(): void
    {
        $stmt = m::mock(\PDOStatement::class);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andReturn($stmt);
        $db->shouldReceive('lastInsertId')->once()->andReturn('77');

        $model = $this->makeModel($db);
        $result = $model->create([
            'user_id' => 5,
            'type' => Notification::TYPE_SYSTEM,
            'title' => 'Hello',
            'message' => 'World',
        ]);

        $this->assertSame(77, $result);
    }

    /** @test */
    public function create_returns_false_when_no_insert_id(): void
    {
        $stmt = m::mock(\PDOStatement::class);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andReturn($stmt);
        $db->shouldReceive('lastInsertId')->once()->andReturn('0');

        $model = $this->makeModel($db);
        $this->assertFalse($model->create(['user_id' => 5, 'title' => 'T', 'message' => 'M']));
    }

    /** @test */
    public function mark_as_read_returns_true_when_row_updated(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andReturn($stmt);

        $model = $this->makeModel($db);
        $this->assertTrue($model->markAsRead(10, 5));
    }

    /** @test */
    public function mark_as_read_returns_false_when_no_row_updated(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(0);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andReturn($stmt);

        $model = $this->makeModel($db);
        $this->assertFalse($model->markAsRead(10, 5));
    }
}
