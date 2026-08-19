<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

use App\Services\CryptoDeposit\CryptoDepositService;

/**
 * Job تأیید واریز کریپتو.
 *
 * نسخه‌ی قبلی به‌صورت خودبازگشتی خودش را از Container می‌ساخت و دوباره handle را
 * صدا می‌زد (بازگشت بی‌نهایت → stack overflow). اکنون به CryptoDepositService واگذار می‌کند.
 */
class ApproveCryptoDepositJob
{
    public function __construct(
        private CryptoDepositService $service
    ) {}

    /** @return array<string, mixed> */
public function handle(int $adminId, int $depositId): array
    {
        return $this->service->approve($adminId, $depositId);
    }
}
