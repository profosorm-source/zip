<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Models\Dispute;

/**
 * تست‌های حرفه‌ای برای بستنِ یکپارچه‌ی پرونده‌ی اختلاف (resolveByRef)
 *
 * پوشش: بستن پرونده بر اساس ref_type+ref_id، جلوگیری از بستنِ دوباره (idempotent)،
 * و به‌روزرسانی فیلدهای داوری.
 */
class DisputeResolveByRefTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeModel(?Database $db = null): Dispute
    {
        return new Dispute($db ?? m::mock(Database::class));
    }

    /** @test */
    public function resolve_by_ref_marks_open_case_as_resolved_admin(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(
                m::on(fn($sql) => str_contains($sql, "status = 'resolved_admin'")
                    && str_contains($sql, "ref_type = ? AND ref_id = ?")
                    && str_contains($sql, "status != 'resolved_admin'")),
                [7, 'favor_seller', 7, 'vitrine_listing', 42]
            )
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->resolveByRef('vitrine_listing', 42, 7, 'favor_seller', 4));
    }

    /** @test */
    public function resolve_by_ref_is_idempotent_when_already_resolved(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(0);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andReturn($stmt);

        // پرونده از قبل resolved است → 0 ردیف → false
        $this->assertFalse($this->makeModel($db)->resolveByRef('vitrine_listing', 42, 7, 'favor_seller', 4));
    }

    /** @test */
    public function resolve_by_ref_uses_correct_ref_type_column_values(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'admin_decision = ?') && str_contains($sql, 'resolved_by = ?')), m::on(function ($params) {
                // params: [adminId, verdict, adminId, refType, refId]
                return $params[3] === 'vitrine_listing' && $params[4] === 42;
            }))
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->resolveByRef('vitrine_listing', 42, 7, 'favor_buyer'));
    }
}
