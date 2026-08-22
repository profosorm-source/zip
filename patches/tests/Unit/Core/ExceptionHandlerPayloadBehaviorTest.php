<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Contracts\ErrorContract;
use App\Exceptions\PaymentGatewayException;
use Core\ExceptionHandler;
use Core\Exceptions\InsufficientBalanceException;
use Core\Exceptions\UnauthorizedException;
use Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ExceptionHandlerPayloadBehaviorTest extends TestCase
{
    /**
     * @dataProvider exceptionContractProvider
     * @param array<string, mixed> $expectedSubset
     */
    public function test_exception_is_normalized_to_the_public_error_contract(
        Throwable $exception,
        array $expectedSubset
    ): void {
        $payload = ExceptionHandler::getJsonPayloadForException($exception);

        foreach ($expectedSubset as $key => $expected) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertSame($expected, $payload[$key]);
        }

        $this->assertSame(false, $payload['success']);
        $this->assertIsInt($payload['code']);
        $this->assertIsString($payload['error_code']);
        $this->assertIsString($payload['message']);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertSame(1, preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $payload['meta']['trace_id']));
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['meta']['timestamp']));
    }

    /**
     * @dataProvider invalidContractProvider
     */
    public function test_error_contract_rejects_invalid_internal_contracts(
        int $statusCode,
        string $errorCode,
        string $message
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new ErrorContract($statusCode, $errorCode, $message);
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public function invalidContractProvider(): array
    {
        return [
            'success status is not an error response' => [200, 'INTERNAL_ERROR', 'failure'],
            'status above HTTP range' => [600, 'INTERNAL_ERROR', 'failure'],
            'empty error code' => [500, '', 'failure'],
            'lowercase error code' => [500, 'internal_error', 'failure'],
            'punctuated error code' => [500, 'INTERNAL-ERROR', 'failure'],
            'empty message' => [500, 'INTERNAL_ERROR', '   '],
        ];
    }

    /** @return array<string, array{0: Throwable, 1: array<string, mixed>}> */
    public function exceptionContractProvider(): array
    {
        return [
            'validation' => [
                new ValidationException(['email' => ['required']]),
                ['code' => 422, 'error_code' => 'VALIDATION_ERROR', 'errors' => ['email' => ['required']]],
            ],
            'unauthorized' => [
                new UnauthorizedException('login required'),
                ['code' => 401, 'error_code' => 'UNAUTHORIZED', 'message' => 'login required'],
            ],
            'insufficient balance subtype' => [
                new InsufficientBalanceException('balance too low'),
                ['code' => 400, 'error_code' => 'INSUFFICIENT_FUNDS', 'message' => 'balance too low'],
            ],
            'domain exception' => [
                new \DomainException('invalid operation'),
                ['code' => 400, 'error_code' => 'DOMAIN_LOGIC_ERROR', 'message' => 'invalid operation'],
            ],
            'invalid provider status cannot escape error range' => [
                new PaymentGatewayException('provider failed', 200),
                ['code' => 500, 'error_code' => 'PAYMENT_GATEWAY_ERROR', 'message' => 'provider failed'],
            ],

            // ── REGRESSION: business rule violations must never be reported as 5xx ──
            // یک نقض قانون کسب‌وکار خطای سمت کاربر است، نه خرابی سرور. پیش‌تر همهٔ
            // BusinessExceptionها به 500/INTERNAL_ERROR نگاشت می‌شدند و پیام‌های
            // کاربرپسند فارسی به‌صورت «خطای سرور» به کاربر نشان داده می‌شد.
            'business rule violation defaults to 422 not 500' => [
                new \App\Exceptions\BusinessException('مبلغ برداشت نامعتبر است'),
                [
                    'code'       => 422,
                    'error_code' => 'BUSINESS_RULE_ERROR',
                    'message'    => 'مبلغ برداشت نامعتبر است',
                ],
            ],
            'business rule violation honours an explicit http code' => [
                new \App\Exceptions\BusinessException('شما یک درخواست برداشت در انتظار دارید', 409),
                [
                    'code'       => 409,
                    'error_code' => 'BUSINESS_RULE_ERROR',
                    'message'    => 'شما یک درخواست برداشت در انتظار دارید',
                ],
            ],
            'business rule violation keeps field level errors' => [
                new \App\Exceptions\BusinessException('ورودی نامعتبر', ['amount' => 'too_low']),
                [
                    'code'       => 422,
                    'error_code' => 'BUSINESS_RULE_ERROR',
                    'errors'     => ['amount' => 'too_low'],
                ],
            ],
            'business exception with a nonsense code falls back to 422' => [
                new \App\Exceptions\BusinessException('کد بی‌معنا', 7),
                ['code' => 422, 'error_code' => 'BUSINESS_RULE_ERROR'],
            ],
            // زیرکلاس‌های BusinessException نباید توسط شاخهٔ جدید بلعیده شوند؛
            // نگاشت اختصاصی خودشان باید حفظ شود (ترتیب elseif اهمیت دارد).
            'invalid state subtype still maps to conflict' => [
                new \Core\Exceptions\InvalidStateException('وضعیت درخواست نامعتبر است'),
                ['code' => 409, 'error_code' => 'CONFLICT'],
            ],
        ];
    }

    /**
     * یک خرابی واقعی سرور باید همچنان 500 بماند — این تست تضمین می‌کند اصلاحِ
     * بالا بیش از حد گسترده نبوده و خطاهای ناشناخته را به 4xx تنزل نداده است.
     */
    public function test_genuine_server_faults_are_still_reported_as_500(): void
    {
        foreach ([new \RuntimeException('null deref'), new \TypeError('bad type')] as $fault) {
            $payload = ExceptionHandler::getJsonPayloadForException($fault);

            $this->assertSame(500, $payload['code']);
            $this->assertSame('INTERNAL_ERROR', $payload['error_code']);
        }
    }

    /**
     * تست انتها-به-انتها از میان Pipeline واقعی و GlobalExceptionMiddleware:
     * وقتی کنترلر یک BusinessException پرتاب می‌کند، پاسخ نهایی HTTP باید 422
     * باشد (نه 500) و پیام کاربرپسند باید در بدنهٔ JSON حفظ شود.
     */
    public function test_business_exception_from_a_controller_yields_422_through_the_real_pipeline(): void
    {
        $response = $this->runThroughGlobalPipeline(
            static function (): void {
                throw new \App\Exceptions\BusinessException('سودی برای برداشت وجود ندارد');
            }
        );

        $this->assertSame(422, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertFalse($body['success']);
        $this->assertSame('سودی برای برداشت وجود ندارد', $body['message']);
        $this->assertSame('BUSINESS_RULE_ERROR', $body['error_code']);
    }

    public function test_unexpected_controller_fault_still_yields_500_through_the_real_pipeline(): void
    {
        $response = $this->runThroughGlobalPipeline(
            static function (): void {
                throw new \RuntimeException('unexpected null dereference');
            }
        );

        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * اجرای GlobalExceptionMiddleware از طریق Pipeline واقعی، دقیقاً همان‌گونه
     * که Router در زمان اجرا آن را می‌سازد.
     */
    /**
     * BUG-CATCHER (CORE-021): بدنه‌ی بیش از حد بزرگ باید 413 برگرداند نه 500.
     * PayloadTooLargeException در core/Request.php پرتاب می‌شود، هرگز جایی
     * catch نمی‌شود و کد 413 را با خود حمل می‌کند؛ اما هیچ شاخه‌ای در
     * ExceptionHandler برای آن وجود نداشت و به‌صورت 500/INTERNAL_ERROR
     * به کاربر نمایش داده می‌شد (اثبات‌شده با درخواست واقعی 13 مگابایتی).
     */
    public function test_payload_too_large_is_reported_as_413_not_500(): void
    {
        $payload = \Core\ExceptionHandler::getJsonPayloadForException(
            new \Core\Exceptions\PayloadTooLargeException()
        );

        $this->assertSame(413, $payload['code']);
        $this->assertSame('PAYLOAD_TOO_LARGE', $payload['error_code']);
        $this->assertFalse($payload['success']);
    }

    /**
     * BUG-CATCHER: مدار قطع‌شده یعنی سرویس موقتاً در دسترس نیست (503)،
     * که کد خودِ exception نیز هست، ولی دور ریخته می‌شد و 500 برمی‌گشت.
     */
    public function test_circuit_breaker_open_is_reported_as_503(): void
    {
        $payload = \Core\ExceptionHandler::getJsonPayloadForException(
            new \Core\Exceptions\CircuitBreakerOpenException('zarinpal')
        );

        $this->assertSame(503, $payload['code']);
        $this->assertSame('SERVICE_UNAVAILABLE', $payload['error_code']);
    }

    /**
     * BUG-CATCHER: RateLimitedFailure کد 429 خود را داشت اما 500 برمی‌گشت.
     */
    public function test_provider_rate_limited_failure_is_reported_as_429(): void
    {
        $payload = \Core\ExceptionHandler::getJsonPayloadForException(
            new \Core\Exceptions\RateLimitedFailure('Provider rate limit exceeded', 30)
        );

        $this->assertSame(429, $payload['code']);
        $this->assertSame('RATE_LIMITED', $payload['error_code']);
    }

    /**
     * BUG-CATCHER: خرابی سرویس بیرونی یک خطای دروازه (502) است، نه خطای
     * داخلی ما. PermanentFailure در 12 نقطه از آداپتورها پرتاب می‌شود.
     */
    public function test_external_service_failures_are_reported_as_502(): void
    {
        foreach ([
            new \Core\Exceptions\ExternalServiceException('provider exploded'),
            new \Core\Exceptions\PermanentFailure('invalid credentials'),
        ] as $exception) {
            $payload = \Core\ExceptionHandler::getJsonPayloadForException($exception);

            $this->assertSame(502, $payload['code'], get_class($exception));
            $this->assertSame('EXTERNAL_SERVICE_ERROR', $payload['error_code'], get_class($exception));
        }
    }

    /**
     * BUG-CATCHER: خطاهای گذرا/عدم دسترس‌بودن provider باید 503 باشند تا
     * کلاینت بداند تلاش مجدد منطقی است.
     */
    public function test_transient_and_unavailable_failures_are_reported_as_503(): void
    {
        foreach ([
            new \Core\Exceptions\TransientException('timeout'),
            new \Core\Exceptions\ProviderUnavailable('provider down'),
        ] as $exception) {
            $payload = \Core\ExceptionHandler::getJsonPayloadForException($exception);

            $this->assertSame(503, $payload['code'], get_class($exception));
            $this->assertSame('SERVICE_UNAVAILABLE', $payload['error_code'], get_class($exception));
        }
    }

    /**
     * PRESERVER: InfrastructureException پایه (بدون طبقه‌بندی دقیق‌تر) باید
     * همچنان 500 بماند — این تغییر نباید کل درخت را به 5xx نرم تبدیل کند.
     */
    public function test_generic_infrastructure_failure_remains_500(): void
    {
        $payload = \Core\ExceptionHandler::getJsonPayloadForException(
            new \Core\Exceptions\InfrastructureException('disk on fire')
        );

        $this->assertSame(500, $payload['code']);
        $this->assertSame('INTERNAL_ERROR', $payload['error_code']);
    }

    /**
     * PRESERVER: پیام خطاهای زیرساختی نباید جزئیات داخلی را لو بدهد وقتی
     * debug خاموش است (نشت اطلاعات).
     */
    public function test_external_failure_message_is_not_leaked_when_debug_is_off(): void
    {
        $previous = config('app.debug', false);
        config_set('app.debug', false);

        try {
            $payload = \Core\ExceptionHandler::getJsonPayloadForException(
                new \Core\Exceptions\PermanentFailure('SECRET api key sk_live_12345 rejected')
            );

            $this->assertSame(502, $payload['code']);
            $this->assertStringNotContainsString('sk_live_12345', (string) $payload['message']);
        } finally {
            config_set('app.debug', $previous);
        }
    }

    private function runThroughGlobalPipeline(callable $controller): \Core\Response
    {
        $request = \Mockery::mock(\Core\Request::class);
        $request->shouldIgnoreMissing();
        $request->shouldReceive('uri')->andReturn('/api/v1/investment/withdraw');
        $request->shouldReceive('method')->andReturn('POST');
        $request->shouldReceive('isAjax')->andReturn(true);
        $request->shouldReceive('header')->andReturn('application/json');

        $response = (new \Core\Pipeline(\Core\Container::getInstance()))
            ->send($request)
            ->through([\App\Middleware\GlobalExceptionMiddleware::class])
            ->then(static function ($req) use ($controller) {
                $controller($req);
            });

        $this->assertInstanceOf(\Core\Response::class, $response);

        return $response;
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
