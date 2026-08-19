<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Models\Notification;

/**
 * تست‌های حرفه‌ای برای قابلیت «تبلیغ نوتیفیکیشنی + آمار engagement»
 *
 * پوشش: متدهای ثبت رویداد (shown/opened/closed/dismissed)، محاسبه‌ی مدت خواندن،
 * آمار per-ad و دسته‌بندیِ زود/متوسط/دیر بستن، و aggregation روزانه.
 */
class NotificationAdAnalyticsTest extends TestCase
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
    public function record_shown_coalesces_timestamp_and_sets_source(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'shown_at = COALESCE(shown_at, NOW())')), m::type('array'))
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->recordShown(10, 5, 'mobile'));
    }

    /** @test */
    public function record_opened_also_marks_as_read(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'opened_at = COALESCE(opened_at, NOW())')
                && str_contains($sql, 'is_read = 1')), m::type('array'))
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->recordOpened(10, 5));
    }

    /** @test */
    public function record_closed_uses_provided_duration_when_given(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'read_duration_sec = COALESCE(read_duration_sec, ?)')), [15, 10, 5])
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->recordClosed(10, 5, 15));
    }

    /** @test */
    public function record_closed_computes_duration_from_timestamps_when_not_given(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'TIMESTAMPDIFF')), [10, 5])
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->recordClosed(10, 5));
    }

    /** @test */
    public function record_closed_clamps_negative_duration_to_zero(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'read_duration_sec = COALESCE(read_duration_sec, ?)')), [0, 10, 5])
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->recordClosed(10, 5, -5));
    }

    /** @test */
    public function record_dismissed_sets_dismissed_and_closed(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('rowCount')->once()->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'dismissed_at = COALESCE(dismissed_at, NOW())')), [10, 5])
            ->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->recordDismissed(10, 5));
    }

    /** @test */
    public function get_ad_analytics_returns_engagement_breakdown_and_interpretive_rates(): void
    {
        $row = (object)[
            'sent' => 100, 'read_count' => 60, 'seen_not_read' => 20,
            'clicked' => 10, 'dismissed' => 30, 'shown' => 90,
            'with_duration' => 50, 'read_rate' => 60.0, 'ctr' => 10.0,
            'avg_duration_sec' => 20.0, 'avg_read_sec' => 20.0,
            'max_duration_sec' => 120, 'min_duration_sec' => 1,
            'avg_time_to_read_sec' => 5.0, 'unique_users' => 90,
            'fast_close' => 25, 'medium_close' => 20, 'deep_read' => 5,
        ];

        $db = m::mock(Database::class);
        $db->shouldReceive('fetch')->once()
            ->with(m::on(fn($sql) => str_contains($sql, 'ad_id = ?')), [42, 30])
            ->andReturn($row);

        $stats = $this->makeModel($db)->getAdAnalytics(42, 30);

        $this->assertNotNull($stats);
        $this->assertSame(100, int_value($stats['sent'] ?? 0));
        $this->assertSame(60, int_value($stats['read_count'] ?? 0));
        // نرخ‌های تفسیری
        $this->assertSame(25.0, $stats['fast_close_rate']);
        $this->assertSame(5.0, $stats['deep_read_rate']);
        $this->assertSame(30.0, $stats['dismissed_rate']);
    }

    /** @test */
    public function get_ad_analytics_returns_null_when_no_rows(): void
    {
        $db = m::mock(Database::class);
        $db->shouldReceive('fetch')->once()->andReturn(null);

        $this->assertNull($this->makeModel($db)->getAdAnalytics(999, 30));
    }

    /** @test */
    public function belongs_to_ad_checks_owner_and_ad(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->with([10, 42, 5]);
        $stmt->shouldReceive('fetch')->once()->andReturn((object)['1' => 1]);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $this->assertTrue($this->makeModel($db)->belongsToAd(10, 42, 5));
    }

    /** @test */
    public function aggregate_daily_analytics_upserts_rows(): void
    {
        $rows = [
            (object)['type' => 'marketing', 'channel' => 'in_app', 'sent' => 5,
                     'read_count' => 3, 'click_count' => 1, 'unique_users' => 4,
                     'ad_sent' => 2, 'ad_read' => 1, 'ad_click' => 1],
        ];

        $db = m::mock(Database::class);
        $db->shouldReceive('fetchAll')->once()->andReturn($rows);
        $db->shouldReceive('query')->once()->andReturn(
            (function () { $s = m::mock(\PDOStatement::class); $s->shouldReceive('rowCount')->andReturn(1); return $s; })()
        );

        $result = $this->makeModel($db)->aggregateDailyAnalytics();

        $this->assertSame(5, $result['sent']);
        $this->assertSame(1, $result['updated']);
    }
}
