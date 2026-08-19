<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\EventDispatcher;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\AuditTrail;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Core\TransactionWrapper;
use App\Services\Shared\ReferralService;

/**
 * تست‌های حرفه‌ای برای منطق بونوس و میلستون رفرال
 *
 * پوشش: رفتارِ «بدون معرف»، رفتارِ «تنظیمات پیکربندی‌نشده»، و
 * محاسبه‌ی میلستون‌های دست‌یافته.
 */
class ReferralBonusMilestoneTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var User&\Mockery\MockInterface */
    private User $userModel;
    /** @var AppSettings&\Mockery\MockInterface */
    private AppSettings $settings;
    /** @var ReferralCommission&\Mockery\MockInterface */
    private ReferralCommission $commissionModel;
    private ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->db->shouldIgnoreMissing();
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $wallet = m::mock(WalletServiceInterface::class);
        $wallet->shouldIgnoreMissing();
        $this->userModel = m::mock(User::class);
        $this->settings = m::mock(AppSettings::class);
        $this->commissionModel = m::mock(ReferralCommission::class);

        $this->service = new ReferralService(
            $this->db,
            $logger,
            $wallet,
            $this->commissionModel,
            $this->userModel,
            $this->settings,
            m::mock(TransactionWrapper::class)
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function check_and_award_bonus_skips_when_user_has_no_referrer(): void
    {
        $this->userModel->shouldReceive('findById')->once()->with(5)->andReturn((object)['id' => 5, 'referred_by' => null]);

        $result = $this->service->checkAndAwardBonus(5, 'content_approval', 100);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['awarded']);
        $this->assertStringContainsString('معرفی', str_value($result['message'] ?? ''));
    }

    /** @test */
    public function check_and_award_bonus_skips_when_amount_not_configured(): void
    {
        $this->userModel->shouldReceive('findById')->once()->with(5)->andReturn((object)['id' => 5, 'referred_by' => 7]);
        $this->settings->shouldReceive('get')->with('referral_content_approval_amount', '0')->andReturn('0');

        $result = $this->service->checkAndAwardBonus(5, 'content_approval', 100);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('پیکربندی', str_value($result['message'] ?? ''));
    }

    /** @test */
    public function check_and_award_bonus_returns_duplicate_when_already_awarded(): void
    {
        $this->userModel->shouldReceive('findById')->once()->with(5)->andReturn((object)['id' => 5, 'referred_by' => 7]);
        $this->settings->shouldReceive('get')->with('referral_content_approval_amount', '0')->andReturn('5000');
        $this->settings->shouldReceive('get')->with('referral_content_approval_currency', 'irt')->andReturn('irt');
        $this->commissionModel->shouldReceive('findByIdempotencyKey')->once()->with('referral_5_content_approval_100')
            ->andReturn((object)['commission_amount' => '5000']);

        $result = $this->service->checkAndAwardBonus(5, 'content_approval', 100);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertSame('5000', $result['commission']);
    }

    /** @test */
    public function award_signup_bonus_skips_when_no_referrer(): void
    {
        $this->userModel->shouldReceive('findById')->once()->with(5)->andReturn((object)['id' => 5, 'referred_by' => null]);

        $result = $this->service->awardSignupBonus(5, 'irt');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['awarded']);
    }

    /** @test */
    public function award_signup_bonus_skips_when_amount_zero(): void
    {
        $this->userModel->shouldReceive('findById')->once()->with(5)->andReturn((object)['id' => 5, 'referred_by' => 7]);
        $this->settings->shouldReceive('get')->with('referral_signup_bonus', '1000')->andReturn('0');

        $result = $this->service->awardSignupBonus(5, 'irt');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('پیکربندی', str_value($result['message'] ?? ''));
    }

    /** @test */
    public function award_signup_bonus_returns_duplicate_when_already_awarded(): void
    {
        $this->userModel->shouldReceive('findById')->once()->with(5)->andReturn((object)['id' => 5, 'referred_by' => 7]);
        $this->settings->shouldReceive('get')->with('referral_signup_bonus', '1000')->andReturn('1000');
        $this->commissionModel->shouldReceive('findByIdempotencyKey')->once()->with('referral_signup_7_5')
            ->andReturn((object)['commission_amount' => '1000']);

        $result = $this->service->awardSignupBonus(5, 'irt');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertSame('1000', $result['commission']);
    }

    /** @test */
    public function get_user_achieved_milestones_returns_only_reached_ones(): void
    {
        $this->db->shouldReceive('fetch')
            ->once()
            ->with('SELECT COUNT(*) AS count FROM users WHERE referred_by = ? AND deleted_at IS NULL', [9])
            ->andReturn((object)['count' => 3]);
        $this->db->shouldReceive('fetchAll')
            ->once()
            ->andReturn([
                (object)['id' => 1, 'threshold_value' => 1, 'milestone_type' => 'referrals', 'reward_amount' => '10000', 'currency' => 'irt', 'title' => 'اولین'],
                (object)['id' => 2, 'threshold_value' => 5, 'milestone_type' => 'referrals', 'reward_amount' => '25000', 'currency' => 'irt', 'title' => 'پنج'],
            ]);

        $achieved = $this->service->getUserAchievedMilestones(9);

        // با ۳ معرف، فقط threshold=1 رسیده است
        $this->assertCount(1, $achieved);
        $this->assertSame(1, (int)$achieved[0]->id);
    }
}
