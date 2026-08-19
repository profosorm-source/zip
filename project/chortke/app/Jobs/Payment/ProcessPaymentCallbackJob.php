<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Services\Payment\PaymentCommandService;
use App\Contracts\LoggerInterface;

class ProcessPaymentCallbackJob
{
    public function __construct(
        private PaymentCommandService $paymentService,
        private LoggerInterface $logger
    ) {}

/**
 * @param array<string, mixed> $callbackData
 * @return array<string, mixed>
 */
public function handle(string $gatewayName, array $callbackData, ?int $sessionUserId = null, string $clientIp = '', string $userAgent = ''): array
    {
        try {
            return $this->paymentService->callback($gatewayName, $callbackData, $sessionUserId, $clientIp, $userAgent);
        } catch (\Throwable $e) {
            $this->logger->error('payment.callback.job.failed', [
                'gateway' => $gatewayName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در پردازش بازگشت پرداخت'];
        }
    }
}

