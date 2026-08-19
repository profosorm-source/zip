<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Services\CryptoDeposit\CryptoDepositService;

/**
 * Job رد واریز کریپتو.
 *
 * رفع باگ بازگشت بی‌نهایت نسخه‌ی قبلی؛ اکنون به CryptoDepositService واگذار می‌کند.
 */
class RejectCryptoDepositJob
{
    public function __construct(
        private CryptoDepositService $service
    ) {}

    /** @return array<string, mixed> */
public function handle(int $adminId, int $depositId, string $reason): array
    {
        return $this->service->reject($adminId, $depositId, $reason);
    }
}
