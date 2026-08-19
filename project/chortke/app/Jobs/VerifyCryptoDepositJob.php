<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Payment\PaymentService;

class VerifyCryptoDepositJob
{
    public function __construct(
        private PaymentService $paymentService
    ) {}

    /** @return array<string, mixed> */
public function handle(int $depositId): array
    {
        try {
            return $this->paymentService->fulfillCryptoDeposit($depositId);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
