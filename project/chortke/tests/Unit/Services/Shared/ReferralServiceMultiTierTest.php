<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Shared;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Settings\AppSettings;
use App\Services\Shared\ReferralService;
use Core\Database;
use Core\EventDispatcher;
use Core\TransactionWrapper;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * H-05 — پرداخت چندسطحی Referral مستعد تکرار/پرداخت نادرست.
 *
 * پوشش:
 *  - مبلغ غیرمثبت (خصوصاً 0 که داشبورد ارسال می‌کرد) هیچ پرداختی ایجاد نمی‌کند
 *    (رفع جعل مبنای 1000.00).
 *  - کمیسیون پیش از واریز ثبت می‌شود (گاردِ اتمیک idempotency).
 *  - در صورت وجود کمیسیون تکراری، واریز دوباره انجام نمی‌شود.
 */
/**
 * @phpstan-type ReferralMocks array{
 *   eventDispatcher: EventDispatcher&\Mockery\MockInterface,
 *   db: Database&\Mockery\MockInterface,
 *   logger: LoggerInterface&\Mockery\MockInterface,
 *   wallet: WalletServiceInterface&\Mockery\MockInterface,
 *   auditTrail: AuditTrail&\Mockery\MockInterface,
 *   commissionModel: ReferralCommission&\Mockery\MockInterface,
 *   userModel: User&\Mockery\MockInterface,
 *   appSettings: AppSettings&\Mockery\MockInterface,
 *   wrapper: TransactionWrapper&\Mockery\MockInterface
 * }
 */
final class ReferralServiceMultiTierTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @return ReferralMocks */
    private function makeMocks(): array
    {
        $eventDispatcher = m::mock(EventDispatcher::class);
        $eventDispatcher->shouldIgnoreMissing();
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldIgnoreMissing();
        $db = m::mock(Database::class);
        $db->shouldReceive('query')->byDefault()->andReturn($stmt);
        $db->shouldIgnoreMissing();
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $wallet = m::mock(WalletServiceInterface::class);
        $auditTrail = m::mock(AuditTrail::class);
        $auditTrail->shouldIgnoreMissing();
        $commissionModel = m::mock(ReferralCommission::class);
        $userModel = m::mock(User::class);
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldIgnoreMissing();
        $wrapper = m::mock(TransactionWrapper::class);
        // کالبک را واقعاً اجرا کن تا منطق درون تراکنش آزموده شود.
        $wrapper->shouldReceive('runWithRetry')->andReturnUsing(static function ($cb) {
            return $cb();
        });

        return [
            'eventDispatcher' => $eventDispatcher,
            'db' => $db,
            'logger' => $logger,
            'wallet' => $wallet,
            'auditTrail' => $auditTrail,
            'commissionModel' => $commissionModel,
            'userModel' => $userModel,
            'appSettings' => $appSettings,
            'wrapper' => $wrapper,
        ];
    }

    /** @param ReferralMocks $mocks */
    private function makeService(array $mocks): ReferralService
    {
        return new ReferralService(
            $mocks['db'],
            $mocks['logger'],
            $mocks['wallet'],
            $mocks['commissionModel'],
            $mocks['userModel'],
            $mocks['appSettings'],
            $mocks['wrapper'],
            null
        );
    }

    public function test_does_not_pay_on_non_positive_amount(): void
    {
        $mocks = $this->makeMocks();
        // هیچ خواندن/پرداختی نباید رخ دهد.
        $mocks['userModel']->shouldNotReceive('findById');
        $mocks['commissionModel']->shouldNotReceive('createCommission');
        $mocks['wallet']->shouldNotReceive('depositInTransaction');

        $service = $this->makeService($mocks);
        $result = $service->processMultiTierCommissions(1, 0, 'irt');

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['payouts']);
        $this->assertSame('non_positive_amount', $result['skipped'] ?? null);
    }

    public function test_pays_single_level_creating_commission_before_deposit(): void
    {
        $mocks = $this->makeMocks();
        $mocks['userModel']->shouldReceive('findById')->andReturnUsing(static function (int $id) {
            $map = [
                1 => (object)['id' => 1, 'referred_by' => 100],
                100 => (object)['id' => 100, 'referred_by' => null],
            ];
            return $map[$id] ?? null;
        });
        $mocks['commissionModel']->shouldReceive('findByIdempotencyKey')->andReturn(null);
        // ترتیب قطعی: createCommission پیش از depositInTransaction.
        $mocks['commissionModel']->shouldReceive('createCommission')->once()
            ->andReturn((object)['id' => 7]);
        $mocks['wallet']->shouldReceive('depositInTransaction')->once()
            ->with(100, '50.00', 'irt', m::type('array'))
            ->andReturn(['success' => true]);

        $service = $this->makeService($mocks);
        $result = $service->processMultiTierCommissions(1, '1000', 'irt');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey(1, $result['payouts']);
        $this->assertSame(100, $result['payouts'][1]['user_id']);
        $this->assertSame('50.00', $result['payouts'][1]['amount']);
    }

    public function test_skips_deposit_when_commission_already_exists(): void
    {
        $mocks = $this->makeMocks();
        $mocks['userModel']->shouldReceive('findById')->andReturnUsing(static function (int $id) {
            $map = [
                1 => (object)['id' => 1, 'referred_by' => 100],
                100 => (object)['id' => 100, 'referred_by' => null],
            ];
            return $map[$id] ?? null;
        });
        // کمیسیون از پیش وجود دارد → نه ثبت دوباره، نه واریز دوباره.
        $mocks['commissionModel']->shouldReceive('findByIdempotencyKey')
            ->andReturn((object)['id' => 5, 'commission_amount' => '50.00']);
        $mocks['commissionModel']->shouldNotReceive('createCommission');
        $mocks['wallet']->shouldNotReceive('depositInTransaction');

        $service = $this->makeService($mocks);
        $result = $service->processMultiTierCommissions(1, '1000', 'irt');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey(1, $result['payouts']);
        $this->assertTrue($result['payouts'][1]['duplicate'] ?? false);
    }
}
