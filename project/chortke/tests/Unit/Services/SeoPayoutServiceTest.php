<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SeoPayoutService;
use App\Models\Ads;
use Core\Database;
use Mockery as m;

/**
 * تست حرفه‌ای SeoPayoutService — رفتار، لبه‌ها و قراردادها.
 *
 * پوشش:
 *   - calculatePayout: آگهی یافت‌نشده / غیرفعال / بودجه ناکافی / امتیاز زیر حداقل / موفقیت با پاداش پویا
 *   - refundToBudget: آگهی یافت‌نشده و بازفعال‌سازی آگهی exhausted با بودجه‌ی احیا شده
 *   - estimateReach: استفاده از min/max و بودجه باقیمانده
 *   - estimateBudget: مقادیر پیش‌فرض وقتی config کامل نیست
 */
class SeoPayoutServiceTest extends TestCase
{
    /** @var Ads&\Mockery\MockInterface */
    private Ads $ads;
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    private SeoPayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ads = m::mock(Ads::class);
        $this->db = m::mock(Database::class);
        $this->ads->shouldReceive('getDb')->andReturn($this->db);
        $this->service = new SeoPayoutService($this->ads);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @param array<string, mixed> $props */
    private function stubAd(array $props = []): \stdClass
    {
        return (object) array_merge([
            'id' => 1,
            'status' => 'active',
            'min_payout' => 1000,
            'max_payout' => 5000,
            'min_score' => 40,
            'remaining_budget' => 100000,
        ], $props);
    }

    /** @test */
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(SeoPayoutService::class, $this->service);
    }

    /** @test */
    public function calculatePayout_returns_zero_when_ad_not_found(): void
    {
        $this->ads->shouldReceive('find')->once()->with(999)->andReturn(null);

        $result = $this->service->calculatePayout(999, 80.0);

        $this->assertFalse($result['can_pay']);
        $this->assertSame(0, $result['payout']);
        $this->assertSame('آگهی یافت نشد', $result['message']);
    }

    /** @test */
    public function calculatePayout_rejects_inactive_ad(): void
    {
        $this->ads->shouldReceive('find')->once()->with(1)->andReturn($this->stubAd(['status' => 'paused']));

        $result = $this->service->calculatePayout(1, 80.0);

        $this->assertFalse($result['can_pay']);
        $this->assertSame('آگهی فعال نیست', $result['message']);
    }

    /** @test */
    public function calculatePayout_rejects_when_budget_is_insufficient(): void
    {
        // remaining_budget بسیار کم اما max/min بالا → پاداش محاسبه‌شده از بودجه بیشتر است
        $this->ads->shouldReceive('find')->once()->with(1)->andReturn($this->stubAd([
            'remaining_budget' => 50,
            'min_payout' => 1000,
            'max_payout' => 5000,
        ]));

        $result = $this->service->calculatePayout(1, 90.0);

        $this->assertFalse($result['can_pay']);
        $this->assertSame('بودجه آگهی کافی نیست', $result['message']);
    }

    /** @test */
    public function calculatePayout_rejects_score_below_minimum(): void
    {
        $this->ads->shouldReceive('find')->once()->with(1)->andReturn($this->stubAd([
            'min_score' => 40,
            'remaining_budget' => 100000,
        ]));

        $result = $this->service->calculatePayout(1, 30.0);

        $this->assertFalse($result['can_pay']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('حداقل امتیاز', $result['message']);
    }

    /** @test */
    public function calculatePayout_computes_dynamic_payout_on_success(): void
    {
        // min=1000, max=5000, score=100 → payout = 5000
        $this->ads->shouldReceive('find')->once()->with(1)->andReturn($this->stubAd([
            'min_payout' => 1000,
            'max_payout' => 5000,
            'min_score' => 0,
            'remaining_budget' => 100000,
        ]));

        $result = $this->service->calculatePayout(1, 100.0);

        $this->assertTrue($result['can_pay']);
        $this->assertSame('5000.0000', str_value($result['payout'] ?? ''));
    }

    /** @test */
    public function refundToBudget_returns_false_when_ad_not_found(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true);
        $stmt->shouldReceive('rowCount')->andReturn(0);
        $this->db->shouldReceive('prepare')->andReturn($stmt);

        $this->assertFalse($this->service->refundToBudget(5, 200.0));
    }

    /** @test */
    public function refundToBudget_reactivates_exhausted_ad_when_budget_restored(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->andReturn(true);
        $stmt->shouldReceive('rowCount')->andReturn(1);
        $this->db->shouldReceive('prepare')->andReturn($stmt);

        $this->assertTrue($this->service->refundToBudget(1, 500.0));
    }

    /** @test */
    public function estimateReach_uses_remaining_budget_and_payout_range(): void
    {
        $this->ads->shouldReceive('find')->once()->with(1)->andReturn($this->stubAd([
            'remaining_budget' => 10000,
            'min_payout' => 1000,
            'max_payout' => 5000,
        ]));

        $result = $this->service->estimateReach(1);

        $this->assertSame(10000, $result['remaining_budget']);
        // بدترین حالت: تقسیم بر max_payout
        $this->assertSame(2.0, $result['min_users']);
        // بهترین حالت: تقسیم بر min_payout
        $this->assertSame(10.0, $result['max_users']);
    }

    /** @test */
    public function estimateBudget_uses_defaults_when_config_is_incomplete(): void
    {
        $result = $this->service->estimateBudget([]);

        // defaults: min=1000, max=5000, expected=100, avg=70
        $this->assertArrayHasKey('expected_users', $result);
        $this->assertSame(100, $result['expected_users']);
        // avg_payout = 1000 + (70/100)*(5000-1000) = 3800
        $this->assertSame(3800.0, $result['avg_payout']);
    }
}
