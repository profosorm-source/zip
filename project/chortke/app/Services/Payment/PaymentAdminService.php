<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\LoggerInterface;
use App\Models\PaymentLog;
use App\Services\OutboxService;
use Core\Database;

/**
 * PaymentAdminService — عملیات ادمین روی پرداخت‌ها
 *
 * مسئولیت‌ها:
 *   - getPendingVerificationPayments() : لیست پرداخت‌های در انتظار تأیید دستی
 *   - manuallyVerifyPayment()          : تأیید دستی توسط ادمین
 *   - reconcilePendingPayments()       : reconcile پرداخت‌های stuck
 */
class PaymentAdminService
{
    private LoggerInterface $logger;
    private PaymentLog $log;
    private Database $db;
    private PaymentCommandService $commandService;
    private PaymentGatewayFactory $gatewayFactory;
    private ?OutboxService $outbox;

    private function toObject(mixed $data): ?\stdClass
    {
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        return null;
    }

    public function __construct(
        LoggerInterface $logger,
        PaymentLog $log,
        Database $db,
        PaymentCommandService $commandService,
        PaymentGatewayFactory $gatewayFactory,
        ?OutboxService $outbox = null
    ) {
        $this->logger         = $logger;
        $this->log            = $log;
        $this->db             = $db;
        $this->commandService = $commandService;
        $this->gatewayFactory = $gatewayFactory;
        $this->outbox         = $outbox;
    }

    /** @return list<\stdClass> */
    public function getPendingVerificationPayments(): array
    {
        return $this->db->fetchAll(
            "SELECT pl.*, u.email, u.mobile
             FROM payment_logs pl
             JOIN users u ON u.id = pl.user_id
             WHERE pl.status = 'pending_verification'
             ORDER BY pl.created_at ASC"
        );
    }

    /** @return array<string, mixed> */
    public function manuallyVerifyPayment(int $paymentId, int $adminId): array
    {
        $pay = $this->toObject($this->log->where('id', '=', $paymentId)->first());

        if (!$pay || !isset($pay->id) || $pay->status !== 'pending_verification') {
            return ['success' => false, 'message' => 'Invalid payment record'];
        }

        try {
            $gw = $this->makeGateway((string)$pay->gateway);
            if (!$gw) {
                return ['success' => false, 'message' => 'Invalid gateway'];
            }

            $verify = $gw->verifyPayment((string)$pay->authority, (string)$pay->amount);
        } catch (\Throwable $e) {
            $this->logger->error('payment.manual_verify.exception', [
                'payment_id' => $paymentId,
                'gateway'    => $pay->gateway,
                'authority'  => $pay->authority,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Error communicating with gateway: ' . $e->getMessage()];
        }

        if (!empty($verify['success'])) {
            $storedRequestData = @(array)(json_decode($pay->request_data ?? '', true) ?? []) ?: [];
            $callbackNonceValue = $storedRequestData['callback_nonce'] ?? null;
            $bypassNonce       = is_scalar($callbackNonceValue) ? strval($callbackNonceValue) : 'BYPASS_NONCE';

            return $this->commandService->callback((string)$pay->gateway, [
                'authority' => (string)$pay->authority,
                'nonce'     => $bypassNonce,
                'status'    => 'OK',
            ], (int)$pay->user_id);
        }

        $this->log->update($paymentId, [
            'status'        => 'failed',
            'response_data' => json_encode($verify, JSON_UNESCAPED_UNICODE),
        ]);

        return ['success' => false, 'message' => $verify['message'] ?? 'Manual verification failed'];
    }

    /** @return array<string, mixed> */
    public function reconcilePendingPayments(): array
    {
        $processCbJob = new \App\Jobs\Payment\ProcessPaymentCallbackJob(
            $this->commandService,
            $this->logger
        );
        $job = new \App\Jobs\Payment\ReconcilePaymentsJob(
            $this->db,
            $this->logger,
            $this->log,
            $processCbJob,
            $this->outbox
        );
        return $job->handle();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeGateway(string $name): ?\App\Contracts\PaymentGatewayInterface
    {
        try {
            return $this->gatewayFactory->create($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
