<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\OutboxServiceInterface;

/**
 * PaymentService — Facade
 *
 * این کلاس فقط یک نقطه ورود واحد برای backward-compatibility است.
 * منطق واقعی به سه سرویس جداگانه منتقل شده:
 *
 *   PaymentCommandService  → create(), callback()
 *   PaymentAdminService    → getPendingVerificationPayments(), manuallyVerifyPayment(), reconcilePendingPayments()
 *   PaymentDepositService  → approveManualDeposit(), fulfillCryptoDeposit()
 *
 * کد جدید باید مستقیماً یکی از سرویس‌های بالا را inject کند.
 */
/** @phpstan-type PaymentResult array<string, mixed> */
class PaymentService
{
    private PaymentCommandService $commandService;
    private PaymentAdminService $adminService;
    private PaymentDepositService $depositService;
    private ?OutboxServiceInterface $outbox = null;

    public function __construct(
        PaymentCommandService $commandService,
        PaymentAdminService $adminService,
        PaymentDepositService $depositService,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->commandService = $commandService;
        $this->adminService   = $adminService;
        $this->depositService = $depositService;
        $this->outbox         = $outbox;
    }

    // ─── PaymentCommandService ────────────────────────────────────────────────

    /** @return PaymentResult */
    public function create(
        int $userId,
        string $gatewayName,
        string $amount,
        int $bankCardId,
        string $idempotencyKey,
        string $clientIp = '',
        string $userAgent = ''
    ): array {
        try {
            return $this->commandService->create($userId, $gatewayName, $amount, $bankCardId, $idempotencyKey, $clientIp, $userAgent);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطا در ایجاد پرداخت: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $callbackData
     * @return PaymentResult
     */
    public function callback(
        string $gatewayName,
        array $callbackData,
        ?int $sessionUserId = null,
        string $clientIp = '',
        string $userAgent = ''
    ): array {
        try {
            return $this->commandService->callback($gatewayName, $callbackData, $sessionUserId, $clientIp, $userAgent);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطا در پردازش callback: ' . $e->getMessage()];
        }
    }

    // ─── PaymentAdminService ──────────────────────────────────────────────────

    /** @return list<\stdClass> */
    public function getPendingVerificationPayments(): array
    {
        return $this->adminService->getPendingVerificationPayments();
    }

    /** @return PaymentResult */
    public function manuallyVerifyPayment(int $paymentId, int $adminId): array
    {
        return $this->adminService->manuallyVerifyPayment($paymentId, $adminId);
    }

    /** @return PaymentResult */
    public function reconcilePendingPayments(): array
    {
        return $this->adminService->reconcilePendingPayments();
    }

    // ─── PaymentDepositService ────────────────────────────────────────────────

    /** @return PaymentResult */
    public function approveManualDeposit(int $depositId, int $adminId): array
    {
        $result = $this->depositService->approveManualDeposit($depositId, $adminId);

        if ($result['success'] ?? false) {
            $this->outbox?->record('deposit', $depositId, 'payment.completed', [
                'deposit_id' => $depositId,
                'admin_id'   => $adminId,
            ]);
            $this->outbox?->record('deposit', $depositId, 'notification.deposit_success', [
                'deposit_id' => $depositId,
            ]);
        }

        return $result;
    }

    /** @return PaymentResult */
    public function fulfillCryptoDeposit(int $depositId): array
    {
        return $this->depositService->fulfillCryptoDeposit($depositId);
    }
}
