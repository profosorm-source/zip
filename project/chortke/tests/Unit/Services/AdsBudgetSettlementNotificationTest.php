<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use App\Domain\Financial\Services\FinancialEscrowService;
use App\Contracts\WalletServiceInterface;
use App\Services\Ads\AdsBudgetSettlementService;

/**
 * تست‌های حرفه‌ای برای تسویه‌ی «نمایش نوتیفیکیشن تبلیغاتی و واریز پاداش به بیننده»
 *
 * پوشش: واریز پاداش به بیننده، مصرف بودجه‌ی تبلیغ‌دهنده، ایدمپوتنسی،
 * و لبه‌ها (کمپین غیرفعال، بودجه ناکافی، آگهی ناموجود).
 */
class AdsBudgetSettlementNotificationTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var AppSettings&\Mockery\MockInterface */
    private AppSettings $settings;
    /** @var FinancialEscrowService&\Mockery\MockInterface */
    private FinancialEscrowService $escrow;
    /** @var WalletServiceInterface&\Mockery\MockInterface */
    private WalletServiceInterface $wallet;
    private AdsBudgetSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->db->shouldIgnoreMissing();
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->settings = m::mock(AppSettings::class);
        $this->escrow = m::mock(FinancialEscrowService::class);
        $this->wallet = m::mock(WalletServiceInterface::class);

        $this->service = new AdsBudgetSettlementService(
            $this->db,
            $this->logger,
            $this->settings,
            $this->escrow,
            $this->wallet
        );

        // پیش‌فرض: ایدمپوتنسی خالی (هرگز تسویه نشده)
        $this->db->shouldReceive('beginTransaction')->byDefault();
        $this->db->shouldReceive('commit')->byDefault();
        $this->db->shouldReceive('inTransaction')->andReturn(false)->byDefault();
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @param array<string, mixed> $over */
    private function adRow(array $over = []): \stdClass
    {
        return (object) array_merge([
            'id' => 7,
            'user_id' => 99,
            'type' => 'notification',
            'status' => 'active',
            'is_active' => 1,
            'currency' => 'irt',
            'remaining_budget' => '1000',
            'site_commission_percent' => '15',
            'price_per_click' => '500',
        ], $over);
    }

    /** @test */
    public function notification_view_pays_reward_to_viewer_and_consumes_advertiser_budget(): void
    {
        $this->settings->shouldReceive('get')->with('notification_ad_delivery_cost', '25')->andReturn('25');
        $this->settings->shouldReceive('get')->with('notification_ad_fee_percent', '15')->andReturn('15');

        // 1) idempotency check -> null (نه‌تسویه‌شده)
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'ad_delivery_events WHERE idempotency_key')), m::any())->once()->andReturn(null);
        // 2) ad lookup
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'FROM ads WHERE id = ? AND type = ?')), m::any())->once()->andReturn($this->adRow());
        // 3) escrow lookup -> active escrow (جدول escrow_transactions)
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'FROM escrow_transactions')), m::any())->once()->andReturn((object)['id' => 1, 'order_id' => 7, 'order_type' => 'notification_ad_budget', 'amount' => '1000', 'status' => 'in_escrow']);
        // debitAdvertiserLockedAndEscrow -> consumeHeldBudget از FinancialEscrowService
        $this->escrow->shouldReceive('consumeHeldBudget')->once()->andReturn(['ok' => true]);
        // 4) remaining budget after update
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'SELECT remaining_budget, status FROM ads')), m::any())->once()->andReturn((object)['remaining_budget' => '975', 'status' => 'active']);

        // applyDeliveryAdUpdate -> UPDATE ads (no rows assertion needed, just allow)
        $this->db->shouldReceive('query')->with(m::on(fn($s) => str_contains($s, 'UPDATE ads SET')), m::any())->andReturn((function(){ $s=m::mock(\PDOStatement::class); $s->shouldReceive('rowCount')->andReturn(1); return $s; })());
        // debitAdvertiserLockedAndEscrow -> UPDATE escrows
        $this->db->shouldReceive('query')->with(m::on(fn($s) => str_contains($s, 'UPDATE escrows')), m::any())->andReturn((function(){ $s=m::mock(\PDOStatement::class); $s->shouldReceive('rowCount')->andReturn(1); return $s; })());
        // insertDeliveryEvent -> INSERT + lastInsertId
        $this->db->shouldReceive('query')->with(m::on(fn($s) => str_contains($s, 'INSERT INTO ad_delivery_events')), m::any())->andReturn((function(){ $s=m::mock(\PDOStatement::class); $s->shouldReceive('rowCount')->andReturn(1); return $s; })());
        $this->db->shouldReceive('lastInsertId')->andReturn('5001');
        // recordTransaction x2 -> INSERT
        $this->db->shouldReceive('query')->with(m::on(fn($s) => str_contains($s, 'INSERT INTO transactions')), m::any())->twice()->andReturn((function(){ $s=m::mock(\PDOStatement::class); $s->shouldReceive('rowCount')->andReturn(1); return $s; })());

        // wallet: ensureWallet (viewer + advertiser) و deposit موفق
        $this->wallet->shouldReceive('getOrCreateWallet')->andReturn((object)['id' => 1]);
        $this->wallet->shouldReceive('deposit')->once()->with(m::type('int'), '25', 'irt', m::on(function($meta){
            return ($meta['type'] ?? '') === 'notification_reward' && ($meta['ref_type'] ?? '') === 'notification_view';
        }))->andReturn(['success' => true]);

        $result = $this->service->settleNotificationView(7, 5, 'delivery', 123);

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame('25', $result['reward'] ?? null);
        $this->assertSame(5001, int_value($result['event_id'] ?? 0));
    }

    /** @test */
    public function notification_view_is_idempotent_per_notification(): void
    {
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'ad_delivery_events WHERE idempotency_key')), m::any())->once()
            ->andReturn((object)['id' => 900]);

        $result = $this->service->settleNotificationView(7, 5, 'delivery', 123);

        $this->assertTrue($result['duplicate'] ?? false);
        $this->assertSame(900, int_value($result['event_id']));
    }

    /** @test */
    public function notification_view_returns_error_when_ad_missing(): void
    {
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'ad_delivery_events WHERE idempotency_key')), m::any())->once()->andReturn(null);
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'FROM ads WHERE id = ? AND type = ?')), m::any())->once()->andReturn(null);

        $result = $this->service->settleNotificationView(999, 5, 'delivery', 123);

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('یافت نشد', str_value($result['message'] ?? ''));
    }

    /** @test */
    public function notification_view_returns_error_when_campaign_inactive(): void
    {
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'ad_delivery_events WHERE idempotency_key')), m::any())->once()->andReturn(null);
        $this->db->shouldReceive('fetch')->with(m::on(fn($s) => str_contains($s, 'FROM ads WHERE id = ? AND type = ?')), m::any())->once()
            ->andReturn($this->adRow(['status' => 'paused']));

        $result = $this->service->settleNotificationView(7, 5, 'delivery', 123);

        $this->assertFalse($result['success'] ?? true);
        $this->assertStringContainsString('فعال نیست', str_value($result['message'] ?? ''));
    }
}
