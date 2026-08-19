<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\UserVacation;
use Core\Database;
use Mockery as m;

class UserVacationTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    private UserVacation $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->model = new UserVacation($this->db);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_calculate_cumulative_vacation_days(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with([42, 90])->andReturn(true);
        $stmt->shouldReceive('fetchColumn')->once()->andReturn(14);

        $this->db->shouldReceive('prepare')->once()->andReturn($stmt);

        $this->assertEquals(14, $this->model->getCumulativeVacationDays(42, 90));
    }

    /** @test */
    public function it_blocks_vacation_registration_if_limit_exceeded(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with([42, 90])->andReturn(true);
        $stmt->shouldReceive('fetchColumn')->once()->andReturn(25); // 25 days already taken

        $this->db->shouldReceive('prepare')->andReturn($stmt);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مجموع روزهای مرخصی شما در ۹۰ روز گذشته نمی‌تواند از ۳۰ روز بیشتر شود');

        // Trying to register 7 more days (25 + 7 = 32 > 30)
        $this->model->registerVacation(42, 7, 100.0);
    }

    /** @test */
    public function it_allows_vacation_registration_if_within_limit(): void
    {
        $stmtVal = m::mock(\PDOStatement::class);
        $stmtVal->shouldReceive('execute')->once()->with([42, 90])->andReturn(true);
        $stmtVal->shouldReceive('fetchColumn')->once()->andReturn(10); // 10 days taken

        $stmtIns = m::mock(\PDOStatement::class);
        $stmtIns->shouldReceive('execute')->once()->andReturn(true);

        $stmtFind = m::mock(\PDOStatement::class);
        $stmtFind->shouldReceive('execute')->once()->with([123])->andReturn(true);
        $stmtFind->shouldReceive('fetch')->once()->with(\PDO::FETCH_OBJ)->andReturn((object)['id' => 123]);

        $this->db->shouldReceive('prepare')
            ->andReturnUsing(function ($sql) use ($stmtVal, $stmtIns, $stmtFind) {
                if (stripos($sql, 'SUM(duration_days)') !== false) {
                    return $stmtVal;
                }
                if (stripos($sql, 'LIMIT 1') !== false) {
                    return $stmtFind;
                }
                return $stmtIns;
            });

        $this->db->shouldReceive('lastInsertId')->once()->andReturn('123');

        $result = $this->model->registerVacation(42, 5, 50.0); // 10 + 5 = 15 <= 30
        $this->assertNotNull($result);
        $this->assertEquals(123, $result->id);
    }
}
