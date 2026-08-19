<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Payment\NextPayGateway;
use App\Services\Payment\PaymentCommandService;
use Mockery as m;

/**
 * تست‌های رگرسیون برای سه‌گانهٔ درگاه پرداخت (H-06 / H-07 / H-08).
 *
 * - H-07: verifyCallback نباید callbackِ معتبر از درگاه‌های بدونِ امضا را رد کند،
 *   اما وقتی امضا «مورد انتظار» است باید آن را الزام کند.
 * - H-06/H-08: URL بازگشت باید nonce را با encoding استاندارد حمل کند.
 */
class PaymentCallbackSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeNextPayGateway(): NextPayGateway
    {
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $logger->shouldIgnoreMissing();

        $circuitBreaker = m::mock('Core\\CircuitBreaker');
        $circuitBreaker->shouldIgnoreMissing();

        $appSettings = m::mock('App\\Services\\Settings\\AppSettings');
        $appSettings->shouldIgnoreMissing();

        // درگاه پیکربندی‌نشده => getActiveGateway مقدار null برمی‌گرداند
        // تا هیچ callback_secretی در دسترس نباشد (حالتِ درگاهِ بدونِ امضا).
        $paymentGatewayModel = m::mock('App\\Models\\PaymentGateway');
        $paymentGatewayModel->shouldReceive('getActiveGateway')
            ->with('nextpay')
            ->andReturn(null);

        return new NextPayGateway($paymentGatewayModel, $circuitBreaker, $logger);
    }

    /**
     * H-07: درگاهی که callback را امضا نمی‌کند و کلیدی هم پیکربندی نشده،
     * نباید callback را به‌دلیل نبودِ امضا رد کند (اعتبارسنجی به nonce + verifyPayment واگذار می‌شود).
     *
     * @test
     */
    public function unsigned_gateway_callback_is_not_rejected_for_missing_signature(): void
    {
        $gateway = $this->makeNextPayGateway();

        $result = $gateway->verifyCallback([
            'authority' => 'nextpay_trans_123',
            'Status'    => 'success',
            'amount'    => '50000',
        ]);

        $this->assertTrue(
            $result,
            'callback معتبر از درگاهِ بدونِ امضا نباید رد شود (H-07)'
        );
    }

    /**
     * H-07: وقتی payload ادعای امضا دارد (signature) اما کلیدی برای وارسی نیست،
     * نباید بی‌سروصدا پذیرفته شود؛ بررسی امضا «مورد انتظار» می‌شود و نبودِ کلید منجر به خطا می‌شود.
     *
     * @test
     */
    public function claimed_signature_without_secret_is_enforced_not_bypassed(): void
    {
        $gateway = $this->makeNextPayGateway();

        $this->expectException(\RuntimeException::class);

        $gateway->verifyCallback([
            'authority' => 'nextpay_trans_123',
            'Status'    => 'success',
            'amount'    => '50000',
            'signature' => 'deadbeef',
        ]);
    }

    /**
     * H-06/H-08: URL بازگشت باید nonce را به‌صورت پارامتر کوئریِ انکدشده حمل کند.
     *
     * @test
     */
    public function callback_url_carries_nonce_as_encoded_query_param(): void
    {
        if (!function_exists('url')) {
            $this->fail('helper تابع url() در این محیطِ تست بارگذاری نشده است.');
        }

        $ref = new \ReflectionClass(PaymentCommandService::class);
        /** @var PaymentCommandService $service */
        $service = $ref->newInstanceWithoutConstructor();

        $method = $ref->getMethod('buildCallbackUrl');
        $method->setAccessible(true);

        $nonce = bin2hex(random_bytes(16));
        $url = str_value($method->invoke($service, 'nextpay', $nonce));

        $this->assertStringContainsString('/payment/callback/nextpay', $url);
        $this->assertStringContainsString('nonce=' . $nonce, $url);
        $this->assertStringContainsString('?', $url, 'URL باید دارای query string باشد');

        // تطابق دقیق مقدار nonce پس از parse (اطمینان از round-trip صحیح).
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $parsed);
        $this->assertSame($nonce, $parsed['nonce'] ?? null);
    }
}
