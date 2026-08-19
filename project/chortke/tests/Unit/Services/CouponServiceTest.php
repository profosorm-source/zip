<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Shared\CouponService;
use Mockery as m;

/**
 * تست‌های حرفه‌ای CouponService
 *
 * پوشش: رفتار validateAndCalculate (کوپن معتبر/نامعتبر/منقضی/ظرفیت/کاربر)،
 * محاسبه‌ی تخفیف درصدی با سقف max_discount و تخفیف ثابت،
 * و لبه‌ها (min_purchase، applicability).
 */
class CouponServiceTest extends TestCase
{
    /** @var \App\Models\Coupon&\Mockery\MockInterface */
    private \App\Models\Coupon $couponModel;
    /** @var \App\Models\CouponRedemption&\Mockery\MockInterface */
    private \App\Models\CouponRedemption $redemptionModel;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $database;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \Core\TransactionWrapper&\Mockery\MockInterface */
    private \Core\TransactionWrapper $transactionWrapper;
    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->couponModel = m::mock('App\Models\Coupon');
        $this->redemptionModel = m::mock('App\Models\CouponRedemption');
        $this->database = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->logger->shouldIgnoreMissing();
        $this->transactionWrapper = m::mock('Core\TransactionWrapper');
        $this->service = new CouponService(
            $this->transactionWrapper,
            $this->database,
            $this->logger,
            $this->couponModel,
            $this->redemptionModel
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @param array<string, mixed> $over */
    private function coupon(array $over = []): \stdClass
    {
        return (object) array_merge([
            'id' => 1,
            'code' => 'SAVE10',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => 100,
            'usage_count' => 5,
            'applicable_to' => 'all',
            'min_purchase' => null,
            'max_discount' => null,
            'active' => 1,
            'start_date' => null,
            'end_date' => null,
            'status' => 'active',
        ], $over);
    }

    /** @test */
    public function validates_and_calculates_percent_discount(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('SAVE10')->andReturn($this->coupon());
        $this->redemptionModel->shouldReceive('hasUserUsedCoupon')->once()->with(5, 1)->andReturn(false);

        $result = $this->service->validateAndCalculate('SAVE10', '1000', 'irt', 5);

        $this->assertTrue($result['valid']);
        $this->assertEquals(100.0, (float)$result['discount_amount']);
        $this->assertEquals(900.0, (float)$result['final_amount']);
        $this->assertSame(1, (int)$result['coupon_id']);
        $this->assertNotEmpty($result['validation_token']);
    }

    /** @test */
    public function percent_discount_is_capped_by_max_discount(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('MAX50')->andReturn($this->coupon([
            'code' => 'MAX50', 'value' => 20, 'max_discount' => 50,
        ]));
        $this->redemptionModel->shouldReceive('hasUserUsedCoupon')->once()->with(5, 1)->andReturn(false);

        $result = $this->service->validateAndCalculate('MAX50', '1000', 'irt', 5);

        // 20% از 1000 = 200 ولی سقف 50
        $this->assertTrue($result['valid']);
        $this->assertEquals(50.0, (float)$result['discount_amount']);
        $this->assertEquals(950.0, (float)$result['final_amount']);
    }

    /** @test */
    public function fixed_amount_discount_is_limited_to_order_amount(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('FIXED')->andReturn($this->coupon([
            'code' => 'FIXED', 'type' => 'fixed', 'value' => 500,
        ]));
        $this->redemptionModel->shouldReceive('hasUserUsedCoupon')->once()->with(5, 1)->andReturn(false);

        $result = $this->service->validateAndCalculate('FIXED', '300', 'irt', 5);

        $this->assertTrue($result['valid']);
        $this->assertEquals(300.0, (float)$result['discount_amount']);
        $this->assertEquals(0.0, (float)$result['final_amount']);
    }

    /** @test */
    public function invalid_coupon_returns_error(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('NOPE')->andReturn(null);

        $result = $this->service->validateAndCalculate('NOPE', 1000, 'irt', 5);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('معتبر نیست', $result['error']);
    }

    /** @test */
    public function expired_or_inactive_coupon_is_rejected(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('OLD')->andReturn($this->coupon([
            'code' => 'OLD', 'active' => 0,
        ]));

        $result = $this->service->validateAndCalculate('OLD', 1000, 'irt', 5);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('منقضی', $result['error']);
    }

    /** @test */
    public function exhausted_usage_limit_is_rejected(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('FULL')->andReturn($this->coupon([
            'code' => 'FULL', 'usage_limit' => 10, 'usage_count' => 10,
        ]));

        $result = $this->service->validateAndCalculate('FULL', 1000, 'irt', 5);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('منقضی', $result['error']);
    }

    /** @test */
    public function already_used_coupon_is_rejected(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('USED')->andReturn($this->coupon());
        $this->redemptionModel->shouldReceive('hasUserUsedCoupon')->once()->with(5, 1)->andReturn(true);

        $result = $this->service->validateAndCalculate('USED', 1000, 'irt', 5);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('استفاده', $result['error']);
    }

    /** @test */
    public function wrong_applicability_is_rejected(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('SPEC')->andReturn($this->coupon([
            'code' => 'SPEC', 'applicable_to' => 'deposit',
        ]));

        $result = $this->service->validateAndCalculate('SPEC', 1000, 'irt', 5, 'withdrawal');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('نوع عملیات', $result['error']);
    }

    /** @test */
    public function below_min_purchase_is_rejected(): void
    {
        $this->couponModel->shouldReceive('findByCode')->once()->with('MIN')->andReturn($this->coupon([
            'code' => 'MIN', 'min_purchase' => 500,
        ]));

        $result = $this->service->validateAndCalculate('MIN', 100, 'irt', 5);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('حداقل', $result['error']);
    }

    // ── رگرسیون C-01: بلوک‌های بی‌شرط update/delete/toggle که پیش‌تر همیشه false برمی‌گرداندند ──

    /** @test */
    public function update_persists_when_coupon_exists(): void
    {
        $this->couponModel->shouldReceive('find')->with(7)->andReturn($this->coupon(['id' => 7]));
        $this->couponModel->shouldReceive('update')->once()->with(7, m::type('array'))->andReturn(true);

        $this->assertTrue($this->service->update(7, ['active' => 0]));
    }

    /** @test */
    public function update_returns_false_when_coupon_missing(): void
    {
        $this->couponModel->shouldReceive('find')->with(7)->andReturn(null);
        $this->couponModel->shouldNotReceive('update');

        $this->assertFalse($this->service->update(7, ['active' => 0]));
    }

    /** @test */
    public function delete_removes_when_coupon_exists(): void
    {
        $this->couponModel->shouldReceive('find')->with(7)->andReturn($this->coupon(['id' => 7]));
        $this->couponModel->shouldReceive('delete')->once()->with(7)->andReturn(true);

        $this->assertTrue($this->service->delete(7));
    }

    /** @test */
    public function delete_returns_false_when_coupon_missing(): void
    {
        $this->couponModel->shouldReceive('find')->with(7)->andReturn(null);
        $this->couponModel->shouldNotReceive('delete');

        $this->assertFalse($this->service->delete(7));
    }

    /** @test */
    public function toggle_updates_status_when_coupon_exists(): void
    {
        // toggle باید از گارد عبور کند و به منطق update برسد (find دوبار صدا می‌شود)
        $this->couponModel->shouldReceive('find')->with(3)->andReturn($this->coupon(['id' => 3, 'active' => 1]));
        $this->couponModel->shouldReceive('update')->once()->with(3, m::type('array'))->andReturn(true);

        $this->assertTrue($this->service->toggle(3));
    }

    /** @test */
    public function toggle_returns_false_when_coupon_missing(): void
    {
        $this->couponModel->shouldReceive('find')->with(3)->andReturn(null);
        $this->couponModel->shouldNotReceive('update');

        $this->assertFalse($this->service->toggle(3));
    }
}
