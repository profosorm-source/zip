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
        ];
    }
}
