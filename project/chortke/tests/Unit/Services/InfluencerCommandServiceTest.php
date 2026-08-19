<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Contracts\WalletServiceInterface;
use App\Services\Settings\AppSettings;
use App\Domain\Financial\Services\FinancialEscrowService;
use Core\TransactionWrapper;
use App\Contracts\OutboxServiceInterface;
use App\Services\Influencer\InfluencerCommandService;
use Core\ValueObjects\Money;

class InfluencerCommandServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeService(
        ?Database $db = null,
        ?InfluencerModel $profileModel = null,
        ?StoryOrder $orderModel = null,
        ?AppSettings $appSettings = null,
        ?OutboxServiceInterface $outbox = null,
        ?LoggerInterface $logger = null
    ): InfluencerCommandService {
        if ($logger === null) {
            $logMock = m::mock(LoggerInterface::class);
            $logMock->shouldIgnoreMissing();
        } else {
            $logMock = $logger;
        }
        return new InfluencerCommandService(
            $db ?? m::mock(Database::class),
            $logMock,
            $profileModel ?? m::mock(InfluencerModel::class),
            $orderModel ?? m::mock(StoryOrder::class),
            m::mock(WalletServiceInterface::class),
            $appSettings ?? m::mock(AppSettings::class),
            m::mock(FinancialEscrowService::class),
            m::mock(TransactionWrapper::class),
            $outbox
        );
    }

    /**
     * db->fetchColumn مقدار null برمیگرداند => influencerEnabled به appSettings fallback میکند.
     */
    /** @param Database&\Mockery\MockInterface $db */
    private function mockInfluencerEnabled(Database $db): void
    {
        $db->shouldReceive('fetchColumn')->once()->andReturn(null);
    }

    /** @return AppSettings&\Mockery\MockInterface */
    private function newAppSettingsEnabled(): AppSettings
    {
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('influencer_enabled', 1)->andReturn(1);
        return $appSettings;
    }

    /** @test */
    public function register_influencer_rejects_when_feature_disabled(): void
    {
        $db = m::mock(Database::class);
        $db->shouldReceive('fetchColumn')->once()->andReturn(null);

        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('influencer_enabled', 1)->once()->andReturn(0);

        $service = $this->makeService($db, null, null, $appSettings);
        $result = $service->registerInfluencer(1, ['follower_count' => 5000]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('غیرفعال', $result['message']);
    }

    /** @test */
    public function register_influencer_rejects_duplicate_profile(): void
    {
        $db = m::mock(Database::class);
        $this->mockInfluencerEnabled($db);

        $profileModel = m::mock(InfluencerModel::class);
        $profileModel->shouldReceive('findByUserId')->once()->with(1)->andReturn((object)['id' => 9]);

        $appSettings = $this->newAppSettingsEnabled();

        $service = $this->makeService($db, $profileModel, null, $appSettings);
        $result = $service->registerInfluencer(1, ['follower_count' => 5000]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('قبلاً', $result['message']);
    }

    /** @test */
    public function register_influencer_rejects_low_followers(): void
    {
        $db = m::mock(Database::class);
        $this->mockInfluencerEnabled($db);

        $profileModel = m::mock(InfluencerModel::class);
        $profileModel->shouldReceive('findByUserId')->once()->andReturn(null);

        $appSettings = $this->newAppSettingsEnabled();
        $appSettings->shouldReceive('get')->with('influencer_min_followers', 1000)->once()->andReturn(1000);

        $service = $this->makeService($db, $profileModel, null, $appSettings);
        $result = $service->registerInfluencer(1, ['follower_count' => 10]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('فالوور', $result['message']);
    }

    /** @test */
    public function register_influencer_succeeds_and_records_outbox_event(): void
    {
        $db = m::mock(Database::class);
        $this->mockInfluencerEnabled($db);

        $profileModel = m::mock(InfluencerModel::class);
        $profileModel->shouldReceive('findByUserId')->once()->andReturn(null);

        $createdProfile = (object)[
            'id' => 42,
            'username' => 'ali_page',
            'status' => 'pending',
            'verification_code' => 'CK-ABCDEFGH',
        ];
        $profileModel->shouldReceive('createProfile')->once()->andReturn($createdProfile);

        $appSettings = $this->newAppSettingsEnabled();
        $appSettings->shouldReceive('get')->with('influencer_min_followers', 1000)->once()->andReturn(1000);
        $appSettings->shouldReceive('get')->with('currency_mode', 'irt')->once()->andReturn('irt');

        $outbox = m::mock(OutboxServiceInterface::class);
        $outbox->shouldReceive('record')
            ->once()
            ->withArgs(function ($aggregateType, $aggregateId, $eventType, $payload) {
                return $aggregateType === 'influencer'
                    && $aggregateId === 1
                    && $eventType === 'influencer.profile_registered'
                    && $payload['profile_id'] === 42;
            })
            ->andReturn(true);

        $service = $this->makeService($db, $profileModel, null, $appSettings, $outbox);
        $result = $service->registerInfluencer(1, ['follower_count' => 5000]);

        $this->assertTrue($result['success']);
        $profile = $result['profile'] ?? null;
        $this->assertInstanceOf(\stdClass::class, $profile);
        $this->assertSame(42, $profile->id);
        $this->assertArrayHasKey('verification_code', $result);
    }

    /** @test */
    public function register_influencer_degrades_gracefully_when_outbox_missing(): void
    {
        $db = m::mock(Database::class);
        $this->mockInfluencerEnabled($db);

        $profileModel = m::mock(InfluencerModel::class);
        $profileModel->shouldReceive('findByUserId')->once()->andReturn(null);
        $profileModel->shouldReceive('createProfile')->once()->andReturn((object)['id' => 1, 'username' => 'u']);

        $appSettings = $this->newAppSettingsEnabled();
        $appSettings->shouldReceive('get')->with('influencer_min_followers', 1000)->once()->andReturn(1000);
        $appSettings->shouldReceive('get')->with('currency_mode', 'irt')->once()->andReturn('irt');

        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $service = $this->makeService($db, $profileModel, null, $appSettings, null, $logger);
        $res = $service->registerInfluencer(1, ['follower_count' => 5000]);
        $this->assertTrue($res['success'] ?? false);
    }

    // ── رگرسیون C-01: بلوک‌های بی‌شرط که پیش‌تر همیشه «یافت نشد» برمی‌گرداندند ──

    /** @test */
    public function complete_order_rejects_when_order_missing(): void
    {
        $orderModel = m::mock(StoryOrder::class);
        $orderModel->shouldReceive('find')->once()->with(55)->andReturn(null);

        $service = $this->makeService(null, null, $orderModel);
        $result = $service->completeOrder(55, 1);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('یافت نشد', $result['message']);
    }

    /** @test */
    public function complete_order_reaches_logic_when_order_exists(): void
    {
        // پیش‌تر بلوک بی‌شرط همیشه «سفارش یافت نشد» برمی‌گرداند و تسویه از کار می‌افتاد
        $orderModel = m::mock(StoryOrder::class);
        $orderModel->shouldReceive('find')->once()->with(55)->andReturn((object)['id' => 55, 'status' => 'completed']);

        $service = $this->makeService(null, null, $orderModel);
        $result = $service->completeOrder(55, 1);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('قبلاً', $result['message']);
        $data = $result['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertTrue((bool)($data['already_completed'] ?? false));
    }

    /** @test */
    public function submit_verification_rejects_when_profile_missing(): void
    {
        $profileModel = m::mock(InfluencerModel::class);
        $profileModel->shouldReceive('findByUserId')->once()->with(9)->andReturn(null);

        $service = $this->makeService(null, $profileModel);
        $result = $service->submitVerificationPost(9, 'https://t.me/post/1');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('یافت نشد', $result['message']);
    }

    /** @test */
    public function submit_verification_reaches_logic_when_profile_exists(): void
    {
        // پروفایل موجود باید از گارد عبور کند و به بررسی وضعیت برسد
        $profileModel = m::mock(InfluencerModel::class);
        $profileModel->shouldReceive('findByUserId')->once()->with(9)->andReturn((object)['id' => 3, 'status' => 'approved']);

        $service = $this->makeService(null, $profileModel);
        $result = $service->submitVerificationPost(9, 'https://t.me/post/1');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('وضعیت پروفایل', $result['message']);
    }

    // ── رگرسیون float→decimal: مبالغ بازگشت باید از مسیر Money/BCMath دقیق باشند ──

    /**
     * بازگشت ۱۰۰٪: مبلغ‌‌ثبت‌شده در outbox باید دقیقاً خروجی Money باشد، نه round روی float.
     * @test
     */
    public function refund_order_full_records_money_exact_amount_not_float(): void
    {
        $order = (object)[
            'id' => 77,
            'customer_id' => 5,
            'influencer_user_id' => 9,
            'price' => '123456.79',
            'currency' => 'irt',
            'status' => 'completed',
        ];

        $orderModel = m::mock(StoryOrder::class);
        $orderModel->shouldReceive('find')->once()->with(77)->andReturn($order);
        $orderModel->shouldReceive('update')->once();

        $capturedAmount = null;
        $outbox = m::mock(OutboxServiceInterface::class);
        $outbox->shouldReceive('record')->andReturnUsing(
            function ($type, $id, $event, $payload) use (&$capturedAmount) {
                if ($capturedAmount === null && isset($payload['amount']) && ($payload['metadata']['type'] ?? null) === 'refund') {
                    $capturedAmount = $payload['amount'];
                }
                return true;
            }
        );

        $wrapper = m::mock(TransactionWrapper::class);
        $wrapper->shouldReceive('runWithRetry')->once()->andReturnUsing(fn($cb) => $cb());

        $service = new InfluencerCommandService(
            m::mock(Database::class),
            m::mock(LoggerInterface::class),
            m::mock(InfluencerModel::class),
            $orderModel,
            m::mock(WalletServiceInterface::class),
            m::mock(AppSettings::class),
            m::mock(FinancialEscrowService::class),
            $wrapper,
            $outbox
        );

        $result = $service->refundOrder(77, 1, 100.0, 'test');

        $expected = Money::fromString('123456.79', 'irt')->percentage('100')->getAmount();
        $this->assertTrue($result['success']);
        $this->assertIsString($capturedAmount);
        $this->assertSame($expected, $capturedAmount);
    }

    /**
     * بازگشت ۵۰٪: هم مبلغ بازگشت، هم سهم اینفلوئنسر از باقی‌مانده باید دقیقاً
     * با Money/BCMath محاسبه شوند (نه round روی float که خطای اعشار دارد).
     * @test
     */
    public function refund_order_partial_computes_remaining_share_with_money(): void
    {
        $order = (object)[
            'id' => 88,
            'customer_id' => 5,
            'influencer_user_id' => 9,
            'price' => '100000',
            'currency' => 'irt',
            'status' => 'completed',
        ];

        $orderModel = m::mock(StoryOrder::class);
        $orderModel->shouldReceive('find')->once()->with(88)->andReturn($order);
        $orderModel->shouldReceive('update')->once();

        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('influencer_fee_percent', 15)->andReturn(15);

        $captured = [];
        $outbox = m::mock(OutboxServiceInterface::class);
        $outbox->shouldReceive('record')->andReturnUsing(
            function ($type, $id, $event, $payload) use (&$captured) {
                $mtype = $payload['metadata']['type'] ?? null;
                if ($mtype === 'refund' || $mtype === 'partial_earning') {
                    $captured[$mtype] = $payload['amount'];
                }
                return true;
            }
        );

        $wrapper = m::mock(TransactionWrapper::class);
        $wrapper->shouldReceive('runWithRetry')->once()->andReturnUsing(fn($cb) => $cb());

        $service = new InfluencerCommandService(
            m::mock(Database::class),
            m::mock(LoggerInterface::class),
            m::mock(InfluencerModel::class),
            $orderModel,
            m::mock(WalletServiceInterface::class),
            $appSettings,
            m::mock(FinancialEscrowService::class),
            $wrapper,
            $outbox
        );

        $result = $service->refundOrder(88, 1, 50.0, 'test');

        $expectedRefund = Money::fromString('100000', 'irt')->percentage('50')->getAmount();
        $remaining = Money::fromString('100000', 'irt')
            ->subtract(Money::fromString($expectedRefund, 'irt'))
            ->getAmount();
        $remMoney = Money::fromString($remaining, 'irt');
        $expectedShare = $remMoney->subtract($remMoney->percentage('15'))->getAmount();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('refund', $captured);
        $this->assertArrayHasKey('partial_earning', $captured);
        $this->assertSame($expectedRefund, $captured['refund']);
        $this->assertSame($expectedShare, $captured['partial_earning']);
    }
}
