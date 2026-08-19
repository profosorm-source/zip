<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\AntiFraud\EmailPhoneIntelligenceService;
use App\Services\Shared\IdempotencyService;
use Core\IdempotencyKey;
use Core\TransactionWrapper;
use App\Services\Wallet\WalletMutationService;
use Core\Database;
use Core\Encryption;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use Mockery as m;

/**
 * SecurityAndPerformanceBenchmarkTest — تست‌های عمیق غیرعملیاتی: امنیت، ضدتقلب، همزمانی و کارایی
 */
class SecurityAndPerformanceBenchmarkTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var AppSettings&\Mockery\MockInterface */
    private AppSettings $appSettings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->db->shouldReceive('fetch')->andReturn((object)['value' => '1', 'count' => 0])->byDefault();
        $this->db->shouldReceive('fetchAll')->andReturn([])->byDefault();
        $this->db->shouldReceive('query')->andReturn([])->byDefault();

        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->appSettings = m::mock(AppSettings::class);
        $this->appSettings->shouldIgnoreMissing();
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    // ─── ۱. تست‌های امنیتی و مهار نفوذ (Security & Penetration) ──────────

    /** @test */
    public function xss_payloads_are_strictly_escaped(): void
    {
        $payloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert(1)>',
            'javascript:alert("DOM")',
            '"><script>document.cookie</script>'
        ];

        foreach ($payloads as $p) {
            $escaped = e($p);
            $this->assertStringNotContainsString('<script>', $escaped);
            $this->assertStringNotContainsString('</script>', $escaped);
            $this->assertNotSame($p, $escaped);
        }
    }

    /** @test */
    public function aes_256_gcm_encryption_rejects_tampered_ciphertext(): void
    {
        $enc = new Encryption();
        $plaintext = 'SecretFinancialToken_12345';
        $context = 'wallet.transaction.key';

        $ciphertext = $enc->encrypt($plaintext, $context);
        $this->assertNotEmpty($ciphertext);

        // جعل یا دستکاری متن رمزنگاری‌شده
        $tampered = substr($ciphertext, 0, -4) . 'AABB';

        $this->expectException(\RuntimeException::class);
        $enc->decrypt($tampered);
    }

    // ─── ۲. تست‌های سناریوی ضدتقلب (Anti-Fraud & Risk Engine) ──────────

    /** @test */
    public function impossible_travel_detection_calculates_high_speed_anomalies(): void
    {
        $model = m::mock(\App\Models\IpAndDeviceModel::class);
        $policy = m::mock(\App\Services\AntiFraud\RiskPolicyService::class);
        $policy->shouldReceive('getInt')->with('fraud', 'geo.max_travel_speed_kmh', 900)->andReturn(900);

        // آخرین لاگین کاربر در تهران
        $model->shouldReceive('getLastLoginLocation')->with(10)->andReturn((object)[
            'ip_address' => '5.52.0.1',
            'country' => 'IR',
            'city' => 'Tehran',
            'latitude' => 35.6892,
            'longitude' => 51.3890,
            'login_at' => date('Y-m-d H:i:s', time() - 300) // ۵ دقیقه پیش
        ]);

        $geoService = new GeolocationIntelligenceService(
            $this->logger,
            $model,
            $policy,
            new \Core\PathResolver(dirname(__DIR__, 2))
        );

        // موقعیت جدید در فرانکفورت آلمان (فاصله ~۳,۵۰۰ کیلومتر در ۵ دقیقه = ۴۲,۰۰۰ کیلومتر بر ساعت)
        $currentLocation = [
            'country' => 'DE',
            'city' => 'Frankfurt',
            'latitude' => 50.1109,
            'longitude' => 8.6821,
        ];

        $model->shouldReceive('logImpossibleTravel')->once()->andReturn(true);

        $travelCheck = $geoService->detectImpossibleTravel(10, '185.12.0.1', $currentLocation);

        $this->assertTrue($travelCheck['is_impossible']);
        $this->assertSame(90, $travelCheck['risk_score']);
        $this->assertGreaterThan(900, $travelCheck['required_speed_kmh']);
    }

    // ─── ۳. تست همزمانی و قفل اتمیک (Concurrency & Double-Spend) ───────

    /** @test */
    public function idempotency_service_prevents_duplicate_financial_execution(): void
    {
        $idempotencyKeyModel = m::mock(IdempotencyKey::class);
        $transactionWrapper = m::mock(TransactionWrapper::class);

        $idempotencyKeyModel->shouldReceive('run')
            ->once()
            ->andReturn(['success' => true, 'transaction_id' => 'TX_EXISTING']);

        $service = new IdempotencyService(
            $idempotencyKeyModel,
            $transactionWrapper,
            $this->logger
        );

        $executed = false;
        $response = $service->execute('wallet_deposit', 12, ['amount' => 50000], function () use (&$executed) {
            $executed = true;
            return ['success' => true, 'transaction_id' => 'TX_NEW'];
        }, 'key_wallet_deposit_1001');

        $this->assertFalse($executed, 'Callback must NOT run when idempotency key is already completed');
        $this->assertTrue($response['success']);
        $this->assertSame('TX_EXISTING', $response['transaction_id']);
    }
}
